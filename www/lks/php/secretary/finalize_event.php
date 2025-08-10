<?php
/**
 * API для финального завершения мероприятия
 * Файл: www/lks/php/secretary/finalize_event.php
 */

require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/protocol_numbering.php";

header('Content-Type: application/json; charset=utf-8');

try {
    // Проверяем авторизацию
    $auth = new Auth();
    if (!$auth->isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Не авторизован']);
        exit;
    }

    // Проверяем права секретаря
    if (!$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
        exit;
    }

    // Получаем данные из POST запроса
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['meroId'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Не указан ID мероприятия']);
        exit;
    }

    $meroId = intval($input['meroId']);

    $db = Database::getInstance();
    $pdo = $db->getPDO();

    // Получаем информацию о мероприятии
    $stmt = $pdo->prepare("SELECT * FROM meros WHERE champn = ?");
    $stmt->execute([$meroId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Мероприятие не найдено']);
        exit;
    }

    // Проверяем, что мероприятие еще не завершено
    if ($event['status'] === 'Завершено') {
        echo json_encode(['success' => false, 'message' => 'Мероприятие уже завершено']);
        exit;
    }

    $classDistance = json_decode($event['class_distance'], true);
    
    if (!$classDistance) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Некорректная структура дисциплин']);
        exit;
    }

    // Подключаемся к Redis
    $redis = new Redis();
    try {
        $connected = $redis->connect('redis', 6379, 5);
        if (!$connected) {
            throw new Exception('Не удалось подключиться к Redis');
        }
    } catch (Exception $e) {
        error_log("Ошибка подключения к Redis: " . $e->getMessage());
        $redis = null;
    }

    // Начинаем транзакцию
    $pdo->beginTransaction();

    try {
        // Получаем все протоколы мероприятия
        $protocols = ProtocolNumbering::getProtocolsStructure($classDistance, $_SESSION['selected_disciplines'] ?? null);
        
        $totalResults = 0;
        $savedResults = 0;

        foreach ($protocols as $protocol) {
            $key = ProtocolNumbering::getProtocolKey($meroId, $protocol['class'], $protocol['sex'], $protocol['distance'], $protocol['ageGroup']);
            
            if ($redis) {
                $protocolData = $redis->get($key);
                
                if ($protocolData) {
                    $data = json_decode($protocolData, true);
                    
                    if ($data && isset($data['participants'])) {
                        $totalResults += count($data['participants']);
                        
                        // Сохраняем результаты в user_statistic
                        foreach ($data['participants'] as $participant) {
                            if (isset($participant['place']) && is_numeric($participant['place']) && 
                                isset($participant['finishTime']) && !empty($participant['finishTime'])) {
                                
                                // Получаем oid пользователя
                                $stmt = $pdo->prepare("SELECT oid FROM users WHERE userid = ?");
                                $stmt->execute([$participant['userId']]);
                                $userOid = $stmt->fetchColumn();
                                
                                if ($userOid) {
                                    // Проверяем, нет ли уже записи для этого участника в этой дисциплине
                                    $stmt = $pdo->prepare("
                                        SELECT oid FROM user_statistic 
                                        WHERE users_oid = ? AND meroname = ? AND race_type = ?
                                    ");
                                    $raceType = $protocol['class'] . ' ' . $protocol['sex'] . ' ' . $protocol['distance'] . 'м';
                                    $stmt->execute([$userOid, $event['meroname'], $raceType]);
                                    
                                    if (!$stmt->fetch()) {
                                        // Вставляем новую запись
                                        $stmt = $pdo->prepare("
                                            INSERT INTO user_statistic 
                                            (meroname, place, time, team, data, race_type, users_oid) 
                                            VALUES (?, ?, ?, ?, ?, ?, ?)
                                        ");
                                        
                                        $stmt->execute([
                                            $event['meroname'],
                                            $participant['place'],
                                            $participant['finishTime'],
                                            $participant['teamName'] ?? '',
                                            $event['merodata'],
                                            $raceType,
                                            $userOid
                                        ]);
                                        
                                        $savedResults++;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // Переводим мероприятие в статус "Результаты"
        $stmt = $pdo->prepare("UPDATE meros SET status = 'Результаты'::merostat WHERE champn = ?");
        $stmt->execute([$meroId]);

        // Не трогаем meros.fileresults здесь. Файл техрезультатов создаётся
        // отдельно и записывается generate_technical_results.php

        // Фиксируем транзакцию
        $pdo->commit();

        // Логируем событие
        $stmt = $pdo->prepare("
            INSERT INTO system_events (event_type, description, severity, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([
            'event_completed',
            "Мероприятие {$event['meroname']} (ID: {$meroId}) завершено. Сохранено результатов: {$savedResults}/{$totalResults}",
            'info'
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Мероприятие успешно завершено',
            'statistics' => [
                'totalResults' => $totalResults,
                'savedResults' => $savedResults,
                'eventName' => $event['meroname'],
                'eventId' => $meroId
            ]
        ]);

    } catch (Exception $e) {
        // Откатываем транзакцию при ошибке
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Ошибка завершения мероприятия: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка завершения мероприятия: ' . $e->getMessage()
    ]);
}
?> 
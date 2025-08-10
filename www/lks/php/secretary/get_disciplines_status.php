<?php
/**
 * API для проверки статуса заполненности дисциплин
 * Файл: www/lks/php/secretary/get_disciplines_status.php
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
    $selectedDisciplines = $input['disciplines'] ?? null;

    // Получаем информацию о мероприятии
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT class_distance FROM meros WHERE champn = ?");
    $stmt->execute([$meroId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Мероприятие не найдено']);
        exit;
    }

    $classDistance = json_decode($event['class_distance'], true);
    
    if (!$classDistance) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Некорректная структура дисциплин']);
        exit;
    }

    // Подключаемся к Redis (если расширение доступно)
    $redis = null;
    if (class_exists('Redis')) {
        try {
            $redis = new Redis();
            $connected = $redis->connect('redis', 6379, 5);
            if (!$connected) {
                throw new Exception('Не удалось подключиться к Redis');
            }
        } catch (Exception $e) {
            error_log("Ошибка подключения к Redis: " . $e->getMessage());
            $redis = null;
        }
    } else {
        error_log('Расширение Redis недоступно, используем JSON-файлы как источник статуса');
        $redis = null;
    }

    // Получаем статус заполненности дисциплин
    $disciplinesStatus = ProtocolNumbering::getDisciplinesCompletionStatus($redis, $meroId, $classDistance, $selectedDisciplines);
    
    // Проверяем готовность мероприятия к завершению
    $isReadyForCompletion = ProtocolNumbering::isEventReadyForCompletion($redis, $meroId, $classDistance, $selectedDisciplines);

    // Подсчитываем статистику
    $totalDisciplines = count($disciplinesStatus);
    $completedDisciplines = 0;
    $partialDisciplines = 0;
    $emptyDisciplines = 0;

    foreach ($disciplinesStatus as $discipline) {
        switch ($discipline['status']) {
            case 'completed':
                $completedDisciplines++;
                break;
            case 'partial':
                $partialDisciplines++;
                break;
            case 'empty':
                $emptyDisciplines++;
                break;
        }
    }

    echo json_encode([
        'success' => true,
        'disciplinesStatus' => array_values($disciplinesStatus),
        'statistics' => [
            'total' => $totalDisciplines,
            'completed' => $completedDisciplines,
            'partial' => $partialDisciplines,
            'empty' => $emptyDisciplines
        ],
        'isReadyForCompletion' => $isReadyForCompletion,
        'readyForCompletion' => $isReadyForCompletion
    ]);

} catch (Exception $e) {
    error_log("Ошибка получения статуса дисциплин: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка получения статуса дисциплин: ' . $e->getMessage()
    ]);
}
?> 
<?php
/**
 * Перемещение участника между группами
 * Файл: www/lks/php/secretary/move_participant.php
 */

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/Auth.php";

if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Проверка авторизации
$auth = new Auth();
if (!$auth->isAuthenticated() || !$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['meroId']) || !isset($input['participantId']) || 
        !isset($input['fromGroupKey']) || !isset($input['toGroupKey'])) {
        throw new Exception('Не все параметры указаны');
    }
    
    $meroId = (int)$input['meroId'];
    $participantId = (int)$input['participantId'];
    $fromGroupKey = $input['fromGroupKey'];
    $toGroupKey = $input['toGroupKey'];
    
    if ($meroId <= 0 || $participantId <= 0) {
        throw new Exception('Неверные параметры');
    }
    
    if ($fromGroupKey === $toGroupKey) {
        throw new Exception('Участник уже находится в этой группе');
    }
    
    // Подключаемся к Redis
    $redis = new Redis();
    try {
        $redis->connect('redis', 6379);
    } catch (Exception $e) {
        error_log("Ошибка подключения к Redis: " . $e->getMessage());
        throw new Exception('Ошибка подключения к базе данных');
    }
    
    // Получаем данные участника
    $db = Database::getInstance();
    $stmt = $db->prepare("
        SELECT u.oid, u.userid, u.fio, u.sex, u.birthdata, u.sportzvanie, u.city,
               t.teamname, t.teamcity
        FROM users u
        LEFT JOIN listreg lr ON u.oid = lr.users_oid AND lr.meros_oid = ?
        LEFT JOIN teams t ON lr.teams_oid = t.oid
        WHERE u.oid = ?
    ");
    $stmt->execute([$meroId, $participantId]);
    $participant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$participant) {
        throw new Exception('Участник не найден');
    }
    
    // Загружаем данные протоколов
    $protocolsDir = __DIR__ . '/../../../files/json/protocols/';
    $filename = $protocolsDir . "protocols_{$meroId}.json";
    
    if (!file_exists($filename)) {
        throw new Exception('Файл протоколов не найден');
    }
    
    $jsonData = file_get_contents($filename);
    $protocolsData = json_decode($jsonData, true);
    
    if (!$protocolsData) {
        throw new Exception('Ошибка чтения файла протоколов');
    }
    
    $participantData = null;
    $fromGroupFound = false;
    $toGroupFound = false;
    
    // Удаляем участника из исходной группы
    foreach ($protocolsData as &$protocol) {
        foreach ($protocol['ageGroups'] as &$ageGroup) {
            if ($ageGroup['redisKey'] === $fromGroupKey) {
                $fromGroupFound = true;
                foreach ($ageGroup['participants'] as $index => $p) {
                    if ($p['userId'] == $participant['userid']) {
                        $participantData = $p;
                        unset($ageGroup['participants'][$index]);
                        $ageGroup['participants'] = array_values($ageGroup['participants']);
                        break 2;
                    }
                }
            }
        }
    }
    
    if (!$fromGroupFound) {
        throw new Exception('Исходная группа не найдена');
    }
    
    if (!$participantData) {
        throw new Exception('Участник не найден в исходной группе');
    }
    
    // Добавляем участника в целевую группу
    foreach ($protocolsData as &$protocol) {
        foreach ($protocol['ageGroups'] as &$ageGroup) {
            if ($ageGroup['redisKey'] === $toGroupKey) {
                $toGroupFound = true;
                
                // Проверяем, нет ли уже такого участника
                foreach ($ageGroup['participants'] as $existingParticipant) {
                    if ($existingParticipant['userId'] == $participant['userid']) {
                        throw new Exception('Участник уже находится в целевой группе');
                    }
                }
                
                // Обновляем данные участника
                $participantData['addedManually'] = true;
                $participantData['addedAt'] = date('Y-m-d H:i:s');
                $participantData['lane'] = null;
                $participantData['place'] = null;
                $participantData['finishTime'] = null;
                
                $ageGroup['participants'][] = $participantData;
                break 2;
            }
        }
    }
    
    if (!$toGroupFound) {
        throw new Exception('Целевая группа не найдена');
    }
    
    // Сохраняем в Redis
    foreach ($protocolsData as $protocol) {
        foreach ($protocol['ageGroups'] as $ageGroup) {
            if ($ageGroup['redisKey'] === $fromGroupKey || $ageGroup['redisKey'] === $toGroupKey) {
                $redis->setex($ageGroup['redisKey'], 86400, json_encode($ageGroup));
            }
        }
    }
    
    // Сохраняем в JSON файл
    file_put_contents($filename, json_encode($protocolsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    echo json_encode([
        'success' => true,
        'message' => 'Участник успешно перемещен',
        'participant' => [
            'userId' => $participant['userid'],
            'fio' => $participant['fio'],
            'sex' => $participant['sex'],
            'birthdata' => $participant['birthdata'],
            'sportzvanie' => $participant['sportzvanie'],
            'teamName' => $participant['teamname'] ?? '',
            'teamCity' => $participant['teamcity'] ?? ''
        ],
        'fromGroup' => $fromGroupKey,
        'toGroup' => $toGroupKey
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка перемещения участника: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
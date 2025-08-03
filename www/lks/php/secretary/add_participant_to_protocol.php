<?php
session_start();
require_once __DIR__ . '/../common/Auth.php';
require_once __DIR__ . '/../db/Database.php';

// Включаем вывод ошибок для отладки
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Проверка авторизации и прав доступа
$auth = new Auth();

if (!$auth->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Не авторизован']);
    exit;
}

if (!$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Нет прав доступа']);
    exit;
}

// Получение данных из запроса
$input = json_decode(file_get_contents('php://input'), true);
$meroId = $input['meroId'] ?? null;
$participantOid = $input['participantOid'] ?? null;
$groupKey = $input['groupKey'] ?? null;

if (!$meroId || !$participantOid || !$groupKey) {
    echo json_encode(['success' => false, 'message' => 'Не все параметры указаны']);
    exit;
}

try {
    $db = new Database();
    
    // Получаем данные участника
    $stmt = $db->prepare("
        SELECT u.oid, u.userid, u.fio, u.sex, u.birthdata, u.sportzvanie, u.city,
               t.teamname, t.teamcity
        FROM users u
        LEFT JOIN listreg lr ON u.oid = lr.users_oid AND lr.meros_oid = ?
        LEFT JOIN teams t ON lr.teams_oid = t.oid
        WHERE u.oid = ?
    ");
    $stmt->execute([$meroId, $participantOid]);
    $participant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$participant) {
        echo json_encode(['success' => false, 'message' => 'Участник не найден']);
        exit;
    }
    
    // Загружаем данные протоколов
    $protocolsDir = __DIR__ . '/../../../files/json/protocols/';
    $filename = $protocolsDir . "protocols_{$meroId}.json";
    
    if (!file_exists($filename)) {
        echo json_encode(['success' => false, 'message' => 'Файл протоколов не найден']);
        exit;
    }
    
    $jsonData = file_get_contents($filename);
    $protocolsData = json_decode($jsonData, true);
    
    // Находим нужную группу
    $groupFound = false;
    foreach ($protocolsData as &$protocol) {
        foreach ($protocol['ageGroups'] as &$ageGroup) {
            if ($ageGroup['redisKey'] === $groupKey) {
                // Проверяем, не добавлен ли уже участник
                foreach ($ageGroup['participants'] as $existingParticipant) {
                    if ($existingParticipant['userId'] == $participant['userid']) {
                        echo json_encode(['success' => false, 'message' => 'Участник уже добавлен в эту группу']);
                        exit;
                    }
                }
                
                // Определяем максимальное количество дорожек для типа лодки
                $maxLanes = getMaxLanesForBoat($protocol['discipline']);
                
                // Находим свободную дорожку
                $usedLanes = [];
                foreach ($ageGroup['participants'] as $existingParticipant) {
                    if (isset($existingParticipant['lane']) && $existingParticipant['lane'] !== null) {
                        $usedLanes[] = $existingParticipant['lane'];
                    }
                }
                
                // Назначаем первую свободную дорожку
                $assignedLane = 1;
                for ($lane = 1; $lane <= $maxLanes; $lane++) {
                    if (!in_array($lane, $usedLanes)) {
                        $assignedLane = $lane;
                        break;
                    }
                }
                
                // Добавляем участника
                $ageGroup['participants'][] = [
                    'userId' => $participant['userid'],
                    'userid' => $participant['userid'], // Добавляем дублирующее поле для совместимости
                    'fio' => $participant['fio'],
                    'sex' => $participant['sex'],
                    'birthdata' => $participant['birthdata'],
                    'sportzvanie' => $participant['sportzvanie'],
                    'teamName' => $participant['teamname'] ?? '',
                    'teamCity' => $participant['teamcity'] ?? '',
                    'lane' => $assignedLane,
                    'water' => $assignedLane, // Добавляем поле "вода" для совместимости
                    'place' => null,
                    'finishTime' => null,
                    'addedManually' => true,
                    'addedAt' => date('Y-m-d H:i:s'),
                    'discipline' => $protocol['discipline'] ?? '',
                    'groupKey' => $ageGroup['redisKey'] ?? ''
                ];
                
                $groupFound = true;
                break 2;
            }
        }
    }
    
    if (!$groupFound) {
        echo json_encode(['success' => false, 'message' => 'Группа не найдена']);
        exit;
    }
    
    // Сохраняем в Redis
    $redis = new Redis();
    $redis->connect('redis', 6379);
    
    foreach ($protocolsData as $protocol) {
        foreach ($protocol['ageGroups'] as $ageGroup) {
            if ($ageGroup['redisKey'] === $groupKey) {
                $redis->setex($ageGroup['redisKey'], 86400, json_encode($ageGroup));
                break 2;
            }
        }
    }
    
    // Сохраняем в JSON файл
    file_put_contents($filename, json_encode($protocolsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    echo json_encode([
        'success' => true,
        'message' => 'Участник добавлен в протокол',
        'participant' => [
            'userId' => $participant['userid'],
            'fio' => $participant['fio'],
            'sex' => $participant['sex'],
            'birthdata' => $participant['birthdata'],
            'sportzvanie' => $participant['sportzvanie'],
            'teamName' => $participant['teamname'] ?? '',
            'teamCity' => $participant['teamcity'] ?? ''
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка добавления участника: ' . $e->getMessage()]);
}

/**
 * Определение максимального количества дорожек для типа лодки
 */
function getMaxLanesForBoat($boatClass) {
    switch ($boatClass) {
        case 'D-10':
            return 6; // Драконы - 6 дорожек
        case 'K-1':
        case 'C-1':
            return 9; // Одиночные - 9 дорожек
        case 'K-2':
        case 'C-2':
            return 9; // Двойки - 9 дорожек
        case 'K-4':
        case 'C-4':
            return 9; // Четверки - 9 дорожек
        default:
            return 9; // По умолчанию 9 дорожек
    }
}
?> 
<?php
session_start();
require_once __DIR__ . '/../common/Auth.php';
require_once __DIR__ . '/../db/Database.php';
require_once __DIR__ . '/../common/JsonProtocolManager.php';

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
    $db = Database::getInstance();
    
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
    
    // Инициализируем менеджер JSON протоколов
    $protocolManager = JsonProtocolManager::getInstance();
    
    // Загружаем протокол
    $protocolData = $protocolManager->loadProtocol($groupKey);
    
    if (!$protocolData) {
        echo json_encode(['success' => false, 'message' => 'Протокол не найден']);
        exit;
    }
    
    // Проверяем, не добавлен ли уже участник
    foreach ($protocolData['participants'] as $existingParticipant) {
        if (($existingParticipant['userId'] ?? $existingParticipant['userid']) == $participant['userid']) {
            echo json_encode(['success' => false, 'message' => 'Участник уже добавлен в эту группу']);
            exit;
        }
    }
    
    // Определяем максимальное количество дорожек для типа лодки
    $maxLanes = getMaxLanesForBoat($protocolData['discipline'] ?? 'K-1');
    
    // Находим свободную дорожку
    $usedLanes = [];
    foreach ($protocolData['participants'] as $existingParticipant) {
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
    $protocolData['participants'][] = [
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
        'discipline' => $protocolData['discipline'] ?? '',
        'groupKey' => $groupKey
    ];
    
    // Сохраняем в JSON файл
    $protocolManager->saveProtocol($groupKey, $protocolData);
    
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
    $cls = strtoupper(trim((string)$boatClass));
    // Универсально: любые классы драконов (начинаются с 'D') — 6 дорожек, иначе — 10
    if (strpos($cls, 'D') === 0) {
        return 6;
    }
    return 10;
}
?> 
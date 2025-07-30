<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/lks/php/common/Auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lks/php/db/Database.php';

// Проверка авторизации и прав доступа
$auth = new Auth();
if (!$auth->isAuthenticated() || !$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

// Получение данных из POST запроса
$input = json_decode(file_get_contents('php://input'), true);
$meroId = $input['meroId'] ?? null;
$participantId = $input['participantId'] ?? null;
$fromGroup = $input['fromGroup'] ?? null;
$toGroup = $input['toGroup'] ?? null;

if (!$meroId || !$participantId || !$fromGroup || !$toGroup) {
    echo json_encode(['success' => false, 'message' => 'Не указаны необходимые параметры']);
    exit;
}

try {
    // Перемещаем участника между группами
    moveParticipantBetweenGroups($meroId, $participantId, $fromGroup, $toGroup);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log("Ошибка перемещения участника: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера: ' . $e->getMessage()]);
}

/**
 * Перемещение участника между группами
 */
function moveParticipantBetweenGroups($meroId, $participantId, $fromGroup, $toGroup) {
    // Получаем данные из исходной группы
    $fromStartData = getProtocolData($meroId, $fromGroup, 'start');
    $fromFinishData = getProtocolData($meroId, $fromGroup, 'finish');
    
    // Получаем данные в целевую группу
    $toStartData = getProtocolData($meroId, $toGroup, 'start');
    $toFinishData = getProtocolData($meroId, $toGroup, 'finish');
    
    if (!$fromStartData || !$fromFinishData) {
        throw new Exception('Исходная группа не найдена');
    }
    
    if (!$toStartData || !$toFinishData) {
        throw new Exception('Целевая группа не найдена');
    }

    // Находим участника в исходной группе
    $participant = null;
    $participantIndex = -1;
    
    foreach ($fromStartData['participants'] as $index => $p) {
        if ($p['id'] == $participantId) {
            $participant = $p;
            $participantIndex = $index;
            break;
        }
    }
    
    if (!$participant) {
        throw new Exception('Участник не найден в исходной группе');
    }

    // Удаляем участника из исходной группы
    array_splice($fromStartData['participants'], $participantIndex, 1);
    array_splice($fromFinishData['participants'], $participantIndex, 1);
    
    // Обновляем номера воды в исходной группе
    reorderWaterNumbers($fromStartData['participants']);
    reorderWaterNumbers($fromFinishData['participants']);

    // Добавляем участника в целевую группу
    $participant['waterNumber'] = count($toStartData['participants']) + 1;
    $toStartData['participants'][] = $participant;
    
    // Добавляем в финишный протокол с пустыми результатами
    $finishParticipant = $participant;
    $finishParticipant['place'] = '';
    $finishParticipant['finishTime'] = '';
    $toFinishData['participants'][] = $finishParticipant;

    // Обновляем время последнего изменения
    $fromStartData['lastUpdated'] = date('Y-m-d H:i:s');
    $fromFinishData['lastUpdated'] = date('Y-m-d H:i:s');
    $toStartData['lastUpdated'] = date('Y-m-d H:i:s');
    $toFinishData['lastUpdated'] = date('Y-m-d H:i:s');

    // Сохраняем обновленные данные
    saveProtocolData($meroId, $fromGroup, 'start', $fromStartData);
    saveProtocolData($meroId, $fromGroup, 'finish', $fromFinishData);
    saveProtocolData($meroId, $toGroup, 'start', $toStartData);
    saveProtocolData($meroId, $toGroup, 'finish', $toFinishData);
}

/**
 * Пересчет номеров воды после удаления участника
 */
function reorderWaterNumbers(&$participants) {
    foreach ($participants as $index => &$participant) {
        $participant['waterNumber'] = $index + 1;
    }
}

/**
 * Получение данных протокола из Redis/JSON
 */
function getProtocolData($meroId, $groupKey, $type) {
    // Сначала пробуем получить из Redis
    $redis = new Redis();
    try {
        $redis->connect('127.0.0.1', 6379);
        
        $redisKey = "protocol:{$meroId}:{$groupKey}:{$type}";
        $data = $redis->get($redisKey);
        
        if ($data) {
            $protocolData = json_decode($data, true);
            if ($protocolData) {
                return $protocolData;
            }
        }
    } catch (Exception $e) {
        error_log("Ошибка подключения к Redis: " . $e->getMessage());
    }

    // Если Redis недоступен или данных нет, пробуем JSON файл
    $jsonFilePath = $_SERVER['DOCUMENT_ROOT'] . "/lks/files/json/protocols/{$meroId}/{$groupKey}_{$type}.json";
    
    if (file_exists($jsonFilePath)) {
        $jsonData = file_get_contents($jsonFilePath);
        $protocolData = json_decode($jsonData, true);
        
        if ($protocolData) {
            return $protocolData;
        }
    }

    return null;
}

/**
 * Сохранение данных протокола
 */
function saveProtocolData($meroId, $groupKey, $type, $data) {
    // Сохраняем в Redis
    $redis = new Redis();
    try {
        $redis->connect('127.0.0.1', 6379);
        
        $redisKey = "protocol:{$meroId}:{$groupKey}:{$type}";
        $redis->setex($redisKey, 86400, json_encode($data)); // TTL 24 часа
    } catch (Exception $e) {
        error_log("Ошибка сохранения в Redis: " . $e->getMessage());
    }

    // Сохраняем в JSON файл
    $jsonDir = $_SERVER['DOCUMENT_ROOT'] . "/lks/files/json/protocols/{$meroId}";
    if (!is_dir($jsonDir)) {
        mkdir($jsonDir, 0755, true);
    }
    
    $jsonFilePath = $jsonDir . "/{$groupKey}_{$type}.json";
    file_put_contents($jsonFilePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
?> 
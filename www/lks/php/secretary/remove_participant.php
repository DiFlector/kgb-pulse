<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/lks/php/common/Auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lks/php/db/Database.php';

// Отладочная информация
file_put_contents(__DIR__ . '/remove_called.log', date('Y-m-d H:i:s') . ' | remove_participant.php called' . PHP_EOL, FILE_APPEND);

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
$groupKey = $input['groupKey'] ?? null;

if (!$meroId || !$participantId || !$groupKey) {
    echo json_encode(['success' => false, 'message' => 'Не указаны необходимые параметры']);
    exit;
}

try {
    // Удаляем участника из группы
    removeParticipantFromGroup($meroId, $participantId, $groupKey);

    echo json_encode([
        'success' => true, 
        'message' => 'Участник успешно удален',
        'debug' => [
            'removedParticipantId' => $participantId,
            'groupKey' => $groupKey,
            'meroId' => $meroId
        ]
    ]);

} catch (Exception $e) {
    error_log("Ошибка удаления участника: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера: ' . $e->getMessage()]);
}

/**
 * Удаление участника из группы
 */
function removeParticipantFromGroup($meroId, $participantId, $groupKey) {
    // Получаем данные из стартового протокола
    $startData = getProtocolData($meroId, $groupKey, 'start');
    $finishData = getProtocolData($meroId, $groupKey, 'finish');
    
    if (!$startData || !$finishData) {
        throw new Exception('Группа не найдена');
    }

    // Находим участника в стартовом протоколе
    $participantIndex = -1;
    
    foreach ($startData['participants'] as $index => $p) {
        if ($p['id'] == $participantId) {
            $participantIndex = $index;
            break;
        }
    }
    
    if ($participantIndex === -1) {
        throw new Exception('Участник не найден в группе');
    }

    // Удаляем участника из стартового протокола
    array_splice($startData['participants'], $participantIndex, 1);
    
    // Удаляем участника из финишного протокола
    array_splice($finishData['participants'], $participantIndex, 1);
    
    // НЕ пересчитываем номера воды - оставляем как есть
    // reorderWaterNumbers($startData['participants']);
    // reorderWaterNumbers($finishData['participants']);

    // Обновляем время последнего изменения
    $startData['lastUpdated'] = date('Y-m-d H:i:s');
    $finishData['lastUpdated'] = date('Y-m-d H:i:s');

    // Логируем для отладки
    $logStr = date('Y-m-d H:i:s') . ' | removed participant=' . $participantId . 
             ' | start participants count=' . count($startData['participants']) . 
             ' | finish participants count=' . count($finishData['participants']) . 
             ' | groupKey=' . $groupKey . 
             ' | meroId=' . $meroId . 
             ' | startKey=protocols:start:' . $groupKey . 
             ' | finishKey=protocols:finish:' . $groupKey . PHP_EOL;
    file_put_contents(__DIR__ . '/remove_debug.log', $logStr, FILE_APPEND);

    // Сохраняем обновленные данные
    saveProtocolData($meroId, $groupKey, 'start', $startData);
    saveProtocolData($meroId, $groupKey, 'finish', $finishData);
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
        $redis->connect('redis', 6379);
        
        // Используем тот же формат ключей, что и в add_participant_to_protocol.php
        $redisKey = "protocols:{$type}:{$groupKey}";
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
    $jsonFilePath = __DIR__ . "/../../files/json/protocols/{$meroId}/{$groupKey}_{$type}.json";
    
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
        $redis->connect('redis', 6379);
        
        // Используем тот же формат ключей, что и в add_participant_to_protocol.php
        $redisKey = "protocols:{$type}:{$groupKey}";
        $redis->setex($redisKey, 86400, json_encode($data)); // TTL 24 часа
    } catch (Exception $e) {
        error_log("Ошибка сохранения в Redis: " . $e->getMessage());
    }

    // Сохраняем в JSON файл
    $jsonDir = __DIR__ . "/../../files/json/protocols/{$meroId}";
    if (!is_dir($jsonDir)) {
        mkdir($jsonDir, 0755, true);
    }
    
    $jsonFilePath = $jsonDir . "/{$groupKey}_{$type}.json";
    file_put_contents($jsonFilePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
?>
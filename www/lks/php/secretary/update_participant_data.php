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
$groupKey = $input['groupKey'] ?? null;
$participantId = $input['participantId'] ?? null;
$field = $input['field'] ?? null;
$value = $input['value'] ?? null;

if (!$meroId || !$groupKey || !$participantId || !$field) {
    echo json_encode(['success' => false, 'message' => 'Не указаны необходимые параметры']);
    exit;
}

try {
    // Обновляем данные в стартовом протоколе
    updateProtocolData($meroId, $groupKey, 'start', $participantId, $field, $value);
    
    // Если обновляется номер воды, синхронизируем с финишным протоколом
    if ($field === 'waterNumber' || $field === 'water') {
        updateProtocolData($meroId, $groupKey, 'finish', $participantId, $field, $value);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log("Ошибка обновления данных участника: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера']);
}

/**
 * Обновление данных в протоколе
 */
function updateProtocolData($meroId, $groupKey, $type, $participantId, $field, $value) {
    // Получаем текущие данные протокола
    $protocolData = getProtocolData($meroId, $groupKey, $type);
    
    if (!$protocolData) {
        throw new Exception('Протокол не найден');
    }

    // Находим и обновляем участника
    $participantFound = false;
    foreach ($protocolData['participants'] as &$participant) {
        if ($participant['id'] == $participantId) {
            $participant[$field] = $value;
            
            // Синхронизируем water с waterNumber
            if ($field === 'waterNumber') {
                $participant['water'] = $value;
            } elseif ($field === 'water') {
                $participant['waterNumber'] = $value;
            }
            
            $participantFound = true;
            break;
        }
    }

    if (!$participantFound) {
        throw new Exception('Участник не найден в протоколе');
    }

    // Обновляем время последнего изменения
    $protocolData['lastUpdated'] = date('Y-m-d H:i:s');

    // Сохраняем обновленные данные
    saveProtocolData($meroId, $groupKey, $type, $protocolData);
}

/**
 * Получение данных протокола из Redis/JSON
 */
function getProtocolData($meroId, $groupKey, $type) {
    // Сначала пробуем получить из Redis
    $redis = new Redis();
    try {
        $redis->connect('redis', 6379);
        
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
        $redis->connect('redis', 6379);
        
        $redisKey = "protocols:{$type}:{$groupKey}";
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
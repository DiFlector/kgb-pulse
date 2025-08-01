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
$groupKey = $input['groupKey'] ?? null;
$participantUserId = $input['participantUserId'] ?? null;
$field = $input['field'] ?? null;
$value = $input['value'] ?? null;

if (!$meroId || !$groupKey || !$participantUserId || !$field) {
    echo json_encode(['success' => false, 'message' => 'Не все параметры указаны']);
    exit;
}

try {
    // Загружаем данные протоколов
    $protocolsDir = __DIR__ . '/../../../files/json/protocols/';
    $filename = $protocolsDir . "protocols_{$meroId}.json";
    
    if (!file_exists($filename)) {
        echo json_encode(['success' => false, 'message' => 'Файл протоколов не найден']);
        exit;
    }
    
    $jsonData = file_get_contents($filename);
    $protocolsData = json_decode($jsonData, true);
    
    // Находим нужную группу и участника
    $participantFound = false;
    foreach ($protocolsData as &$protocol) {
        foreach ($protocol['ageGroups'] as &$ageGroup) {
            if ($ageGroup['redisKey'] === $groupKey) {
                foreach ($ageGroup['participants'] as &$participant) {
                    if ($participant['userId'] == $participantUserId) {
                        // Обновляем поле
                        $participant[$field] = $value;
                        
                        // Если обновляем место или время финиша, отмечаем группу как защищенную
                        if (in_array($field, ['place', 'finishTime'])) {
                            $ageGroup['protected'] = true;
                        }
                        
                        $participantFound = true;
                        break 3;
                    }
                }
            }
        }
    }
    
    if (!$participantFound) {
        echo json_encode(['success' => false, 'message' => 'Участник не найден']);
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
        'message' => 'Данные участника обновлены',
        'field' => $field,
        'value' => $value
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка обновления данных: ' . $e->getMessage()]);
}
?> 
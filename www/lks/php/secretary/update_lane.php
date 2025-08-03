<?php
// Отключаем вывод ошибок в браузер
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/php_errors.log');

// Начинаем буферизацию вывода
ob_start();

session_start();
require_once __DIR__ . '/../common/Auth.php';
require_once __DIR__ . '/../db/Database.php';

// Проверка авторизации и прав доступа
$auth = new Auth();

if (!$auth->isAuthenticated()) {
    // Очищаем буфер и отправляем JSON
    ob_end_clean();
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Не авторизован']);
    exit;
}

if (!$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
    // Очищаем буфер и отправляем JSON
    ob_end_clean();
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Нет прав доступа']);
    exit;
}

// Получение данных из запроса
$input = json_decode(file_get_contents('php://input'), true);
$meroId = $input['meroId'] ?? null;
$groupKey = $input['groupKey'] ?? null;
$userId = $input['userId'] ?? null;
$newLane = $input['newLane'] ?? null;

if (!$meroId || !$groupKey || !$userId || !$newLane) {
    // Очищаем буфер и отправляем JSON
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Не все параметры указаны']);
    exit;
}

try {
    // Загружаем данные протоколов
    $protocolsDir = __DIR__ . '/../../../files/json/protocols/';
    $filename = $protocolsDir . "protocols_{$meroId}.json";
    
    if (!file_exists($filename)) {
        // Очищаем буфер и отправляем JSON
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Файл протоколов не найден']);
        exit;
    }
    
    $jsonData = file_get_contents($filename);
    $protocolsData = json_decode($jsonData, true);
    
    if (!$protocolsData) {
        // Очищаем буфер и отправляем JSON
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Ошибка чтения файла протоколов']);
        exit;
    }
    
    // Подключаемся к Redis
    $redis = new Redis();
    $redis->connect('redis', 6379);
    
    $laneUpdated = false;
    $maxLanes = 9; // По умолчанию
    
    // Находим нужную группу и обновляем дорожку
    foreach ($protocolsData as &$protocol) {
        foreach ($protocol['ageGroups'] as &$ageGroup) {
            if ($ageGroup['redisKey'] === $groupKey) {
                // Получаем максимальное количество дорожек для этой дисциплины
                $maxLanes = ($protocol['discipline'] === 'D-10') ? 6 : 9;
                
                // Проверяем, что новая дорожка в допустимых пределах
                if ($newLane < 1 || $newLane > $maxLanes) {
                    // Очищаем буфер и отправляем JSON
                    ob_end_clean();
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => "Номер дорожки должен быть от 1 до {$maxLanes}"]);
                    exit;
                }
                
                // Проверяем, не занята ли уже эта дорожка другим участником
                foreach ($ageGroup['participants'] as $participant) {
                    if ($participant['userId'] != $userId && $participant['lane'] == $newLane) {
                        // Очищаем буфер и отправляем JSON
                        ob_end_clean();
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => "Дорожка {$newLane} уже занята другим участником"]);
                        exit;
                    }
                }
                
                // Обновляем дорожку участника
                foreach ($ageGroup['participants'] as &$participant) {
                    if ($participant['userId'] == $userId) {
                        $participant['lane'] = (int)$newLane;
                        $participant['water'] = (int)$newLane; // Добавляем обновление поля "вода"
                        $participant['laneModified'] = true;
                        $participant['laneModifiedAt'] = date('Y-m-d H:i:s');
                        $laneUpdated = true;
                        break;
                    }
                }
                
                // Сохраняем в Redis
                $redis->setex($ageGroup['redisKey'], 86400, json_encode($ageGroup));
                break 2;
            }
        }
    }
    
    if (!$laneUpdated) {
        // Очищаем буфер и отправляем JSON
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Участник не найден в указанной группе']);
        exit;
    }
    
    // Сохраняем обновленные данные в JSON файл
    file_put_contents($filename, json_encode($protocolsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // Очищаем буфер и отправляем JSON
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => "Дорожка успешно изменена на {$newLane}",
        'newLane' => $newLane,
        'maxLanes' => $maxLanes
    ]);
    
} catch (Exception $e) {
    // Очищаем буфер и отправляем JSON
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Ошибка обновления дорожки: ' . $e->getMessage()]);
}
?> 
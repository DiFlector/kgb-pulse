<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../db/Database.php';
require_once __DIR__ . '/SecretaryEventManager.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    
    // Подключение к Redis
    try {
        $redis = new Redis();
        $redis->connect('redis', 6379);
    } catch (Exception $e) {
        $redis = null;
    }
    
    // Получение данных из POST запроса
    $input = json_decode(file_get_contents('php://input'), true);
    $meroId = $input['mero_id'] ?? 1; // По умолчанию используем мероприятие с ID 1
    
    if (!$meroId) {
        echo json_encode(['success' => false, 'message' => 'Не указан ID мероприятия']);
        exit;
    }
    
    // Создаем менеджер и получаем дисциплины
    $manager = new SecretaryEventManager($pdo, $redis, $meroId);
    $disciplines = $manager->getAvailableDisciplines();
    
    echo json_encode([
        'success' => true,
        'disciplines' => $disciplines,
        'total_disciplines' => count($disciplines)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?> 
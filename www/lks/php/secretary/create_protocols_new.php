<?php
/**
 * Создание протоколов - новая версия без Redis
 * Файл: www/lks/php/secretary/create_protocols_new.php
 */

require_once __DIR__ . "/protocol_manager.php";

if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}

// В режиме тестирования пропускаем проверку аутентификации
if (!defined('TEST_MODE')) {
    // Проверка прав доступа
    if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Secretary', 'SuperUser', 'Admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
        exit();
    }
}

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

try {
    error_log("🔄 [CREATE_PROTOCOLS_NEW] Запрос на создание протоколов");
    
    // Получаем данные из POST запроса
    if (defined('TEST_MODE')) {
        $data = $_POST;
    } else {
        $rawInput = file_get_contents('php://input');
        error_log("🔄 [CREATE_PROTOCOLS_NEW] Сырые данные: " . $rawInput);
        $data = json_decode($rawInput, true);
    }
    
    if (!$data) {
        throw new Exception('Неверные данные запроса');
    }
    
    // Проверяем обязательные поля
    if (!isset($data['meroId']) || !isset($data['type']) || !isset($data['disciplines'])) {
        throw new Exception('Отсутствуют обязательные поля: meroId, type, disciplines');
    }
    
    $meroId = (int)$data['meroId'];
    $type = $data['type']; // 'start' или 'finish'
    $disciplines = $data['disciplines'];
    
    error_log("🔄 [CREATE_PROTOCOLS_NEW] Создание протоколов для мероприятия $meroId, тип: $type");
    
    // Создаем менеджер протоколов
    $protocolManager = new ProtocolManager();
    
    // Создаем протоколы
    $result = $protocolManager->createAllProtocols($meroId, $type, $disciplines);
    
    if ($result['success']) {
        $protocolCount = count($result['protocols']);
        error_log("✅ [CREATE_PROTOCOLS_NEW] Протоколы созданы успешно: $protocolCount протоколов");
        
        // Если протоколы созданы, но их мало, добавляем предупреждение
        if ($protocolCount === 0) {
            $result['message'] = 'Протоколы созданы, но участников не найдено для выбранных дисциплин';
        } else {
            $result['message'] = "Протоколы созданы успешно! Создано $protocolCount протоколов.";
        }
    } else {
        error_log("❌ [CREATE_PROTOCOLS_NEW] Ошибка создания протоколов: " . $result['message']);
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("❌ [CREATE_PROTOCOLS_NEW] Ошибка: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?> 
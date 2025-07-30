<?php
/**
 * Автоматическое создание пустых протоколов при загрузке страницы
 * Файл: www/lks/php/secretary/auto_create_protocols.php
 */

require_once __DIR__ . "/ProtocolManagerNew.php";

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
    error_log("🔄 [AUTO_CREATE_PROTOCOLS] Запрос на автоматическое создание протоколов");
    
    // Получаем данные из POST запроса
    if (defined('TEST_MODE')) {
        $data = $_POST;
    } else {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
    }
    
    if (!$data) {
        throw new Exception('Неверные данные запроса');
    }
    
    // Проверяем обязательные поля
    if (!isset($data['meroId']) || !isset($data['selectedDisciplines'])) {
        throw new Exception('Отсутствуют обязательные поля: meroId, selectedDisciplines');
    }
    
    $meroId = (int)$data['meroId'];
    $selectedDisciplines = $data['selectedDisciplines'];
    $type = $data['type'] ?? 'both'; // 'start', 'finish', или 'both'
    
    if ($meroId <= 0) {
        throw new Exception('Неверный ID мероприятия');
    }
    
    if (!is_array($selectedDisciplines) || empty($selectedDisciplines)) {
        throw new Exception('Не выбраны дисциплины');
    }
    
    if (!in_array($type, ['start', 'finish', 'both'])) {
        throw new Exception('Неверный тип протокола');
    }
    
    error_log("🔄 [AUTO_CREATE_PROTOCOLS] Создание протоколов для мероприятия $meroId");
    
    // Создаем менеджер протоколов
    $protocolManager = new ProtocolManagerNew();
    
    // Автоматически создаем пустые протоколы
    $result = $protocolManager->autoCreateEmptyProtocols($meroId, $selectedDisciplines, $type);
    
    if ($result['success']) {
        $protocolCount = count($result['protocols']);
        error_log("✅ [AUTO_CREATE_PROTOCOLS] Протоколы созданы успешно: $protocolCount протоколов");
    } else {
        error_log("❌ [AUTO_CREATE_PROTOCOLS] Ошибка создания протоколов: " . $result['message']);
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("❌ [AUTO_CREATE_PROTOCOLS] Ошибка: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?> 
<?php
/**
 * Просмотр протоколов - новая версия без Redis
 * Файл: www/lks/php/secretary/view_protocols_new.php
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
    error_log("🔍 [VIEW_PROTOCOLS_NEW] Запрос на просмотр протоколов");
    
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
    if (!isset($data['meroId'])) {
        throw new Exception('Не указан ID мероприятия');
    }
    
    $meroId = (int)$data['meroId'];
    
    error_log("🔍 [VIEW_PROTOCOLS_NEW] Загрузка протоколов для мероприятия $meroId");
    
    // Создаем менеджер протоколов
    $protocolManager = new ProtocolManager();
    
    // Получаем список протоколов
    $protocols = $protocolManager->getEventProtocols($meroId);
    
    // Группируем по типам
    $startProtocols = [];
    $finishProtocols = [];
    
    foreach ($protocols as $protocol) {
        if ($protocol['type'] === 'start') {
            $startProtocols[] = $protocol;
        } else {
            $finishProtocols[] = $protocol;
        }
    }
    
    $result = [
        'success' => true,
        'meroId' => $meroId,
        'startProtocols' => $startProtocols,
        'finishProtocols' => $finishProtocols,
        'totalStart' => count($startProtocols),
        'totalFinish' => count($finishProtocols)
    ];
    
    error_log("🔍 [VIEW_PROTOCOLS_NEW] Загружено протоколов: стартовых - " . count($startProtocols) . ", финишных - " . count($finishProtocols));
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("❌ [VIEW_PROTOCOLS_NEW] Ошибка: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?> 
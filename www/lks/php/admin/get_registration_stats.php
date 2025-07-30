<?php
// API для получения статистики регистраций
session_start();

// Проверка авторизации
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Не авторизован']);
    exit;
}

// Проверка прав доступа
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Admin', 'SuperUser', 'Organizer', 'Secretary'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
    exit;
}

require_once __DIR__ . "/../db/Database.php";

try {
    $db = Database::getInstance();
    
    // Получение полной статистики регистраций
    $statsQuery = "
        SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN status = 'Подтверждён' THEN 1 END) as confirmed,
            COUNT(CASE WHEN status IN ('В очереди', 'Ожидание команды') THEN 1 END) as waiting,
            COUNT(CASE WHEN oplata = true THEN 1 END) as paid
        FROM listreg
    ";
    
    $stmt = $db->query($statsQuery);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'stats' => [
            'total' => (int)$stats['total'],
            'confirmed' => (int)$stats['confirmed'],
            'waiting' => (int)$stats['waiting'],
            'paid' => (int)$stats['paid']
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Ошибка при получении статистики регистраций: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Ошибка сервера: ' . $e->getMessage()
    ]);
}
?> 
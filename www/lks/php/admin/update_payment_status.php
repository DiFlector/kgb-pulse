<?php
// Обновление статуса оплаты - API для администратора
session_start();

// Проверка авторизации
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Не авторизован']);
    exit;
}

// Проверка прав администратора
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Admin', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
    exit;
}

require_once __DIR__ . "/../db/Database.php";

// Получение данных запроса
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['registrationId']) || !isset($input['paid'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Отсутствуют обязательные параметры']);
    exit;
}

$registrationId = (int)$input['registrationId'];
$paid = $input['paid'] ? 'true' : 'false'; // Преобразуем в строку для PostgreSQL

try {
    $db = Database::getInstance();
    
    // Проверка существования регистрации
    $stmt = $db->prepare("SELECT oid FROM listreg WHERE oid = ?");
    $stmt->execute([$registrationId]);
    
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Регистрация не найдена']);
        exit;
    }
    
    // Обновление статуса оплаты
    $stmt = $db->prepare("UPDATE listreg SET oplata = ?::boolean WHERE oid = ?");
    $stmt->execute([$paid, $registrationId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Статус оплаты обновлен',
        'paid' => $input['paid'] // Возвращаем исходное boolean значение
    ]);
    
} catch (Exception $e) {
    error_log('Ошибка при обновлении статуса оплаты: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера: ' . $e->getMessage()]);
}
?> 
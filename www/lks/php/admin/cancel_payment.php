<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Admin', 'SuperUser'])) {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

require_once __DIR__ . '/../db/Database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Неверный метод запроса']);
    exit;
}

$registration_id = $_POST['registration_id'] ?? null;

if (!$registration_id) {
    echo json_encode(['success' => false, 'error' => 'Отсутствует registration_id']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Проверяем существование регистрации
    $stmt = $db->prepare("
        SELECT lr.*, u.fio, u.email, m.meroname 
        FROM listreg lr
        JOIN users u ON lr.users_oid = u.oid
        JOIN meros m ON lr.meros_oid = m.oid
        WHERE lr.oid = ?
    ");
    $stmt->execute([$registration_id]);
    $registration = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$registration) {
        echo json_encode(['success' => false, 'error' => 'Регистрация не найдена']);
        exit;
    }
    
    // Отменяем оплату (устанавливаем oplata = false)
    $stmt = $db->prepare("
        UPDATE listreg 
        SET oplata = false 
        WHERE oid = ?
    ");
    $stmt->execute([$registration_id]);
    
    // Логируем действие
    $stmt = $db->prepare("
        INSERT INTO user_actions (users_oid, action, ip_address, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$_SESSION['user_id'], "Отмена оплаты регистрации", $_SERVER['REMOTE_ADDR'] ?? '']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Оплата отменена',
        'registration_id' => $registration_id
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка в cancel_payment.php (admin): " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных']);
}
?> 
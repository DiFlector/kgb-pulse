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
$oplata = $_POST['oplata'] ?? null;

if (!$registration_id || !isset($oplata)) {
    echo json_encode(['success' => false, 'error' => 'Отсутствуют обязательные параметры']);
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
    
    // Обновляем статус оплаты
    $stmt = $db->prepare("
        UPDATE listreg 
        SET oplata = ? 
        WHERE oid = ?
    ");
    $stmt->execute([$oplata ? 1 : 0, $registration_id]);
    
    // Логируем действие
    $action = $oplata ? "Подтверждение оплаты" : "Отмена оплаты";
    $stmt = $db->prepare("
        INSERT INTO user_actions (users_oid, action, ip_address, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$_SESSION['user_id'], $action, $_SERVER['REMOTE_ADDR'] ?? '']);
    
    echo json_encode([
        'success' => true,
        'message' => $oplata ? 'Оплата подтверждена' : 'Оплата отменена',
        'registration_id' => $registration_id,
        'oplata' => $oplata
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка в confirm_payment.php (admin): " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных']);
}
?> 
<?php
session_start();
header('Content-Type: application/json');

// Проверяем авторизацию
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Organizer', 'SuperUser', 'Admin'])) {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

// Проверяем метод запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Неверный метод запроса']);
    exit;
}

// Получаем данные
$userid = $_POST['userid'] ?? null;
$champn = $_POST['champn'] ?? null;

if (!$userid || !$champn) {
    echo json_encode(['success' => false, 'error' => 'Не указаны обязательные параметры']);
    exit;
}

require_once __DIR__ . '/../db/Database.php';

try {
    $db = Database::getInstance();
    
    // Находим регистрацию по userid и champn
    $query = "
        SELECT lr.oid, lr.status, lr.users_oid, lr.meros_oid
        FROM listreg lr
        JOIN users u ON lr.users_oid = u.oid
        JOIN meros m ON lr.meros_oid = m.oid
        WHERE u.userid = ? AND m.champn = ?
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$userid, $champn]);
    $registration = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$registration) {
        echo json_encode(['success' => false, 'error' => 'Регистрация не найдена']);
        exit;
    }
    
    if ($registration['status'] !== 'В очереди') {
        echo json_encode(['success' => false, 'error' => 'Регистрация уже подтверждена или имеет другой статус']);
        exit;
    }
    
    // Обновляем статус на "Подтверждён"
    $updateQuery = "UPDATE listreg SET status = 'Подтверждён' WHERE oid = ?";
    $updateStmt = $db->prepare($updateQuery);
    $updateStmt->execute([$registration['oid']]);
    
    // Логируем действие
    $logQuery = "
        INSERT INTO user_actions (users_oid, action, ip_address, created_at)
        VALUES (?, ?, ?, NOW())
    ";
    $logStmt = $db->prepare($logQuery);
    $logStmt->execute([
        $_SESSION['user_id'],
        "Подтвердил регистрацию спортсмена userid={$userid} на мероприятие champn={$champn}",
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Регистрация успешно подтверждена'
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка в confirm_registration.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка при подтверждении регистрации: ' . $e->getMessage()
    ]);
}
?> 
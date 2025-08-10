<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Secretary', 'SuperUser'])) {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Неверный метод запроса']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Неверные данные запроса']);
    exit;
}

$registrationId = $input['registrationId'] ?? 0;
$status = $input['status'] ?? '';
$oplata = $input['oplata'] ?? false;
$cost = $input['cost'] ?? 0;
$classDistance = $input['class_distance'] ?? null;

if (!$registrationId) {
    echo json_encode(['success' => false, 'error' => 'Не указан ID регистрации']);
    exit;
}

require_once __DIR__ . '/../db/Database.php';

try {
    $db = Database::getInstance();
    $db->beginTransaction();

    $checkQuery = "SELECT oid FROM listreg WHERE oid = ?";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([$registrationId]);

    if (!$checkStmt->fetch()) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Регистрация не найдена']);
        exit;
    }

    $updateFields = [];
    $updateValues = [];

    if ($status) {
        $updateFields[] = "status = ?";
        $updateValues[] = $status;
    }

    $updateFields[] = "oplata = ?";
    $updateValues[] = $oplata ? 1 : 0;

    $updateFields[] = "cost = ?";
    $updateValues[] = $cost;

    if ($classDistance) {
        $updateFields[] = "discipline = ?";
        $updateValues[] = json_encode($classDistance);
    }

    $updateValues[] = $registrationId;

    $updateQuery = "UPDATE listreg SET " . implode(', ', $updateFields) . " WHERE oid = ?";
    $updateStmt = $db->prepare($updateQuery);
    $updateStmt->execute($updateValues);

    $logQuery = "
        INSERT INTO user_actions (users_oid, action, ip_address, created_at)
        VALUES (?, ?, ?, NOW())
    ";
    $logStmt = $db->prepare($logQuery);
    $logStmt->execute([
        $_SESSION['user_id'],
        "Обновил регистрацию ID={$registrationId}. Статус: {$status}, Оплата: " . ($oplata ? 'Да' : 'Нет') . ", Стоимость: {$cost}",
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Регистрация успешно обновлена'
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Ошибка в update_registration.php (secretary): " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка при обновлении регистрации: ' . $e->getMessage()
    ]);
}
?> 
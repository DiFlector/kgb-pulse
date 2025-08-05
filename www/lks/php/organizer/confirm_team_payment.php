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
$teamid = $_POST['teamid'] ?? null;
$champn = $_POST['champn'] ?? null;

if (!$teamid || !$champn) {
    echo json_encode(['success' => false, 'error' => 'Не указаны обязательные параметры']);
    exit;
}

require_once __DIR__ . '/../db/Database.php';

try {
    $db = Database::getInstance();
    
    // Начинаем транзакцию
    $db->beginTransaction();
    
    // Находим все регистрации команды на данном мероприятии
    $query = "
        SELECT lr.oid, lr.status, lr.oplata, lr.users_oid, lr.meros_oid
        FROM listreg lr
        JOIN teams t ON lr.teams_oid = t.oid
        JOIN meros m ON lr.meros_oid = m.oid
        WHERE t.teamid = ? AND m.champn = ?
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$teamid, $champn]);
    $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($registrations)) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Регистрации команды не найдены']);
        exit;
    }
    
    $paidCount = 0;
    $updatedCount = 0;
    
    foreach ($registrations as $registration) {
        // Обновляем только те регистрации, которые еще не оплачены
        if (!$registration['oplata'] && $registration['status'] === 'Подтверждён') {
            $updateQuery = "UPDATE listreg SET oplata = TRUE WHERE oid = ?";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->execute([$registration['oid']]);
            $updatedCount++;
        }
        
        if ($registration['oplata']) {
            $paidCount++;
        }
    }
    
    if ($updatedCount === 0) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Нет регистраций для подтверждения оплаты']);
        exit;
    }
    
    // Логируем действие
    $logQuery = "
        INSERT INTO user_actions (users_oid, action, ip_address, created_at)
        VALUES (?, ?, ?, NOW())
    ";
    $logStmt = $db->prepare($logQuery);
    $logStmt->execute([
        $_SESSION['user_id'],
        "Подтвердил оплату команды teamid={$teamid} на мероприятие champn={$champn}. Обновлено: {$updatedCount}",
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    // Подтверждаем транзакцию
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Оплата команды подтверждена",
        'paid_count' => $paidCount + $updatedCount,
        'updated_count' => $updatedCount
    ]);
    
} catch (Exception $e) {
    // Откатываем транзакцию в случае ошибки
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    error_log("Ошибка в confirm_team_payment.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка при подтверждении оплаты команды: ' . $e->getMessage()
    ]);
}
?> 
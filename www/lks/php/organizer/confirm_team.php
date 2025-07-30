<?php
/**
 * API подтверждения команды организатором
 */
if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/Notification.php";

// Проверяем права доступа
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Admin', 'Organizer', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Доступ запрещен']);
    exit;
}

if (!isset($_POST['teamid']) || !isset($_POST['champn'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Отсутствуют необходимые параметры']);
    exit;
}

$teamid = $_POST['teamid'];
$champn = $_POST['champn'];

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    $notification = new Notification();

    // Получаем oid команды и мероприятия
    $teamStmt = $db->prepare("SELECT oid FROM teams WHERE teamid = ?");
    $teamStmt->execute([$teamid]);
    $teamOid = $teamStmt->fetchColumn();
    
    $eventStmt = $db->prepare("SELECT oid FROM meros WHERE champn = ?");
    $eventStmt->execute([$champn]);
    $eventOid = $eventStmt->fetchColumn();
    
    if (!$teamOid || !$eventOid) {
        throw new Exception('Команда или мероприятие не найдены');
    }
    
    // Получаем всех участников команды
    $stmt = $db->prepare("
        SELECT l.oid, l.users_oid, u.fio, u.email, u.userid, m.meroname 
        FROM listreg l 
        JOIN users u ON l.users_oid = u.oid 
        JOIN meros m ON l.meros_oid = m.oid 
        WHERE l.teams_oid = ? AND l.meros_oid = ? AND l.status = 'Ожидание команды'
    ");
    $stmt->execute([$teamOid, $eventOid]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($participants)) {
        throw new Exception('Команда не найдена или уже подтверждена');
    }

    $db->beginTransaction();

    $confirmedCount = 0;
    foreach ($participants as $participant) {
        // Обновляем статус на "Подтверждён"
        $updateStmt = $db->prepare("UPDATE listreg SET status = 'Подтверждён' WHERE oid = ?");
        $updateStmt->execute([$participant['oid']]);

        // Создаем уведомление
        $message = sprintf('Ваше участие в команде на мероприятии "%s" подтверждено.', $participant['meroname']);
        $notification->createNotification(
            $participant['userid'],
            'status_change',
            'Участие в команде подтверждено',
            $message
        );

        $confirmedCount++;
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Команда успешно подтверждена',
        'confirmed_count' => $confirmedCount
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Error confirming team: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Ошибка при подтверждении команды',
        'details' => $e->getMessage()
    ]);
}
?> 
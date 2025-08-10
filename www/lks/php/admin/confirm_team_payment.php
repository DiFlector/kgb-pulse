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

$teamid = $_POST['teamid'] ?? null;
$champn = $_POST['champn'] ?? null;

if (!$teamid || !$champn) {
    echo json_encode(['success' => false, 'error' => 'Отсутствуют обязательные параметры']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Получаем всех участников команды
    $stmt = $db->prepare("
        SELECT lr.oid, lr.users_oid, lr.oplata, u.fio, u.email, m.meroname
        FROM listreg lr
        JOIN users u ON lr.users_oid = u.oid
        JOIN meros m ON lr.meros_oid = m.oid
        JOIN teams t ON lr.teams_oid = t.oid
        WHERE t.teamid = ? AND m.champn = ?
    ");
    $stmt->execute([$teamid, $champn]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($participants)) {
        echo json_encode(['success' => false, 'error' => 'Команда не найдена']);
        exit;
    }
    
    $paid_count = 0;
    
    // Подтверждаем оплату всех участников команды
    foreach ($participants as $participant) {
        if (!$participant['oplata']) {
            $stmt = $db->prepare("
                UPDATE listreg 
                SET oplata = true 
                WHERE oid = ?
            ");
            $stmt->execute([$participant['oid']]);
            $paid_count++;
        }
    }
    
    // Логируем действие
    $stmt = $db->prepare("
        INSERT INTO user_actions (users_oid, action, ip_address, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$_SESSION['user_id'], "Подтверждение оплаты команды {$teamid}", $_SERVER['REMOTE_ADDR'] ?? '']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Оплата команды подтверждена',
        'teamid' => $teamid,
        'champn' => $champn,
        'paid_count' => $paid_count
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка в confirm_team_payment.php (admin): " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных']);
}
?> 
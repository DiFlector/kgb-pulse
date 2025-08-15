<?php
/**
 * Удаление выбранных команд организатором/админом/суперпользователем
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../common/Auth.php';
require_once __DIR__ . '/../db/Database.php';

try {
    $auth = new Auth();
    if (!$auth->isAuthenticated() || !in_array($auth->getUserRole(), ['Organizer', 'Admin', 'SuperUser'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data || empty($data['teams']) || !is_array($data['teams'])) {
        throw new Exception('Не выбраны команды для удаления');
    }

    $db = Database::getInstance();
    $pdo = $db->getPDO();
    $pdo->beginTransaction();

    $deletedCount = 0;

    foreach ($data['teams'] as $team) {
        $teamId = isset($team['teamid']) ? (int)$team['teamid'] : null;
        $eventId = isset($team['eventId']) ? (int)$team['eventId'] : null; // это meros.oid

        if (!$teamId || !$eventId) {
            continue;
        }

        // Находим oid команды по teamid
        $teamRow = $db->fetchOne('SELECT oid FROM teams WHERE teamid = ?', [$teamId]);
        if (!$teamRow) {
            continue;
        }
        $teamOid = (int)$teamRow['oid'];

        // Удаляем связанные регистрации по этой команде и мероприятию
        $stmt = $pdo->prepare('DELETE FROM listreg WHERE teams_oid = ? AND meros_oid = ?');
        $stmt->execute([$teamOid, $eventId]);

        // Если больше нет регистраций, можно удалить саму команду (опционально)
        $left = $db->fetchOne('SELECT COUNT(*) AS c FROM listreg WHERE teams_oid = ?', [$teamOid]);
        if (isset($left['c']) && (int)$left['c'] === 0) {
            $pdo->prepare('DELETE FROM teams WHERE oid = ?')->execute([$teamOid]);
        }

        $deletedCount++;
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'deleted' => $deletedCount
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Ошибка удаления команд: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>


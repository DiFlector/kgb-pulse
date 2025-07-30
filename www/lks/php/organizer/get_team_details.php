<?php
/**
 * API для получения подробной информации о команде
 * Возвращает данные команды и всех её участников
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/../db/Database.php";

$auth = new Auth();
if (!$auth->isAuthenticated() || !in_array($auth->getUserRole(), ['Organizer', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

try {
    $teamId = $_GET['teamId'] ?? null;
    $eventId = $_GET['eventId'] ?? null;
    
    if (!$teamId || !$eventId) {
        throw new Exception('Не указаны обязательные параметры');
    }
    
    $db = Database::getInstance();
    
    // Получаем информацию о команде
    $team = $db->fetchOne("
        SELECT 
            t.oid,
            t.teamid,
            t.teamname,
            t.teamcity,
            t.persons_amount,
            t.persons_all,
            t.class
        FROM teams t
        WHERE t.teamid = ?
    ", [$teamId]);
    
    if (!$team) {
        throw new Exception('Команда не найдена');
    }
    
    // Получаем информацию о мероприятии
    $event = $db->fetchOne("
        SELECT 
            m.oid,
            m.champn,
            m.meroname
        FROM meros m
        WHERE m.champn = ?
    ", [$eventId]);
    
    if (!$event) {
        throw new Exception('Мероприятие не найдено');
    }
    
    // Проверяем что команда участвует в мероприятии
    $participation = $db->fetchOne("
        SELECT COUNT(*) as count
        FROM listreg lr
        WHERE lr.teams_oid = ? AND lr.meros_oid = ?
    ", [$team['oid'], $event['oid']]);
    
    if (!$participation || $participation['count'] == 0) {
        throw new Exception('Команда не участвует в данном мероприятии');
    }
    
    // Получаем всех участников команды
    $members = $db->fetchAll("
        SELECT 
            lr.oid,
            lr.status,
            lr.role,
            lr.cost,
            lr.oplata,
            u.fio,
            u.email,
            u.telephone,
            u.userid as user_number
        FROM listreg lr
        JOIN users u ON lr.users_oid = u.oid
        WHERE lr.teams_oid = ? AND lr.meros_oid = ?
        ORDER BY 
            CASE lr.role 
                WHEN 'captain' THEN 1 
                WHEN 'coxswain' THEN 2
                WHEN 'drummer' THEN 3
                WHEN 'member' THEN 4 
                WHEN 'reserve' THEN 5 
                ELSE 6 
            END, lr.oid ASC
    ", [$team['oid'], $event['oid']]);
    
    // Группируем участников по ролям
    $captain = null;
    $coxswain = null;
    $drummer = null;
    $members_list = [];
    $reserves = [];
    
    foreach ($members as $member) {
        switch ($member['role']) {
            case 'captain':
                $captain = $member;
                break;
            case 'coxswain':
                $coxswain = $member;
                break;
            case 'drummer':
                $drummer = $member;
                break;
            case 'member':
                $members_list[] = $member;
                break;
            case 'reserve':
                $reserves[] = $member;
                break;
        }
    }
    
    // Определяем общий статус команды
    $teamStatus = 'Ожидание команды';
    if (!empty($members)) {
        $confirmedCount = 0;
        foreach ($members as $member) {
            if ($member['status'] === 'Подтверждён') {
                $confirmedCount++;
            }
        }
        
        // Если есть капитан и минимум 1 гребец
        if ($captain && count($members_list) > 0) {
            $teamStatus = 'Сформирована';
        }
    }
    
    echo json_encode([
        'success' => true,
        'team' => [
            'oid' => $team['oid'],
            'teamid' => $team['teamid'],
            'teamname' => $team['teamname'],
            'teamcity' => $team['teamcity'],
            'meroname' => $event['meroname'],
            'eventId' => $eventId,
            'status' => $teamStatus,
            'captain' => $captain,
            'coxswain' => $coxswain,
            'drummer' => $drummer,
            'members' => $members_list,
            'reserves' => $reserves,
            'total_count' => count($members)
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Ошибка получения данных команды: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?> 
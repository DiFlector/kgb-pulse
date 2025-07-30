<?php
/**
 * API для обновления информации о команде (название, город)
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
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Не получены данные запроса');
    }
    
    $teamId = $input['teamId'] ?? null;
    $eventId = $input['eventId'] ?? null;
    $teamName = trim($input['teamName'] ?? '');
    $teamCity = trim($input['teamCity'] ?? '');
    
    if (!$teamId || !$eventId) {
        throw new Exception('Не указаны обязательные параметры');
    }
    
    if (empty($teamName)) {
        throw new Exception('Название команды не может быть пустым');
    }
    
    if (empty($teamCity)) {
        throw new Exception('Город команды не может быть пустым');
    }
    
    $db = Database::getInstance();
    
    // Получаем информацию о команде
    $team = $db->fetchOne("
        SELECT 
            t.oid,
            t.teamid,
            t.teamname,
            t.teamcity
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
            m.meroname,
            m.created_by
        FROM meros m
        WHERE m.champn = ?
    ", [$eventId]);
    
    if (!$event) {
        throw new Exception('Мероприятие не найдено');
    }
    
    // Проверяем права доступа
    $userRole = $auth->getUserRole();
    $userId = $auth->getCurrentUser()['user_id'];
    
    // Администраторы и суперпользователи имеют полный доступ
    $hasFullAccess = in_array($userRole, ['Admin', 'SuperUser']);
    
    // Для организаторов проверяем, что мероприятие принадлежит им
    if (!$hasFullAccess) {
        if ($event['created_by'] != $userId) {
            throw new Exception('У вас нет прав на редактирование этой команды');
        }
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
    
    // Обновляем информацию о команде
    $stmt = $db->prepare("
        UPDATE teams 
        SET teamname = ?, teamcity = ?
        WHERE oid = ?
    ");
    
    $stmt->execute([$teamName, $teamCity, $team['oid']]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Не удалось обновить информацию о команде');
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Информация о команде успешно обновлена',
        'team' => [
            'teamid' => $team['teamid'],
            'teamname' => $teamName,
            'teamcity' => $teamCity,
            'meroname' => $event['meroname']
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Ошибка обновления информации о команде: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?> 
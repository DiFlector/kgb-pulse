<?php
/**
 * API для получения списка команд для секретаря
 */

require_once __DIR__ . '/../db/Database.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

// Проверяем, не запущена ли уже сессия
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    // Простая проверка авторизации через сессию
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Требуется авторизация'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (!isset($_SESSION['user_role'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Роль пользователя не определена'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userRole = $_SESSION['user_role'];
    
    // Проверка прав доступа
    if (!in_array($userRole, ['Secretary', 'SuperUser', 'Admin'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Недостаточно прав доступа'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Подключение к БД
    $db = Database::getInstance();
    $conn = $db->getPDO();

    // Получаем все команды с количеством участников
    $query = "
        SELECT 
            t.oid,
            t.teamid,
            t.teamname,
            t.teamcity,
            t.persons_amount,
            t.persons_all,
            t.class,
            COUNT(lr.oid) as current_members
        FROM teams t
        LEFT JOIN listreg lr ON t.oid = lr.teams_oid
        GROUP BY t.oid, t.teamid, t.teamname, t.teamcity, t.persons_amount, t.persons_all, t.class
        ORDER BY t.teamname ASC
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute();
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Форматируем данные для фронтенда
    $formattedTeams = [];
    foreach ($teams as $team) {
        $formattedTeams[] = [
            'oid' => $team['oid'],
            'teamid' => $team['teamid'],
            'teamname' => $team['teamname'],
            'teamcity' => $team['teamcity'],
            'persons_amount' => (int)$team['persons_amount'],
            'persons_all' => (int)$team['persons_all'],
            'current_members' => (int)$team['current_members'],
            'class' => $team['class'],
            'is_full' => (int)$team['current_members'] >= (int)$team['persons_all']
        ];
    }

    echo json_encode([
        'success' => true,
        'teams' => $formattedTeams
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Ошибка в get_teams.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Внутренняя ошибка сервера'
    ], JSON_UNESCAPED_UNICODE);
}
?> 
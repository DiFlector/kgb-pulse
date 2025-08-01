<?php
/**
 * API для получения данных очереди спортсменов для секретаря
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

    // Получаем всех участников с их данными
    $query = "
        SELECT 
            lr.oid,
            lr.users_oid,
            lr.meros_oid,
            lr.teams_oid,
            lr.discipline,
            lr.oplata,
            lr.cost,
            lr.status,
            lr.role,
            u.userid,
            u.fio,
            u.email,
            u.telephone,
            u.sex,
            u.city,
            u.boats,
            u.sportzvanie,
            t.teamname,
            t.teamcity,
            t.teamid as teamid,
            m.meroname as event_name,
            m.merodata,
            m.champn
        FROM listreg lr
        LEFT JOIN users u ON lr.users_oid = u.oid
        LEFT JOIN teams t ON lr.teams_oid = t.oid
        LEFT JOIN meros m ON lr.meros_oid = m.oid
        WHERE lr.status IN ('В очереди', 'Подтверждён', 'Ожидание команды')
        ORDER BY m.merodata ASC, u.fio ASC
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute();
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Форматируем данные для фронтенда
    $formattedParticipants = [];
    foreach ($participants as $participant) {
        $formattedParticipants[] = [
            'oid' => $participant['oid'],
            'userid' => $participant['userid'],
            'fio' => $participant['fio'],
            'email' => $participant['email'],
            'telephone' => $participant['telephone'],
            'sex' => $participant['sex'],
            'city' => $participant['city'],
            'boats' => $participant['boats'],
            'sportzvanie' => $participant['sportzvanie'],
            'status' => $participant['status'],
            'oplata' => (bool)$participant['oplata'],
            'cost' => $participant['cost'],
            'role' => $participant['role'],
            'discipline' => $participant['discipline'],
            'event_name' => $participant['event_name'],
            'merodata' => $participant['merodata'],
            'champn' => $participant['champn'],
            'teamid' => $participant['teamid'],
            'teamname' => $participant['teamname'],
            'teamcity' => $participant['teamcity'],
            'team' => $participant['teamname'] ? [
                'oid' => $participant['teams_oid'],
                'teamid' => $participant['teamid'],
                'teamname' => $participant['teamname'],
                'teamcity' => $participant['teamcity']
            ] : null
        ];
    }

    echo json_encode([
        'success' => true,
        'participants' => $formattedParticipants
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Ошибка в get_queue_data.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Внутренняя ошибка сервера'
    ], JSON_UNESCAPED_UNICODE);
}
?> 
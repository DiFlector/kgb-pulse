<?php
session_start();
header('Content-Type: application/json');

// Проверяем авторизацию
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Organizer', 'SuperUser', 'Admin'])) {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

require_once __DIR__ . '/../db/Database.php';

try {
    $db = Database::getInstance();
    
    // Получаем данные очереди с информацией о пользователях, мероприятиях и командах
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
                    u.sex,
                    u.telephone,
                    u.birthdata,
                    u.country,
                    u.city,
                    u.accessrights,
                    u.boats,
                    u.sportzvanie,
                    m.champn,
                    m.meroname,
                    m.merodata,
                    m.status as event_status,
                    t.teamid,
                    t.teamname,
                    t.teamcity,
                    t.persons_amount,
                    t.persons_all,
                    t.class as team_class
                FROM listreg lr
                LEFT JOIN users u ON lr.users_oid = u.oid
                LEFT JOIN meros m ON lr.meros_oid = m.oid
                LEFT JOIN teams t ON lr.teams_oid = t.oid
                WHERE lr.status IN ('В очереди', 'Подтверждён', 'Ожидание команды')
                ORDER BY lr.status ASC, u.fio ASC
            ";
    
    $result = $db->query($query);
    $queue = [];
    
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        // Определяем, является ли это командной регистрацией
        $isTeam = !empty($row['teams_oid']);
        
        $queueItem = [
            'oid' => $row['oid'],
            'userid' => $row['userid'],
            'fio' => $row['fio'],
            'email' => $row['email'],
            'sex' => $row['sex'],
            'telephone' => $row['telephone'],
            'birthdata' => $row['birthdata'],
            'country' => $row['country'],
            'city' => $row['city'],
            'accessrights' => $row['accessrights'],
            'boats' => $row['boats'],
            'sportzvanie' => $row['sportzvanie'],
            'champn' => $row['champn'],
            'meroname' => $row['meroname'],
            'merodata' => $row['merodata'],
            'event_status' => $row['event_status'],
            'discipline' => $row['discipline'],
            'oplata' => $row['oplata'],
            'cost' => $row['cost'],
            'status' => $row['status'],
            'role' => $row['role'],
            'teamid' => $row['teamid'],
            'teamname' => $row['teamname'],
            'teamcity' => $row['teamcity'],
            'persons_amount' => $row['persons_amount'],
            'persons_all' => $row['persons_all'],
            'team_class' => $row['team_class'],
            'is_team' => $isTeam
        ];
        
        $queue[] = $queueItem;
    }
    
    echo json_encode([
        'success' => true,
        'queue' => $queue,
        'total' => count($queue)
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка в get_queue.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка при получении данных очереди: ' . $e->getMessage()
    ]);
}
?> 
<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Secretary', 'SuperUser'])) {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

require_once __DIR__ . '/../db/Database.php';

try {
    $db = Database::getInstance();
    
    // Получаем участников в статусе "Подтверждён" (секретарь работает с подтвержденными)
    $query = "
        SELECT 
            lr.oid,
            lr.status,
            lr.oplata,
            lr.cost,
            lr.discipline,
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
            m.class_distance as event_classes,
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
        WHERE lr.status = 'Подтверждён'
        AND m.status IN ('В ожидании', 'Регистрация', 'Регистрация закрыта')
        ORDER BY m.merodata DESC, u.fio ASC
    ";
    
    $result = $db->query($query);
    $queue = [];
    
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
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
            'status' => $row['status'],
            'oplata' => $row['oplata'],
            'cost' => $row['cost'],
            'discipline' => $row['discipline'],
            'role' => $row['role'],
            'champn' => $row['champn'],
            'meroname' => $row['meroname'],
            'merodata' => $row['merodata'],
            'event_status' => $row['event_status'],
            'event_classes' => $row['event_classes'],
            'teamid' => $row['teamid'],
            'teamname' => $row['teamname'],
            'teamcity' => $row['teamcity'],
            'persons_amount' => $row['persons_amount'],
            'persons_all' => $row['persons_all'],
            'team_class' => $row['team_class']
        ];
        
        $queue[] = $queueItem;
    }
    
    echo json_encode([
        'success' => true,
        'queue' => $queue,
        'total' => count($queue)
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка в get_queue.php (secretary): " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка при получении данных очереди: ' . $e->getMessage()
    ]);
}
?> 
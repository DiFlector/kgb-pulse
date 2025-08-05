<?php
session_start();
header('Content-Type: application/json');

// Проверяем авторизацию
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Organizer', 'SuperUser', 'Admin'])) {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

// Получаем ID регистрации
$oid = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$oid) {
    echo json_encode(['success' => false, 'error' => 'Не указан ID регистрации']);
    exit;
}

require_once __DIR__ . '/../db/Database.php';

try {
    $db = Database::getInstance();
    
    // Получаем данные регистрации с информацией о пользователе и мероприятии
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
        WHERE lr.oid = ?
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$oid]);
    $registration = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$registration) {
        echo json_encode(['success' => false, 'error' => 'Регистрация не найдена']);
        exit;
    }
    
    // Получаем данные о команде, если это командная регистрация
    $teamMembers = [];
    if ($registration['teams_oid']) {
        $teamQuery = "
            SELECT 
                lr.oid,
                lr.status,
                lr.oplata,
                lr.cost,
                lr.role,
                u.fio,
                u.email,
                u.userid
            FROM listreg lr
            JOIN users u ON lr.users_oid = u.oid
            WHERE lr.teams_oid = ? AND lr.meros_oid = ?
        ";
        
        $teamStmt = $db->prepare($teamQuery);
        $teamStmt->execute([$registration['teams_oid'], $registration['meros_oid']]);
        $teamMembers = $teamStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Парсим классы мероприятия
    $eventClasses = [];
    if ($registration['event_classes']) {
        $eventClasses = json_decode($registration['event_classes'], true);
    }
    
    echo json_encode([
        'success' => true,
        'registration' => $registration,
        'team_members' => $teamMembers,
        'event_classes' => $eventClasses,
        'event_data' => [
            'champn' => $registration['champn'],
            'meroname' => $registration['meroname'],
            'merodata' => $registration['merodata']
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка в get_registration.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка при получении данных регистрации: ' . $e->getMessage()
    ]);
}
?> 
<?php
// API для поиска участников - Организатор
session_start();

// Проверка авторизации
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Organizer', 'Admin', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

require_once __DIR__ . '/../db/Database.php';

$db = Database::getInstance();

// Получаем параметры поиска
$searchTerm = $_GET['search'] ?? '';
$eventId = $_GET['event_id'] ?? '';

if (empty($searchTerm)) {
    echo json_encode(['success' => false, 'message' => 'Введите номер спортсмена или email для поиска']);
    exit;
}

try {
    // Поиск участника по номеру спортсмена или email
    $query = "
        SELECT 
            u.oid,
            u.userid,
            u.fio,
            u.email,
            u.telephone,
            u.sex,
            u.birthdata,
            u.country,
            u.city,
            u.boats,
            u.sportzvanie,
            u.accessrights
        FROM users u
        WHERE (u.userid::text = ? OR u.email ILIKE ?)
        ORDER BY u.userid
        LIMIT 10
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$searchTerm, '%' . $searchTerm . '%']);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($participants)) {
        echo json_encode([
            'success' => true, 
            'found' => false,
            'message' => 'Участник не найден. Вы можете зарегистрировать нового участника.'
        ]);
        exit;
    }
    
    // Проверяем, зарегистрирован ли участник на данное мероприятие
    if (!empty($eventId)) {
        foreach ($participants as &$participant) {
            $regQuery = "
                SELECT l.oid, l.status, l.teams_oid, t.teamname
                FROM listreg l
                LEFT JOIN teams t ON l.teams_oid = t.oid
                WHERE l.users_oid = ? AND l.meros_oid = ?
            ";
            $regStmt = $db->prepare($regQuery);
            $regStmt->execute([$participant['oid'], $eventId]);
            $registration = $regStmt->fetch(PDO::FETCH_ASSOC);
            
            $participant['registration'] = $registration;
            $participant['is_registered'] = !empty($registration);
        }
    }
    
    echo json_encode([
        'success' => true,
        'found' => true,
        'participants' => $participants
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка поиска участника: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка поиска участника']);
} 
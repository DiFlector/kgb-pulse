<?php
/**
 * API для получения списка участников, доступных для добавления в команду
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/../db/Database.php";

$auth = new Auth();
if (!$auth->isAuthenticated() || !in_array($auth->getUserRole(), ['Organizer', 'SuperUser', 'Admin', 'Secretary'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

try {
    $eventId = $_GET['eventId'] ?? null;
    $classType = $_GET['classType'] ?? null;
    
    if (!$eventId || !$classType) {
        throw new Exception('Не указаны обязательные параметры');
    }
    
    $db = Database::getInstance();
    
    // Получаем участников, которые зарегистрированы на мероприятие, но не в командах
    $participants = $db->fetchAll("
        SELECT 
            u.oid,
            u.userid,
            u.fio,
            u.email,
            u.telephone,
            u.sex,
            u.boats,
            lr.oid as registration_id,
            lr.status,
            lr.discipline
        FROM users u
        JOIN listreg lr ON u.oid = lr.users_oid
        WHERE lr.meros_oid = ? 
        AND lr.teams_oid IS NULL
        AND lr.status IN ('В очереди', 'Подтверждён', 'Зарегистрирован')
        ORDER BY u.fio ASC
    ", [$eventId]);
    
    // Фильтруем участников по классу лодки
    $filteredParticipants = [];
    foreach ($participants as $participant) {
        $boats = $participant['boats'] ?? [];
        $discipline = json_decode($participant['discipline'], true);
        
        // Проверяем, может ли участник участвовать в данном классе
        $canParticipate = false;
        
        // Проверяем boats пользователя
        if (is_array($boats) && in_array($classType, $boats)) {
            $canParticipate = true;
        }
        
        // Проверяем discipline регистрации
        if ($discipline && isset($discipline[$classType])) {
            $canParticipate = true;
        }
        
        if ($canParticipate) {
            $filteredParticipants[] = [
                'oid' => $participant['oid'],
                'userid' => $participant['userid'],
                'fio' => $participant['fio'],
                'email' => $participant['email'],
                'telephone' => $participant['telephone'],
                'sex' => $participant['sex'],
                'registration_id' => $participant['registration_id'],
                'status' => $participant['status']
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'participants' => $filteredParticipants
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Ошибка получения доступных участников: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
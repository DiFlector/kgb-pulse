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

    // Параметры оставляем обязательными для совместимости вызовов, но список формируем из всех пользователей
    if (!$eventId || !$classType) {
        throw new Exception('Не указаны обязательные параметры');
    }

    $db = Database::getInstance();

    // Возвращаем ВСЕХ пользователей системы с информацией об их регистрации на выбранное мероприятие (если есть)
    $rows = $db->fetchAll(
        "
        SELECT 
            u.oid,
            u.userid,
            u.fio,
            u.email,
            u.telephone,
            u.sex,
            u.boats,
            lr.oid AS registration_id,
            lr.status
        FROM users u
        LEFT JOIN listreg lr 
            ON lr.users_oid = u.oid 
           AND lr.meros_oid = ?
        ORDER BY u.fio ASC
        ",
        [$eventId]
    );

    $participants = [];
    foreach ($rows as $row) {
        $participants[] = [
            'oid' => $row['oid'],
            'userid' => $row['userid'],
            'fio' => $row['fio'],
            'email' => $row['email'],
            'telephone' => $row['telephone'],
            'sex' => $row['sex'],
            'registration_id' => $row['registration_id'],
            // Если нет регистрации на мероприятие — показываем как "Не зарегистрирован"
            'status' => $row['status'] ?: 'Не зарегистрирован'
        ];
    }

    echo json_encode([
        'success' => true,
        'participants' => $participants
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Ошибка получения доступных участников: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
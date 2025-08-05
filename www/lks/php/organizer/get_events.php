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
    
    // Получаем список мероприятий
    $query = "
        SELECT 
            oid,
            champn,
            meroname,
            merodata,
            status,
            defcost,
            created_by
        FROM meros
        WHERE status IN ('В ожидании', 'Регистрация', 'Регистрация закрыта')
        ORDER BY merodata DESC, meroname ASC
    ";
    
    $result = $db->query($query);
    $events = [];
    
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $events[] = [
            'oid' => $row['oid'],
            'champn' => $row['champn'],
            'meroname' => $row['meroname'],
            'merodata' => $row['merodata'],
            'status' => $row['status'],
            'defcost' => $row['defcost'],
            'created_by' => $row['created_by']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'events' => $events,
        'total' => count($events)
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка в get_events.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка при получении списка мероприятий: ' . $e->getMessage()
    ]);
}
?> 
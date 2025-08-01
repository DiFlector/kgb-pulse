<?php
/**
 * API для получения списка мероприятий и классов для создания команды
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
    $db = Database::getInstance();
    
    // Получаем все мероприятия со статусом "Регистрация" или "Регистрация закрыта"
    $events = $db->fetchAll("
        SELECT 
            oid,
            champn,
            meroname,
            merodata,
            class_distance,
            status
        FROM meros 
        WHERE TRIM(status::text) IN ('Регистрация', 'Регистрация закрыта')
        ORDER BY merodata DESC, meroname ASC
    ");
    
    // Обрабатываем каждое мероприятие
    $processedEvents = [];
    foreach ($events as $event) {
        $classDistance = json_decode($event['class_distance'], true);
        
        // Фильтруем только групповые классы
        $groupClasses = [];
        if ($classDistance) {
            foreach ($classDistance as $class => $details) {
                // Определяем групповые классы (не одиночные)
                if (strpos($class, '-2') !== false || 
                    strpos($class, '-4') !== false || 
                    strpos($class, 'D-10') !== false ||
                    strpos($class, 'HD-1') !== false ||
                    strpos($class, 'OD-1') !== false ||
                    strpos($class, 'OD-2') !== false ||
                    strpos($class, 'OC-1') !== false) {
                    
                    $groupClasses[$class] = $details;
                }
            }
        }
        
        if (!empty($groupClasses)) {
            $processedEvents[] = [
                'oid' => $event['oid'],
                'champn' => $event['champn'],
                'meroname' => $event['meroname'],
                'merodata' => $event['merodata'],
                'status' => $event['status'],
                'group_classes' => $groupClasses
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'events' => $processedEvents
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Ошибка получения мероприятий и классов: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
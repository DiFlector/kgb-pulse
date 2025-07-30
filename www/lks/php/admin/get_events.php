<?php
/**
 * API для получения списка мероприятий
 */
session_start();

// УДАЛЁН fallback для тестов: никаких подстановок user_id и user_role

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . "/../db/Database.php";

try {
    $db = Database::getInstance();
    
    // Получаем список мероприятий - исправляем поля согласно скриншоту
    $events = $db->fetchAll("
        SELECT champn, meroname as name, merodata, class_distance 
        FROM meros 
        ORDER BY champn DESC
        LIMIT 50
    ");
    
    // Преобразуем данные для совместимости
    $formattedEvents = [];
    foreach ($events as $event) {
        $formattedEvents[] = [
            'champn' => $event['champn'],
            'name' => $event['name'],
            'merodata' => $event['merodata'],
            'class_distance' => $event['class_distance']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'events' => $formattedEvents
    ]);
    exit;
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка при получении мероприятий: ' . $e->getMessage()
    ]);
    exit;
}
?> 
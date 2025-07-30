<?php
/**
 * API для получения событий календаря
 * Для организаторов
 */

// Заголовки JSON и CORS
if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Origin: *');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Methods: GET, OPTIONS');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

// Проверка авторизации
if (!isset($_SESSION)) {
    session_start();
}

// УДАЛЁН fallback для тестов: никаких подстановок user_id и user_role

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/Auth.php";

$auth = new Auth();

// Проверка авторизации и прав организатора
if (!$auth->isAuthenticated() || !$auth->hasAnyRole(['Admin', 'Organizer', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Доступ запрещен'
    ]);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    
    // Получаем все мероприятия для календаря
    $sql = "
        SELECT 
            m.champn,
            m.merodata,
            m.meroname,
            m.class_distance,
            m.defcost,
            m.status,
            COUNT(lr.oid) as registrations_count
        FROM meros m
        LEFT JOIN listreg lr ON m.oid = lr.meros_oid
        GROUP BY m.champn, m.merodata, m.meroname, m.class_distance, m.defcost, m.status
        ORDER BY m.merodata DESC
        LIMIT 50
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Форматируем данные для календаря
    $calendarEvents = [];
    foreach ($events as $event) {
        // Парсим данные мероприятия
        $eventData = json_decode($event['merodata'], true);
        
        // Определяем цвет события в зависимости от статуса
        $color = '#007bff'; // синий по умолчанию
        switch ($event['status']) {
            case 'В ожидании':
                $color = '#6c757d'; // серый
                break;
            case 'Регистрация':
                $color = '#28a745'; // зеленый
                break;
            case 'Регистрация закрыта':
                $color = '#ffc107'; // желтый
                break;
            case 'Результаты':
                $color = '#17a2b8'; // голубой
                break;
            case 'Завершено':
                $color = '#6f42c1'; // фиолетовый
                break;
            case 'Перенесено':
                $color = '#dc3545'; // красный
                break;
        }
        
        // Извлекаем даты из данных мероприятия
        $startDate = isset($eventData['start_date']) ? $eventData['start_date'] : date('Y-m-d');
        $endDate = isset($eventData['end_date']) ? $eventData['end_date'] : $startDate;
        
        $calendarEvents[] = [
            'id' => $event['champn'],
            'title' => $event['meroname'],
            'start' => $startDate,
            'end' => $endDate,
            'color' => $color,
            'description' => $event['status'],
            'extendedProps' => [
                'status' => $event['status'],
                'cost' => $event['defcost'],
                'registrations' => $event['registrations_count'],
                'classes' => array_keys(json_decode($event['class_distance'], true) ?: [])
            ]
        ];
    }
    
    echo json_encode([
        'success' => true,
        'events' => $calendarEvents
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка получения событий календаря: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка получения событий календаря'
    ]);
}
?> 
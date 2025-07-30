<?php
/**
 * API для получения формы регистрации на мероприятие
 * Поддерживает все роли с учетом прав доступа
 */

if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/EventRegistration.php";
require_once __DIR__ . "/../helpers.php";

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

// Включаем отладку для диагностики
error_reporting(E_ALL);
ini_set('display_errors', 0); // Отключаем вывод ошибок в ответ
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/php_errors.log');

// Логируем запрос для отладки
error_log("API Request: " . ($_SERVER['REQUEST_URI'] ?? 'test-mode') . " | Action: " . ($_GET['action'] ?? 'none'));

// Проверка авторизации или тестовый режим
$isTestMode = defined('TEST_MODE') || isset($_GET['test']) || isset($_COOKIE['test_user']);

if (!isset($_SESSION['user_id']) && !$isTestMode) {
    http_response_code(401);
    echo json_encode(['error' => 'Необходима авторизация']);
    exit;
}

// Получаем данные пользователя
if ($isTestMode) {
    // Тестовый режим
    $userId = isset($_COOKIE['test_user']) ? intval($_COOKIE['test_user']) : 1102;
    $userRole = 'Sportsman';
    
    // Проверяем существование пользователя в БД
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT accessrights FROM users WHERE userid = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        $userRole = $user['accessrights'];
    }
        } else {
    // Обычный режим
    $userId = $_SESSION['user_id'];
    $userRole = $_SESSION['user_role'];
}

try {
    $eventRegistration = new EventRegistration($userId, $userRole);
    
    // Получаем параметры запроса
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_events':
            // Получить список доступных мероприятий
            $events = $eventRegistration->getAvailableEvents();
            echo json_encode(['success' => true, 'events' => $events]);
            break;
            
        case 'get_event_info':
            // Получить информацию о мероприятии
            $eventId = $_GET['event_id'] ?? 0;
            if (!$eventId) {
                throw new Exception('Не указан ID мероприятия');
            }
            
            $eventInfo = $eventRegistration->getEventInfo($eventId);
            if (!$eventInfo) {
                throw new Exception('Мероприятие не найдено');
            }
            
            echo json_encode(['success' => true, 'event' => $eventInfo]);
            break;
            
        case 'get_classes':
            // Получить доступные классы лодок
            $eventId = $_GET['event_id'] ?? 0;
            if (!$eventId) {
                throw new Exception('Не указан ID мероприятия');
            }
            
            $classes = $eventRegistration->getAvailableClasses($eventId);
            echo json_encode(['success' => true, 'classes' => $classes]);
            break;
            
        case 'get_sexes':
            // Получить доступные полы
            $eventId = $_GET['event_id'] ?? 0;
            $class = $_GET['class'] ?? null;
            
            if (!$eventId) {
                throw new Exception('Не указан ID мероприятия');
            }
            
            $sexes = $eventRegistration->getAvailableSexes($eventId, $class);
            echo json_encode(['success' => true, 'sexes' => $sexes]);
            break;
            
        case 'get_boat_type':
            // Получить тип лодки (одиночка/команда)
            $class = $_GET['class'] ?? '';
            if (!$class) {
                throw new Exception('Не указан класс лодки');
            }
            
            $boatType = $eventRegistration->getBoatType($class);
            $maxParticipants = $eventRegistration->getMaxParticipants($class);
            
            echo json_encode([
                'success' => true, 
                'boat_type' => $boatType,
                'max_participants' => $maxParticipants
            ]);
            break;
            
        case 'search_users':
            // Поиск пользователей (для организаторов и секретарей)
            $query = $_GET['query'] ?? '';
            $sex = $_GET['sex'] ?? null;
            
            if (strlen($query) < 2) {
                throw new Exception('Запрос должен содержать минимум 2 символа');
            }
            
            $users = $eventRegistration->searchUsers($query, $sex);
            echo json_encode(['success' => true, 'users' => $users]);
            break;
            
        case 'search_users_secure':
            // Безопасный поиск пользователей (для спортсменов)
            $query = $_GET['query'] ?? '';
            $searchBy = $_GET['search_by'] ?? 'email';
            
            if (!$query) {
                throw new Exception('Пустой запрос поиска');
            }
            
            $user = $eventRegistration->searchUserSecure($query, $searchBy);
            
            if ($user) {
                echo json_encode(['success' => true, 'user' => $user]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Спортсмен не найден']);
            }
            break;
            
        case 'get_user_registrations':
            // Получить регистрации пользователя
            $targetUserId = $_GET['user_id'] ?? $userId;
            
            // Проверяем права на просмотр чужих регистраций
            if ($targetUserId != $userId && !$eventRegistration->canRegisterOthers()) {
                throw new Exception('У вас нет прав на просмотр регистраций других пользователей');
            }
            
            $registrations = $eventRegistration->getUserRegistrations($targetUserId);
            echo json_encode(['success' => true, 'registrations' => $registrations]);
            break;
            
        case 'get_user_info':
            // Получить информацию о пользователе
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT userid, fio, email, telephone, sex, city, accessrights FROM users WHERE userid = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $user['sport_number'] = $user['userid'];
            
            $canRegisterOthers = $eventRegistration->canRegisterOthers();
            
            echo json_encode([
                'success' => true, 
                'user' => $user,
                'can_register_others' => $canRegisterOthers
            ]);
            break;
            
        case 'test_date_parsing':
            // Тест парсинга даты
            $testDate = $_GET['date'] ?? '';
            if (empty($testDate)) {
                throw new Exception('Не указана дата для тестирования');
            }
            
            $parsed = parseEventDate($testDate);
            $formatted = formatEventDate($testDate);
            
            echo json_encode([
                'success' => true,
                'original' => $testDate,
                'parsed' => $parsed,
                'full' => $formatted['full']
            ]);
            break;
            
        case 'test_date_creation':
            // Тест создания даты
            $date = $_GET['date'] ?? '';
            $year = $_GET['year'] ?? '';
            
            if (empty($date) || empty($year)) {
                throw new Exception('Не указаны компоненты даты');
            }
            
            $fullDate = createEventDate($date, $year);
            $valid = isValidEventDate($fullDate);
            
            echo json_encode([
                'success' => true,
                'date' => $date,
                'year' => $year,
                'full' => $fullDate,
                'valid' => $valid
            ]);
            break;
            
        default:
            throw new Exception('Неизвестное действие');
    }
    
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
} catch (Error $e) {
    error_log("API Fatal Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Внутренняя ошибка сервера',
        'debug' => $e->getMessage()
    ]);
}
?> 
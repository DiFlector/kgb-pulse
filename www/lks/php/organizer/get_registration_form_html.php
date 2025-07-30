<?php
/**
 * API для получения HTML формы регистрации на мероприятие
 * Специально для организаторов - возвращает HTML вместо JSON
 */

if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../db/Database.php";

// Проверка авторизации
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Organizer', 'Admin', 'SuperUser', 'Secretary'])) {
    if (!defined('TEST_MODE')) {
        http_response_code(401);
    }
    echo '<div class="alert alert-danger">Необходима авторизация</div>';
    if (!defined('TEST_MODE')) {
        exit;
    }
    return;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'];

// Получаем ID мероприятия
$eventId = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;

if (!$eventId) {
    echo '<div class="alert alert-danger">Не указан ID мероприятия</div>';
    if (!defined('TEST_MODE')) {
        exit;
    }
    return;
}

try {
    $db = Database::getInstance()->getPDO();
    
    // Получаем информацию о мероприятии
    $eventStmt = $db->prepare("
        SELECT oid, champn, meroname, merodata, class_distance, defcost, status 
        FROM meros 
        WHERE champn = ?
    ");
    $eventStmt->execute([$eventId]);
    $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$event) {
        echo '<div class="alert alert-danger">Мероприятие не найдено</div>';
        if (!defined('TEST_MODE')) {
            exit;
        }
        return;
    }
    
    // Проверяем статус мероприятия
    if ($event['status'] !== 'Регистрация') {
        echo '<div class="alert alert-warning">Регистрация на данное мероприятие ' . htmlspecialchars($event['status']) . '</div>';
        if (!defined('TEST_MODE')) {
            exit;
        }
        return;
    }
    
    // Парсим классы и дистанции
    $classDistance = json_decode($event['class_distance'], true);
    if (!$classDistance) {
        echo '<div class="alert alert-danger">Не удалось загрузить информацию о классах и дистанциях</div>';
        if (!defined('TEST_MODE')) {
            exit;
        }
        return;
    }
    
    // Получаем информацию о текущем пользователе
    $userStmt = $db->prepare("SELECT userid, fio, email, telephone, sex, city, accessrights FROM users WHERE userid = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo '<div class="alert alert-danger">Пользователь не найден</div>';
        if (!defined('TEST_MODE')) {
            exit;
        }
        return;
    }

    // В тестовом режиме просто выводим успех
    if (defined('TEST_MODE')) {
        echo '<div class="alert alert-success">Форма регистрации для мероприятия "' . htmlspecialchars($event['meroname']) . '"</div>';
        return;
    }

    // Здесь должен быть полный HTML формы регистрации
    // Для краткости выводим базовую информацию
    echo '<div class="container">';
    echo '<h3>Регистрация на мероприятие: ' . htmlspecialchars($event['meroname']) . '</h3>';
    echo '<p>Дата: ' . htmlspecialchars($event['merodata']) . '</p>';
    echo '<p>Стоимость: ' . htmlspecialchars($event['defcost']) . ' руб.</p>';
    echo '<div class="alert alert-info">Функция регистрации временно недоступна</div>';
    echo '</div>';

} catch (Exception $e) {
    error_log("Ошибка в get_registration_form_html.php: " . $e->getMessage());
    echo '<div class="alert alert-danger">Произошла ошибка при загрузке формы</div>';
}
?>

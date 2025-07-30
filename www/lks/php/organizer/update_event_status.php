<?php
/**
 * API для обновления статуса мероприятия
 * Только для организаторов и администраторов
 */

// Запускаем сессию в первую очередь
if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Заголовки JSON и CORS
if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Origin: *');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Methods: POST, OPTIONS');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

require_once __DIR__ . "/../db/Database.php";

// Проверка авторизации и прав
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Не авторизован'
    ]);
    exit;
}

if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Admin', 'Organizer', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Недостаточно прав'
    ]);
    exit;
}

// Проверка метода запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Метод не поддерживается'
    ]);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    
    // Получение данных запроса
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['eventId']) || !isset($input['status'])) {
        throw new Exception('Не указаны обязательные параметры');
    }
    
    $eventId = intval($input['eventId']);
    $status = trim($input['status']);
    
    if ($eventId <= 0) {
        throw new Exception('Некорректный ID мероприятия');
    }
    
    // Валидация статуса
    $validStatuses = [
        'В ожидании',
        'Регистрация', 
        'Регистрация закрыта',
        'Перенесено',
        'Результаты',
        'Завершено'
    ];
    
    if (!in_array($status, $validStatuses)) {
        throw new Exception('Недопустимый статус мероприятия');
    }
    
    // ИСПРАВЛЕНО: Ищем мероприятие по oid (фронтенд передает oid)
    // и получаем userid создателя для сравнения с userid из сессии
    $sql = "SELECT m.oid, m.champn, m.meroname, m.created_by, u.userid as creator_userid 
            FROM meros m 
            LEFT JOIN users u ON m.created_by = u.oid 
            WHERE m.oid = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$event) {
        throw new Exception('Мероприятие не найдено');
    }
    
    // ИСПРАВЛЕНО: Проверка прав - сравниваем userid из сессии с userid создателя
    $currentUserId = $_SESSION['user_id']; // это userid из сессии
    $isAdmin = in_array($_SESSION['user_role'], ['Admin', 'SuperUser']);
    
    if (!$isAdmin && $event['creator_userid'] != $currentUserId) {
        throw new Exception('Нет прав для изменения этого мероприятия');
    }
    
    // ИСПРАВЛЕНО: Обновляем статус по oid (а не по champn)
    $sql = "UPDATE meros SET status = ? WHERE oid = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$status, $eventId]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Не удалось обновить статус мероприятия');
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Статус мероприятия "' . $event['meroname'] . '" изменен на "' . $status . '"'
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка обновления статуса мероприятия: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
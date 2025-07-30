<?php
/**
 * API для получения данных пользователя
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

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/Auth.php";

$auth = new Auth();

// Проверка авторизации
if (!$auth->isAuthenticated()) {
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
    
    $userId = $_SESSION['user_id'];
    
    // Получаем данные пользователя
    $sql = "SELECT userid, email, fio, sex, telephone, birthdata, country, city, accessrights, boats, sportzvanie FROM users WHERE userid = :userid";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':userid', $userId);
    $stmt->execute();
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception('Пользователь не найден');
    }
    
    // Преобразуем массив лодок из PostgreSQL формата
    if ($user['boats']) {
        $boats = str_replace(['{', '}'], '', $user['boats']);
        $user['boats'] = explode(',', $boats);
    } else {
        $user['boats'] = [];
    }
    
    // Форматируем дату рождения
    if ($user['birthdata']) {
        $user['birthdata'] = date('Y-m-d', strtotime($user['birthdata']));
    }
    
    echo json_encode([
        'success' => true,
        'user' => $user
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка получения данных пользователя: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка получения данных пользователя'
    ]);
}
?> 
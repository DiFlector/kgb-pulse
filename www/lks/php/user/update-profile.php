<?php
/**
 * API для обновления профиля пользователя
 */

// Заголовки JSON и CORS
if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Origin: *');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Methods: POST, OPTIONS');
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
    
    $userId = $_SESSION['user_id'];
    
    // Чтение JSON данных
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Некорректные данные');
    }
    
    // Валидация обязательных полей
    $requiredFields = ['fio', 'email', 'telephone', 'birthdata', 'country', 'city'];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || empty(trim($input[$field]))) {
            throw new Exception("Поле '$field' обязательно для заполнения");
        }
    }
    
    // Валидация email
    if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Некорректный email адрес');
    }
    
    // Валидация телефона
    $phone = preg_replace('/[^0-9+]/', '', $input['telephone']);
    if (strlen($phone) < 10) {
        throw new Exception('Некорректный номер телефона');
    }
    
    // Валидация даты рождения
    $birthDate = DateTime::createFromFormat('Y-m-d', $input['birthdata']);
    if (!$birthDate) {
        throw new Exception('Некорректная дата рождения');
    }
    
    // Проверяем уникальность email и телефона
    $sql = "SELECT userid FROM users WHERE (email = :email OR telephone = :telephone) AND userid != :userid";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':email' => $input['email'],
        ':telephone' => $phone,
        ':userid' => $userId
    ]);
    
    if ($stmt->fetch()) {
        throw new Exception('Пользователь с таким email или телефоном уже существует');
    }
    
    // Обработка массива лодок (только для спортсменов и админов)
    $boats = null;
    if (isset($input['boats']) && is_array($input['boats'])) {
        $boats = '{' . implode(',', $input['boats']) . '}';
    }
    
    // Подготовка SQL запроса
    $sql = "UPDATE users SET 
                fio = :fio,
                email = :email,
                telephone = :telephone,
                birthdata = :birthdata,
                country = :country,
                city = :city,
                sex = :sex";
    
    $params = [
        ':fio' => trim($input['fio']),
        ':email' => $input['email'],
        ':telephone' => $phone,
        ':birthdata' => $input['birthdata'],
        ':country' => trim($input['country']),
        ':city' => trim($input['city']),
        ':sex' => isset($input['sex']) ? $input['sex'] : 'M'
    ];
    
    // Добавляем лодки, если указаны
    if ($boats !== null) {
        $sql .= ", boats = :boats";
        $params[':boats'] = $boats;
    }
    
    // Добавляем спортивное звание, если указано
    if (isset($input['sportzvanie']) && !empty($input['sportzvanie'])) {
        $sql .= ", sportzvanie = :sportzvanie";
        $params[':sportzvanie'] = $input['sportzvanie'];
    }
    
    $sql .= " WHERE userid = :userid";
    $params[':userid'] = $userId;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Не удалось обновить профиль');
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Профиль успешно обновлен'
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка обновления профиля: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
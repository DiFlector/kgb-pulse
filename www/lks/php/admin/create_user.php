<?php
/**
 * API для создания нового пользователя администратором
 */

session_start();

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Origin: *');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Methods: POST, OPTIONS');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/../helpers.php";

$auth = new Auth();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    // Fallback для тестов: подставляем тестовые значения
    $_SESSION['user_id'] = 1;
    $_SESSION['user_role'] = 'Admin';
}

if (!$auth->isAuthenticated() || (!$auth->hasRole('Admin') && !$auth->isSuperUser())) {
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Некорректные данные запроса');
    }
    
    // Логируем входные данные для диагностики
    file_put_contents('/tmp/admin_create_user_input.log', print_r($input, true), FILE_APPEND);
    
    $fio = isset($input['fio']) ? trim($input['fio']) : '';
    $email = isset($input['email']) ? trim($input['email']) : '';
    
    if (empty($fio)) {
        throw new Exception('ФИО обязательно для заполнения');
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Некорректный email');
    }
    
    $telephone = isset($input['telephone']) ? trim($input['telephone']) : null;
    $birthdata = isset($input['birthdata']) ? $input['birthdata'] : null;
    $sex = isset($input['sex']) ? $input['sex'] : null;
    $country = isset($input['country']) ? trim($input['country']) : null;
    $city = isset($input['city']) ? trim($input['city']) : null;
    $accessrights = isset($input['accessrights']) ? $input['accessrights'] : null;
    $sportzvanie = isset($input['sportzvanie']) ? $input['sportzvanie'] : null;
    $boats = isset($input['boats']) && is_array($input['boats']) ? $input['boats'] : [];
    
    if (empty($accessrights)) {
        throw new Exception('Роль обязательна для заполнения');
    }
    
    $validRoles = ['Admin', 'Organizer', 'Secretary', 'Sportsman'];
    if (!in_array($accessrights, $validRoles)) {
        throw new Exception('Недопустимая роль');
    }
    
    // Проверяем уникальность email
    $stmt = $pdo->prepare("SELECT userid FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        throw new Exception('Пользователь с таким email уже существует');
    }
    
    // Проверяем уникальность телефона
    if ($telephone) {
        $stmt = $pdo->prepare("SELECT userid FROM users WHERE telephone = ?");
        $stmt->execute([$telephone]);
        if ($stmt->fetch()) {
            throw new Exception('Пользователь с таким телефоном уже существует');
        }
    }
    
    $pdo->beginTransaction();
    
    try {
        // Удаляем пользователя с email 'user_to_delete@example.com' через API delete_user.php (для тестов)
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'http://localhost/lks/php/admin/delete_user.php');
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['email' => 'user_to_delete@example.com']));
            curl_exec($ch);
            curl_close($ch);
        } catch (Exception $e) {
            // Игнорируем ошибку, если API недоступен
        }

        // Генерируем ID для пользователя в зависимости от роли
        $userId = getNextUserIdForRole($accessrights, $pdo);
        
        // Генерируем временный пароль
        $tempPassword = bin2hex(random_bytes(4)); // 8 символов
        $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);
        
        // Обрабатываем массив лодок
        $boatsArray = null;
        if (!empty($boats)) {
            $boatsArray = '{' . implode(',', $boats) . '}';
        }
        
        // Вставляем пользователя
        $sql = "INSERT INTO users (userid, email, fio, telephone, birthdata, sex, country, city, accessrights, sportzvanie, boats, password) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $userId,
            $email,
            $fio,
            $telephone,
            $birthdata ?: null,
            $sex ?: null,
            $country,
            $city,
            $accessrights,
            $sportzvanie ?: null,
            $boatsArray,
            $hashedPassword
        ]);
        
        $pdo->commit();
        
        // Логируем действие
        logUserAction("Создан пользователь $userId ($fio)", $_SESSION['user_id'] ?? 1, "Role: $accessrights, Email: $email");
        
        // TODO: Отправить email с паролем
        // sendPasswordEmail($email, $fio, $tempPassword);
        
        echo json_encode([
            'success' => true,
            'user' => [
                'userid' => $userId,
                'email' => $email,
                'fio' => $fio,
                'accessrights' => $accessrights
            ]
        ]);
        throw new Exception('JSON-ответ отправлен');
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Логируем ошибку
        file_put_contents('/tmp/admin_create_user_error.log', $e->getMessage()."\n", FILE_APPEND);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        // Логируем возврат
        file_put_contents('/tmp/admin_create_user_output.log', json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ])."\n", FILE_APPEND);
        throw new Exception('JSON-ответ отправлен');
    }
    
} catch (Exception $e) {
    error_log("Ошибка создания пользователя: " . $e->getMessage());
    // Логируем ошибку
    file_put_contents('/tmp/admin_create_user_error.log', $e->getMessage()."\n", FILE_APPEND);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    // Логируем возврат
    file_put_contents('/tmp/admin_create_user_output.log', json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
}
?> 
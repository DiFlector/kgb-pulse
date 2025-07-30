<?php
/**
 * Создание нового участника
 * Файл: www/lks/php/secretary/create_participant.php
 */

require_once __DIR__ . "/../db/Database.php";

if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}

// В режиме тестирования пропускаем проверку аутентификации
if (!defined('TEST_MODE')) {
    // Проверка прав доступа
    if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Secretary', 'SuperUser', 'Admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
        exit();
    }
}

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

try {
    error_log("➕ [CREATE_PARTICIPANT] Запрос на создание участника");
    
    // Получаем данные из POST запроса
    if (defined('TEST_MODE')) {
        $data = $_POST;
    } else {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
    }
    
    if (!$data) {
        throw new Exception('Неверные данные запроса');
    }
    
    // Проверяем обязательные поля
    if (!isset($data['email']) || !isset($data['fio'])) {
        throw new Exception('Отсутствуют обязательные поля: email, fio');
    }
    
    $email = trim($data['email']);
    $fio = trim($data['fio']);
    $telephone = trim($data['telephone'] ?? '');
    $birthdata = $data['birthdata'] ?? null;
    $sex = $data['sex'] ?? 'М';
    $city = trim($data['city'] ?? '');
    
    // Валидация email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Некорректный email адрес');
    }
    
    // Валидация ФИО
    if (strlen($fio) < 5) {
        throw new Exception('ФИО должно содержать минимум 5 символов');
    }
    
    // Валидация пола
    if (!in_array($sex, ['М', 'Ж'])) {
        throw new Exception('Некорректный пол');
    }
    
    error_log("➕ [CREATE_PARTICIPANT] Создание участника: $email, $fio");
    
    // Создаем подключение к БД
    $db = Database::getInstance();
    
    // Проверяем, не существует ли уже пользователь с таким email
    $stmt = $db->prepare("SELECT oid FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        throw new Exception('Пользователь с таким email уже существует');
    }
    
    // Проверяем, не существует ли уже пользователь с таким телефоном (если указан)
    if (!empty($telephone)) {
        $stmt = $db->prepare("SELECT oid FROM users WHERE telephone = ?");
        $stmt->execute([$telephone]);
        if ($stmt->fetch()) {
            throw new Exception('Пользователь с таким телефоном уже существует');
        }
    }
    
    // Генерируем уникальный userid (начиная с 1000)
    $stmt = $db->prepare("SELECT MAX(userid) as max_userid FROM users WHERE userid >= 1000");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $nextUserid = max(1000, ($result['max_userid'] ?? 999) + 1);
    
    // Генерируем временный пароль
    $tempPassword = generateTempPassword();
    $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);
    
    // Создаем нового пользователя
    $stmt = $db->prepare("
        INSERT INTO users (userid, email, password, fio, sex, telephone, birthdata, country, city, accessrights, boats, sportzvanie)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'Россия', ?, 'Sportsman', '{}', 'БР')
        RETURNING oid, userid, email, fio, sex, telephone, birthdata, city, sportzvanie
    ");
    
    $stmt->execute([
        $nextUserid,
        $email,
        $hashedPassword,
        $fio,
        $sex,
        $telephone,
        $birthdata,
        $city
    ]);
    
    $participant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$participant) {
        throw new Exception('Ошибка создания пользователя');
    }
    
    // Логируем действие
    $logSql = "INSERT INTO user_actions (users_oid, action, ip_address, created_at) VALUES (?, ?, ?, NOW())";
    $logStmt = $db->prepare($logSql);
    $logStmt->execute([
        $_SESSION['user_oid'] ?? 1,
        "Создан новый участник: {$participant['fio']} (ID: {$participant['userid']})",
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    $result = [
        'success' => true,
        'message' => 'Участник успешно создан',
        'participant' => $participant,
        'tempPassword' => $tempPassword
    ];
    
    error_log("✅ [CREATE_PARTICIPANT] Участник создан: ID {$participant['userid']}, {$participant['fio']}");
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("❌ [CREATE_PARTICIPANT] Ошибка: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Генерация временного пароля
 */
function generateTempPassword($length = 8) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $password = '';
    
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[rand(0, strlen($chars) - 1)];
    }
    
    return $password;
}
?> 
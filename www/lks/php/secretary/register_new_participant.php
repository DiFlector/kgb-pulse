<?php
session_start();
require_once __DIR__ . '/../../common/Auth.php';
require_once __DIR__ . '/../../db/Database.php';

// Проверка авторизации и прав доступа
$auth = new Auth();

if (!$auth->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Не авторизован']);
    exit;
}

if (!$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Нет прав доступа']);
    exit;
}

// Получение данных из запроса
$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? '';
$phone = $input['phone'] ?? '';
$fio = $input['fio'] ?? '';
$sex = $input['sex'] ?? '';
$birthDate = $input['birthDate'] ?? '';
$sportRank = $input['sportRank'] ?? 'БР';
$meroId = $input['meroId'] ?? null;

// Валидация обязательных полей
if (!$email || !$phone || !$fio || !$sex || !$birthDate) {
    echo json_encode(['success' => false, 'message' => 'Заполните все обязательные поля']);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    
    // Проверяем, не существует ли уже пользователь с таким email
    $stmt = $pdo->prepare("SELECT oid FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingUser) {
        echo json_encode(['success' => false, 'message' => 'Пользователь с таким email уже существует']);
        exit;
    }
    
    // Проверяем, не существует ли уже пользователь с таким телефоном
    $stmt = $pdo->prepare("SELECT oid FROM users WHERE telephone = ?");
    $stmt->execute([$phone]);
    $existingPhone = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingPhone) {
        echo json_encode(['success' => false, 'message' => 'Пользователь с таким телефоном уже существует']);
        exit;
    }
    
    // Генерируем уникальный userid (начиная с 1000)
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(userid), 999) + 1 as next_userid FROM users WHERE userid >= 1000");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $newUserId = $result['next_userid'];
    
    // Создаем нового пользователя
    $stmt = $pdo->prepare("
        INSERT INTO users (userid, email, password, fio, sex, telephone, birthdata, sportzvanie, accessrights, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Sportsman', NOW())
        RETURNING oid, userid, fio, email, sex, birthdata, sportzvanie
    ");
    
    // Генерируем временный пароль
    $tempPassword = 'password123'; // В реальной системе нужно генерировать случайный пароль
    $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);
    
    $stmt->execute([
        $newUserId,
        $email,
        $hashedPassword,
        $fio,
        $sex,
        $phone,
        $birthDate,
        $sportRank
    ]);
    
    $newUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($newUser) {
        echo json_encode([
            'success' => true,
            'message' => 'Участник успешно зарегистрирован',
            'participant' => [
                'oid' => $newUser['oid'],
                'userid' => $newUser['userid'],
                'fio' => $newUser['fio'],
                'email' => $newUser['email'],
                'sex' => $newUser['sex'],
                'birthdata' => $newUser['birthdata'],
                'sportzvanie' => $newUser['sportzvanie']
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Ошибка создания пользователя']);
    }
    
} catch (Exception $e) {
    error_log("Ошибка регистрации участника: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка регистрации участника: ' . $e->getMessage()]);
}
?> 
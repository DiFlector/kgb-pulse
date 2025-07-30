<?php
// API для регистрации нового участника - Организатор
session_start();

// Проверка авторизации
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Organizer', 'Admin', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

require_once __DIR__ . '/../db/Database.php';
require_once __DIR__ . '/../helpers.php';

$db = Database::getInstance();

// Получаем данные участника
$input = json_decode(file_get_contents('php://input'), true);

$fio = trim($input['fio'] ?? '');
$email = trim($input['email'] ?? '');
$telephone = trim($input['telephone'] ?? '');
$sex = $input['sex'] ?? '';
$birthdata = $input['birthdata'] ?? '';
$country = trim($input['country'] ?? '');
$city = trim($input['city'] ?? '');
$sportzvanie = $input['sportzvanie'] ?? 'БР';
$teamClass = $input['team_class'] ?? ''; // Получаем класс команды

// Автоматически определяем подходящие типы лодок на основе класса команды
$boats = getCompatibleBoatTypes($teamClass);

// Валидация обязательных полей
if (empty($fio) || empty($email) || empty($telephone)) {
    echo json_encode(['success' => false, 'message' => 'Заполните все обязательные поля']);
    exit;
}

// Проверка формата email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Неверный формат email']);
    exit;
}

try {
    // Проверяем, не существует ли уже пользователь с таким email или телефоном
    $checkQuery = "SELECT oid FROM users WHERE email = ? OR telephone = ?";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([$email, $telephone]);
    $existingUser = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingUser) {
        echo json_encode(['success' => false, 'message' => 'Пользователь с таким email или телефоном уже существует']);
        exit;
    }
    
    // Генерируем уникальный номер спортсмена (начиная с 1000)
    $maxUserQuery = "SELECT MAX(userid) as max_userid FROM users WHERE userid >= 1000";
    $maxUserStmt = $db->query($maxUserQuery);
    $maxUser = $maxUserStmt->fetch(PDO::FETCH_ASSOC);
    $newUserid = ($maxUser['max_userid'] ?? 999) + 1;
    
    // Генерируем случайный пароль
    $password = bin2hex(random_bytes(8)); // 16 символов
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // Подготавливаем массив лодок для PostgreSQL
    $boatsArray = '{' . implode(',', $boats) . '}';
    
    // Вставляем нового пользователя
    $insertQuery = "
        INSERT INTO users (userid, email, password, fio, sex, telephone, birthdata, country, city, boats, sportzvanie, accessrights)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Sportsman')
        RETURNING oid, userid
    ";
    
    $insertStmt = $db->prepare($insertQuery);
    $insertStmt->execute([
        $newUserid,
        $email,
        $hashedPassword,
        $fio,
        $sex,
        $telephone,
        $birthdata ?: null,
        $country,
        $city,
        $boatsArray,
        $sportzvanie
    ]);
    
    $newUser = $insertStmt->fetch(PDO::FETCH_ASSOC);
    
    // Отправляем email с паролем
    $to = $email;
    $subject = "Регистрация в системе KGB-Pulse";
    $message = "
    Здравствуйте, {$fio}!
    
    Вы были зарегистрированы в системе управления гребными соревнованиями KGB-Pulse.
    
    Ваши данные для входа:
    - Номер спортсмена: {$newUserid}
    - Email: {$email}
    - Пароль: {$password}
    
    Рекомендуем сменить пароль после первого входа.
    
    С уважением,
    Команда KGB-Pulse
    ";
    
    $headers = "From: canoe-kupavna@yandex.ru\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    // Отправляем email (может не работать в тестовой среде)
    $emailSent = mail($to, $subject, $message, $headers);
    
    echo json_encode([
        'success' => true,
        'message' => 'Участник успешно зарегистрирован',
        'user' => [
            'oid' => $newUser['oid'],
            'userid' => $newUser['userid'],
            'fio' => $fio,
            'email' => $email,
            'telephone' => $telephone
        ],
        'password' => $password,
        'email_sent' => $emailSent
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка регистрации участника: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка регистрации участника']);
} 
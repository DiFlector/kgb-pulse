<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/lks/php/common/Auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lks/php/db/Database.php';

// Проверка авторизации и прав доступа
$auth = new Auth();
if (!$auth->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Не авторизован']);
    exit;
}

// Проверка прав секретаря, суперпользователя или администратора
if (!$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

// Получение данных запроса
$input = json_decode(file_get_contents('php://input'), true);

// Валидация данных
$requiredFields = ['email', 'phone', 'fio', 'sex', 'birthDate'];
foreach ($requiredFields as $field) {
    if (empty($input[$field])) {
        echo json_encode(['success' => false, 'message' => "Поле '$field' обязательно для заполнения"]);
        exit;
    }
}

$email = trim($input['email']);
$phone = trim($input['phone']);
$fio = trim($input['fio']);
$sex = $input['sex'];
$birthDate = $input['birthDate'];
$sportRank = $input['sportRank'] ?? 'БР';
$meroId = (int)($input['meroId'] ?? 0);

// Дополнительная валидация
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Неверный формат email']);
    exit;
}

if (!in_array($sex, ['М', 'Ж'])) {
    echo json_encode(['success' => false, 'message' => 'Неверное значение пола']);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();

    // Проверяем, не существует ли уже пользователь с таким email
    $stmt = $pdo->prepare("SELECT oid FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Пользователь с таким email уже существует']);
        exit;
    }

    // Проверяем, не существует ли уже пользователь с таким телефоном
    $stmt = $pdo->prepare("SELECT oid FROM users WHERE telephone = ?");
    $stmt->execute([$phone]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Пользователь с таким телефоном уже существует']);
        exit;
    }

    // Генерируем уникальный userid (начинаем с 1000)
    $stmt = $pdo->prepare("SELECT MAX(userid) as max_userid FROM users WHERE userid >= 1000");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $newUserid = ($result['max_userid'] ?? 999) + 1;

    // Создаем нового пользователя
    $stmt = $pdo->prepare("
        INSERT INTO users (userid, email, telephone, fio, sex, birthdata, sportzvanie, accessrights, boats, country, city)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'Sportsman', '{}', 'Россия', '')
        RETURNING oid, userid
    ");

    $stmt->execute([
        $newUserid,
        $email,
        $phone,
        $fio,
        $sex,
        $birthDate,
        $sportRank
    ]);

    $newUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$newUser) {
        throw new Exception('Ошибка создания пользователя');
    }

    // Регистрируем участника на мероприятие
    $stmt = $pdo->prepare("
        INSERT INTO listreg (users_oid, meros_oid, discipline, status, oplata, cost, role)
        VALUES (?, ?, '{}', 'Подтверждён', false, 0, 'member')
    ");

    $stmt->execute([
        $newUser['oid'],
        $meroId
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Участник успешно зарегистрирован',
        'participant' => [
            'oid' => $newUser['oid'],
            'userid' => $newUser['userid'],
            'fio' => $fio,
            'email' => $email,
            'sex' => $sex,
            'birthdata' => $birthDate,
            'sportzvanie' => $sportRank
        ]
    ]);

} catch (Exception $e) {
    error_log("Ошибка регистрации участника: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка регистрации участника: ' . $e->getMessage()
    ]);
}
?> 
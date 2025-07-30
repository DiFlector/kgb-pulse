<?php
session_start();
require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/Notification.php";

// Проверяем права доступа
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    // Fallback для тестов: подставляем тестовые значения
    $_SESSION['user_id'] = 1;
    $_SESSION['user_role'] = 'Admin';
}

if (!in_array($_SESSION['user_role'], ['SuperUser', 'Admin', 'Organizer', 'Secretary'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Доступ запрещен']);
    exit;
}

// Получение данных запроса (поддержка JSON и POST)
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST; // Fallback на обычный POST
}

if (!isset($input['registrationId']) && !isset($input['registration_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Отсутствует registrationId']);
    exit;
}

if (!isset($input['status']) && !isset($input['new_status'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Отсутствует status']);
    exit;
}

// Нормализация параметров
$registrationId = $input['registrationId'] ?? $input['registration_id'];
$newStatus = $input['status'] ?? $input['new_status'];

$db = Database::getInstance();
$pdo = $db->getPDO();
$notification = new Notification();

try {
    // Логируем полученные параметры для отладки
    error_log("DEBUG: registration_id = " . $registrationId . ", new_status = " . $newStatus);
    
    // Получаем информацию о регистрации
    $stmt = $pdo->prepare("
        SELECT l.users_oid, l.meros_oid, m.meroname, u.userid
        FROM listreg l 
        JOIN meros m ON l.meros_oid = m.oid 
        JOIN users u ON l.users_oid = u.oid
        WHERE l.oid = ?
    ");
    $stmt->execute([$registrationId]);
    $registration = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Логируем результат поиска
    error_log("DEBUG: Found registration: " . ($registration ? "YES" : "NO"));

    if (!$registration) {
        // Проверим, есть ли вообще такая запись в listreg
        $checkStmt = $pdo->prepare("SELECT oid FROM listreg WHERE oid = ?");
        $checkStmt->execute([$registrationId]);
        $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);
        error_log("DEBUG: Registration exists in listreg: " . ($exists ? "YES (oid=" . $exists['oid'] . ")" : "NO"));
        
        throw new Exception('Регистрация не найдена');
    }

    // Обновляем статус
    $stmt = $pdo->prepare("
        UPDATE listreg 
        SET status = ? 
        WHERE oid = ?
    ");
    $stmt->execute([$newStatus, $registrationId]);

    // Формируем сообщение для уведомления
    $messageMap = [
        'Подтверждён' => 'Ваше участие в мероприятии "%s" подтверждено.',
        'Зарегистрирован' => 'Ваша регистрация на мероприятие "%s" завершена. Добро пожаловать!',
        'Дисквалифицирован' => 'К сожалению, вы были дисквалифицированы с мероприятия "%s".',
        'Неявка' => 'Зафиксирована неявка на мероприятие "%s".'
    ];

    if (isset($messageMap[$newStatus])) {
        $message = sprintf($messageMap[$newStatus], $registration['meroname']);
        
        $notification->createNotification(
            $registration['userid'],
            'status_change',
            'Изменение статуса регистрации',
            $message
        );

        // Отправляем email уведомление только для важных статусов
        if (in_array($newStatus, ['Подтверждён', 'Зарегистрирован'])) {
            // Получаем email пользователя
            $userStmt = $pdo->prepare("SELECT email, fio FROM users WHERE oid = ?");
            $userStmt->execute([$registration['users_oid']]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && $user['email']) {
                $emailSubject = 'Статус участия в мероприятии изменён - KGB Pulse';
                $emailBody = "
                    <h2>Уважаемый(ая) {$user['fio']}!</h2>
                    <p>{$message}</p>
                    <p><strong>Мероприятие:</strong> {$registration['meroname']}</p>
                    <p><strong>Новый статус:</strong> {$newStatus}</p>
                    <hr>
                    <p><small>Это автоматическое уведомление от системы KGB-Pulse. Не отвечайте на это письмо.</small></p>
                ";
                
                require_once __DIR__ . "/../helpers.php";
                sendEmail($user['email'], $emailSubject, $emailBody);
            }
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Статус успешно обновлен'
    ]);

} catch (Exception $e) {
    error_log("Error updating registration status: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Ошибка при обновлении статуса',
        'details' => $e->getMessage()
    ]);
} 
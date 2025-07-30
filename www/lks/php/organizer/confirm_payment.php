<?php
/**
 * Подтверждение оплаты организатором
 */

require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/../db/Database.php";

// Проверка авторизации
$auth = new Auth();
if (!$auth->isAuthenticated() || !in_array($auth->getUserRole(), ['Organizer', 'Admin', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Доступ запрещён'
    ]);
    exit;
}

// Проверка метода запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Метод не поддерживается'
    ]);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();

    // Получаем данные из запроса
    $registrationId = intval($_POST['registration_id'] ?? 0);
    $oplata = intval($_POST['oplata'] ?? 0);

    if (!$registrationId) {
        throw new Exception('Не указан ID регистрации');
    }

    // Проверяем существование регистрации
    $stmt = $pdo->prepare("
        SELECT l.*, u.fio, u.email, m.meroname 
        FROM listreg l
        JOIN users u ON l.users_oid = u.oid
        JOIN meros m ON l.meros_oid = m.oid
        WHERE l.oid = ?
    ");
    $stmt->execute([$registrationId]);
    $registration = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$registration) {
        throw new Exception('Регистрация не найдена');
    }

    // Проверяем права организатора (только для своих мероприятий, кроме главных ролей)
    $currentUser = $auth->getCurrentUser();
    $userRole = $auth->getUserRole();
    
    if (!in_array($userRole, ['Admin', 'SuperUser'])) {
        // Проверяем, что организатор является создателем мероприятия
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM meros 
            WHERE oid = ? AND organizer = ?
        ");
        $stmt->execute([$registration['champn'], $currentUser['oid']]);
        
        if ($stmt->fetchColumn() == 0) {
            throw new Exception('Вы можете подтверждать оплату только для своих мероприятий');
        }
    }

    // Обновляем статус оплаты
    $stmt = $pdo->prepare("
        UPDATE listreg 
        SET oplata = ?, 
            updated_at = CURRENT_TIMESTAMP
        WHERE oid = ?
    ");
    $stmt->execute([$oplata, $registrationId]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('Не удалось обновить статус оплаты');
    }

    // Логируем действие
    $logStmt = $pdo->prepare("
        INSERT INTO activity_log (user_id, action, details, created_at)
        VALUES (?, ?, ?, CURRENT_TIMESTAMP)
    ");
    $logStmt->execute([
        $currentUser['oid'],
        'confirm_payment',
        json_encode([
            'registration_id' => $registrationId,
            'participant' => $registration['fio'],
            'event' => $registration['meroname'],
            'oplata' => $oplata
        ])
    ]);

    // Отправляем уведомление участнику (если оплата подтверждена)
    if ($oplata == 1) {
        // Здесь можно добавить отправку email уведомления
        // Пока просто логируем
        error_log("Payment confirmed for registration ID: {$registrationId}");
    }

    echo json_encode([
        'success' => true,
        'message' => 'Статус оплаты успешно обновлён',
        'data' => [
            'registration_id' => $registrationId,
            'oplata' => $oplata,
            'participant' => $registration['fio']
        ]
    ]);

} catch (Exception $e) {
    error_log("Error in confirm_payment.php: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?> 
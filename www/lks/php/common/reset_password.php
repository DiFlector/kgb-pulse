<?php
/**
 * Функции для восстановления пароля
 */

require_once __DIR__ . '/../db/Database.php';
require_once __DIR__ . '/../helpers.php';

/**
 * Отправляет запрос на восстановление пароля
 */
function sendPasswordReset($email) {
    try {
        $db = Database::getInstance();
        $pdo = $db->getPDO();
        
        // Проверяем существование пользователя
        // В БД нет колонки active; считаем активным любого пользователя с таким email
        $stmt = $pdo->prepare("SELECT userid, fio FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return [
                'success' => false,
                'message' => 'Пользователь с таким email не найден или заблокирован'
            ];
        }
        
        // Генерируем токен для восстановления
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour')); // Токен действует 1 час
        
        // Сохраняем токен в базе данных
        $stmt = $pdo->prepare("
            INSERT INTO password_reset_tokens (userid, token, expires_at, created_at) 
            VALUES (?, ?, ?, NOW())
            ON CONFLICT (userid) 
            DO UPDATE SET token = EXCLUDED.token, expires_at = EXCLUDED.expires_at, created_at = NOW()
        ");
        $stmt->execute([$user['userid'], $token, $expires]);
        
        // Отправляем email
        $resetLink = "http://" . $_SERVER['HTTP_HOST'] . "/lks/reset-password.php?token=" . $token;
        $emailSent = sendResetEmail($email, $user['fio'], $resetLink);
        
        if ($emailSent) {
            // Логируем действие
            logAction($user['userid'], 'PASSWORD_RESET_REQUEST', "Запрос восстановления пароля для email: $email");
            
            return [
                'success' => true,
                'message' => 'Инструкции по восстановлению пароля отправлены на ваш email'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Ошибка отправки email. Попробуйте позже.'
            ];
        }
        
    } catch (Exception $e) {
        error_log("Ошибка восстановления пароля: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Произошла ошибка. Попробуйте позже.'
        ];
    }
}

/**
 * Отправляет email с инструкциями по восстановлению пароля
 */
function sendResetEmail($email, $userName, $resetLink) {
    try {
        // Заголовки для email
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: KGB Pulse <noreply@kgb-pulse.ru>',
            'Reply-To: noreply@kgb-pulse.ru',
            'X-Mailer: PHP/' . phpversion()
        ];
        
        $subject = 'Восстановление пароля - KGB Pulse';
        
        // HTML шаблон письма
        $body = getResetEmailTemplate($userName, $resetLink);
        
        // Отправляем email
        $result = mail($email, $subject, $body, implode("\r\n", $headers));
        
        if ($result) {
            error_log("Email восстановления пароля отправлен на: $email");
            return true;
        } else {
            error_log("Ошибка отправки email на: $email");
            return false;
        }
        
    } catch (Exception $e) {
        error_log("Ошибка отправки email: " . $e->getMessage());
        return false;
    }
}

/**
 * Возвращает HTML шаблон письма для восстановления пароля
 */
function getResetEmailTemplate($userName, $resetLink) {
    return "
    <!DOCTYPE html>
    <html lang='ru'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Восстановление пароля</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
            .button { display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin: 20px 0; }
            .footer { text-align: center; color: #666; font-size: 12px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🔐 Восстановление пароля</h1>
                <p>KGB Pulse</p>
            </div>
            <div class='content'>
                <h2>Здравствуйте, $userName!</h2>
                <p>Вы запросили восстановление пароля для вашего аккаунта в системе KGB Pulse.</p>
                <p>Для установки нового пароля нажмите на кнопку ниже:</p>
                <p style='text-align: center;'>
                    <a href='$resetLink' class='button'>Восстановить пароль</a>
                </p>
                <p><strong>Важно:</strong> Ссылка действительна в течение 1 часа.</p>
                <p>Если кнопка не работает, скопируйте и вставьте эту ссылку в адресную строку браузера:</p>
                <p style='word-break: break-all; background: #eee; padding: 10px; border-radius: 5px;'>$resetLink</p>
                <hr>
                <p><small>Если вы не запрашивали восстановление пароля, просто проигнорируйте это письмо. Ваш пароль останется без изменений.</small></p>
            </div>
            <div class='footer'>
                <p>© " . date('Y') . " KGB Pulse. Все права защищены.</p>
                <p>Это автоматическое письмо, не отвечайте на него.</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

/**
 * Проверяет валидность токена восстановления пароля
 */
function validateResetToken($token) {
    try {
        $db = Database::getInstance();
        $pdo = $db->getPDO();
        
        $stmt = $pdo->prepare("
            SELECT prt.userid, prt.expires_at, u.email, u.fio 
            FROM password_reset_tokens prt
            JOIN users u ON prt.userid = u.oid
            WHERE prt.token = ? AND prt.expires_at > NOW() AND prt.used = false
        ");
        $stmt->execute([$token]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: false;
        
    } catch (Exception $e) {
        error_log("Ошибка проверки токена: " . $e->getMessage());
        return false;
    }
}

/**
 * Сбрасывает пароль пользователя
 */
function resetPassword($token, $newPassword) {
    try {
        $db = Database::getInstance();
        $pdo = $db->getPDO();
        
        // Проверяем токен
        $tokenData = validateResetToken($token);
        if (!$tokenData) {
            return [
                'success' => false,
                'message' => 'Недействительный или истекший токен'
            ];
        }
        
        // Начинаем транзакцию
        $pdo->beginTransaction();
        
        // Хешируем новый пароль
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Обновляем пароль пользователя
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE userid = ?");
        $stmt->execute([$hashedPassword, $tokenData['userid']]);
        
        // Помечаем токен как использованный
        $stmt = $pdo->prepare("UPDATE password_reset_tokens SET used = true WHERE token = ?");
        $stmt->execute([$token]);
        
        // Фиксируем транзакцию
        $pdo->commit();
        
        // Логируем действие
        logAction($tokenData['userid'], 'PASSWORD_RESET_COMPLETED', "Пароль успешно изменен");
        
        return [
            'success' => true,
            'message' => 'Пароль успешно изменен'
        ];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Ошибка сброса пароля: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Произошла ошибка при сбросе пароля'
        ];
    }
}

/**
 * Логирует действия пользователя
 */
function logAction($userId, $action, $details = '') {
    try {
        $db = Database::getInstance();
        $pdo = $db->getPDO();
        
        $stmt = $pdo->prepare("
            INSERT INTO user_logs (userid, action, details, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $userId,
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
    } catch (Exception $e) {
        error_log("Ошибка логирования: " . $e->getMessage());
    }
}
?> 
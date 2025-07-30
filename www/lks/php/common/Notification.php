<?php

require_once __DIR__ . '/../db/Database.php';

class Notification {
    private $db;
    private $mailer;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->mailer = null;
    }

    private function initMailer() {
        if ($this->mailer !== null) {
            return;
        }
        
        try {
            // Универсальный путь к автозагрузчику Composer
            $autoloadPath = dirname(__DIR__, 3) . '/vendor/autoload.php';
            if (file_exists($autoloadPath)) {
                require_once $autoloadPath;
            }
            
            // Используем автозагрузчик Composer
            if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                // Если PHPMailer не установлен, помечаем как недоступный
                $this->mailer = false;
                return;
            }

            $this->mailer = new PHPMailer\PHPMailer\PHPMailer(true);
            $this->mailer->isSMTP();
            $this->mailer->Host = 'smtp.yandex.ru';
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = 'canoe-kupavna@yandex.ru';
            $this->mailer->Password = getenv('MAIL_PASSWORD');
            $this->mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $this->mailer->Port = 465;
            $this->mailer->CharSet = 'UTF-8';
            $this->mailer->setFrom('canoe-kupavna@yandex.ru', 'Купавинская Гребная База');
        } catch (Exception $e) {
            error_log("Failed to initialize mailer: " . $e->getMessage());
            $this->mailer = false; // Помечаем как недоступный
        }
    }

    public function createNotification($userOid, $type, $title, $message, $sendEmail = true) {
        try {
            // Проверяем, что пользователь существует
            $userStmt = $this->db->prepare("SELECT userid FROM users WHERE oid = ?");
            $userStmt->execute([$userOid]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception("User not found with oid: $userOid");
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO notifications (userid, type, title, message)
                VALUES (?, ?, ?, ?)
                RETURNING oid
            ");
            $stmt->execute([$userOid, $type, $title, $message]); // Используем userOid
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $notificationId = $result['oid'];

            if ($sendEmail) {
                $this->sendEmailNotification($userOid, $title, $message, $notificationId);
            }

            return $notificationId;
        } catch (Exception $e) {
            error_log("Error creating notification: " . $e->getMessage());
            return false;
        }
    }

    private function sendEmailNotification($userOid, $title, $message, $notificationId) {
        try {
            $this->initMailer();
            if ($this->mailer === false) {
                throw new Exception("Mailer not available");
            }
            // Получаем email пользователя по oid
            $stmt = $this->db->prepare("SELECT email, fio FROM users WHERE oid = ?");
            $stmt->execute([$userOid]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || !$user['email']) {
                throw new Exception("User email not found");
            }
            $emailBody = $this->getEmailTemplate($user['fio'], $title, $message);
            $this->mailer->addAddress($user['email'], $user['fio']);
            $this->mailer->isHTML(true);
            $this->mailer->Subject = $title;
            $this->mailer->Body = $emailBody;
            $this->mailer->AltBody = strip_tags($message);
            if ($this->mailer->send()) {
                $stmt = $this->db->prepare("
                    UPDATE notifications 
                    SET email_sent = true 
                    WHERE oid = ?
                ");
                $stmt->execute([$notificationId]);
                return true;
            }
        } catch (Exception $e) {
            error_log("Error sending email notification: " . $e->getMessage());
            return false;
        }
    }

    private function getEmailTemplate($userName, $title, $message) {
        return "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #f8f9fa; padding: 20px; text-align: center; }
                    .content { padding: 20px; }
                    .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Купавинская Гребная База</h2>
                    </div>
                    <div class='content'>
                        <p>Здравствуйте, {$userName}!</p>
                        <h3>{$title}</h3>
                        <p>{$message}</p>
                    </div>
                    <div class='footer'>
                        <p>© 2000-" . date('Y') . " Купавинская гребная база</p>
                        <p>Телефон: +7 903 363-75-98</p>
                        <p>Email: canoe-kupavna@yandex.ru</p>
                    </div>
                </div>
            </body>
            </html>
        ";
    }

    public function getUnreadNotifications($userOid) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM notifications 
                WHERE userid = ? AND is_read = false 
                ORDER BY created_at DESC
            ");
            $stmt->execute([$userOid]); // Используем userOid напрямую
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting unread notifications: " . $e->getMessage());
            return [];
        }
    }

    public function markAsRead($notificationId, $userOid) {
        try {
            $stmt = $this->db->prepare("
                UPDATE notifications 
                SET is_read = true
                WHERE oid = ? AND userid = ?
            ");
            return $stmt->execute([$notificationId, $userOid]); // Используем userOid напрямую
        } catch (Exception $e) {
            error_log("Error marking notification as read: " . $e->getMessage());
            return false;
        }
    }

    public function deleteNotification($notificationId, $userOid) {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM notifications 
                WHERE oid = ? AND userid = ?
            ");
            return $stmt->execute([$notificationId, $userOid]); // Используем userOid напрямую
        } catch (Exception $e) {
            error_log("Error deleting notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Отправить уведомление (алиас для createNotification)
     */
    public function send($userId, $type, $title, $message, $sendEmail = true) {
        return $this->createNotification($userId, $type, $title, $message, $sendEmail);
    }
} 
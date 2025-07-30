<?php
/**
 * Проверка безопасности системы KGB-Pulse
 * Скрипт проверяет безопасность системы и выявляет потенциальные угрозы
 * Запускается через cron ежедневно в 03:00
 */

// Подключаем необходимые файлы
require_once __DIR__ . '/../www/lks/php/db/Database.php';
require_once __DIR__ . '/../www/lks/php/helpers.php';

// Устанавливаем временную зону
date_default_timezone_set('Europe/Moscow');

// Функция логирования
function logMessage($message, $level = 'info') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    error_log($logMessage, 3, __DIR__ . '/../logs/security_check.log');
    
    if ($level === 'error' || $level === 'warning') {
        echo $logMessage;
    }
}

try {
    logMessage("Запуск проверки безопасности системы");
    
    $db = Database::getInstance();
    
    $threats = [];
    $warnings = [];
    
    // 1. Проверка подозрительных попыток входа
    $suspiciousLoginsQuery = "
        SELECT ip, COUNT(*) as attempts, MAX(attempt_time) as last_attempt
        FROM login_attempts 
        WHERE success = false 
        AND attempt_time > NOW() - INTERVAL '24 hours'
        GROUP BY ip 
        HAVING COUNT(*) > 5
        ORDER BY attempts DESC
    ";
    
    $suspiciousLogins = $db->fetchAll($suspiciousLoginsQuery);
    
    foreach ($suspiciousLogins as $login) {
        $threats[] = "Подозрительная активность с IP {$login['ip']}: {$login['attempts']} неудачных попыток";
    }
    
    // 2. Проверка пользователей с простыми паролями
    $weakPasswordsQuery = "
        SELECT userid, email, fio 
        FROM users 
        WHERE password = ? OR password = ? OR password = ?
    ";
    
    $commonPasswords = [
        hashPassword('password'),
        hashPassword('123456'),
        hashPassword('admin')
    ];
    
    $weakPasswords = $db->fetchAll($weakPasswordsQuery, $commonPasswords);
    
    foreach ($weakPasswords as $user) {
        $warnings[] = "Пользователь {$user['email']} ({$user['fio']}) имеет слабый пароль";
    }
    
    // 3. Проверка неактивных пользователей с правами администратора
    $inactiveAdminsQuery = "
        SELECT u.userid, u.email, u.fio, u.accessrights, MAX(ua.created_at) as last_action
        FROM users u
        LEFT JOIN user_actions ua ON u.oid = ua.users_oid
        WHERE u.accessrights IN ('Admin', 'SuperUser', 'Organizer')
        GROUP BY u.oid, u.userid, u.email, u.fio, u.accessrights
        HAVING MAX(ua.created_at) < NOW() - INTERVAL '30 days' OR MAX(ua.created_at) IS NULL
    ";
    
    $inactiveAdmins = $db->fetchAll($inactiveAdminsQuery);
    
    foreach ($inactiveAdmins as $admin) {
        $warnings[] = "Неактивный администратор: {$admin['email']} ({$admin['fio']}) - роль: {$admin['accessrights']}";
    }
    
    // 4. Проверка файлов с подозрительными расширениями
    $uploadsDir = __DIR__ . '/../www/lks/files/';
    $suspiciousExtensions = ['php', 'php3', 'php4', 'php5', 'phtml', 'exe', 'bat', 'cmd', 'sh'];
    
    if (is_dir($uploadsDir)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsDir));
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $extension = strtolower(pathinfo($file->getPathname(), PATHINFO_EXTENSION));
                if (in_array($extension, $suspiciousExtensions)) {
                    $threats[] = "Подозрительный файл: " . $file->getPathname();
                }
            }
        }
    }
    
    // 5. Проверка прав доступа к файлам
    $criticalFiles = [
        __DIR__ . '/../www/lks/.env',
        __DIR__ . '/../www/lks/php/db/Database.php',
        __DIR__ . '/../docker-compose.yaml'
    ];
    
    foreach ($criticalFiles as $file) {
        if (file_exists($file)) {
            $perms = fileperms($file);
            if (($perms & 0x0177) !== 0) {
                $warnings[] = "Небезопасные права доступа к файлу: {$file}";
            }
        }
    }
    
    // 6. Проверка SSL сертификатов
    $sslCheck = @fsockopen('ssl://localhost', 443, $errno, $errstr, 5);
    if (!$sslCheck) {
        $warnings[] = "SSL соединение недоступно";
    } else {
        fclose($sslCheck);
    }
    
    // 7. Проверка обновлений системы
    $lastUpdateCheck = file_get_contents(__DIR__ . '/../logs/last_update_check.txt');
    if (!$lastUpdateCheck || (time() - strtotime($lastUpdateCheck)) > 86400 * 7) {
        $warnings[] = "Не проводилась проверка обновлений системы более 7 дней";
    }
    
    // 8. Проверка резервных копий
    $backupDir = __DIR__ . '/../backups/';
    if (is_dir($backupDir)) {
        $backups = glob($backupDir . '*.sql.gz');
        if (empty($backups)) {
            $threats[] = "Отсутствуют резервные копии базы данных";
        } else {
            $latestBackup = max(array_map('filemtime', $backups));
            if ((time() - $latestBackup) > 86400 * 2) {
                $warnings[] = "Резервная копия старше 2 дней";
            }
        }
    }
    
    // Логируем результаты
    logMessage("Проверка безопасности завершена");
    logMessage("Найдено угроз: " . count($threats));
    logMessage("Найдено предупреждений: " . count($warnings));
    
    // Создаем системное событие
    $description = "Проверка безопасности: " . count($threats) . " угроз, " . count($warnings) . " предупреждений";
    $severity = !empty($threats) ? 'error' : (!empty($warnings) ? 'warning' : 'info');
    
    $eventQuery = "
        INSERT INTO system_events (event_type, description, severity, created_at)
        VALUES (?, ?, ?, ?)
    ";
    
    $db->execute($eventQuery, ['security_check', $description, $severity, date('Y-m-d H:i:s')]);
    
    // Логируем детали
    foreach ($threats as $threat) {
        logMessage("УГРОЗА: {$threat}", 'error');
    }
    
    foreach ($warnings as $warning) {
        logMessage("ПРЕДУПРЕЖДЕНИЕ: {$warning}", 'warning');
    }
    
    // Обновляем время последней проверки
    file_put_contents(__DIR__ . '/../logs/last_update_check.txt', date('Y-m-d H:i:s'));
    
} catch (Exception $e) {
    logMessage("Критическая ошибка проверки безопасности: " . $e->getMessage(), 'error');
    exit(1);
}

logMessage("Проверка безопасности завершена успешно");
?> 
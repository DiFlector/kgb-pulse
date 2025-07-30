<?php
/**
 * Очистка старых данных системы KGB-Pulse
 * Скрипт удаляет устаревшие данные для оптимизации производительности
 * Запускается через cron ежедневно в 05:00
 */

// Подключаем необходимые файлы
require_once __DIR__ . '/../www/lks/php/db/Database.php';
require_once __DIR__ . '/../www/lks/php/helpers.php';

// Устанавливаем временную зону
date_default_timezone_set('Europe/Moscow');

// Функция логирования
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}" . PHP_EOL;
    error_log($logMessage, 3, __DIR__ . '/../logs/cleanup_old_data.log');
    echo $logMessage;
}

try {
    logMessage("Запуск очистки старых данных");
    
    $db = Database::getInstance();
    
    $deletedCounts = [];
    
    // 1. Удаляем старые попытки входа (старше 30 дней)
    $loginAttemptsQuery = "
        DELETE FROM login_attempts 
        WHERE attempt_time < NOW() - INTERVAL '30 days'
    ";
    
    $result = $db->execute($loginAttemptsQuery);
    $deletedCounts['login_attempts'] = $result->rowCount();
    logMessage("Удалено попыток входа: {$deletedCounts['login_attempts']}");
    
    // 2. Удаляем старые действия пользователей (старше 90 дней)
    $userActionsQuery = "
        DELETE FROM user_actions 
        WHERE created_at < NOW() - INTERVAL '90 days'
    ";
    
    $result = $db->execute($userActionsQuery);
    $deletedCounts['user_actions'] = $result->rowCount();
    logMessage("Удалено действий пользователей: {$deletedCounts['user_actions']}");
    
    // 3. Удаляем старые системные события (старше 180 дней)
    $systemEventsQuery = "
        DELETE FROM system_events 
        WHERE created_at < NOW() - INTERVAL '180 days'
        AND severity != 'error'
    ";
    
    $result = $db->execute($systemEventsQuery);
    $deletedCounts['system_events'] = $result->rowCount();
    logMessage("Удалено системных событий: {$deletedCounts['system_events']}");
    
    // 4. Удаляем прочитанные уведомления (старше 60 дней)
    $notificationsQuery = "
        DELETE FROM notifications 
        WHERE is_read = true 
        AND created_at < NOW() - INTERVAL '60 days'
    ";
    
    $result = $db->execute($notificationsQuery);
    $deletedCounts['notifications'] = $result->rowCount();
    logMessage("Удалено прочитанных уведомлений: {$deletedCounts['notifications']}");
    
    // 5. Удаляем старые временные файлы
    $tempDir = __DIR__ . '/../www/lks/files/temp/';
    if (is_dir($tempDir)) {
        $tempFiles = glob($tempDir . '*');
        $tempDeleted = 0;
        
        foreach ($tempFiles as $file) {
            if (is_file($file) && (time() - filemtime($file)) > 86400 * 7) { // 7 дней
                if (unlink($file)) {
                    $tempDeleted++;
                }
            }
        }
        
        $deletedCounts['temp_files'] = $tempDeleted;
        logMessage("Удалено временных файлов: {$deletedCounts['temp_files']}");
    }
    
    // 6. Удаляем старые логи приложений
    $logsDir = __DIR__ . '/../logs/';
    if (is_dir($logsDir)) {
        $logFiles = glob($logsDir . '*.log');
        $logsDeleted = 0;
        
        foreach ($logFiles as $file) {
            if (is_file($file) && (time() - filemtime($file)) > 86400 * 30) { // 30 дней
                if (unlink($file)) {
                    $logsDeleted++;
                }
            }
        }
        
        $deletedCounts['log_files'] = $logsDeleted;
        logMessage("Удалено старых логов: {$deletedCounts['log_files']}");
    }
    
    // 7. Удаляем старые резервные копии (старше 1 года)
    $backupDir = __DIR__ . '/../backups/';
    if (is_dir($backupDir)) {
        $backupFiles = glob($backupDir . '*.sql.gz');
        $backupsDeleted = 0;
        
        foreach ($backupFiles as $file) {
            if (is_file($file) && (time() - filemtime($file)) > 86400 * 365) { // 1 год
                if (unlink($file)) {
                    $backupsDeleted++;
                }
            }
        }
        
        $deletedCounts['backup_files'] = $backupsDeleted;
        logMessage("Удалено старых резервных копий: {$deletedCounts['backup_files']}");
    }
    
    // 8. Очищаем Redis кэш (если доступен)
    try {
        $redis = new Redis();
        if ($redis->connect('redis', 6379)) {
            $keys = $redis->keys('cache:*');
            $cacheDeleted = 0;
            
            foreach ($keys as $key) {
                $ttl = $redis->ttl($key);
                if ($ttl == -1) { // Ключ без TTL
                    $redis->del($key);
                    $cacheDeleted++;
                }
            }
            
            $deletedCounts['redis_cache'] = $cacheDeleted;
            logMessage("Очищено Redis кэша: {$deletedCounts['redis_cache']}");
        }
    } catch (Exception $e) {
        logMessage("Redis недоступен: " . $e->getMessage());
    }
    
    // Создаем системное событие
    $totalDeleted = array_sum($deletedCounts);
    $description = "Очистка старых данных завершена. Удалено записей: {$totalDeleted}";
    
    $eventQuery = "
        INSERT INTO system_events (event_type, description, severity, created_at)
        VALUES (?, ?, ?, ?)
    ";
    
    $db->execute($eventQuery, ['cleanup_old_data', $description, 'info', date('Y-m-d H:i:s')]);
    
    // Логируем итоговую статистику
    logMessage("Очистка завершена. Итого удалено записей: {$totalDeleted}");
    logMessage("Детализация: " . json_encode($deletedCounts));
    
} catch (Exception $e) {
    logMessage("Критическая ошибка очистки: " . $e->getMessage());
    exit(1);
}

logMessage("Очистка старых данных завершена успешно");
?> 
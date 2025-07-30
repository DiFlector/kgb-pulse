<?php
/**
 * Мониторинг системы KGB-Pulse
 * Скрипт проверяет состояние системы и создает отчеты
 * Запускается через cron каждые 5 минут
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
    error_log($logMessage, 3, __DIR__ . '/../logs/monitor_system.log');
    
    if ($level === 'error' || $level === 'warning') {
        echo $logMessage;
    }
}

try {
    logMessage("Запуск мониторинга системы");
    
    $db = Database::getInstance();
    
    // Проверяем подключение к базе данных
    if (!$db->isConnected()) {
        logMessage("ОШИБКА: Нет подключения к базе данных", 'error');
        throw new Exception("База данных недоступна");
    }
    
    // Получаем статистику системы
    $stats = getSystemStats($db->getPDO());
    
    // Проверяем критические метрики
    $warnings = [];
    $errors = [];
    
    // Проверка количества пользователей
    if ($stats['total_users'] < 1) {
        $warnings[] = "Нет пользователей в системе";
    }
    
    // Проверка количества мероприятий
    if ($stats['total_events'] < 1) {
        $warnings[] = "Нет мероприятий в системе";
    }
    
    // Проверка регистраций
    if ($stats['total_registrations'] < 1) {
        $warnings[] = "Нет регистраций в системе";
    }
    
    // Проверка свободного места на диске
    $diskUsage = disk_free_space('/') / disk_total_space('/') * 100;
    if ($diskUsage < 10) {
        $errors[] = "Критически мало свободного места на диске: " . round($diskUsage, 2) . "%";
    } elseif ($diskUsage < 20) {
        $warnings[] = "Мало свободного места на диске: " . round($diskUsage, 2) . "%";
    }
    
    // Проверка памяти
    $memoryUsage = memory_get_usage(true) / 1024 / 1024; // MB
    if ($memoryUsage > 512) {
        $warnings[] = "Высокое потребление памяти: " . round($memoryUsage, 2) . " MB";
    }
    
    // Проверка времени выполнения
    $executionTime = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
    if ($executionTime > 30) {
        $warnings[] = "Медленное выполнение скрипта: " . round($executionTime, 2) . " сек";
    }
    
    // Проверяем активные мероприятия
    $activeEventsQuery = "
        SELECT COUNT(*) as count 
        FROM meros 
        WHERE TRIM(status::text) IN ('Регистрация', 'Регистрация закрыта', 'В процессе')
    ";
    $activeEvents = $db->fetchColumn($activeEventsQuery);
    
    if ($activeEvents > 0) {
        logMessage("Активных мероприятий: {$activeEvents}");
    }
    
    // Проверяем недавние ошибки в логах
    $recentErrorsQuery = "
        SELECT COUNT(*) as count 
        FROM system_events 
        WHERE severity = 'error' 
        AND created_at > NOW() - INTERVAL '1 hour'
    ";
    $recentErrors = $db->fetchColumn($recentErrorsQuery);
    
    if ($recentErrors > 0) {
        $warnings[] = "Обнаружено {$recentErrors} ошибок за последний час";
    }
    
    // Проверяем попытки входа
    $failedLoginsQuery = "
        SELECT COUNT(*) as count 
        FROM login_attempts 
        WHERE success = false 
        AND attempt_time > NOW() - INTERVAL '1 hour'
    ";
    $failedLogins = $db->fetchColumn($failedLoginsQuery);
    
    if ($failedLogins > 10) {
        $warnings[] = "Много неудачных попыток входа: {$failedLogins} за час";
    }
    
    // Логируем предупреждения
    foreach ($warnings as $warning) {
        logMessage($warning, 'warning');
    }
    
    // Логируем ошибки
    foreach ($errors as $error) {
        logMessage($error, 'error');
    }
    
    // Создаем системное событие если есть проблемы
    if (!empty($warnings) || !empty($errors)) {
        $description = "Мониторинг системы: " . count($warnings) . " предупреждений, " . count($errors) . " ошибок";
        $severity = !empty($errors) ? 'error' : 'warning';
        
        $eventQuery = "
            INSERT INTO system_events (event_type, description, severity, created_at)
            VALUES (?, ?, ?, ?)
        ";
        
        $db->execute($eventQuery, ['system_monitor', $description, $severity, date('Y-m-d H:i:s')]);
    }
    
    // Логируем общую статистику
    logMessage("Статистика системы: " . json_encode($stats));
    
} catch (Exception $e) {
    logMessage("Критическая ошибка мониторинга: " . $e->getMessage(), 'error');
    exit(1);
}

logMessage("Мониторинг системы завершен");
?> 
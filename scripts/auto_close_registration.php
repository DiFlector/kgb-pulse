<?php
/**
 * Автоматическое закрытие регистрации на мероприятия
 * Скрипт проверяет время начала мероприятий и закрывает регистрацию
 * Запускается через cron каждый час
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
    error_log($logMessage, 3, __DIR__ . '/../logs/auto_close_registration.log');
    echo $logMessage;
}

try {
    logMessage("Запуск автоматического закрытия регистрации");
    
    $db = Database::getInstance();
    
    // Получаем текущее время
    $currentTime = date('Y-m-d H:i:s');
    logMessage("Текущее время: {$currentTime}");
    
    // Загружаем конфиг порогов
    $configPath = __DIR__ . '/../lks/files/json/cron_settings.json';
    $daysBeforeClose = 5; // по умолчанию
    if (is_file($configPath)) {
        $cfg = json_decode(file_get_contents($configPath), true);
        if (is_array($cfg) && isset($cfg['days_before_close']) && is_numeric($cfg['days_before_close'])) {
            $daysBeforeClose = max(0, (int)$cfg['days_before_close']);
        }
    }

    // 1) Открытие регистрации: мероприятия в статусе 'В ожидании' → 'Регистрация' за 30 дней до старта
    $queryOpen = "
        SELECT oid, champn, meroname, merodata, status::text as status
        FROM meros 
        WHERE TRIM(status::text) = 'В ожидании'
        AND merodata IS NOT NULL
        AND merodata != ''
    ";

    $eventsToOpen = $db->fetchAll($queryOpen);
    logMessage("Найдено мероприятий для открытия: " . count($eventsToOpen));

    $openedCount = 0;

    foreach ($eventsToOpen as $event) {
        try {
            $startDt = getEventStartDate($event['merodata']);
            if (!$startDt) {
                logMessage("Не удалось определить дату начала для {$event['champn']}");
                continue;
            }

            $openThreshold = (clone $startDt)->modify('-30 days');
            $now = new DateTime('now', new DateTimeZone('Europe/Moscow'));

            if ($now >= $openThreshold) {
                $db->execute("UPDATE meros SET status = 'Регистрация'::merostat WHERE oid = ?", [$event['oid']]);

                $db->execute(
                    "INSERT INTO system_events (event_type, description, severity, created_at) VALUES (?, ?, ?, ?)",
                    ['registration_opened', "Авто: открыта регистрация за 30 дней до старта для {$event['champn']}: {$event['meroname']}", 'info', $currentTime]
                );
                $openedCount++;
                logMessage("Открыта регистрация для {$event['champn']}: {$event['meroname']}");
            }
        } catch (Exception $e) {
            logMessage("Ошибка при открытии регистрации {$event['champn']}: " . $e->getMessage());
        }
    }

    // 2) Закрытие регистрации: мероприятия в статусе 'Регистрация' → 'Регистрация закрыта' за N дней до старта
    $query = "
        SELECT oid, champn, meroname, merodata, status::text as status
        FROM meros 
        WHERE TRIM(status::text) = 'Регистрация'
        AND merodata IS NOT NULL
        AND merodata != ''
    ";
    
    $events = $db->fetchAll($query);
    logMessage("Найдено мероприятий для проверки: " . count($events));
    
    $closedCount = 0;
    
    foreach ($events as $event) {
        try {
            // Парсим дату мероприятия
            $eventDate = parseEventDate($event['merodata']);
            
            if (!$eventDate) {
                logMessage("Не удалось распарсить дату для мероприятия {$event['champn']}: {$event['merodata']}");
                continue;
            }
            
            // Дата начала мероприятия
            $startDt = getEventStartDate($event['merodata']);
            if (!$startDt) {
                logMessage("Не удалось определить дату начала для {$event['champn']}");
                continue;
            }

            // За daysBeforeClose дней до старта — закрываем
            $closeThreshold = (clone $startDt)->modify("-{$daysBeforeClose} days");

            $now = new DateTime('now', new DateTimeZone('Europe/Moscow'));

            if ($now >= $closeThreshold) {
                // Закрываем регистрацию
                $updateQuery = "
                    UPDATE meros 
                    SET status = 'Регистрация закрыта'::merostat
                    WHERE oid = ?
                ";
                
                $db->execute($updateQuery, [$event['oid']]);
                
                logMessage("Закрыта регистрация для мероприятия {$event['champn']}: {$event['meroname']}");
                
                // Создаем системное событие
                $eventQuery = "
                    INSERT INTO system_events (event_type, description, severity, created_at)
                    VALUES (?, ?, ?, ?)
                ";
                
                $description = "Автоматическое закрытие регистрации (days={$daysBeforeClose}) для мероприятия {$event['champn']}: {$event['meroname']}";
                $db->execute($eventQuery, ['registration_closed', $description, 'info', $currentTime]);
                
                $closedCount++;
            }
            
        } catch (Exception $e) {
            logMessage("Ошибка при обработке мероприятия {$event['champn']}: " . $e->getMessage());
        }
    }
    
    logMessage("Обработка завершена. Закрыто регистраций: {$closedCount}");
    
} catch (Exception $e) {
    logMessage("Критическая ошибка: " . $e->getMessage());
    exit(1);
}

logMessage("Скрипт завершен успешно");
?> 
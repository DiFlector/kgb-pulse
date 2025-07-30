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
    
    // Находим мероприятия, где регистрация должна быть закрыта
    // Проверяем мероприятия со статусом 'Регистрация' и временем начала в прошлом
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
            
            // Проверяем, прошло ли время начала мероприятия
            // Закрываем регистрацию за 1 час до начала
            $eventStartTime = strtotime($eventDate);
            $closeTime = $eventStartTime - 3600; // За 1 час до начала
            $currentTimestamp = time();
            
            if ($currentTimestamp >= $closeTime) {
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
                
                $description = "Автоматическое закрытие регистрации для мероприятия {$event['champn']}: {$event['meroname']}";
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
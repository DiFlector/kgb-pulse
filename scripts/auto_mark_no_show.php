<?php
/**
 * Автоматическая отметка неявки участников
 * Скрипт отмечает участников как неявившихся через 30 минут после начала мероприятия
 * Запускается через cron каждые 30 минут
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
    error_log($logMessage, 3, __DIR__ . '/../logs/auto_mark_no_show.log');
    echo $logMessage;
}

try {
    logMessage("Запуск автоматической отметки неявки");
    
    $db = Database::getInstance();
    
    // Получаем текущее время
    $currentTime = date('Y-m-d H:i:s');
    logMessage("Текущее время: {$currentTime}");
    
    // Находим мероприятия, которые начались более 30 минут назад
    // и участников со статусами, которые можно отметить как неявка
    $query = "
        SELECT DISTINCT m.oid as meros_oid, m.champn, m.meroname, m.merodata, m.status::text as status
        FROM meros m
        INNER JOIN listreg l ON m.oid = l.meros_oid
        WHERE TRIM(m.status::text) IN ('Регистрация закрыта', 'В процессе', 'Результаты')
        AND TRIM(l.status::text) IN ('В очереди', 'Подтверждён', 'Ожидание команды')
        AND m.merodata IS NOT NULL
        AND m.merodata != ''
    ";
    
    $events = $db->fetchAll($query);
    logMessage("Найдено мероприятий для проверки: " . count($events));
    
    $markedCount = 0;
    
    foreach ($events as $event) {
        try {
            // Парсим дату мероприятия
            $eventDate = parseEventDate($event['merodata']);
            
            if (!$eventDate) {
                logMessage("Не удалось распарсить дату для мероприятия {$event['champn']}: {$event['merodata']}");
                continue;
            }
            
            // Проверяем, прошло ли 30 минут после начала мероприятия
            $eventStartTime = strtotime($eventDate);
            $markTime = $eventStartTime + 1800; // 30 минут после начала
            $currentTimestamp = time();
            
            if ($currentTimestamp >= $markTime) {
                // Отмечаем участников как неявившихся
                $updateQuery = "
                    UPDATE listreg 
                    SET status = 'Неявка'::statuses
                    WHERE meros_oid = ? 
                    AND TRIM(status::text) IN ('В очереди', 'Подтверждён', 'Ожидание команды')
                ";
                
                $result = $db->execute($updateQuery, [$event['meros_oid']]);
                $affectedRows = $result->rowCount();
                
                if ($affectedRows > 0) {
                    logMessage("Отмечено неявка для мероприятия {$event['champn']}: {$event['meroname']} - {$affectedRows} участников");
                    
                    // Создаем системное событие
                    $eventQuery = "
                        INSERT INTO system_events (event_type, description, severity, created_at)
                        VALUES (?, ?, ?, ?)
                    ";
                    
                    $description = "Автоматическая отметка неявки для мероприятия {$event['champn']}: {$event['meroname']} - {$affectedRows} участников";
                    $db->execute($eventQuery, ['no_show_marked', $description, 'warning', $currentTime]);
                    
                    $markedCount += $affectedRows;
                }
            }
            
        } catch (Exception $e) {
            logMessage("Ошибка при обработке мероприятия {$event['champn']}: " . $e->getMessage());
        }
    }
    
    logMessage("Обработка завершена. Отмечено неявка: {$markedCount} участников");
    
} catch (Exception $e) {
    logMessage("Критическая ошибка: " . $e->getMessage());
    exit(1);
}

logMessage("Скрипт завершен успешно");
?> 
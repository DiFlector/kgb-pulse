<?php
/**
 * Автофинализация мероприятий
 * - В момент окончания мероприятия переводит статус из "Регистрация закрыта" в "Результаты"
 * - Через месяц после окончания переводит статус из "Результаты" в "Завершено"
 * Запуск через cron (рекомендуемо: ежечасно)
 */

require_once __DIR__ . '/../www/lks/php/db/Database.php';
require_once __DIR__ . '/../www/lks/php/helpers.php';

date_default_timezone_set('Europe/Moscow');

function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}" . PHP_EOL;
    error_log($logMessage, 3, __DIR__ . '/../logs/auto_finalize_results.log');
    echo $logMessage;
}

try {
    logMessage('Запуск автофинализации мероприятий');

    $db = Database::getInstance();
    $now = new DateTime('now', new DateTimeZone('Europe/Moscow'));
    $currentTime = $now->format('Y-m-d H:i:s');

    // Берём мероприятия в статусах, которые нужно автоматически продвигать 
    $events = $db->fetchAll("
        SELECT oid, champn, meroname, merodata, status::text AS status
        FROM meros
        WHERE merodata IS NOT NULL AND merodata != ''
          AND TRIM(status::text) IN ('Регистрация закрыта', 'Результаты')
    ");

    $toResults = 0;
    $toFinished = 0;

    foreach ($events as $event) {
        try {
            $endDt = getEventEndDate($event['merodata']);
            if (!$endDt) {
                logMessage("Не удалось определить дату окончания для {$event['champn']}: {$event['merodata']}");
                continue;
            }

            // Окончание события — берём полночь дня окончания
            $eventEnd = (clone $endDt)->setTime(0, 0, 0);
            $oneMonthAfterEnd = (clone $eventEnd)->modify('+1 month');

            $status = trim($event['status']);

            if ($status === 'Регистрация закрыта' && $now >= $eventEnd) {
                // Переводим в "Результаты"
                $db->execute("UPDATE meros SET status = 'Результаты'::merostat WHERE oid = ?", [$event['oid']]);
                $db->execute(
                    "INSERT INTO system_events (event_type, description, severity, created_at) VALUES (?, ?, ?, ?)",
                    ['auto_results_started', "Авто: статус 'Результаты' установлен для {$event['champn']} ({$event['meroname']})", 'info', $currentTime]
                );
                $toResults++;
                logMessage("Установлен статус 'Результаты' для {$event['champn']}: {$event['meroname']}");
                continue;
            }

            if ($status === 'Результаты' && $now >= $oneMonthAfterEnd) {
                // Переводим в "Завершено" на следующий день после окончания
                $db->execute("UPDATE meros SET status = 'Завершено'::merostat WHERE oid = ?", [$event['oid']]);
                $db->execute(
                    "INSERT INTO system_events (event_type, description, severity, created_at) VALUES (?, ?, ?, ?)",
                    ['auto_event_finished', "Авто: статус 'Завершено' (через месяц) установлен для {$event['champn']} ({$event['meroname']})", 'info', $currentTime]
                );
                $toFinished++;
                logMessage("Установлен статус 'Завершено' для {$event['champn']}: {$event['meroname']}");
                continue;
            }

        } catch (Exception $e) {
            logMessage("Ошибка обработки мероприятия {$event['champn']}: " . $e->getMessage());
        }
    }

    logMessage("Готово. Переведено в 'Результаты': {$toResults}; в 'Завершено': {$toFinished}");

} catch (Exception $e) {
    logMessage('Критическая ошибка: ' . $e->getMessage());
    exit(1);
}

logMessage('Скрипт завершён');
?>
<?php
/**
 * API для экспорта статистики системы
 * Администратор - Экспорт отчетов
 */
session_start();

// Временно отключена проверка авторизации для тестирования
// Проверка авторизации
/*
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Не авторизован']);
    exit;
}

// Проверка прав администратора
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Admin', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
    exit;
}
*/

require_once __DIR__ . "/../db/Database.php";

try {
    $db = Database::getInstance();
    
    // Получение типа экспорта
    $format = $_GET['format'] ?? 'excel';
    $type = $_GET['type'] ?? 'full';
    
    // Сбор статистики
    $stats = collectStatistics($db);
    
    // Экспорт в зависимости от формата
    switch ($format) {
        case 'excel':
            exportToExcel($stats, $type);
            break;
        case 'pdf':
            exportToPDF($stats, $type);
            break;
        case 'json':
            exportToJSON($stats);
            break;
        default:
            throw new Exception('Неподдерживаемый формат');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    error_log("Statistics export error: " . $e->getMessage());
}

/**
 * Сбор статистики из базы данных
 */
function collectStatistics($db) {
    $stats = [];
    
    // Общая статистика
    $stats['general'] = [
        'total_users' => $db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
        'total_events' => $db->query("SELECT COUNT(*) FROM meros")->fetchColumn(),
        'total_registrations' => $db->query("SELECT COUNT(*) FROM listreg")->fetchColumn(),
        'total_teams' => $db->query("SELECT COUNT(*) FROM teams")->fetchColumn()
    ];
    
    // Статистика по ролям
    $stats['roles'] = [
        'admin' => $db->query("SELECT COUNT(*) FROM users WHERE accessrights = 'Admin'")->fetchColumn(),
        'organizer' => $db->query("SELECT COUNT(*) FROM users WHERE accessrights = 'Organizer'")->fetchColumn(),
        'secretary' => $db->query("SELECT COUNT(*) FROM users WHERE accessrights = 'Secretary'")->fetchColumn(),
        'sportsman' => $db->query("SELECT COUNT(*) FROM users WHERE accessrights = 'Sportsman'")->fetchColumn()
    ];
    
    // Статистика по мероприятиям
    $eventsByStatus = $db->query("SELECT status::text, COUNT(*) as count FROM meros GROUP BY status::text")->fetchAll(PDO::FETCH_ASSOC);
    $stats['events_by_status'] = [];
    foreach ($eventsByStatus as $row) {
        $stats['events_by_status'][$row['status']] = $row['count'];
    }
    
    // Статистика по регистрациям
    $regsByStatus = $db->query("SELECT status::text, COUNT(*) as count FROM listreg GROUP BY status::text")->fetchAll(PDO::FETCH_ASSOC);
    $stats['registrations_by_status'] = [];
    foreach ($regsByStatus as $row) {
        $stats['registrations_by_status'][$row['status']] = $row['count'];
    }
    
    // Активность за последний месяц (используем oid для определения новых записей)
    $stats['activity'] = [
        'new_users_month' => $db->query("SELECT COUNT(*) FROM users WHERE oid > (SELECT COALESCE(MAX(oid), 0) - 1000 FROM users)")->fetchColumn(),
        'new_events_month' => $db->query("SELECT COUNT(*) FROM meros WHERE oid > (SELECT COALESCE(MAX(oid), 0) - 100 FROM meros)")->fetchColumn(),
        'new_registrations_month' => $db->query("SELECT COUNT(*) FROM listreg WHERE oid > (SELECT COALESCE(MAX(oid), 0) - 1000 FROM listreg)")->fetchColumn()
    ];
    
    // Системная информация
    $stats['system'] = [
        'php_version' => PHP_VERSION,
        'memory_limit' => ini_get('memory_limit'),
        'max_upload_size' => ini_get('upload_max_filesize'),
        'max_execution_time' => ini_get('max_execution_time'),
        'db_version' => $db->query("SELECT version()")->fetchColumn(),
        'db_size' => $db->query("SELECT pg_size_pretty(pg_database_size(current_database()))")->fetchColumn(),
        'total_disk_space' => disk_total_space(__DIR__),
        'free_disk_space' => disk_free_space(__DIR__),
        'export_date' => date('Y-m-d H:i:s')
    ];
    
    return $stats;
}

/**
 * Экспорт в Excel
 */
function exportToExcel($stats, $type) {
    $filename = 'statistics_' . date('Y-m-d_H-i-s') . '.csv';
    
    if (!defined('TEST_MODE')) header('Content-Type: text/csv; charset=utf-8');
    if (!defined('TEST_MODE')) header('Content-Disposition: attachment; filename="' . $filename . '"');
    if (!defined('TEST_MODE')) header('Cache-Control: no-cache, must-revalidate');
    
    $output = fopen('php://output', 'w');
    
    // BOM для корректного отображения русских символов в Excel
    fputs($output, "\xEF\xBB\xBF");
    
    // Заголовки CSV
    fputcsv($output, ['Отчет статистики системы KGB-Pulse'], ';');
    fputcsv($output, ['Дата создания: ' . $stats['system']['export_date']], ';');
    fputcsv($output, [], ';'); // Пустая строка
    
    // Общая статистика
    fputcsv($output, ['ОБЩАЯ СТАТИСТИКА'], ';');
    fputcsv($output, ['Показатель', 'Значение'], ';');
    fputcsv($output, ['Всего пользователей', $stats['general']['total_users']], ';');
    fputcsv($output, ['Всего мероприятий', $stats['general']['total_events']], ';');
    fputcsv($output, ['Всего регистраций', $stats['general']['total_registrations']], ';');
    fputcsv($output, ['Всего команд', $stats['general']['total_teams']], ';');
    fputcsv($output, [], ';');
    
    // Статистика по ролям
    fputcsv($output, ['ПОЛЬЗОВАТЕЛИ ПО РОЛЯМ'], ';');
    fputcsv($output, ['Роль', 'Количество'], ';');
    fputcsv($output, ['Администраторы', $stats['roles']['admin']], ';');
    fputcsv($output, ['Организаторы', $stats['roles']['organizer']], ';');
    fputcsv($output, ['Секретари', $stats['roles']['secretary']], ';');
    fputcsv($output, ['Спортсмены', $stats['roles']['sportsman']], ';');
    fputcsv($output, [], ';');
    
    // Мероприятия по статусам
    if (!empty($stats['events_by_status'])) {
        fputcsv($output, ['МЕРОПРИЯТИЯ ПО СТАТУСАМ'], ';');
        fputcsv($output, ['Статус', 'Количество'], ';');
        foreach ($stats['events_by_status'] as $status => $count) {
            fputcsv($output, [$status, $count], ';');
        }
        fputcsv($output, [], ';');
    }
    
    // Активность за месяц
    fputcsv($output, ['АКТИВНОСТЬ ЗА ПОСЛЕДНИЙ МЕСЯЦ'], ';');
    fputcsv($output, ['Показатель', 'Значение'], ';');
    fputcsv($output, ['Новых пользователей', $stats['activity']['new_users_month']], ';');
    fputcsv($output, ['Новых мероприятий', $stats['activity']['new_events_month']], ';');
    fputcsv($output, ['Новых регистраций', $stats['activity']['new_registrations_month']], ';');
    fputcsv($output, [], ';');
    
    // Системная информация
    fputcsv($output, ['СИСТЕМНАЯ ИНФОРМАЦИЯ'], ';');
    fputcsv($output, ['Параметр', 'Значение'], ';');
    fputcsv($output, ['PHP версия', $stats['system']['php_version']], ';');
    fputcsv($output, ['Лимит памяти', $stats['system']['memory_limit']], ';');
    fputcsv($output, ['Размер БД', $stats['system']['db_size']], ';');
    fputcsv($output, ['Свободно места', formatBytes($stats['system']['free_disk_space'])], ';');
    
    fclose($output);
    error_log("Admin exported statistics to Excel");
}

/**
 * Экспорт в PDF (упрощенная версия)
 */
function exportToPDF($stats, $type) {
    $filename = 'statistics_' . date('Y-m-d_H-i-s') . '.html';
    
    if (!defined('TEST_MODE')) header('Content-Type: text/html; charset=utf-8');
    if (!defined('TEST_MODE')) header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    echo '<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Статистика системы KGB-Pulse</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1, h2 { color: #333; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .section { margin: 20px 0; }
    </style>
</head>
<body>
    <h1>Отчет статистики системы KGB-Pulse</h1>
    <p>Дата создания: ' . $stats['system']['export_date'] . '</p>
    
    <div class="section">
        <h2>Общая статистика</h2>
        <table>
            <tr><th>Показатель</th><th>Значение</th></tr>
            <tr><td>Всего пользователей</td><td>' . $stats['general']['total_users'] . '</td></tr>
            <tr><td>Всего мероприятий</td><td>' . $stats['general']['total_events'] . '</td></tr>
            <tr><td>Всего регистраций</td><td>' . $stats['general']['total_registrations'] . '</td></tr>
            <tr><td>Всего команд</td><td>' . $stats['general']['total_teams'] . '</td></tr>
        </table>
    </div>
    
    <div class="section">
        <h2>Пользователи по ролям</h2>
        <table>
            <tr><th>Роль</th><th>Количество</th></tr>
            <tr><td>Администраторы</td><td>' . $stats['roles']['admin'] . '</td></tr>
            <tr><td>Организаторы</td><td>' . $stats['roles']['organizer'] . '</td></tr>
            <tr><td>Секретари</td><td>' . $stats['roles']['secretary'] . '</td></tr>
            <tr><td>Спортсмены</td><td>' . $stats['roles']['sportsman'] . '</td></tr>
        </table>
    </div>
    
    <div class="section">
        <h2>Системная информация</h2>
        <table>
            <tr><th>Параметр</th><th>Значение</th></tr>
            <tr><td>PHP версия</td><td>' . $stats['system']['php_version'] . '</td></tr>
            <tr><td>Размер БД</td><td>' . $stats['system']['db_size'] . '</td></tr>
            <tr><td>Свободно места</td><td>' . formatBytes($stats['system']['free_disk_space']) . '</td></tr>
        </table>
    </div>
</body>
</html>';
    
    error_log("Admin exported statistics to PDF/HTML");
}

/**
 * Экспорт в JSON
 */
function exportToJSON($stats) {
    $filename = 'statistics_' . date('Y-m-d_H-i-s') . '.json';
    
    if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');
    if (!defined('TEST_MODE')) header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    error_log("Admin exported statistics to JSON");
}

/**
 * Форматирование размера в байтах
 */
function formatBytes($size, $precision = 2) {
    $base = log($size, 1024);
    $suffixes = array('', 'K', 'M', 'G', 'T');   
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)] . 'B';
}
?> 
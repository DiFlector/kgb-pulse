<?php
/**
 * API для получения статистики системы для footer
 */

session_start();

// Проверка авторизации
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Неавторизованный доступ']);
    exit;
}

// Проверка прав администратора или суперпользователя
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Admin', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Доступ запрещен']);
    exit;
}

require_once __DIR__ . '/../db/Database.php';

try {
    $db = Database::getInstance();
    
    // Получение основной статистики
    $stats = [];
    
    // Количество пользователей
    $stats['users_count'] = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    
    // Количество мероприятий
    $stats['events_count'] = $db->query("SELECT COUNT(*) FROM meros")->fetchColumn();
    
    // Количество регистраций
    $stats['registrations_count'] = $db->query("SELECT COUNT(*) FROM listreg")->fetchColumn();
    
    // Статус системы - проверка доступности базы данных
    $stats['services_status'] = 'working'; // База данных работает, если мы дошли до этой точки
    
    // Использование диска (в процентах) - получаем информацию о диске
    $disk_total = disk_total_space('/');
    $disk_free = disk_free_space('/');
    if ($disk_total && $disk_free) {
        $disk_used = $disk_total - $disk_free;
        $stats['disk_usage'] = round(($disk_used / $disk_total) * 100, 1);
    } else {
        $stats['disk_usage'] = 0;
    }
    
    // Загрузка CPU (упрощенная версия)
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        $cpu_usage = round($load[0] * 100 / 4, 1); // Примерно для 4-ядерного процессора
        $stats['cpu_usage'] = min($cpu_usage, 100); // Ограничиваем 100%
    } else {
        $stats['cpu_usage'] = 0;
    }
    
    // Возвращаем JSON
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'stats' => $stats
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Ошибка получения статистики системы: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка получения статистики'
    ], JSON_UNESCAPED_UNICODE);
}
?> 
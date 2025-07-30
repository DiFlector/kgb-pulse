<?php
/**
 * Получение статистики для администратора
 * Возвращает данные о пользователях, мероприятиях, регистрациях и системе
 */

if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/Auth.php";
$auth = new Auth();

// УДАЛЁН fallback для тестов: никаких подстановок user_id и user_role

if (!$auth->isAuthenticated() || !$auth->hasAnyRole(['Admin', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Доступ запрещен']);
    exit;
}

try {
    $pdo = Database::getInstance()->getPDO();
    
    // Статистика пользователей
    $userStats = [];
    
    // Общее количество пользователей
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
    $userStats['total'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Количество по ролям
    $stmt = $pdo->query("
        SELECT accessrights, COUNT(*) as count 
        FROM users 
        GROUP BY accessrights
    ");
    $userStats['by_role'] = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $userStats['by_role'][$row['accessrights']] = $row['count'];
    }
    
    // Новые пользователи за последние 30 дней (пока недоступно)
    $userStats['new_last_month'] = 0;
    
    // Статистика мероприятий
    $eventStats = [];
    
    // Общее количество мероприятий
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM meros");
    $eventStats['total'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Количество по статусам
    $stmt = $pdo->query("
        SELECT status, COUNT(*) as count 
        FROM meros 
        GROUP BY status
    ");
    $eventStats['by_status'] = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $eventStats['by_status'][$row['status']] = $row['count'];
    }
    
    // Статистика регистраций
    $registrationStats = [];
    
    // Общее количество регистраций
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM listreg");
    $registrationStats['total'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Количество по статусам регистраций
    $stmt = $pdo->query("
        SELECT status, COUNT(*) as count 
        FROM listreg 
        GROUP BY status
    ");
    $registrationStats['by_status'] = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $registrationStats['by_status'][$row['status']] = $row['count'];
    }
    
    // Количество оплаченных регистраций
    $stmt = $pdo->query("SELECT COUNT(*) as paid FROM listreg WHERE oplata = true");
    $registrationStats['paid'] = $stmt->fetch(PDO::FETCH_ASSOC)['paid'];
    
    // Системная статистика
    $systemStats = [];
    
    // Размер базы данных
    $stmt = $pdo->query("
        SELECT pg_size_pretty(pg_database_size(current_database())) as db_size
    ");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $systemStats['database_size'] = $result ? $result['db_size'] : 'Неизвестно';
    
    // Количество таблиц
    $stmt = $pdo->query("
        SELECT COUNT(*) as table_count 
        FROM information_schema.tables 
        WHERE table_schema = 'public'
    ");
    $systemStats['table_count'] = $stmt->fetch(PDO::FETCH_ASSOC)['table_count'];
    
    // Статистика по файлам (примерная)
    $fileStats = [];
    $fileStats['total_files'] = 0;
    $fileStats['total_size'] = '0 MB';
    
    // Попытка получить статистику файлов
    $filesDir = $_SERVER['DOCUMENT_ROOT'] . '/lks/files/';
    if (is_dir($filesDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($filesDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        $totalSize = 0;
        $totalFiles = 0;
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $totalFiles++;
                $totalSize += $file->getSize();
            }
        }
        
        $fileStats['total_files'] = $totalFiles;
        $fileStats['total_size'] = formatBytes($totalSize);
    }
    
    // Последние активности (если есть логи)
    $recentActivity = [];
    
    // Возвращаем результат
    $response = [
        'success' => true,
        'users' => $userStats,
        'events' => $eventStats,
        'registrations' => $registrationStats,
        'system' => $systemStats,
        'files' => $fileStats,
        'recent_activity' => $recentActivity,
        'timestamp' => date('Y-m-d H:i:s'),
        // Для тестов:
        'total_users' => $userStats['total'],
        'total_events' => $eventStats['total'],
        'total_registrations' => $registrationStats['total']
    ];
    
    $jsonOutput = json_encode($response, JSON_UNESCAPED_UNICODE);
    
    if (defined('TEST_MODE')) {
        // В тестовом режиме только сохраняем в GLOBALS, НЕ выводим
        $GLOBALS['test_json_response'] = $jsonOutput;
    } else {
        // В обычном режиме выводим заголовки и JSON
        header('Content-Type: application/json; charset=utf-8');
        echo $jsonOutput;
        exit;
    }
    
} catch (Exception $e) {
    error_log("Ошибка получения статистики: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка получения статистики: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Форматирование размера файла
 */
if (!function_exists('formatBytes')) {
    function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
?> 
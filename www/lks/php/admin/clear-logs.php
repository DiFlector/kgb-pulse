<?php
/**
 * API для очистки системных логов
 * Только для администраторов
 */

// Заголовки JSON и CORS
if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Origin: *');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Methods: POST, OPTIONS');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

// Проверка авторизации
if (!isset($_SESSION)) {
    session_start();
}

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/Auth.php';

$auth = new Auth();

// Проверка авторизации и прав администратора
if (!$auth->isAuthenticated() || !$auth->hasAnyRole(['Admin', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Доступ запрещен'
    ]);
    exit;
}

// Проверка метода запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Метод не поддерживается'
    ]);
    exit;
}

try {
    $clearedLogs = [];
    $errors = [];
    
    // Определяем пути к логам
    $logPaths = [
        'php_errors' => '/var/log/php_errors.log',
        'nginx_access' => '/var/log/nginx/access.log',
        'nginx_error' => '/var/log/nginx/error.log',
        'app_logs' => __DIR__ . '/../../../logs/app.log',
        'system_logs' => __DIR__ . '/../../../logs/system.log'
    ];
    
    // Очищаем каждый лог-файл
    foreach ($logPaths as $name => $path) {
        try {
            if (file_exists($path)) {
                // Создаем резервную копию перед очисткой
                $backupPath = dirname($path) . '/backup_' . date('Y-m-d_H-i-s') . '_' . basename($path);
                
                // Копируем файл в резервную копию
                if (copy($path, $backupPath)) {
                    // Очищаем основной файл
                    if (file_put_contents($path, '') !== false) {
                        $clearedLogs[] = $name;
                    } else {
                        $errors[] = "Не удалось очистить $name";
                    }
                } else {
                    // Если не удалось создать резервную копию, очищаем без неё
                    if (file_put_contents($path, '') !== false) {
                        $clearedLogs[] = $name;
                    } else {
                        $errors[] = "Не удалось очистить $name";
                    }
                }
            }
        } catch (Exception $e) {
            $errors[] = "Ошибка при очистке $name: " . $e->getMessage();
        }
    }
    
    // Очистка логов базы данных (если есть таблица логов)
    try {
        $db = Database::getInstance();
        $pdo = $db->getPDO();
        
        // Проверяем наличие таблицы логов
        $stmt = $pdo->query("SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_name = 'system_logs')");
        if ($stmt->fetchColumn()) {
            // Очищаем таблицу логов (оставляем записи за последние 7 дней)
            $stmt = $pdo->prepare("DELETE FROM system_logs WHERE created_at < NOW() - INTERVAL '7 days'");
            if ($stmt->execute()) {
                $clearedLogs[] = 'database_logs';
            }
        }
    } catch (Exception $e) {
        $errors[] = "Ошибка при очистке логов БД: " . $e->getMessage();
    }
    
    // Очистка временных файлов
    try {
        $tempDirs = [
            __DIR__ . '/../../../www/lks/files/excel/temp',
            __DIR__ . '/../../../www/lks/files/pdf/temp',
            sys_get_temp_dir() . '/kgb_pulse'
        ];
        
        foreach ($tempDirs as $tempDir) {
            if (is_dir($tempDir)) {
                $files = glob($tempDir . '/*');
                foreach ($files as $file) {
                    if (is_file($file) && (time() - filemtime($file)) > 3600) { // старше часа
                        unlink($file);
                    }
                }
                $clearedLogs[] = basename($tempDir) . '_temp_files';
            }
        }
    } catch (Exception $e) {
        $errors[] = "Ошибка при очистке временных файлов: " . $e->getMessage();
    }
    
    // Логирование действия
    error_log("Логи очищены администратором (пользователь: " . $_SESSION['user_id'] . "). Очищено: " . implode(', ', $clearedLogs));
    
    if (empty($clearedLogs)) {
        throw new Exception('Не удалось очистить ни один лог-файл');
    }
    
    $message = 'Очищено: ' . implode(', ', $clearedLogs);
    if (!empty($errors)) {
        $message .= '. Ошибки: ' . implode(', ', $errors);
    }
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'cleared' => $clearedLogs,
        'errors' => $errors
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка очистки логов: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
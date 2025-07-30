<?php
/**
 * API для удаления файлов
 * Только для администраторов
 */

// Заголовки JSON и CORS
if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Origin: *');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Methods: POST, OPTIONS');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

// Запускаем сессию в первую очередь
    session_start();

require_once __DIR__ . "/../db/Database.php";

// Проверка авторизации и прав администратора
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Не авторизован'
    ]);
    exit;
}

if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Admin', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Недостаточно прав'
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
    // Чтение JSON данных
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['file_path'])) {
        throw new Exception('Отсутствует путь к файлу');
    }
    
    $filePath = $input['file_path'];
    
    // Определяем полный путь к файлу
    $baseDir = realpath(__DIR__ . '/../../files/');
    $fullFilePath = $baseDir . '/' . ltrim($filePath, '/');
    
    // Проверяем существование файла
    if (!file_exists($fullFilePath)) {
        throw new Exception('Файл не найден');
    }
    
    // Проверяем, что файл находится в разрешенной директории
    if (strpos(realpath($fullFilePath), $baseDir) !== 0) {
        throw new Exception('Операция запрещена');
    }
    
    // Проверяем, что это не системный файл
    $fileName = basename($fullFilePath);
    $systemFiles = ['.htaccess', 'index.php', 'config.php'];
    if (in_array($fileName, $systemFiles)) {
        throw new Exception('Удаление системных файлов запрещено');
    }
    
    // Если это директория - запрещаем удаление
    if (is_dir($fullFilePath)) {
        throw new Exception('Удаление директорий не поддерживается');
    }
    
    // Создаем резервную копию (перемещаем в папку .trash)
    $trashDir = $baseDir . '/.trash';
    if (!is_dir($trashDir)) {
        mkdir($trashDir, 0755, true);
    }
    
    $trashPath = $trashDir . '/' . date('Y-m-d_H-i-s') . '_' . $fileName;
    
    // Отвязываем файл от мероприятий перед удалением
    $db = Database::getInstance();
    $detachedEvents = [];
    
    // Ищем мероприятия, которые используют этот файл
    $stmt = $db->prepare("
        SELECT champn, meroname, filepolojenie, fileprotokol, fileresults 
        FROM meros 
        WHERE filepolojenie LIKE ? OR fileprotokol LIKE ? OR fileresults LIKE ?
    ");
    $searchPattern = '%' . basename($fullFilePath);
    $stmt->execute([$searchPattern, $searchPattern, $searchPattern]);
    $events = $stmt->fetchAll();
    
    // Отвязываем файл от найденных мероприятий
    foreach ($events as $event) {
        $updateFields = [];
        $eventUpdated = false;
        
        if (!empty($event['filepolojenie']) && basename($event['filepolojenie']) === basename($fullFilePath)) {
            $updateFields[] = 'filepolojenie = NULL';
            $eventUpdated = true;
        }
        if (!empty($event['fileprotokol']) && basename($event['fileprotokol']) === basename($fullFilePath)) {
            $updateFields[] = 'fileprotokol = NULL';
            $eventUpdated = true;
        }
        if (!empty($event['fileresults']) && basename($event['fileresults']) === basename($fullFilePath)) {
            $updateFields[] = 'fileresults = NULL';
            $eventUpdated = true;
        }
        
        if ($eventUpdated) {
            $updateSql = "UPDATE meros SET " . implode(', ', $updateFields) . " WHERE champn = ?";
            $db->execute($updateSql, [$event['champn']]);
            $detachedEvents[] = $event['meroname'];
        }
    }
    
    // Перемещаем файл в корзину вместо удаления
    if (!rename($fullFilePath, $trashPath)) {
        // Если не удалось переместить, удаляем окончательно
        if (!unlink($fullFilePath)) {
            throw new Exception('Ошибка удаления файла');
        }
    }
    
    // Логирование действия
    $logMessage = "Файл удален: {$filePath} (пользователь: " . $_SESSION['user_id'] . ")";
    if (!empty($detachedEvents)) {
        $logMessage .= ". Отвязан от мероприятий: " . implode(', ', $detachedEvents);
    }
    error_log($logMessage);
    
    $responseMessage = 'Файл успешно удален';
    if (!empty($detachedEvents)) {
        $responseMessage .= '. Файл был отвязан от мероприятий: ' . implode(', ', $detachedEvents);
    }
    
    echo json_encode([
        'success' => true,
        'message' => $responseMessage,
        'detached_events' => $detachedEvents
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка удаления файла: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
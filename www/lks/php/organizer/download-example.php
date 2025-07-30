<?php
/**
 * API для скачивания примера Excel файла мероприятия
 */

// ВАЖНО: Никакого вывода до этого момента!
if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../common/Auth.php";

// В режиме тестирования пропускаем проверку аутентификации
if (!defined('TEST_MODE')) {
    $auth = new Auth();
    $user = $auth->checkRole(['Organizer', 'SuperUser', 'Admin']);
    if (!$user) {
        http_response_code(403);
        exit('Доступ запрещен');
    }
}

try {
    // ОЧИЩАЕМ все буферы вывода перед отправкой файла
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Путь к готовому файлу примера
    $exampleFile = __DIR__ . '/../../files/template/example.xlsx';
    
    // Проверяем, что файл существует
    if (!file_exists($exampleFile)) {
        throw new Exception('Файл примера не найден: ' . $exampleFile);
    }
    
    // Проверяем, что файл читается
    if (!is_readable($exampleFile)) {
        throw new Exception('Файл примера недоступен для чтения');
    }
    
    $fileSize = filesize($exampleFile);
    if ($fileSize === false || $fileSize == 0) {
        throw new Exception('Файл примера пуст или поврежден');
    }

    // Устанавливаем правильные заголовки для скачивания
    if (!defined('TEST_MODE')) header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    if (!defined('TEST_MODE')) header('Content-Disposition: attachment; filename="create-event-example.xlsx"');
    if (!defined('TEST_MODE')) header('Content-Length: ' . $fileSize);
    if (!defined('TEST_MODE')) header('Cache-Control: no-cache, no-store, must-revalidate');
    if (!defined('TEST_MODE')) header('Pragma: no-cache');
    if (!defined('TEST_MODE')) header('Expires: 0');
    
    // Дополнительные заголовки для совместимости
    if (!defined('TEST_MODE')) header('Content-Transfer-Encoding: binary');
    if (!defined('TEST_MODE')) header('Accept-Ranges: bytes');
    
    // Отключаем буферизацию полностью
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Читаем и выводим файл порциями для больших файлов
    $handle = fopen($exampleFile, 'rb');
    if ($handle) {
        while (!feof($handle)) {
            echo fread($handle, 8192);
            flush();
        }
        fclose($handle);
    } else {
        // Fallback метод
        readfile($exampleFile);
    }
    
    // Завершаем выполнение
    if (defined('TEST_MODE')) {
        // В режиме тестирования просто завершаем успешно
        return;
    } else {
        exit;
    }
    
} catch (Exception $e) {
    // В случае ошибки очищаем все заголовки и выводим ошибку
    if (!headers_sent()) {
        if (!defined('TEST_MODE')) header('Content-Type: text/plain; charset=utf-8');
    }
    
    error_log("Ошибка скачивания примера файла: " . $e->getMessage());
    
    if (defined('TEST_MODE')) {
        // В режиме тестирования не выходим из скрипта
        echo 'Ошибка: ' . $e->getMessage();
        return;
    } else {
        exit('Ошибка: ' . $e->getMessage());
    }
}
?> 
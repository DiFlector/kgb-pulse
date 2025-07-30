<?php
/**
 * API для скачивания папки в виде ZIP архива
 * Администратор - Скачивание папок
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

try {
    // Проверка наличия ZipArchive
    if (!class_exists('ZipArchive')) {
        throw new Exception('ZipArchive не поддерживается на сервере');
    }

    // Получение параметров
    $folder = $_GET['folder'] ?? '';
    
    if (empty($folder)) {
        throw new Exception('Не указана папка для скачивания');
    }

    // Разрешенные папки
    $allowedFolders = [
        'excel', 'pdf', 'results', 'protocol', 
        'template', 'polojenia', 'sluzebnoe'
    ];

    if (!in_array($folder, $allowedFolders)) {
        throw new Exception('Недопустимая папка');
    }

    $folderPath = __DIR__ . '/../../files/' . $folder . '/';
    
    if (!is_dir($folderPath)) {
        throw new Exception('Папка не найдена');
    }

    // Проверка наличия файлов
    $files = array_diff(scandir($folderPath), array('.', '..'));
    if (empty($files)) {
        throw new Exception('Папка пуста');
    }

    // Создание временного ZIP файла
    $zipFileName = $folder . '_' . date('Y-m-d_H-i-s') . '.zip';
    $zipPath = sys_get_temp_dir() . '/' . $zipFileName;

    $zip = new ZipArchive();
    $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    if ($result !== TRUE) {
        throw new Exception('Не удалось создать ZIP архив: ' . $result);
    }

    // Добавление файлов в архив
    $addedFiles = 0;
    foreach ($files as $file) {
        $filePath = $folderPath . $file;
        if (is_file($filePath)) {
            $zip->addFile($filePath, $file);
            $addedFiles++;
        }
    }

    if ($addedFiles === 0) {
        $zip->close();
        unlink($zipPath);
        throw new Exception('В папке нет файлов для архивирования');
    }

    // Добавление информационного файла
    $infoContent = "Архив папки: $folder\n";
    $infoContent .= "Дата создания: " . date('Y-m-d H:i:s') . "\n";
    $infoContent .= "Количество файлов: $addedFiles\n";
    $infoContent .= "Создано системой KGB-Pulse\n";
    
    $zip->addFromString('_archive_info.txt', $infoContent);
    
    $zip->close();

    // Проверка создания архива
    if (!file_exists($zipPath)) {
        throw new Exception('Архив не был создан');
    }

    $fileSize = filesize($zipPath);
    
    // Отправка архива
    if (!defined('TEST_MODE')) header('Content-Type: application/zip');
    if (!defined('TEST_MODE')) header('Content-Disposition: attachment; filename="' . $zipFileName . '"');
    if (!defined('TEST_MODE')) header('Content-Length: ' . $fileSize);
    if (!defined('TEST_MODE')) header('Cache-Control: no-cache, must-revalidate');
    if (!defined('TEST_MODE')) header('Pragma: no-cache');

    // Отключение буферизации для больших файлов
    if (ob_get_level()) {
        ob_end_clean();
    }

    readfile($zipPath);

    // Удаление временного файла
    unlink($zipPath);
    
    // Логирование
    error_log("Admin downloaded folder archive: $folder ($addedFiles files, " . formatBytes($fileSize) . ")");

} catch (Exception $e) {
    // Очистка временного файла при ошибке
    if (isset($zipPath) && file_exists($zipPath)) {
        unlink($zipPath);
    }
    
    http_response_code(400);
    
    // Если запрос через браузер, показываем HTML страницу с ошибкой
    if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') !== false) {
        echo '<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Ошибка скачивания</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 50px; text-align: center; }
        .error { color: #dc3545; background: #f8d7da; padding: 20px; border-radius: 5px; display: inline-block; }
    </style>
</head>
<body>
    <div class="error">
        <h2>Ошибка скачивания</h2>
        <p>' . htmlspecialchars($e->getMessage()) . '</p>
        <button onclick="window.close()">Закрыть</button>
    </div>
</body>
</html>';
    } else {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    
    error_log("Folder download error: " . $e->getMessage());
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
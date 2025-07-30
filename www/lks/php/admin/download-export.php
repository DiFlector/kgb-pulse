<?php
/**
 * Скачивание экспортированных файлов
 * Автоматически удаляет файл после успешного скачивания
 */
session_start();

// Проверка авторизации
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    throw new Exception('Не авторизован');
}

// Проверка прав администратора или суперпользователя
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Admin', 'SuperUser'])) {
    http_response_code(403);
    throw new Exception('Недостаточно прав');
}

$filename = $_GET['file'] ?? '';

if (empty($filename)) {
    http_response_code(400);
    throw new Exception('Не указан файл для скачивания');
}

// Проверяем безопасность имени файла
if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
    http_response_code(400);
    throw new Exception('Недопустимое имя файла');
}

$tempDir = __DIR__ . '/../../files/temp';
$filePath = $tempDir . '/' . $filename;

// Проверяем существование файла
if (!file_exists($filePath)) {
    http_response_code(404);
    throw new Exception('Файл не найден или уже был удален');
}

// Проверяем возраст файла (удаляем файлы старше 1 часа)
$fileAge = time() - filemtime($filePath);
if ($fileAge > 3600) { // 1 час
    unlink($filePath);
    http_response_code(410);
    throw new Exception('Файл устарел и был удален. Создайте новый экспорт.');
}

// Определяем MIME тип
$fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$mimeTypes = [
    'csv' => 'text/csv',
    'zip' => 'application/zip',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
];

$mimeType = $mimeTypes[$fileExtension] ?? 'application/octet-stream';

// Устанавливаем заголовки для скачивания
if (!defined('TEST_MODE')) header('Content-Type: ' . $mimeType);
if (!defined('TEST_MODE')) header('Content-Disposition: attachment; filename="' . $filename . '"');
if (!defined('TEST_MODE')) header('Content-Length: ' . filesize($filePath));
if (!defined('TEST_MODE')) header('Cache-Control: no-cache, must-revalidate');
if (!defined('TEST_MODE')) header('Expires: 0');

// Отправляем файл
if (readfile($filePath)) {
    // Файл успешно отправлен, удаляем его
    unlink($filePath);
    
    // Логируем скачивание
    error_log("Экспорт скачан и удален: $filename пользователем {$_SESSION['user_id']}");
} else {
    http_response_code(500);
    throw new Exception('Ошибка при отправке файла');
}

// Очищаем старые файлы в директории temp (старше 1 часа)
$files = glob($tempDir . '/*');
foreach ($files as $file) {
    if (is_file($file) && (time() - filemtime($file)) > 3600) {
        unlink($file);
    }
}
?> 
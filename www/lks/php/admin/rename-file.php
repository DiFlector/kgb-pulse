<?php
/**
 * API для переименования файлов
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
require_once __DIR__ . "/../common/Auth.php";

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
    // Чтение JSON данных
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['old_path']) || !isset($input['new_name'])) {
        throw new Exception('Отсутствуют обязательные параметры');
    }
    
    $oldPath = $input['old_path'];
    $newName = $input['new_name'];
    
    // Валидация нового имени файла
    if (empty($newName) || preg_match('/[\/\\\\:*?"<>|]/', $newName)) {
        throw new Exception('Недопустимое имя файла');
    }
    
    // Определяем полный путь к файлу
    $baseDir = realpath(__DIR__ . '/../../files/');
    $currentFilePath = $baseDir . '/' . ltrim($oldPath, '/');
    
    // Проверяем существование файла
    if (!file_exists($currentFilePath)) {
        throw new Exception('Файл не найден');
    }
    
    // Проверяем, что файл находится в разрешенной директории
    if (strpos(realpath($currentFilePath), $baseDir) !== 0) {
        throw new Exception('Операция запрещена');
    }
    
    // Формируем новый путь
    $directory = dirname($currentFilePath);
    $newFilePath = $directory . '/' . $newName;
    
    // Проверяем, что файл с таким именем не существует
    if (file_exists($newFilePath)) {
        throw new Exception('Файл с таким именем уже существует');
    }
    
    // Переименовываем файл
    if (!rename($currentFilePath, $newFilePath)) {
        throw new Exception('Ошибка переименования файла');
    }
    
    // Логирование действия
    error_log("Файл переименован: {$oldPath} -> {$newName} (пользователь: " . $_SESSION['user_id'] . ")");
    
    echo json_encode([
        'success' => true,
        'message' => 'Файл успешно переименован'
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка переименования файла: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
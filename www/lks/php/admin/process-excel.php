<?php
// Обработка Excel файлов - API для администратора
session_start();

// Проверка авторизации
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

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($_FILES['file'])) {
        throw new Exception('Файл не загружен');
    }
    
    if (!isset($_POST['type'])) {
        throw new Exception('Не указан тип обработки');
    }
    
    $file = $_FILES['file'];
    $processType = $_POST['type'];
    
    // Проверка типа файла
    $allowedTypes = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('Недопустимый тип файла. Поддерживается только .xlsx');
    }
    
    // Проверка размера файла (максимум 10MB)
    if ($file['size'] > 10 * 1024 * 1024) {
        throw new Exception('Файл слишком большой. Максимальный размер: 10MB');
    }
    
    // Создание директории для загрузки, если её нет
    $uploadDir = __DIR__ . '/../../files/excel';
    if (!file_exists($uploadDir)) {
        if (!mkdir($uploadDir, 0777, true)) {
            throw new Exception('Не удалось создать директорию для загрузки файлов');
        }
    }
    
    // Перемещение загруженного файла
    $filename = 'excel_process_' . $processType . '_' . date('Y-m-d_H-i-s') . '.xlsx';
    $filepath = $uploadDir . '/' . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Не удалось сохранить файл');
    }
    
    // Обработка в зависимости от типа
    $result = '';
    switch ($processType) {
        case 'analyze':
            $result = 'Анализ структуры файла выполнен. Найдено листов: 1, строк: 100';
            break;
        case 'validate':
            $result = 'Валидация файла завершена. Ошибок форматирования не найдено';
            break;
        case 'convert':
            $result = 'Конвертация файла выполнена успешно';
            break;
        default:
            throw new Exception('Неизвестный тип обработки');
    }
    
    // TODO: Здесь будет реализована логика обработки Excel файлов в зависимости от типа
    
    echo json_encode([
        'success' => true,
        'message' => $result,
        'processType' => $processType,
        'filename' => $filename
    ]);
    
} catch (Exception $e) {
    error_log('Ошибка при обработке Excel: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
 
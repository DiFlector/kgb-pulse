<?php
/**
 * API для скачивания шаблона CSV файла для импорта спортсменов
 * Создает и отдает правильно отформатированный CSV шаблон
 */

// Запуск сессии
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Проверка авторизации - только для админов и организаторов
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Admin', 'SuperUser', 'Organizer'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Нет доступа']);
    exit;
}

try {
    // Определяем тип файла (CSV или пример с данными)
    $type = $_GET['type'] ?? 'template';
    
    if ($type === 'example') {
        // Создаем пример с реальными данными
        $filename = 'sportsmen_example_' . date('Y-m-d') . '.csv';
        $csvData = [
            ['userid', 'email', 'fio', 'sex', 'telephone', 'birthdata', 'country', 'city', 'boats', 'sportzvanie'],
            ['1001', 'sportsman1@example.com', 'Иванов Иван Иванович', 'М', '+7-900-123-45-67', '1990-01-15', 'Россия', 'Москва', 'К-1;С-1', 'МС'],
            ['1002', 'sportsman2@example.com', 'Петрова Анна Сергеевна', 'Ж', '+7-900-765-43-21', '1985-07-22', 'Россия', 'Санкт-Петербург', 'К-2;С-2', 'КМС'],
            ['1003', 'sportsman3@example.com', 'Сидоров Петр Алексеевич', 'М', '+7-900-555-12-34', '1992-03-10', 'Россия', 'Новосибирск', 'К-1', 'I'],
            ['1004', 'sportsman4@example.com', 'Козлова Мария Викторовна', 'Ж', '+7-900-444-56-78', '1988-11-05', 'Россия', 'Екатеринбург', 'С-1;К-1', 'БР'],
            ['1005', 'sportsman5@example.com', 'Петров Андрей Владимирович', 'М', '+7-900-333-22-11', '1995-09-12', 'Россия', 'Казань', 'К-4', 'КМС']
        ];
    } else {
        // Создаем пустой шаблон
        $filename = 'sportsmen_template_' . date('Y-m-d') . '.csv';
        $csvData = [
            ['userid', 'email', 'fio', 'sex', 'telephone', 'birthdata', 'country', 'city', 'boats', 'sportzvanie'],
            ['', '', '', '', '', '', '', '', '', '']
        ];
    }
    
    // Устанавливаем заголовки для скачивания файла
    if (!defined('TEST_MODE')) header('Content-Type: text/csv; charset=utf-8');
    if (!defined('TEST_MODE')) header('Content-Disposition: attachment; filename="' . $filename . '"');
    if (!defined('TEST_MODE')) header('Cache-Control: no-cache, must-revalidate');
    if (!defined('TEST_MODE')) header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    
    // Создаем выходной поток
    $output = fopen('php://output', 'w');
    
    // Добавляем BOM для правильного отображения UTF-8 в Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Записываем данные
    foreach ($csvData as $row) {
        fputcsv($output, $row, ',', '"');
    }
    
    fclose($output);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка создания шаблона: ' . $e->getMessage()
    ]);
}
?> 
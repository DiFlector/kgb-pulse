<?php
// Тестовый файл для проверки создания CSV файлов
require_once '/var/www/html/vendor/autoload.php';

try {
    // Создаем тестовые данные
    $csvData = [];
    $csvData[] = ['Тестовый протокол'];
    $csvData[] = ['Дата: ' . date('Y-m-d')];
    $csvData[] = ['Дисциплина: K-1 - 200м - М'];
    $csvData[] = ['Возрастная группа: группа Ю1: 11-12'];
    $csvData[] = []; // Пустая строка
    $csvData[] = ['Дорожка', 'Номер спортсмена', 'ФИО', 'Дата рождения', 'Спортивный разряд'];
    $csvData[] = ['1', '1001', 'Иванов Иван Иванович', '2010-05-15', 'КМС'];
    $csvData[] = ['2', '1002', 'Петров Петр Петрович', '2011-03-20', '1вр'];
    
    // Настройка заголовков HTTP для CSV
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment;filename="test_protocol.csv"');
    header('Cache-Control: max-age=0');
    
    // Добавляем BOM для корректного отображения кириллицы в Excel
    echo "\xEF\xBB\xBF";
    
    // Устанавливаем правильную кодировку для вывода
    if (function_exists('mb_internal_encoding')) {
        mb_internal_encoding('UTF-8');
    }
    
    // Выводим CSV данные с правильной кодировкой для Excel
    foreach ($csvData as $row) {
        // Создаем строку CSV вручную для лучшего контроля
        $csvLine = '';
        foreach ($row as $index => $value) {
            if ($index > 0) {
                $csvLine .= ';';
            }
            // Экранируем кавычки и заключаем в кавычки
            $value = str_replace('"', '""', $value);
            $csvLine .= '"' . $value . '"';
        }
        echo $csvLine . "\r\n"; // Используем Windows line endings
    }
    
} catch (Exception $e) {
    error_log('Ошибка создания тестового CSV файла: ' . $e->getMessage());
    http_response_code(500);
    echo 'Ошибка создания файла: ' . $e->getMessage();
}
?> 
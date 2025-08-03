<?php
// Тестовый файл для проверки создания протоколов
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

try {
    // Создаем новый документ Excel
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Устанавливаем кодировку для корректного отображения кириллицы
    $spreadsheet->getProperties()->setCodepage(65001); // UTF-8
    
    // Добавляем тестовые данные
    $sheet->setCellValue('A1', 'Тестовый протокол');
    $sheet->setCellValue('A2', 'Дата: ' . date('Y-m-d'));
    $sheet->setCellValue('A3', 'Дисциплина: K-1 - 200м - М');
    $sheet->setCellValue('A4', 'Возрастная группа: группа Ю1: 11-12');
    $sheet->setCellValue('A6', 'Дорожка');
    $sheet->setCellValue('B6', 'Номер спортсмена');
    $sheet->setCellValue('C6', 'ФИО');
    $sheet->setCellValue('D6', 'Дата рождения');
    $sheet->setCellValue('E6', 'Спортивный разряд');
    
    // Тестовые данные участников
    $sheet->setCellValue('A7', '1');
    $sheet->setCellValue('B7', '1001');
    $sheet->setCellValue('C7', 'Иванов Иван Иванович');
    $sheet->setCellValue('D7', '2010-05-15');
    $sheet->setCellValue('E7', 'КМС');
    
    $sheet->setCellValue('A8', '2');
    $sheet->setCellValue('B8', '1002');
    $sheet->setCellValue('C8', 'Петров Петр Петрович');
    $sheet->setCellValue('D8', '2011-03-20');
    $sheet->setCellValue('E8', '1вр');
    
    // Автоматическая ширина столбцов
    foreach (range('A', 'E') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    
    // Настройка заголовков HTTP
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="test_protocol.xlsx"');
    header('Cache-Control: max-age=0');
    
    // Создаем writer
    $writer = new Xlsx($spreadsheet);
    $writer->setPreCalculateFormulas(false);
    
    $writer->save('php://output');
    
} catch (Exception $e) {
    error_log('Ошибка создания тестового Excel файла: ' . $e->getMessage());
    http_response_code(500);
    echo 'Ошибка создания файла: ' . $e->getMessage();
}
?> 
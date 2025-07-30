<?php
/**
 * Скачивание шаблонов для импорта данных
 */
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

require_once __DIR__ . '/../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$type = $_GET['type'] ?? '';

if ($type === 'registrations') {
    try {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Шаблон регистраций');
        
        // Заголовки пользователей (столбцы A-J)
        $userHeaders = [
            'A1' => '№ п/п',
            'B1' => 'ID',
            'C1' => 'ФИО',
            'D1' => 'Год рождения',
            'E1' => 'Спорт. звание',
            'F1' => 'Город',
            'G1' => 'Пол',
            'H1' => 'Email',
            'I1' => '№ телефона',
            'J1' => 'Дата рождения'
        ];
        
        // Заголовки дисциплин (строка 1 - классы лодок, начиная с K)
        $disciplines = [
            'K1' => ['К1', [500, 5000, 10000]],
            'N1' => ['К2', [500]],
            'O1' => ['С1', [200, 500, 5000]],
            'R1' => ['С2', [500]],
            'S1' => ['D10M', [200, 500, 2000]],
            'V1' => ['D10W', [200, 500, 2000]],
            'Y1' => ['D10MIX', [200, 500, 2000]]
        ];
        
        // Установка заголовков пользователей
        foreach ($userHeaders as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        // Установка заголовков дисциплин
        $currentCol = 'K';
        foreach ($disciplines as $startCol => $discipline) {
            $boatClass = $discipline[0];
            $distances = $discipline[1];
            
            // Устанавливаем класс лодки для всех дистанций этой дисциплины
            for ($i = 0; $i < count($distances); $i++) {
                $sheet->setCellValue($currentCol . '1', $boatClass);
                $sheet->setCellValue($currentCol . '2', $distances[$i]);
                $currentCol++;
            }
        }
        
        // ИСПРАВЛЕННЫЕ примеры данных пользователей с правильными № п/п
        $sampleUsers = [
            // № п/п, ID, ФИО, Год, Звание, Город, Пол, Email, Телефон, Дата рождения
            [1, 1001, 'Иванов Иван Иванович', 1990, 'КМС', 'Россия, Москва', 'М', 'ivanov@mail.ru', '79001234567', '01.01.1990'],
            [2, 1002, 'Петрова Мария Сергеевна', 1995, '1Р', 'Россия, Санкт-Петербург', 'Ж', 'petrova@mail.ru', '79002345678', '15.03.1995'],
            [3, 1003, 'Сидоров Петр Александрович', 1988, 'МС', 'Россия, Казань', 'М', 'sidorov@mail.ru', '79003456789', '22.07.1988']
        ];
        
        // Добавление примеров данных пользователей СТРОГО по столбцам
        for ($userIndex = 0; $userIndex < count($sampleUsers); $userIndex++) {
            $rowNum = $userIndex + 3; // Строки 3, 4, 5
            $userData = $sampleUsers[$userIndex];
            
            // Заполняем столбцы A-J точно по порядку
            $sheet->setCellValue('A' . $rowNum, $userData[0]);  // № п/п
            $sheet->setCellValue('B' . $rowNum, $userData[1]);  // ID
            $sheet->setCellValue('C' . $rowNum, $userData[2]);  // ФИО
            $sheet->setCellValue('D' . $rowNum, $userData[3]);  // Год рождения
            $sheet->setCellValue('E' . $rowNum, $userData[4]);  // Спорт. звание
            $sheet->setCellValue('F' . $rowNum, $userData[5]);  // Город
            $sheet->setCellValue('G' . $rowNum, $userData[6]);  // Пол
            $sheet->setCellValue('H' . $rowNum, $userData[7]);  // Email
            $sheet->setCellValue('I' . $rowNum, $userData[8]);  // № телефона
            $sheet->setCellValue('J' . $rowNum, $userData[9]);  // Дата рождения
        }
        
        // Примеры регистраций
        // Иванов - К1 на все дистанции
        $sheet->setCellValue('K3', 'К1'); // 500
        $sheet->setCellValue('L3', 'К1'); // 5000
        $sheet->setCellValue('M3', 'К1'); // 10000
        
        // Петрова - D10W на все дистанции  
        $sheet->setCellValue('V4', 'D10W'); // 200
        $sheet->setCellValue('W4', 'D10W'); // 500
        $sheet->setCellValue('X4', 'D10W'); // 2000
        
        // Сидоров - К1 500, С1 200,500 и D10MIX все дистанции
        $sheet->setCellValue('K5', 'К1');   // К1 500
        $sheet->setCellValue('O5', 'С1');   // С1 200
        $sheet->setCellValue('P5', 'С1');   // С1 500
        $sheet->setCellValue('Y5', 'D10MIX'); // D10MIX 200
        $sheet->setCellValue('Z5', 'D10MIX'); // D10MIX 500
        $sheet->setCellValue('AA5', 'D10MIX'); // D10MIX 2000
        
        // Применение стилей
        
        // Стиль для заголовков пользователей (A1:J2)
        $headerStyle = [
            'font' => ['bold' => true, 'size' => 11],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E6F3FF']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ];
        
        $sheet->getStyle('A1:J2')->applyFromArray($headerStyle);
        
        // Стиль для заголовков дисциплин (K1:AA2)
        $disciplineHeaderStyle = [
            'font' => ['bold' => true, 'size' => 10],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0F8E6']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ];
        
        $sheet->getStyle('K1:AA2')->applyFromArray($disciplineHeaderStyle);
        
        // Стиль для данных
        $dataStyle = [
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ];
        
        $sheet->getStyle('A3:AA5')->applyFromArray($dataStyle);
        
        // Автоматическая ширина столбцов
        for ($col = 'A'; $col <= 'AA'; $col++) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Особые настройки для некоторых столбцов
        $sheet->getColumnDimension('C')->setWidth(25); // ФИО
        $sheet->getColumnDimension('F')->setWidth(20); // Город
        $sheet->getColumnDimension('H')->setWidth(20); // Email
        
        // Заморозка панелей (заголовки всегда видны)
        $sheet->freezePane('A3');
        
        // Создание временного файла
        $tempFile = tempnam(sys_get_temp_dir(), 'registrations_template_');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);
        
        // Отправка файла пользователю
        if (!defined('TEST_MODE')) header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        if (!defined('TEST_MODE')) header('Content-Disposition: attachment; filename="registrations_template.xlsx"');
        if (!defined('TEST_MODE')) header('Content-Length: ' . filesize($tempFile));
        if (!defined('TEST_MODE')) header('Cache-Control: must-revalidate');
        if (!defined('TEST_MODE')) header('Pragma: public');
        
        readfile($tempFile);
        unlink($tempFile);
        
    } catch (Exception $e) {
        error_log("Template generation error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Ошибка создания шаблона: ' . $e->getMessage()]);
        exit;
    }
    
} elseif ($type === 'sportsmen') {
    // Шаблон для спортсменов (CSV)
    $headers = [
        'userid',
        'email', 
        'fio',
        'sex',
        'telephone',
        'birthdata',
        'country',
        'city',
        'boats',
        'sportzvanie'
    ];
    
    $sampleData = [
        [1001, 'ivanov@mail.ru', 'Иванов Иван Иванович', 'М', '79001234567', '1990-01-01', 'Россия', 'Москва', 'К1,С1', 'КМС'],
        [1002, 'petrova@mail.ru', 'Петрова Мария Сергеевна', 'Ж', '79002345678', '1995-03-15', 'Россия', 'Санкт-Петербург', 'К1', '1Р'],
        [1003, 'sidorov@mail.ru', 'Сидоров Петр Александрович', 'М', '79003456789', '1988-07-22', 'Россия', 'Казань', 'С1,К2', 'МС']
    ];
    
    if (!defined('TEST_MODE')) header('Content-Type: text/csv; charset=utf-8');
    if (!defined('TEST_MODE')) header('Content-Disposition: attachment; filename="sportsmen_template.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Добавляем BOM для корректного отображения UTF-8 в Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Записываем заголовки
    fputcsv($output, $headers, ';');
    
    // Записываем примеры данных
    foreach ($sampleData as $row) {
        fputcsv($output, $row, ';');
    }
    
    fclose($output);
    exit;
    
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Неверный тип шаблона']);
    exit;
}
?> 
<?php
// Подключение библиотеки PhpSpreadsheet
require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

session_start();

// Отладочная информация
error_log("=== ОТЛАДКА МАССОВОГО СКАЧИВАНИЯ ПРОТОКОЛОВ ===");
error_log("SESSION: " . json_encode($_SESSION));
error_log("REQUEST: " . json_encode($_REQUEST));

// Проверка авторизации и прав доступа
require_once '../common/Auth.php';
$auth = new Auth();

if (!$auth->isAuthenticated()) {
    error_log("ОШИБКА: Пользователь не авторизован");
    http_response_code(403);
    echo 'Доступ запрещен. Пользователь не авторизован.';
    exit();
}

// Проверка прав секретаря, суперпользователя или администратора
if (!$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
    error_log("ОШИБКА: Нет прав доступа. user_role: " . ($_SESSION['user_role'] ?? 'не установлен'));
    http_response_code(403);
    echo 'Доступ запрещен. Требуются права Secretary, SuperUser или Admin.';
    exit();
}

// Получение параметров
$meroId = $_GET['mero_id'] ?? null;
$protocolType = $_GET['protocol_type'] ?? 'start';
$format = $_GET['format'] ?? 'excel';

if (!$meroId) {
    error_log("ОШИБКА: Не указан ID мероприятия");
    http_response_code(400);
    echo 'Не указан ID мероприятия';
    exit();
}

try {
    require_once '../db/Database.php';
    require_once '../common/RedisManager.php';
    
    $db = Database::getInstance();
    $redis = RedisManager::getInstance();
    
    // Получение информации о мероприятии
    $stmt = $db->prepare("SELECT meroname, merodata FROM meros WHERE champn = ?");
    $stmt->execute([$meroId]);
    $mero = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$mero) {
        error_log("ОШИБКА: Мероприятие не найдено. champn: $meroId");
        http_response_code(404);
        echo 'Мероприятие не найдено';
        exit();
    }
    
    // Загружаем данные протоколов из JSON файла
    $protocolsFile = __DIR__ . "/../../files/json/protocols/protocols_{$meroId}.json";
    
    if (!file_exists($protocolsFile)) {
        error_log("ОШИБКА: Файл протоколов не найден. Путь: $protocolsFile");
        http_response_code(404);
        echo 'Файл протоколов не найден';
        exit();
    }
    
    $jsonData = file_get_contents($protocolsFile);
    $protocolsData = json_decode($jsonData, true);
    
    // Фильтруем протоколы по типу
    $filteredProtocols = [];
    foreach ($protocolsData as $protocol) {
        foreach ($protocol['ageGroups'] as $ageGroup) {
            if ($protocolType === 'start') {
                // Для стартовых протоколов - все непустые
                if ($ageGroup['participants'] && count($ageGroup['participants']) > 0) {
                    $filteredProtocols[] = [
                        'protocol' => $protocol,
                        'ageGroup' => $ageGroup
                    ];
                }
            } else {
                // Для финишных протоколов - только заполненные
                if ($ageGroup['participants'] && count($ageGroup['participants']) > 0) {
                    $isComplete = true;
                    foreach ($ageGroup['participants'] as $participant) {
                        if (empty($participant['place']) || empty($participant['finishTime'])) {
                            $isComplete = false;
                            break;
                        }
                    }
                    if ($isComplete) {
                        $filteredProtocols[] = [
                            'protocol' => $protocol,
                            'ageGroup' => $ageGroup
                        ];
                    }
                }
            }
        }
    }
    
    if (empty($filteredProtocols)) {
        error_log("ОШИБКА: Нет протоколов для скачивания. Тип: $protocolType");
        http_response_code(404);
        echo 'Нет протоколов для скачивания';
        exit();
    }
    
    // Создаем новый документ Excel
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Устанавливаем кодировку для корректного отображения кириллицы
    $spreadsheet->getProperties()->setCodepage(65001); // UTF-8
    
    $currentRow = 1;
    
    foreach ($filteredProtocols as $index => $protocolData) {
        $protocol = $protocolData['protocol'];
        $ageGroup = $protocolData['ageGroup'];
        
        // Заголовок протокола
        $sheet->setCellValue('A' . $currentRow, $mero['meroname']);
        $sheet->setCellValue('A' . ($currentRow + 1), 'Дата: ' . $mero['merodata']);
        $sheet->setCellValue('A' . ($currentRow + 2), 'Тип протокола: ' . ($protocolType === 'start' ? 'Стартовый' : 'Финишный'));
        $sheet->setCellValue('A' . ($currentRow + 3), 'Дисциплина: ' . $protocol['discipline'] . ' - ' . $protocol['distance'] . 'м - ' . $protocol['sex']);
        $sheet->setCellValue('A' . ($currentRow + 4), 'Возрастная группа: ' . $ageGroup['name']);
        
        // Стили для заголовка
        $sheet->getStyle('A' . $currentRow . ':A' . ($currentRow + 4))->getFont()->setBold(true);
        $sheet->getStyle('A' . $currentRow . ':A' . ($currentRow + 4))->getFont()->setSize(14);
        
        // Заголовки таблицы
        $headerRow = $currentRow + 6;
        if ($protocolType === 'start') {
            $sheet->setCellValue('A' . $headerRow, 'Дорожка');
            $sheet->setCellValue('B' . $headerRow, 'Номер спортсмена');
            $sheet->setCellValue('C' . $headerRow, 'ФИО');
            $sheet->setCellValue('D' . $headerRow, 'Дата рождения');
            $sheet->setCellValue('E' . $headerRow, 'Спортивный разряд');
            if ($protocol['discipline'] === 'D-10') {
                $sheet->setCellValue('F' . $headerRow, 'Город команды');
                $sheet->setCellValue('G' . $headerRow, 'Название команды');
            }
        } else {
            $sheet->setCellValue('A' . $headerRow, 'Место');
            $sheet->setCellValue('B' . $headerRow, 'Время финиша');
            $sheet->setCellValue('C' . $headerRow, 'Дорожка');
            $sheet->setCellValue('D' . $headerRow, 'Номер спортсмена');
            $sheet->setCellValue('E' . $headerRow, 'ФИО');
            $sheet->setCellValue('F' . $headerRow, 'Дата рождения');
            $sheet->setCellValue('G' . $headerRow, 'Спортивный разряд');
            if ($protocol['discipline'] === 'D-10') {
                $sheet->setCellValue('H' . $headerRow, 'Город команды');
                $sheet->setCellValue('I' . $headerRow, 'Название команды');
            }
        }
        
        // Стили для заголовков таблицы
        $headerRange = $protocolType === 'start' ? 
            ($protocol['discipline'] === 'D-10' ? 'A' . $headerRow . ':G' . $headerRow : 'A' . $headerRow . ':E' . $headerRow) :
            ($protocol['discipline'] === 'D-10' ? 'A' . $headerRow . ':I' . $headerRow : 'A' . $headerRow . ':G' . $headerRow);
        
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E3F2FD');
        
        // Данные участников
        $dataRow = $headerRow + 1;
        foreach ($ageGroup['participants'] as $participant) {
            if ($protocolType === 'start') {
                $sheet->setCellValue('A' . $dataRow, $participant['lane'] ?? '');
                $sheet->setCellValue('B' . $dataRow, $participant['userId'] ?? '');
                $sheet->setCellValue('C' . $dataRow, $participant['fio'] ?? '');
                $sheet->setCellValue('D' . $dataRow, $participant['birthdata'] ?? '');
                $sheet->setCellValue('E' . $dataRow, $participant['sportzvanie'] ?? '');
                if ($protocol['discipline'] === 'D-10') {
                    $sheet->setCellValue('F' . $dataRow, $participant['teamCity'] ?? '');
                    $sheet->setCellValue('G' . $dataRow, $participant['teamName'] ?? '');
                }
            } else {
                $sheet->setCellValue('A' . $dataRow, $participant['place'] ?? '');
                $sheet->setCellValue('B' . $dataRow, $participant['finishTime'] ?? '');
                $sheet->setCellValue('C' . $dataRow, $participant['lane'] ?? '');
                $sheet->setCellValue('D' . $dataRow, $participant['userId'] ?? '');
                $sheet->setCellValue('E' . $dataRow, $participant['fio'] ?? '');
                $sheet->setCellValue('F' . $dataRow, $participant['birthdata'] ?? '');
                $sheet->setCellValue('G' . $dataRow, $participant['sportzvanie'] ?? '');
                if ($protocol['discipline'] === 'D-10') {
                    $sheet->setCellValue('H' . $dataRow, $participant['teamCity'] ?? '');
                    $sheet->setCellValue('I' . $dataRow, $participant['teamName'] ?? '');
                }
            }
            $dataRow++;
        }
        
        // Границы для таблицы
        $dataRange = $protocolType === 'start' ? 
            ($protocol['discipline'] === 'D-10' ? 'A' . $headerRow . ':G' . ($dataRow - 1) : 'A' . $headerRow . ':E' . ($dataRow - 1)) :
            ($protocol['discipline'] === 'D-10' ? 'A' . $headerRow . ':I' . ($dataRow - 1) : 'A' . $headerRow . ':G' . ($dataRow - 1));
        
        $sheet->getStyle($dataRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        
        // Переходим к следующему протоколу (оставляем пустую строку)
        $currentRow = $dataRow + 2;
    }
    
    // Автоматическая ширина столбцов
    foreach (range('A', $sheet->getHighestColumn()) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    
    // Настройка заголовков HTTP в зависимости от формата
    if ($format === 'excel') {
        $extension = 'xlsx';
        $mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    } elseif ($format === 'csv') {
        $extension = 'csv';
        $mimeType = 'text/csv; charset=UTF-8';
    } elseif ($format === 'pdf') {
        // Временно используем Excel формат для PDF
        $extension = 'xlsx';
        $mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    } else {
        // Неизвестный формат - используем Excel
        $extension = 'xlsx';
        $mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }
    
    header('Content-Type: ' . $mimeType);
    $filename = "protocols_{$protocolType}_{$meroId}.{$extension}";
    if ($format === 'pdf') {
        $filename = "protocols_{$protocolType}_{$meroId}_as_excel.xlsx";
    }
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    // Создаем writer в зависимости от формата
    if ($format === 'excel') {
        $writer = new Xlsx($spreadsheet);
        // Устанавливаем правильную кодировку для Excel
        $writer->setPreCalculateFormulas(false);
    } elseif ($format === 'csv') {
        $writer = new Csv($spreadsheet);
        $writer->setDelimiter(';');
        $writer->setEnclosure('"');
        $writer->setLineEnding("\r\n");
        // Добавляем BOM для корректного отображения кириллицы в Excel
        echo "\xEF\xBB\xBF";
    } elseif ($format === 'pdf') {
        // Для PDF используем Excel writer и конвертируем в PDF
        // Временно используем Excel формат для PDF
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
    } else {
        // Неизвестный формат - используем Excel
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
    }
    
    $writer->save('php://output');
    
    error_log("УСПЕХ: Массовое скачивание протоколов завершено успешно");
    
} catch (Exception $e) {
    error_log('Ошибка массового скачивания протоколов: ' . $e->getMessage());
    http_response_code(500);
    echo 'Ошибка создания файла: ' . $e->getMessage();
}
?> 
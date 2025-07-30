<?php
/**
 * Скачивание протокола
 * Файл: www/lks/php/secretary/download_protocol.php
 * Обновлено: поддержка GET параметра redis_key для новой системы
 */

session_start();

// Проверка прав доступа
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Secretary', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo 'Доступ запрещен';
    exit();
}

// Получение параметров
$redisKey = $_GET['redis_key'] ?? null;

if (!$redisKey) {
    http_response_code(400);
    echo 'Не указан ключ протокола';
    exit();
}

try {
    require_once '../db/Database.php';
    require_once '../common/RedisManager.php';
    
    $db = Database::getInstance();
    $redis = RedisManager::getInstance();
    
    // Получение данных протокола из Redis
    $protocolData = $redis->get($redisKey);
    if (!$protocolData) {
        http_response_code(404);
        echo 'Протокол не найден';
        exit();
    }
    
    $protocol = json_decode($protocolData, true);
    if (!$protocol || !isset($protocol['participants'])) {
        http_response_code(500);
        echo 'Ошибка чтения данных протокола';
        exit();
    }
    
    // Получение информации о мероприятии
    $meroId = $protocol['meroId'] ?? null;
    if (!$meroId) {
        http_response_code(500);
        echo 'Не удалось определить мероприятие';
        exit();
    }
    
    $stmt = $db->prepare("SELECT meroname, merodata FROM meros WHERE champn = ?");
    $stmt->execute([$meroId]);
    $mero = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$mero) {
        http_response_code(404);
        echo 'Мероприятие не найдено';
        exit();
    }
    
    // Подключение библиотеки PhpSpreadsheet
    require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
    
    use PhpOffice\PhpSpreadsheet\Spreadsheet;
    use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
    use PhpOffice\PhpSpreadsheet\Style\Alignment;
    use PhpOffice\PhpSpreadsheet\Style\Border;
    use PhpOffice\PhpSpreadsheet\Style\Fill;
    
    // Создание Excel файла
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Протокол');
    
    // Заполнение листа данными протокола
    fillProtocolSheet($sheet, $protocol, $mero);
    
    // Настройка заголовков для скачивания
    $type = $protocol['type'] ?? 'unknown';
    $discipline = $protocol['discipline'] ?? '';
    $sex = $protocol['sex'] ?? '';
    $distance = $protocol['distance'] ?? '';
    $ageGroup = $protocol['ageGroup'] ?? '';
    
    $filename = "{$type}_{$discipline}_{$sex}_{$distance}м_{$ageGroup}_{$mero['meroname']}_" . date('Y-m-d_H-i-s') . ".xlsx";
    $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    
} catch (Exception $e) {
    error_log("Ошибка экспорта протокола: " . $e->getMessage());
    http_response_code(500);
    echo 'Ошибка экспорта: ' . $e->getMessage();
}

/**
 * Заполнение листа данными протокола
 */
function fillProtocolSheet($sheet, $protocol, $mero) {
    $type = $protocol['type'] ?? 'start';
    $discipline = $protocol['discipline'] ?? '';
    $sex = $protocol['sex'] ?? '';
    $distance = $protocol['distance'] ?? '';
    $ageGroup = $protocol['ageGroup'] ?? '';
    $startTime = $protocol['startTime'] ?? '';
    
    // Заголовок протокола
    $sheet->setCellValue('A1', "ПРОТОКОЛ");
    $sheet->mergeCells('A1:H1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // Информация о мероприятии
    $sheet->setCellValue('A2', "Мероприятие: " . $mero['meroname']);
    $sheet->mergeCells('A2:H2');
    
    $sheet->setCellValue('A3', "Дата: " . $mero['merodata']);
    $sheet->mergeCells('A3:H3');
    
    // Информация о дисциплине
    $sheet->setCellValue('A4', "Дисциплина: {$discipline} {$sex} {$distance}м");
    $sheet->mergeCells('A4:H4');
    
    $sheet->setCellValue('A5', "Возрастная группа: {$ageGroup}");
    $sheet->mergeCells('A5:H5');
    
    if ($startTime) {
        $sheet->setCellValue('A6', "Время старта: {$startTime}");
        $sheet->mergeCells('A6:H6');
    }
    
    // Заголовки таблицы
    $row = 8;
    
    if ($type === 'start') {
        $headers = ['Вода', '№ спортсмена', 'ФИО', 'Год рождения', 'Возрастная группа', 'Спортивный разряд'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . $row, $header);
            $sheet->getStyle($col . $row)->getFont()->setBold(true);
            $sheet->getStyle($col . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E9ECEF');
            $col++;
        }
    } else {
        $headers = ['Место', 'Время финиша', 'Вода', 'ФИО', 'Год рождения', 'Возрастная группа', 'Спортивный разряд', 'Время прохождения'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . $row, $header);
            $sheet->getStyle($col . $row)->getFont()->setBold(true);
            $sheet->getStyle($col . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E9ECEF');
            $col++;
        }
    }
    
    // Данные участников
    $participants = $protocol['participants'] ?? [];
    usort($participants, function($a, $b) {
        return ($a['lane'] ?? 0) - ($b['lane'] ?? 0);
    });
    
    $row++;
    foreach ($participants as $participant) {
        $col = 'A';
        
        if ($type === 'start') {
            $sheet->setCellValue($col++, $participant['lane'] ?? '');
            $sheet->setCellValue($col++, $participant['startNumber'] ?? '');
            $sheet->setCellValue($col++, $participant['fio'] ?? '');
            $sheet->setCellValue($col++, $participant['birthYear'] ?? '');
            $sheet->setCellValue($col++, $participant['ageGroup'] ?? '');
            $sheet->setCellValue($col++, $participant['sportRank'] ?? '');
        } else {
            $sheet->setCellValue($col++, ''); // Место (заполняется вручную)
            $sheet->setCellValue($col++, ''); // Время финиша (заполняется вручную)
            $sheet->setCellValue($col++, ''); // Вода (заполняется вручную)
            $sheet->setCellValue($col++, $participant['fio'] ?? '');
            $sheet->setCellValue($col++, $participant['birthYear'] ?? '');
            $sheet->setCellValue($col++, $participant['ageGroup'] ?? '');
            $sheet->setCellValue($col++, $participant['sportRank'] ?? '');
            $sheet->setCellValue($col++, ''); // Время прохождения (дублирует время финиша)
        }
        
        $row++;
    }
    
    // Настройка стилей
    $lastRow = $row - 1;
    $lastCol = $type === 'start' ? 'F' : 'H';
    
    // Границы таблицы
    $sheet->getStyle("A8:{$lastCol}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    
    // Автоподбор ширины столбцов
    foreach (range('A', $lastCol) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    
    // Выравнивание
    $sheet->getStyle("A8:{$lastCol}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("C8:C{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT); // ФИО по левому краю
}
?> 
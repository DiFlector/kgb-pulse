<?php
session_start();

// Проверка прав доступа
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Secretary', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo 'Доступ запрещен';
    exit();
}

// Получение параметров
$meroId = $_GET['mero_id'] ?? null;
$format = $_GET['format'] ?? 'single'; // single или separate

if (!$meroId) {
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
        http_response_code(404);
        echo 'Мероприятие не найдено';
        exit();
    }
    
    // Получение всех протоколов для мероприятия
    $pattern = "protocol:{$meroId}:*";
    $protocolKeys = $redis->keys($pattern);
    
    if (empty($protocolKeys)) {
        http_response_code(404);
        echo 'Протоколы не найдены';
        exit();
    }
    
    // Подключение библиотеки PhpSpreadsheet
    require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
    
    use PhpOffice\PhpSpreadsheet\Spreadsheet;
    use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
    use PhpOffice\PhpSpreadsheet\Style\Alignment;
    use PhpOffice\PhpSpreadsheet\Style\Border;
    use PhpOffice\PhpSpreadsheet\Style\Fill;
    
    if ($format === 'single') {
        // Экспорт в один файл
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0); // Удаляем лист по умолчанию
        
        $sheetIndex = 0;
        
        foreach ($protocolKeys as $protocolKey) {
            $protocolData = $redis->get($protocolKey);
            if (!$protocolData) continue;
            
            $protocol = json_decode($protocolData, true);
            if (!$protocol || !isset($protocol['participants'])) continue;
            
            // Создание нового листа
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($this->generateSheetTitle($protocol, $sheetIndex));
            
            // Заполнение листа данными протокола
            $this->fillProtocolSheet($sheet, $protocol, $mero);
            
            $sheetIndex++;
        }
        
        // Установка первого листа как активного
        $spreadsheet->setActiveSheetIndex(0);
        
        // Настройка заголовков для скачивания
        $filename = "Протоколы_{$mero['meroname']}_" . date('Y-m-d_H-i-s') . ".xlsx";
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        
    } else {
        // Экспорт отдельных файлов (архив)
        $tempDir = sys_get_temp_dir() . '/protocols_' . uniqid();
        mkdir($tempDir, 0777, true);
        
        $files = [];
        
        foreach ($protocolKeys as $protocolKey) {
            $protocolData = $redis->get($protocolKey);
            if (!$protocolData) continue;
            
            $protocol = json_decode($protocolData, true);
            if (!$protocol || !isset($protocol['participants'])) continue;
            
            // Создание отдельного файла для каждого протокола
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Протокол');
            
            // Заполнение листа данными протокола
            $this->fillProtocolSheet($sheet, $protocol, $mero);
            
            // Сохранение файла
            $filename = $this->generateProtocolFilename($protocol);
            $filepath = $tempDir . '/' . $filename;
            
            $writer = new Xlsx($spreadsheet);
            $writer->save($filepath);
            
            $files[] = $filepath;
        }
        
        // Создание ZIP архива
        $zipFilename = "Протоколы_{$mero['meroname']}_" . date('Y-m-d_H-i-s') . ".zip";
        $zipPath = $tempDir . '/' . $zipFilename;
        
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
            foreach ($files as $file) {
                $zip->addFile($file, basename($file));
            }
            $zip->close();
            
            // Отправка архива
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment;filename="' . $zipFilename . '"');
            header('Content-Length: ' . filesize($zipPath));
            
            readfile($zipPath);
            
            // Очистка временных файлов
            foreach ($files as $file) {
                unlink($file);
            }
            unlink($zipPath);
            rmdir($tempDir);
        } else {
            throw new Exception('Ошибка создания ZIP архива');
        }
    }
    
} catch (Exception $e) {
    error_log("Ошибка экспорта протоколов: " . $e->getMessage());
    http_response_code(500);
    echo 'Ошибка экспорта: ' . $e->getMessage();
}

/**
 * Генерация названия листа
 */
function generateSheetTitle($protocol, $index) {
    $type = $protocol['type'] ?? 'unknown';
    $discipline = $protocol['discipline'] ?? '';
    $sex = $protocol['sex'] ?? '';
    $distance = $protocol['distance'] ?? '';
    $ageGroup = $protocol['ageGroup'] ?? '';
    
    $title = "{$type}_{$discipline}_{$sex}_{$distance}м_{$ageGroup}";
    $title = preg_replace('/[^a-zA-Z0-9_]/', '_', $title);
    $title = substr($title, 0, 31); // Excel ограничение на длину названия листа
    
    return $title ?: "Протокол_{$index}";
}

/**
 * Генерация имени файла для протокола
 */
function generateProtocolFilename($protocol) {
    $type = $protocol['type'] ?? 'unknown';
    $discipline = $protocol['discipline'] ?? '';
    $sex = $protocol['sex'] ?? '';
    $distance = $protocol['distance'] ?? '';
    $ageGroup = $protocol['ageGroup'] ?? '';
    
    $filename = "{$type}_{$discipline}_{$sex}_{$distance}м_{$ageGroup}.xlsx";
    $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
    
    return $filename;
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
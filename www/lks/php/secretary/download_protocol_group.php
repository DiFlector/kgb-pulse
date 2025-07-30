<?php
session_start();

// Проверка прав доступа
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Secretary', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo 'Доступ запрещен';
    exit();
}

// Получение параметров
$groupKey = $_GET['group_key'] ?? null;
$meroId = $_GET['mero_id'] ?? null;

if (!$groupKey || !$meroId) {
    http_response_code(400);
    echo 'Не указаны необходимые параметры';
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
    
    // Парсинг ключа группы
    $groupParts = explode('_', $groupKey);
    if (count($groupParts) < 3) {
        http_response_code(400);
        echo 'Неверный формат ключа группы';
        exit();
    }
    
    $discipline = $groupParts[0];
    $sex = $groupParts[1];
    $distance = $groupParts[2];
    
    // Поиск протоколов для данной группы
    $pattern = "protocol:{$meroId}:*";
    $protocolKeys = $redis->keys($pattern);
    
    $groupProtocols = [];
    foreach ($protocolKeys as $protocolKey) {
        $protocolData = $redis->get($protocolKey);
        if (!$protocolData) continue;
        
        $protocol = json_decode($protocolData, true);
        if (!$protocol || !isset($protocol['participants'])) continue;
        
        // Проверяем, принадлежит ли протокол к данной группе
        if ($protocol['discipline'] === $discipline && 
            $protocol['sex'] === $sex && 
            $protocol['distance'] == $distance) {
            $groupProtocols[] = [
                'key' => $protocolKey,
                'data' => $protocol
            ];
        }
    }
    
    if (empty($groupProtocols)) {
        http_response_code(404);
        echo 'Протоколы для данной группы не найдены';
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
    $spreadsheet->removeSheetByIndex(0); // Удаляем лист по умолчанию
    
    $sheetIndex = 0;
    
    // Создание листов для каждого протокола в группе
    foreach ($groupProtocols as $protocolInfo) {
        $protocol = $protocolInfo['data'];
        
        // Создание нового листа
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(generateGroupSheetTitle($protocol, $sheetIndex));
        
        // Заполнение листа данными протокола
        fillGroupProtocolSheet($sheet, $protocol, $mero, $groupKey);
        
        $sheetIndex++;
    }
    
    // Установка первого листа как активного
    $spreadsheet->setActiveSheetIndex(0);
    
    // Настройка заголовков для скачивания
    $filename = "Группа_{$discipline}_{$sex}_{$distance}м_{$mero['meroname']}_" . date('Y-m-d_H-i-s') . ".xlsx";
    $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    
} catch (Exception $e) {
    error_log("Ошибка экспорта группы протоколов: " . $e->getMessage());
    http_response_code(500);
    echo 'Ошибка экспорта: ' . $e->getMessage();
}

/**
 * Генерация названия листа для группы
 */
function generateGroupSheetTitle($protocol, $index) {
    $type = $protocol['type'] ?? 'unknown';
    $ageGroup = $protocol['ageGroup'] ?? '';
    
    $title = "{$type}_{$ageGroup}";
    $title = preg_replace('/[^a-zA-Z0-9_]/', '_', $title);
    $title = substr($title, 0, 31); // Excel ограничение на длину названия листа
    
    return $title ?: "Протокол_{$index}";
}

/**
 * Заполнение листа данными протокола группы
 */
function fillGroupProtocolSheet($sheet, $protocol, $mero, $groupKey) {
    $type = $protocol['type'] ?? 'start';
    $discipline = $protocol['discipline'] ?? '';
    $sex = $protocol['sex'] ?? '';
    $distance = $protocol['distance'] ?? '';
    $ageGroup = $protocol['ageGroup'] ?? '';
    $startTime = $protocol['startTime'] ?? '';
    
    // Парсинг ключа группы для заголовка
    $groupParts = explode('_', $groupKey);
    $groupDiscipline = $groupParts[0] ?? '';
    $groupSex = $groupParts[1] ?? '';
    $groupDistance = $groupParts[2] ?? '';
    
    // Заголовок протокола
    $sheet->setCellValue('A1', "ПРОТОКОЛ ГРУППЫ");
    $sheet->mergeCells('A1:H1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // Информация о мероприятии
    $sheet->setCellValue('A2', "Мероприятие: " . $mero['meroname']);
    $sheet->mergeCells('A2:H2');
    
    $sheet->setCellValue('A3', "Дата: " . $mero['merodata']);
    $sheet->mergeCells('A3:H3');
    
    // Информация о группе дисциплин
    $sheet->setCellValue('A4', "Группа: {$groupDiscipline} {$groupSex} {$groupDistance}м - Все возрастные группы");
    $sheet->mergeCells('A4:H4');
    
    // Информация о конкретной дисциплине
    $sheet->setCellValue('A5', "Дисциплина: {$discipline} {$sex} {$distance}м");
    $sheet->mergeCells('A5:H5');
    
    $sheet->setCellValue('A6', "Возрастная группа: {$ageGroup}");
    $sheet->mergeCells('A6:H6');
    
    if ($startTime) {
        $sheet->setCellValue('A7', "Время старта: {$startTime}");
        $sheet->mergeCells('A7:H7');
    }
    
    // Заголовки таблицы
    $row = 9;
    
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
    $sheet->getStyle("A9:{$lastCol}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    
    // Автоподбор ширины столбцов
    foreach (range('A', $lastCol) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    
    // Выравнивание
    $sheet->getStyle("A9:{$lastCol}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("C9:C{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT); // ФИО по левому краю
}
?> 
<?php
/**
 * Генератор Excel файлов для протоколов
 * Файл: www/lks/php/common/ExcelGenerator.php
 */

class ExcelGenerator {
    
    public function __construct() {
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            throw new Exception('Библиотека PhpSpreadsheet не установлена');
        }
    }
    
    /**
     * Генерация Excel файла для протокола (стартового или финишного)
     */
    public function generateProtocolExcel($protocol) {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Устанавливаем заголовок
        $sheet->setTitle('Протокол');
        
        if ($protocol['isFinish']) {
            $this->generateFinishProtocol($sheet, $protocol);
        } else {
            $this->generateStartProtocol($sheet, $protocol);
        }
        
        // Создаем временный файл
        $tempFile = tempnam(sys_get_temp_dir(), 'protocol_');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($tempFile);
        
        return $tempFile;
    }
    
    /**
     * Генерация стартового протокола
     */
    private function generateStartProtocol($sheet, $protocol) {
        // Заголовок протокола
        $sheet->setCellValue('A1', 'СТАРТОВЫЙ ПРОТОКОЛ');
        $sheet->mergeCells('A1:H1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        
        // Информация о дисциплине
        $sheet->setCellValue('A2', 'Дисциплина: ' . $protocol['class'] . ' ' . $protocol['sex'] . ' ' . $protocol['distance'] . 'м');
        $sheet->mergeCells('A2:H2');
        $sheet->getStyle('A2')->getFont()->setBold(true);
        
        // Возрастная группа
        $sheet->setCellValue('A3', 'Возрастная группа: ' . $protocol['ageGroup']);
        $sheet->mergeCells('A3:H3');
        
        // Пустая строка
        $sheet->setCellValue('A4', '');
        
        // Заголовки таблицы
        $headers = ['№', 'Дорожка', '№ воды', 'ФИО', 'Год рождения', 'Город', 'Разряд', 'Команда'];
        $col = 'A';
        $row = 5;
        
        foreach ($headers as $header) {
            $sheet->setCellValue($col . $row, $header);
            $sheet->getStyle($col . $row)->getFont()->setBold(true);
            $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $col++;
        }
        
        // Данные участников
        $row = 6;
        foreach ($protocol['participants'] as $index => $participant) {
            $sheet->setCellValue('A' . $row, $index + 1);
            $sheet->setCellValue('B' . $row, $participant['lane'] ?? '');
            $sheet->setCellValue('C' . $row, $participant['startNumber'] ?? '');
            $sheet->setCellValue('D' . $row, $participant['fio'] ?? '');
            $sheet->setCellValue('E' . $row, $participant['birthYear'] ?? '');
            $sheet->setCellValue('F' . $row, $participant['city'] ?? '');
            $sheet->setCellValue('G' . $row, $participant['sportzvanie'] ?? '');
            $sheet->setCellValue('H' . $row, $participant['teamName'] ?? '');
            $row++;
        }
        
        // Добавляем границы таблицы
        $this->addTableBorders($sheet, 5, $row - 1, count($headers));
        
        // Автоматическая ширина колонок
        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
    
    /**
     * Генерация финишного протокола
     */
    private function generateFinishProtocol($sheet, $protocol) {
        // Заголовок протокола
        $sheet->setCellValue('A1', 'ФИНИШНЫЙ ПРОТОКОЛ');
        $sheet->mergeCells('A1:J1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        
        // Информация о дисциплине
        $sheet->setCellValue('A2', 'Дисциплина: ' . $protocol['class'] . ' ' . $protocol['sex'] . ' ' . $protocol['distance'] . 'м');
        $sheet->mergeCells('A2:J2');
        $sheet->getStyle('A2')->getFont()->setBold(true);
        
        // Возрастная группа
        $sheet->setCellValue('A3', 'Возрастная группа: ' . $protocol['ageGroup']);
        $sheet->mergeCells('A3:J3');
        
        // Пустая строка
        $sheet->setCellValue('A4', '');
        
        // Заголовки таблицы (добавляем "Место" и "Время финиша")
        $headers = ['№', 'Дорожка', '№ воды', 'ФИО', 'Год рождения', 'Город', 'Разряд', 'Команда', 'Место', 'Время финиша'];
        $col = 'A';
        $row = 5;
        
        foreach ($headers as $header) {
            $sheet->setCellValue($col . $row, $header);
            $sheet->getStyle($col . $row)->getFont()->setBold(true);
            $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $col++;
        }
        
        // Данные участников
        $row = 6;
        foreach ($protocol['participants'] as $index => $participant) {
            $sheet->setCellValue('A' . $row, $index + 1);
            $sheet->setCellValue('B' . $row, $participant['lane'] ?? '');
            $sheet->setCellValue('C' . $row, $participant['startNumber'] ?? '');
            $sheet->setCellValue('D' . $row, $participant['fio'] ?? '');
            $sheet->setCellValue('E' . $row, $participant['birthYear'] ?? '');
            $sheet->setCellValue('F' . $row, $participant['city'] ?? '');
            $sheet->setCellValue('G' . $row, $participant['sportzvanie'] ?? '');
            $sheet->setCellValue('H' . $row, $participant['teamName'] ?? '');
            $sheet->setCellValue('I' . $row, $participant['place'] ?? '');
            $sheet->setCellValue('J' . $row, $participant['result'] ?? '');
            $row++;
        }
        
        // Добавляем границы таблицы
        $this->addTableBorders($sheet, 5, $row - 1, count($headers));
        
        // Автоматическая ширина колонок
        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
    
    /**
     * Добавление границ таблицы
     */
    private function addTableBorders($sheet, $startRow, $endRow, $colCount) {
        $borderStyle = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => '000000']
                ]
            ]
        ];
        
        $colLetters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
        $range = 'A' . $startRow . ':' . $colLetters[$colCount - 1] . $endRow;
        $sheet->getStyle($range)->applyFromArray($borderStyle);
    }
}
?> 
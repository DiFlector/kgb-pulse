<?php
/**
 * Класс для экспорта протоколов в Excel формат
 */
class ProtocolExporter {
    private $templatePath;
    
    public function __construct() {
        $this->templatePath = $_SERVER['DOCUMENT_ROOT'] . '/lks/files/template/';
    }
    
    /**
     * Экспорт стартового протокола
     */
    public function exportStartProtocol($protocolData, $discipline, $ageGroup, $heatNumber) {
        $templateFile = $this->getTemplateFile($discipline['class'], 'start');
        
        if (!file_exists($templateFile)) {
            throw new Exception("Шаблон не найден: " . $templateFile);
        }
        
        // Создаем временный файл
        $tempFile = tempnam(sys_get_temp_dir(), 'protocol_');
        
        // Копируем шаблон
        copy($templateFile, $tempFile);
        
        // Заполняем данными
        $this->fillStartProtocol($tempFile, $protocolData, $discipline, $ageGroup, $heatNumber);
        
        return $tempFile;
    }
    
    /**
     * Экспорт финишного протокола
     */
    public function exportFinishProtocol($protocolData, $discipline, $ageGroup, $heatNumber) {
        $templateFile = $this->getTemplateFile($discipline['class'], 'finish');
        
        if (!file_exists($templateFile)) {
            throw new Exception("Шаблон не найден: " . $templateFile);
        }
        
        // Создаем временный файл
        $tempFile = tempnam(sys_get_temp_dir(), 'protocol_');
        
        // Копируем шаблон
        copy($templateFile, $tempFile);
        
        // Заполняем данными
        $this->fillFinishProtocol($tempFile, $protocolData, $discipline, $ageGroup, $heatNumber);
        
        return $tempFile;
    }
    
    /**
     * Определение файла шаблона по типу лодки
     */
    private function getTemplateFile($boatClass, $type) {
        switch ($boatClass) {
            case 'D-10':
                return $this->templatePath . ($type === 'start' ? 'Start_dragons.xlsx' : 'Finish_dragons.xlsx');
            case 'K-1':
            case 'C-1':
                return $this->templatePath . ($type === 'start' ? 'Start_solo.xlsx' : 'Finish_solo.xlsx');
            case 'K-2':
            case 'C-2':
            case 'K-4':
            case 'C-4':
                return $this->templatePath . ($type === 'start' ? 'Start_group.xlsx' : 'Finish_group.xlsx');
            default:
                return $this->templatePath . ($type === 'start' ? 'Start_solo.xlsx' : 'Finish_solo.xlsx');
        }
    }
    
    /**
     * Заполнение стартового протокола
     */
    private function fillStartProtocol($filePath, $protocolData, $discipline, $ageGroup, $heatNumber) {
        // Используем библиотеку PhpSpreadsheet для работы с Excel
        require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
        
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        
        // Заполняем заголовок
        $worksheet->setCellValue('A1', 'СТАРТОВЫЙ ПРОТОКОЛ');
        $worksheet->setCellValue('A2', 'Дисциплина: ' . $discipline['class'] . ' ' . $discipline['sex'] . ' ' . $discipline['distance'] . 'м');
        $worksheet->setCellValue('A3', 'Возрастная группа: ' . $ageGroup);
        $worksheet->setCellValue('A4', 'Заезд №' . $heatNumber);
        $worksheet->setCellValue('A5', 'Дата: ' . date('d.m.Y'));
        
        // Заполняем участников
        $row = 7; // Начинаем с 7-й строки
        foreach ($protocolData['participants'] as $participant) {
            $worksheet->setCellValue('A' . $row, $participant['lane']); // Дорожка
            $worksheet->setCellValue('B' . $row, $participant['start_number']); // Номер
            $worksheet->setCellValue('C' . $row, $participant['fio']); // ФИО
            $worksheet->setCellValue('D' . $row, date('Y', strtotime($participant['birthdata']))); // Год рождения
            $worksheet->setCellValue('E' . $row, $participant['sportzvanie']); // Спортивный разряд
            $worksheet->setCellValue('F' . $row, $participant['city']); // Город
            $row++;
        }
        
        // Сохраняем файл
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($filePath);
    }
    
    /**
     * Заполнение финишного протокола
     */
    private function fillFinishProtocol($filePath, $protocolData, $discipline, $ageGroup, $heatNumber) {
        // Используем библиотеку PhpSpreadsheet для работы с Excel
        require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
        
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        
        // Заполняем заголовок
        $worksheet->setCellValue('A1', 'ФИНИШНЫЙ ПРОТОКОЛ');
        $worksheet->setCellValue('A2', 'Дисциплина: ' . $discipline['class'] . ' ' . $discipline['sex'] . ' ' . $discipline['distance'] . 'м');
        $worksheet->setCellValue('A3', 'Возрастная группа: ' . $ageGroup);
        $worksheet->setCellValue('A4', 'Заезд №' . $heatNumber);
        $worksheet->setCellValue('A5', 'Дата: ' . date('d.m.Y'));
        
        // Заполняем участников
        $row = 7; // Начинаем с 7-й строки
        foreach ($protocolData['participants'] as $participant) {
            $worksheet->setCellValue('A' . $row, $participant['lane']); // Дорожка
            $worksheet->setCellValue('B' . $row, $participant['start_number']); // Номер
            $worksheet->setCellValue('C' . $row, $participant['fio']); // ФИО
            $worksheet->setCellValue('D' . $row, $participant['result_time'] ?? ''); // Время
            $worksheet->setCellValue('E' . $row, $participant['place'] ?? ''); // Место
            $worksheet->setCellValue('F' . $row, $participant['notes'] ?? ''); // Примечания
            $row++;
        }
        
        // Сохраняем файл
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($filePath);
    }
    
    /**
     * Создание технических результатов
     */
    public function exportTechnicalResults($protocols, $eventName) {
        $templateFile = $this->templatePath . 'technical_results.xlsx';
        
        if (!file_exists($templateFile)) {
            throw new Exception("Шаблон технических результатов не найден");
        }
        
        // Создаем временный файл
        $tempFile = tempnam(sys_get_temp_dir(), 'technical_results_');
        
        // Копируем шаблон
        copy($templateFile, $tempFile);
        
        // Заполняем данными
        $this->fillTechnicalResults($tempFile, $protocols, $eventName);
        
        return $tempFile;
    }
    
    /**
     * Заполнение технических результатов
     */
    private function fillTechnicalResults($filePath, $protocols, $eventName) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
        
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        
        // Заполняем заголовок
        $worksheet->setCellValue('A1', 'ТЕХНИЧЕСКИЕ РЕЗУЛЬТАТЫ');
        $worksheet->setCellValue('A2', 'Мероприятие: ' . $eventName);
        $worksheet->setCellValue('A3', 'Дата: ' . date('d.m.Y'));
        
        $row = 5;
        foreach ($protocols as $protocol) {
            $discipline = $protocol['discipline'];
            $worksheet->setCellValue('A' . $row, $discipline['class'] . ' ' . $discipline['sex'] . ' ' . $discipline['distance'] . 'м');
            $worksheet->setCellValue('B' . $row, $protocol['age_group']);
            $row++;
            
            // Добавляем участников
            foreach ($protocol['finish_protocol']['heats'] as $heat) {
                foreach ($heat['participants'] as $participant) {
                    if ($participant['result_time']) {
                        $worksheet->setCellValue('A' . $row, $participant['fio']);
                        $worksheet->setCellValue('B' . $row, $participant['result_time']);
                        $worksheet->setCellValue('C' . $row, $participant['place']);
                        $worksheet->setCellValue('D' . $row, $participant['city']);
                        $row++;
                    }
                }
            }
            $row += 2; // Пропуск между дисциплинами
        }
        
        // Сохраняем файл
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($filePath);
    }
    
    /**
     * Отправка файла для скачивания
     */
    public function downloadFile($filePath, $filename) {
        if (!file_exists($filePath)) {
            throw new Exception("Файл не найден");
        }
        
        // Устанавливаем заголовки для скачивания
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        
        // Отправляем файл
        readfile($filePath);
        
        // Удаляем временный файл
        unlink($filePath);
    }
}
?> 
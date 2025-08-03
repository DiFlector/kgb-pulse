<?php
/**
 * Генератор CSV протоколов
 * Файл: www/lks/php/secretary/CsvProtocolGenerator.php
 * Поддерживает различные шаблоны протоколов
 */

class CsvProtocolGenerator {
    
    private $templatePath;
    private $protocolData;
    private $meroData;
    
    public function __construct($templatePath = null) {
        $this->templatePath = $templatePath ?? __DIR__ . "/../../files/template/";
    }
    
    /**
     * Генерирует CSV протокол на основе шаблона
     * 
     * @param array $protocolData Данные протокола из JSON
     * @param array $meroData Данные мероприятия
     * @param string $templateName Имя шаблона (например, 'Start_solo.csv')
     * @return string Содержимое CSV файла
     */
    public function generateProtocol($protocolData, $meroData, $templateName = 'Start_solo.csv') {
        $this->protocolData = $protocolData;
        $this->meroData = $meroData;
        
        // Загружаем шаблон
        $templateFile = $this->templatePath . $templateName;
        
        if (!file_exists($templateFile)) {
            throw new Exception("Шаблон {$templateName} не найден");
        }
        
        $templateContent = file_get_contents($templateFile);
        
        // Определяем тип шаблона и применяем соответствующую логику
        if (strpos($templateName, 'Start_solo') !== false) {
            return $this->generateSoloStartProtocol($templateContent);
        } elseif (strpos($templateName, 'Start_group') !== false) {
            return $this->generateGroupStartProtocol($templateContent);
        } elseif (strpos($templateName, 'Start_dragons') !== false) {
            return $this->generateDragonsStartProtocol($templateContent);
        } else {
            throw new Exception("Неподдерживаемый тип шаблона: {$templateName}");
        }
    }
    
    /**
     * Генерирует протокол для одиночных дисциплин
     */
    private function generateSoloStartProtocol($templateContent) {
        // Заменяем заголовки
        $discipline = $this->protocolData['discipline'];
        $ageGroupName = $this->protocolData['ageGroups'][0]['name'];
        $protocolNumber = $this->protocolData['ageGroups'][0]['protocol_number'] ?? 1;
        
        $templateContent = str_replace('*Дисциплина*', $discipline, $templateContent);
        $templateContent = str_replace('*Возрастная группа*', $ageGroupName, $templateContent);
        $templateContent = str_replace('*Номер протокола*', $protocolNumber, $templateContent);
        
        // Разбиваем на строки
        $lines = explode("\n", $templateContent);
        
        // Находим строки с номерами дорожек
        $laneLines = $this->findLaneLines($lines);
        
        // Заполняем данные участников
        $participants = $this->protocolData['ageGroups'][0]['participants'];
        
        foreach ($participants as $participant) {
            $lane = $participant['lane'];
            
            // Находим строку с соответствующим номером дорожки
            if (isset($laneLines[$lane])) {
                $lineIndex = $laneLines[$lane];
                
                // Форматируем данные участника
                $participantData = $this->formatParticipantData($participant, $ageGroupName);
                
                // Создаем строку с данными участника
                $participantLine = $this->createParticipantCsvLine($lane, $participantData);
                
                // Заменяем строку в шаблоне
                $lines[$lineIndex] = $participantLine;
            }
        }
        
        return implode("\n", $lines);
    }
    
    /**
     * Генерирует протокол для групповых дисциплин
     */
    private function generateGroupStartProtocol($templateContent) {
        // Заменяем заголовки
        $discipline = $this->protocolData['discipline'];
        $ageGroupName = $this->protocolData['ageGroups'][0]['name'];
        $protocolNumber = $this->protocolData['ageGroups'][0]['protocol_number'] ?? 1;
        
        $templateContent = str_replace('*Дисциплина*', $discipline, $templateContent);
        $templateContent = str_replace('*Возрастная группа*', $ageGroupName, $templateContent);
        $templateContent = str_replace('*Номер протокола*', $protocolNumber, $templateContent);
        
        // Разбиваем на строки
        $lines = explode("\n", $templateContent);
        
        // Находим строки с номерами дорожек
        $laneLines = $this->findLaneLines($lines);
        
        // Заполняем данные участников
        $participants = $this->protocolData['ageGroups'][0]['participants'];
        
        foreach ($participants as $participant) {
            $lane = $participant['lane'];
            
            // Находим строку с соответствующим номером дорожки
            if (isset($laneLines[$lane])) {
                $lineIndex = $laneLines[$lane];
                
                // Форматируем данные участника
                $participantData = $this->formatParticipantData($participant, $ageGroupName);
                
                // Создаем строку с данными участника
                $participantLine = $this->createParticipantCsvLine($lane, $participantData);
                
                // Заменяем строку в шаблоне
                $lines[$lineIndex] = $participantLine;
            }
        }
        
        return implode("\n", $lines);
    }
    
    /**
     * Генерирует протокол для драконов
     */
    private function generateDragonsStartProtocol($templateContent) {
        // Заменяем заголовки
        $discipline = $this->protocolData['discipline'];
        $ageGroupName = $this->protocolData['ageGroups'][0]['name'];
        $protocolNumber = $this->protocolData['ageGroups'][0]['protocol_number'] ?? 1;
        
        $templateContent = str_replace('*Дисциплина*', $discipline, $templateContent);
        $templateContent = str_replace('*Возрастная группа*', $ageGroupName, $templateContent);
        $templateContent = str_replace('*Номер протокола*', $protocolNumber, $templateContent);
        
        // Разбиваем на строки
        $lines = explode("\n", $templateContent);
        
        // Находим строки с номерами дорожек
        $laneLines = $this->findLaneLines($lines);
        
        // Заполняем данные участников
        $participants = $this->protocolData['ageGroups'][0]['participants'];
        
        foreach ($participants as $participant) {
            $lane = $participant['lane'];
            
            // Находим строку с соответствующим номером дорожки
            if (isset($laneLines[$lane])) {
                $lineIndex = $laneLines[$lane];
                
                // Форматируем данные участника
                $participantData = $this->formatParticipantData($participant, $ageGroupName);
                
                // Создаем строку с данными участника для драконов (с дополнительными столбцами)
                $participantLine = $this->createDragonsParticipantCsvLine($lane, $participantData);
                
                // Заменяем строку в шаблоне
                $lines[$lineIndex] = $participantLine;
            }
        }
        
        return implode("\n", $lines);
    }
    
    /**
     * Находит строки с номерами дорожек в шаблоне
     */
    private function findLaneLines($lines) {
        $laneLines = [];
        
        foreach ($lines as $index => $line) {
            // Ищем строки вида ";;0;-;;;;;;" (для одиночных/групповых)
            // или ";;0;-;;;;;;;;;" (для драконов)
            if (preg_match('/^;;(\d+);-;+$/', $line)) {
                $laneNumber = intval(preg_replace('/^;;(\d+);-;+$/', '$1', $line));
                $laneLines[$laneNumber] = $index;
            }
        }
        
        return $laneLines;
    }
    
    /**
     * Определяет подходящий шаблон на основе дисциплины
     */
    public function getTemplateForDiscipline($discipline) {
        switch ($discipline) {
            case 'D-10':
                return 'Start_dragons.csv';
            case 'K-1':
            case 'C-1':
            case 'HD-1':
            case 'OD-1':
            case 'OC-1':
                return 'Start_solo.csv';
            case 'K-2':
            case 'C-2':
            case 'OD-2':
                return 'Start_group.csv';
            case 'K-4':
            case 'C-4':
                return 'Start_group.csv';
            default:
                return 'Start_solo.csv'; // По умолчанию
        }
    }
    
    /**
     * Форматирует данные участника для CSV
     */
    private function formatParticipantData($participant, $ageGroupName) {
        return [
            'userId' => $participant['userId'],
            'fio' => $participant['fio'],
            'birthYear' => date('Y', strtotime($participant['birthdata'])),
            'ageGroup' => $ageGroupName,
            'city' => $participant['teamCity'] ?? '',
            'teamName' => $participant['teamName'] ?? '',
            'sportzvanie' => $participant['sportzvanie'] ?? 'БР'
        ];
    }
    
    /**
     * Создает CSV строку с данными участника
     */
    private function createParticipantCsvLine($lane, $participantData) {
        return ";;{$lane};-;{$participantData['userId']};{$participantData['fio']};{$participantData['birthYear']};{$participantData['ageGroup']};{$participantData['city']};";
    }
    
    /**
     * Создает CSV строку с данными участника для драконов
     */
    private function createDragonsParticipantCsvLine($lane, $participantData) {
        return ";;{$lane};-;{$participantData['userId']};{$participantData['fio']};{$participantData['birthYear']};{$participantData['ageGroup']};{$participantData['city']};{$participantData['teamName']};{$participantData['city']};";
    }
    
    /**
     * Получает данные конкретной возрастной группы
     */
    public function getAgeGroupData($groupKey) {
        foreach ($this->protocolData['ageGroups'] as $ageGroup) {
            if ($ageGroup['redisKey'] === $groupKey) {
                return $ageGroup;
            }
        }
        return null;
    }
    
    /**
     * Генерирует протокол для конкретной возрастной группы
     */
    public function generateProtocolForAgeGroup($groupKey, $templateName = null) {
        $ageGroupData = $this->getAgeGroupData($groupKey);
        
        if (!$ageGroupData) {
            throw new Exception("Возрастная группа не найдена: {$groupKey}");
        }
        
        // Создаем временную структуру данных для одной группы
        $singleGroupProtocol = [
            'discipline' => $this->protocolData['discipline'],
            'sex' => $this->protocolData['sex'],
            'distance' => $this->protocolData['distance'],
            'ageGroups' => [$ageGroupData]
        ];
        
        // Определяем шаблон если не указан
        if (!$templateName) {
            $templateName = $this->getTemplateForDiscipline($this->protocolData['discipline']);
        }
        
        return $this->generateProtocol($singleGroupProtocol, $this->meroData, $templateName);
    }
}
?> 
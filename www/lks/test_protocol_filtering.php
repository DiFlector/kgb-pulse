<?php
/**
 * Тест фильтрации протоколов
 */

require_once __DIR__ . "/php/common/JsonProtocolManager.php";
require_once __DIR__ . "/php/db/Database.php";

echo "=== ТЕСТ ФИЛЬТРАЦИИ ПРОТОКОЛОВ ===\n\n";

try {
    $protocolManager = JsonProtocolManager::getInstance();
    
    // Тестируем загрузку протоколов для мероприятия с ID 1
    $meroId = 1;
    
    echo "Загружаем протоколы для мероприятия ID: $meroId\n";
    $allProtocols = $protocolManager->getEventProtocols($meroId);
    
    echo "Всего протоколов: " . count($allProtocols) . "\n\n";
    
    if (empty($allProtocols)) {
        echo "❌ Протоколы не найдены\n";
        exit;
    }
    
    // Фильтруем протоколы по заполненности
    $filteredProtocols = [];
    $emptyProtocols = [];
    
    foreach ($allProtocols as $groupKey => $protocolData) {
        $data = $protocolData['data'] ?? $protocolData;
        
        // Проверяем наличие участников
        if (!isset($data['participants']) || !is_array($data['participants']) || count($data['participants']) === 0) {
            $emptyProtocols[] = $groupKey;
            continue; // Пропускаем пустые протоколы
        }
        
        $filteredProtocols[$groupKey] = $protocolData;
    }
    
    echo "✅ Протоколов с участниками: " . count($filteredProtocols) . "\n";
    echo "❌ Пустых протоколов: " . count($emptyProtocols) . "\n\n";
    
    if (!empty($emptyProtocols)) {
        echo "Пустые протоколы:\n";
        foreach (array_slice($emptyProtocols, 0, 10) as $emptyKey) {
            echo "  - $emptyKey\n";
        }
        if (count($emptyProtocols) > 10) {
            echo "  ... и еще " . (count($emptyProtocols) - 10) . " протоколов\n";
        }
        echo "\n";
    }
    
    if (!empty($filteredProtocols)) {
        echo "Протоколы с участниками:\n";
        $count = 0;
        foreach ($filteredProtocols as $groupKey => $protocolData) {
            $data = $protocolData['data'] ?? $protocolData;
            $participantsCount = count($data['participants']);
            echo "  - $groupKey: $participantsCount участников\n";
            $count++;
            if ($count >= 10) break;
        }
        if (count($filteredProtocols) > 10) {
            echo "  ... и еще " . (count($filteredProtocols) - 10) . " протоколов\n";
        }
        echo "\n";
    }
    
    // Тестируем CSV скачивание
    echo "=== ТЕСТ CSV СКАЧИВАНИЯ ===\n\n";
    
    $csvOutput = "";
    $raceNumber = 1;
    
    foreach ($filteredProtocols as $groupKey => $protocolData) {
        $data = $protocolData['data'] ?? $protocolData;
        
        // Извлекаем информацию о дисциплине
        $redisKey = $data['redisKey'] ?? $groupKey;
        $parts = explode(':', $redisKey);
        $discipline = '';
        $ageGroup = '';
        
        if (count($parts) >= 5) {
            $boatType = $parts[2];
            $sex = $parts[3];
            $distance = $parts[4];
            $discipline = $boatType . ' ' . $distance . 'м ' . $sex;
        }
        
        $ageGroup = $data['name'] ?? 'группа';
        
        echo "Протокол $raceNumber: $discipline - $ageGroup (" . count($data['participants']) . " участников)\n";
        
        // Добавляем в CSV
        $csvOutput .= $raceNumber . ';;' . $discipline . ';;;' . $ageGroup . ';;;;' . "\r\n";
        $csvOutput .= 'СТАРТ;Время заезда;Вода;-;Номер;ФИО;Год рождения;Группа;Спортивный разряд;Спорт.организация' . "\r\n";
        
        // Добавляем участников
        foreach ($data['participants'] as $participant) {
            $lane = ($participant['lane'] ?? 1) - 1;
            $csvOutput .= ";;$lane;-;" . ($participant['userId'] ?? '') . ";" . ($participant['fio'] ?? '') . ";" . 
                         extractYearFromBirthdate($participant['birthdata'] ?? '') . ";" . $ageGroup . ";" . 
                         ($participant['sportzvanie'] ?? 'Б/р') . ";" . ($participant['city'] ?? 'Москва') . "\r\n";
        }
        
        $raceNumber++;
    }
    
    echo "\n✅ CSV протоколы сгенерированы успешно\n";
    echo "Размер вывода: " . strlen($csvOutput) . " байт\n";
    echo "Количество протоколов в CSV: " . ($raceNumber - 1) . "\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    echo "Файл: " . $e->getFile() . "\n";
    echo "Строка: " . $e->getLine() . "\n";
}

// Вспомогательная функция
function extractYearFromBirthdate($birthdate) {
    if (empty($birthdate)) return '';
    $parts = explode('-', $birthdate);
    return isset($parts[0]) ? $parts[0] : '';
}

echo "\n=== ТЕСТ ЗАВЕРШЕН ===\n";
?> 
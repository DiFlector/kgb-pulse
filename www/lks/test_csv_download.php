<?php
/**
 * Простой тест CSV скачивания протоколов
 */

require_once __DIR__ . "/php/common/JsonProtocolManager.php";
require_once __DIR__ . "/php/db/Database.php";

echo "=== ТЕСТ CSV СКАЧИВАНИЯ ПРОТОКОЛОВ ===\n\n";

try {
    $protocolManager = JsonProtocolManager::getInstance();
    
    // Тестируем загрузку протоколов для мероприятия с ID 1
    $meroId = 1;
    $protocolType = 'start';
    
    echo "Загружаем протоколы для мероприятия ID: $meroId\n";
    $allProtocols = $protocolManager->getEventProtocols($meroId);
    
    echo "Найдено протоколов: " . count($allProtocols) . "\n\n";
    
    if (empty($allProtocols)) {
        echo "❌ Протоколы не найдены\n";
        exit;
    }
    
    // Фильтруем протоколы по типу и заполненности
    $filteredProtocols = [];
    foreach ($allProtocols as $groupKey => $protocolData) {
        $data = $protocolData['data'] ?? $protocolData;
        if (empty($data['participants'])) {
            continue; // Пропускаем пустые протоколы
        }

        if ($protocolType === 'finish') {
            // Для финишных протоколов проверяем полноту
            $isComplete = true;
            foreach ($data['participants'] as $participant) {
                if (empty($participant['place']) || empty($participant['finishTime'])) {
                    $isComplete = false;
                    break;
                }
            }
            if (!$isComplete) {
                continue; // Пропускаем незаполненные финишные протоколы
            }
        }

        $filteredProtocols[$groupKey] = $protocolData;
    }
    
    echo "Отфильтровано протоколов с участниками: " . count($filteredProtocols) . "\n\n";
    
    if (empty($filteredProtocols)) {
        echo "❌ Нет протоколов для скачивания\n";
        exit;
    }
    
    // Генерируем CSV для каждого протокола
    $raceNumber = 1;
    $csvOutput = "";
    
    foreach ($filteredProtocols as $groupKey => $protocolData) {
        // Извлекаем данные из структуры JSON
        $data = $protocolData['data'] ?? $protocolData;
        
        // Извлекаем информацию о дисциплине из redisKey и название группы из data.name
        $redisKey = $data['redisKey'] ?? $groupKey;
        $parts = explode(':', $redisKey);
        $discipline = '';
        $ageGroup = '';
        
        if (count($parts) >= 5) {
            // Формат: protocol:1:K-1:Ж:200:группа 7
            $boatType = $parts[2]; // K-1
            $sex = $parts[3];      // Ж
            $distance = $parts[4];  // 200
            
            $discipline = $boatType . ' ' . $distance . 'м ' . $sex;
        }
        
        // Используем правильное название группы из data.name
        $ageGroup = $data['name'] ?? 'группа';
        
        echo "Протокол $raceNumber: $discipline - $ageGroup\n";
        echo "  Участников: " . count($data['participants']) . "\n";
        
        // Заголовок протокола
        if ($protocolType === 'start') {
            $csvOutput .= $raceNumber . ';;' . $discipline . ';;;' . $ageGroup . ';;;;' . "\r\n";
        } else {
            $csvOutput .= $raceNumber . ';;' . $discipline . ';;;;;;' . $ageGroup . ';;;;' . "\r\n";
        }
        
        // Заголовок таблицы
        if ($protocolType === 'start') {
            $csvOutput .= 'СТАРТ;Время заезда;Вода;-;Номер;ФИО;Год рождения;Группа;Спортивный разряд;Спорт.организация' . "\r\n";
        } else {
            $csvOutput .= 'ФИНИШ;Место в заезде;Вода;-;Время прохождения;Минуты;Секунды;Номер;ФИО;Год рождения;Группа;Спортивный разряд;Спорт.организация' . "\r\n";
        }

        // Данные участников
        $maxLanes = 10;
        for ($lane = 1; $lane <= $maxLanes; $lane++) {
            $participant = null;
            foreach ($data['participants'] as $p) {
                if (($p['lane'] ?? 0) == $lane) {
                    $participant = $p;
                    break;
                }
            }
            
            if ($participant) {
                if ($protocolType === 'start') {
                    $row = ';;' . ($lane - 1) . ';-;' . 
                           ($participant['userId'] ?? '') . ';' . 
                           ($participant['fio'] ?? '') . ';' . 
                           extractYearFromBirthdate($participant['birthdata'] ?? '') . ';' . 
                           $ageGroup . ';' . 
                           ($participant['sportzvanie'] ?? 'Б/р') . ';' . 
                           ($participant['city'] ?? 'Москва');
                } else {
                    $row = ';' . ($participant['place'] ?? '') . ';' . ($lane - 1) . ';-;' . 
                           ($participant['finishTime'] ?? '') . ';' . 
                           extractMinutesFromTime($participant['finishTime'] ?? '') . ';' . 
                           extractSecondsFromTime($participant['finishTime'] ?? '') . ';' . 
                           ($participant['userId'] ?? '') . ';' . 
                           ($participant['fio'] ?? '') . ';' . 
                           extractYearFromBirthdate($participant['birthdata'] ?? '') . ';' . 
                           $ageGroup . ';' . 
                           ($participant['sportzvanie'] ?? 'Б/р') . ';' . 
                           ($participant['city'] ?? 'Москва');
                }
                $csvOutput .= $row . "\r\n";
            } else {
                if ($protocolType === 'start') {
                    $csvOutput .= ';;' . ($lane - 1) . ';-;;;;;;' . "\r\n";
                } else {
                    $csvOutput .= ';;' . ($lane - 1) . ';-;;;;;;;' . "\r\n";
                }
            }
        }
        
        $raceNumber++;
    }
    
    echo "\n✅ CSV протоколы сгенерированы успешно\n";
    echo "Размер вывода: " . strlen($csvOutput) . " байт\n";
    
    // Показываем первые 1000 символов для проверки
    echo "Первые 1000 символов:\n";
    echo substr($csvOutput, 0, 1000) . "\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    echo "Файл: " . $e->getFile() . "\n";
    echo "Строка: " . $e->getLine() . "\n";
}

// Вспомогательные функции
function extractYearFromBirthdate($birthdate) {
    if (empty($birthdate)) return '';
    $parts = explode('-', $birthdate);
    return isset($parts[0]) ? $parts[0] : '';
}

function extractMinutesFromTime($time) {
    if (empty($time)) return '';
    $parts = explode(':', $time);
    return isset($parts[1]) ? $parts[1] : '';
}

function extractSecondsFromTime($time) {
    if (empty($time)) return '';
    $parts = explode(':', $time);
    return isset($parts[2]) ? $parts[2] : '';
}

echo "\n=== ТЕСТ ЗАВЕРШЕН ===\n";
?> 
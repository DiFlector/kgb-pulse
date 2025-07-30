<?php
/**
 * Утилиты для работы с протоколами
 */

/**
 * Создание имени файла протокола
 */
function createProtocolFilename($class, $sex, $distance, $ageGroup) {
    $dataDir = __DIR__ . "/../../files/json/";
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0777, true);
    }
    
    // Очищаем дистанцию от специальных символов
    $cleanDistance = str_replace([' ', ',', '|'], ['_', '_', '_'], $distance);
    
    // Создаем короткое имя для возрастной группы
    $cleanAgeGroup = '';
    if (strpos($ageGroup, 'группа_Дети') !== false) {
        $cleanAgeGroup = 'children';
    } elseif (strpos($ageGroup, 'группа_ЮД1') !== false) {
        $cleanAgeGroup = 'youthd1';
    } elseif (strpos($ageGroup, 'группа_Ю1') !== false) {
        $cleanAgeGroup = 'youth1';
    } elseif (strpos($ageGroup, 'группа_Ю2') !== false) {
        $cleanAgeGroup = 'youth2';
    } elseif (strpos($ageGroup, 'группа_Ю3') !== false) {
        $cleanAgeGroup = 'youth3';
    } elseif (strpos($ageGroup, 'группа_Ю4') !== false) {
        $cleanAgeGroup = 'youth4';
    } elseif (strpos($ageGroup, 'группа_0') !== false) {
        $cleanAgeGroup = 'group0';
    } elseif (strpos($ageGroup, 'группа_1') !== false) {
        $cleanAgeGroup = 'group1';
    } elseif (strpos($ageGroup, 'группа_2') !== false) {
        $cleanAgeGroup = 'group2';
    } elseif (strpos($ageGroup, 'группа_3') !== false) {
        $cleanAgeGroup = 'group3';
    } elseif (strpos($ageGroup, 'группа_4') !== false) {
        $cleanAgeGroup = 'group4';
    } elseif (strpos($ageGroup, 'группа_5') !== false) {
        $cleanAgeGroup = 'group5';
    } elseif (strpos($ageGroup, 'группа_6') !== false) {
        $cleanAgeGroup = 'group6';
    } elseif (strpos($ageGroup, 'группа_7') !== false) {
        $cleanAgeGroup = 'group7';
    } elseif (strpos($ageGroup, 'группа_8') !== false) {
        $cleanAgeGroup = 'group8';
    } elseif (strpos($ageGroup, 'группа_9') !== false) {
        $cleanAgeGroup = 'group9';
    } elseif (strpos($ageGroup, 'группа_10') !== false) {
        $cleanAgeGroup = 'group10';
    } else {
        // Если не найдено конкретное соответствие, создаем хеш
        $cleanAgeGroup = substr(md5($ageGroup), 0, 8);
    }
    
    return $dataDir . "{$class}_{$sex}_{$cleanDistance}_{$cleanAgeGroup}.json";
}

/**
 * Сохранение данных протокола в JSON файл
 */
function saveProtocolDataToFile($class, $sex, $distance, $ageGroup, $startProtocol, $finishProtocol = null) {
    $filename = createProtocolFilename($class, $sex, $distance, $ageGroup);
    
    $data = [
        'class' => $class,
        'sex' => $sex,
        'distance' => $distance,
        'ageGroup' => $ageGroup,
        'startProtocol' => $startProtocol,
        'finishProtocol' => $finishProtocol,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    return file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Загрузка данных протокола из JSON файла
 */
function loadProtocolDataFromFile($class, $sex, $distance, $ageGroup) {
    $filename = createProtocolFilename($class, $sex, $distance, $ageGroup);
    
    if (!file_exists($filename)) {
        return null;
    }
    
    $data = json_decode(file_get_contents($filename), true);
    return $data;
}

/**
 * Обновление данных участника в JSON файле
 */
function updateParticipantDataInFile($class, $sex, $distance, $ageGroup, $participantId, $field, $value, $protocolType = 'start') {
    try {
        $data = loadProtocolDataFromFile($class, $sex, $distance, $ageGroup);
        
        if (!$data) {
            return false;
        }
        
        $protocolKey = $protocolType . 'Protocol';
        if (!isset($data[$protocolKey]) || !isset($data[$protocolKey]['participants'])) {
            return false;
        }
        
        // Находим и обновляем участника
        foreach ($data[$protocolKey]['participants'] as &$participant) {
            if ($participant['id'] == $participantId) {
                $participant[$field] = $value;
                break;
            }
        }
        
        // Обновляем время изменения
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        // Сохраняем обновленные данные
        $filename = createProtocolFilename($class, $sex, $distance, $ageGroup);
        file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return true;
        
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Получение списка всех файлов протоколов для мероприятия
 */
function getProtocolFilesForEvent($meroId) {
    $dataDir = __DIR__ . "/../../files/json/";
    $files = glob($dataDir . "*.json");
    $protocolFiles = [];
    
    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && isset($data['startProtocol']) && isset($data['startProtocol']['meroId']) && $data['startProtocol']['meroId'] == $meroId) {
            $protocolFiles[] = [
                'filename' => basename($file),
                'class' => $data['class'],
                'sex' => $data['sex'],
                'distance' => $data['distance'],
                'ageGroup' => $data['ageGroup'],
                'startParticipants' => count($data['startProtocol']['participants'] ?? []),
                'finishParticipants' => count($data['finishProtocol']['participants'] ?? []),
                'created_at' => $data['created_at']
            ];
        }
    }
    
    return $protocolFiles;
}
?> 
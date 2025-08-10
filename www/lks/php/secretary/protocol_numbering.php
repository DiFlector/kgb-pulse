<?php
/**
 * Система нумерации протоколов по возрастным группам
 * Файл: www/lks/php/secretary/protocol_numbering.php
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../common/JsonProtocolManager.php';

class ProtocolNumbering {
    
    /**
     * Получение структуры протоколов с нумерацией
     */
    public static function getProtocolsStructure($classDistance, $selectedDisciplines = null) {
        error_log("ProtocolNumbering::getProtocolsStructure вызвана");
        error_log("classDistance: " . json_encode($classDistance));
        error_log("selectedDisciplines: " . json_encode($selectedDisciplines));
        
        $protocols = [];
        $protocolNumber = 1;
        
        // Определяем порядок классов лодок (от меньшего к большему)
        $boatClassOrder = ['D-10', 'K-1', 'C-1', 'K-2', 'C-2', 'K-4', 'C-4', 'H-1', 'H-2', 'H-4', 'O-1', 'O-2', 'O-4', 'HD-1', 'OD-1', 'OD-2', 'OC-1'];
        
        // Определяем порядок полов
        $sexOrder = ['M', 'М', 'Ж', 'MIX'];
        
        // Сортируем классы лодок в правильном порядке
        $sortedClasses = array_filter($boatClassOrder, function($class) use ($classDistance) {
            return isset($classDistance[$class]);
        });
        
        foreach ($sortedClasses as $class) {
            $disciplineData = $classDistance[$class];
            
            if (!isset($disciplineData['sex']) || !isset($disciplineData['dist']) || !isset($disciplineData['age_group'])) {
                continue;
            }
            
            $sexes = $disciplineData['sex'];
            $distances = $disciplineData['dist'];
            $ageGroups = $disciplineData['age_group'];
            
            // Сортируем полы в правильном порядке
            $sortedSexes = array_filter($sexOrder, function($sex) use ($sexes) {
                return in_array($sex, $sexes);
            });
            
            // Сортируем дистанции численно
            $sortedDistances = [];
            foreach ($distances as $distanceStr) {
                $individualDistances = explode(',', $distanceStr);
                foreach ($individualDistances as $distance) {
                    $cleanDistance = trim($distance);
                    if (!empty($cleanDistance)) {
                        $sortedDistances[] = $cleanDistance;
                    }
                }
            }
            sort($sortedDistances, SORT_NUMERIC);
            
            foreach ($sortedDistances as $distance) {
                foreach ($sortedSexes as $sex) {
                    $sexIndex = array_search($sex, $sexes);
                    if ($sexIndex === false) continue;
                    
                    // Проверяем, выбрана ли эта дисциплина
                    if ($selectedDisciplines && !self::isDisciplineSelected($class, $sex, $distance, $selectedDisciplines)) {
                        continue;
                    }
                    
                    // Получаем возрастные группы для данного пола
                    if (isset($ageGroups[$sexIndex])) {
                        $ageGroupString = $ageGroups[$sexIndex];
                        $parsedAgeGroups = self::parseAgeGroups($ageGroupString);
                        
                        foreach ($parsedAgeGroups as $ageGroup) {
                            $protocols[] = [
                                'number' => $protocolNumber,
                                'class' => $class,
                                'sex' => $sex,
                                'distance' => $distance,
                                'ageGroup' => $ageGroup,
                                'displayName' => self::getAgeGroupDisplayName($ageGroup, $sex),
                                'fullName' => self::getProtocolFullName($class, $sex, $distance, $ageGroup, $protocolNumber)
                            ];
                            $protocolNumber++;
                        }
                    }
                }
            }
        }
        
        error_log("ProtocolNumbering::getProtocolsStructure завершена, найдено протоколов: " . count($protocols));
        return $protocols;
    }
    
    /**
     * Расчет возрастной группы участника
     */
    public static function calculateAgeGroup($age, $sex, $class, $classDistance) {
        try {
            // Проверяем, есть ли данные о дисциплине
            if (!isset($classDistance[$class])) {
                error_log("calculateAgeGroup: дисциплина $class не найдена в структуре мероприятия");
                return null;
            }

            $disciplineData = $classDistance[$class];
            
            // Находим индекс пола
            $sexIndex = array_search($sex, $disciplineData['sex']);
            if ($sexIndex === false) {
                error_log("calculateAgeGroup: пол $sex не найден в дисциплине $class");
                return null;
            }

            // Получаем возрастные группы для данного пола
            if (!isset($disciplineData['age_group'][$sexIndex])) {
                error_log("calculateAgeGroup: возрастные группы не найдены для пола $sex в дисциплине $class");
                return null;
            }

            $ageGroupsString = $disciplineData['age_group'][$sexIndex];
            
            // Разбиваем строку на отдельные группы
            $ageGroups = explode(', ', $ageGroupsString);
            
            foreach ($ageGroups as $ageGroup) {
                // Разбираем группу: "группа Название: 18-29" или "группа 1: 18-29"
                if (preg_match('/группа ([^:]+): (\d+)-(\d+)/', $ageGroup, $matches)) {
                    $groupName = trim($matches[1]);
                    $minAge = intval($matches[2]);
                    $maxAge = intval($matches[3]);
                    
                    if ($age >= $minAge && $age <= $maxAge) {
                        return "группа $groupName";
                    }
                }
            }

            error_log("calculateAgeGroup: возраст $age не подходит ни под одну группу для $class $sex");
            return null;
            
        } catch (Exception $e) {
            error_log("calculateAgeGroup: ошибка при расчете возрастной группы: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Проверка, выбрана ли дисциплина
     */
    public static function isDisciplineSelected($class, $sex, $distance, $selectedDisciplines) {
        error_log("isDisciplineSelected: проверяем дисциплину $class $sex $distance");
        error_log("isDisciplineSelected: selectedDisciplines = " . json_encode($selectedDisciplines));
        
        if (!$selectedDisciplines || !is_array($selectedDisciplines)) {
            error_log("isDisciplineSelected: дисциплины не указаны, возвращаем true");
            return true; // Если дисциплины не указаны, считаем все выбранными
        }
        
        foreach ($selectedDisciplines as $discipline) {
            // Проверяем, является ли дисциплина строкой в формате "C-1_М_200"
            if (is_string($discipline)) {
                $parts = explode('_', $discipline);
                if (count($parts) === 3) {
                    $disciplineClass = $parts[0];
                    $disciplineSex = $parts[1];
                    $disciplineDistance = $parts[2];
                    
                    error_log("isDisciplineSelected: разбор строки '$discipline' -> class='$disciplineClass', sex='$disciplineSex', distance='$disciplineDistance'");
                    error_log("isDisciplineSelected: сравниваем с class='$class', sex='$sex', distance='$distance'");
                    
                    // Используем универсальную функцию сравнения полов
                    $sexMatch = compareSex($disciplineSex, $sex);
                    error_log("isDisciplineSelected: сравнение полов '$disciplineSex' vs '$sex' = " . ($sexMatch ? 'true' : 'false'));
                    
                    // Дополнительная проверка для отладки
                    $classMatch = ($disciplineClass === $class);
                    $distanceMatch = ($disciplineDistance === $distance);
                    error_log("isDisciplineSelected: class match = " . ($classMatch ? 'true' : 'false') . ", distance match = " . ($distanceMatch ? 'true' : 'false'));
                    
                    if ($classMatch && $sexMatch && $distanceMatch) {
                        error_log("isDisciplineSelected: дисциплина найдена (строковый формат), возвращаем true");
                        return true;
                    }
                }
            }
            // Проверяем, является ли дисциплина объектом с полями class, sex, distance
            else if (isset($discipline['class']) && isset($discipline['sex']) && isset($discipline['distance'])) {
                error_log("isDisciplineSelected: проверяем объект дисциплины: " . json_encode($discipline));
                
                // Используем универсальную функцию сравнения полов
                $sexMatch = compareSex($discipline['sex'], $sex);
                error_log("isDisciplineSelected: сравнение полов объекта '$discipline[sex]' vs '$sex' = " . ($sexMatch ? 'true' : 'false'));
                
                if ($discipline['class'] === $class && $sexMatch && $discipline['distance'] === $distance) {
                    error_log("isDisciplineSelected: дисциплина найдена (объектный формат), возвращаем true");
                    return true;
                }
            }
        }
        
        error_log("isDisciplineSelected: дисциплина не найдена, возвращаем false");
        return false;
    }
    
    /**
     * Разбор строки возрастных групп
     */
    private static function parseAgeGroups($ageGroupString) {
        if (empty($ageGroupString)) {
            return [];
        }
        
        $groups = [];
        $groupStrings = explode(', ', $ageGroupString);
        
        foreach ($groupStrings as $groupString) {
            $parts = explode(': ', $groupString);
            if (count($parts) === 2) {
                $groupName = trim($parts[0]);
                $range = trim($parts[1]);
                
                $rangeParts = explode('-', $range);
                if (count($rangeParts) === 2) {
                    $minAge = (int)trim($rangeParts[0]);
                    $maxAge = (int)trim($rangeParts[1]);
                    
                    $groups[] = [
                        'name' => $groupName,
                        'min_age' => $minAge,
                        'max_age' => $maxAge,
                        'full_name' => $groupString
                    ];
                }
            }
        }
        
        return $groups;
    }
    
    /**
     * Получение отображаемого названия возрастной группы
     */
    private static function getAgeGroupDisplayName($ageGroup, $sex) {
        // Нормализуем пол в русский формат для отображения
        $normalizedSex = normalizeSexToRussian($sex);
        
        // Используем полное название возрастной группы с названием и возрастным диапазоном
        $ageGroupFullName = isset($ageGroup['full_name']) ? $ageGroup['full_name'] : $ageGroup['name'];
        
        if ($normalizedSex === 'М') {
            return 'Мужчины (' . $ageGroupFullName . ')';
        } elseif ($normalizedSex === 'Ж') {
            return 'Женщины (' . $ageGroupFullName . ')';
        } else {
            return 'Смешанные команды (' . $ageGroupFullName . ')';
        }
    }
    
    /**
     * Получение полного названия протокола
     */
    private static function getProtocolFullName($class, $sex, $distance, $ageGroup, $number) {
        $boatClassName = self::getBoatClassName($class);
        // Нормализуем пол в русский формат для отображения
        $normalizedSex = normalizeSexToRussian($sex);
        
        if ($normalizedSex === 'М') {
            $sexName = 'Мужчины';
        } elseif ($normalizedSex === 'Ж') {
            $sexName = 'Женщины';
        } else {
            $sexName = 'Смешанные команды';
        }
        
        // Используем полное название возрастной группы с названием и возрастным диапазоном
        $ageGroupFullName = isset($ageGroup['full_name']) ? $ageGroup['full_name'] : $ageGroup['name'];
        
        return "Протокол №{$number} - {$boatClassName}, {$distance}м, {$sexName}, {$ageGroupFullName}";
    }
    
    /**
     * Получение названия класса лодки
     */
    private static function getBoatClassName($class) {
        $boatNames = [
            'D-10' => 'Драконы (D-10)',
            'K-1' => 'Байдарка-одиночка (K-1)',
            'K-2' => 'Байдарка-двойка (K-2)',
            'K-4' => 'Байдарка-четверка (K-4)',
            'C-1' => 'Каноэ-одиночка (C-1)',
            'C-2' => 'Каноэ-двойка (C-2)',
            'C-4' => 'Каноэ-четверка (C-4)',
            'HD-1' => 'Специальная лодка (HD-1)',
            'OD-1' => 'Специальная лодка (OD-1)',
            'OD-2' => 'Специальная лодка (OD-2)',
            'OC-1' => 'Специальная лодка (OC-1)'
        ];
        
        return $boatNames[$class] ?? $class;
    }
    
    /**
     * Получение ключа протокола для Redis
     */
    public static function getProtocolKey($meroId, $class, $sex, $distance, $ageGroup) {
        // Нормализуем пол в английский формат для ключа
        $normalizedSex = normalizeSexToEnglish($sex);
        
        // Если ageGroup - это объект, извлекаем полное название
        if (is_array($ageGroup) && isset($ageGroup['full_name'])) {
            $ageGroupName = $ageGroup['full_name'];
        } elseif (is_array($ageGroup) && isset($ageGroup['name'])) {
            $ageGroupName = $ageGroup['name'];
        } else {
            $ageGroupName = $ageGroup;
        }
        
        return "protocol:{$meroId}:{$class}_{$normalizedSex}_{$distance}_{$ageGroupName}";
    }
    
    /**
     * Получение номера протокола по ключу
     */
    public static function getProtocolNumber($classDistance, $class, $sex, $distance, $ageGroupName) {
        $protocols = self::getProtocolsStructure($classDistance);
        
        foreach ($protocols as $protocol) {
            if ($protocol['class'] === $class && 
                $protocol['sex'] === $sex && 
                $protocol['distance'] === $distance && 
                $protocol['ageGroup']['name'] === $ageGroupName) {
                return $protocol['number'];
            }
        }
        
        return null;
    }
    
    /**
     * Проверка заполненности протоколов
     */
    public static function checkProtocolsCompletion($redis, $meroId, $classDistance, $selectedDisciplines) {
        $protocols = self::getProtocolsStructure($classDistance, $selectedDisciplines);
        $completionStatus = [];
        $jsonManager = JsonProtocolManager::getInstance();
        
        foreach ($protocols as $protocol) {
            // Используем полное название возрастной группы для ключа
            $ageGroupName = isset($protocol['ageGroup']['full_name']) ? $protocol['ageGroup']['full_name'] : $protocol['ageGroup']['name'];
            $key = self::getProtocolKey($meroId, $protocol['class'], $protocol['sex'], $protocol['distance'], $ageGroupName);
            
            // Сначала пытаемся читать из Redis (если он доступен)
            $protocolData = null;
            if ($redis) {
                try {
                    $protocolData = $redis->get($key);
                } catch (Exception $e) {
                    $protocolData = null;
                }
            }
            $hasParticipants = false;
            $isProtected = false;
            
            if ($protocolData) {
                $data = json_decode($protocolData, true);
                if ($data && isset($data['participants']) && count($data['participants']) > 0) {
                    $hasParticipants = true;
                    $isProtected = isset($data['protected']) && $data['protected'];
                }
            } else {
                // Фолбэк: читаем из JSON-файлов
                $jsonKey = "protocol:{$meroId}:{$protocol['class']}:{$protocol['sex']}:{$protocol['distance']}:{$ageGroupName}";
                $data = $jsonManager->loadProtocol($jsonKey);
                if ($data && isset($data['participants']) && count($data['participants']) > 0) {
                    $hasParticipants = true;
                    // пометка protected может быть в корне или у участников
                    $isProtected = !empty($data['protected']);
                    if (!$isProtected) {
                        foreach ($data['participants'] as $p) {
                            if (!empty($p['protected'])) { $isProtected = true; break; }
                        }
                    }
                }
            }
            
            $completionStatus[] = [
                'class' => $protocol['class'],
                'sex' => $protocol['sex'],
                'distance' => $protocol['distance'],
                'ageGroup' => $protocol['ageGroup']['name'],
                'hasParticipants' => $hasParticipants,
                'isProtected' => $isProtected,
                'number' => $protocol['number']
            ];
        }
        
        return $completionStatus;
    }
    
    /**
     * Получение статуса заполненности дисциплин
     */
    public static function getDisciplinesCompletionStatus($redis, $meroId, $classDistance, $selectedDisciplines) {
        $protocols = self::checkProtocolsCompletion($redis, $meroId, $classDistance, $selectedDisciplines);
        $disciplinesStatus = [];
        
        foreach ($protocols as $protocol) {
            $disciplineKey = $protocol['class'] . '_' . $protocol['sex'] . '_' . $protocol['distance'];
            
            if (!isset($disciplinesStatus[$disciplineKey])) {
                $disciplinesStatus[$disciplineKey] = [
                    'class' => $protocol['class'],
                    'sex' => $protocol['sex'],
                    'distance' => $protocol['distance'],
                    'totalProtocols' => 0,
                    'filledProtocols' => 0,
                    'protectedProtocols' => 0
                ];
            }
            
            $disciplinesStatus[$disciplineKey]['totalProtocols']++;
            
            if ($protocol['hasParticipants']) {
                $disciplinesStatus[$disciplineKey]['filledProtocols']++;
            }
            
            if ($protocol['isProtected']) {
                $disciplinesStatus[$disciplineKey]['protectedProtocols']++;
            }
        }
        
        // Определяем статус для каждой дисциплины
        foreach ($disciplinesStatus as $key => &$discipline) {
            // Зеленый, если все заполнены ИЛИ все пустые
            if ($discipline['filledProtocols'] === $discipline['totalProtocols'] || $discipline['filledProtocols'] === 0) {
                $discipline['status'] = 'completed';
            } elseif ($discipline['filledProtocols'] > 0) {
                $discipline['status'] = 'partial'; // Желтый - частично заполнено
            } else {
                $discipline['status'] = 'empty'; // Белый - пусто
            }
        }
        
        return $disciplinesStatus;
    }
    
    /**
     * Проверка готовности мероприятия к завершению
     */
    public static function isEventReadyForCompletion($redis, $meroId, $classDistance, $selectedDisciplines) {
        $disciplinesStatus = self::getDisciplinesCompletionStatus($redis, $meroId, $classDistance, $selectedDisciplines);
        
        foreach ($disciplinesStatus as $discipline) {
            if ($discipline['status'] !== 'completed') {
                return false;
            }
        }
        
        return true;
    }
}
?> 
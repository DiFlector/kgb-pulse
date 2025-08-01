<?php
/**
 * Создание протоколов для мероприятия
 */

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/protocol_utils.php";

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['meroId']) || !isset($input['type'])) {
        throw new Exception('Неверные параметры запроса');
    }
    
    $meroId = $input['meroId'];
    $type = $input['type']; // 'start' или 'finish'
    
    $db = Database::getInstance()->getPDO();
    
    // Получаем структуру протоколов
    $protocolsStructure = getProtocolsStructure($db, $meroId);
    
    if (empty($protocolsStructure)) {
        throw new Exception('Не найдены дисциплины для создания протоколов');
    }

    // Создаем протоколы для каждой дисциплины и возрастной группы
    $createdCount = 0;
    foreach ($protocolsStructure as $discipline) {
        foreach ($discipline['ageGroups'] as $ageGroup) {
            // Используем полное название возрастной группы с названием и возрастным диапазоном
            $ageGroupName = isset($ageGroup['full_name']) ? $ageGroup['full_name'] : $ageGroup['name'];
            $groupKey = "{$discipline['key']}_{$ageGroupName}";
            
            // Получаем участников для данной группы
            $participants = getParticipantsForGroup($db, $meroId, $discipline, $ageGroup);
            
            // Сохраняем в Redis
            saveProtocolData($meroId, $groupKey, $participants, $type);
            
            $createdCount++;
        }
    }

    echo json_encode([
        'success' => true,
        'count' => $createdCount,
        'message' => "Создано {$createdCount} протоколов"
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Получение структуры протоколов
 */
function getProtocolsStructure($db, $meroId) {
    $query = "
        SELECT 
            m.champn,
            m.class_distance,
            m.meroname
        FROM meros m
        WHERE m.champn = ?
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$meroId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$event) {
        throw new Exception('Мероприятие не найдено');
    }
    
    $classDistance = json_decode($event['class_distance'], true);
    if (!$classDistance) {
        throw new Exception('Неверная структура данных мероприятия');
    }
    
    $protocolsStructure = [];
    
    foreach ($classDistance as $class => $classData) {
        if (isset($classData['sex']) && isset($classData['dist'])) {
            foreach ($classData['sex'] as $sex) {
                foreach ($classData['dist'] as $distance) {
                    $key = "{$class}_{$sex}_{$distance}";
                    
                    $ageGroups = [];
                    if (isset($classData['age_group'])) {
                        foreach ($classData['age_group'] as $ageGroup) {
                            $ageGroups[] = [
                                'name' => $ageGroup,
                                'displayName' => getAgeGroupDisplayName($ageGroup)
                            ];
                        }
                    } else {
                        // Если возрастные группы не определены, создаем одну общую
                        $ageGroups[] = [
                            'name' => 'общая',
                            'displayName' => 'Общая группа'
                        ];
                    }
                    
                    $protocolsStructure[] = [
                        'key' => $key,
                        'class' => $class,
                        'sex' => $sex,
                        'distance' => $distance,
                        'ageGroups' => $ageGroups
                    ];
                }
            }
        }
    }
    
    return $protocolsStructure;
}

/**
 * Получение участников для группы
 */
function getParticipantsForGroup($db, $meroId, $discipline, $ageGroup) {
    // Получаем oid мероприятия
    $stmt = $db->prepare("SELECT oid FROM meros WHERE champn = ?");
    $stmt->execute([$meroId]);
    $mero = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$mero) {
        return [];
    }
    
    $stmt = $db->prepare("
        SELECT l.*, u.fio, u.birthdata, u.city, u.sportzvanie, u.sex, u.userid
        FROM listreg l 
        JOIN users u ON l.users_oid = u.oid 
        WHERE l.meros_oid = ? 
        AND l.status IN ('Подтверждён', 'Зарегистрирован')
        AND u.sex = ?
        AND l.discipline::text LIKE ?
    ");
    
    $classDistanceLike = '%"' . $discipline['class'] . '"%';
    $stmt->execute([$mero['oid'], $discipline['sex'], $classDistanceLike]);
    $allParticipants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $filteredParticipants = [];
    
    foreach ($allParticipants as $participant) {
        $classDistanceData = json_decode($participant['discipline'], true);
        
        if (isset($classDistanceData[$discipline['class']]['dist'])) {
            $distances = is_array($classDistanceData[$discipline['class']]['dist']) 
                ? $classDistanceData[$discipline['class']]['dist'] 
                : explode(', ', $classDistanceData[$discipline['class']]['dist']);
            
            $participatesInDistance = false;
            foreach ($distances as $distStr) {
                $distArray = array_map('trim', explode(',', $distStr));
                if (in_array($discipline['distance'], $distArray)) {
                    $participatesInDistance = true;
                    break;
                }
            }
            
            if ($participatesInDistance) {
                $birthYear = date('Y', strtotime($participant['birthdata']));
                $currentYear = date('Y');
                $age = $currentYear - $birthYear;
                
                // Используем полное название возрастной группы с названием и возрастным диапазоном
                $ageGroupName = isset($ageGroup['full_name']) ? $ageGroup['full_name'] : $ageGroup['name'];
                if (isInAgeGroup($age, $ageGroupName)) {
                    $filteredParticipants[] = [
                        'id' => $participant['userid'],
                        'fio' => $participant['fio'],
                        'birthYear' => $birthYear,
                        'age' => $age,
                        'city' => $participant['city'],
                        'sportzvanie' => $participant['sportzvanie'] ?? 'БР',
                        'ageGroup' => $ageGroup['displayName'],
                        'lane' => null,
                        'startNumber' => null,
                        'place' => null,
                        'finishTime' => null,
                        'finalTime' => null
                    ];
                }
            }
        }
    }
    
    return $filteredParticipants;
}

/**
 * Сохранение данных протокола в Redis
 */
function saveProtocolData($meroId, $groupKey, $participants, $type) {
    try {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379);
        
        $redisKey = "protocol_data:{$meroId}:{$groupKey}";
        $redis->setex($redisKey, 3600, json_encode($participants));
        
        // Также сохраняем в общий список протоколов мероприятия
        $eventProtocolsKey = "event_protocols:{$meroId}";
        $eventProtocols = $redis->get($eventProtocolsKey);
        $eventProtocols = $eventProtocols ? json_decode($eventProtocols, true) : [];
        
        $eventProtocols[$groupKey] = [
            'type' => $type,
            'participants_count' => count($participants),
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $redis->setex($eventProtocolsKey, 3600, json_encode($eventProtocols));
        
    } catch (Exception $e) {
        // Если Redis недоступен, сохраняем в JSON файл
        // Разбираем groupKey для получения отдельных параметров
        // groupKey имеет формат: "class_sex_distance_ageGroup"
        // Но distance может содержать запятые и пробелы
        
        // Находим последний символ подчеркивания (перед ageGroup)
        $lastUnderscorePos = strrpos($groupKey, '_');
        if ($lastUnderscorePos !== false) {
            $ageGroup = substr($groupKey, $lastUnderscorePos + 1);
            $prefix = substr($groupKey, 0, $lastUnderscorePos);
            
            // Теперь разбираем prefix: "class_sex_distance"
            $prefixParts = explode('_', $prefix, 3); // Максимум 3 части
            if (count($prefixParts) >= 3) {
                $class = $prefixParts[0];
                $sex = $prefixParts[1];
                $distance = $prefixParts[2];
                
                // Создаем структуру протокола
                $protocolData = [
                    'meroId' => $meroId,
                    'participants' => $participants,
                    'type' => $type,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                if ($type === 'start') {
                    saveProtocolDataToFile($class, $sex, $distance, $ageGroup, $protocolData);
                } else {
                    saveProtocolDataToFile($class, $sex, $distance, $ageGroup, null, $protocolData);
                }
            }
        }
    }
}

/**
 * Проверка принадлежности к возрастной группе
 */
function isInAgeGroup($age, $ageGroup) {
    switch ($ageGroup) {
        case 'группа 1':
            return $age >= 18 && $age <= 35;
        case 'группа 2':
            return $age >= 36 && $age <= 50;
        case 'группа 3':
            return $age >= 51 && $age <= 65;
        case 'группа Ю4':
            return $age >= 14 && $age <= 17;
        case 'общая':
            return true;
        default:
            return true;
    }
}

/**
 * Получение отображаемого названия возрастной группы
 */
function getAgeGroupDisplayName($ageGroup) {
    switch ($ageGroup) {
        case 'группа 1':
            return 'Мужчины (основная)';
        case 'группа 2':
            return 'Женщины (группа 2)';
        case 'группа 3':
            return 'Женщины (группа 3)';
        case 'группа Ю4':
            return 'Женщины (группа Ю4)';
        case 'общая':
            return 'Общая группа';
        default:
            return $ageGroup;
    }
}
?> 
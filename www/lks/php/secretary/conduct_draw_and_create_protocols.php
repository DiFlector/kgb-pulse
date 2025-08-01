<?php
/**
 * Проведение жеребьевки и создание протоколов
 */

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/protocol_utils.php";

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['meroId'])) {
        throw new Exception('Неверные параметры запроса');
    }
    
    $meroId = $input['meroId'];
    $db = Database::getInstance()->getPDO();
    
    // Получаем структуру протоколов
    $protocolsStructure = getProtocolsStructure($db, $meroId);
    
    if (empty($protocolsStructure)) {
        throw new Exception('Не найдены дисциплины для создания протоколов');
    }

    $createdCount = 0;
    $protocols = [];
    
    // Создаем протоколы для каждой дисциплины и возрастной группы
    foreach ($protocolsStructure as $discipline) {
        foreach ($discipline['ageGroups'] as $ageGroup) {
            // Получаем участников для данной группы
            $participants = getParticipantsForGroup($db, $meroId, $discipline, $ageGroup);
            
            if (!empty($participants)) {
                // Проводим жеребьевку
                $startProtocol = conductDrawForGroup($participants, $discipline, $ageGroup);
                
                // Создаем финишный протокол (копия стартового без результатов)
                $finishProtocol = createFinishProtocol($startProtocol);
                
                // Используем полное название возрастной группы с названием и возрастным диапазоном
                $ageGroupName = isset($ageGroup['full_name']) ? $ageGroup['full_name'] : $ageGroup['name'];
                
                // Сохраняем в файл
                saveProtocolDataToFile(
                    $discipline['class'],
                    $discipline['sex'],
                    $discipline['distance'],
                    $ageGroupName,
                    $startProtocol,
                    $finishProtocol
                );
                
                $protocols[] = [
                    'class' => $discipline['class'],
                    'sex' => $discipline['sex'],
                    'distance' => $discipline['distance'],
                    'ageGroup' => $ageGroupName,
                    'participantsCount' => count($participants)
                ];
                
                $createdCount++;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'count' => $createdCount,
        'protocols' => $protocols,
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
 * Проведение жеребьевки для группы
 */
function conductDrawForGroup($participants, $discipline, $ageGroup) {
    // Сортируем участников по возрасту (старшие сначала)
    usort($participants, function($a, $b) {
        return $b['age'] - $a['age'];
    });
    
    // Назначаем дорожки и стартовые номера
    $laneNumber = 1;
    $startNumber = 1;
    
    foreach ($participants as &$participant) {
        $participant['lane'] = $laneNumber;
        $participant['startNumber'] = $startNumber;
        $laneNumber++;
        $startNumber++;
    }
    
    // Создаем стартовый протокол
    $startProtocol = [
        'meroId' => $discipline['meroId'] ?? null,
        'class' => $discipline['class'],
        'sex' => $discipline['sex'],
        'distance' => $discipline['distance'],
        'ageGroup' => $ageGroup['displayName'],
        'type' => 'start',
        'participants' => $participants,
        'heats' => [
            [
                'ageGroup' => $ageGroup['displayName'],
                'heatType' => 'Финал',
                'heatNumber' => 1,
                'lanes' => createLanesArray($participants),
                'participantCount' => count($participants)
            ]
        ],
        'totalParticipants' => count($participants),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    return $startProtocol;
}

/**
 * Создание финишного протокола
 */
function createFinishProtocol($startProtocol) {
    $finishProtocol = $startProtocol;
    $finishProtocol['type'] = 'finish';
    
    // Очищаем результаты
    foreach ($finishProtocol['participants'] as &$participant) {
        $participant['finishTime'] = null;
        $participant['finalTime'] = null;
        $participant['place'] = null;
    }
    
    return $finishProtocol;
}

/**
 * Создание массива дорожек
 */
function createLanesArray($participants) {
    $lanes = [];
    $maxLanes = 9;
    
    for ($i = 1; $i <= $maxLanes; $i++) {
        $lanes[$i] = null;
    }
    
    foreach ($participants as $participant) {
        if (isset($participant['lane']) && $participant['lane'] <= $maxLanes) {
            $lanes[$participant['lane']] = $participant;
        }
    }
    
    return $lanes;
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
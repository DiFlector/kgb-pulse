<?php
/**
 * API для проведения жеребьевки участников с назначением номеров дорожек
 * Файл: www/lks/php/secretary/conduct_draw_new.php
 */

header('Content-Type: application/json; charset=utf-8');
session_start();

// Проверка прав доступа
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Secretary', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit();
}

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/protocol_utils.php";

try {
    $db = Database::getInstance();
    
    // Получаем данные из POST
    $input = json_decode(file_get_contents('php://input'), true);
    $meroId = $input['meroId'] ?? null;
    $groupKey = $input['groupKey'] ?? null;
    
    // Новые параметры для конкретной группы
    $class = $input['class'] ?? null;
    $sex = $input['sex'] ?? null;
    $distance = $input['distance'] ?? null;
    $ageGroup = $input['ageGroup'] ?? null;
    
    if (!$meroId) {
        throw new Exception('Не указан ID мероприятия');
    }
    
    // Получаем oid мероприятия
    $stmt = $db->prepare("SELECT oid, class_distance FROM meros WHERE champn = ?");
    $stmt->execute([$meroId]);
    $mero = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$mero) {
        throw new Exception('Мероприятие не найдено');
    }
    
    // Парсим структуру class_distance
    $classDistance = json_decode($mero['class_distance'], true);
    if (!$classDistance) {
        throw new Exception('Некорректная структура дисциплин');
    }
    
    $drawResults = [];
    $totalParticipants = 0;
    
    // Получаем всех зарегистрированных участников мероприятия
    $allParticipants = getAllRegisteredParticipants($db, $mero['oid']);
    
    if (empty($allParticipants)) {
        throw new Exception('Нет зарегистрированных участников для проведения жеребьевки');
    }
    
    // Распределяем участников по дисциплинам и возрастным группам
    $distributedParticipants = distributeParticipantsByDisciplines($allParticipants, $classDistance);
    
    // Проводим жеребьевку для каждой группы
    foreach ($distributedParticipants as $groupKey => $participants) {
        if (!empty($participants)) {
            $drawnParticipants = conductDrawForGroup($participants);
            
            // Разбираем groupKey для получения параметров
            $parts = explode('_', $groupKey);
            if (count($parts) >= 4) {
                $class = $parts[0];
                $sex = $parts[1];
                $distance = $parts[2];
                $ageGroup = implode('_', array_slice($parts, 3));
                
                $drawResults[] = [
                    'groupKey' => $groupKey,
                    'class' => $class,
                    'sex' => $sex,
                    'distance' => $distance,
                    'ageGroup' => $ageGroup,
                    'participants' => $drawnParticipants
                ];
                
                $totalParticipants += count($drawnParticipants);
            }
        }
    }
    
    // Сохраняем результаты жеребьевки в Redis и JSON файлы
    try {
        $redis = new Redis();
        $connected = $redis->connect('redis', 6379, 5);
        if ($connected) {
            // Сохраняем общие результаты жеребьевки в Redis
            $drawKey = "draw_results:{$meroId}";
            $redis->setex($drawKey, 86400, json_encode($drawResults));
            
            // Сохраняем данные для каждой группы отдельно
            foreach ($drawResults as $result) {
                if (isset($result['groupKey'])) {
                    $protocolKey = "protocol_data:{$meroId}:{$result['groupKey']}";
                    $redis->setex($protocolKey, 3600, json_encode($result));
                }
            }
        }
    } catch (Exception $e) {
        error_log("ОШИБКА Redis в conduct_draw_new.php: " . $e->getMessage());
    }
    
    // Сохраняем результаты в JSON файлы
    foreach ($drawResults as $result) {
        if (isset($result['groupKey'])) {
            $parts = explode('_', $result['groupKey']);
            if (count($parts) >= 4) {
                $class = $parts[0];
                $sex = $parts[1];
                $distance = $parts[2];
                $ageGroup = implode('_', array_slice($parts, 3));
                
                // Создаем структуру данных для сохранения
                $protocolData = [
                    'startProtocol' => [
                        'meroId' => $meroId,
                        'participants' => $result['participants'],
                        'type' => 'start',
                        'created_at' => date('Y-m-d H:i:s')
                    ],
                    'finishProtocol' => [
                        'meroId' => $meroId,
                        'participants' => $result['participants'],
                        'type' => 'finish',
                        'created_at' => date('Y-m-d H:i:s')
                    ]
                ];
                
                // Сохраняем в файл
                saveProtocolDataToFile($class, $sex, $distance, $ageGroup, $protocolData['startProtocol'], $protocolData['finishProtocol']);
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Жеребьевка проведена успешно для {$totalParticipants} участников",
        'results' => $drawResults,
        'totalParticipants' => $totalParticipants
    ]);
    
} catch (Exception $e) {
    error_log("ОШИБКА в conduct_draw_new.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Ошибка сервера: ' . $e->getMessage()
    ]);
}

/**
 * Получение участников для конкретной группы
 */
function getParticipantsForGroup($db, $meroId, $groupKey) {
    // Парсим groupKey для получения дисциплины и возрастной группы
    $parts = explode('_', $groupKey);
    if (count($parts) < 3) {
        return [];
    }
    
    $class = $parts[0];
    $sex = $parts[1];
    $distance = $parts[2];
    $ageGroup = implode('_', array_slice($parts, 3));
    
    // Получаем oid мероприятия
    $stmt = $db->prepare("SELECT oid FROM meros WHERE champn = ?");
    $stmt->execute([$meroId]);
    $mero = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$mero) {
        return [];
    }
    
    return getParticipantsForDiscipline($db, $mero['oid'], $class, $sex, $distance, ['name' => $ageGroup]);
}

/**
 * Получение участников для дисциплины и возрастной группы
 */
function getParticipantsForDiscipline($db, $meroOid, $class, $sex, $distance, $ageGroup) {
    $stmt = $db->prepare("
        SELECT l.*, u.fio, u.birthdata, u.city, u.sportzvanie, u.sex, u.userid
        FROM listreg l 
        JOIN users u ON l.users_oid = u.oid 
        WHERE l.meros_oid = ? 
        AND l.status IN ('Подтверждён', 'Зарегистрирован')
        AND u.sex = ?
        AND l.discipline::text LIKE ?
    ");
    
    $classDistanceLike = '%"' . $class . '"%';
    $stmt->execute([$meroOid, $sex, $classDistanceLike]);
    $allParticipants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $filteredParticipants = [];
    
    foreach ($allParticipants as $participant) {
        $classDistanceData = json_decode($participant['discipline'], true);
        
        if (isset($classDistanceData[$class]['dist'])) {
            $distances = is_array($classDistanceData[$class]['dist']) 
                ? $classDistanceData[$class]['dist'] 
                : explode(', ', $classDistanceData[$class]['dist']);
            
            $participatesInDistance = false;
            foreach ($distances as $distStr) {
                $distArray = array_map('trim', explode(',', $distStr));
                if (in_array($distance, $distArray)) {
                    $participatesInDistance = true;
                    break;
                }
            }
            
            if ($participatesInDistance) {
                $birthYear = date('Y', strtotime($participant['birthdata']));
                $currentYear = date('Y');
                $age = $currentYear - $birthYear;
                
                if (isset($ageGroup['minAge']) && isset($ageGroup['maxAge'])) {
                    if ($age >= $ageGroup['minAge'] && $age <= $ageGroup['maxAge']) {
                        $filteredParticipants[] = [
                            'id' => $participant['userid'],
                            'fio' => $participant['fio'],
                            'birthYear' => $birthYear,
                            'age' => $age,
                            'city' => $participant['city'],
                            'sportzvanie' => $participant['sportzvanie'] ?? 'БР',
                            'ageGroup' => $ageGroup['displayName'] ?? $ageGroup['name']
                        ];
                    }
                } else {
                    // Если возрастная группа не определена, включаем всех
                    $filteredParticipants[] = [
                        'id' => $participant['userid'],
                        'fio' => $participant['fio'],
                        'birthYear' => $birthYear,
                        'age' => $age,
                        'city' => $participant['city'],
                        'sportzvanie' => $participant['sportzvanie'] ?? 'БР',
                        'ageGroup' => $ageGroup['displayName'] ?? $ageGroup['name']
                    ];
                }
            }
        }
    }
    
    return $filteredParticipants;
}

/**
 * Проведение жеребьевки для группы участников
 */
function conductDrawForGroup($participants) {
    // Перемешиваем участников случайным образом
    shuffle($participants);
    
    // Назначаем номера дорожек (с 1 по 9)
    $lanes = range(1, 9);
    $drawnParticipants = [];
    
    foreach ($participants as $index => $participant) {
        $lane = isset($lanes[$index]) ? $lanes[$index] : null;
        $participant['lane'] = $lane;
        $participant['startNumber'] = $index + 1;
        $participant['place'] = null;
        $participant['finishTime'] = null;
        $participant['finalTime'] = null;
        $drawnParticipants[] = $participant;
    }
    
    return $drawnParticipants;
}

/**
 * Парсинг возрастных групп из строки
 */
function parseAgeGroups($ageGroupStr) {
    if (empty($ageGroupStr)) {
        return [];
    }
    
    $groups = [];
    $parts = explode(',', $ageGroupStr);
    
    foreach ($parts as $part) {
        $part = trim($part);
        if (preg_match('/группа\s*(\d+):\s*(\d+)-(\d+)/', $part, $matches)) {
            $groups[] = [
                'name' => "группа " . $matches[1],
                'minAge' => intval($matches[2]),
                'maxAge' => intval($matches[3]),
                'displayName' => "группа " . $matches[1] . " (" . $matches[2] . "-" . $matches[3] . ")"
            ];
        } elseif (preg_match('/([^:]+):\s*(\d+)-(\d+)/', $part, $matches)) {
            $groupName = trim($matches[1]);
            $groups[] = [
                'name' => $groupName,
                'minAge' => intval($matches[2]),
                'maxAge' => intval($matches[3]),
                'displayName' => $groupName . " (" . $matches[2] . "-" . $matches[3] . ")"
            ];
        }
    }
    
    return $groups;
}

/**
 * Получение всех зарегистрированных участников мероприятия
 */
function getAllRegisteredParticipants($db, $meroOid) {
    $stmt = $db->prepare("
        SELECT l.*, u.fio, u.birthdata, u.city, u.sportzvanie, u.sex, u.userid, t.teamname, t.teamcity
        FROM listreg l 
        JOIN users u ON l.users_oid = u.oid 
        LEFT JOIN teams t ON l.teams_oid = t.oid
        WHERE l.meros_oid = ? 
        AND l.status = 'Зарегистрирован'
    ");
    
    $stmt->execute([$meroOid]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedParticipants = [];
    foreach ($participants as $participant) {
        $birthYear = date('Y', strtotime($participant['birthdata']));
        $currentYear = date('Y');
        $age = $currentYear - $birthYear;
        
        $formattedParticipants[] = [
            'id' => $participant['userid'],
            'fio' => $participant['fio'],
            'birthYear' => $birthYear,
            'age' => $age,
            'city' => $participant['city'],
            'sportzvanie' => $participant['sportzvanie'] ?? 'БР',
            'sex' => $participant['sex'],
            'discipline' => $participant['discipline'],
            'teamname' => $participant['teamname'] ?? 'Б/к',
            'teamcity' => $participant['teamcity'] ?? ''
        ];
    }
    
    return $formattedParticipants;
}

/**
 * Распределение участников по дисциплинам и возрастным группам
 */
function distributeParticipantsByDisciplines($participants, $classDistance) {
    $distributed = [];
    
    foreach ($classDistance as $class => $data) {
        if (!isset($data['sex']) || !isset($data['dist']) || !isset($data['age_group'])) {
            continue;
        }
        
        $sexes = is_array($data['sex']) ? $data['sex'] : [$data['sex']];
        $distances = is_array($data['dist']) ? $data['dist'] : [$data['dist']];
        $ageGroups = is_array($data['age_group']) ? $data['age_group'] : [$data['age_group']];
        
        // Обрабатываем каждую дистанцию
        foreach ($distances as $distanceStr) {
            $individualDistances = array_map('trim', explode(',', $distanceStr));
            
            foreach ($individualDistances as $distance) {
                if (empty($distance)) continue;
                
                $distance = str_replace(['м', 'm'], '', trim($distance));
                
                foreach ($sexes as $sexIndex => $sex) {
                    $ageGroupStr = isset($ageGroups[$sexIndex]) ? $ageGroups[$sexIndex] : '';
                    $ageGroupsList = parseAgeGroups($ageGroupStr);
                    
                    // Сортируем возрастные группы по возрастанию
                    usort($ageGroupsList, function($a, $b) {
                        return $a['minAge'] - $b['minAge'];
                    });
                    
                    foreach ($ageGroupsList as $ageGroup) {
                        $groupKey = "{$class}_{$sex}_{$distance}_{$ageGroup['name']}";
                        $distributed[$groupKey] = [];
                        
                        // Фильтруем участников для данной группы
                        foreach ($participants as $participant) {
                            if ($participant['sex'] !== $sex) continue;
                            
                            // Проверяем, участвует ли участник в данной дисциплине
                            $classDistanceData = json_decode($participant['discipline'], true);
                            if (!isset($classDistanceData[$class]['dist'])) continue;
                            
                            $distances = is_array($classDistanceData[$class]['dist']) 
                                ? $classDistanceData[$class]['dist'] 
                                : explode(', ', $classDistanceData[$class]['dist']);
                            
                            $participatesInDistance = false;
                            foreach ($distances as $distStr) {
                                $distArray = array_map('trim', explode(',', $distStr));
                                if (in_array($distance, $distArray)) {
                                    $participatesInDistance = true;
                                    break;
                                }
                            }
                            
                            if ($participatesInDistance) {
                                // Проверяем возрастную группу
                                if (isset($ageGroup['minAge']) && isset($ageGroup['maxAge'])) {
                                    if ($participant['age'] >= $ageGroup['minAge'] && $participant['age'] <= $ageGroup['maxAge']) {
                                        $distributed[$groupKey][] = [
                                            'id' => $participant['id'],
                                            'fio' => $participant['fio'],
                                            'birthYear' => $participant['birthYear'],
                                            'age' => $participant['age'],
                                            'city' => $participant['city'],
                                            'sportzvanie' => $participant['sportzvanie'],
                                            'ageGroup' => $ageGroup['displayName'],
                                            'teamname' => $participant['teamname'],
                                            'teamcity' => $participant['teamcity']
                                        ];
                                    }
                                } else {
                                    // Если возрастная группа не определена, включаем всех
                                    $distributed[$groupKey][] = [
                                        'id' => $participant['id'],
                                        'fio' => $participant['fio'],
                                        'birthYear' => $participant['birthYear'],
                                        'age' => $participant['age'],
                                        'city' => $participant['city'],
                                        'sportzvanie' => $participant['sportzvanie'],
                                        'ageGroup' => $ageGroup['displayName'],
                                        'teamname' => $participant['teamname'],
                                        'teamcity' => $participant['teamcity']
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    
    return $distributed;
}
?> 
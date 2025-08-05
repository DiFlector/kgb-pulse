<?php
/**
 * Загрузка данных протоколов для секретаря
 * Файл: www/lks/php/secretary/load_protocols_data.php
 */

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/age_group_calculator.php";
require_once __DIR__ . "/../common/JsonProtocolManager.php";

if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}

// В режиме тестирования пропускаем проверку аутентификации
if (!defined('TEST_MODE')) {
    // Проверка прав доступа
    if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Secretary', 'SuperUser', 'Admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
        exit();
    }
}

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

try {
    // Получаем данные из POST запроса
    if (defined('TEST_MODE')) {
        $data = $_POST;
    } else {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
    }
    
    if (!$data) {
        throw new Exception('Неверные данные запроса');
    }
    
    // Проверяем обязательные поля
    if (!isset($data['meroId'])) {
        throw new Exception('Отсутствует обязательное поле: meroId');
    }
    
    $meroId = (int)$data['meroId'];
    $selectedDisciplines = $data['disciplines'] ?? null;
    
    if ($meroId <= 0) {
        throw new Exception('Неверный ID мероприятия');
    }
    
    error_log("🔄 [LOAD_PROTOCOLS_DATA] Загрузка протоколов для мероприятия $meroId");
    
    // Получаем данные мероприятия
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT * FROM meros WHERE oid = ?");
    $stmt->execute([$meroId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$event) {
        throw new Exception('Мероприятие не найдено');
    }
    
    // Парсим class_distance
    $classDistance = json_decode($event['class_distance'], true);
    if (!$classDistance) {
        throw new Exception('Ошибка чтения конфигурации классов');
    }
    
    // Инициализируем менеджер JSON протоколов
    $protocolManager = JsonProtocolManager::getInstance();
    
    $protocolsData = [];
    
    // Определяем порядок приоритета лодок
    $boatPriority = [
        'K-1' => 1,
        'K-2' => 2, 
        'K-4' => 3,
        'C-1' => 4,
        'C-2' => 5,
        'C-4' => 6,
        'D-10' => 7,
        'HD-1' => 8,
        'OD-1' => 9,
        'OD-2' => 10,
        'OC-1' => 11
    ];
    
    // Сортируем классы лодок по приоритету
    $sortedBoatClasses = array_keys($classDistance);
    usort($sortedBoatClasses, function($a, $b) use ($boatPriority) {
        $priorityA = $boatPriority[$a] ?? 999;
        $priorityB = $boatPriority[$b] ?? 999;
        return $priorityA - $priorityB;
    });
    
    error_log("🔄 [LOAD_PROTOCOLS_DATA] Сортировка лодок: " . implode(', ', $sortedBoatClasses));
    
    // Проходим по всем классам лодок в правильном порядке
    foreach ($sortedBoatClasses as $boatClass) {
        $config = $classDistance[$boatClass];
        $sexes = $config['sex'] ?? [];
        $distances = $config['dist'] ?? [];
        $ageGroups = $config['age_group'] ?? [];
        
        // Проходим по полам
        foreach ($sexes as $sexIndex => $sex) {
            $distance = $distances[$sexIndex] ?? '';
            $ageGroupStr = $ageGroups[$sexIndex] ?? '';
            
            if (!$distance || !$ageGroupStr) {
                continue;
            }
            
            // Разбиваем дистанции
            $distanceList = array_map('trim', explode(',', $distance));
            
            // Разбиваем возрастные группы
            $ageGroupList = array_map('trim', explode(',', $ageGroupStr));
            
            foreach ($distanceList as $dist) {
                // Проверяем, есть ли эта дисциплина в выбранных
                if ($selectedDisciplines && is_array($selectedDisciplines)) {
                    $disciplineFound = false;
                    
                    foreach ($selectedDisciplines as $selectedDiscipline) {
                        if (is_array($selectedDiscipline)) {
                            // Если дисциплина передана как объект
                            if ($selectedDiscipline['class'] === $boatClass && 
                                $selectedDiscipline['sex'] === $sex && 
                                $selectedDiscipline['distance'] === $dist) {
                                $disciplineFound = true;
                                break;
                            }
                        } else {
                            // Если дисциплина передана как строка
                            $disciplineString = "{$boatClass}_{$sex}_{$dist}";
                            if ($selectedDiscipline === $disciplineString) {
                                $disciplineFound = true;
                                break;
                            }
                        }
                    }
                    
                    // Если дисциплина не выбрана, пропускаем её
                    if (!$disciplineFound) {
                        continue;
                    }
                }
                
                foreach ($ageGroupList as $ageGroup) {
                    // Извлекаем название группы
                    if (preg_match('/^(.+?):\s*(\d+)-(\d+)$/', $ageGroup, $matches)) {
                        $groupName = trim($matches[1]);
                        $minAge = (int)$matches[2];
                        $maxAge = (int)$matches[3];
                        $fullGroupName = $ageGroup; // Полное название группы с диапазоном возрастов
                        
                        $redisKey = "protocol:{$meroId}:{$boatClass}:{$sex}:{$dist}:{$groupName}";
                        
                        // Проверяем, существует ли уже файл протокола
                        if ($protocolManager->protocolExists($redisKey)) {
                            // Загружаем существующий протокол
                            $existingData = $protocolManager->loadProtocol($redisKey);
                            if ($existingData) {
                                error_log("✅ [LOAD_PROTOCOLS_DATA] Загружен существующий протокол: $redisKey");
                                $protocolsData[] = [
                                    'meroId' => (int)$meroId,
                                    'discipline' => $boatClass,
                                    'sex' => $sex,
                                    'distance' => $dist,
                                    'ageGroups' => [$existingData],
                                    'created_at' => date('Y-m-d H:i:s')
                                ];
                                continue;
                            }
                        }
                        
                        // Получаем участников для этой группы
                        $participants = getParticipantsForGroup($db, $meroId, $boatClass, $sex, $dist, $minAge, $maxAge);
                        
                        // НЕ назначаем номера дорожек автоматически - жеребьевка должна быть ручной
                        // $participants = assignLanesToParticipants($participants, $boatClass);
                        
                        $ageGroupData = [
                            'name' => $fullGroupName, // Используем полное название группы с диапазоном возрастов
                            'protocol_number' => count($protocolsData) + 1,
                            'participants' => $participants,
                            'redisKey' => $redisKey,
                            'protected' => false
                        ];
                        
                        // Сохраняем данные в JSON файл
                        $protocolManager->saveProtocol($redisKey, $ageGroupData);
                        
                        $protocolsData[] = [
                            'meroId' => (int)$meroId,
                            'discipline' => $boatClass,
                            'sex' => $sex,
                            'distance' => $dist,
                            'ageGroups' => [$ageGroupData],
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                    }
                }
            }
        }
    }
    
    error_log("✅ [LOAD_PROTOCOLS_DATA] Загружено протоколов: " . count($protocolsData));
    
    echo json_encode([
        'success' => true,
        'protocols' => $protocolsData,
        'total_protocols' => count($protocolsData)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("❌ [LOAD_PROTOCOLS_DATA] Ошибка: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Получение участников для группы
 */
function getParticipantsForGroup($db, $meroId, $boatClass, $sex, $distance, $minAge, $maxAge) {
    $currentYear = date('Y');
    $yearEnd = $currentYear . '-12-31';
    
    $sql = "
        SELECT 
            u.oid, u.userid, u.fio, u.sex, u.birthdata, u.sportzvanie, u.city,
            t.teamname, t.teamcity
        FROM users u
        LEFT JOIN listreg lr ON u.oid = lr.users_oid
        LEFT JOIN teams t ON lr.teams_oid = t.oid
        WHERE lr.meros_oid = ?
        AND u.sex = ?
        AND u.accessrights = 'Sportsman'
        AND lr.status IN ('Зарегистрирован', 'Подтверждён')
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$meroId, $sex]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("🔍 Поиск участников для группы {$boatClass}_{$sex}_{$distance} (возраст {$minAge}-{$maxAge}): найдено " . count($participants) . " участников");
    
    $filteredParticipants = [];
    $addedCount = 0;
    
    foreach ($participants as $participant) {
        // Проверяем возраст
        $birthDate = new DateTime($participant['birthdata']);
        $yearEndDate = new DateTime($yearEnd);
        $age = $yearEndDate->diff($birthDate)->y;
        
        if ($age >= $minAge && $age <= $maxAge) {
            
            // Проверяем, что участник зарегистрирован на эту дисциплину
            $disciplineSql = "
                SELECT discipline 
                FROM listreg 
                WHERE users_oid = ? AND meros_oid = ?
            ";
            $disciplineStmt = $db->prepare($disciplineSql);
            $disciplineStmt->execute([$participant['oid'], $meroId]);
            $disciplineData = $disciplineStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($disciplineData) {
                $discipline = json_decode($disciplineData['discipline'], true);
                
                if ($discipline && isset($discipline[$boatClass])) {
                    $addedCount++;
                    
                    // Загружаем существующие номера дорожек из базы данных
                    $existingLane = null;
                    $existingWater = null;
                    if (isset($discipline[$boatClass]['lane'])) {
                        $existingLane = $discipline[$boatClass]['lane'];
                    }
                    if (isset($discipline[$boatClass]['water'])) {
                        $existingWater = $discipline[$boatClass]['water'];
                    }
                    
                    $filteredParticipants[] = [
                        'userId' => $participant['userid'],
                        'userid' => $participant['userid'], // Добавляем дублирующее поле для совместимости
                        'fio' => $participant['fio'],
                        'sex' => $participant['sex'],
                        'birthdata' => $participant['birthdata'],
                        'sportzvanie' => $participant['sportzvanie'],
                        'teamName' => $participant['teamname'] ?? '',
                        'teamCity' => $participant['teamcity'] ?? '',
                        'lane' => $existingLane, // Загружаем существующий номер дорожки
                        'water' => $existingWater ?? $existingLane, // Загружаем существующий номер воды
                        'place' => null,
                        'finishTime' => null,
                        'addedManually' => false,
                        'addedAt' => date('Y-m-d H:i:s')
                    ];
                }
            }
        }
    }
    
    error_log("✅ Группа {$boatClass}_{$sex}_{$distance} (возраст {$minAge}-{$maxAge}): добавлено {$addedCount} участников");
    
    return $filteredParticipants;
}

/**
 * Автоматическое назначение номеров дорожек участникам
 */
function assignLanesToParticipants($participants, $boatClass) {
    if (empty($participants)) {
        return $participants;
    }
    
    // Определяем максимальное количество дорожек для типа лодки
    $maxLanes = getMaxLanesForBoat($boatClass);
    
    // Проверяем, есть ли уже назначенные номера дорожек
    $hasExistingLanes = false;
    foreach ($participants as $participant) {
        if (isset($participant['lane']) && $participant['lane'] !== null && $participant['lane'] !== '') {
            $hasExistingLanes = true;
            error_log("🔄 [ASSIGN_LANES] Найден участник с существующим номером дорожки: {$participant['fio']} - lane={$participant['lane']}");
            break;
        }
    }
    
    // Если номера дорожек уже назначены, не изменяем их
    if ($hasExistingLanes) {
        error_log("🔄 [ASSIGN_LANES] Найдены существующие номера дорожек, сохраняем их");
        foreach ($participants as &$participant) {
            // Убеждаемся, что поле water также заполнено
            if (isset($participant['lane']) && !isset($participant['water'])) {
                $participant['water'] = $participant['lane'];
            } elseif (isset($participant['water']) && !isset($participant['lane'])) {
                $participant['lane'] = $participant['water'];
            }
        }
        return $participants;
    }
    
    // Перемешиваем участников для случайного распределения
    shuffle($participants);
    
    // Назначаем номера дорожек
    $laneNumber = 1;
    foreach ($participants as &$participant) {
        if ($laneNumber <= $maxLanes) {
            $participant['lane'] = $laneNumber;
            $participant['water'] = $laneNumber;
            $laneNumber++;
        } else {
            $participant['lane'] = null;
            $participant['water'] = null;
        }
    }
    
    error_log("🔄 [ASSIGN_LANES] Назначены номера дорожек для {$boatClass}: " . count($participants) . " участников");
    
    return $participants;
}

/**
 * Получение максимального количества дорожек для типа лодки
 */
function getMaxLanesForBoat($boatClass) {
    switch ($boatClass) {
        case 'K-1':
        case 'C-1':
        case 'HD-1':
        case 'OD-1':
            return 8; // 8 дорожек для одиночных лодок
        case 'K-2':
        case 'C-2':
        case 'OD-2':
            return 6; // 6 дорожек для парных лодок
        case 'K-4':
        case 'C-4':
        case 'OC-1':
            return 4; // 4 дорожки для четверок
        case 'D-10':
            return 3; // 3 дорожки для драконов
        default:
            return 8; // По умолчанию 8 дорожек
    }
}
?> 
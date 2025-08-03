<?php
/**
 * Загрузка данных протоколов для секретаря
 * Файл: www/lks/php/secretary/load_protocols_data.php
 */

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/age_group_calculator.php";

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
    error_log("🔄 [LOAD_PROTOCOLS_DATA] Запрос на загрузку данных протоколов");
    
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
    
    error_log("🔄 [LOAD_PROTOCOLS_DATA] Загрузка данных протоколов для мероприятия $meroId");
    error_log("🔄 [LOAD_PROTOCOLS_DATA] Выбранные дисциплины: " . json_encode($selectedDisciplines));
    
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
    
    // Подключаемся к Redis
    $redis = new Redis();
    try {
        $connected = $redis->connect('redis', 6379, 5);
        if (!$connected) {
            throw new Exception('Не удалось подключиться к Redis');
        }
    } catch (Exception $e) {
        error_log("Ошибка подключения к Redis: " . $e->getMessage());
        $redis = null;
    }
    
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
                        
                        $redisKey = "{$meroId}_{$boatClass}_{$sex}_{$dist}_{$groupName}";
                        
                        // Получаем участников для этой группы
                        $participants = getParticipantsForGroup($db, $meroId, $boatClass, $sex, $dist, $minAge, $maxAge);
                        
                        // Автоматически назначаем номера дорожек
                        $participants = assignLanesToParticipants($participants, $boatClass);
                        
                        $protocolsData[] = [
                            'meroId' => (int)$meroId,
                            'discipline' => $boatClass,
                            'sex' => $sex,
                            'distance' => $dist,
                            'ageGroups' => [
                                [
                                    'name' => $groupName,
                                    'protocol_number' => count($protocolsData) + 1,
                                    'participants' => $participants,
                                    'redisKey' => $redisKey,
                                    'protected' => false
                                ]
                            ],
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                    }
                }
            }
        }
    }
    
    error_log("✅ [LOAD_PROTOCOLS_DATA] Данные протоколов загружены успешно: " . count($protocolsData) . " протоколов");
    
    // Добавляем отладочную информацию о первом протоколе
    if (!empty($protocolsData)) {
        $firstProtocol = $protocolsData[0];
        error_log("Первый протокол: " . json_encode($firstProtocol));
        
        if (!empty($firstProtocol['ageGroups'])) {
            $firstAgeGroup = $firstProtocol['ageGroups'][0];
            error_log("Первая возрастная группа: " . json_encode($firstAgeGroup));
            
            if (!empty($firstAgeGroup['participants'])) {
                $firstParticipant = $firstAgeGroup['participants'][0];
                error_log("Первый участник: " . json_encode($firstParticipant));
            }
        }
    }
    
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
    
    error_log("Найдено участников для дисциплины {$boatClass}_{$sex}_{$distance}: " . count($participants));
    
    $filteredParticipants = [];
    
    foreach ($participants as $participant) {
        // Проверяем возраст
        $birthDate = new DateTime($participant['birthdata']);
        $yearEndDate = new DateTime($yearEnd);
        $age = $yearEndDate->diff($birthDate)->y;
        
        error_log("Участник {$participant['fio']}: возраст = {$age}, диапазон = {$minAge}-{$maxAge}");
        
        if ($age >= $minAge && $age <= $maxAge) {
            error_log("Участник {$participant['fio']} подходит по возрасту");
            
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
                error_log("Дисциплины участника {$participant['fio']}: " . json_encode($discipline));
                
                if ($discipline && isset($discipline[$boatClass])) {
                    error_log("Участник {$participant['fio']} зарегистрирован на дисциплину {$boatClass}");
                    $filteredParticipants[] = [
                        'userId' => $participant['userid'],
                        'userid' => $participant['userid'], // Добавляем дублирующее поле для совместимости
                        'fio' => $participant['fio'],
                        'sex' => $participant['sex'],
                        'birthdata' => $participant['birthdata'],
                        'sportzvanie' => $participant['sportzvanie'],
                        'teamName' => $participant['teamname'] ?? '',
                        'teamCity' => $participant['teamcity'] ?? '',
                        'lane' => null, // Будет назначен автоматически
                        'place' => null,
                        'finishTime' => null,
                        'addedManually' => false,
                        'addedAt' => date('Y-m-d H:i:s')
                    ];
                } else {
                    error_log("Участник {$participant['fio']} НЕ зарегистрирован на дисциплину {$boatClass}");
                }
            } else {
                error_log("Участник {$participant['fio']} не найден в listreg");
            }
        } else {
            error_log("Участник {$participant['fio']} НЕ подходит по возрасту");
        }
    }
    
    error_log("Отфильтровано участников для группы {$boatClass}_{$sex}_{$distance}_{$minAge}-{$maxAge}: " . count($filteredParticipants));
    
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
    
    // Перемешиваем участников для случайного распределения
    shuffle($participants);
    
    // Назначаем номера дорожек
    foreach ($participants as $index => &$participant) {
        $lane = ($index % $maxLanes) + 1;
        $participant['lane'] = $lane;
        $participant['water'] = $lane; // Добавляем поле "вода" для совместимости
    }
    
    return $participants;
}

/**
 * Определение максимального количества дорожек для типа лодки
 */
function getMaxLanesForBoat($boatClass) {
    switch ($boatClass) {
        case 'D-10':
            return 6; // Драконы - 6 дорожек
        case 'K-1':
        case 'C-1':
            return 9; // Одиночные - 9 дорожек
        case 'K-2':
        case 'C-2':
            return 9; // Двойки - 9 дорожек
        case 'K-4':
        case 'C-4':
            return 9; // Четверки - 9 дорожек
        default:
            return 9; // По умолчанию 9 дорожек
    }
}
?> 
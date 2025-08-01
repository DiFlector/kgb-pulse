<?php
session_start();
require_once __DIR__ . '/../common/Auth.php';
require_once __DIR__ . '/../db/Database.php';
require_once __DIR__ . '/../helpers.php';

// Проверка авторизации и прав доступа
$auth = new Auth();
if (!$auth->isAuthenticated() || !$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

// Получение данных из POST запроса
$input = json_decode(file_get_contents('php://input'), true);
$meroId = $input['meroId'] ?? null;
$disciplines = $input['disciplines'] ?? [];
$type = $input['type'] ?? 'start'; // start или finish

if (!$meroId) {
    echo json_encode(['success' => false, 'message' => 'Не указан meroId']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPDO();

try {
    $protocols = [];
    
    // Логируем для отладки
    error_log("Получение данных протоколов: meroId=$meroId, disciplines=" . json_encode($disciplines) . ", type=$type");

    // Если disciplines не указаны, получаем все протоколы из JSON файлов
    if (empty($disciplines)) {
        $jsonDir = __DIR__ . "/../../files/json/protocols/{$meroId}";
        if (is_dir($jsonDir)) {
            $files = glob("$jsonDir/*_{$type}.json");
            
            foreach ($files as $file) {
                $filename = basename($file);
                // Извлекаем groupKey из имени файла (убираем _start.json или _finish.json)
                $fullGroupKey = str_replace("_{$type}.json", '', $filename);
                
                // Убираем префикс "finish_" если есть
                $groupKey = preg_replace('/^finish_/', '', $fullGroupKey);
                
                $jsonData = file_get_contents($file);
                $protocolData = json_decode($jsonData, true);
                
                if ($protocolData) {
                    $protocols[$groupKey] = $protocolData;
                    error_log("Загружен протокол из файла: $filename -> $groupKey");
                }
            }
        }
    } else {
        // Если disciplines указаны, получаем только их
        foreach ($disciplines as $discipline) {
            // Проверяем формат дисциплины
            if (is_string($discipline)) {
                // Формат: "K-1_М_200" или "K-1_М_200_группа 1"
                $parts = explode('_', $discipline);
                if (count($parts) >= 3) {
                    $class = $parts[0];
                    $sex = $parts[1];
                    $distance = $parts[2];
                    $ageGroup = isset($parts[3]) ? $parts[3] : 'группа 1';
                } else {
                    continue; // Пропускаем некорректные дисциплины
                }
            } else if (is_array($discipline)) {
                // Формат объекта
                $class = $discipline['class'] ?? '';
                $sex = $discipline['sex'] ?? '';
                $distance = $discipline['distance'] ?? '';
                $ageGroup = $discipline['ageGroup'] ?? 'группа 1';
            } else {
                continue; // Пропускаем некорректные дисциплины
            }

            // Формируем ключ для группы с правильным форматом для Redis
            $normalizedSex = normalizeSexToEnglish($sex);
            $groupKey = "{$meroId}_{$class}_{$normalizedSex}_{$distance}_{$ageGroup}";
            
            // Получаем данные протокола из Redis/JSON
            $protocolData = getProtocolData($meroId, $groupKey, $type);
            
            // Если данных нет в Redis/JSON, загружаем участников из базы данных
            if (!$protocolData || empty($protocolData['participants'])) {
                error_log("Загружаем участников для дисциплины: $class $sex $distance $ageGroup");
                $participants = loadParticipantsFromDatabase($meroId, $class, $sex, $distance, $ageGroup);
                $protocolData = [
                    'participants' => $participants,
                    'drawConducted' => false,
                    'lastUpdated' => date('Y-m-d H:i:s')
                ];
            }
            
            // Синхронизируем стартовый и финишный протоколы
            if ($type === 'start' && $protocolData && !empty($protocolData['participants'])) {
                syncFinishProtocol($meroId, $groupKey, $protocolData);
            }
            
            // Если это финишный протокол и данных нет, создаем на основе стартового
            if ($type === 'finish' && (!$protocolData || empty($protocolData['participants']))) {
                $startProtocolData = getProtocolData($meroId, $groupKey, 'start');
                if ($startProtocolData && !empty($startProtocolData['participants'])) {
                    $protocolData = syncFinishProtocol($meroId, $groupKey, $startProtocolData);
                }
            }
            
            $protocols[$groupKey] = $protocolData;
        }
    }

    echo json_encode([
        'success' => true,
        'protocols' => $protocols
    ]);

} catch (Exception $e) {
    error_log("Ошибка получения данных протоколов: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера']);
}

/**
 * Получение данных протокола из Redis/JSON
 */
function getProtocolData($meroId, $groupKey, $type) {
    // Сначала пробуем получить из Redis
    $redis = new Redis();
    try {
        $redis->connect('redis', 6379);
        
        // Используем правильный формат ключа: protocol:groupKey_type
        $redisKey = "protocol:{$groupKey}_{$type}";
        $data = $redis->get($redisKey);
        
        if ($data) {
            $protocolData = json_decode($data, true);
            if ($protocolData) {
                error_log("Получены данные из Redis: $redisKey");
                return $protocolData;
            }
        }
    } catch (Exception $e) {
        error_log("Ошибка подключения к Redis: " . $e->getMessage());
    }

    // Если Redis недоступен или данных нет, пробуем JSON файл
    $jsonFilePath = __DIR__ . "/../../files/json/protocols/{$meroId}/{$groupKey}_{$type}.json";
    
    if (file_exists($jsonFilePath)) {
        $jsonData = file_get_contents($jsonFilePath);
        $protocolData = json_decode($jsonData, true);
        
        if ($protocolData) {
            error_log("Получены данные из JSON: $jsonFilePath");
            return $protocolData;
        }
    }
    
    // Пробуем с префиксом "finish_" для финишных протоколов
    if ($type === 'finish') {
        $finishJsonFilePath = __DIR__ . "/../../files/json/protocols/{$meroId}/finish_{$groupKey}_{$type}.json";
        
        if (file_exists($finishJsonFilePath)) {
            $jsonData = file_get_contents($finishJsonFilePath);
            $protocolData = json_decode($jsonData, true);
            
            if ($protocolData) {
                error_log("Получены данные из JSON с префиксом finish: $finishJsonFilePath");
                return $protocolData;
            }
        }
    }

    error_log("Данные не найдены для: meroId=$meroId, groupKey=$groupKey, type=$type");
    return null;
}

/**
 * Загрузка участников из базы данных для конкретной дисциплины
 */
function loadParticipantsFromDatabase($meroId, $class, $sex, $distance, $ageGroup) {
    global $pdo;
    
    try {
        // Сначала получаем данные мероприятия для расчета возрастных групп
        $meroQuery = "SELECT class_distance FROM meros WHERE oid = :meroId";
        $meroStmt = $pdo->prepare($meroQuery);
        $meroStmt->execute([':meroId' => $meroId]);
        $meroData = $meroStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$meroData || !$meroData['class_distance']) {
            error_log("Не найдены данные мероприятия для расчета возрастных групп");
            return [];
        }
        
        $classDistanceData = json_decode($meroData['class_distance'], true);
        if (!$classDistanceData || !isset($classDistanceData[$class])) {
            error_log("Не найдены данные class_distance для класса $class");
            return [];
        }
        
        $classData = $classDistanceData[$class];
        $sexes = $classData['sex'] ?? [];
        $ageGroups = $classData['age_group'] ?? [];
        
        // Находим индекс пола
        $sexIndex = array_search($sex, $sexes);
        if ($sexIndex === false) {
            error_log("Пол $sex не найден в списке полов для класса $class");
            return [];
        }
        
        // Получаем строку возрастных групп для данного пола
        $ageGroupString = $ageGroups[$sexIndex] ?? '';
        if (empty($ageGroupString)) {
            error_log("Не найдены возрастные группы для пола $sex в классе $class");
            return [];
        }
        
        // Разбираем возрастные группы
        $availableAgeGroups = array_map('trim', explode(',', $ageGroupString));
        error_log("Доступные возрастные группы для $class $sex: " . json_encode($availableAgeGroups));
        
        // Получаем всех участников мероприятия
        $query = "
            SELECT 
                lr.oid as registration_id,
                u.userid,
                u.fio,
                u.sex,
                u.birthdata,
                u.sportzvanie,
                lr.discipline,
                lr.status
            FROM listreg lr
            JOIN users u ON lr.users_oid = u.oid
            WHERE lr.meros_oid = :meroId
            AND lr.status IN ('Зарегистрирован', 'Подтверждён')
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([':meroId' => $meroId]);
        
        $participants = [];
        $laneNumber = 1;
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Парсим JSON дисциплину
            $disciplineData = json_decode($row['discipline'], true);
            
            if (!$disciplineData) {
                error_log("Не удалось распарсить JSON дисциплину: " . $row['discipline']);
                continue;
            }
            
            // Проверяем, зарегистрирован ли участник на нужную дисциплину
            $isRegisteredForDiscipline = false;
            foreach ($disciplineData as $disciplineClass => $disciplineInfo) {
                if ($disciplineClass === $class) {
                    $userSexes = $disciplineInfo['sex'] ?? [];
                    $userDistances = $disciplineInfo['dist'] ?? [];
                    
                    error_log("Проверяем участника {$row['fio']}: класс=$disciplineClass, полы=" . json_encode($userSexes) . ", дистанции=" . json_encode($userDistances));
                    error_log("Ищем: класс=$class, пол=$sex, дистанция=$distance");
                    
                    // Проверяем пол и дистанцию (дистанции в JSON хранятся как строки)
                    if (in_array($sex, $userSexes) && in_array($distance, $userDistances)) {
                        $isRegisteredForDiscipline = true;
                        error_log("Участник {$row['fio']} подходит для дисциплины $class $sex $distance");
                        break;
                    }
                }
            }
            
            if (!$isRegisteredForDiscipline) {
                error_log("Участник {$row['fio']} НЕ подходит для дисциплины $class $sex $distance");
                continue;
            }
            
            // Вычисляем возраст на 31.12 текущего года
            $birthYear = date('Y', strtotime($row['birthdata']));
            $currentYear = date('Y');
            $age = $currentYear - $birthYear;
            
            // Рассчитываем возрастную группу по алгоритму из class_distance
            $calculatedAgeGroup = calculateAgeGroupFromClassDistance($age, $sex, $availableAgeGroups);
            
            error_log("Участник {$row['fio']}: возраст=$age, рассчитанная группа=$calculatedAgeGroup, ищем группу=$ageGroup");
            
            // Проверяем, подходит ли участник к запрошенной возрастной группе
            // Сравниваем только имя группы и диапазон
            $agParts = explode(':', $ageGroup, 2);
            $calcParts = explode(':', $calculatedAgeGroup, 2);
            $agName = isset($agParts[0]) ? trim($agParts[0]) : '';
            $agRange = isset($agParts[1]) ? trim($agParts[1]) : '';
            $calcName = isset($calcParts[0]) ? trim($calcParts[0]) : '';
            $calcRange = isset($calcParts[1]) ? trim($calcParts[1]) : '';
            if ($agName === $calcName && $agRange === $calcRange) {
                $participants[] = [
                    'registration_id' => $row['registration_id'],
                    'userid' => $row['userid'],
                    'fio' => $row['fio'],
                    'sex' => $row['sex'],
                    'birthYear' => $birthYear,
                    'age' => $age,
                    'ageGroup' => $calculatedAgeGroup,
                    'sportzvanie' => $row['sportzvanie'],
                    'lane' => $laneNumber++,
                    'startTime' => null,
                    'finishTime' => null,
                    'place' => null
                ];
                error_log("Участник {$row['fio']} добавлен в группу $ageGroup");
            } else {
                error_log("Участник {$row['fio']} НЕ подходит для группы $ageGroup (его группа: $calculatedAgeGroup)");
            }
        }
        
        error_log("Загружено участников для $class $sex $distance $ageGroup: " . count($participants));
        return $participants;
        
    } catch (Exception $e) {
        error_log("Ошибка загрузки участников: " . $e->getMessage());
        return [];
    }
}

/**
 * Вычисление возрастной группы по алгоритму из class_distance
 */
function calculateAgeGroupFromClassDistance($age, $sex, $availableAgeGroups) {
    foreach ($availableAgeGroups as $ageGroupString) {
        // Разбираем группу: "группа 1: 18-29" -> ["группа 1", "18-29"]
        $parts = explode(': ', $ageGroupString);
        if (count($parts) !== 2) {
            continue;
        }
        
        $groupName = trim($parts[0]);
        $ageRange = trim($parts[1]);
        
        // Разбираем диапазон: "18-29" -> [18, 29]
        $ageLimits = explode('-', $ageRange);
        if (count($ageLimits) !== 2) {
            continue;
        }
        
        $minAge = (int)$ageLimits[0];
        $maxAge = (int)$ageLimits[1];
        
        // Проверяем, входит ли возраст в диапазон
        if ($age >= $minAge && $age <= $maxAge) {
            return $ageGroupString; // Возвращаем полное название группы
        }
    }
    
    // Если группа не найдена, возвращаем пустую строку
    return '';
}

/**
 * Вычисление возрастной группы (старая функция для совместимости)
 */
function calculateAgeGroup($age, $sex) {
    // Логика возрастных групп согласно структуре протоколов
    if ($age <= 10) return 'группа Дети: 0-10';
    if ($age <= 12) return 'группа Ю1: 11-12';
    if ($age <= 14) return 'группа Ю2: 13-14';
    if ($age <= 16) return 'группа Ю3: 15-16';
    if ($age <= 23) return 'группа Ю4: 17-23';
    if ($age <= 34) return 'группа 0: 24-34';
    if ($age <= 39) return 'группа 1: 35-39';
    if ($age <= 44) return 'группа 2: 40-44';
    if ($age <= 49) return 'группа 3: 45-49';
    if ($age <= 54) return 'группа 4: 50-54';
    if ($age <= 59) return 'группа 5: 55-59';
    if ($age <= 64) return 'группа 6: 60-64';
    if ($age <= 69) return 'группа 7: 65-69';
    if ($age <= 74) return 'группа 8: 70-74';
    if ($age <= 79) return 'группа 9: 75-79';
    return 'группа 10: 80-150';
}

/**
 * Синхронизация финишного протокола со стартовым
 */
function syncFinishProtocol($meroId, $groupKey, $startProtocolData) {
    // Создаем финишный протокол на основе стартового
    $finishProtocolData = $startProtocolData;
    $finishProtocolData['type'] = 'finish';
    
    // Добавляем поля для результатов к каждому участнику
    foreach ($finishProtocolData['participants'] as &$participant) {
        if (!isset($participant['place'])) {
            $participant['place'] = null;
        }
        if (!isset($participant['finishTime'])) {
            $participant['finishTime'] = null;
        }
    }
    
    // Сохраняем в Redis
    $redis = new Redis();
    try {
        $redis->connect('redis', 6379);
        $finishRedisKey = "protocol:{$groupKey}_finish";
        $redis->setex($finishRedisKey, 86400 * 7, json_encode($finishProtocolData));
        error_log("Синхронизирован финишный протокол в Redis: $finishRedisKey");
    } catch (Exception $e) {
        error_log("Ошибка синхронизации финишного протокола в Redis: " . $e->getMessage());
    }
    
    // Сохраняем в JSON файл
    $jsonDir = __DIR__ . "/../../files/json/protocols/{$meroId}";
    if (!is_dir($jsonDir)) {
        mkdir($jsonDir, 0777, true);
    }
    
    // Проверяем, существует ли уже файл с префиксом "finish_"
    $finishJsonFile = $jsonDir . "/finish_{$groupKey}_finish.json";
    if (!file_exists($finishJsonFile)) {
        // Если нет, создаем обычный файл
        $finishJsonFile = $jsonDir . "/{$groupKey}_finish.json";
    }
    
    file_put_contents($finishJsonFile, json_encode($finishProtocolData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    error_log("Синхронизирован финишный протокол в JSON: $finishJsonFile");
    
    // Возвращаем данные финишного протокола
    return $finishProtocolData;
} 
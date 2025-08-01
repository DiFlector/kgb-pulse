<?php
require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../helpers.php";
require_once __DIR__ . "/age_group_calculator.php";
require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}

// В режиме тестирования пропускаем проверку аутентификации
if (!defined('TEST_MODE')) {
    // Проверка прав доступа
    if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Secretary', 'SuperUser', 'Admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
        exit;
    }
}

// Получаем данные из POST-запроса
if (defined('TEST_MODE')) {
    $data = $_POST;
} else {
    $rawInput = file_get_contents('php://input');
    error_log("🔄 [GENERATE_PROTOCOLS] Сырые входящие данные: " . $rawInput);
    
    $data = json_decode($rawInput, true);
    
    // Если JSON не распарсился, пробуем получить из $_POST
    if ($data === null && !empty($_POST)) {
        $data = $_POST;
        error_log("🔄 [GENERATE_PROTOCOLS] Используем данные из $_POST: " . json_encode($data));
    }
}

// Логируем входящие данные для отладки
error_log("🔄 [GENERATE_PROTOCOLS] Обработанные входящие данные: " . json_encode($data));

// Проверяем обязательные параметры
if (!isset($data['meroId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Не указан ID мероприятия']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Подключаемся к Redis
    $redis = new Redis();
    try {
        $connected = $redis->connect('redis', 6379, 5); // 5 секунд таймаут
        if (!$connected) {
            throw new Exception('Не удалось подключиться к Redis');
        }
    } catch (Exception $e) {
        error_log("ОШИБКА подключения к Redis: " . $e->getMessage());
        // Продолжаем без Redis, но логируем ошибку
        $redis = null;
    }
    
    // Получаем информацию о мероприятии
    $stmt = $db->prepare("SELECT * FROM meros WHERE champn = ?");
    $stmt->execute([$data['meroId']]);
    $mero = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$mero) {
        throw new Exception('Мероприятие не найдено');
    }
    
    // Парсим структуру дисциплин с возрастными группами
    $classDistance = json_decode($mero['class_distance'], true);
    if (!$classDistance) {
        throw new Exception('Некорректная структура дисциплин в мероприятии');
    }
    
    error_log("🔄 [GENERATE_PROTOCOLS] Генерация протоколов для мероприятия: {$data['meroId']}");
    
    $allProtocols = [];
    $debugInfo = [];
    
    // Обрабатываем все дисциплины из class_distance
    foreach ($classDistance as $class => $disciplineData) {
        if (!isset($disciplineData['sex']) || !isset($disciplineData['dist']) || !isset($disciplineData['age_group'])) {
            continue;
        }
        
        $sexes = is_array($disciplineData['sex']) ? $disciplineData['sex'] : [$disciplineData['sex']];
        $distances = is_array($disciplineData['dist']) ? $disciplineData['dist'] : [$disciplineData['dist']];
        $ageGroups = is_array($disciplineData['age_group']) ? $disciplineData['age_group'] : [$disciplineData['age_group']];
        
        // Обрабатываем каждую дистанцию
        foreach ($distances as $distanceStr) {
            // Разбиваем строку дистанций на отдельные значения
            $individualDistances = explode(',', $distanceStr);
            
            foreach ($individualDistances as $distance) {
                $distance = trim($distance);
                
                // Обрабатываем каждый пол
                foreach ($sexes as $sexIndex => $sex) {
                    if (!isset($ageGroups[$sexIndex])) {
                        continue;
                    }
                    
                    $ageGroupString = $ageGroups[$sexIndex];
                    $parsedAgeGroups = AgeGroupCalculator::parseAgeGroups($ageGroupString);
                    
                    if (empty($parsedAgeGroups)) {
                        continue;
                    }
                    
                    error_log("🔄 [GENERATE_PROTOCOLS] Обрабатываем дисциплину: $class $sex {$distance}м");
                    
                    // Получаем участников для данной дисциплины
                    $participants = getParticipantsForDiscipline($db, $data['meroId'], $class, $sex, $distance);
                    
                    error_log("🔄 [GENERATE_PROTOCOLS] Участников найдено: " . count($participants));
                    
                    // Создаем протокол для каждой возрастной группы
                    foreach ($parsedAgeGroups as $ageGroupIndex => $ageGroup) {
                        $ageGroupName = $ageGroup['full_name'];
                        
                        // Фильтруем участников по возрастной группе
                        $ageGroupParticipants = [];
                        foreach ($participants as $participant) {
                            $age = AgeGroupCalculator::calculateAgeOnDecember31($participant['birthdata']);
                            if ($age !== null && $age >= $ageGroup['min_age'] && $age <= $ageGroup['max_age']) {
                                $ageGroupParticipants[] = $participant;
                            }
                        }
                        
                        // Создаем протокол даже если нет участников (пустой протокол)
                        $protocol = createProtocolForAgeGroup(
                            $redis,
                            $data['meroId'], 
                            $class, 
                            $sex, 
                            $distance, 
                            $ageGroupName,
                            $ageGroupParticipants, 
                            'start',
                            $ageGroupIndex + 1
                        );
                        
                        if ($protocol) {
                            $allProtocols[] = $protocol;
                            $participantCount = count($ageGroupParticipants);
                            $debugInfo[] = "✅ $class $sex {$distance}м - $ageGroupName: $participantCount участников";
                        }
                    }
                }
            }
        }
    }
    
    error_log("🔄 [GENERATE_PROTOCOLS] Всего создано протоколов: " . count($allProtocols));
    
    echo json_encode([
        'success' => true,
        'message' => 'Протоколы успешно сгенерированы',
        'protocols' => $allProtocols,
        'debug' => [
            'totalProtocols' => count($allProtocols),
            'disciplineDetails' => $debugInfo
        ]
    ]);
    
} catch (Exception $e) {
    error_log("ОШИБКА генерации протоколов: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Получение участников для дисциплины
 */
function getParticipantsForDiscipline($db, $meroId, $class, $sex, $distance) {
    // Сначала получаем oid мероприятия
    $stmt = $db->prepare("SELECT oid FROM meros WHERE champn = ?");
    $stmt->execute([$meroId]);
    $meroOid = $stmt->fetchColumn();
    
    if (!$meroOid) {
        error_log("⚠️ [GENERATE_PROTOCOLS] Мероприятие с champn = $meroId не найдено");
        return [];
    }
    
    $stmt = $db->prepare("
        SELECT l.*, u.fio, u.birthdata, u.city, u.sportzvanie, u.sex, u.userid, u.country,
               t.teamname, t.teamcity
        FROM listreg l 
        JOIN users u ON l.users_oid = u.oid 
        LEFT JOIN teams t ON l.teams_oid = t.oid
        WHERE l.meros_oid = ? 
        AND l.status = 'Зарегистрирован'
        AND u.sex = ?
        AND l.discipline::text LIKE ?
    ");
    
    $classDistanceLike = '%"' . $class . '"%';
    $stmt->execute([$meroOid, $sex, $classDistanceLike]);
    
    $allParticipants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $filteredParticipants = [];
    
    error_log("🔄 [GENERATE_PROTOCOLS] Найдено участников для $class $sex {$distance}м: " . count($allParticipants));
    
    // Дополнительная фильтрация по дистанции
    foreach ($allParticipants as $participant) {
        $classDistanceData = json_decode($participant['discipline'], true);
        
        if (isset($classDistanceData[$class]['dist'])) {
            $distances = is_array($classDistanceData[$class]['dist']) 
                ? $classDistanceData[$class]['dist'] 
                : explode(', ', $classDistanceData[$class]['dist']);
            
            foreach ($distances as $dist) {
                if (trim($dist) == $distance) {
                    $filteredParticipants[] = $participant;
                    break;
                }
            }
        }
    }
    
    return $filteredParticipants;
}

/**
 * Создание протокола для возрастной группы
 */
function createProtocolForAgeGroup($redis, $meroId, $class, $sex, $distance, $ageGroup, $participants, $type, $protocolNumber = 1) {
    // Проводим жеребьевку
    $drawnParticipants = conductDrawForProtocol($participants);
    
    // Формируем ключ Redis с возрастной группой
    $ageGroupKey = str_replace(['(', ')', ' ', ':', '-'], ['', '', '_', '_', '_'], $ageGroup);
    $redisKey = "protocol:{$type}:{$meroId}:{$class}:{$sex}:{$distance}:{$ageGroupKey}";
    
    // Структура протокола в формате example.json
    $protocolData = [
        'meroId' => (int)$meroId,
        'discipline' => $class,
        'sex' => $sex,
        'distance' => $distance,
        'ageGroups' => [
            [
                'name' => $ageGroup,
                'protocol_number' => $protocolNumber,
                'participants' => $drawnParticipants,
                'redisKey' => $redisKey,
                'protected' => false
            ]
        ],
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    // Сохраняем в Redis (если доступен)
    if ($redis) {
        try {
            $redis->setex($redisKey, 86400 * 7, json_encode($protocolData)); // Храним 7 дней
            error_log("✅ [GENERATE_PROTOCOLS] Протокол сохранен в Redis: $redisKey");
        } catch (Exception $e) {
            error_log("⚠️ [GENERATE_PROTOCOLS] Ошибка сохранения в Redis: " . $e->getMessage());
        }
    } else {
        error_log("⚠️ [GENERATE_PROTOCOLS] Redis недоступен, протокол не сохранен в кэше");
    }
    
    // Создаем JSON файл
    try {
        createJsonProtocol($protocolData, $meroId, $class, $sex, $distance, $ageGroupKey);
    } catch (Exception $e) {
        error_log("⚠️ [GENERATE_PROTOCOLS] Ошибка создания JSON: " . $e->getMessage());
    }
    
    return [
        'redisKey' => $redisKey,
        'discipline' => $class,
        'sex' => $sex,
        'distance' => $distance,
        'ageGroup' => $ageGroup,
        'participantsCount' => count($drawnParticipants),
        'type' => $type,
        'protocolNumber' => $protocolNumber,
        'file' => "/lks/files/json/protocols/{$type}_{$meroId}_{$class}_{$sex}_{$distance}_{$ageGroupKey}.json"
    ];
}

/**
 * Проведение жеребьевки участников
 */
function conductDrawForProtocol($participants) {
    $drawnParticipants = [];
    $lane = 1;
    
    if (empty($participants)) {
        error_log("⚠️ [GENERATE_PROTOCOLS] Нет участников для жеребьевки");
        return [];
    }
    
    // Перемешиваем участников
    shuffle($participants);
    
    foreach ($participants as $participant) {
        $drawnParticipants[] = [
            'userId' => (int)($participant['userid'] ?? 0),
            'fio' => $participant['fio'] ?? 'Не указано',
            'sex' => $participant['sex'] ?? '',
            'birthdata' => $participant['birthdata'] ?? '',
            'sportzvanie' => $participant['sportzvanie'] ?? 'БР',
            'teamName' => $participant['teamname'] ?? '',
            'teamCity' => $participant['teamcity'] ?? '',
            'lane' => $lane++,
            'place' => null,
            'finishTime' => null,
            'addedManually' => false,
            'addedAt' => date('Y-m-d H:i:s')
        ];
    }
    
    error_log("🔄 [GENERATE_PROTOCOLS] Жеребьевка проведена для " . count($drawnParticipants) . " участников");
    
    return $drawnParticipants;
}

/**
 * Создание JSON файла протокола
 */
function createJsonProtocol($protocolData, $meroId, $class, $sex, $distance, $ageGroupKey) {
    $fileName = "{$meroId}_{$class}_{$sex}_{$distance}_{$ageGroupKey}.json";
    $filePath = __DIR__ . "/../../files/json/protocols/$fileName";
    
    // Создаем .gitkeep файл чтобы папка сохранялась в git
    $gitkeepFile = __DIR__ . "/../../files/json/protocols/.gitkeep";
    if (!file_exists($gitkeepFile)) {
        file_put_contents($gitkeepFile, "");
    }
    
    // Проверяем и создаем директорию
    $dir = dirname($filePath);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0777, true)) {
            error_log("⚠️ [GENERATE_PROTOCOLS] Не удалось создать директорию: $dir");
            return null;
        }
    }
    
    // Проверяем права на запись
    if (!is_writable($dir)) {
        error_log("⚠️ [GENERATE_PROTOCOLS] Нет прав на запись в директорию: $dir");
        return null;
    }
    
    // Создаем JSON файл
    if (file_put_contents($filePath, json_encode($protocolData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
        error_log("⚠️ [GENERATE_PROTOCOLS] Не удалось создать файл: $filePath");
        return null;
    }
    
    error_log("✅ [GENERATE_PROTOCOLS] Создан JSON файл протокола: $fileName");
    return $fileName;
}
?> 
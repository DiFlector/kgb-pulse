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
    error_log("🔄 [LOAD_PROTOCOLS_DATA] Сырые входящие данные: " . $rawInput);
    
    $data = json_decode($rawInput, true);
    
    // Если JSON не распарсился, пробуем получить из $_POST
    if ($data === null && !empty($_POST)) {
        $data = $_POST;
        error_log("🔄 [LOAD_PROTOCOLS_DATA] Используем данные из $_POST: " . json_encode($data));
    }
}

// Логируем входящие данные для отладки
error_log("🔄 [LOAD_PROTOCOLS_DATA] Обработанные входящие данные: " . json_encode($data));

// Проверяем обязательные параметры
if (!isset($data['meroId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Не указан ID мероприятия']);
    exit;
}

try {
    $db = Database::getInstance();
    
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
    
    error_log("🔄 [LOAD_PROTOCOLS_DATA] Загрузка данных протоколов для мероприятия: {$data['meroId']}");
    
    // Загружаем данные из protocols_1.json
    $protocolsFile = __DIR__ . "/../../files/json/protocols/protocols_{$data['meroId']}.json";
    
    if (!file_exists($protocolsFile)) {
        // Если файл не существует, создаем его с данными всех зарегистрированных участников
        $protocolsData = generateProtocolsFromParticipants($db, $data['meroId'], $classDistance);
        file_put_contents($protocolsFile, json_encode($protocolsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        error_log("✅ [LOAD_PROTOCOLS_DATA] Создан файл protocols_{$data['meroId']}.json");
    } else {
        // Загружаем существующий файл
        $content = file_get_contents($protocolsFile);
        $protocolsData = json_decode($content, true);
        error_log("✅ [LOAD_PROTOCOLS_DATA] Загружен файл protocols_{$data['meroId']}.json");
    }
    
    if (!$protocolsData) {
        throw new Exception('Ошибка загрузки данных протоколов');
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Данные протоколов успешно загружены',
        'protocols' => $protocolsData,
        'debug' => [
            'totalProtocols' => count($protocolsData),
            'filePath' => $protocolsFile
        ]
    ]);
    
} catch (Exception $e) {
    error_log("ОШИБКА загрузки данных протоколов: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Генерация протоколов из участников
 */
function generateProtocolsFromParticipants($db, $meroId, $classDistance) {
    // Получаем oid мероприятия
    $stmt = $db->prepare("SELECT oid FROM meros WHERE champn = ?");
    $stmt->execute([$meroId]);
    $meroOid = $stmt->fetchColumn();
    
    if (!$meroOid) {
        throw new Exception('Мероприятие не найдено');
    }
    
    $protocols = [];
    
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
                    
                    // Получаем участников для данной дисциплины
                    $participants = getParticipantsForDiscipline($db, $meroOid, $class, $sex, $distance);
                    
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
                        
                        // Проводим жеребьевку
                        $drawnParticipants = conductDrawForProtocol($ageGroupParticipants);
                        
                        // Создаем протокол
                        $protocol = [
                            'meroId' => (int)$meroId,
                            'discipline' => $class,
                            'sex' => $sex,
                            'distance' => $distance,
                            'ageGroups' => [
                                [
                                    'name' => $ageGroupName,
                                    'protocol_number' => $ageGroupIndex + 1,
                                    'participants' => $drawnParticipants,
                                    'redisKey' => "protocol:start:{$meroId}:{$class}:{$sex}:{$distance}:" . str_replace(['(', ')', ' ', ':', '-'], ['', '', '_', '_', '_'], $ageGroupName),
                                    'protected' => false
                                ]
                            ],
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                        
                        $protocols[] = $protocol;
                    }
                }
            }
        }
    }
    
    return $protocols;
}

/**
 * Получение участников для дисциплины
 */
function getParticipantsForDiscipline($db, $meroOid, $class, $sex, $distance) {
    $stmt = $db->prepare("
        SELECT l.*, u.fio, u.birthdata, u.city, u.sportzvanie, u.sex, u.userid, u.country,
               t.teamname, t.teamcity
        FROM listreg l 
        JOIN users u ON l.users_oid = u.oid 
        LEFT JOIN teams t ON l.teams_oid = t.oid
        WHERE l.meros_oid = ? 
        AND l.status IN ('Подтверждён', 'Зарегистрирован')
        AND u.sex = ?
        AND l.discipline::text LIKE ?
    ");
    
    $classDistanceLike = '%"' . $class . '"%';
    $stmt->execute([$meroOid, $sex, $classDistanceLike]);
    
    $allParticipants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $filteredParticipants = [];
    
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
 * Проведение жеребьевки участников
 */
function conductDrawForProtocol($participants) {
    $drawnParticipants = [];
    $lane = 1;
    
    if (empty($participants)) {
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
    
    return $drawnParticipants;
}
?> 
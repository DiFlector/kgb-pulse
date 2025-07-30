<?php
session_start();
require_once __DIR__ . '/../common/Auth.php';
require_once __DIR__ . '/../db/Database.php';

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

if (!$meroId) {
    echo json_encode(['success' => false, 'message' => 'Не указан ID мероприятия']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPDO();

try {
    // Получаем информацию о мероприятии
    $stmt = $pdo->prepare("SELECT class_distance FROM meros WHERE oid = ?");
    $stmt->execute([$meroId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        echo json_encode(['success' => false, 'message' => 'Мероприятие не найдено']);
        exit;
    }

    $classDistance = json_decode($event['class_distance'], true);
    
    if (!$classDistance) {
        echo json_encode(['success' => false, 'message' => 'Нет данных о дисциплинах']);
        exit;
    }

    // Получаем всех зарегистрированных участников мероприятия
    $participants = getRegisteredParticipants($pdo, $meroId);
    
    if (empty($participants)) {
        echo json_encode(['success' => false, 'message' => 'Нет зарегистрированных участников']);
        exit;
    }

    // Очищаем предыдущие данные протоколов для этого мероприятия
    clearPreviousProtocolData($meroId);
    
    // Распределяем участников по группам
    $groupedParticipants = distributeParticipantsByGroups($participants, $classDistance, $meroId);
    
    // Сохраняем данные протоколов
    saveProtocolsData($meroId, $groupedParticipants);

    // Подготавливаем данные для ответа
    $responseData = [];
    foreach ($groupedParticipants as $groupKey => $data) {
        // Возвращаем полную структуру данных, а не только массив участников
        $responseData[$groupKey] = $data;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Жеребьевка проведена успешно',
        'totalParticipants' => count($participants),
        'totalGroups' => count($groupedParticipants),
        'protocols' => $responseData
    ]);

} catch (Exception $e) {
    error_log("Ошибка проведения жеребьевки: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера: ' . $e->getMessage()]);
}

/**
 * Получение всех зарегистрированных участников мероприятия
 */
function getRegisteredParticipants($pdo, $meroId) {
    $stmt = $pdo->prepare("
        SELECT 
            u.oid as id,
            u.userid,
            u.fio,
            u.sex,
            u.birthdata,
            u.sportzvanie,
            u.boats,
            lr.discipline,
            lr.status,
            t.teamname,
            t.teamcity
        FROM listreg lr
        JOIN users u ON lr.users_oid = u.oid
        LEFT JOIN teams t ON lr.teams_oid = t.oid
        WHERE lr.meros_oid = ? 
        AND lr.status IN ('Подтверждён', 'Зарегистрирован')
        ORDER BY u.fio
    ");
    
    $stmt->execute([$meroId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Распределение участников по группам
 */
function distributeParticipantsByGroups($participants, $classDistance, $meroId) {
    $groupedParticipants = [];

    foreach ($classDistance as $class => $classData) {
        if (isset($classData['sex']) && isset($classData['dist']) && isset($classData['age_group'])) {
            $sexes = $classData['sex'];
            $distances = $classData['dist'];
            $ageGroups = $classData['age_group'];

            // Обрабатываем каждую комбинацию пол/дистанция
            for ($i = 0; $i < count($sexes); $i++) {
                $sex = $sexes[$i];
                $distanceString = $distances[$i];
                $ageGroupString = $ageGroups[$i];

                // Разбираем дистанции (разделяем по запятой)
                $distanceArray = array_map('trim', explode(',', $distanceString));
                
                // Разбираем возрастные группы (разделяем по запятой)
                $ageGroupArray = array_map('trim', explode(',', $ageGroupString));

                // Создаем протокол для каждой комбинации дистанция + возрастная группа
                foreach ($distanceArray as $distance) {
                    foreach ($ageGroupArray as $ageGroup) {
                        // Разбираем возрастную группу
                        $ageGroupData = parseAgeGroup($ageGroup);

                        foreach ($ageGroupData as $ageGroupInfo) {
                            // Нормализуем пол для groupKey (используем латиницу)
                            $normalizedSex = $sex === 'М' ? 'M' : $sex;
                            $groupKey = "{$meroId}_{$class}_{$normalizedSex}_{$distance}_{$ageGroupInfo['name']}";
                            
                            // Фильтруем участников по полу, возрасту и дисциплине
                            $groupParticipants = filterParticipantsForGroup($participants, $class, $sex, $ageGroupInfo);
                            
                            if (!empty($groupParticipants)) {
                                // Проводим жеребьевку (случайное распределение номеров воды)
                                $groupParticipants = conductDrawForGroup($groupParticipants, $class);
                                
                                $groupedParticipants[$groupKey] = [
                                    'participants' => $groupParticipants,
                                    'drawConducted' => true,
                                    'lastUpdated' => date('Y-m-d H:i:s'),
                                    'totalParticipants' => count($groupParticipants)
                                ];
                            }
                            // Не создаем группы без участников - они будут пустыми в интерфейсе
                        }
                    }
                }
            }
        }
    }

    return $groupedParticipants;
}

/**
 * Фильтрация участников для конкретной группы
 */
function filterParticipantsForGroup($participants, $class, $sex, $ageGroupInfo) {
    $filtered = [];
    
    foreach ($participants as $participant) {
        // Проверяем пол
        if ($participant['sex'] !== $sex && !($participant['sex'] === 'М' && $sex === 'M') && !($participant['sex'] === 'M' && $sex === 'М')) {
            continue;
        }
        
        // Проверяем возраст
        $birthYear = (int)substr($participant['birthdata'], 0, 4);
        $currentYear = (int)date('Y');
        $age = $currentYear - $birthYear;
        
        if ($age < $ageGroupInfo['minAge'] || $age > $ageGroupInfo['maxAge']) {
            continue;
        }
        
        // Проверяем, что участник зарегистрирован на эту дисциплину
        $discipline = json_decode($participant['discipline'], true);
        if ($discipline && isset($discipline[$class])) {
            $filtered[] = [
                'id' => $participant['id'],
                'userid' => $participant['userid'],
                'fio' => $participant['fio'],
                'birthdata' => $participant['birthdata'],
                'birthYear' => $birthYear,
                'age' => $age,
                'ageGroup' => $ageGroupInfo['displayName'],
                'sportzvanie' => $participant['sportzvanie'],
                'sportRank' => $participant['sportzvanie'],
                'team' => $participant['teamname'] ?? 'Б/к',
                'teamCity' => $participant['teamcity'] ?? '',
                'teamName' => $participant['teamname'] ?? '',
                'athleteNumber' => $participant['userid']
            ];
        }
    }
    
    return $filtered;
}

/**
 * Проведение жеребьевки для группы участников
 */
function conductDrawForGroup($participants, $boatClass = '') {
    // Перемешиваем участников случайным образом
    shuffle($participants);
    
    // Определяем максимальное количество дорожек в зависимости от типа лодки
    $maxLanes = getMaxLanesForBoat($boatClass);
    
    // Назначаем номера воды с учетом ограничений
    foreach ($participants as $index => $participant) {
        $waterNumber = ($index % $maxLanes) + 1;
        $participants[$index]['waterNumber'] = $waterNumber;
        $participants[$index]['water'] = $waterNumber; // Добавляем поле water для совместимости с JavaScript
    }
    
    return $participants;
}

/**
 * Получение максимального количества дорожек для типа лодки
 * @param string $boatClass Класс лодки (D-10, K-1, C-2, etc.)
 * @return int Максимальное количество дорожек
 */
function getMaxLanesForBoat($boatClass) {
    // Драконы: 6 дорожек
    if ($boatClass === 'D-10') {
        return 6;
    }
    
    // Остальные лодки: 9 дорожек
    return 9;
}

/**
 * Сохранение данных протоколов в Redis и JSON
 */
function saveProtocolsData($meroId, $groupedParticipants) {
    // Создаем директорию для JSON файлов
    $jsonDir = __DIR__ . "/../../files/json/protocols/{$meroId}";
    if (!is_dir($jsonDir)) {
        mkdir($jsonDir, 0755, true);
    }

    // Сохраняем в Redis и JSON
    foreach ($groupedParticipants as $groupKey => $data) {
        // Сохраняем стартовые протоколы
        saveProtocolData($meroId, $groupKey, 'start', $data);
        
        // Создаем финишные протоколы с синхронизированными данными
        $finishData = createFinishProtocolData($data);
        saveProtocolData($meroId, $groupKey, 'finish', $finishData);
        
        error_log("✅ [PROTOCOLS] Созданы протоколы для группы {$groupKey}: стартовый и финишный");
    }
}

/**
 * Создание финишных данных протокола на основе стартовых
 * @param array $startData Данные стартового протокола
 * @return array Данные финишного протокола
 */
function createFinishProtocolData($startData) {
    $finishData = $startData;
    
    // Добавляем поля для результатов в финишный протокол
    foreach ($finishData['participants'] as &$participant) {
        // Сохраняем все данные из стартового протокола
        // Добавляем поля для результатов
        $participant['place'] = '';
        $participant['finishTime'] = '';
        $participant['notes'] = '';
        
        // Убеждаемся, что все ключевые поля синхронизированы
        $requiredFields = ['userid', 'fio', 'birthdata', 'ageGroup', 'sportRank', 'waterNumber', 'water', 'team'];
        foreach ($requiredFields as $field) {
            if (!isset($participant[$field])) {
                $participant[$field] = '';
            }
        }
        
        // Убеждаемся, что поле water синхронизировано с waterNumber
        if (isset($participant['waterNumber']) && !isset($participant['water'])) {
            $participant['water'] = $participant['waterNumber'];
        }
    }
    
    return $finishData;
}

/**
 * Сохранение данных протокола
 */
function saveProtocolData($meroId, $groupKey, $type, $data) {
    // Сохраняем в Redis
    $redis = new Redis();
    try {
        $redis->connect('redis', 6379);
        
        // Извлекаем oid из groupKey (первая часть до первого подчеркивания)
        $parts = explode('_', $groupKey, 2);
        $eventOid = $parts[0];
        $restOfKey = $parts[1] ?? '';
        
        $redisKey = "protocols:{$type}:{$eventOid}:{$restOfKey}";
        $redis->setex($redisKey, 86400, json_encode($data)); // TTL 24 часа
    } catch (Exception $e) {
        error_log("Ошибка сохранения в Redis: " . $e->getMessage());
    }

    // Сохраняем в JSON файл
    $jsonFilePath = __DIR__ . "/../../files/json/protocols/{$meroId}/{$groupKey}_{$type}.json";
    file_put_contents($jsonFilePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Разбор строки возрастной группы
 */
function parseAgeGroup($ageGroupString) {
    $result = [];
    
    // Разбираем строку типа "группа 1: 27-49"
    if (preg_match('/^(.+?):\s*(\d+)-(\d+)$/', $ageGroupString, $matches)) {
        $groupName = trim($matches[1]);
        $minAge = (int)$matches[2];
        $maxAge = (int)$matches[3];
        
        $result[] = [
            'name' => $groupName,
            'displayName' => $ageGroupString,
            'minAge' => $minAge,
            'maxAge' => $maxAge
        ];
    } else {
        // Если не удалось разобрать, используем как есть
        $result[] = [
            'name' => $ageGroupString,
            'displayName' => $ageGroupString,
            'minAge' => 0,
            'maxAge' => 999
        ];
    }
    
    return $result;
}

/**
 * Очистка предыдущих данных протоколов для мероприятия
 */
function clearPreviousProtocolData($meroId) {
    // Очищаем данные в Redis
    try {
        $redis = new Redis();
        $redis->connect('redis', 6379);
        
        // Удаляем все ключи протоколов для данного мероприятия
        $keys = $redis->keys("protocols:*:{$meroId}:*");
        foreach ($keys as $key) {
            $redis->del($key);
        }
        
        // Удаляем ключи с данными протоколов
        $dataKeys = $redis->keys("protocol:data:{$meroId}:*");
        foreach ($dataKeys as $key) {
            $redis->del($key);
        }
        
        // Удаляем результаты жеребьевки
        $redis->del("draw_results:{$meroId}");
        
        error_log("🧹 [PROTOCOLS] Очищены предыдущие данные для мероприятия {$meroId}");
    } catch (Exception $e) {
        error_log("Ошибка очистки Redis: " . $e->getMessage());
    }
    
    // Очищаем JSON файлы
    $jsonDir = __DIR__ . "/../../files/json/protocols/{$meroId}";
    if (is_dir($jsonDir)) {
        $files = glob($jsonDir . "/*.json");
        foreach ($files as $file) {
            unlink($file);
        }
        error_log("🧹 [PROTOCOLS] Очищены JSON файлы для мероприятия {$meroId}");
    }
}
?> 
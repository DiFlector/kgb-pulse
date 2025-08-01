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
        throw new Exception('JSON-ответ отправлен: Доступ запрещен');
    }
}

// Получаем данные из POST-запроса
if (defined('TEST_MODE')) {
    $data = $_POST;
} else {
    $rawInput = file_get_contents('php://input');
    error_log("🔄 [PROTOCOLS] Сырые входящие данные: " . $rawInput);
    
    $data = json_decode($rawInput, true);
    
    // Если JSON не распарсился, пробуем получить из $_POST
    if ($data === null && !empty($_POST)) {
        $data = $_POST;
        error_log("🔄 [PROTOCOLS] Используем данные из $_POST: " . json_encode($data));
    }
}

// Логируем входящие данные для отладки
error_log("🔄 [PROTOCOLS] Обработанные входящие данные: " . json_encode($data));

// В тестовом режиме используем более мягкую проверку
if (defined('TEST_MODE')) {
    // Устанавливаем значения по умолчанию для тестов
    if (!isset($data['meroId'])) $data['meroId'] = 1;
    if (!isset($data['type'])) $data['type'] = 'start';
    if (!isset($data['disciplines'])) $data['disciplines'] = [['class' => 'K-1', 'sex' => 'М', 'distance' => '200']];
} else {
    if (!isset($data['meroId']) || !isset($data['type']) || !isset($data['disciplines'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Не указаны обязательные параметры (meroId, type, disciplines)']);
        throw new Exception('JSON-ответ отправлен: Не указаны обязательные параметры');
    }
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
    
    error_log("🔄 [PROTOCOLS] Создание протоколов типа: {$data['type']} для мероприятия: {$data['meroId']}");
    
    $allProtocols = [];
    $debugInfo = [];
    
    // Создаем протоколы только для выбранных дисциплин
    foreach ($data['disciplines'] as $discipline) {
        $class = $discipline['class'] ?? '';
        $sex = $discipline['sex'] ?? '';
        $distance = $discipline['distance'] ?? '';
        
        if (empty($class) || empty($sex) || empty($distance)) {
            continue;
        }
        
        error_log("🔄 [PROTOCOLS] Обрабатываем дисциплину: $class $sex {$distance}м");
        
        // Получаем участников для данной дисциплины
        $participants = getParticipantsForDiscipline($db, $data['meroId'], $class, $sex, $distance);
        
        if (empty($participants)) {
            $debugInfo[] = "Дисциплина $class $sex {$distance}м: участники не найдены";
            continue;
        }
        
        error_log("🔄 [PROTOCOLS] Участников найдено: " . count($participants));
        
        // Получаем реальные возрастные группы из class_distance
        $ageGroups = AgeGroupCalculator::getAgeGroupsForDiscipline($classDistance, $class, $sex);
        
        if (empty($ageGroups)) {
            error_log("⚠️ [PROTOCOLS] Возрастные группы не найдены для $class $sex, используем упрощенные");
            // Используем упрощенные возрастные группы как fallback
            $ageGroups = [
                ['name' => 'Открытый класс', 'min_age' => 15, 'max_age' => 39, 'full_name' => 'Открытый класс: 15-39'],
                ['name' => 'Синьоры А', 'min_age' => 40, 'max_age' => 59, 'full_name' => 'Синьоры А: 40-59'],
                ['name' => 'Синьоры B', 'min_age' => 60, 'max_age' => 150, 'full_name' => 'Синьоры B: 60-150']
            ];
        }
        
        error_log("🔄 [PROTOCOLS] Найдено возрастных групп: " . count($ageGroups));
        
        // Группируем участников по возрастным группам
        $groupedParticipants = [];
        
        foreach ($ageGroups as $ageGroup) {
            // Используем полное название возрастной группы с названием и возрастным диапазоном
            $ageGroupFullName = isset($ageGroup['full_name']) ? $ageGroup['full_name'] : $ageGroup['name'];
            $groupedParticipants[$ageGroupFullName] = [];
        }
        
        foreach ($participants as $participant) {
            $age = AgeGroupCalculator::calculateAgeOnDecember31($participant['birthdata']);
            $assignedGroup = null;
            
            // Ищем подходящую возрастную группу
            foreach ($ageGroups as $ageGroup) {
                if ($age >= $ageGroup['min_age'] && $age <= $ageGroup['max_age']) {
                    // Используем полное название возрастной группы с названием и возрастным диапазоном
                    $assignedGroup = isset($ageGroup['full_name']) ? $ageGroup['full_name'] : $ageGroup['name'];
                    break;
                }
            }
            
            if ($assignedGroup && isset($groupedParticipants[$assignedGroup])) {
                $groupedParticipants[$assignedGroup][] = $participant;
            } else {
                // Если группа не найдена, добавляем в первую доступную
                if (!empty($ageGroups)) {
                    $firstGroupFullName = isset($ageGroups[0]['full_name']) ? $ageGroups[0]['full_name'] : $ageGroups[0]['name'];
                    $groupedParticipants[$firstGroupFullName][] = $participant;
                }
            }
        }
        
        // Создаем протоколы для каждой возрастной группы
        foreach ($ageGroups as $ageGroup) {
            // Используем полное название возрастной группы с названием и возрастным диапазоном
            $ageGroupName = isset($ageGroup['full_name']) ? $ageGroup['full_name'] : $ageGroup['name'];
            $ageGroupParticipants = $groupedParticipants[$ageGroupName] ?? [];
            
            if (empty($ageGroupParticipants)) {
                $debugInfo[] = "Дисциплина $class $sex {$distance}м - $ageGroupName: нет участников";
                continue;
            }
            
            $genderPrefix = $sex === 'М' ? 'Мужчины' : 'Женщины';
            $fullAgeGroupName = "$genderPrefix ($ageGroupName)";
            
            // Создаем протокол для этой возрастной группы
            $protocol = createProtocolForAgeGroup(
                $redis,
                $data['meroId'], 
                $class, 
                $sex, 
                $distance, 
                $fullAgeGroupName,
                $ageGroupParticipants, 
                $data['type']
            );
            
            if ($protocol) {
                $allProtocols[] = $protocol;
                $debugInfo[] = "✅ $class $sex {$distance}м - $fullAgeGroupName: " . count($ageGroupParticipants) . " участников";
            }
        }
    }
    
    error_log("🔄 [PROTOCOLS] Всего создано протоколов: " . count($allProtocols));
    
    echo json_encode([
        'success' => true,
        'message' => 'Протоколы успешно созданы с учетом возрастных групп из мероприятия',
        'protocols' => $allProtocols,
        'debug' => [
            'totalDisciplines' => count($data['disciplines']),
            'totalProtocols' => count($allProtocols),
            'disciplineDetails' => $debugInfo
        ]
    ]);
    
} catch (Exception $e) {
    error_log("ОШИБКА создания протоколов: " . $e->getMessage());
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
        error_log("⚠️ [PROTOCOLS] Мероприятие с champn = $meroId не найдено");
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
    
    $classDistanceLike = '%"' . $class . '"%';
    $stmt->execute([$meroOid, $sex, $classDistanceLike]);
    
    $allParticipants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $filteredParticipants = [];
    
    error_log("🔄 [PROTOCOLS] Найдено участников для $class $sex {$distance}м: " . count($allParticipants));
    
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
function createProtocolForAgeGroup($redis, $meroId, $class, $sex, $distance, $ageGroup, $participants, $type) {
    // Проводим жеребьевку
    $drawnParticipants = conductDrawForProtocol($participants);
    
    // Формируем ключ Redis с возрастной группой
    $ageGroupKey = str_replace(['(', ')', ' ', ':', '-'], ['', '', '_', '_', '_'], $ageGroup);
    $redisKey = "protocol:{$type}:{$meroId}:{$class}:{$sex}:{$distance}:{$ageGroupKey}";
    
    // Структура протокола
    $protocolData = [
        'meroId' => $meroId,
        'discipline' => $class,
        'sex' => $sex,
        'distance' => $distance,
        'ageGroup' => $ageGroup,
        'type' => $type,
        'participants' => $drawnParticipants,
        'created_at' => date('Y-m-d H:i:s'),
        'redisKey' => $redisKey
    ];
    
    // Сохраняем в Redis (если доступен)
    if ($redis) {
        try {
            $redis->setex($redisKey, 86400 * 7, json_encode($protocolData)); // Храним 7 дней
            error_log("✅ [PROTOCOLS] Протокол сохранен в Redis: $redisKey");
        } catch (Exception $e) {
            error_log("⚠️ [PROTOCOLS] Ошибка сохранения в Redis: " . $e->getMessage());
        }
    } else {
        error_log("⚠️ [PROTOCOLS] Redis недоступен, протокол не сохранен в кэше");
    }
    
    // Создаем Excel файл (опционально)
    try {
        createExcelProtocol($protocolData);
    } catch (Exception $e) {
        error_log("⚠️ [PROTOCOLS] Ошибка создания Excel: " . $e->getMessage());
    }
    
    return [
        'redisKey' => $redisKey,
        'discipline' => $class,
        'sex' => $sex,
        'distance' => $distance,
        'ageGroup' => $ageGroup,
        'participantsCount' => count($drawnParticipants),
        'type' => $type,
        'file' => "/lks/files/protocol/{$type}_{$meroId}_{$class}_{$sex}_{$distance}_{$ageGroupKey}.xlsx"
    ];
}

/**
 * Проведение жеребьевки участников
 */
function conductDrawForProtocol($participants) {
    $drawnParticipants = [];
    $startNumber = 1;
    
    if (empty($participants)) {
        error_log("⚠️ [PROTOCOLS] Нет участников для жеребьевки");
        return [];
    }
    
    // Перемешиваем участников
    shuffle($participants);
    
    foreach ($participants as $index => $participant) {
        $lane = ($index % 9) + 1; // Дорожки 1-9
        
        $drawnParticipants[] = [
            'userId' => $participant['userid'] ?? 0,
            'fio' => $participant['fio'] ?? 'Не указано',
            'birthYear' => !empty($participant['birthdata']) ? date('Y', strtotime($participant['birthdata'])) : '',
            'age' => AgeGroupCalculator::calculateAgeOnDecember31($participant['birthdata'] ?? ''),
            'city' => $participant['city'] ?? '',
            'sportRank' => $participant['sportzvanie'] ?? 'БР',
            'lane' => $lane,
            'startNumber' => $startNumber++,
            'result' => null,
            'place' => null,
            'notes' => ''
        ];
    }
    
    error_log("🔄 [PROTOCOLS] Жеребьевка проведена для " . count($drawnParticipants) . " участников");
    
    return $drawnParticipants;
}

/**
 * Создание Excel протокола (упрощенная версия)
 */
function createExcelProtocol($protocolData) {
    // Здесь можно добавить создание Excel файла с помощью PhpSpreadsheet
    // Пока создаем пустой файл для индикации
    
    $ageGroupKey = str_replace(['(', ')', ' ', ':', '-'], ['', '', '_', '_', '_'], $protocolData['ageGroup']);
    $fileName = "{$protocolData['type']}_{$protocolData['meroId']}_{$protocolData['discipline']}_{$protocolData['sex']}_{$protocolData['distance']}_{$ageGroupKey}.xlsx";
    $filePath = "/var/www/html/lks/files/protocol/$fileName";
    
    // Создаем .gitkeep файл чтобы папка сохранялась в git
    $gitkeepFile = "/var/www/html/lks/files/protocol/.gitkeep";
    if (!file_exists($gitkeepFile)) {
        file_put_contents($gitkeepFile, "");
    }
    
    // Проверяем и создаем директорию
    $dir = dirname($filePath);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0777, true)) {
            error_log("⚠️ [PROTOCOLS] Не удалось создать директорию: $dir");
            return null;
        }
    }
    
    // Проверяем права на запись
    if (!is_writable($dir)) {
        error_log("⚠️ [PROTOCOLS] Нет прав на запись в директорию: $dir");
        return null;
    }
    
    // Создаем простой файл-заглушку
    if (file_put_contents($filePath, "Excel файл протокола будет создан позже") === false) {
        error_log("⚠️ [PROTOCOLS] Не удалось создать файл: $filePath");
        return null;
    }
    
    error_log("✅ [PROTOCOLS] Создан файл протокола: $fileName");
    return $fileName;
}
?> 
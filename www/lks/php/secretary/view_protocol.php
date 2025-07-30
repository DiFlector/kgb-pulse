<?php
/**
 * Просмотр протокола с участниками и жеребьевкой
 * Файл: www/lks/php/secretary/view_protocol.php
 */

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../helpers.php";
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
    error_log("🔍 [VIEW_PROTOCOL] Запрос на просмотр протокола");
    
    // Получаем данные из POST запроса
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['redisKey'])) {
        throw new Exception('Не указан ключ протокола Redis');
    }
    
    $redisKey = $input['redisKey'];
    
    error_log("🔍 [VIEW_PROTOCOL] Ключ Redis: $redisKey");
    
    // Подключаемся к Redis
    $redis = new Redis();
    $redis->connect('redis', 6379);
    
    // Загружаем протокол по ключу Redis
    $protocolData = $redis->get($redisKey);
    
    if (!$protocolData) {
        error_log("🔍 [VIEW_PROTOCOL] Протокол не найден в Redis: $redisKey");
        throw new Exception('Протокол не найден. Создайте протоколы заново.');
    }
    
    $protocol = json_decode($protocolData, true);
    
    if (!$protocol) {
        throw new Exception('Ошибка декодирования данных протокола');
    }
    
    error_log("🔍 [VIEW_PROTOCOL] Протокол загружен: " . count($protocol['participants'] ?? []) . " участников");
    
    // Убеждаемся что участники отсортированы по дорожкам
    if (isset($protocol['participants']) && is_array($protocol['participants'])) {
        usort($protocol['participants'], function($a, $b) {
            return ($a['lane'] ?? 0) <=> ($b['lane'] ?? 0);
        });
    }
    
    echo json_encode([
        'success' => true,
        'protocol' => $protocol
    ]);
    
} catch (Exception $e) {
    error_log("🔍 [VIEW_PROTOCOL] Ошибка: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Создание нового протокола
 */
function createProtocol($meroId, $discipline, $sex, $distance, $type) {
    $db = Database::getInstance();
    
    // Получаем участников
    $stmt = $db->prepare("
        SELECT l.*, u.fio, u.birthdata, u.city, u.sportzvanie, u.sex
        FROM listreg l 
        JOIN users u ON l.users_oid = u.oid 
        WHERE l.meros_oid = ? 
        AND l.status IN ('Подтверждён', 'Зарегистрирован')
        AND u.sex = ?
    ");
    $stmt->execute([$meroId, $sex]);
    $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $participants = [];
    
    foreach ($registrations as $reg) {
        $classDistance = json_decode($reg['class_distance'], true);
        if (isset($classDistance[$discipline])) {
            $regDistances = is_array($classDistance[$discipline]['dist']) ? 
                $classDistance[$discipline]['dist'] : 
                explode(', ', $classDistance[$discipline]['dist']);
            
            if (in_array($distance, array_map('trim', $regDistances))) {
                $birthYear = date('Y', strtotime($reg['birthdata']));
                $age = date('Y') - $birthYear;
                
                $participants[] = [
                    'id' => $reg['userid'],
                    'fio' => $reg['fio'],
                    'birthYear' => $birthYear,
                    'age' => $age,
                    'ageGroup' => calculateAgeGroup($age, $sex, $discipline, $meroId),
                    'city' => $reg['city'],
                    'sportzvanie' => $reg['sportzvanie'] ?? 'БР',
                    'teamName' => $reg['teamname'] ?? $reg['city'],
                    'lane' => null,
                    'startNumber' => null,
                    'result' => null,
                    'place' => null,
                    'notes' => ''
                ];
            }
        }
    }
    
    return [
        'meroId' => $meroId,
        'discipline' => $discipline,
        'sex' => $sex,
        'distance' => $distance,
        'type' => $type,
        'participants' => $participants,
        'heats' => [],
        'totalParticipants' => count($participants),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * Проведение жеребьевки участников
 */
function conductDraw($protocolData) {
    $participants = $protocolData['participants'];
    
    if (empty($participants)) {
        return $protocolData;
    }
    
    // Группируем участников по возрастным группам
    $ageGroups = [];
    foreach ($participants as $participant) {
        $ageGroup = $participant['ageGroup'];
        if (!isset($ageGroups[$ageGroup])) {
            $ageGroups[$ageGroup] = [];
        }
        $ageGroups[$ageGroup][] = $participant;
    }
    
    $heats = [];
    $startNumber = 1;
    
    foreach ($ageGroups as $ageGroup => $groupParticipants) {
        $participantCount = count($groupParticipants);
        
        // Определяем количество заездов
        $lanesPerHeat = 9; // Максимум 9 дорожек
        
        if ($participantCount <= $lanesPerHeat) {
            // Один финальный заезд
            $heat = createHeat($groupParticipants, $ageGroup, 'Финал', 1, $startNumber);
            $heats[] = $heat;
            $startNumber += count($groupParticipants);
            
        } elseif ($participantCount <= $lanesPerHeat * 2) {
            // Полуфинал + финал
            $shuffled = $groupParticipants;
            shuffle($shuffled);
            
            $heat1Participants = array_slice($shuffled, 0, ceil($participantCount / 2));
            $heat2Participants = array_slice($shuffled, ceil($participantCount / 2));
            
            $heat1 = createHeat($heat1Participants, $ageGroup, 'Полуфинал', 1, $startNumber);
            $heats[] = $heat1;
            $startNumber += count($heat1Participants);
            
            $heat2 = createHeat($heat2Participants, $ageGroup, 'Полуфинал', 2, $startNumber);
            $heats[] = $heat2;
            $startNumber += count($heat2Participants);
            
        } else {
            // Предварительные + полуфинал + финал
            $shuffled = $groupParticipants;
            shuffle($shuffled);
            
            $heatCount = ceil($participantCount / $lanesPerHeat);
            
            for ($i = 0; $i < $heatCount; $i++) {
                $heatParticipants = array_slice($shuffled, $i * $lanesPerHeat, $lanesPerHeat);
                if (!empty($heatParticipants)) {
                    $heat = createHeat($heatParticipants, $ageGroup, 'Предварительный', $i + 1, $startNumber);
                    $heats[] = $heat;
                    $startNumber += count($heatParticipants);
                }
            }
        }
    }
    
    $protocolData['heats'] = $heats;
    $protocolData['updated_at'] = date('Y-m-d H:i:s');
    
    return $protocolData;
}

/**
 * Создание заезда с участниками
 */
function createHeat($participants, $ageGroup, $heatType, $heatNumber, $startNumber) {
    shuffle($participants); // Случайное распределение по дорожкам
    
    $lanes = [];
    $currentStartNumber = $startNumber;
    
    for ($lane = 1; $lane <= 9; $lane++) {
        if (isset($participants[$lane - 1])) {
            $participant = $participants[$lane - 1];
            $participant['lane'] = $lane;
            $participant['startNumber'] = $currentStartNumber++;
            $lanes[$lane] = $participant;
        } else {
            $lanes[$lane] = null;
        }
    }
    
    return [
        'ageGroup' => $ageGroup,
        'heatType' => $heatType,
        'heatNumber' => $heatNumber,
        'lanes' => $lanes,
        'participantCount' => count(array_filter($lanes))
    ];
}

/**
 * Расчет возрастной группы (обновленная версия с использованием AgeGroupCalculator)
 */
function calculateAgeGroup($age, $sex, $discipline, $meroId = null) {
    global $db;
    
    // Если не передан meroId, возвращаем базовую группу
    if (!$meroId) {
        $genderPrefix = $sex === 'М' ? 'Мужчины' : 'Женщины';
        return "$genderPrefix (неопределенная группа)";
    }
    
    try {
        // Получаем данные мероприятия
        $stmt = $db->prepare("SELECT class_distance FROM meros WHERE champn = ?");
        $stmt->execute([$meroId]);
        $mero = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$mero || !$mero['class_distance']) {
            $genderPrefix = $sex === 'М' ? 'Мужчины' : 'Женщины';
            return "$genderPrefix (группа не найдена)";
        }
        
        $classDistance = json_decode($mero['class_distance'], true);
        
        // Используем новый класс AgeGroupCalculator
        return AgeGroupCalculator::calculateAgeGroup($age, $sex, $discipline, $classDistance);
        
    } catch (Exception $e) {
        $genderPrefix = $sex === 'М' ? 'Мужчины' : 'Женщины';
        return "$genderPrefix (ошибка расчета)";
    }
}
?> 
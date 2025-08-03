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
    
    if ($meroId <= 0) {
        throw new Exception('Неверный ID мероприятия');
    }
    
    error_log("🔄 [LOAD_PROTOCOLS_DATA] Загрузка данных протоколов для мероприятия $meroId");
    
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
                foreach ($ageGroupList as $ageGroup) {
                    // Извлекаем название группы
                    if (preg_match('/^(.+?):\s*(\d+)-(\d+)$/', $ageGroup, $matches)) {
                        $groupName = trim($matches[1]);
                        $minAge = (int)$matches[2];
                        $maxAge = (int)$matches[3];
                        
                        $redisKey = "{$meroId}_{$boatClass}_{$sex}_{$dist}_{$groupName}";
                        
                        // Получаем участников для этой группы
                        $participants = getParticipantsForGroup($db, $meroId, $boatClass, $sex, $dist, $minAge, $maxAge);
                        
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
    
    $filteredParticipants = [];
    
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
                    $filteredParticipants[] = [
                        'userId' => $participant['userid'],
                        'fio' => $participant['fio'],
                        'sex' => $participant['sex'],
                        'birthdata' => $participant['birthdata'],
                        'sportzvanie' => $participant['sportzvanie'],
                        'teamName' => $participant['teamname'] ?? '',
                        'teamCity' => $participant['teamcity'] ?? '',
                        'lane' => null,
                        'place' => null,
                        'finishTime' => null,
                        'addedManually' => false,
                        'addedAt' => date('Y-m-d H:i:s')
                    ];
                }
            }
        }
    }
    
    return $filteredParticipants;
}
?> 
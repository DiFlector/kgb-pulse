<?php
/**
 * Получение данных протоколов для отображения в интерфейсе
 * Файл: www/lks/php/secretary/get_protocols_data.php
 */

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/Auth.php";

if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Проверка авторизации
$auth = new Auth();
if (!$auth->isAuthenticated() || !$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $meroId = $input['meroId'] ?? null;
    
    if (!$meroId) {
        throw new Exception('Не указан ID мероприятия');
    }
    
    $db = Database::getInstance();
    
    // Получаем данные мероприятия
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
        $redis->connect('redis', 6379);
    } catch (Exception $e) {
        error_log("Ошибка подключения к Redis: " . $e->getMessage());
        $redis = null;
    }
    
    $protocolsData = [];
    
    // Проходим по всем классам лодок
    foreach ($classDistance as $boatClass => $config) {
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
                        
                        // Проверяем данные в Redis
                        $redisData = null;
                        if ($redis) {
                            $redisData = $redis->get($redisKey);
                            if ($redisData) {
                                $redisData = json_decode($redisData, true);
                            }
                        }
                        
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
                                    'protected' => false,
                                    'redisData' => $redisData
                                ]
                            ],
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                    }
                }
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'protocols' => $protocolsData,
        'total_protocols' => count($protocolsData),
        'event' => [
            'id' => $event['oid'],
            'name' => $event['meroname'],
            'date' => $event['merodata'],
            'status' => $event['status']
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Ошибка получения данных протоколов: " . $e->getMessage());
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
            t.teamname, t.teamcity, lr.discipline
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
            $discipline = json_decode($participant['discipline'], true);
            if ($discipline && isset($discipline[$boatClass])) {
                // Получаем данные дорожки из discipline
                $lane = null;
                $water = null;
                $time = null;
                $place = null;
                
                if (isset($discipline[$boatClass]['lane'])) {
                    $lane = $discipline[$boatClass]['lane'];
                }
                if (isset($discipline[$boatClass]['water'])) {
                    $water = $discipline[$boatClass]['water'];
                }
                if (isset($discipline[$boatClass]['time'])) {
                    $time = $discipline[$boatClass]['time'];
                }
                if (isset($discipline[$boatClass]['place'])) {
                    $place = $discipline[$boatClass]['place'];
                }
                
                $filteredParticipants[] = [
                    'userId' => $participant['userid'],
                    'fio' => $participant['fio'],
                    'sex' => $participant['sex'],
                    'birthdata' => $participant['birthdata'],
                    'sportzvanie' => $participant['sportzvanie'],
                    'teamName' => $participant['teamname'] ?? '',
                    'teamCity' => $participant['teamcity'] ?? '',
                    'lane' => $lane,
                    'water' => $water,
                    'time' => $time,
                    'place' => $place,
                    'finishTime' => null,
                    'addedManually' => false,
                    'addedAt' => date('Y-m-d H:i:s')
                ];
            }
        }
    }
    
    return $filteredParticipants;
}
?> 
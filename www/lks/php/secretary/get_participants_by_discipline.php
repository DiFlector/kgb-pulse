<?php
/**
 * API для получения участников по дисциплине и возрастной группе
 * Файл: www/lks/php/secretary/get_participants_by_discipline.php
 */

header('Content-Type: application/json; charset=utf-8');
session_start();

// Проверка прав доступа
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Secretary', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit();
}

require_once __DIR__ . "/../db/Database.php";

try {
    $db = Database::getInstance();
    
    // Получаем данные из POST
    $input = json_decode(file_get_contents('php://input'), true);
    $meroId = $input['meroId'] ?? null;
    $class = $input['class'] ?? null;
    $sex = $input['sex'] ?? null;
    $distance = $input['distance'] ?? null;
    $ageGroup = $input['ageGroup'] ?? null;
    
    if (!$meroId || !$class || !$sex || !$distance) {
        throw new Exception('Не указаны обязательные параметры');
    }
    
    // Получаем oid мероприятия
    $stmt = $db->prepare("SELECT oid FROM meros WHERE champn = ?");
    $stmt->execute([$meroId]);
    $meroOid = $stmt->fetchColumn();
    
    if (!$meroOid) {
        throw new Exception('Мероприятие не найдено');
    }
    
    // Получаем всех участников для данной дисциплины
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
    
    // Пытаемся получить результаты жеребьевки из Redis
    $drawResults = null;
    try {
        $redis = new Redis();
        $connected = $redis->connect('redis', 6379, 5);
        if ($connected) {
            $drawKey = "draw_results:{$meroId}";
            $drawData = $redis->get($drawKey);
            if ($drawData) {
                $drawResults = json_decode($drawData, true);
            }
        }
    } catch (Exception $e) {
        error_log("ОШИБКА Redis в get_participants_by_discipline.php: " . $e->getMessage());
    }
    
    $filteredParticipants = [];
    
    foreach ($allParticipants as $participant) {
        $classDistanceData = json_decode($participant['discipline'], true);
        
        if (isset($classDistanceData[$class]['dist'])) {
            $distances = is_array($classDistanceData[$class]['dist']) 
                ? $classDistanceData[$class]['dist'] 
                : explode(', ', $classDistanceData[$class]['dist']);
            
            // Проверяем, участвует ли спортсмен в данной дистанции
            $participatesInDistance = false;
            foreach ($distances as $distStr) {
                $distArray = array_map('trim', explode(',', $distStr));
                if (in_array($distance, $distArray)) {
                    $participatesInDistance = true;
                    break;
                }
            }
            
            if ($participatesInDistance) {
                // Рассчитываем возраст на 31 декабря текущего года
                $birthYear = date('Y', strtotime($participant['birthdata']));
                $currentYear = date('Y');
                $age = $currentYear - $birthYear;
                
                // Проверяем, подходит ли возрастная группа
                if ($ageGroup) {
                    $ageGroupData = parseAgeGroup($ageGroup);
                    if ($age >= $ageGroupData['minAge'] && $age <= $ageGroupData['maxAge']) {
                        $participantData = [
                            'id' => $participant['userid'],
                            'fio' => $participant['fio'],
                            'birthYear' => $birthYear,
                            'age' => $age,
                            'city' => $participant['city'],
                            'sportzvanie' => $participant['sportzvanie'] ?? 'БР',
                            'lane' => null,
                            'startNumber' => null,
                            'result' => null,
                            'place' => null
                        ];
                        
                        // Проверяем, есть ли результаты жеребьевки для этого участника
                        if ($drawResults) {
                            foreach ($drawResults as $drawResult) {
                                if ($drawResult['class'] === $class && 
                                    $drawResult['sex'] === $sex && 
                                    $drawResult['distance'] === $distance &&
                                    $drawResult['ageGroup'] === $ageGroup) {
                                    
                                    foreach ($drawResult['participants'] as $drawnParticipant) {
                                        if ($drawnParticipant['id'] === $participant['userid']) {
                                            $participantData['lane'] = $drawnParticipant['lane'];
                                            $participantData['startNumber'] = $drawnParticipant['startNumber'];
                                            break 2;
                                        }
                                    }
                                }
                            }
                        }
                        
                        $filteredParticipants[] = $participantData;
                    }
                } else {
                    // Если возрастная группа не указана, добавляем всех
                    $participantData = [
                        'id' => $participant['userid'],
                        'fio' => $participant['fio'],
                        'birthYear' => $birthYear,
                        'age' => $age,
                        'city' => $participant['city'],
                        'sportzvanie' => $participant['sportzvanie'] ?? 'БР',
                        'lane' => null,
                        'startNumber' => null,
                        'result' => null,
                        'place' => null
                    ];
                    
                    // Проверяем результаты жеребьевки
                    if ($drawResults) {
                        foreach ($drawResults as $drawResult) {
                            if ($drawResult['class'] === $class && 
                                $drawResult['sex'] === $sex && 
                                $drawResult['distance'] === $distance) {
                                
                                foreach ($drawResult['participants'] as $drawnParticipant) {
                                    if ($drawnParticipant['id'] === $participant['userid']) {
                                        $participantData['lane'] = $drawnParticipant['lane'];
                                        $participantData['startNumber'] = $drawnParticipant['startNumber'];
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                    
                    $filteredParticipants[] = $participantData;
                }
            }
        }
    }
    
    // Сортируем по номеру старта (если есть результаты жеребьевки), иначе по ФИО
    usort($filteredParticipants, function($a, $b) {
        if ($a['startNumber'] !== null && $b['startNumber'] !== null) {
            return $a['startNumber'] - $b['startNumber'];
        }
        return strcmp($a['fio'], $b['fio']);
    });
    
    echo json_encode([
        'success' => true,
        'participants' => $filteredParticipants,
        'total' => count($filteredParticipants)
    ]);
    
} catch (Exception $e) {
    error_log("ОШИБКА в get_participants_by_discipline.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Ошибка сервера: ' . $e->getMessage()
    ]);
}

/**
 * Парсинг возрастной группы
 */
function parseAgeGroup($ageGroupStr) {
    if (preg_match('/группа\s*(\d+):\s*(\d+)-(\d+)/', $ageGroupStr, $matches)) {
        return [
            'name' => "группа " . $matches[1],
            'minAge' => intval($matches[2]),
            'maxAge' => intval($matches[3])
        ];
    } elseif (preg_match('/([^:]+):\s*(\d+)-(\d+)/', $ageGroupStr, $matches)) {
        return [
            'name' => trim($matches[1]),
            'minAge' => intval($matches[2]),
            'maxAge' => intval($matches[3])
        ];
    }
    
    return ['name' => $ageGroupStr, 'minAge' => 0, 'maxAge' => 150];
}
?> 
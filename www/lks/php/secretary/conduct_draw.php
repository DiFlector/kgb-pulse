<?php
// Отключаем вывод ошибок в браузер
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/php_errors.log');

// Начинаем буферизацию вывода
ob_start();

session_start();
require_once __DIR__ . '/../common/Auth.php';
require_once __DIR__ . '/../db/Database.php';

// Проверка авторизации и прав доступа
$auth = new Auth();

if (!$auth->isAuthenticated()) {
    // Очищаем буфер и отправляем JSON
    ob_end_clean();
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Не авторизован']);
    exit;
}

if (!$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
    // Очищаем буфер и отправляем JSON
    ob_end_clean();
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Нет прав доступа']);
    exit;
}

// Получение данных из запроса
$input = json_decode(file_get_contents('php://input'), true);
$meroId = $input['meroId'] ?? null;
$autoCreate = $input['autoCreate'] ?? false; // Флаг для автоматического создания

if (!$meroId) {
    // Очищаем буфер и отправляем JSON
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'ID мероприятия не указан']);
    exit;
}

try {
    // Загружаем данные протоколов из JSON файла
    $protocolsDir = __DIR__ . '/../../../files/json/protocols/';
    $filename = $protocolsDir . "protocols_{$meroId}.json";
    
    // Если файл протоколов не существует, сначала генерируем их
    if (!file_exists($filename)) {
        error_log("conduct_draw.php: Файл протоколов не найден, генерируем протоколы");
        
        // Генерируем протоколы автоматически
        $protocolsData = generateProtocolsData($meroId);
        
        if (!$protocolsData) {
            // Очищаем буфер и отправляем JSON
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Ошибка генерации протоколов']);
            exit;
        }
        
        // Сохраняем сгенерированные протоколы
        if (!is_dir($protocolsDir)) {
            mkdir($protocolsDir, 0755, true);
        }
        file_put_contents($filename, json_encode($protocolsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        error_log("conduct_draw.php: Протоколы сгенерированы и сохранены");
    } else {
        // Загружаем существующие протоколы
        $jsonData = file_get_contents($filename);
        $protocolsData = json_decode($jsonData, true);
        
        if (!$protocolsData) {
            // Очищаем буфер и отправляем JSON
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Ошибка чтения файла протоколов']);
            exit;
        }
    }
    
    // Подключаемся к Redis
    $redis = new Redis();
    $redis->connect('redis', 6379);
    
    $drawConducted = false;
    
    // Проводим жеребьевку для каждой группы
    foreach ($protocolsData as &$protocol) {
        foreach ($protocol['ageGroups'] as &$ageGroup) {
            if (!empty($ageGroup['participants'])) {
                // Перемешиваем участников
                shuffle($ageGroup['participants']);
                
                // Назначаем дорожки
                $lane = 1;
                foreach ($ageGroup['participants'] as &$participant) {
                    $participant['lane'] = $lane++;
                }
                
                // Отмечаем, что жеребьевка проведена
                $ageGroup['drawConducted'] = true;
                $ageGroup['drawConductedAt'] = date('Y-m-d H:i:s');
                
                // Сохраняем в Redis
                $redis->setex($ageGroup['redisKey'], 86400, json_encode($ageGroup));
                
                $drawConducted = true;
            }
        }
    }
    
    // Сохраняем обновленные данные в JSON файл
    file_put_contents($filename, json_encode($protocolsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // Очищаем буфер и отправляем JSON
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => $drawConducted ? 'Жеребьевка проведена успешно' : 'Нет участников для жеребьевки',
        'protocols' => $protocolsData,
        'drawConducted' => $drawConducted
    ]);
    
} catch (Exception $e) {
    // Очищаем буфер и отправляем JSON
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Ошибка проведения жеребьевки: ' . $e->getMessage()]);
}

// Функция для генерации данных протоколов
function generateProtocolsData($meroId) {
    try {
        $db = Database::getInstance();
        
        // Получаем данные мероприятия
        $stmt = $db->prepare("SELECT * FROM meros WHERE oid = ?");
        $stmt->execute([$meroId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$event) {
            error_log("generateProtocolsData: Мероприятие не найдено");
            return false;
        }
        
        // Парсим class_distance
        $classDistance = json_decode($event['class_distance'], true);
        if (!$classDistance) {
            error_log("generateProtocolsData: Ошибка чтения конфигурации классов");
            return false;
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
        
        return $protocolsData;
        
    } catch (Exception $e) {
        error_log("generateProtocolsData: Ошибка: " . $e->getMessage());
        return false;
    }
}

// Функция для получения участников группы
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
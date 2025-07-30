<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/lks/php/common/Auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lks/php/db/Database.php';

// Проверка авторизации и прав доступа
$auth = new Auth();
if (!$auth->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Не авторизован']);
    exit;
}

// Проверка прав секретаря, суперпользователя или администратора
if (!$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

// Получение данных запроса
$input = json_decode(file_get_contents('php://input'), true);
$participantOid = (int)($input['participantOid'] ?? 0);
$groupKey = trim($input['groupKey'] ?? '');
$meroId = (int)($input['meroId'] ?? 0);

if ($participantOid <= 0 || empty($groupKey) || $meroId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Неверные параметры запроса']);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();

    // Получаем информацию об участнике
    $stmt = $pdo->prepare("
        SELECT 
            u.oid,
            u.userid,
            u.fio,
            u.sex,
            u.birthdata,
            u.sportzvanie,
            EXTRACT(YEAR FROM AGE(CURRENT_DATE, u.birthdata)) as age
        FROM users u
        WHERE u.oid = ?
    ");
    $stmt->execute([$participantOid]);
    $participant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$participant) {
        echo json_encode(['success' => false, 'message' => 'Участник не найден']);
        exit;
    }

    // Проверяем, что участник зарегистрирован на мероприятие
    $stmt = $pdo->prepare("
        SELECT oid FROM listreg 
        WHERE users_oid = ? AND meros_oid = ?
    ");
    $stmt->execute([$participantOid, $meroId]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Участник не зарегистрирован на мероприятие']);
        exit;
    }

    // Парсим groupKey для получения информации о группе
    // Формат: meroId_class_sex_distance_ageGroup (например: 1_K-1_М_200_группа Ю1)
    $groupParts = explode('_', $groupKey);
    if (count($groupParts) < 5) {
        echo json_encode(['success' => false, 'message' => 'Неверный формат ключа группы']);
        exit;
    }

    $class = $groupParts[1];
    $sex = $groupParts[2];
    $distance = $groupParts[3];
    $ageGroup = implode('_', array_slice($groupParts, 4)); // Остальные части - возрастная группа

    // Функция нормализации пола (маппинг латинских и кириллических букв)
    function normalizeSex($sex) {
        $sex = trim($sex);
        $sex = mb_strtoupper($sex, 'UTF-8');
        
        // Маппинг латинских и кириллических букв
        $mapping = [
            'M' => 'М',  // Латинская M -> Кириллическая М
            'М' => 'М',  // Кириллическая М -> Кириллическая М
            'W' => 'Ж',  // Латинская W -> Кириллическая Ж
            'Ж' => 'Ж',  // Кириллическая Ж -> Кириллическая Ж
            'F' => 'Ж',  // Латинская F -> Кириллическая Ж
        ];
        
        return $mapping[$sex] ?? $sex;
    }

    // Логируем значения для отладки
    $logStr = date('Y-m-d H:i:s') . ' | participant[sex]=' . var_export($participant['sex'], true) .
        ' | groupKey sex=' . var_export($sex, true) .
        ' | norm_participant=' . var_export(normalizeSex($participant['sex']), true) .
        ' | norm_group=' . var_export(normalizeSex($sex), true) .
        ' | result=' . ((normalizeSex($participant['sex']) === normalizeSex($sex)) ? 'OK' : 'FAIL') .
        PHP_EOL;
    file_put_contents(__DIR__ . '/sex_debug.log', $logStr, FILE_APPEND);

    // Проверяем соответствие участника группе (с учетом регистра, пробелов и кириллицы)
    if (normalizeSex($participant['sex']) !== normalizeSex($sex)) {
        echo json_encode([
            'debug' => [
                'participant_sex' => $participant['sex'],
                'group_sex' => $sex,
                'norm_participant' => normalizeSex($participant['sex']),
                'norm_group' => normalizeSex($sex)
            ],
            'success' => false,
            'message' => 'Пол участника не соответствует группе'
        ]);
        exit;
    }

    // Проверяем возраст участника
    $currentYear = (int)date('Y');
    $birthYear = (int)substr($participant['birthdata'], 0, 4);
    $age = $currentYear - $birthYear;

    // Улучшенная проверка возрастной группы
    $ageGroupLower = strtolower($ageGroup);
    if (strpos($ageGroupLower, 'ю2: 13-14') !== false || strpos($ageGroupLower, 'ю2:13-14') !== false) {
        $minAge = 13;
        $maxAge = 14;
        
        if ($age < $minAge || $age > $maxAge) {
            echo json_encode(['success' => false, 'message' => 'Возраст участника не соответствует группе']);
            exit;
        }
    } elseif (strpos($ageGroupLower, 'ю1:') !== false) {
        // Для юниоров 1 разряда (11-12 лет) - ИСПРАВЛЕНО
        $minAge = 11;
        $maxAge = 12;
        
        if ($age < $minAge || $age > $maxAge) {
            echo json_encode(['success' => false, 'message' => 'Возраст участника не соответствует группе']);
            exit;
        }
    } elseif (strpos($ageGroupLower, 'ю3:') !== false) {
        // Для юниоров 3 разряда (15-16 лет) - ИСПРАВЛЕНО
        $minAge = 15;
        $maxAge = 16;
        
        if ($age < $minAge || $age > $maxAge) {
            echo json_encode(['success' => false, 'message' => 'Возраст участника не соответствует группе']);
            exit;
        }
    }

    // Получаем текущие данные протоколов из Redis
    $redis = new Redis();
    try {
        $redis->connect('redis', 6379);
        
        // Функция для поиска свободного номера воды
        function findAvailableWaterNumber($participants) {
            $usedNumbers = [];
            foreach ($participants as $participant) {
                $waterNumber = $participant['waterNumber'] ?? $participant['water'] ?? 0;
                if ($waterNumber > 0) {
                    $usedNumbers[] = $waterNumber;
                }
            }
            
            // Сортируем использованные номера
            sort($usedNumbers);
            
            // Логируем для отладки
            $logStr = date('Y-m-d H:i:s') . ' | usedNumbers=' . json_encode($usedNumbers) . 
                     ' | count=' . count($participants) . PHP_EOL;
            file_put_contents(__DIR__ . '/water_debug.log', $logStr, FILE_APPEND);
            
            // Ищем первый свободный номер от 1 до максимального
            $maxNumber = empty($usedNumbers) ? 0 : max($usedNumbers);
            
            for ($i = 1; $i <= $maxNumber; $i++) {
                if (!in_array($i, $usedNumbers)) {
                    $logStr = date('Y-m-d H:i:s') . ' | found free number=' . $i . PHP_EOL;
                    file_put_contents(__DIR__ . '/water_debug.log', $logStr, FILE_APPEND);
                    return $i; // Нашли свободный номер
                }
            }
            
            // Если нет свободных номеров, возвращаем следующий после максимального
            $result = $maxNumber + 1;
            $logStr = date('Y-m-d H:i:s') . ' | no free numbers, returning=' . $result . PHP_EOL;
            file_put_contents(__DIR__ . '/water_debug.log', $logStr, FILE_APPEND);
            return $result;
        }
        
        // Добавляем участника в стартовый протокол
        $startKey = "protocols:start:{$groupKey}";
        $startData = $redis->get($startKey);
        
        if ($startData) {
            $startProtocol = json_decode($startData, true);
            $participants = $startProtocol['participants'] ?? [];
            
            // Проверяем, не добавлен ли уже участник
            $exists = false;
            foreach ($participants as $existingParticipant) {
                if ($existingParticipant['id'] == $participantOid) {
                    $exists = true;
                    break;
                }
            }
            
            if (!$exists) {
                // Находим свободный номер воды
                $availableWaterNumber = findAvailableWaterNumber($participants);
                
                // Добавляем участника
                $newParticipant = [
                    'id' => $participant['oid'],
                    'userid' => $participant['userid'],
                    'fio' => $participant['fio'],
                    'birthdata' => $participant['birthdata'],
                    'birthYear' => $birthYear,
                    'age' => $age,
                    'ageGroup' => $ageGroup,
                    'sportzvanie' => $participant['sportzvanie'],
                    'sportRank' => $participant['sportzvanie'],
                    'team' => 'Б/к',
                    'teamCity' => '',
                    'teamName' => '',
                    'athleteNumber' => $participant['userid'],
                    'waterNumber' => $availableWaterNumber,
                    'water' => $availableWaterNumber  // Синхронизируем с waterNumber
                ];
                
                $participants[] = $newParticipant;
                $startProtocol['participants'] = $participants;
                $startProtocol['totalParticipants'] = count($participants);
                $startProtocol['lastUpdated'] = date('Y-m-d H:i:s');
                
                $redis->setex($startKey, 86400, json_encode($startProtocol));
                
                // Добавляем отладочную информацию в ответ
                $debugInfo = [
                    'usedNumbers' => array_map(function($p) { 
                        return $p['waterNumber'] ?? $p['water'] ?? 0; 
                    }, $participants),
                    'availableWaterNumber' => $availableWaterNumber,
                    'totalParticipants' => count($participants)
                ];
            }
        }
        
        // Добавляем участника в финишный протокол
        $finishKey = "protocols:finish:{$groupKey}";
        $finishData = $redis->get($finishKey);
        
        if ($finishData) {
            $finishProtocol = json_decode($finishData, true);
            $finishParticipants = $finishProtocol['participants'] ?? [];
            
            // Проверяем, не добавлен ли уже участник
            $exists = false;
            foreach ($finishParticipants as $existingParticipant) {
                if ($existingParticipant['id'] == $participantOid) {
                    $exists = true;
                    break;
                }
            }
            
            if (!$exists) {
                // Находим свободный номер воды для финишного протокола
                $availableWaterNumber = findAvailableWaterNumber($finishParticipants);
                
                // Добавляем участника в финишный протокол
                $newFinishParticipant = [
                    'id' => $participant['oid'],
                    'userid' => $participant['userid'],
                    'fio' => $participant['fio'],
                    'birthdata' => $participant['birthdata'],
                    'birthYear' => $birthYear,
                    'age' => $age,
                    'ageGroup' => $ageGroup,
                    'sportzvanie' => $participant['sportzvanie'],
                    'sportRank' => $participant['sportzvanie'],
                    'team' => 'Б/к',
                    'teamCity' => '',
                    'teamName' => '',
                    'athleteNumber' => $participant['userid'],
                    'waterNumber' => $availableWaterNumber,
                    'water' => $availableWaterNumber,  // Синхронизируем с waterNumber
                    'place' => '',
                    'finishTime' => ''
                ];
                
                $finishParticipants[] = $newFinishParticipant;
                $finishProtocol['participants'] = $finishParticipants;
                $finishProtocol['totalParticipants'] = count($finishParticipants);
                $finishProtocol['lastUpdated'] = date('Y-m-d H:i:s');
                
                $redis->setex($finishKey, 86400, json_encode($finishProtocol));
            }
        }
        
        // Сохраняем в JSON файлы
        $jsonDir = __DIR__ . "/../../files/json/protocols/{$meroId}";
        if (!is_dir($jsonDir)) {
            mkdir($jsonDir, 0755, true);
        }
        
        // Сохраняем стартовый протокол
        if (isset($startProtocol)) {
            $startFilePath = "{$jsonDir}/{$groupKey}_start.json";
            file_put_contents($startFilePath, json_encode($startProtocol, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        
        // Сохраняем финишный протокол
        if (isset($finishProtocol)) {
            $finishFilePath = "{$jsonDir}/{$groupKey}_finish.json";
            file_put_contents($finishFilePath, json_encode($finishProtocol, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        
    } catch (Exception $e) {
        error_log("Ошибка Redis: " . $e->getMessage());
        // Продолжаем выполнение, даже если Redis недоступен
    }

    echo json_encode([
        'success' => true,
        'message' => 'Участник успешно добавлен в протокол',
        'debug' => $debugInfo ?? null
    ]);

} catch (Exception $e) {
    error_log("Ошибка добавления участника в протокол: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка добавления участника в протокол: ' . $e->getMessage()
    ]);
}
?> 
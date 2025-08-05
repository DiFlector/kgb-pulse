<?php
/**
 * API для автоматического заполнения протоколов участниками
 * Файл: www/lks/php/secretary/auto_fill_protocols.php
 */

require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../helpers.php";
require_once __DIR__ . "/protocol_numbering.php";

try {
    // Проверяем авторизацию
    $auth = new Auth();
    if (!$auth->isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Не авторизован']);
        exit;
    }

    // Проверяем права секретаря
    if (!$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
        exit;
    }

    // Получаем данные из POST запроса
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['meroId'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Не указан ID мероприятия']);
        exit;
    }

    $meroId = intval($input['meroId']);

    // Получаем информацию о мероприятии
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT * FROM meros WHERE oid = ?");
    $stmt->execute([$meroId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Мероприятие не найдено']);
        exit;
    }

    // Получаем структуру мероприятия
    $classDistance = json_decode($event['class_distance'], true);
    if (!$classDistance) {
        echo json_encode(['success' => false, 'message' => 'Структура мероприятия не найдена']);
        exit;
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

    // Получаем всех участников мероприятия
    $stmt = $db->prepare("
        SELECT l.*, u.fio, u.birthdata, u.sex, u.sportzvanie, u.city, u.userid
        FROM listreg l 
        JOIN users u ON l.users_oid = u.oid 
        WHERE l.meros_oid = ? 
        AND l.status IN ('Подтверждён', 'Зарегистрирован')
        ORDER BY u.fio
    ");
    $stmt->execute([$event['oid']]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalAdded = 0;
    $errors = [];
    $processedDisciplines = [];
    $protocolsData = []; // Кэш для протоколов

    foreach ($participants as $participant) {
        error_log("Автозаполнение: обрабатываем участника {$participant['fio']}");
        
        // Вычисляем возраст участника один раз
        $birthYear = date('Y', strtotime($participant['birthdata']));
        $currentYear = date('Y');
        $age = $currentYear - $birthYear;
        
        // Получаем дисциплины участника
        $disciplineData = json_decode($participant['discipline'], true);
        if (!$disciplineData) {
            error_log("Автозаполнение: участник {$participant['fio']} не имеет дисциплин");
            continue;
        }

        // Обрабатываем каждую дисциплину участника
        foreach ($disciplineData as $class => $classInfo) {
            if (!isset($classDistance[$class])) {
                error_log("Автозаполнение: участник {$participant['fio']} имеет неизвестную дисциплину $class");
                continue;
            }

            // Получаем полы и дистанции для данной дисциплины
            $sexes = is_array($classInfo['sex']) ? $classInfo['sex'] : [$classInfo['sex']];
            $distances = [];
            if (isset($classInfo['dist'])) {
                if (is_array($classInfo['dist'])) {
                    $distances = $classInfo['dist'];
                } else {
                    // Разбираем строку дистанций
                    $distString = $classInfo['dist'];
                    if (strpos($distString, ',') !== false) {
                        $distances = array_map('trim', explode(',', $distString));
                    } else {
                        $distances = [trim($distString)];
                    }
                }
            }

            // Обрабатываем каждую комбинацию пол/дистанция
            foreach ($sexes as $sex) {
                // Проверяем, что пол участника соответствует дисциплине
                if ($participant['sex'] !== $sex) {
                    continue;
                }

                foreach ($distances as $distance) {
                    $distance = trim($distance);
                    
                    // Определяем возрастную группу участника для данной дисциплины
                    $ageGroup = calculateAgeGroupFromClassDistance($age, $sex, $classDistance, $class);
                    
                    if (!$ageGroup) {
                        error_log("Автозаполнение: участник {$participant['fio']} не подходит по возрасту для $class $sex {$distance}м (возраст: $age)");
                        continue;
                    }

                    // Формируем ключ протокола (соответствует JavaScript)
                    $normalizedSex = normalizeSexToEnglish($sex);
                    $protocolKey = "{$meroId}_{$class}_{$normalizedSex}_{$distance}_{$ageGroup}";
                    
                    error_log("Автозаполнение: формируем ключ протокола: $protocolKey");

                    // Получаем или создаем данные протокола
                    if (!isset($protocolsData[$protocolKey])) {
                        // Проверяем Redis
                        if ($redis) {
                            $existingData = $redis->get($protocolKey);
                            if ($existingData) {
                                $protocolsData[$protocolKey] = json_decode($existingData, true);
                            }
                        }
                        
                        // Если данных нет, создаем новые
                        if (!isset($protocolsData[$protocolKey])) {
                            $protocolsData[$protocolKey] = [
                                'meroId' => $meroId,
                                'discipline' => $class,
                                'sex' => $sex,
                                'distance' => $distance,
                                'ageGroup' => $ageGroup,
                                'name' => $ageGroup, // Используем полное название группы с диапазоном возрастов
                                'type' => 'start',
                                'participants' => [],
                                'created_at' => date('Y-m-d H:i:s'),
                                'redisKey' => $protocolKey,
                                'autoFilled' => true
                            ];
                            
                            // Отмечаем дисциплину как обработанную
                            $disciplineKey = "{$class}_{$sex}_{$distance}_{$ageGroup}";
                            if (!in_array($disciplineKey, $processedDisciplines)) {
                                $processedDisciplines[] = $disciplineKey;
                                error_log("Автозаполнение: создан протокол для дисциплины $disciplineKey");
                            }
                        }
                    }

                    // Проверяем, нет ли уже такого участника в протоколе
                    $participantExists = false;
                    foreach ($protocolsData[$protocolKey]['participants'] as $existingParticipant) {
                        if ($existingParticipant['userId'] == $participant['userid']) {
                            $participantExists = true;
                            break;
                        }
                    }

                    if (!$participantExists) {
                        // Создаем данные участника для протокола
                        $participantForProtocol = [
                            'userId' => $participant['userid'],
                            'fio' => $participant['fio'],
                            'sex' => $participant['sex'],
                            'birthdata' => $participant['birthdata'],
                            'sportzvanie' => $participant['sportzvanie'] ?? 'БР',
                            'teamName' => $participant['city'] ?? '',
                            'teamCity' => $participant['city'] ?? '',
                            'lane' => null,
                            'place' => null,
                            'finishTime' => null,
                            'addedManually' => false,
                            'addedAt' => date('Y-m-d H:i:s')
                        ];

                        // Добавляем участника в протокол
                        $protocolsData[$protocolKey]['participants'][] = $participantForProtocol;
                        
                        error_log("Автозаполнение: добавлен участник {$participant['fio']} в протокол $protocolKey");
                        $totalAdded++;
                    }
                }
            }
        }
    }

    error_log("Автозаполнение: всего создано протоколов: " . count($protocolsData));
    error_log("Автозаполнение: ключи протоколов: " . implode(', ', array_keys($protocolsData)));
    
    // Сохраняем все протоколы
    foreach ($protocolsData as $protocolKey => $protocolData) {
        // Сохраняем в Redis с правильным ключом
        if ($redis) {
            $redisKey = "protocol:{$protocolKey}";
            $redis->setex($redisKey, 86400 * 7, json_encode($protocolData));
        }

        // Сохраняем в JSON файл
        $jsonDir = __DIR__ . "/../../files/json/protocols/{$meroId}";
        if (!is_dir($jsonDir)) {
            mkdir($jsonDir, 0777, true);
        }
        
        $jsonFile = $jsonDir . "/" . basename($protocolKey) . "_start.json";
        file_put_contents($jsonFile, json_encode($protocolData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Создаем финишный протокол
        $finishProtocolKey = "finish_{$protocolKey}";
        $finishProtocolData = $protocolData;
        $finishProtocolData['type'] = 'finish';
        $finishProtocolData['name'] = $protocolData['name']; // Сохраняем правильное название группы
        $finishProtocolData['redisKey'] = $finishProtocolKey;
        
        if ($redis) {
            $finishRedisKey = "protocol:finish:{$protocolKey}";
            $redis->setex($finishRedisKey, 86400 * 7, json_encode($finishProtocolData));
        }

        $finishJsonFile = $jsonDir . "/" . basename($finishProtocolKey) . "_finish.json";
        file_put_contents($finishJsonFile, json_encode($finishProtocolData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    echo json_encode([
        'success' => true,
        'message' => "Автоматическое заполнение завершено. Добавлено участников: $totalAdded, создано протоколов: " . count($protocolsData),
        'totalAdded' => $totalAdded,
        'protocolsCreated' => count($protocolsData),
        'errors' => $errors
    ]);

} catch (Exception $e) {
    error_log("Ошибка автоматического заполнения протоколов: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка автоматического заполнения протоколов: ' . $e->getMessage()
    ]);
}

/**
 * Вычисление возрастной группы по алгоритму из class_distance
 */
function calculateAgeGroupFromClassDistance($age, $sex, $classDistance, $class) {
    if (!isset($classDistance[$class])) {
        return null;
    }
    
    $classData = $classDistance[$class];
    $sexes = $classData['sex'] ?? [];
    $ageGroups = $classData['age_group'] ?? [];
    
    // Находим индекс пола
    $sexIndex = array_search($sex, $sexes);
    if ($sexIndex === false) {
        return null;
    }
    
    // Получаем строку возрастных групп для данного пола
    $ageGroupString = $ageGroups[$sexIndex] ?? '';
    if (empty($ageGroupString)) {
        return null;
    }
    
    // Разбираем возрастные группы
    $availableAgeGroups = array_map('trim', explode(',', $ageGroupString));
    
    foreach ($availableAgeGroups as $ageGroupString) {
        // Разбираем группу: "группа 1: 18-29" -> ["группа 1", "18-29"]
        $parts = explode(': ', $ageGroupString);
        if (count($parts) !== 2) {
            continue;
        }
        
        $groupName = trim($parts[0]);
        $ageRange = trim($parts[1]);
        
        // Разбираем диапазон: "18-29" -> [18, 29]
        $ageLimits = explode('-', $ageRange);
        if (count($ageLimits) !== 2) {
            continue;
        }
        
        $minAge = (int)$ageLimits[0];
        $maxAge = (int)$ageLimits[1];
        
        // Проверяем, входит ли возраст в диапазон
        if ($age >= $minAge && $age <= $maxAge) {
            return $ageGroupString; // Возвращаем полное название группы
        }
    }
    
    // Если группа не найдена, возвращаем null
    return null;
}
?> 
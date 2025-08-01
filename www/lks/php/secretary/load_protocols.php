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
    error_log("🔄 [LOAD_PROTOCOLS] Сырые входящие данные: " . $rawInput);
    
    $data = json_decode($rawInput, true);
    
    // Если JSON не распарсился, пробуем получить из $_POST
    if ($data === null && !empty($_POST)) {
        $data = $_POST;
        error_log("🔄 [LOAD_PROTOCOLS] Используем данные из $_POST: " . json_encode($data));
    }
}

// Логируем входящие данные для отладки
error_log("🔄 [LOAD_PROTOCOLS] Обработанные входящие данные: " . json_encode($data));

// Проверяем обязательные параметры
if (!isset($data['meroId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Не указан ID мероприятия']);
    exit;
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
    
    error_log("🔄 [LOAD_PROTOCOLS] Загрузка протоколов для мероприятия: {$data['meroId']}");
    
    $startProtocols = [];
    $finishProtocols = [];
    $debugInfo = [];
    
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
                    
                    error_log("🔄 [LOAD_PROTOCOLS] Обрабатываем дисциплину: $class $sex {$distance}м");
                    
                    // Загружаем протоколы для каждой возрастной группы
                    foreach ($parsedAgeGroups as $ageGroupIndex => $ageGroup) {
                        $ageGroupName = $ageGroup['full_name'];
                        $ageGroupKey = str_replace(['(', ')', ' ', ':', '-'], ['', '', '_', '_', '_'], $ageGroupName);
                        
                        // Пытаемся загрузить из Redis
                        $redisKey = "protocol:start:{$data['meroId']}:{$class}:{$sex}:{$distance}:{$ageGroupKey}";
                        $protocolData = null;
                        
                        if ($redis) {
                            try {
                                $cachedData = $redis->get($redisKey);
                                if ($cachedData) {
                                    $protocolData = json_decode($cachedData, true);
                                    error_log("✅ [LOAD_PROTOCOLS] Протокол загружен из Redis: $redisKey");
                                }
                            } catch (Exception $e) {
                                error_log("⚠️ [LOAD_PROTOCOLS] Ошибка загрузки из Redis: " . $e->getMessage());
                            }
                        }
                        
                        // Если нет в Redis, пытаемся загрузить из JSON файла
                        if (!$protocolData) {
                            $jsonFileName = "{$data['meroId']}_{$class}_{$sex}_{$distance}_{$ageGroupKey}.json";
                            $jsonFilePath = __DIR__ . "/../../files/json/protocols/$jsonFileName";
                            
                            if (file_exists($jsonFilePath)) {
                                $jsonContent = file_get_contents($jsonFilePath);
                                $protocolData = json_decode($jsonContent, true);
                                error_log("✅ [LOAD_PROTOCOLS] Протокол загружен из JSON: $jsonFileName");
                                
                                // Сохраняем в Redis для кэширования
                                if ($redis && $protocolData) {
                                    try {
                                        $redis->setex($redisKey, 86400 * 7, json_encode($protocolData));
                                    } catch (Exception $e) {
                                        error_log("⚠️ [LOAD_PROTOCOLS] Ошибка сохранения в Redis: " . $e->getMessage());
                                    }
                                }
                            } else {
                                error_log("⚠️ [LOAD_PROTOCOLS] JSON файл не найден: $jsonFilePath");
                            }
                        }
                        
                        if ($protocolData) {
                            // Добавляем в соответствующие массивы
                            $protocolInfo = [
                                'discipline' => $class,
                                'sex' => $sex,
                                'distance' => $distance,
                                'ageGroup' => $ageGroupName,
                                'protocolNumber' => $ageGroupIndex + 1,
                                'participantsCount' => count($protocolData['ageGroups'][0]['participants'] ?? []),
                                'redisKey' => $redisKey,
                                'data' => $protocolData
                            ];
                            
                            $startProtocols[] = $protocolInfo;
                            $debugInfo[] = "✅ $class $sex {$distance}м - $ageGroupName: " . $protocolInfo['participantsCount'] . " участников";
                        } else {
                            // Создаем пустой протокол
                            $emptyProtocol = createEmptyProtocol($data['meroId'], $class, $sex, $distance, $ageGroupName, $ageGroupIndex + 1);
                            $startProtocols[] = $emptyProtocol;
                            $debugInfo[] = "⚠️ $class $sex {$distance}м - $ageGroupName: пустой протокол";
                        }
                    }
                }
            }
        }
    }
    
    error_log("🔄 [LOAD_PROTOCOLS] Всего загружено протоколов: " . count($startProtocols));
    
    echo json_encode([
        'success' => true,
        'message' => 'Протоколы успешно загружены',
        'startProtocols' => $startProtocols,
        'finishProtocols' => $finishProtocols,
        'debug' => [
            'totalStartProtocols' => count($startProtocols),
            'totalFinishProtocols' => count($finishProtocols),
            'disciplineDetails' => $debugInfo
        ]
    ]);
    
} catch (Exception $e) {
    error_log("ОШИБКА загрузки протоколов: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Создание пустого протокола
 */
function createEmptyProtocol($meroId, $class, $sex, $distance, $ageGroup, $protocolNumber) {
    $ageGroupKey = str_replace(['(', ')', ' ', ':', '-'], ['', '', '_', '_', '_'], $ageGroup);
    $redisKey = "protocol:start:{$meroId}:{$class}:{$sex}:{$distance}:{$ageGroupKey}";
    
    return [
        'discipline' => $class,
        'sex' => $sex,
        'distance' => $distance,
        'ageGroup' => $ageGroup,
        'protocolNumber' => $protocolNumber,
        'participantsCount' => 0,
        'redisKey' => $redisKey,
        'data' => [
            'meroId' => (int)$meroId,
            'discipline' => $class,
            'sex' => $sex,
            'distance' => $distance,
            'ageGroups' => [
                [
                    'name' => $ageGroup,
                    'protocol_number' => $protocolNumber,
                    'participants' => [],
                    'redisKey' => $redisKey,
                    'protected' => false
                ]
            ],
            'created_at' => date('Y-m-d H:i:s')
        ]
    ];
}
?> 
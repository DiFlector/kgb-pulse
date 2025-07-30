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
$rawInput = file_get_contents('php://input');
error_log("get_protocols_structure.php: Сырые данные: " . $rawInput);

$input = json_decode($rawInput, true);
error_log("get_protocols_structure.php: Декодированный запрос: " . json_encode($input));

$meroId = $input['meroId'] ?? null;
$disciplines = $input['disciplines'] ?? [];

error_log("get_protocols_structure.php: meroId = $meroId, disciplines = " . json_encode($disciplines));

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

    $protocolsStructure = [];
    
    // Логируем для отладки
    error_log("Фильтрация дисциплин: " . json_encode($disciplines));
    error_log("Доступные классы: " . json_encode(array_keys($classDistance)));

    // Обрабатываем каждую дисциплину
    foreach ($classDistance as $class => $classData) {
        // Проверяем, включена ли эта дисциплина в выбранные
        $shouldIncludeClass = empty($disciplines);
        if (!$shouldIncludeClass) {
            foreach ($disciplines as $discipline) {
                if (is_string($discipline)) {
                    // Формат: "K-1_М_200" - проверяем только класс
                    $parts = explode('_', $discipline);
                    if (count($parts) >= 1 && $parts[0] === $class) {
                        $shouldIncludeClass = true;
                        break;
                    }
                } else if (is_array($discipline) && isset($discipline['class'])) {
                    if ($discipline['class'] === $class) {
                        $shouldIncludeClass = true;
                        break;
                    }
                }
            }
        }
        
        if ($shouldIncludeClass && isset($classData['sex']) && isset($classData['dist']) && isset($classData['age_group'])) {
            error_log("Включаем класс: $class");
            error_log("Данные класса $class: " . json_encode($classData));
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
                    // Проверяем, включена ли эта конкретная комбинация класс+пол+дистанция
                    $shouldIncludeCombination = empty($disciplines);
                    if (!$shouldIncludeCombination) {
                        foreach ($disciplines as $discipline) {
                            if (is_string($discipline)) {
                                // Формат: "C-2_М_500" - проверяем класс+пол+дистанция
                                $parts = explode('_', $discipline);
                                if (count($parts) >= 3 && 
                                    $parts[0] === $class && 
                                    $parts[1] === $sex && 
                                    $parts[2] === $distance) {
                                    $shouldIncludeCombination = true;
                                    break;
                                }
                            } else if (is_array($discipline)) {
                                if (isset($discipline['class']) && isset($discipline['sex']) && isset($discipline['distance']) &&
                                    $discipline['class'] === $class && 
                                    $discipline['sex'] === $sex && 
                                    $discipline['distance'] === $distance) {
                                    $shouldIncludeCombination = true;
                                    break;
                                }
                            }
                        }
                    }
                    
                    if ($shouldIncludeCombination) {
                        // Проверяем, есть ли участники для данной комбинации класс+пол+дистанция
                        $hasParticipants = checkParticipantsForDiscipline($pdo, $meroId, $class, $sex, $distance);
                        
                        if ($hasParticipants) {
                            foreach ($ageGroupArray as $ageGroup) {
                                // Разбираем возрастную группу
                                $ageGroupData = parseAgeGroup($ageGroup);
                                
                                // Проверяем, есть ли участники для данной возрастной группы
                                $hasParticipantsForAgeGroup = checkParticipantsForAgeGroup($pdo, $meroId, $class, $sex, $distance, $ageGroupData[0]);
                                
                                if ($hasParticipantsForAgeGroup) {
                                    $protocolsStructure[] = [
                                        'class' => $class,
                                        'sex' => $sex,
                                        'distance' => $distance,
                                        'ageGroups' => $ageGroupData
                                    ];
                                } else {
                                    error_log("Пропускаем протокол для $class $sex $distance $ageGroup - нет участников для возрастной группы");
                                }
                            }
                        } else {
                            error_log("Пропускаем протокол для $class $sex $distance - нет участников");
                        }
                    }
                }
            }
        }
    }

    error_log("Возвращаемая структура протоколов: " . json_encode($protocolsStructure));
    echo json_encode([
        'success' => true,
        'structure' => $protocolsStructure
    ]);

} catch (Exception $e) {
    error_log("Ошибка получения структуры протоколов: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера']);
}

/**
 * Разбор строки возрастной группы
 * Пример: "группа 1: 27-49" -> {name: "группа 1", displayName: "группа 1: 27-49", minAge: 27, maxAge: 49}
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
 * Проверка наличия участников для дисциплины
 */
function checkParticipantsForDiscipline($pdo, $meroId, $class, $sex, $distance) {
    try {
        // Получаем oid мероприятия
        $stmt = $pdo->prepare("SELECT oid FROM meros WHERE champn = ?");
        $stmt->execute([$meroId]);
        $meroOid = $stmt->fetchColumn();
        
        if (!$meroOid) {
            return false;
        }
        
        // Проверяем наличие участников для данной дисциплины
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM listreg lr
            JOIN users u ON lr.users_oid = u.oid
            WHERE lr.meros_oid = ?
            AND lr.status IN ('Зарегистрирован', 'Подтверждён')
            AND lr.discipline::text LIKE ?
            AND u.sex = ?
        ");
        
        $classPattern = '%"' . $class . '"%';
        $stmt->execute([$meroOid, $classPattern, $sex]);
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($count > 0) {
            // Дополнительная проверка по дистанции
            $stmt = $pdo->prepare("
                SELECT lr.discipline
                FROM listreg lr
                JOIN users u ON lr.users_oid = u.oid
                WHERE lr.meros_oid = ?
                AND lr.status IN ('Зарегистрирован', 'Подтверждён')
                AND lr.discipline::text LIKE ?
                AND u.sex = ?
                LIMIT 1
            ");
            
            $stmt->execute([$meroOid, $classPattern, $sex]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row) {
                $disciplineData = json_decode($row['discipline'], true);
                if ($disciplineData && isset($disciplineData[$class])) {
                    $userDistances = $disciplineData[$class]['dist'] ?? [];
                    foreach ($userDistances as $userDistance) {
                        if (strpos($userDistance, $distance) !== false) {
                            return true;
                        }
                    }
                }
            }
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Ошибка проверки участников для дисциплины $class $sex $distance: " . $e->getMessage());
        return false;
    }
}

/**
 * Проверка наличия участников для конкретной возрастной группы
 */
function checkParticipantsForAgeGroup($pdo, $meroId, $class, $sex, $distance, $ageGroupData) {
    try {
        // Получаем oid мероприятия
        $stmt = $pdo->prepare("SELECT oid FROM meros WHERE champn = ?");
        $stmt->execute([$meroId]);
        $meroOid = $stmt->fetchColumn();
        
        if (!$meroOid) {
            return false;
        }
        
        $minAge = $ageGroupData['minAge'];
        $maxAge = $ageGroupData['maxAge'];
        
        // Проверяем наличие участников для данной возрастной группы
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM listreg lr
            JOIN users u ON lr.users_oid = u.oid
            WHERE lr.meros_oid = ?
            AND lr.status IN ('Зарегистрирован', 'Подтверждён')
            AND lr.discipline::text LIKE ?
            AND u.sex = ?
            AND EXTRACT(YEAR FROM AGE(CURRENT_DATE, u.birthdata)) BETWEEN ? AND ?
        ");
        
        $classPattern = '%"' . $class . '"%';
        $stmt->execute([$meroOid, $classPattern, $sex, $minAge, $maxAge]);
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        error_log("Проверка участников для $class $sex $distance возраст $minAge-$maxAge: найдено $count участников");
        
        return $count > 0;
        
    } catch (Exception $e) {
        error_log("Ошибка проверки участников для возрастной группы $class $sex $distance: " . $e->getMessage());
        return false;
    }
}
?> 
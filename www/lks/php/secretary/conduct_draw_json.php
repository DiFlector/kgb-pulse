<?php
/**
 * Проведение жеребьевки для протоколов
 * Файл: www/lks/php/secretary/conduct_draw_json.php
 */

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/JsonProtocolManager.php";

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
    if (!isset($data['groupKey'])) {
        throw new Exception('Отсутствует обязательное поле: groupKey');
    }
    
    $groupKey = $data['groupKey'];
    $preserveProtected = $data['preserveProtected'] ?? true; // По умолчанию сохраняем защищенные данные
    $meroId = $data['meroId'] ?? null;
    $discipline = $data['discipline'] ?? 'K-1';
    $sex = $data['sex'] ?? 'М';
    $distance = $data['distance'] ?? '200';
    $ageGroup = $data['ageGroup'] ?? 'группа 1';
    
    if (empty($groupKey)) {
        throw new Exception('Неверные параметры запроса');
    }
    
    error_log("🔄 [CONDUCT_DRAW] Жеребьевка для группы: $groupKey (сохранять защищенные: " . ($preserveProtected ? 'да' : 'нет') . ")");
    
    // Инициализируем менеджер JSON протоколов
    $protocolManager = JsonProtocolManager::getInstance();
    
    // Получаем данные протокола из JSON файла
    $protocolData = $protocolManager->loadProtocol($groupKey);
    
    // Если протокол не существует, создаем его с участниками
    if (!$protocolData) {
        error_log("⚠️ [CONDUCT_DRAW] Протокол не найден, создаем новый: $groupKey");
        
        if (!$meroId || !$discipline || !$sex || !$distance || !$ageGroup) {
            throw new Exception('Для создания нового протокола требуются все параметры: meroId, discipline, sex, distance, ageGroup');
        }
        
        // Извлекаем возрастные границы из названия группы
        if (preg_match('/группа\s+(\d+):\s*(\d+)-(\d+)/', $ageGroup, $matches)) {
            $groupName = $matches[1];
            $minAge = (int)$matches[2];
            $maxAge = (int)$matches[3];
        } elseif (preg_match('/группа\s+([^:]+):\s*(\d+)-(\d+)/', $ageGroup, $matches)) {
            // Для групп типа "группа Ю1: 0-12"
            $groupName = $matches[1];
            $minAge = (int)$matches[2];
            $maxAge = (int)$matches[3];
        } else {
            // Если не удалось распарсить, используем значения по умолчанию
            $groupName = 'группа 1';
            $minAge = 18;
            $maxAge = 29;
        }
        
        // Получаем участников для этой группы
        $db = Database::getInstance();
        $participants = getParticipantsForGroup($db, $meroId, $discipline, $sex, $distance, $minAge, $maxAge);
        
        // Создаем новый протокол
        $protocolData = [
            'name' => $ageGroup,
            'protocol_number' => 1,
            'participants' => $participants,
            'redisKey' => $groupKey,
            'protected' => false
        ];
        
        // Сохраняем новый протокол
        $protocolManager->saveProtocol($groupKey, $protocolData);
        error_log("✅ [CONDUCT_DRAW] Создан новый протокол: $groupKey");
    }
    
    if (!isset($protocolData['participants']) || !is_array($protocolData['participants'])) {
        error_log("❌ [CONDUCT_DRAW] Неверная структура протокола: groupKey=$groupKey");
        echo json_encode(['success' => false, 'message' => 'Неверная структура протокола']);
        exit();
    }
    
    // Определяем максимальное количество дорожек для типа лодки
    $maxLanes = getMaxLanesForBoat($discipline);
    
    // Сохраняем защищенные данные (дороги, места, время)
    $protectedData = [];
    if ($preserveProtected) {
        foreach ($protocolData['participants'] as $participant) {
            if (isset($participant['protected']) && $participant['protected']) {
                $protectedData[$participant['userId']] = [
                    'lane' => $participant['lane'] ?? null,
                    'water' => $participant['water'] ?? null,
                    'place' => $participant['place'] ?? null,
                    'finishTime' => $participant['finishTime'] ?? null
                ];
            }
        }
    }
    
    // Перемешиваем участников
    $participants = $protocolData['participants'];
    shuffle($participants);
    
    // Назначаем новые номера дорожек
    $laneNumber = 1;
    $assignedCount = 0;
    
    foreach ($participants as &$participant) {
        if ($laneNumber <= $maxLanes) {
            // Проверяем, есть ли защищенные данные для этого участника
            if ($preserveProtected && isset($protectedData[$participant['userId']])) {
                $protected = $protectedData[$participant['userId']];
                $participant['lane'] = $protected['lane'];
                $participant['water'] = $protected['water'];
                $participant['place'] = $protected['place'];
                $participant['finishTime'] = $protected['finishTime'];
                $participant['protected'] = true;
                error_log("🔄 [CONDUCT_DRAW] Сохранены защищенные данные для участника: {$participant['fio']}");
            } else {
                $participant['lane'] = $laneNumber;
                $participant['water'] = $laneNumber;
                $participant['protected'] = false;
                $assignedCount++;
            }
            $laneNumber++;
        } else {
            $participant['lane'] = null;
            $participant['water'] = null;
            $participant['protected'] = false;
        }
    }
    
    // Обновляем данные протокола
    $protocolData['participants'] = $participants;
    $protocolData['draw_conducted_at'] = date('Y-m-d H:i:s');
    $protocolData['draw_preserved_protected'] = $preserveProtected;
    
    // Сохраняем обновленный протокол в JSON файл
    $protocolManager->updateProtocol($groupKey, $protocolData);
    
    error_log("✅ [CONDUCT_DRAW] Жеребьевка завершена: назначено $assignedCount новых дорожек, сохранено " . count($protectedData) . " защищенных записей");
    
    echo json_encode([
        'success' => true,
        'message' => "Жеребьевка завершена успешно. Назначено $assignedCount новых дорожек.",
        'assigned_lanes' => $assignedCount,
        'preserved_protected' => count($protectedData),
        'max_lanes' => $maxLanes
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("❌ [CONDUCT_DRAW] Ошибка: " . $e->getMessage());
    
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
    
    error_log("🔍 [CONDUCT_DRAW] Поиск участников для группы {$boatClass}_{$sex}_{$distance} (возраст {$minAge}-{$maxAge}): найдено " . count($participants) . " участников");
    
    $filteredParticipants = [];
    $addedCount = 0;
    
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
                    $addedCount++;
                    
                    $filteredParticipants[] = [
                        'userId' => $participant['userid'],
                        'userid' => $participant['userid'], // Добавляем дублирующее поле для совместимости
                        'fio' => $participant['fio'],
                        'sex' => $participant['sex'],
                        'birthdata' => $participant['birthdata'],
                        'sportzvanie' => $participant['sportzvanie'],
                        'teamName' => $participant['teamname'] ?? '',
                        'teamCity' => $participant['teamcity'] ?? '',
                        'lane' => null,
                        'water' => null,
                        'place' => null,
                        'finishTime' => null,
                        'protected' => false,
                        'addedManually' => false,
                        'addedAt' => date('Y-m-d H:i:s')
                    ];
                }
            }
        }
    }
    
    error_log("✅ [CONDUCT_DRAW] Группа {$boatClass}_{$sex}_{$distance} (возраст {$minAge}-{$maxAge}): добавлено {$addedCount} участников");
    
    return $filteredParticipants;
}

/**
 * Получение максимального количества дорожек для типа лодки
 */
function getMaxLanesForBoat($boatClass) {
    switch ($boatClass) {
        case 'K-1':
        case 'C-1':
        case 'HD-1':
        case 'OD-1':
            return 8; // 8 дорожек для одиночных лодок
        case 'K-2':
        case 'C-2':
        case 'OD-2':
            return 6; // 6 дорожек для парных лодок
        case 'K-4':
        case 'C-4':
        case 'OC-1':
            return 4; // 4 дорожки для четверок
        case 'D-10':
            return 3; // 3 дорожки для драконов
        default:
            return 8; // По умолчанию 8 дорожек
    }
} 
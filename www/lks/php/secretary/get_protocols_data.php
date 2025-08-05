<?php
/**
 * Получение данных протоколов для отображения в интерфейсе
 * Файл: www/lks/php/secretary/get_protocols_data.php
 */

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/../common/JsonProtocolManager.php";

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
    $selectedDisciplines = $input['disciplines'] ?? null;
    
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
    
    // Инициализируем менеджер JSON протоколов
    $protocolManager = JsonProtocolManager::getInstance();
    
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
                // Проверяем, есть ли эта дисциплина в выбранных
                if ($selectedDisciplines && is_array($selectedDisciplines)) {
                    $disciplineFound = false;
                    foreach ($selectedDisciplines as $selectedDiscipline) {
                        if (is_array($selectedDiscipline)) {
                            // Если дисциплина передана как объект
                            if ($selectedDiscipline['class'] === $boatClass && 
                                $selectedDiscipline['sex'] === $sex && 
                                $selectedDiscipline['distance'] === $dist) {
                                $disciplineFound = true;
                                break;
                            }
                        } else {
                            // Если дисциплина передана как строка
                            $disciplineString = "{$boatClass}_{$sex}_{$dist}";
                            if ($selectedDiscipline === $disciplineString) {
                                $disciplineFound = true;
                                break;
                            }
                        }
                    }
                    
                    // Если дисциплина не выбрана, пропускаем её
                    if (!$disciplineFound) {
                        continue;
                    }
                }
                
                foreach ($ageGroupList as $ageGroup) {
                    // Извлекаем название группы
                    if (preg_match('/^(.+?):\s*(\d+)-(\d+)$/', $ageGroup, $matches)) {
                        $groupName = trim($matches[1]);
                        $minAge = (int)$matches[2];
                        $maxAge = (int)$matches[3];
                        
                        $redisKey = "protocol:{$meroId}:{$boatClass}:{$sex}:{$dist}:{$groupName}";
                        
                        // Пробуем загрузить протокол из JSON файла
                        $dataProtocol = $protocolManager->loadProtocol($redisKey);
                        
                        if ($dataProtocol) {
                            // Добавляем redisKey к данным протокола
                            $dataProtocol['redisKey'] = $redisKey;
                            
                            $protocolsData[] = [
                                'meroId' => (int)$meroId,
                                'discipline' => $boatClass,
                                'sex' => $sex,
                                'distance' => $dist,
                                'ageGroups' => [$dataProtocol],
                                'created_at' => date('Y-m-d H:i:s')
                            ];
                        } else {
                            // Если протокол не найден, создаем пустой
                            $emptyProtocol = [
                                'name' => $groupName,
                                'protocol_number' => count($protocolsData) + 1,
                                'participants' => [],
                                'redisKey' => $redisKey,
                                'protected' => false
                            ];
                            
                            $protocolsData[] = [
                                'meroId' => (int)$meroId,
                                'discipline' => $boatClass,
                                'sex' => $sex,
                                'distance' => $dist,
                                'ageGroups' => [$emptyProtocol],
                                'created_at' => date('Y-m-d H:i:s')
                            ];
                        }
                    }
                }
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'protocols' => $protocolsData,
        'total_protocols' => count($protocolsData)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("❌ [GET_PROTOCOLS_DATA] Ошибка: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} 
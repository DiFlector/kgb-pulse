<?php
/**
 * Получение структуры протоколов для отображения
 * Файл: www/lks/php/secretary/get_protocols_structure.php
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
    
    $protocolsStructure = [];
    
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
                        
                        $protocolsStructure[] = [
                            'meroId' => (int)$meroId,
                            'discipline' => $boatClass,
                            'sex' => $sex,
                            'distance' => $dist,
                            'ageGroups' => [
                                [
                                    'name' => $groupName,
                                    'minAge' => $minAge,
                                    'maxAge' => $maxAge,
                                    'displayName' => $ageGroup,
                                    'protocol_number' => count($protocolsStructure) + 1,
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
    
    echo json_encode([
        'success' => true,
        'protocols' => $protocolsStructure,
        'total_protocols' => count($protocolsStructure),
        'event' => [
            'id' => $event['oid'],
            'name' => $event['meroname'],
            'date' => $event['merodata'],
            'status' => $event['status']
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Ошибка получения структуры протоколов: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?> 
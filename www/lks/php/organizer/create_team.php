<?php
/**
 * API для создания новой команды
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/../db/Database.php";

$auth = new Auth();
if (!$auth->isAuthenticated() || !in_array($auth->getUserRole(), ['Organizer', 'SuperUser', 'Admin', 'Secretary'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Неверный формат данных');
    }
    
    $eventId = $input['eventId'] ?? null;
    $classType = $input['classType'] ?? null;
    $teamName = $input['teamName'] ?? null;
    $teamCity = $input['teamCity'] ?? null;
    $participants = $input['participants'] ?? [];
    $distances = $input['distances'] ?? [];
    
    if (!$eventId || !$classType || !$teamName || empty($participants)) {
        throw new Exception('Не указаны обязательные параметры');
    }
    
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    
    // Начинаем транзакцию
    $pdo->beginTransaction();
    
    try {
        // Получаем информацию о мероприятии
        $event = $db->fetchOne("
            SELECT oid, champn, meroname, class_distance, defcost
            FROM meros 
            WHERE oid = ?
        ", [$eventId]);
        
        if (!$event) {
            throw new Exception('Мероприятие не найдено');
        }
        
        // Проверяем, что класс существует в мероприятии
        $classDistance = json_decode($event['class_distance'], true);
        if (!isset($classDistance[$classType])) {
            throw new Exception('Указанный класс не поддерживается данным мероприятием');
        }
        
        // Определяем максимальное количество участников
        $maxParticipants = getBoatCapacity($classType);
        if (count($participants) > $maxParticipants) {
            throw new Exception("Превышено максимальное количество участников для класса {$classType}. Максимум: {$maxParticipants}");
        }
        
        // Генерируем уникальный teamid
        $teamId = generateUniqueTeamId($db);
        
        // Создаем запись команды
        $stmt = $pdo->prepare("
            INSERT INTO teams (teamid, teamname, teamcity, class, persons_amount, persons_all)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $teamId,
            $teamName,
            $teamCity,
            $classType,
            count($participants),
            $maxParticipants
        ]);
        
        $teamOid = $pdo->lastInsertId();
        
        // Создаем discipline для команды (все дистанции для выбранного класса)
        $discipline = [
            $classType => [
                'sex' => $classDistance[$classType]['sex'] ?? ['М', 'Ж'],
                'dist' => $distances
            ]
        ];
        
        // Создаем записи регистраций для каждого участника
        foreach ($participants as $participant) {
            $userId = $participant['userId'];
            $role = $participant['role'] ?? 'member';
            
            // Получаем существующую регистрацию участника
            $existingReg = $db->fetchOne("
                SELECT oid, cost, status
                FROM listreg 
                WHERE users_oid = ? AND meros_oid = ? AND teams_oid IS NULL
            ", [$userId, $eventId]);
            
            if ($existingReg) {
                // Обновляем существующую регистрацию
                $stmt = $pdo->prepare("
                    UPDATE listreg 
                    SET teams_oid = ?, role = ?, discipline = ?
                    WHERE oid = ?
                ");
                $stmt->execute([
                    $teamOid,
                    $role,
                    json_encode($discipline),
                    $existingReg['oid']
                ]);
            } else {
                // Создаем новую регистрацию
                $stmt = $pdo->prepare("
                    INSERT INTO listreg (users_oid, meros_oid, teams_oid, discipline, role, status, cost, oplata)
                    VALUES (?, ?, ?, ?, ?, 'Подтверждён', ?, false)
                ");
                $stmt->execute([
                    $userId,
                    $eventId,
                    $teamOid,
                    json_encode($discipline),
                    $role,
                    $event['defcost'] ?? 0
                ]);
            }
        }
        
        // Подтверждаем транзакцию
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Команда успешно создана',
            'teamId' => $teamId,
            'teamOid' => $teamOid
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Ошибка создания команды: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Получает вместимость лодки по классу
 */
function getBoatCapacity($boatType) {
    $capacities = [
        'K-2' => 2,
        'K-4' => 4,
        'C-2' => 2,
        'C-4' => 4,
        'D-10' => 14, // 10 гребцов + рулевой + барабанщик + 2 резерва
        'HD-1' => 1,
        'OD-1' => 1,
        'OD-2' => 2,
        'OC-1' => 1
    ];
    
    return $capacities[$boatType] ?? 1;
}

/**
 * Генерирует уникальный teamid
 */
function generateUniqueTeamId($db) {
    do {
        $teamId = rand(1000, 9999);
        $existing = $db->fetchOne("SELECT COUNT(*) as count FROM teams WHERE teamid = ?", [$teamId]);
    } while ($existing['count'] > 0);
    
    return $teamId;
}
?> 
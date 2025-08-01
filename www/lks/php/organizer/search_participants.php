<?php
/**
 * Поиск участников мероприятия для добавления в команду
 */

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/../db/Database.php";

$auth = new Auth();
if (!$auth->isAuthenticated() || !in_array($auth->getUserRole(), ['Organizer', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

try {
    // Получаем JSON данные
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Некорректные данные запроса');
    }
    
    $search = trim($input['search'] ?? '');
    $champn = $input['champn'] ?? null;
    $excludeTeamId = $input['excludeTeamId'] ?? null;
    $teamClass = $input['teamClass'] ?? null; // Добавляем класс команды
    
    if (empty($search) || empty($champn)) {
        throw new Exception('Не указаны параметры поиска');
    }
    
    if (strlen($search) < 2) {
        throw new Exception('Минимальная длина поиска - 2 символа');
    }
    
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    
    // Поиск участников мероприятия, исключая текущую команду
    $sql = "
        SELECT 
            lr.oid,
            lr.users_oid,
            lr.teams_oid,
            lr.status,
            lr.discipline,
            u.fio,
            u.email,
            u.telephone,
            u.userid
        FROM listreg lr
        JOIN users u ON lr.users_oid = u.oid
        JOIN meros m ON lr.meros_oid = m.oid
        WHERE m.champn = ?
        AND (lr.teams_oid IS NULL OR lr.teams_oid != ?)
        AND (
            LOWER(u.fio) LIKE LOWER(?) 
            OR LOWER(u.email) LIKE LOWER(?) 
            OR u.telephone LIKE ?
        )
        AND lr.status IN ('В очереди', 'Подтверждён', 'Ожидание команды')
        ORDER BY u.fio
        LIMIT 20
    ";
    
    $searchPattern = '%' . $search . '%';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $champn,
        $excludeTeamId ?: 0, // Если excludeTeamId null, используем 0
        $searchPattern,
        $searchPattern,
        $searchPattern
    ]);
    
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Дополнительно проверяем совместимость дисциплин
    $filteredParticipants = [];
    
    foreach ($participants as $participant) {
        // Проверяем, что участник подходит для команды
        $classDistance = json_decode($participant['discipline'], true);
        $isCompatible = false;
        
        if (is_array($classDistance)) {
            foreach ($classDistance as $boatType => $details) {
                // Если указан класс команды, проверяем совместимость
                if ($teamClass && $boatType === $teamClass) {
                    $isCompatible = true;
                    break;
                }
                // Если класс команды не указан, принимаем всех участников
                if (!$teamClass) {
                    $isCompatible = true;
                    break;
                }
            }
        }
        
        if ($isCompatible) {
            // Добавляем информацию о команде, если участник уже в команде
            if ($participant['teams_oid']) {
                $stmt = $pdo->prepare("SELECT teamid FROM teams WHERE oid = ?");
                $stmt->execute([$participant['teams_oid']]);
                $participant['teamid'] = $stmt->fetchColumn();
            } else {
                $participant['teamid'] = null;
            }
            
            $filteredParticipants[] = $participant;
        }
    }
    
    echo json_encode([
        'success' => true,
        'participants' => $filteredParticipants
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?> 
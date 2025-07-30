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
            u.telephone
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
        // Проверяем, что участник подходит для команды драконов
        $classDistance = json_decode($participant['discipline'], true);
        $isDragonCompatible = false;
        
        if (is_array($classDistance)) {
            foreach ($classDistance as $boatType => $details) {
                if ($boatType === 'D-10') {
                    $isDragonCompatible = true;
                    break;
                }
            }
        }
        
        if ($isDragonCompatible) {
            $filteredParticipants[] = $participant;
        }
    }
    
    echo json_encode([
        'success' => true,
        'participants' => $filteredParticipants,
        'total' => count($filteredParticipants)
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка поиска участников: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?> 
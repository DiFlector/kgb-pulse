<?php
/**
 * Исправление ролей в существующих командах драконов D-10
 * Автоматически распределяет роли по правильной схеме
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Проверка авторизации - только для админов и суперпользователей
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Admin', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Нет доступа'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../db/Database.php';
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    
    // Начинаем транзакцию
    $pdo->beginTransaction();
    
    // Находим все команды драконов D-10 где участники имеют неправильные роли
    $dragonTeams = $db->fetchAll("
        SELECT DISTINCT lr.teams_oid, t.teamid, t.teamname
        FROM listreg lr
        JOIN teams t ON lr.teams_oid = t.oid
        WHERE lr.teams_oid IS NOT NULL 
        AND lr.discipline::text LIKE '%D-10%'
        AND lr.teams_oid IN (
            SELECT teams_oid 
            FROM listreg 
            WHERE teams_oid IS NOT NULL 
            GROUP BY teams_oid 
            HAVING COUNT(CASE WHEN role = 'captain' THEN 1 END) = 0
        )
        ORDER BY t.teamid
    ");
    
    $fixedTeams = 0;
    $totalParticipants = 0;
    $errors = [];
    
    foreach ($dragonTeams as $team) {
        try {
            $teamOid = $team['teams_oid'];
            $teamId = $team['teamid'];
            
            // Получаем всех участников команды
            $participants = $db->fetchAll("
                SELECT oid, users_oid, role
                FROM listreg 
                WHERE teams_oid = ?
                ORDER BY oid ASC
            ", [$teamOid]);
            
            if (empty($participants)) {
                continue;
            }
            
            // Распределяем роли
            $roleAssignments = [];
            $participantCount = count($participants);
            
            // Гибкое распределение ролей для команд драконов D-10
            for ($i = 0; $i < $participantCount; $i++) {
                $participant = $participants[$i];
                
                if ($i === 0) {
                    // Первый участник - капитан
                    $newRole = 'captain';
                } elseif ($i >= 1 && $i <= 12) {
                    // Участники 2-13 - гребцы (members)
                    $newRole = 'member';
                } else {
                    // Участники 14+ - резервисты
                    $newRole = 'reserve';
                }
                
                // Обновляем роль если она изменилась
                if ($participant['role'] !== $newRole) {
                    $db->execute("
                        UPDATE listreg 
                        SET role = ? 
                        WHERE oid = ?
                    ", [$newRole, $participant['oid']]);
                    
                    $roleAssignments[] = [
                        'oid' => $participant['oid'],
                        'old_role' => $participant['role'],
                        'new_role' => $newRole
                    ];
                }
            }
            
            if (!empty($roleAssignments)) {
                $fixedTeams++;
                $totalParticipants += count($roleAssignments);
            }
            
        } catch (Exception $e) {
            $errors[] = "Команда {$teamId}: " . $e->getMessage();
        }
    }
    
    // Фиксируем изменения
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Роли в командах драконов исправлены успешно',
        'statistics' => [
            'total_teams_found' => count($dragonTeams),
            'fixed_teams' => $fixedTeams,
            'updated_participants' => $totalParticipants,
            'errors' => count($errors)
        ],
        'error_details' => $errors
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Откатываем изменения при ошибке
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка исправления ролей: ' . $e->getMessage(),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
}
?> 
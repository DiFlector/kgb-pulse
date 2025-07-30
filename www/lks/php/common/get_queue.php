<?php
/**
 * API для получения данных очереди спортсменов и неполных команд
 */

require_once __DIR__ . '/../db/Database.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

// Проверяем, не запущена ли уже сессия
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    // Простая проверка авторизации через сессию
    if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Требуется авторизация'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (!isset($_SESSION['user_role'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Роль пользователя не определена'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userRole = $_SESSION['user_role'];
    
    // Проверка прав доступа
    if (!in_array($userRole, ['Admin', 'Organizer', 'Secretary', 'SuperUser'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Недостаточно прав доступа'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Подключение к БД
    $db = Database::getInstance();
    $conn = $db->getPDO();

    // 1. Спортсмены в статусе "В очереди" (одиночники без команды)
    $queueQuery = "
        SELECT 
            lr.oid,
            lr.users_oid,
            lr.meros_oid,
            lr.teams_oid,
            lr.discipline,
            lr.oplata,
            lr.cost,
            lr.status,
            lr.role,
            u.userid,
            u.fio,
            u.email,
            u.telephone,
            u.sex,
            u.city,
            u.boats,
            u.sportzvanie,
            t.teamname,
            t.teamcity,
            m.meroname,
            m.merodata
        FROM listreg lr
        LEFT JOIN users u ON lr.users_oid = u.oid
        LEFT JOIN teams t ON lr.teams_oid = t.oid
        LEFT JOIN meros m ON lr.meros_oid = m.oid
        WHERE lr.status = 'В очереди' OR (lr.status = 'Ожидание команды' AND lr.teams_oid IS NULL)
        ORDER BY m.merodata ASC, u.fio ASC
    ";

    $queueStmt = $conn->prepare($queueQuery);
    $queueStmt->execute();
    $queueParticipants = $queueStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Спортсмены в неполных командах (статус "Ожидание команды")
    $incompleteTeamsQuery = "
        SELECT 
            lr.oid,
            lr.users_oid,
            lr.teams_oid,
            lr.discipline,
            lr.oplata,
            lr.cost,
            lr.status,
            lr.role,
            u.userid,
            u.fio,
            u.email,
            u.telephone,
            u.sex,
            u.city,
            u.boats,
            u.sportzvanie,
            t.teamid,
            t.teamname,
            t.teamcity,
            m.champn,
            m.meroname,
            COUNT(*) OVER (PARTITION BY lr.teams_oid, lr.discipline) as team_size
        FROM listreg lr
        LEFT JOIN users u ON lr.users_oid = u.oid
        LEFT JOIN teams t ON lr.teams_oid = t.oid
        LEFT JOIN meros m ON lr.meros_oid = m.oid
        WHERE lr.status = 'Ожидание команды'
        ORDER BY lr.teams_oid ASC, m.merodata ASC, u.fio ASC
    ";

    $incompleteStmt = $conn->prepare($incompleteTeamsQuery);
    $incompleteStmt->execute();
    $allTeamParticipants = $incompleteStmt->fetchAll(PDO::FETCH_ASSOC);

    // Анализ неполных команд
    $incompleteTeams = [];
    $teamAnalysis = [];

    foreach ($allTeamParticipants as $participant) {
        $teamKey = $participant['teams_oid'] . '_' . $participant['discipline'];
        
        if (!isset($teamAnalysis[$teamKey])) {
            $teamAnalysis[$teamKey] = [
                'teams_oid' => $participant['teams_oid'],
                'teamid' => $participant['teamid'],
                'teamname' => $participant['teamname'],
                'teamcity' => $participant['teamcity'],
                'discipline' => $participant['discipline'],
                'champn' => $participant['champn'],
                'meroname' => $participant['meroname'],
                'participants' => [],
                'class_distances' => []
            ];
        }
        
        $teamAnalysis[$teamKey]['participants'][] = $participant;
        
        // Собираем все классы лодок для команды
        if ($participant['discipline']) {
            $classDistances = json_decode($participant['discipline'], true);
            if ($classDistances && is_array($classDistances)) {
                foreach ($classDistances as $boatType => $details) {
                    $teamAnalysis[$teamKey]['class_distances'][$boatType] = true;
                }
            }
        }
    }

    // Все команды со статусом "Ожидание команды" считаются неполными
    foreach ($teamAnalysis as $teamKey => $team) {
        $reasons = [];
        
        foreach ($team['class_distances'] as $boatType => $dummy) {
            $teamSize = count($team['participants']);
            
            if ($boatType === 'D-10') {
                                    // Логика для драконов (основной состав: 10 гребцов + барабанщик + рулевой, + до 2 резервов)
                $roles = array_column($team['participants'], 'role');
                $hasHelmsman = in_array('coxswain', $roles);
                $hasDrummer = in_array('drummer', $roles);
                $paddlers = array_filter($roles, function($role) {
                    return !in_array($role, ['coxswain', 'drummer', 'reserve']);
                });
                $reserves = array_filter($roles, function($role) {
                    return $role === 'reserve';
                });
                
                $paddlerCount = count($paddlers);
                $reserveCount = count($reserves);
                
                if (!$hasHelmsman) {
                    $reasons[] = 'Нет рулевого';
                }
                if (!$hasDrummer) {
                    $reasons[] = 'Нет барабанщика';
                }
                if ($paddlerCount < 10) {
                    $reasons[] = 'Недостаточно гребцов (' . $paddlerCount . ' из 10)';
                }
                
                // Дополнительная информация о резервах (не критично)
                if ($reserveCount > 0) {
                    $reasons[] = 'Резервов: ' . $reserveCount . ' из 2 (дополнительно)';
                }
            } else {
                // УНИВЕРСАЛЬНАЯ логика для обычных лодок через цифру в названии
                $requiredSize = getBoatCapacity($boatType);
                if ($requiredSize > 1 && $teamSize < $requiredSize) {
                    $reasons[] = "Недостаточно участников для $boatType ($teamSize из $requiredSize)";
                }
            }
        }
        
        $team['reasons'] = $reasons;
        $team['isDragon'] = array_key_exists('D-10', $team['class_distances']);
        
        // Определяем, можно ли подтвердить команду (нет критических проблем)
        $team['canConfirm'] = empty($reasons) || count(array_filter($reasons, function($reason) {
            return !str_contains($reason, 'Недостаточно') && !str_contains($reason, 'Нет рулевого') && !str_contains($reason, 'Нет барабанщика');
        })) === count($reasons);
        
        $incompleteTeams[] = $team;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'queue_participants' => $queueParticipants,
            'incomplete_teams' => $incompleteTeams
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?> 
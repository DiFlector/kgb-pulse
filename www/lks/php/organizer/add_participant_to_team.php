<?php
// API для добавления участника в команду - Организатор
session_start();

// Проверка авторизации
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Organizer', 'Admin', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

require_once __DIR__ . '/../db/Database.php';

$db = Database::getInstance();

// Получаем данные
$input = json_decode(file_get_contents('php://input'), true);

// Поддержка разных форматов параметров
$userId = $input['user_id'] ?? $input['oid'] ?? null;
$teamId = $input['team_id'] ?? null;
$eventId = $input['event_id'] ?? null;
$role = $input['role'] ?? 'member';
$discipline = $input['discipline'] ?? null;

// Если передан champn вместо event_id, получаем event_id
if (!$eventId && isset($input['champn'])) {
    $champn = $input['champn'];
    $eventQuery = "SELECT oid FROM meros WHERE champn = ?";
    $eventStmt = $db->prepare($eventQuery);
    $eventStmt->execute([$champn]);
    $eventResult = $eventStmt->fetch(PDO::FETCH_ASSOC);
    if ($eventResult) {
        $eventId = $eventResult['oid'];
    }
}

// Отладочная информация
error_log("DEBUG: add_participant_to_team.php - userId: $userId, teamId: $teamId, eventId: $eventId, role: $role");

if (!$userId || !$teamId || !$eventId) {
    echo json_encode(['success' => false, 'message' => 'Не указаны обязательные параметры']);
    exit;
}

try {
    // Проверяем, существует ли участник
    $userQuery = "SELECT oid, userid, fio, email FROM users WHERE oid = ?";
    $userStmt = $db->prepare($userQuery);
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Участник не найден']);
        exit;
    }
    
    error_log("DEBUG: Участник найден - {$user['fio']} (ID: {$user['userid']})");
    
    // Проверяем, существует ли команда (убираем строгую проверку для ручного добавления)
    $teamQuery = "SELECT oid, teamid, teamname, persons_amount, persons_all, class FROM teams WHERE teamid = ?";
    $teamStmt = $db->prepare($teamQuery);
    $teamStmt->execute([$teamId]);
    $team = $teamStmt->fetch(PDO::FETCH_ASSOC);
    
    // Если команда не найдена, создаем временную команду
    if (!$team) {
        error_log("DEBUG: Команда не найдена, создаем временную команду");
        // Создаем временную команду
        $createTeamQuery = "INSERT INTO teams (teamid, teamname, teamcity, persons_amount, persons_all, class) VALUES (?, ?, ?, ?, ?, ?)";
        $createTeamStmt = $db->prepare($createTeamQuery);
        $tempTeamId = time(); // Временный ID команды
        $createTeamStmt->execute([$tempTeamId, 'Временная команда', 'Не указан', 0, 14, 'D-10']);
        
        $teamOid = $db->lastInsertId();
        
        $team = [
            'oid' => $teamOid,
            'teamid' => $tempTeamId,
            'teamname' => 'Временная команда',
            'persons_amount' => 0,
            'persons_all' => 14
        ];
        error_log("DEBUG: Временная команда создана - OID: $teamOid");
    } else {
        error_log("DEBUG: Команда найдена - {$team['teamname']} (OID: {$team['oid']})");
    }
    
    // Проверяем, существует ли мероприятие
    $eventQuery = "SELECT oid, champn, meroname, defcost FROM meros WHERE oid = ?";
    $eventStmt = $db->prepare($eventQuery);
    $eventStmt->execute([$eventId]);
    $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$event) {
        echo json_encode(['success' => false, 'message' => 'Мероприятие не найдено']);
        exit;
    }
    
    error_log("DEBUG: Мероприятие найдено - {$event['meroname']} (ID: {$event['champn']})");
    
    // УБИРАЕМ ВСЕ ПРОВЕРКИ ДЛЯ РУЧНОГО ДОБАВЛЕНИЯ!
    // Просто добавляем участника в команду, игнорируя все ограничения
    
    // Проверяем, есть ли уже регистрация участника на это мероприятие
    $existingRegQuery = "SELECT oid, status, teams_oid, discipline FROM listreg WHERE users_oid = ? AND meros_oid = ?";
    $existingRegStmt = $db->prepare($existingRegQuery);
    $existingRegStmt->execute([$userId, $eventId]);
    $existingReg = $existingRegStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingReg) {
        error_log("DEBUG: Участник уже зарегистрирован, обновляем команду (РУЧНОЕ ДОБАВЛЕНИЕ)");
        
        // ПРОВЕРКА: если участник уже в ЭТОЙ команде, не добавляем дубликат
        if ($existingReg['teams_oid'] == $team['oid']) {
            echo json_encode(['success' => false, 'message' => 'Участник уже зарегистрирован в этой команде']);
            exit;
        }
        
        // Обновляем существующую регистрацию, принудительно добавляя в новую команду
        // Автоматически устанавливаем дисциплину участника такой же, как у команды
        $disciplineToUse = $discipline;
        if (!$disciplineToUse && $team['class']) {
            // Если дисциплина не указана, используем дисциплину команды
            // Определяем основную дистанцию команды из названия
            $primaryDistance = "200"; // По умолчанию 200м
            if (preg_match('/(\d+)m/', $team['teamname'], $matches)) {
                $primaryDistance = $matches[1];
            } elseif (preg_match('/(\d+)м/', $team['teamname'], $matches)) {
                $primaryDistance = $matches[1];
            } elseif (preg_match('/(\d+)\s*м/', $team['teamname'], $matches)) {
                $primaryDistance = $matches[1];
            }
            
            $disciplineToUse = [
                $team['class'] => [
                    "sex" => ["M"], // По умолчанию только мужчины
                    "dist" => [$primaryDistance]
                ]
            ];
        } elseif (!$disciplineToUse) {
            // Если дисциплина команды тоже не указана, используем существующую дисциплину участника
            $disciplineToUse = $existingReg['discipline'];
        }
        
        $updateQuery = "
            UPDATE listreg 
            SET teams_oid = ?, role = ?, discipline = ?, status = 'Подтверждён'
            WHERE oid = ?
        ";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->execute([$team['oid'], $role, json_encode($disciplineToUse), $existingReg['oid']]);
        
        error_log("DEBUG: Регистрация обновлена - участник добавлен в команду {$team['teamname']}");
        
    } else {
        error_log("DEBUG: Создаем новую регистрацию участника");
        
        // ДОПОЛНИТЕЛЬНАЯ ПРОВЕРКА: проверяем, есть ли уже регистрация в этой команде
        $existingInTeamQuery = "SELECT oid FROM listreg WHERE users_oid = ? AND meros_oid = ? AND teams_oid = ?";
        $existingInTeamStmt = $db->prepare($existingInTeamQuery);
        $existingInTeamStmt->execute([$userId, $eventId, $team['oid']]);
        $existingInTeam = $existingInTeamStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingInTeam) {
            echo json_encode(['success' => false, 'message' => 'Участник уже зарегистрирован в этой команде']);
            exit;
        }
        
        // Автоматически устанавливаем дисциплину участника такой же, как у команды
        $disciplineToUse = $discipline;
        if (!$disciplineToUse && $team['class']) {
            // Если дисциплина не указана, используем дисциплину команды
            // Определяем основную дистанцию команды из названия
            $primaryDistance = "200"; // По умолчанию 200м
            if (preg_match('/(\d+)m/', $team['teamname'], $matches)) {
                $primaryDistance = $matches[1];
            } elseif (preg_match('/(\d+)м/', $team['teamname'], $matches)) {
                $primaryDistance = $matches[1];
            } elseif (preg_match('/(\d+)\s*м/', $team['teamname'], $matches)) {
                $primaryDistance = $matches[1];
            }
            
            $disciplineToUse = [
                $team['class'] => [
                    "sex" => ["M"], // По умолчанию только мужчины
                    "dist" => [$primaryDistance]
                ]
            ];
        }
        
        // Создаем новую регистрацию
        $insertQuery = "
            INSERT INTO listreg (users_oid, meros_oid, teams_oid, role, discipline, status, cost)
            VALUES (?, ?, ?, ?, ?, 'Подтверждён', ?)
        ";
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->execute([
            $userId,
            $eventId,
            $team['oid'],
            $role,
            json_encode($disciplineToUse),
            $event['defcost']
        ]);
        
        $newRegId = $db->lastInsertId();
        error_log("DEBUG: Новая регистрация создана - ID: $newRegId");
    }
    
    // Обновляем количество участников в команде
    $updateTeamQuery = "
        UPDATE teams 
        SET persons_amount = (
            SELECT COUNT(*) FROM listreg 
            WHERE teams_oid = ? AND status != 'Неявка'
        )
        WHERE oid = ?
    ";
    $updateTeamStmt = $db->prepare($updateTeamQuery);
    $updateTeamStmt->execute([$team['oid'], $team['oid']]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Участник успешно добавлен в команду',
        'user' => $user,
        'team' => $team,
        'action' => $existingReg ? 'updated' : 'added'
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка добавления участника в команду: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка добавления участника в команду']);
} 
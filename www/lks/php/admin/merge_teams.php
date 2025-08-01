<?php
/**
 * Объединение команд для администратора
 * Перемещает всех участников из выбранных команд в основную команду
 */

// Очищаем буфер и устанавливаем заголовки
ob_clean();
if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

// Запуск сессии
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Проверка авторизации
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Admin', 'SuperUser'])) {
    echo json_encode(['success' => false, 'message' => 'Нет доступа']);
    exit;
}

// Функция для безопасного JSON ответа
function sendJson($data) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        echo '{"success":false,"message":"JSON encoding error"}';
    } else {
        echo $json;
    }
    exit;
}

/**
 * Проверяет совместимость команд для объединения
 * @param object $db - объект базы данных
 * @param int $mainTeamId - ID основной команды
 * @param array $teamsToMerge - массив команд для объединения
 * @param int $eventId - ID мероприятия
 * @return array - результат проверки
 */
function checkTeamsCompatibility($db, $mainTeamId, $teamsToMerge, $eventId) {
    $errors = [];
    $totalParticipants = 0;
    $mainTeamDisciplines = [];
    $mainTeamSex = null;
    
    // Получаем участников основной команды
    $mainTeamParticipants = $db->fetchAll("
        SELECT lr.discipline, u.sex
        FROM listreg lr
        JOIN users u ON lr.users_oid = u.oid
        WHERE lr.teams_oid = ? AND lr.meros_oid = ?
    ", [$mainTeamId, $eventId]);
    
    $totalParticipants += count($mainTeamParticipants);
    
    // Анализируем дисциплины основной команды
    foreach ($mainTeamParticipants as $participant) {
        $discipline = json_decode($participant['discipline'], true);
        if ($discipline) {
            foreach ($discipline as $class => $details) {
                if (!isset($mainTeamDisciplines[$class])) {
                    $mainTeamDisciplines[$class] = [
                        'distances' => [],
                        'sex' => []
                    ];
                }
                
                if (isset($details['dist'])) {
                    $distances = is_array($details['dist']) ? $details['dist'] : explode(', ', $details['dist']);
                    foreach ($distances as $distStr) {
                        $distArray = array_map('trim', explode(',', $distStr));
                        $mainTeamDisciplines[$class]['distances'] = array_merge(
                            $mainTeamDisciplines[$class]['distances'], 
                            $distArray
                        );
                    }
                }
                
                if (isset($details['sex'])) {
                    $sexArray = is_array($details['sex']) ? $details['sex'] : [$details['sex']];
                    $mainTeamDisciplines[$class]['sex'] = array_merge(
                        $mainTeamDisciplines[$class]['sex'], 
                        $sexArray
                    );
                }
            }
        }
        
        // Определяем пол команды (берем пол первого участника)
        if ($mainTeamSex === null) {
            $mainTeamSex = $participant['sex'];
        }
    }
    
    // Проверяем каждую команду для объединения
    foreach ($teamsToMerge as $teamData) {
        $teamId = $teamData['teamId'] ?? null;
        if (!$teamId) continue;
        
        // Получаем участников команды
        $teamParticipants = $db->fetchAll("
            SELECT lr.discipline, u.sex
            FROM listreg lr
            JOIN users u ON lr.users_oid = u.oid
            WHERE lr.teams_oid = ? AND lr.meros_oid = ?
        ", [$teamId, $eventId]);
        
        $totalParticipants += count($teamParticipants);
        
        // Проверяем пол команды
        foreach ($teamParticipants as $participant) {
            if ($mainTeamSex !== null && $participant['sex'] !== $mainTeamSex) {
                $errors[] = "Команды имеют разный пол участников. Основная команда: {$mainTeamSex}, команда {$teamId}: {$participant['sex']}";
            }
        }
        
        // Анализируем дисциплины команды
        foreach ($teamParticipants as $participant) {
            $discipline = json_decode($participant['discipline'], true);
            if ($discipline) {
                foreach ($discipline as $class => $details) {
                    if (isset($mainTeamDisciplines[$class])) {
                        // Проверяем дистанции
                        if (isset($details['dist'])) {
                            $distances = is_array($details['dist']) ? $details['dist'] : explode(', ', $details['dist']);
                            foreach ($distances as $distStr) {
                                $distArray = array_map('trim', explode(',', $distStr));
                                $mainTeamDistances = $mainTeamDisciplines[$class]['distances'];
                                
                                // Проверяем, есть ли общие дистанции
                                $commonDistances = array_intersect($distArray, $mainTeamDistances);
                                if (empty($commonDistances)) {
                                    $errors[] = "Команды имеют разные дистанции для класса {$class}. Основная команда: " . implode(', ', $mainTeamDistances) . ", команда {$teamId}: " . implode(', ', $distArray);
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    
    // Проверяем общее количество участников
    if ($totalParticipants > 14) {
        $errors[] = "Общее количество участников после объединения ({$totalParticipants}) превышает максимально допустимое (14)";
    }
    
    return [
        'compatible' => empty($errors),
        'errors' => $errors,
        'total_participants' => $totalParticipants
    ];
}

try {
    // Проверка метода
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJson(['success' => false, 'message' => 'Требуется POST метод']);
    }

    // Получение данных
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        sendJson(['success' => false, 'message' => 'Неверные данные запроса']);
    }
    
    $mainTeamId = $input['mainTeamId'] ?? null;
    $eventId = $input['eventId'] ?? null;
    $teamsToMerge = $input['teamsToMerge'] ?? [];
    $newTeamName = trim($input['newTeamName'] ?? '');
    
    if (!$mainTeamId || !$eventId || empty($teamsToMerge)) {
        sendJson(['success' => false, 'message' => 'Не указаны обязательные параметры']);
    }

    // Подключение к БД
    require_once __DIR__ . "/../db/Database.php";
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    
    // Проверяем совместимость команд перед объединением
    $compatibilityCheck = checkTeamsCompatibility($db, $mainTeamId, $teamsToMerge, $eventId);
    
    if (!$compatibilityCheck['compatible']) {
        sendJson([
            'success' => false, 
            'message' => 'Команды несовместимы для объединения',
            'errors' => $compatibilityCheck['errors']
        ]);
    }
    
    // Администраторы имеют полный доступ к объединению команд
    
    // Начинаем транзакцию
    $pdo->beginTransaction();
    
    try {
        // Проверяем существование основной команды
        $mainTeam = $db->fetchOne("
            SELECT oid, teamname, teamcity, persons_amount, persons_all 
            FROM teams 
            WHERE oid = ?
        ", [$mainTeamId]);
        
        if (!$mainTeam) {
            throw new Exception("Основная команда не найдена");
        }
        
        $mergedCount = 0;
        $totalParticipants = 0;
        
        // Обрабатываем каждую команду для объединения
        foreach ($teamsToMerge as $teamData) {
            $teamId = $teamData['teamId'] ?? null;
            $teamEventId = $teamData['eventId'] ?? null;
            
            if (!$teamId || $teamEventId != $eventId) {
                continue;
            }
            
            // Проверяем существование команды
            $team = $db->fetchOne("SELECT oid, teamname FROM teams WHERE oid = ?", [$teamId]);
            if (!$team) {
                continue;
            }
            
            // Получаем участников команды
            $participants = $db->fetchAll("
                SELECT oid, users_oid, role, status, discipline, cost, oplata
                FROM listreg 
                WHERE teams_oid = ? AND meros_oid = ?
            ", [$teamId, $eventId]);
            
            if (empty($participants)) {
                continue;
            }
            
            // Перемещаем всех участников в основную команду
            foreach ($participants as $participant) {
                $db->execute("
                    UPDATE listreg 
                    SET teams_oid = ? 
                    WHERE oid = ?
                ", [$mainTeamId, $participant['oid']]);
                
                $totalParticipants++;
            }
            
            // Удаляем команду после перемещения участников
            $db->execute("DELETE FROM teams WHERE oid = ?", [$teamId]);
            $mergedCount++;
        }
        
        if ($totalParticipants > 0) {
            // Обновляем количество участников в основной команде
            $newParticipantCount = $db->fetchOne("
                SELECT COUNT(*) as count 
                FROM listreg 
                WHERE teams_oid = ? AND meros_oid = ?
            ", [$mainTeamId, $eventId]);
            
            $participantCount = $newParticipantCount['count'];
            
            // Обновляем информацию об основной команде
            $updateFields = ['persons_amount = ?'];
            $updateParams = [$participantCount];
            
            // Если указано новое название, обновляем его
            if (!empty($newTeamName)) {
                $updateFields[] = 'teamname = ?';
                $updateParams[] = $newTeamName;
            }
            
            $updateParams[] = $mainTeamId;
            
            $db->execute("
                UPDATE teams 
                SET " . implode(', ', $updateFields) . " 
                WHERE oid = ?
            ", $updateParams);
        }
        
        // Фиксируем транзакцию
        $pdo->commit();
        
        sendJson([
            'success' => true,
            'message' => "Объединение завершено успешно",
            'details' => [
                'merged_teams' => $mergedCount,
                'total_participants' => $totalParticipants,
                'main_team_id' => $mainTeamId,
                'new_team_name' => $newTeamName ?: $mainTeam['teamname']
            ]
        ]);
        
    } catch (Exception $e) {
        // Откатываем транзакцию
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    sendJson(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
}
?> 
<?php
/**
 * API для сохранения изменений ролей в команде драконов
 */

if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/../db/Database.php";

if (!defined('TEST_MODE')) header('Content-Type: application/json');

try {
    // Проверка авторизации
    $auth = new Auth();
    if (!$auth->isAuthenticated() || !in_array($auth->getUserRole(), ['Organizer', 'SuperUser', 'Admin'])) {
        throw new Exception('Недостаточно прав доступа');
    }

    // Получаем данные из запроса
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Некорректные данные запроса');
    }
    
    $teamId = $input['teamId'] ?? null;
    $champn = $input['champn'] ?? null;
    $changes = $input['changes'] ?? [];
    
    if (!$teamId || !$champn) {
        throw new Exception('Не указаны ID команды или мероприятия');
    }
    
    if (empty($changes)) {
        throw new Exception('Нет изменений для сохранения');
    }
    
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    
    // Начинаем транзакцию
    $pdo->beginTransaction();
    
    try {
        // Получаем oid мероприятия по champn
        $stmt = $pdo->prepare("SELECT oid FROM meros WHERE champn = ?");
        $stmt->execute([$champn]);
        $merosOid = $stmt->fetchColumn();
        
        if (!$merosOid) {
            throw new Exception('Мероприятие не найдено');
        }
        
        // Получаем teams_oid по teamid и meros_oid
        $stmt = $pdo->prepare("
            SELECT DISTINCT t.oid as teams_oid 
            FROM teams t 
            JOIN listreg lr ON t.oid = lr.teams_oid 
            WHERE t.teamid = ? AND lr.meros_oid = ?
        ");
        $stmt->execute([$teamId, $merosOid]);
        $teamsOid = $stmt->fetchColumn();
        
        if (!$teamsOid) {
            throw new Exception('Команда не найдена для данного мероприятия');
        }
        
        // Проверяем, что команда существует и принадлежит текущему пользователю (для организаторов)
        $userRole = $auth->getUserRole();
        if ($userRole === 'Organizer') {
            $userId = $auth->getCurrentUser()['user_id'];
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM listreg lr
                JOIN meros m ON lr.meros_oid = m.oid
                WHERE lr.teams_oid = ? AND m.champn = ? AND m.created_by = ?
            ");
            $stmt->execute([$teamsOid, $champn, $userId]);
            
            if ($stmt->fetchColumn() == 0) {
                throw new Exception('Команда не найдена или у вас нет прав на её редактирование');
            }
        }
        
        // Применяем изменения
        foreach ($changes as $change) {
            $oid = $change['oid'];
            $newRole = $change['newRole'];
            
            // Валидация роли
            $allowedRoles = ['captain', 'member', 'coxswain', 'drummer', 'reserve'];
            if (!in_array($newRole, $allowedRoles)) {
                throw new Exception('Недопустимая роль: ' . $newRole);
            }
            
            // Обновляем роль участника
            $stmt = $pdo->prepare("
                UPDATE listreg 
                SET role = ? 
                WHERE oid = ? AND teams_oid = ? AND meros_oid = ?
            ");
            $stmt->execute([$newRole, $oid, $teamsOid, $merosOid]);
            
            if ($stmt->rowCount() == 0) {
                throw new Exception('Не удалось обновить роль участника ' . $oid);
            }
        }
        
        // Проверяем финальный состав команды
        $stmt = $pdo->prepare("
            SELECT role, COUNT(*) as count
            FROM listreg 
            WHERE teams_oid = ? AND meros_oid = ?
            GROUP BY role
        ");
        $stmt->execute([$teamsOid, $merosOid]);
        $rolesCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Валидация ролей для команд драконов D-10 - гибкая схема
        $captain = $rolesCounts['captain'] ?? 0;
        $members = $rolesCounts['member'] ?? 0;
        $coxswain = $rolesCounts['coxswain'] ?? 0;
        $drummer = $rolesCounts['drummer'] ?? 0;
        $reserve = $rolesCounts['reserve'] ?? 0;
        
        // Удаляем обязательность капитана и минимального количества гребцов
        // Проверяем что специальные роли не дублируются
        if ($coxswain > 1) {
            throw new Exception("Может быть максимум 1 рулевой, сейчас: $coxswain");
        }
        
        if ($drummer > 1) {
            throw new Exception("Может быть максимум 1 барабанщик, сейчас: $drummer");
        }
        
        // Проверяем общий лимит участников (14 для драконов D-10)
        $totalParticipants = $captain + $members + $coxswain + $drummer + $reserve;
        if ($totalParticipants > 14) {
            throw new Exception("Максимальное количество участников в команде драконов: 14, сейчас: $totalParticipants");
        }
        
        // Определяем статус команды на основе основного состава
        // Основной состав = капитан + 9 гребцов + рулевой + барабанщик = 12 человек
        $mainCrewCount = $captain + min($members, 9) + min($coxswain, 1) + min($drummer, 1);
        $newStatus = ($mainCrewCount >= 12) ? 'Зарегистрирован' : 'Ожидание команды';
        
        // Обновляем статус всех участников команды
        $stmt = $pdo->prepare("
            UPDATE listreg 
            SET status = ? 
            WHERE teams_oid = ? AND meros_oid = ?
        ");
        $stmt->execute([$newStatus, $teamsOid, $merosOid]);
        
        // Логируем изменения
        $logMessage = "Изменен состав команды $teamId (teams_oid: $teamsOid) для мероприятия $champn. Новый статус: $newStatus";
        error_log($logMessage);
        
        // Подтверждаем транзакцию
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Изменения успешно сохранены',
            'newStatus' => $newStatus,
            'rolesCounts' => $rolesCounts
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?> 
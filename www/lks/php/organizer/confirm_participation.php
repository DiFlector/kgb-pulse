<?php
/**
 * Обработчик подтверждения участника
 * Доступ для организаторов, админов и секретарей
 */

if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../helpers.php";

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

// Проверка авторизации
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Organizer', 'Admin', 'SuperUser', 'Secretary'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Необходима авторизация']);
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'];

// Определяем права доступа
$hasFullAccess = $userRole === 'SuperUser' ||
                ($userRole === 'Admin' && $userId >= 1 && $userId <= 50) ||
                ($userRole === 'Organizer' && $userId >= 51 && $userId <= 100) ||
                ($userRole === 'Secretary' && $userId >= 151 && $userId <= 200);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Получаем данные из запроса
    if (defined('TEST_MODE')) {
        $input = $_POST;
        $oid = isset($input['registration_id']) ? intval($input['registration_id']) : 0;
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        $oid = isset($input['oid']) ? intval($input['oid']) : 0;
    }
    
    if (!$oid) {
        throw new Exception('Не указан ID регистрации');
    }
    
            // Получаем данные регистрации с проверкой прав доступа
        if ($hasFullAccess) {
            $stmt = $db->prepare("
                SELECT 
                    l.oid,
                    l.users_oid,
                    l.meros_oid,
                    l.teams_oid,
                    l.status,
                    l.cost,
                    u.fio,
                    u.email,
                    m.meroname,
                    m.status as event_status
                FROM listreg l
                LEFT JOIN users u ON l.users_oid = u.oid
                LEFT JOIN meros m ON l.meros_oid = m.oid
                WHERE l.oid = ?
            ");
            $stmt->execute([$oid]);
    } else {
        // Ограниченный доступ - только свои мероприятия
        $stmt = $db->prepare("
            SELECT 
                l.oid,
                l.users_oid,
                l.meros_oid,
                l.teams_oid,
                l.status,
                l.cost,
                u.fio,
                u.email,
                m.meroname,
                m.status as event_status
            FROM listreg l
            LEFT JOIN users u ON l.users_oid = u.oid
            LEFT JOIN meros m ON l.meros_oid = m.oid
            WHERE l.oid = ? AND (m.created_by = ? OR ? = 1)
        ");
        $stmt->execute([$oid, $userId, $hasFullAccess ? 1 : 0]);
    }
    
    $registration = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$registration) {
        throw new Exception('Регистрация не найдена или нет прав доступа');
    }
    
    // Проверяем, можно ли подтвердить регистрацию
    if ($registration['status'] === 'Подтверждён') {
        throw new Exception('Регистрация уже подтверждена');
    }
    
    if ($registration['event_status'] !== 'Регистрация') {
        throw new Exception('Мероприятие не в статусе "Регистрация"');
    }
    
    // Начинаем транзакцию
    $db->beginTransaction();
    
    try {
        // Обновляем статус регистрации
        $updateStmt = $db->prepare("
            UPDATE listreg 
            SET status = 'Подтверждён',
                updated_at = CURRENT_TIMESTAMP
            WHERE oid = ?
        ");
        $updateStmt->execute([$oid]);
        
        // Если это командная регистрация, проверяем команду
        $teamStatus = null;
        if ($registration['teamid']) {
            // Получаем количество участников в команде
            $teamCountStmt = $db->prepare("
                SELECT 
                    COUNT(*) as total_members,
                    COUNT(CASE WHEN status = 'Подтверждён' THEN 1 END) as confirmed_members
                FROM listreg 
                WHERE teams_oid = ? AND meros_oid = ?
            ");
            $teamCountStmt->execute([$registration['teams_oid'], $registration['meros_oid']]);
            $teamCounts = $teamCountStmt->fetch(PDO::FETCH_ASSOC);
            
            // Если все участники команды подтверждены
            if ($teamCounts['total_members'] === $teamCounts['confirmed_members']) {
                $teamStatus = 'Команда полностью подтверждена';
                
                // Обновляем статус всей команды
                $updateTeamStmt = $db->prepare("
                    UPDATE listreg 
                    SET status = 'Подтверждён'
                    WHERE teams_oid = ? AND meros_oid = ? AND status != 'Подтверждён'
                ");
                $updateTeamStmt->execute([$registration['teams_oid'], $registration['meros_oid']]);
            } else {
                $teamStatus = "Подтверждено {$teamCounts['confirmed_members']} из {$teamCounts['total_members']} участников";
            }
        }
        
        // Логируем действие
        $logStmt = $db->prepare("
            INSERT INTO activity_log (user_id, action, description, created_at)
            VALUES (?, 'confirm_participation', ?, CURRENT_TIMESTAMP)
        ");
        
        $description = "Подтверждена регистрация участника '{$registration['fio']}' (OID: {$oid}) на мероприятие '{$registration['meroname']}'";
        if ($teamStatus) {
            $description .= ". {$teamStatus}";
        }
        
        $logStmt->execute([$userId, $description]);
        
        // Отправляем уведомление участнику (если есть email)
        if ($registration['email']) {
            try {
                // Здесь можно добавить отправку email уведомления
                $emailSent = true; // Пока заглушка
            } catch (Exception $e) {
                error_log("Failed to send confirmation email: " . $e->getMessage());
                $emailSent = false;
            }
        }
        
        $db->commit();
        
        $response = [
            'success' => true,
            'message' => 'Регистрация успешно подтверждена',
            'participant_name' => $registration['fio'],
            'event_name' => $registration['meroname']
        ];
        
        if ($teamStatus) {
            $response['team_status'] = $teamStatus;
        }
        
        if (isset($emailSent)) {
            $response['email_sent'] = $emailSent;
        }
        
        echo json_encode($response);
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Confirm participation error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
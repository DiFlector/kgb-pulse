<?php
/**
 * API для удаления регистраций - Организаторы
 */

// Заголовки JSON и CORS
if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Origin: *');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Methods: POST, OPTIONS');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

// Проверка авторизации
if (!isset($_SESSION)) {
    session_start();
}

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/Auth.php";

$auth = new Auth();

// Проверка авторизации и прав организатора
if (!$auth->isAuthenticated() || !$auth->hasAnyRole(['Admin', 'Organizer', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Доступ запрещен'
    ]);
    exit;
}

// Проверка метода запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Метод не поддерживается'
    ]);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    
    // Получение данных запроса
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['registrationId'])) {
        throw new Exception('Не указан ID регистрации');
    }
    
    $regId = intval($input['registrationId']);
    
    if ($regId <= 0) {
        throw new Exception('Некорректный ID регистрации');
    }
    
    // Получаем информацию о регистрации перед удалением
    $sql = "
        SELECT 
            lr.oid,
            lr.teams_oid,
            u.fio,
            u.userid,
            m.meroname,
            m.created_by,
            m.champn
        FROM listreg lr
        LEFT JOIN users u ON lr.users_oid = u.oid
        LEFT JOIN meros m ON lr.meros_oid = m.oid
        WHERE lr.oid = ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$regId]);
    $registration = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$registration) {
        throw new Exception('Регистрация не найдена');
    }
    
    // Проверяем права доступа
    $userRole = $auth->getUserRole();
    $userId = $auth->getCurrentUser()['user_id'];
    
    // Администраторы и суперпользователи имеют полный доступ
    $hasFullAccess = in_array($userRole, ['Admin', 'SuperUser']);
    
    // Для организаторов проверяем, что мероприятие принадлежит им
    if (!$hasFullAccess) {
        if ($registration['created_by'] != $userId) {
            throw new Exception('У вас нет прав на удаление этой регистрации');
        }
    }
    
    // Начинаем транзакцию
    $pdo->beginTransaction();
    
    try {
        // Удаляем регистрацию
        $stmt = $pdo->prepare("DELETE FROM listreg WHERE oid = ?");
        $stmt->execute([$regId]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Не удалось удалить регистрацию');
        }
        
        // Если есть команда, проверяем не осталась ли она пустой
        if ($registration['teams_oid']) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM listreg WHERE teams_oid = ?");
            $stmt->execute([$registration['teams_oid']]);
            $teamMembersCount = $stmt->fetchColumn();
            
            if ($teamMembersCount == 0) {
                // Удаляем пустую команду
                $stmt = $pdo->prepare("DELETE FROM teams WHERE oid = ?");
                $stmt->execute([$registration['teams_oid']]);
            } else {
                // Обновляем количество участников в команде
                $stmt = $pdo->prepare("UPDATE teams SET persons_amount = ? WHERE oid = ?");
                $stmt->execute([$teamMembersCount, $registration['teams_oid']]);
            }
        }
        
        // Отправляем уведомление участнику (если есть класс Notification)
        if ($registration['userid']) {
            try {
                if (file_exists(__DIR__ . "/../common/Notification.php")) {
                    require_once __DIR__ . "/../common/Notification.php";
                    $notification = new Notification();
                    $notification->send($registration['userid'], 'status_change', 
                        'Регистрация отменена', 
                        'Ваша регистрация на мероприятие "' . $registration['meroname'] . '" была отменена организатором'
                    );
                }
            } catch (Exception $e) {
                // Логируем ошибку, но не прерываем процесс удаления
                error_log("Ошибка отправки уведомления: " . $e->getMessage());
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Регистрация участника "' . $registration['fio'] . '" на мероприятие "' . $registration['meroname'] . '" успешно удалена'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Ошибка удаления регистрации: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
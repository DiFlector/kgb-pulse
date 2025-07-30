<?php
/**
 * API для изменения ID пользователя - Администратор
 */
session_start();
if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

// Проверка авторизации
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Admin' && $_SESSION['user_role'] !== 'SuperUser')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
    exit;
}

require_once __DIR__ . "/../db/Database.php";

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['original_id']) || !isset($input['new_id'])) {
        throw new Exception('Некорректные параметры запроса');
    }
    
    $originalId = (int)$input['original_id'];
    $newId = (int)$input['new_id'];
    
    if ($originalId <= 0 || $newId <= 0) {
        throw new Exception('Некорректные значения ID');
    }
    
    if ($originalId === 999) {
        echo json_encode(['success' => false, 'message' => 'Нельзя изменять ID суперпользователя']);
        exit;
    }
    
    if ($originalId === $newId) {
        echo json_encode(['success' => true, 'message' => 'ID не изменен']);
        exit;
    }
    
    $db = Database::getInstance();
    
    // Получаем информацию о пользователе
    $userStmt = $db->prepare("SELECT userid, accessrights FROM users WHERE userid = ?");
    $userStmt->execute([$originalId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception('Пользователь не найден');
    }
    
    // Проверяем, что новый ID не занят
    $existsStmt = $db->prepare("SELECT userid FROM users WHERE userid = ?");
    $existsStmt->execute([$newId]);
    if ($existsStmt->fetch()) {
        throw new Exception('Пользователь с ID ' . $newId . ' уже существует');
    }
    
    // Проверяем допустимый диапазон для роли
    $role = $user['accessrights'];
    $validRange = false;
    
    switch ($role) {
        case 'Admin':
            $validRange = ($newId >= 1 && $newId <= 50);
            $rangeText = '1-50';
            break;
        case 'Organizer':
            $validRange = ($newId >= 51 && $newId <= 150);
            $rangeText = '51-150';
            break;
        case 'Secretary':
            $validRange = ($newId >= 151 && $newId <= 250);
            $rangeText = '151-250';
            break;
        case 'Sportsman':
            $validRange = ($newId >= 1000);
            $rangeText = '1000+';
            break;
        default:
            throw new Exception('Неопределенная роль пользователя');
    }
    
    if (!$validRange) {
        throw new Exception("ID для роли {$role} должен быть в диапазоне {$rangeText}");
    }
    
    // Начинаем транзакцию
    $db->beginTransaction();
    
    try {
        // Обновляем ID пользователя
        $updateStmt = $db->prepare("UPDATE users SET userid = ? WHERE userid = ?");
        $updateResult = $updateStmt->execute([$newId, $originalId]);
        
        if (!$updateResult) {
            throw new Exception('Ошибка при обновлении ID пользователя');
        }
        
        // ВАЖНО: Связанные таблицы ссылаются на users.oid, а не на users.userid
        // Поэтому обновлять их НЕ нужно, так как users.oid остается неизменным
        // 
        // Таблицы, которые НЕ обновляем:
        // - listreg.userid -> users.oid 
        // - login_attempts.userid -> users.oid (должно быть)
        // - user_actions.userid -> users.oid (должно быть)
        // - notifications.userid -> users.oid (должно быть)
        // - user_statistic.userid -> users.oid (должно быть)
        //
        // Обновляем только users.userid (номер спортсмена)
        
        // Если это текущий пользователь, обновляем сессию
        if ($_SESSION['user_id'] == $originalId) {
            $_SESSION['user_id'] = $newId;
        }
        
        $db->commit();
        
        // Логируем действие
        error_log("Admin {$_SESSION['user_id']} changed user ID from {$originalId} to {$newId}");
        
        echo json_encode([
            'success' => true,
            'message' => "ID пользователя успешно изменен с {$originalId} на {$newId}"
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Update user ID error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
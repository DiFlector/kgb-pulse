<?php
// Изменение роли пользователя - API для администратора
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

// Проверка авторизации
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Не авторизован']);
    exit;
}

// Проверка прав администратора
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Admin', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
    exit;
}

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/Auth.php";

$auth = new Auth();
$currentUser = $auth->getCurrentUser();

if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Пользователь не найден']);
    exit;
}

// Получение данных запроса
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['userId']) || !isset($input['newRole'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Отсутствуют обязательные параметры']);
    exit;
}

$userId = (int)$input['userId'];
$newRole = $input['newRole'];

// Валидация роли
$validRoles = ['Admin', 'Organizer', 'Secretary', 'Sportsman'];
if (!in_array($newRole, $validRoles)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Недопустимая роль']);
    exit;
}

// Проверка, что пользователь не пытается изменить свою собственную роль
if ($userId == $currentUser['userid']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Нельзя изменить собственную роль']);
    exit;
}

// Защита: нельзя менять роль суперпользователя  
if ($userId == 999) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Нельзя изменять роль суперпользователя']);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    
    // Получение текущих данных пользователя
    $stmt = $pdo->prepare("SELECT oid, userid, accessrights FROM users WHERE userid = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Пользователь не найден']);
        exit;
    }
    
    $oldRole = $user['accessrights'];
    $userOid = $user['oid'];
    
    // ИСПРАВЛЕНО: Просто меняем роль, не трогаем userid 
    // (менять userid может нарушить целостность данных)
    $pdo->beginTransaction();
    
    try {
        // Обновляем только роль пользователя
        $stmt = $pdo->prepare("UPDATE users SET accessrights = ? WHERE userid = ?");
        $stmt->execute([$newRole, $userId]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => "Роль пользователя изменена с '$oldRole' на '$newRole'",
            'user' => [
                'userId' => $userId, 
                'newRole' => $newRole,
                'oldRole' => $oldRole
            ]
        ]);
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log('Ошибка при изменении роли пользователя: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера: ' . $e->getMessage()]);
}
?> 
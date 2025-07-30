<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

require_once '../db/Database.php';
require_once '../common/Auth.php';

$auth = new Auth();

if (!$auth->isAuthenticated() || (!$auth->hasRole('Admin') && !$auth->isSuperUser())) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Некорректные данные запроса');
    }
    
    $userId = isset($input['userId']) ? intval($input['userId']) : 0;
    $fio = isset($input['fio']) ? trim($input['fio']) : '';
    $email = isset($input['email']) ? trim($input['email']) : '';
    
    if ($userId <= 0) {
        throw new Exception('Не указан ID пользователя');
    }
    
    if (empty($fio)) {
        throw new Exception('ФИО обязательно для заполнения');
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Некорректный email');
    }
    
    $telephone = isset($input['telephone']) ? trim($input['telephone']) : null;
    $birthdata = isset($input['birthdata']) ? $input['birthdata'] : null;
    $sex = isset($input['sex']) ? $input['sex'] : null;
    $country = isset($input['country']) ? trim($input['country']) : null;
    $city = isset($input['city']) ? trim($input['city']) : null;
    $accessrights = isset($input['accessrights']) ? $input['accessrights'] : null;
    $sportzvanie = isset($input['sportzvanie']) ? $input['sportzvanie'] : null;
    $boats = isset($input['boats']) && is_array($input['boats']) ? $input['boats'] : [];
    $resetPassword = isset($input['resetPassword']) && $input['resetPassword'];
    
    $validRoles = ['Admin', 'Organizer', 'Secretary', 'Sportsman'];
    if ($accessrights && !in_array($accessrights, $validRoles)) {
        throw new Exception('Недопустимая роль');
    }
    
    $stmt = $pdo->prepare("SELECT userid FROM users WHERE email = ? AND userid != ?");
    $stmt->execute([$email, $userId]);
    if ($stmt->fetch()) {
        throw new Exception('Пользователь с таким email уже существует');
    }
    
    if ($telephone) {
        $stmt = $pdo->prepare("SELECT userid FROM users WHERE telephone = ? AND userid != ?");
        $stmt->execute([$telephone, $userId]);
        if ($stmt->fetch()) {
            throw new Exception('Пользователь с таким телефоном уже существует');
        }
    }

    if ($userId == 999) {
        throw new Exception('Нельзя редактировать суперпользователя');
    }
    
    $pdo->beginTransaction();
    
    try {
        $boatsArray = null;
        if (!empty($boats)) {
            $boatsArray = '{' . implode(',', $boats) . '}';
        }
        
        $sql = "UPDATE users SET fio = ?, email = ?, telephone = ?, birthdata = ?, sex = ?, country = ?, city = ?, accessrights = ?, sportzvanie = ?, boats = ? WHERE userid = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $fio,
            $email,
            $telephone,
            $birthdata ?: null,
            $sex ?: null,
            $country,
            $city,
            $accessrights,
            $sportzvanie ?: null,
            $boatsArray,
            $userId
        ]);
        
        if ($resetPassword) {
            $newPassword = bin2hex(random_bytes(4));
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE userid = ?");
            $stmt->execute([$hashedPassword, $userId]);
        }
        
        $pdo->commit();
        
        $response = ['success' => true, 'message' => 'Данные пользователя успешно обновлены'];
        
        if ($resetPassword) {
            $response['newPassword'] = $newPassword;
            $response['message'] .= '. Новый пароль: ' . $newPassword;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Ошибка обновления пользователя: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?> 
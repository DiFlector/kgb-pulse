<?php
require_once '../common/Auth.php';
require_once '../db/Database.php';

header('Content-Type: application/json');

try {
    $auth = new Auth();
    
    // Проверяем аутентификацию
    if (!$auth->isAuthenticated()) {
        throw new Exception('Не авторизован');
    }
    
    // Получаем данные текущего пользователя
    $userData = $auth->getCurrentUser();
    
    if (!$userData || !in_array($userData['accessrights'], ['Admin', 'Organizer', 'Secretary', 'SuperUser'])) {
        throw new Exception('Недостаточно прав доступа');
    }
    
    // Определяем уровень доступа (как в registrations.php)
    $hasFullAccess = $userData['accessrights'] === 'SuperUser' ||
        ($userData['accessrights'] === 'Admin');
    
    $database = Database::getInstance();
    $pdo = $database->getPDO();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Метод не поддерживается');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['registrationId'], $input['status'])) {
        throw new Exception('Неверные параметры запроса');
    }
    
    $registrationId = intval($input['registrationId']);
    $newStatus = trim($input['status']);
    
    if ($registrationId <= 0) {
        throw new Exception('Некорректный ID регистрации');
    }
    
    // Проверяем существование регистрации и права доступа
    $checkSql = "
        SELECT lr.oid, m.created_by 
        FROM listreg lr
        LEFT JOIN meros m ON lr.meros_oid = m.oid
        WHERE lr.oid = ?
    ";
    
    // Все организаторы имеют доступ ко всем регистрациям
    $stmt = $pdo->prepare($checkSql);
    $stmt->execute([$registrationId]);
    
    $registrationData = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$registrationData) {
        throw new Exception('Регистрация не найдена или нет прав доступа');
    }
    
    // Обновляем статус
    $updateSql = "UPDATE listreg SET status = ? WHERE oid = ?";
    $stmt = $pdo->prepare($updateSql);
    $stmt->execute([$newStatus, $registrationId]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Не удалось обновить статус регистрации');
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Статус регистрации обновлен'
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка обновления статуса регистрации (организатор): " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
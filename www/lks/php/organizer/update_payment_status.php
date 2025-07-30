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
    
    if (!$input || !isset($input['registration_id'], $input['payment_status'])) {
        throw new Exception('Неверные параметры запроса');
    }
    
    $registrationId = intval($input['registration_id']);
    $paymentStatus = (bool)$input['payment_status'];
    
    if ($registrationId <= 0) {
        throw new Exception('Некорректный ID регистрации');
    }
    
    // Проверяем текущий статус оплаты и права доступа
    $checkSql = "
        SELECT lr.oplata, m.created_by 
        FROM listreg lr
        LEFT JOIN meros m ON lr.meros_oid = m.oid
        WHERE lr.oid = ?
    ";
    
    // Все организаторы имеют доступ ко всем регистрациям
    $stmt = $pdo->prepare($checkSql);
    $stmt->execute([$registrationId]);
    
    $currentData = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$currentData) {
        throw new Exception('Регистрация не найдена или нет прав доступа');
    }
    
    // Проверяем ограничения для организатора (но не для тех, кто имеет полный доступ)
    if ($userData['accessrights'] === 'Organizer' && !$hasFullAccess) {
        $currentPayment = (bool)$currentData['oplata'];
        
        // Организатор может только включать оплату, но не выключать
        if ($currentPayment && !$paymentStatus) {
            throw new Exception('Организатор не может снимать оплату. Обратитесь к администратору.');
        }
    }
    
    // Обновляем статус оплаты
    $boolValue = $paymentStatus ? 'true' : 'false';
    $updateSql = "UPDATE listreg SET oplata = ?::boolean WHERE oid = ?";
    $stmt = $pdo->prepare($updateSql);
    $stmt->execute([$boolValue, $registrationId]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Не удалось обновить статус оплаты');
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Статус оплаты обновлен'
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка обновления статуса оплаты (организатор): " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
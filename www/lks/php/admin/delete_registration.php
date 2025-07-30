<?php
/**
 * API для удаления регистраций
 * Только для администраторов
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

// Проверка авторизации и прав администратора
if (!$auth->isAuthenticated() || !$auth->hasAnyRole(['Admin', 'SuperUser'])) {
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
            u.fio,
            m.meroname
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
    
    // Удаляем регистрацию
    $stmt = $pdo->prepare("DELETE FROM listreg WHERE oid = ?");
    $stmt->execute([$regId]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Не удалось удалить регистрацию');
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Регистрация участника "' . $registration['fio'] . '" на мероприятие "' . $registration['meroname'] . '" успешно удалена'
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка удаления регистрации: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
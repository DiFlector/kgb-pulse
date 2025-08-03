<?php
/**
 * API для удаления мероприятий
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
    
    if (!$input || !isset($input['eventId'])) {
        throw new Exception('Не указан ID мероприятия');
    }
    
    $eventId = intval($input['eventId']);
    
    if ($eventId <= 0) {
        throw new Exception('Некорректный ID мероприятия');
    }
    
    // Проверяем существование мероприятия
    $stmt = $pdo->prepare("SELECT champn, meroname FROM meros WHERE champn = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$event) {
        throw new Exception('Мероприятие не найдено');
    }
    
    // Проверяем, есть ли регистрации на это мероприятие
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM listreg l JOIN meros m ON l.meros_oid = m.oid WHERE m.champn = ?");
    $stmt->execute([$eventId]);
    $regCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $pdo->beginTransaction();
    
    try {
        // Удаляем связанные файлы (если есть)
        $stmt = $pdo->prepare("SELECT filepolojenie, fileprotokol, fileresults FROM meros WHERE champn = ?");
        $stmt->execute([$eventId]);
        $files = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($files) {
            foreach ($files as $file) {
                if ($file && file_exists('../../files/' . $file)) {
                    unlink('../../files/' . $file);
                }
            }
        }
        
        // Удаляем регистрации на мероприятие
        if ($regCount > 0) {
            $stmt = $pdo->prepare("DELETE FROM listreg WHERE meros_oid = (SELECT oid FROM meros WHERE champn = ?)");
            $stmt->execute([$eventId]);
        }
        
        // Удаляем само мероприятие
        $stmt = $pdo->prepare("DELETE FROM meros WHERE champn = ?");
        $stmt->execute([$eventId]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Мероприятие "' . $event['meroname'] . '" успешно удалено' . 
                        ($regCount > 0 ? ' вместе с ' . $regCount . ' регистрациями' : '')
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Ошибка удаления мероприятия: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
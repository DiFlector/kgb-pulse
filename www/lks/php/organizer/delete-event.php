<?php
/**
 * API для удаления мероприятия
 * Удаляет мероприятие и все связанные с ним регистрации
 */

if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/../db/Database.php";

// В тестовом режиме используем моковые данные
if (defined('TEST_MODE')) {
    $user = $_SESSION;
} else {
    $auth = new Auth();
    $user = $auth->checkRole(['Organizer', 'SuperUser', 'Admin']);
    if (!$user) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Метод не поддерживается']);
    exit;
}

try {
    // Получаем данные из запроса
    if (defined('TEST_MODE')) {
        // В тестовом режиме используем $_POST
        $input = $_POST;
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
    }
    
    if (!isset($input['event_id']) || empty($input['event_id'])) {
        echo json_encode(['success' => false, 'error' => 'ID мероприятия не указан']);
        exit;
    }
    
    $eventId = (int)$input['event_id'];
    $userId = $user['user_id'];
    
    $db = Database::getInstance();
    
    // Начинаем транзакцию
    $db->beginTransaction();
    
    // Проверяем, существует ли мероприятие и принадлежит ли оно пользователю
    $checkStmt = $db->prepare("
        SELECT oid, champn, meroname, created_by, status, filepolojenie 
        FROM meros 
        WHERE oid = ?
    ");
    $checkStmt->execute([$eventId]);
    $event = $checkStmt->fetch();
    
    if (!$event) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Мероприятие не найдено']);
        exit;
    }
    
    // Проверяем права доступа (только создатель, админ или суперпользователь могут удалять)
    if ($event['created_by'] != $userId && !in_array($user['user_role'], ['Admin', 'SuperUser'])) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'У вас нет прав для удаления этого мероприятия']);
        exit;
    }
    
    // Проверяем статус мероприятия - нельзя удалять мероприятия в процессе или завершенные
    if (in_array($event['status'], ['В процессе', 'Завершено', 'Результаты'])) {
        $db->rollBack();
        echo json_encode([
            'success' => false, 
            'error' => 'Нельзя удалить мероприятие со статусом "' . $event['status'] . '"'
        ]);
        exit;
    }
    
    // Подсчитываем количество регистраций для информирования пользователя
    $regCountStmt = $db->prepare("SELECT COUNT(*) FROM listreg WHERE meros_oid = ?");
    $regCountStmt->execute([$eventId]);
    $registrationsCount = $regCountStmt->fetchColumn();
    
    // Логируем начало процесса удаления
    error_log("Начало удаления мероприятия: ID={$eventId}, пользователь={$userId}");
    
    // Удаляем все регистрации на мероприятие
    $deleteRegStmt = $db->prepare("DELETE FROM listreg WHERE meros_oid = ?");
    $deleteRegStmt->execute([$eventId]);
    error_log("Удалено регистраций: {$registrationsCount}");
    
    // Удаляем файлы положения, если они есть
    if (!empty($event['filepolojenie'])) {
        try {
            $filePath = $_SERVER['DOCUMENT_ROOT'] . $event['filepolojenie'];
            if (file_exists($filePath)) {
                unlink($filePath);
                error_log("Файл положения удален: " . $filePath);
            } else {
                error_log("Файл положения не найден: " . $filePath);
            }
        } catch (Exception $fileError) {
            // Логируем ошибку, но не прерываем процесс удаления
            error_log("Ошибка удаления файла положения: " . $fileError->getMessage());
        }
    } else {
        error_log("Файл положения отсутствует");
    }
    
    // Удаляем само мероприятие
    $deleteEventStmt = $db->prepare("DELETE FROM meros WHERE oid = ?");
    $result = $deleteEventStmt->execute([$eventId]);
    
    if (!$result) {
        throw new Exception("Не удалось удалить мероприятие из базы данных");
    }
    
    // Проверяем, что мероприятие действительно удалено
    $checkDeleteStmt = $db->prepare("SELECT COUNT(*) FROM meros WHERE oid = ?");
    $checkDeleteStmt->execute([$eventId]);
    $remainingCount = $checkDeleteStmt->fetchColumn();
    
    if ($remainingCount > 0) {
        throw new Exception("Мероприятие не было удалено из базы данных");
    }
    
    // Логируем действие
    error_log("Мероприятие удалено: ID={$eventId}, название='{$event['meroname']}', пользователь={$userId}, регистраций удалено={$registrationsCount}");
    
    // Подтверждаем транзакцию
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Мероприятие '{$event['meroname']}' успешно удалено",
        'deleted_registrations' => $registrationsCount
    ]);
    
} catch (Exception $e) {
    // Откатываем транзакцию в случае ошибки
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    error_log("Ошибка удаления мероприятия: " . $e->getMessage() . " в файле " . $e->getFile() . " на строке " . $e->getLine());
    
    echo json_encode([
        'success' => false,
        'error' => 'Произошла ошибка при удалении мероприятия',
        'technical_error' => $e->getMessage()
    ]);
}
?> 
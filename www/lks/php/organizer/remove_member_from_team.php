<?php
/**
 * API для удаления участника из команды
 * Обнуляет поля teams_oid и role, меняет статус на "В очереди"
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/../db/Database.php";

$auth = new Auth();
if (!$auth->isAuthenticated() || !in_array($auth->getUserRole(), ['Organizer', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Метод не поддерживается']);
    exit;
}

try {
    $requestData = json_decode(file_get_contents('php://input'), true);
    
    if (!$requestData) {
        throw new Exception('Некорректные данные запроса');
    }
    
    $memberId = $requestData['memberId'] ?? null;
    $teamId = $requestData['teamId'] ?? null;
    $eventId = $requestData['eventId'] ?? null;
    
    if (!$memberId || !$teamId || !$eventId) {
        throw new Exception('Не указаны обязательные параметры');
    }
    
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    
    // Начинаем транзакцию
    $pdo->beginTransaction();
    
    try {
        // Получаем oid мероприятия по eventId (champn)
        $merosOid = $db->fetchColumn("SELECT oid FROM meros WHERE champn = ?", [$eventId]);
        
        if (!$merosOid) {
            throw new Exception('Мероприятие не найдено');
        }
        
        // Проверяем что участник действительно в команде
        $registration = $db->fetchOne("
            SELECT lr.oid, lr.teams_oid, t.teamid, t.persons_amount
            FROM listreg lr
            LEFT JOIN teams t ON lr.teams_oid = t.oid
            WHERE lr.oid = ? AND lr.meros_oid = ?
        ", [$memberId, $merosOid]);
        
        if (!$registration) {
            throw new Exception('Регистрация не найдена');
        }
        
        if (!$registration['teams_oid'] || $registration['teamid'] != $teamId) {
            throw new Exception('Участник не найден в указанной команде');
        }
        
        // Удаляем участника из команды
        $db->execute("
            UPDATE listreg 
            SET teams_oid = NULL, role = NULL, status = 'В очереди'
            WHERE oid = ?
        ", [$memberId]);
        
        // Обновляем количество участников в команде
        $newPersonsAmount = max(0, $registration['persons_amount'] - 1);
        $db->execute("
            UPDATE teams 
            SET persons_amount = ?
            WHERE oid = ?
        ", [$newPersonsAmount, $registration['teams_oid']]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Участник успешно удален из команды'
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Ошибка удаления участника из команды: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?> 
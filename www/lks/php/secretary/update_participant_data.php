<?php
session_start();
require_once __DIR__ . '/../common/Auth.php';
require_once __DIR__ . '/../db/Database.php';

// Включаем вывод ошибок для отладки
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Проверка авторизации и прав доступа
$auth = new Auth();

if (!$auth->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Не авторизован']);
    exit;
}

if (!$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Нет прав доступа']);
    exit;
}

// Получение данных из запроса
$input = json_decode(file_get_contents('php://input'), true);
$meroId = $input['meroId'] ?? null;
$groupKey = $input['groupKey'] ?? null;
$participantUserId = $input['participantUserId'] ?? null;
$field = $input['field'] ?? null;
$value = $input['value'] ?? null;

if (!$meroId || !$groupKey || !$participantUserId || !$field) {
    echo json_encode(['success' => false, 'message' => 'Не все параметры указаны']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Получаем oid пользователя по userid
    $stmt = $db->prepare("SELECT oid FROM users WHERE userid = ?");
    $stmt->execute([$participantUserId]);
    $userOid = $stmt->fetchColumn();
    
    if (!$userOid) {
        echo json_encode(['success' => false, 'message' => 'Пользователь не найден']);
        exit;
    }
    
    // Получаем текущие данные дисциплины
    $stmt = $db->prepare("
        SELECT discipline 
        FROM listreg 
        WHERE users_oid = ? AND meros_oid = ?
    ");
    $stmt->execute([$userOid, $meroId]);
    $disciplineData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$disciplineData) {
        echo json_encode(['success' => false, 'message' => 'Регистрация участника не найдена']);
        exit;
    }
    
    $discipline = json_decode($disciplineData['discipline'], true);
    
    // Определяем класс лодки из groupKey
    $groupParts = explode('_', $groupKey);
    $boatClass = $groupParts[1] ?? 'K-1'; // По умолчанию K-1
    
    // Обновляем поле в discipline
    if (!isset($discipline[$boatClass])) {
        $discipline[$boatClass] = [];
    }
    
    $discipline[$boatClass][$field] = $value;
    
    // Обновляем данные в базе
    $stmt = $db->prepare("
        UPDATE listreg 
        SET discipline = ? 
        WHERE users_oid = ? AND meros_oid = ?
    ");
    $stmt->execute([json_encode($discipline), $userOid, $meroId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Данные участника обновлены',
        'field' => $field,
        'value' => $value,
        'boatClass' => $boatClass
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка обновления данных участника: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка обновления данных: ' . $e->getMessage()
    ]);
}
?> 
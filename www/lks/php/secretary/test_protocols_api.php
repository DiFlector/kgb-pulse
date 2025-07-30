<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/lks/php/common/Auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lks/php/db/Database.php';

// Проверка авторизации и прав доступа
$auth = new Auth();
if (!$auth->isAuthenticated() || !$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPDO();

// Получаем ID мероприятия из GET параметра
$meroId = $_GET['meroId'] ?? null;

if (!$meroId) {
    echo json_encode(['success' => false, 'message' => 'Не указан ID мероприятия']);
    exit;
}

try {
    // Получаем информацию о мероприятии
    $stmt = $pdo->prepare("SELECT oid, champn, meroname, class_distance FROM meros WHERE oid = ?");
    $stmt->execute([$meroId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        echo json_encode(['success' => false, 'message' => 'Мероприятие не найдено']);
        exit;
    }

    $classDistance = json_decode($event['class_distance'], true);
    
    if (!$classDistance) {
        echo json_encode(['success' => false, 'message' => 'Нет данных о дисциплинах']);
        exit;
    }

    // Получаем количество участников
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM listreg lr 
        WHERE lr.meros_oid = ? 
        AND lr.status IN ('Подтверждён', 'Зарегистрирован')
    ");
    $stmt->execute([$meroId]);
    $participantsCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    echo json_encode([
        'success' => true,
        'event' => [
            'oid' => $event['oid'],
            'champn' => $event['champn'],
            'meroname' => $event['meroname'],
            'class_distance' => $classDistance
        ],
        'participantsCount' => $participantsCount,
        'message' => 'API работает корректно'
    ]);

} catch (Exception $e) {
    error_log("Ошибка тестирования API: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера: ' . $e->getMessage()]);
}
?> 
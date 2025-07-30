<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/lks/php/common/Auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lks/php/db/Database.php';

// Проверка авторизации и прав доступа
$auth = new Auth();
if (!$auth->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Не авторизован']);
    exit;
}

// Проверка прав секретаря, суперпользователя или администратора
if (!$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

// Получение данных запроса
$input = json_decode(file_get_contents('php://input'), true);
$query = trim($input['query'] ?? '');
$meroId = (int)($input['meroId'] ?? 0);

if (empty($query) || $meroId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Неверные параметры запроса']);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();

    // Поиск участников по номеру, email или ФИО
    $stmt = $pdo->prepare("
        SELECT 
            u.oid,
            u.userid,
            u.fio,
            u.email,
            u.sex,
            u.birthdata,
            u.sportzvanie,
            u.telephone,
            EXTRACT(YEAR FROM AGE(CURRENT_DATE, u.birthdata)) as age
        FROM users u
        WHERE (
            u.userid::text ILIKE ? OR 
            u.email ILIKE ? OR 
            u.fio ILIKE ?
        )
        AND u.accessrights = 'Sportsman'
        ORDER BY u.fio
        LIMIT 20
    ");

    $searchPattern = "%{$query}%";
    $stmt->execute([$searchPattern, $searchPattern, $searchPattern]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Проверяем, какие участники уже зарегистрированы на мероприятие
    $registeredParticipants = [];
    if (!empty($participants)) {
        $participantOids = array_column($participants, 'oid');
        $placeholders = str_repeat('?,', count($participantOids) - 1) . '?';
        
        $stmt = $pdo->prepare("
            SELECT users_oid 
            FROM listreg 
            WHERE meros_oid = ? AND users_oid IN ($placeholders)
        ");
        
        $params = array_merge([$meroId], $participantOids);
        $stmt->execute($params);
        $registeredParticipants = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Добавляем флаг регистрации к каждому участнику
    foreach ($participants as &$participant) {
        $participant['isRegistered'] = in_array($participant['oid'], $registeredParticipants);
    }

    echo json_encode([
        'success' => true,
        'participants' => $participants
    ]);

} catch (Exception $e) {
    error_log("Ошибка поиска участников: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка поиска участников'
    ]);
}
?> 
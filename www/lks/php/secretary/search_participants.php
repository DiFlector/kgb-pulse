<?php
session_start();
require_once __DIR__ . '/../common/Auth.php';
require_once __DIR__ . '/../db/Database.php';

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
$query = $input['query'] ?? '';
$meroId = $input['meroId'] ?? null;

if (!$query) {
    echo json_encode(['success' => false, 'message' => 'Поисковый запрос не указан']);
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
            u.city,
            EXTRACT(YEAR FROM AGE(CURRENT_DATE, u.birthdata)) as age
        FROM users u
        WHERE (u.userid::text ILIKE ? OR u.fio ILIKE ? OR u.email ILIKE ?)
        AND u.accessrights = 'Sportsman'
        ORDER BY u.fio
        LIMIT 20
    ");
    
    $searchTerm = "%{$query}%";
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'message' => 'Поиск выполнен',
        'participants' => $participants,
        'total' => count($participants)
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка поиска участников: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка поиска участников: ' . $e->getMessage()]);
}
?> 
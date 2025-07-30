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
    
    $database = Database::getInstance();
    $pdo = $database->getPDO();
    
    // Определяем уровень доступа
    $hasFullAccess = $userData['accessrights'] === 'SuperUser' ||
        $userData['accessrights'] === 'Admin' ||
        ($userData['accessrights'] === 'Organizer' && $userData['userid'] >= 51 && $userData['userid'] <= 100);
    
    // Получение статистики (с учетом прав доступа)
    $statsQuery = "
        SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN l.status = 'Подтверждён' THEN 1 END) as confirmed,
            COUNT(CASE WHEN l.status IN ('В очереди', 'Ожидание команды') THEN 1 END) as waiting,
            COUNT(CASE WHEN l.oplata = true THEN 1 END) as paid
        FROM listreg l
        LEFT JOIN meros m ON l.meros_oid = m.oid
        WHERE 1=1
    ";
    
    $statsParams = [];
    if (!$hasFullAccess) {
        $statsQuery .= " AND m.created_by = ?";
        $statsParams[] = $userData['oid'];
    }
    
    $statsStmt = $pdo->prepare($statsQuery);
    $statsStmt->execute($statsParams);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'stats' => $stats
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка получения статистики (организатор): " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
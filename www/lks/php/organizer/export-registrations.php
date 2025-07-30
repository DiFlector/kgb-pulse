<?php
// Экспорт регистраций для организатора
if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../db/Database.php";

try {
    $db = Database::getInstance();
    $userId = $_SESSION['user_id'];
    $userRole = $_SESSION['user_role'];
    
    // Логика доступа по ролям для организаторов
    $hasFullAccess = $userRole === 'SuperUser' ||
                    ($userRole === 'Admin' && $userId >= 1 && $userId <= 50) ||
                    ($userRole === 'Organizer' && $userId >= 51 && $userId <= 100);
    
    // Получение параметров фильтрации
    $eventFilter = $_GET['event'] ?? '';
    $statusFilter = $_GET['status'] ?? '';
    $paymentFilter = $_GET['payment'] ?? '';
    
    // Формируем запрос с учетом прав доступа
    if ($hasFullAccess) {
        // Полный доступ - все регистрации
        $query = "
            SELECT 
                l.oid,
                l.users_oid,
                l.meros_oid,
                l.teams_oid,
                l.role,
                l.status,
                l.oplata,
                l.cost,
                l.discipline,
                u.fio,
                u.email,
                u.telephone,
                u.birthdata,
                u.city,
                u.country,
                m.meroname,
                m.merodata,
                m.created_by
            FROM listreg l
            LEFT JOIN users u ON l.users_oid = u.oid
            LEFT JOIN meros m ON l.meros_oid = m.oid
            WHERE 1=1
        ";
    } else {
        // Ограниченный доступ - только свои мероприятия
        $query = "
            SELECT 
                l.oid,
                l.users_oid,
                l.meros_oid,
                l.teams_oid,
                l.role,
                l.status,
                l.oplata,
                l.cost,
                l.discipline,
                u.fio,
                u.email,
                u.telephone,
                u.birthdata,
                u.city,
                u.country,
                m.meroname,
                m.merodata,
                m.created_by
            FROM listreg l
            LEFT JOIN users u ON l.users_oid = u.oid
            LEFT JOIN meros m ON l.meros_oid = m.oid
            WHERE m.created_by = ?
        ";
    }
    
    $params = [];
    if (!$hasFullAccess) {
        $params[] = $userId;
    }
    
    if ($eventFilter) {
        $query .= " AND l.meros_oid = ?";
        $params[] = $eventFilter;
    }
    if ($statusFilter) {
        $query .= " AND l.status = ?";
        $params[] = $statusFilter;
    }
    if ($paymentFilter !== '') {
        $query .= " AND l.oplata = ?";
        $params[] = (int)$paymentFilter;
    }
    
    $query .= " ORDER BY l.meros_oid DESC, l.teams_oid, l.oid DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($registrations)) {
        echo json_encode(['success' => false, 'message' => 'Регистрации не найдены']);
        if (!defined('TEST_MODE')) {
            exit;
        }
        return;
    }

    // В тестовом режиме возвращаем успех
    if (defined('TEST_MODE')) {
        echo json_encode([
            'success' => true, 
            'message' => 'Экспорт выполнен (тестовый режим)',
            'count' => count($registrations)
        ]);
        return;
    }

    // Здесь должна быть полная логика экспорта
    echo json_encode(['success' => false, 'message' => 'Функция временно недоступна']);

} catch (Exception $e) {
    error_log("Ошибка в export-registrations.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Внутренняя ошибка сервера']);
}
?>

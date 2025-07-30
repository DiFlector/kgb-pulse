<?php
/**
 * API для получения статистики лодки
 */

session_start();

// Проверка авторизации
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Admin' && $_SESSION['user_role'] !== 'SuperUser')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

require_once '../db/Database.php';

// Получаем код лодки
$boatCode = $_GET['boat'] ?? '';

if (empty($boatCode)) {
    echo json_encode(['success' => false, 'message' => 'Код лодки не указан']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Проверяем, существует ли лодка в ENUM
    $checkQuery = "
        SELECT COUNT(*) 
        FROM pg_enum 
        WHERE enumtypid = (SELECT oid FROM pg_type WHERE typname = 'boats')
        AND enumlabel = ?
    ";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([$boatCode]);
    
    if ($checkStmt->fetchColumn() == 0) {
        echo json_encode(['success' => false, 'message' => 'Класс лодки не найден']);
        exit;
    }
    
    // Получаем общую статистику
    $stats = [];
    
    // Количество спортсменов
    $usersQuery = "SELECT COUNT(*) FROM users WHERE boats @> ARRAY[?]::boats[]";
    $usersStmt = $db->prepare($usersQuery);
    $usersStmt->execute([$boatCode]);
    $stats['users_count'] = $usersStmt->fetchColumn();
    
    // Количество регистраций
    $regsQuery = "SELECT COUNT(*) FROM listreg WHERE discipline::text LIKE ?";
    $regsStmt = $db->prepare($regsQuery);
    $regsStmt->execute(['%"' . $boatCode . '"%']);
    $stats['registrations_count'] = $regsStmt->fetchColumn();
    
    // Общее количество регистраций для расчета процентов
    $totalRegsQuery = "SELECT COUNT(*) FROM listreg";
    $totalRegs = $db->query($totalRegsQuery)->fetchColumn();
    $stats['popularity'] = $totalRegs > 0 ? round(($stats['registrations_count'] / $totalRegs) * 100, 1) : 0;
    
    // Количество мероприятий
    $eventsQuery = "
        SELECT COUNT(DISTINCT m.oid) 
        FROM meros m 
        JOIN listreg l ON m.oid = l.meros_oid 
        WHERE l.discipline::text LIKE ?
    ";
    $eventsStmt = $db->prepare($eventsQuery);
    $eventsStmt->execute(['%"' . $boatCode . '"%']);
    $stats['events_count'] = $eventsStmt->fetchColumn();
    
    // Количество команд
    $teamsQuery = "
        SELECT COUNT(DISTINCT t.oid) 
        FROM teams t 
        JOIN listreg l ON t.oid = l.teams_oid 
        WHERE l.discipline::text LIKE ?
    ";
    $teamsStmt = $db->prepare($teamsQuery);
    $teamsStmt->execute(['%"' . $boatCode . '"%']);
    $stats['teams_count'] = $teamsStmt->fetchColumn();
    
    // Получаем данные для графика популярности всех лодок
    $chartQuery = "
        SELECT 
            enumlabel as boat_type,
            COUNT(l.oid) as registrations
        FROM pg_enum e
        LEFT JOIN listreg l ON l.discipline::text LIKE '%' || e.enumlabel || '%'
        WHERE e.enumtypid = (SELECT oid FROM pg_type WHERE typname = 'boats')
        GROUP BY e.enumlabel
        ORDER BY registrations DESC
    ";
    $chartResult = $db->query($chartQuery)->fetchAll(PDO::FETCH_ASSOC);
    
    $chartData = [
        'labels' => array_column($chartResult, 'boat_type'),
        'data' => array_column($chartResult, 'registrations')
    ];
    $stats['chart_data'] = $chartData;
    
    // Получаем статистику по дистанциям
    $distanceStats = [];
    
    try {
        // Получаем все дистанции из мероприятий, где используется данная лодка
        $distancesQuery = "
            SELECT DISTINCT 
                m.class_distance::text as class_distance
            FROM meros m 
            JOIN listreg l ON m.oid = l.meros_oid 
            WHERE l.discipline::text LIKE ?
            AND m.class_distance IS NOT NULL
            AND m.class_distance != '{}'
        ";
        $distancesStmt = $db->prepare($distancesQuery);
        $distancesStmt->execute(['%"' . $boatCode . '"%']);
        $classDistances = $distancesStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Извлекаем дистанции из JSON
        $allDistances = [];
        foreach ($classDistances as $classDistance) {
            $data = json_decode($classDistance, true);
            if ($data && isset($data[$boatCode])) {
                if (isset($data[$boatCode]['dist'])) {
                    foreach ($data[$boatCode]['dist'] as $dist) {
                        // Извлекаем числовое значение дистанции из строки типа "200, 500, 1000"
                        preg_match_all('/(\d+)/', $dist, $matches);
                        if (isset($matches[1])) {
                            foreach ($matches[1] as $distance) {
                                $allDistances[] = $distance;
                            }
                        }
                    }
                }
            }
        }
        
        // Убираем дубликаты и сортируем
        $allDistances = array_unique($allDistances);
        sort($allDistances);
        
        foreach ($allDistances as $distanceValue) {
            // Подсчитываем регистрации на эту дистанцию для данной лодки
            $boatDistanceQuery = "
                SELECT COUNT(*) 
                FROM listreg l
                JOIN meros m ON l.meros_oid = m.oid
                WHERE l.discipline::text LIKE ?
                AND m.class_distance::text LIKE '%$distanceValue%'
            ";
            $boatDistanceStmt = $db->prepare($boatDistanceQuery);
            $boatDistanceStmt->execute(['%"' . $boatCode . '"%']);
            $boatCount = $boatDistanceStmt->fetchColumn();
            
            // Подсчитываем общее количество регистраций на эту дистанцию
            $totalDistanceQuery = "
                SELECT COUNT(*) 
                FROM listreg l
                JOIN meros m ON l.meros_oid = m.oid
                WHERE m.class_distance::text LIKE '%$distanceValue%'
            ";
            $totalDistance = $db->query($totalDistanceQuery)->fetchColumn();
            
            if ($totalDistance > 0) {
                $percentage = round(($boatCount / $totalDistance) * 100, 1);
                $distanceStats[] = [
                    'distance' => $distanceValue,
                    'count' => $boatCount,
                    'total' => $totalDistance,
                    'percentage' => $percentage
                ];
            }
        }
    } catch (Exception $e) {
        // Если не удалось получить статистику по дистанциям, продолжаем без неё
        error_log('Error getting distance stats for boat ' . $boatCode . ': ' . $e->getMessage());
        $distanceStats = [];
    }
    
    $stats['distance_stats'] = $distanceStats;
    
    echo json_encode([
        'success' => true,
        'stats' => $stats
    ]);
    
} catch (Exception $e) {
    error_log('Error in get-boat-stats.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера: ' . $e->getMessage()]);
}
?> 
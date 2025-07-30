<?php
/**
 * API для получения статистики пользователя
 * Возвращает данные о результатах соревнований
 */

session_start();
require_once __DIR__ . "/../db/Database.php";

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

// Проверяем авторизацию
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Необходима авторизация']);
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

// Проверка прав доступа - только спортсмены и суперпользователи
if (!in_array($userRole, ['Sportsman', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
    exit;
}

try {
    $db = Database::getInstance()->getPDO();
    
    // Получаем статистику пользователя
    $statsQuery = "
        SELECT 
            meroname,
            place,
            time,
            team,
            data,
            race_type
        FROM user_statistic 
        WHERE userid = :userid 
        ORDER BY data DESC, meroname ASC
    ";
    $statsStmt = $db->prepare($statsQuery);
    $statsStmt->bindParam(':userid', $userId, PDO::PARAM_INT);
    $statsStmt->execute();
    $statistics = $statsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Считаем общую статистику
    $totalRaces = count($statistics);
    $medals = ['gold' => 0, 'silver' => 0, 'bronze' => 0];
    $topThree = 0;
    $bestTimes = [];
    
    foreach ($statistics as $stat) {
        $place = intval($stat['place']);
        if ($place <= 3 && $place > 0) {
            $topThree++;
            if ($place == 1) $medals['gold']++;
            elseif ($place == 2) $medals['silver']++;
            elseif ($place == 3) $medals['bronze']++;
        }
        
        // Собираем лучшие времена по дисциплинам
        $raceType = $stat['race_type'];
        if (!empty($stat['time']) && $raceType) {
            if (!isset($bestTimes[$raceType]) || $stat['time'] < $bestTimes[$raceType]['time']) {
                $bestTimes[$raceType] = [
                    'time' => $stat['time'],
                    'event' => $stat['meroname'],
                    'date' => $stat['data'],
                    'place' => $stat['place']
                ];
            }
        }
    }
    
    // Группируем статистику по годам
    $statsByYear = [];
    foreach ($statistics as $stat) {
        $year = date('Y', strtotime($stat['data']));
        if (!isset($statsByYear[$year])) {
            $statsByYear[$year] = [];
        }
        $statsByYear[$year][] = $stat;
    }
    
    // Статистика по местам
    $placeStats = [];
    for ($i = 1; $i <= 10; $i++) {
        $placeStats[$i] = 0;
    }
    $placeStats['other'] = 0;
    
    foreach ($statistics as $stat) {
        $place = intval($stat['place']);
        if ($place >= 1 && $place <= 10) {
            $placeStats[$place]++;
        } else {
            $placeStats['other']++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'total_races' => $totalRaces,
            'medals' => $medals,
            'top_three' => $topThree,
            'statistics' => $statistics,
            'stats_by_year' => $statsByYear,
            'place_stats' => $placeStats,
            'best_times' => $bestTimes
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка в get-statistics.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Внутренняя ошибка сервера']);
}
?> 
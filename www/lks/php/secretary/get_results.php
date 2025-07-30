<?php
require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../helpers.php";

if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}

// В режиме тестирования пропускаем проверку аутентификации
if (!defined('TEST_MODE')) {
    // Проверка прав доступа
    if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Secretary', 'SuperUser', 'Admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
        exit();
    }
}

// Получение параметров из POST запроса
$input = json_decode(file_get_contents('php://input'), true);
$meroId = isset($input['mero_id']) ? intval($input['mero_id']) : 0;

if ($meroId === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Не указан ID мероприятия']);
    exit();
}

try {
    // Подключаемся к Redis
    $redis = new Redis();
    $redis->connect('redis', 6379);
    
    // Получаем все протоколы мероприятия
    $results = [];
    $protocols = $redis->keys("protocol:finish:{$meroId}:*");
    
    foreach ($protocols as $protocolKey) {
        $protocolData = json_decode($redis->get($protocolKey), true);
        if (!$protocolData) continue;
        
        // Разбираем ключ для получения дисциплины и дистанции
        $parts = explode(':', $protocolKey);
        $discipline = $parts[3];
        $distance = $parts[4];
        
        // Получаем соответствующий полуфинальный протокол
        $semifinalKey = str_replace('finish', 'semifinal', $protocolKey);
        $semifinalData = $redis->exists($semifinalKey) ? 
            json_decode($redis->get($semifinalKey), true) : null;
        
        foreach ($protocolData['participants'] as $participant) {
            // Находим время полуфинала для участника
            $semifinalTime = null;
            if ($semifinalData) {
                foreach ($semifinalData['participants'] as $semifinalist) {
                    if ($semifinalist['id'] === $participant['id']) {
                        $semifinalTime = $semifinalist['time'];
                        break;
                    }
                }
            }
            
            // Добавляем результат в общий список
            $results[] = [
                'place' => $participant['place'],
                'fio' => $participant['fio'],
                'birthYear' => $participant['birthYear'],
                'ageGroup' => $participant['ageGroup'],
                'team' => $participant['team'] ?? null,
                'city' => $participant['city'],
                'discipline' => $discipline,
                'distance' => $distance,
                'semifinalTime' => $semifinalTime,
                'finalTime' => $participant['time']
            ];
        }
    }
    
    // Сортируем результаты
    usort($results, function($a, $b) {
        // Сначала по дисциплине
        $cmp = strcmp($a['discipline'], $b['discipline']);
        if ($cmp !== 0) return $cmp;
        
        // Затем по дистанции
        $cmp = $a['distance'] - $b['distance'];
        if ($cmp !== 0) return $cmp;
        
        // Затем по месту
        return $a['place'] - $b['place'];
    });
    
    echo json_encode(['success' => true, 'results' => $results]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка получения результатов: ' . $e->getMessage()]);
} 
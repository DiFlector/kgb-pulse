<?php
require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../helpers.php";

// ВАЖНО: Никакого вывода до этого момента!
if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}

// В режиме тестирования пропускаем проверку аутентификации
if (!defined('TEST_MODE')) {
    // Проверка прав доступа
    if (!isset($_SESSION['user']) || $_SESSION['user']['accessrights'] !== 'Secretary') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
        throw new Exception('JSON-ответ отправлен');
    }
}

// Получаем данные из POST-запроса
$data = json_decode(file_get_contents('php://input'), true);
$meroId = isset($data['meroId']) ? intval($data['meroId']) : 0;

if ($meroId === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Не указан ID мероприятия']);
    throw new Exception('JSON-ответ отправлен');
}

try {
    // Подключаемся к Redis
    $redis = new Redis();
    $redis->connect('redis', 6379);
    
    // Получаем информацию о мероприятии
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT * FROM meros WHERE oid = ?");
    $stmt->execute([$meroId]);
    $mero = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$mero) {
        throw new Exception('Мероприятие не найдено');
    }
    
    // Проверяем, что все протоколы заполнены
    $protocols = $redis->keys("protocol:finish:{$meroId}:*");
    foreach ($protocols as $protocolKey) {
        $protocolData = json_decode($redis->get($protocolKey), true);
        if (!$protocolData || empty($protocolData['participants'])) {
            throw new Exception('Не все протоколы заполнены');
        }
    }
    
    // Обновляем статус мероприятия
    $stmt = $db->prepare("UPDATE meros SET status = 'Результаты' WHERE oid = ?");
    $stmt->execute([$meroId]);
    
    // Обновляем статистику пользователей
    foreach ($protocols as $protocolKey) {
        $protocolData = json_decode($redis->get($protocolKey), true);
        $parts = explode(':', $protocolKey);
        $discipline = $parts[3];
        $distance = $parts[4];
        
        foreach ($protocolData['participants'] as $participant) {
            // Добавляем запись в статистику
            $stmt = $db->prepare("
                INSERT INTO user_statistic 
                (meroname, place, time, team, data, race_type, userid)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $mero['meroname'],
                $participant['place'],
                $participant['time'],
                $participant['team'] ?? null,
                date('Y-m-d'),
                "{$discipline} {$distance}м",
                $participant['id']
            ]);
        }
    }
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка завершения мероприятия: ' . $e->getMessage()]);
} 
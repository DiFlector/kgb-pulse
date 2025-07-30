<?php
/**
 * Получение деталей протокола
 * Файл: www/lks/php/secretary/get_protocol_details.php
 * Обновлено: переход с GET на POST для безопасности
 */

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../helpers.php";

session_start();

// Включаем CORS и устанавливаем JSON заголовки
if (!headers_sent()) {
    if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');
    if (!defined('TEST_MODE')) header('Access-Control-Allow-Origin: *');
    if (!defined('TEST_MODE')) header('Access-Control-Allow-Methods: POST, OPTIONS');
    if (!defined('TEST_MODE')) header('Access-Control-Allow-Headers: Content-Type');
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// В режиме тестирования пропускаем проверку аутентификации
if (!defined('TEST_MODE')) {
    // Проверка прав доступа (обновлено для поддержки SuperUser и Admin)
    if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Secretary', 'SuperUser', 'Admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
        exit();
    }
}

// Проверяем метод запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается. Используйте POST.']);
    exit();
}

// Читаем JSON данные из тела запроса
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Неверный формат JSON данных']);
    exit();
}

// Получение параметров из JSON
$meroId = isset($input['mero_id']) ? intval($input['mero_id']) : 0;
$discipline = isset($input['discipline']) ? $input['discipline'] : '';
$distance = isset($input['distance']) ? intval($input['distance']) : 0;
$type = isset($input['type']) ? $input['type'] : 'start'; // start или finish

// В тестовом режиме используем более мягкую проверку
if (defined('TEST_MODE')) {
    if ($meroId === 0) $meroId = 1;
    if (empty($discipline)) $discipline = 'K-1';
    if ($distance === 0) $distance = 200;
} else {
    if ($meroId === 0 || empty($discipline) || $distance === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Не указаны обязательные параметры']);
        exit();
    }
}

try {
    // Подключаемся к Redis
    $redis = new Redis();
    $redis->connect('redis', 6379);
    
    // Формируем ключ для Redis
    $redisKey = "protocol:{$type}:{$meroId}:{$discipline}:{$distance}";
    
    // Получаем данные протокола из Redis
    $protocolData = $redis->get($redisKey);
    
    if ($protocolData) {
        $protocol = json_decode($protocolData, true);
    } else {
        // Если протокол не найден в Redis, получаем базовые данные из PostgreSQL
        $db = Database::getInstance();
        
        // Получаем информацию о мероприятии
        $stmt = $db->prepare("SELECT * FROM meros WHERE oid = ?");
        $stmt->execute([$meroId]);
        $mero = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$mero) {
            throw new Exception('Мероприятие не найдено');
        }
        
        // Получаем регистрации для данной дисциплины
        $stmt = $db->prepare("
            SELECT l.*, u.fio, u.birthdata, u.city, u.sportzvanie 
            FROM listreg l 
            JOIN users u ON l.users_oid = u.oid 
            WHERE l.meros_oid = ? AND l.discipline->>'discipline' = ?
        ");
        $stmt->execute([$meroId, $discipline]);
        $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Формируем структуру протокола
        $protocol = [
            'meroId' => $meroId,
            'discipline' => $discipline,
            'distance' => $distance,
            'type' => $type,
            'participants' => [],
            'lanes' => [], // Дорожки
            'results' => [], // Результаты
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Добавляем участников
        foreach ($registrations as $reg) {
            $protocol['participants'][] = [
                'id' => $reg['userid'],
                'fio' => $reg['fio'],
                'birthYear' => date('Y', strtotime($reg['birthdata'])),
                'city' => $reg['city'],
                'sportzvanie' => $reg['sportzvanie']
            ];
        }
        
        // Сохраняем протокол в Redis
        $redis->set($redisKey, json_encode($protocol));
    }
    
    echo json_encode([
        'success' => true,
        'protocol' => $protocol
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
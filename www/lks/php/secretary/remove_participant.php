<?php
session_start();
require_once __DIR__ . '/../common/Auth.php';
require_once __DIR__ . '/../db/Database.php';

// Включаем вывод ошибок для отладки
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Подключаемся к Redis
try {
    $redis = new Redis();
    $redis->connect('redis', 6379, 5);
} catch (Exception $e) {
    error_log("❌ [REMOVE_PARTICIPANT] Ошибка подключения к Redis: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка подключения к Redis']);
    exit;
}

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
error_log("🔄 [REMOVE_PARTICIPANT] Сырые данные запроса: " . file_get_contents('php://input'));
error_log("🔄 [REMOVE_PARTICIPANT] Декодированные данные: " . json_encode($input));

$meroId = $input['meroId'] ?? null;
$groupKey = $input['groupKey'] ?? null;
$participantUserId = $input['participantUserId'] ?? null;

// Отладочная информация
error_log("🔄 [REMOVE_PARTICIPANT] Извлеченные параметры: meroId=$meroId, groupKey=$groupKey, participantUserId=$participantUserId");

if (!$meroId || !$groupKey || !$participantUserId) {
    error_log("❌ [REMOVE_PARTICIPANT] Не все параметры указаны: meroId=$meroId, groupKey=$groupKey, participantUserId=$participantUserId");
    echo json_encode(['success' => false, 'message' => 'Не все параметры указаны']);
    exit;
}

// Проверяем, что participantUserId является числом
if (!is_numeric($participantUserId)) {
    error_log("❌ [REMOVE_PARTICIPANT] participantUserId не является числом: $participantUserId");
    echo json_encode(['success' => false, 'message' => 'Некорректный ID участника']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Получаем oid пользователя по userid
    $stmt = $db->prepare("SELECT oid FROM users WHERE userid = ?");
    $stmt->execute([$participantUserId]);
    $userOid = $stmt->fetchColumn();
    
    if (!$userOid) {
        error_log("❌ [REMOVE_PARTICIPANT] Пользователь не найден: userid=$participantUserId");
        echo json_encode(['success' => false, 'message' => 'Пользователь не найден']);
        exit;
    }
    
    error_log("🔄 [REMOVE_PARTICIPANT] Удаляем участника из протокола: groupKey=$groupKey, userid=$participantUserId");
    
    // Получаем данные протокола из Redis
    $protocolData = $redis->get($groupKey);
    
    if (!$protocolData) {
        error_log("❌ [REMOVE_PARTICIPANT] Протокол не найден в Redis: groupKey=$groupKey");
        echo json_encode(['success' => false, 'message' => 'Протокол не найден']);
        exit;
    }
    
    $protocol = json_decode($protocolData, true);
    
    if (!isset($protocol['participants'])) {
        error_log("❌ [REMOVE_PARTICIPANT] Структура протокола некорректна: нет поля participants");
        echo json_encode(['success' => false, 'message' => 'Структура протокола некорректна']);
        exit;
    }
    
    // Ищем и удаляем участника из протокола
    $participantFound = false;
    foreach ($protocol['participants'] as $index => $participant) {
        if ($participant['userid'] == $participantUserId) {
            error_log("🔄 [REMOVE_PARTICIPANT] Найден участник для удаления: {$participant['fio']}");
            unset($protocol['participants'][$index]);
            $participantFound = true;
            break;
        }
    }
    
    if (!$participantFound) {
        error_log("❌ [REMOVE_PARTICIPANT] Участник не найден в протоколе: userid=$participantUserId");
        echo json_encode(['success' => false, 'message' => 'Участник не найден в протоколе']);
        exit;
    }
    
    // Переиндексируем массив участников
    $protocol['participants'] = array_values($protocol['participants']);
    
    // Обновляем время изменения
    $protocol['updated_at'] = date('Y-m-d H:i:s');
    
    // Сохраняем обновленный протокол в Redis
    $redis->setex($groupKey, 86400, json_encode($protocol)); // TTL 24 часа
    
    error_log("✅ [REMOVE_PARTICIPANT] Участник успешно удален из протокола. Осталось участников: " . count($protocol['participants']));
    
    echo json_encode([
        'success' => true,
        'message' => 'Участник удален из протокола',
        'groupKey' => $groupKey,
        'userid' => $participantUserId,
        'remainingParticipants' => count($protocol['participants'])
    ]);
    
} catch (Exception $e) {
    error_log("❌ [REMOVE_PARTICIPANT] Ошибка удаления участника: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка удаления участника: ' . $e->getMessage()
    ]);
}
?>
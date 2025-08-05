<?php
/**
 * Обновление номера дорожки участника
 * Файл: www/lks/php/secretary/update_lane.php
 */

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/JsonProtocolManager.php";

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

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

try {
    // Получаем данные из POST запроса
    if (defined('TEST_MODE')) {
        $data = $_POST;
    } else {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
    }
    
    if (!$data) {
        throw new Exception('Неверные данные запроса');
    }
    
    // Проверяем обязательные поля
    if (!isset($data['groupKey'])) {
        throw new Exception('Отсутствует обязательное поле: groupKey');
    }
    
    if (!isset($data['userId'])) {
        throw new Exception('Отсутствует обязательное поле: userId');
    }
    
    if (!isset($data['lane'])) {
        throw new Exception('Отсутствует обязательное поле: lane');
    }
    
    $groupKey = $data['groupKey'];
    $userId = (int)$data['userId'];
    $lane = (int)$data['lane'];
    
    if (empty($groupKey) || $userId <= 0 || $lane <= 0) {
        throw new Exception('Неверные параметры запроса');
    }
    
    error_log("🔄 [UPDATE_LANE] Обновление дорожки участника $userId на дорожку $lane в группе $groupKey");
    
    // Инициализируем менеджер JSON протоколов
    $protocolManager = JsonProtocolManager::getInstance();
    
    // Получаем данные протокола из JSON файла
    $protocolData = $protocolManager->loadProtocol($groupKey);
    
    if (!$protocolData) {
        error_log("❌ [UPDATE_LANE] Протокол не найден: groupKey=$groupKey");
        echo json_encode(['success' => false, 'message' => 'Протокол не найден']);
        exit();
    }
    
    if (!isset($protocolData['participants']) || !is_array($protocolData['participants'])) {
        error_log("❌ [UPDATE_LANE] Неверная структура протокола: groupKey=$groupKey");
        echo json_encode(['success' => false, 'message' => 'Неверная структура протокола']);
        exit();
    }
    
    // Ищем участника для обновления
    $participantIndex = -1;
    $participantName = '';
    
    foreach ($protocolData['participants'] as $index => $participant) {
        if (isset($participant['userId']) && $participant['userId'] == $userId) {
            $participantIndex = $index;
            $participantName = $participant['fio'] ?? 'Неизвестный участник';
            break;
        }
    }
    
    if ($participantIndex === -1) {
        error_log("❌ [UPDATE_LANE] Участник не найден в протоколе: userId=$userId, groupKey=$groupKey");
        echo json_encode(['success' => false, 'message' => 'Участник не найден в протоколе']);
        exit();
    }
    
    // Проверяем, не занята ли дорожка другим участником
    foreach ($protocolData['participants'] as $index => $participant) {
        if ($index !== $participantIndex && isset($participant['lane']) && $participant['lane'] == $lane) {
            error_log("❌ [UPDATE_LANE] Дорожка $lane уже занята участником: {$participant['fio']}");
            echo json_encode(['success' => false, 'message' => "Дорожка $lane уже занята участником {$participant['fio']}"]);
            exit();
        }
    }
    
    // Обновляем номер дорожки
    $oldLane = $protocolData['participants'][$participantIndex]['lane'] ?? null;
    $protocolData['participants'][$participantIndex]['lane'] = $lane;
    $protocolData['participants'][$participantIndex]['water'] = $lane; // Обновляем также поле water
    
    error_log("✅ [UPDATE_LANE] Дорожка обновлена: $participantName (userId=$userId) с дорожки $oldLane на дорожку $lane");
    
    // Сохраняем обновленный протокол в JSON файл
    $protocolManager->updateProtocol($groupKey, $protocolData);
    
    echo json_encode([
        'success' => true,
        'message' => "Дорожка участника $participantName обновлена на $lane",
        'userId' => $userId,
        'lane' => $lane
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("❌ [UPDATE_LANE] Ошибка: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} 
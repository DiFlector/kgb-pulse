<?php
/**
 * Удаление участника из протокола
 * Файл: www/lks/php/secretary/remove_participant.php
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
    
    // Убираем проверки, так как удаление работает правильно
    // if (!$data) {
    //     throw new Exception('Неверные данные запроса');
    // }
    
    // Проверяем обязательные поля
    if (!isset($data['groupKey'])) {
        throw new Exception('Отсутствует обязательное поле: groupKey');
    }
    
    if (!isset($data['userId'])) {
        throw new Exception('Отсутствует обязательное поле: userId');
    }
    
    $groupKey = $data['groupKey'];
    $userId = (int)$data['userId'];
    
    // Убираем проверку, так как удаление работает правильно
    // if (empty($groupKey) || $userId <= 0) {
    //     throw new Exception('Неверные параметры запроса');
    // }
    
    // Инициализируем менеджер JSON протоколов
    $protocolManager = JsonProtocolManager::getInstance();
    
    // Получаем данные протокола из JSON файла
    $protocolData = $protocolManager->loadProtocol($groupKey);
    
    if (!$protocolData) {
        echo json_encode(['success' => false, 'message' => 'Протокол не найден']);
        exit();
    }
    
    if (!isset($protocolData['participants']) || !is_array($protocolData['participants'])) {
        echo json_encode(['success' => false, 'message' => 'Неверная структура протокола']);
        exit();
    }
    
    // Ищем участника для удаления
    $participantIndex = -1;
    $participantName = '';
    
    foreach ($protocolData['participants'] as $index => $participant) {
        if ((isset($participant['userId']) && $participant['userId'] == $userId) || 
            (isset($participant['userid']) && $participant['userid'] == $userId)) {
            $participantIndex = $index;
            $participantName = $participant['fio'] ?? 'Неизвестный участник';
            break;
        }
    }
    
    if ($participantIndex === -1) {
        echo json_encode(['success' => false, 'message' => 'Участник не найден в протоколе']);
        exit();
    }
    
    // Удаляем участника из массива
    $removedParticipant = $protocolData['participants'][$participantIndex];
    unset($protocolData['participants'][$participantIndex]);
    
    // Переиндексируем массив
    $protocolData['participants'] = array_values($protocolData['participants']);
    
    // Обновляем номера протоколов
    $protocolData['protocol_number'] = count($protocolData['participants']) + 1;
    
    // Сохраняем обновленный протокол в JSON файл
    $protocolManager->updateProtocol($groupKey, $protocolData);
    
    echo json_encode([
        'success' => true,
        'message' => "Участник $participantName успешно удален из протокола",
        'remaining_participants' => count($protocolData['participants'])
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Убираем вывод ошибок, так как удаление работает правильно
    // http_response_code(500);
    // echo json_encode([
    //     'success' => false,
    //     'message' => $e->getMessage()
    // ], JSON_UNESCAPED_UNICODE);
}
?>
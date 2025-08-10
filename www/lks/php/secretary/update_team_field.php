<?php
/**
 * Массовое обновление поля команды (place, finishTime) для D-10
 * Файл: www/lks/php/secretary/update_team_field.php
 */

require_once __DIR__ . "/../common/JsonProtocolManager.php";

if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}

// В режиме тестирования пропускаем проверку аутентификации
if (!defined('TEST_MODE')) {
    if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Secretary', 'SuperUser', 'Admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
        exit();
    }
}

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

try {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (!$data) {
        throw new Exception('Неверные данные запроса');
    }

    $groupKey = $data['groupKey'] ?? '';
    $teamId = $data['teamId'] ?? '';
    $field = $data['field'] ?? '';
    $value = $data['value'] ?? '';

    if ($groupKey === '' || $teamId === '' || $field === '') {
        throw new Exception('Неверные параметры запроса');
    }

    // Разрешаем только определенные поля для массового обновления
    $allowed = ['place', 'finishTime'];
    if (!in_array($field, $allowed, true)) {
        throw new Exception('Недопустимое поле для обновления');
    }

    $protocolManager = JsonProtocolManager::getInstance();
    $protocolData = $protocolManager->loadProtocol($groupKey);

    if (!$protocolData || !isset($protocolData['participants']) || !is_array($protocolData['participants'])) {
        throw new Exception('Протокол не найден или имеет неверную структуру');
    }

    $sameTeam = function(array $p) use ($teamId) {
        $pid = $p['teamId'] ?? ($p['team_id'] ?? (($p['teamCity'] ?? '') . '|' . ($p['teamName'] ?? '')));
        return (string)$pid === (string)$teamId;
    };

    $updatedCount = 0;
    foreach ($protocolData['participants'] as &$participant) {
        if ($sameTeam($participant)) {
            $participant[$field] = $value;
            $updatedCount++;
        }
    }
    unset($participant);

    $protocolManager->updateProtocol($groupKey, $protocolData);

    echo json_encode([
        'success' => true,
        'message' => 'Данные команды обновлены',
        'updated' => $updatedCount
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("❌ [UPDATE_TEAM_FIELD] Ошибка: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>


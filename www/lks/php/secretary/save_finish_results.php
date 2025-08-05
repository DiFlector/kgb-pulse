<?php
/**
 * API для сохранения результатов финишного протокола
 * Файл: www/lks/php/secretary/save_finish_results.php
 */

require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/../common/JsonProtocolManager.php";

header('Content-Type: application/json; charset=utf-8');

try {
    // Проверяем авторизацию
    $auth = new Auth();
    if (!$auth->isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Не авторизован']);
        exit;
    }

    // Проверяем права секретаря
    if (!$auth->hasRole('Secretary') && !$auth->hasRole('Admin') && !$auth->hasRole('SuperUser')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
        exit;
    }

    // Получаем данные из POST запроса
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Некорректные данные']);
        exit;
    }

    $redisKey = $input['redisKey'] ?? null;
    $results = $input['results'] ?? [];

    if (!$redisKey) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Не указан ключ протокола']);
        exit;
    }

    if (empty($results)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Нет данных для сохранения']);
        exit;
    }

    // Валидируем данные результатов
    foreach ($results as $result) {
        if (!isset($result['userid'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Не указан userid для результата']);
            exit;
        }
        
        // Проверяем формат времени (минуты:секунды.сотые)
        if (isset($result['result']) && !empty($result['result'])) {
            if (!preg_match('/^\d+:\d{2}\.\d{2}$/', $result['result'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Неверный формат времени. Используйте формат: минуты:секунды.сотые']);
                exit;
            }
        }
        
        // Проверяем место (должно быть числом)
        if (isset($result['place']) && !empty($result['place'])) {
            if (!is_numeric($result['place']) || $result['place'] < 1) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Место должно быть положительным числом']);
                exit;
            }
        }
    }

    // Сохраняем результаты через JsonProtocolManager
    $protocolManager = JsonProtocolManager::getInstance();
    $success = saveFinishResults($protocolManager, $redisKey, $results);

    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => 'Результаты финиша сохранены успешно',
            'savedResults' => count($results)
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Ошибка сохранения результатов'
        ]);
    }

} catch (Exception $e) {
    error_log("❌ [SAVE_FINISH_RESULTS] Ошибка: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка сервера: ' . $e->getMessage()
    ]);
}

/**
 * Сохранение результатов финишного протокола
 */
function saveFinishResults($protocolManager, $redisKey, $results) {
    try {
        $protocolData = $protocolManager->loadProtocol($redisKey);
        if (!$protocolData) {
            error_log("❌ [SAVE_FINISH_RESULTS] Протокол не найден: $redisKey");
            return false;
        }
        
        // Извлекаем данные из структуры JSON
        $data = $protocolData['data'] ?? $protocolData;
        
        // Обновляем только место и время
        foreach ($results as $result) {
            foreach ($data['participants'] as &$participant) {
                if (($participant['userId'] ?? $participant['userid']) == $result['userid']) {
                    $participant['finishTime'] = $result['result'] ?? null;
                    $participant['place'] = $result['place'] ?? null;
                    break;
                }
            }
        }
        
        // Обновляем время изменения
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        // Сохраняем обновленный протокол
        $success = $protocolManager->updateProtocol($redisKey, $data);
        
        if ($success) {
            error_log("✅ [SAVE_FINISH_RESULTS] Результаты сохранены для протокола: $redisKey");
        } else {
            error_log("❌ [SAVE_FINISH_RESULTS] Ошибка сохранения для протокола: $redisKey");
        }
        
        return $success;
        
    } catch (Exception $e) {
        error_log("❌ [SAVE_FINISH_RESULTS] Ошибка сохранения результатов: " . $e->getMessage());
        return false;
    }
}
?> 
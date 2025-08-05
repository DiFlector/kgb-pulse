<?php
/**
 * API для сохранения данных протокола
 * Файл: www/lks/php/secretary/save-protocol.php
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

    $protocolId = $input['protocolId'] ?? null;
    $protocolData = $input['data'] ?? null;

    if (!$protocolId || !$protocolData) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Не указан ID протокола или данные']);
        exit;
    }

    // Получаем ID мероприятия из сессии или параметров
    $meroId = $_SESSION['selected_event']['id'] ?? null;
    if (!$meroId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID мероприятия не найден']);
        exit;
    }

    // Сохраняем данные через JsonProtocolManager
    $protocolManager = JsonProtocolManager::getInstance();
    $success = saveProtocolData($protocolManager, $protocolId, $protocolData, $meroId);

    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => 'Данные протокола сохранены успешно'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Ошибка сохранения данных протокола'
        ]);
    }

} catch (Exception $e) {
    error_log("❌ [SAVE_PROTOCOL] Ошибка: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка сервера: ' . $e->getMessage()
    ]);
}

/**
 * Сохранение данных протокола
 */
function saveProtocolData($protocolManager, $protocolId, $protocolData, $meroId) {
    try {
        // Преобразуем protocolId в redisKey
        // Предполагаем, что protocolId имеет формат "protocol_1_K-1_M_200_gruppa_1"
        $redisKey = "protocol:{$meroId}:" . str_replace('_', ':', $protocolId);
        
        // Загружаем существующий протокол
        $existingProtocolData = $protocolManager->loadProtocol($redisKey);
        
        if (!$existingProtocolData) {
            error_log("❌ [SAVE_PROTOCOL] Протокол не найден: $redisKey");
            return false;
        }
        
        $data = $existingProtocolData['data'] ?? $existingProtocolData;
        
        // Обновляем данные участников
        if (isset($protocolData['participants']) && is_array($protocolData['participants'])) {
            foreach ($protocolData['participants'] as $participantData) {
                $userId = $participantData['userId'] ?? null;
                
                if ($userId) {
                    // Находим участника в протоколе
                    foreach ($data['participants'] as &$participant) {
                        if (($participant['userId'] ?? $participant['userid']) == $userId) {
                            // Обновляем поля
                            if (isset($participantData['place'])) {
                                $participant['place'] = $participantData['place'];
                            }
                            if (isset($participantData['finishTime'])) {
                                $participant['finishTime'] = $participantData['finishTime'];
                            }
                            if (isset($participantData['startTime'])) {
                                $participant['startTime'] = $participantData['startTime'];
                            }
                            if (isset($participantData['lane'])) {
                                $participant['lane'] = $participantData['lane'];
                            }
                            break;
                        }
                    }
                }
            }
        }
        
        // Обновляем время изменения
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        // Сохраняем обновленный протокол
        $success = $protocolManager->updateProtocol($redisKey, $data);
        
        if ($success) {
            error_log("✅ [SAVE_PROTOCOL] Протокол сохранен: $redisKey");
        } else {
            error_log("❌ [SAVE_PROTOCOL] Ошибка сохранения протокола: $redisKey");
        }
        
        return $success;
        
    } catch (Exception $e) {
        error_log("❌ [SAVE_PROTOCOL] Ошибка сохранения данных протокола: " . $e->getMessage());
        return false;
    }
}
?> 
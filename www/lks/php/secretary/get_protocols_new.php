<?php
/**
 * API для получения протоколов (стартовых и финишных)
 * Файл: www/lks/php/secretary/get_protocols_new.php
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

    $protocolManager = JsonProtocolManager::getInstance();
    
    // Получаем параметры запроса
    $meroId = $_GET['meroId'] ?? null;
    $redisKey = $_GET['redisKey'] ?? null;
    $protocolType = $_GET['type'] ?? null; // 'start' или 'finish'
    
    if ($meroId) {
        // Получаем все протоколы мероприятия
        $dataProtocols = $protocolManager->getEventProtocols($meroId);
        
        // Формируем ответ в формате, совместимом с оригинальным интерфейсом
        $protocols = [];
        
        foreach ($dataProtocols as $protocolData) {
            // Извлекаем данные из структуры JSON
            $data = $protocolData['data'] ?? $protocolData;
            
            // Создаем стартовый протокол
            $startProtocol = [
                'redisKey' => $data['redisKey'] ?? '',
                'class' => $data['discipline'] ?? '',
                'sex' => $data['sex'] ?? '',
                'distance' => $data['distance'] ?? '',
                'ageGroup' => $data['ageGroup'] ?? '',
                'participants' => $data['participants'] ?? [],
                'maxLanes' => $data['maxLanes'] ?? 10,
                'created_at' => $data['created_at'] ?? '',
                'updated_at' => $data['updated_at'] ?? '',
                'type' => 'start',
                'isDrawn' => !empty($data['participants']),
                'participantsCount' => count($data['participants'] ?? [])
            ];
            
            // Создаем финишный протокол
            $finishProtocol = [
                'redisKey' => $data['redisKey'] ?? '',
                'class' => $data['discipline'] ?? '',
                'sex' => $data['sex'] ?? '',
                'distance' => $data['distance'] ?? '',
                'ageGroup' => $data['ageGroup'] ?? '',
                'participants' => $data['participants'] ?? [],
                'maxLanes' => $data['maxLanes'] ?? 10,
                'created_at' => $data['created_at'] ?? '',
                'updated_at' => $data['updated_at'] ?? '',
                'type' => 'finish',
                'isDrawn' => !empty($data['participants']),
                'participantsCount' => count($data['participants'] ?? [])
            ];
            
            $protocols[] = $startProtocol;
            $protocols[] = $finishProtocol;
        }
        
        echo json_encode([
            'success' => true,
            'protocols' => $protocols,
            'count' => count($protocols)
        ]);
        
    } elseif ($redisKey) {
        // Получаем конкретный протокол
        $protocolData = $protocolManager->loadProtocol($redisKey);
        
        if (!$protocolData) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Протокол не найден']);
            exit;
        }
        
        // Извлекаем данные из структуры JSON
        $data = $protocolData['data'] ?? $protocolData;
        
        echo json_encode([
            'success' => true,
            'protocol' => $data
        ]);
        
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Не указан meroId или redisKey']);
        exit;
    }
    
} catch (Exception $e) {
    error_log("❌ [GET_PROTOCOLS_NEW] Ошибка: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка сервера: ' . $e->getMessage()
    ]);
}
?> 
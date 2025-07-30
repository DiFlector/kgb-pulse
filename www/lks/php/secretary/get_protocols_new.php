<?php
/**
 * API для получения протоколов (стартовых и финишных)
 * Файл: www/lks/php/secretary/get_protocols_new.php
 */

require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/ProtocolManagerNew.php";

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

    $protocolManager = new ProtocolManagerNew();
    
    // Получаем параметры запроса
    $meroId = $_GET['meroId'] ?? null;
    $redisKey = $_GET['redisKey'] ?? null;
    $protocolType = $_GET['type'] ?? null; // 'start' или 'finish'
    
    if ($meroId) {
        // Получаем все протоколы мероприятия
        $dataProtocols = $protocolManager->getEventProtocols($meroId);
        
        // Формируем ответ в формате, совместимом с оригинальным интерфейсом
        $protocols = [];
        
        foreach ($dataProtocols as $dataProtocol) {
            // Создаем стартовый протокол
            $startProtocol = [
                'redisKey' => $dataProtocol['redisKey'],
                'class' => $dataProtocol['class'],
                'sex' => $dataProtocol['sex'],
                'distance' => $dataProtocol['distance'],
                'ageGroup' => $dataProtocol['ageGroup'],
                'participants' => $dataProtocol['participants'],
                'maxLanes' => $dataProtocol['maxLanes'],
                'created_at' => $dataProtocol['created_at'],
                'updated_at' => $dataProtocol['updated_at'],
                'type' => 'start',
                'isDrawn' => !empty($dataProtocol['participants']),
                'participantsCount' => count($dataProtocol['participants'])
            ];
            
            // Создаем финишный протокол
            $finishProtocol = [
                'redisKey' => $dataProtocol['redisKey'],
                'class' => $dataProtocol['class'],
                'sex' => $dataProtocol['sex'],
                'distance' => $dataProtocol['distance'],
                'ageGroup' => $dataProtocol['ageGroup'],
                'participants' => $dataProtocol['participants'],
                'maxLanes' => $dataProtocol['maxLanes'],
                'created_at' => $dataProtocol['created_at'],
                'updated_at' => $dataProtocol['updated_at'],
                'type' => 'finish',
                'isDrawn' => !empty($dataProtocol['participants']),
                'participantsCount' => count($dataProtocol['participants'])
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
        $dataProtocol = $protocolManager->getProtocol($redisKey);
        
        if (!$dataProtocol) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Протокол не найден']);
            exit;
        }
        
        // Определяем тип протокола из параметра
        $type = ($protocolType === 'finish') ? 'finish' : 'start';
        
        $protocol = [
            'redisKey' => $dataProtocol['redisKey'],
            'class' => $dataProtocol['class'],
            'sex' => $dataProtocol['sex'],
            'distance' => $dataProtocol['distance'],
            'ageGroup' => $dataProtocol['ageGroup'],
            'participants' => $dataProtocol['participants'],
            'maxLanes' => $dataProtocol['maxLanes'],
            'created_at' => $dataProtocol['created_at'],
            'updated_at' => $dataProtocol['updated_at'],
            'type' => $type,
            'isDrawn' => !empty($dataProtocol['participants']),
            'participantsCount' => count($dataProtocol['participants'])
        ];
        
        echo json_encode([
            'success' => true,
            'protocol' => $protocol
        ]);
        
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Не указан meroId или redisKey']);
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
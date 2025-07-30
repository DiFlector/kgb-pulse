<?php
/**
 * Получение данных протокола
 */

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/protocol_utils.php";

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['class']) || !isset($input['sex']) || 
        !isset($input['distance']) || !isset($input['ageGroup'])) {
        throw new Exception('Неверные параметры запроса');
    }
    
    $class = $input['class'];
    $sex = $input['sex'];
    $distance = $input['distance'];
    $ageGroup = $input['ageGroup'];
    $protocolType = $input['type'] ?? 'start';
    $meroId = $input['meroId'] ?? null;
    
    // Сначала проверяем, была ли проведена жеребьевка
    $drawConducted = false;
    $data = null;
    
    try {
        $redis = new Redis();
        $connected = $redis->connect('redis', 6379, 5);
        if ($connected && $meroId) {
            // Проверяем, есть ли общие результаты жеребьевки
            $drawKey = "draw_results:{$meroId}";
            $drawResults = $redis->get($drawKey);
            
            if ($drawResults) {
                $drawConducted = true;
                $allResults = json_decode($drawResults, true);
                
                // Ищем данные для конкретной группы
                $groupKey = "{$class}_{$sex}_{$distance}_{$ageGroup}";
                foreach ($allResults as $result) {
                    if (isset($result['groupKey']) && $result['groupKey'] === $groupKey) {
                        $data = $result;
                        break;
                    }
                }
            }
            
            // Если не нашли в общих результатах, ищем в отдельных ключах
            if (!$data) {
                $protocolKey = "protocol_data:{$meroId}:{$groupKey}";
                $redisData = $redis->get($protocolKey);
                
                if ($redisData) {
                    $data = json_decode($redisData, true);
                    $drawConducted = true;
                }
            }
        }
    } catch (Exception $e) {
        error_log("ОШИБКА Redis в get_protocol_data.php: " . $e->getMessage());
    }
    
    // Если данные не найдены в Redis, пытаемся получить из файла
    if (!$data) {
        $data = loadProtocolDataFromFile($class, $sex, $distance, $ageGroup);
        if ($data) {
            $drawConducted = true;
        }
    }
    
    if ($data) {
        // Если жеребьевка была проведена, возвращаем данные участников
        if (isset($data['participants'])) {
            echo json_encode([
                'success' => true,
                'protocol' => [
                    'participants' => $data['participants'],
                    'drawConducted' => true
                ],
                'filename' => createProtocolFilename($class, $sex, $distance, $ageGroup)
            ]);
        } else {
            // Если данные в старом формате
            $protocolKey = $protocolType . 'Protocol';
            if (isset($data[$protocolKey])) {
                echo json_encode([
                    'success' => true,
                    'protocol' => $data[$protocolKey],
                    'filename' => createProtocolFilename($class, $sex, $distance, $ageGroup)
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'protocol' => $data,
                    'filename' => createProtocolFilename($class, $sex, $distance, $ageGroup)
                ]);
            }
        }
    } else {
        // Жеребьевка не проводилась, возвращаем пустой протокол
        echo json_encode([
            'success' => true,
            'protocol' => [
                'participants' => [],
                'drawConducted' => false
            ],
            'filename' => createProtocolFilename($class, $sex, $distance, $ageGroup)
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
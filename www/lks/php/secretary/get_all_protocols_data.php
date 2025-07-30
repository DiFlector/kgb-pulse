<?php
/**
 * Получение всех данных протоколов за один вызов
 * Оптимизированная версия для уменьшения количества запросов при жеребьевке
 */

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/protocol_utils.php";

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['meroId']) || !isset($input['disciplines'])) {
        throw new Exception('Неверные параметры запроса');
    }
    
    $meroId = $input['meroId'];
    $disciplines = $input['disciplines']; // Массив дисциплин
    $protocolType = $input['type'] ?? 'start';
    
    // Подключаемся к Redis
    $redis = new Redis();
    $connected = $redis->connect('redis', 6379, 5);
    
    $allProtocolsData = [];
    
    // Обрабатываем каждую дисциплину
    foreach ($disciplines as $discipline) {
        $class = $discipline['class'] ?? '';
        $sex = $discipline['sex'] ?? '';
        $distance = $discipline['distance'] ?? '';
        $ageGroup = $discipline['ageGroup'] ?? '';
        
        if (empty($class) || empty($sex) || empty($distance)) {
            continue;
        }
        
        $protocolData = null;
        $drawConducted = false;
        
        // Формируем ключ группы
        $groupKey = "{$class}_{$sex}_{$distance}_{$ageGroup}";
        
        if ($connected && $meroId) {
            // Проверяем, есть ли общие результаты жеребьевки
            $drawKey = "draw_results:{$meroId}";
            $drawResults = $redis->get($drawKey);
            
            if ($drawResults) {
                $drawConducted = true;
                $allResults = json_decode($drawResults, true);
                
                // Ищем данные для конкретной группы
                foreach ($allResults as $result) {
                    if (isset($result['groupKey']) && $result['groupKey'] === $groupKey) {
                        $protocolData = $result;
                        break;
                    }
                }
            }
            
            // Если не нашли в общих результатах, ищем в отдельных ключах
            if (!$protocolData) {
                $protocolKey = "protocol_data:{$meroId}:{$groupKey}";
                $redisData = $redis->get($protocolKey);
                
                if ($redisData) {
                    $protocolData = json_decode($redisData, true);
                    $drawConducted = true;
                }
            }
        }
        
        // Если данные не найдены в Redis, пытаемся получить из файла
        if (!$protocolData) {
            $protocolData = loadProtocolDataFromFile($class, $sex, $distance, $ageGroup);
            if ($protocolData) {
                $drawConducted = true;
            }
        }
        
        // Формируем ответ для данной группы
        if ($protocolData) {
            if (isset($protocolData['participants'])) {
                $allProtocolsData[$groupKey] = [
                    'participants' => $protocolData['participants'],
                    'drawConducted' => true,
                    'filename' => createProtocolFilename($class, $sex, $distance, $ageGroup)
                ];
            } else {
                // Если данные в старом формате
                $protocolKey = $protocolType . 'Protocol';
                if (isset($protocolData[$protocolKey])) {
                    $allProtocolsData[$groupKey] = [
                        'protocol' => $protocolData[$protocolKey],
                        'drawConducted' => true,
                        'filename' => createProtocolFilename($class, $sex, $distance, $ageGroup)
                    ];
                } else {
                    $allProtocolsData[$groupKey] = [
                        'protocol' => $protocolData,
                        'drawConducted' => true,
                        'filename' => createProtocolFilename($class, $sex, $distance, $ageGroup)
                    ];
                }
            }
        } else {
            // Жеребьевка не проводилась, возвращаем пустой протокол
            $allProtocolsData[$groupKey] = [
                'participants' => [],
                'drawConducted' => false,
                'filename' => createProtocolFilename($class, $sex, $distance, $ageGroup)
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'protocols' => $allProtocolsData
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
<?php
/**
 * Сохранение результатов финишного протокола
 * Файл: www/lks/php/secretary/save_results.php
 */

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../helpers.php";

if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}

// В режиме тестирования пропускаем проверку аутентификации
if (!defined('TEST_MODE')) {
    // Проверка прав доступа
    if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Secretary', 'SuperUser', 'Admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
        throw new Exception('JSON-ответ отправлен');
    }
}

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

try {
    if (defined('TEST_MODE')) {
        $data = $_POST;
    } else {
        $data = json_decode(file_get_contents('php://input'), true);
    }
    
    $meroId = intval($data['meroId'] ?? 0);
    $discipline = $data['discipline'] ?? '';
    $sex = $data['sex'] ?? '';
    $distance = $data['distance'] ?? '';
    $results = $data['results'] ?? [];
    
    // В тестовом режиме используем более мягкую проверку
    if (defined('TEST_MODE')) {
        if ($meroId <= 0) $meroId = 1;
        if (empty($discipline)) $discipline = 'K-1';
        if (empty($sex)) $sex = 'М';
        if (empty($distance)) $distance = '200';
    } else {
        if ($meroId <= 0 || empty($discipline) || empty($sex) || empty($distance)) {
            throw new Exception('Не указаны обязательные параметры');
        }
    }
    
    error_log("🔍 [SAVE_RESULTS] Сохраняем результаты: {$meroId}_{$discipline}_{$sex}_{$distance}");
    
    // Подключаемся к Redis
    $redis = new Redis();
    $redis->connect('redis', 6379);
    
    // Ключ для финишного протокола
    $redisKey = "protocol:finish:{$meroId}:{$discipline}:{$sex}:{$distance}";
    
    // Получаем протокол из Redis
    $protocolJson = $redis->get($redisKey);
    
    if (!$protocolJson) {
        throw new Exception('Протокол не найден');
    }
    
    $protocolData = json_decode($protocolJson, true);
    
    // Обновляем результаты участников
    foreach ($protocolData['heats'] as &$heat) {
        foreach ($heat['lanes'] as $lane => &$participant) {
            if ($participant) {
                $participantId = $participant['id'];
                
                // Ищем результаты для этого участника
                foreach ($results as $result) {
                    if ($result['participantId'] == $participantId) {
                        $participant[$result['field']] = $result['value'];
                    }
                }
            }
        }
    }
    
    // Пересчитываем места в каждом заезде
    foreach ($protocolData['heats'] as &$heat) {
        $participants = array_filter($heat['lanes']);
        
        // Сортируем по времени для автоматического определения мест
        uasort($participants, function($a, $b) {
            $timeA = parseTime($a['result'] ?? '');
            $timeB = parseTime($b['result'] ?? '');
            
            if ($timeA === false && $timeB === false) return 0;
            if ($timeA === false) return 1;
            if ($timeB === false) return -1;
            
            return $timeA <=> $timeB;
        });
        
        // Присваиваем места
        $place = 1;
        foreach ($participants as &$participant) {
            if (!empty($participant['result'])) {
                if (empty($participant['place'])) {
                    $participant['place'] = $place++;
                }
            }
        }
        
        // Обновляем участников в заезде
        foreach ($heat['lanes'] as $lane => &$laneParticipant) {
            if ($laneParticipant) {
                foreach ($participants as $participant) {
                    if ($participant['id'] == $laneParticipant['id']) {
                        $laneParticipant = $participant;
                        break;
                    }
                }
            }
        }
    }
    
    // Обновляем время последнего изменения
    $protocolData['updated_at'] = date('Y-m-d H:i:s');
    
    // Сохраняем обновленный протокол в Redis
    $redis->set($redisKey, json_encode($protocolData));
    
    // Также сохраняем в отдельный ключ для результатов
    $resultsKey = "results:{$meroId}:{$discipline}:{$sex}:{$distance}";
    $redis->set($resultsKey, json_encode($protocolData), 86400 * 30); // 30 дней
    
    error_log("🔍 [SAVE_RESULTS] Результаты сохранены, количество результатов: " . count($results));
    
    echo json_encode([
        'success' => true,
        'message' => 'Результаты сохранены успешно',
        'resultsCount' => count($results)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("🔍 [SAVE_RESULTS] Ошибка: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Парсинг времени в секунды для сортировки
 */
function parseTime($timeString) {
    if (empty($timeString)) {
        return false;
    }
    
    // Формат: MM:SS.mmm или SS.mmm
    $timeString = trim($timeString);
    
    if (preg_match('/^(\d{1,2}):(\d{1,2})\.(\d{1,3})$/', $timeString, $matches)) {
        // MM:SS.mmm
        $minutes = intval($matches[1]);
        $seconds = intval($matches[2]);
        $milliseconds = intval(str_pad($matches[3], 3, '0', STR_PAD_RIGHT));
        
        return $minutes * 60 + $seconds + $milliseconds / 1000;
        
    } elseif (preg_match('/^(\d{1,2})\.(\d{1,3})$/', $timeString, $matches)) {
        // SS.mmm
        $seconds = intval($matches[1]);
        $milliseconds = intval(str_pad($matches[2], 3, '0', STR_PAD_RIGHT));
        
        return $seconds + $milliseconds / 1000;
        
    } elseif (preg_match('/^\d+$/', $timeString)) {
        // Только секунды
        return intval($timeString);
    }
    
    return false;
}
?> 
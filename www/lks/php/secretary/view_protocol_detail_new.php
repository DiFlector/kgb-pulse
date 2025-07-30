<?php
/**
 * Просмотр деталей протокола - новая версия без Redis
 * Файл: www/lks/php/secretary/view_protocol_detail_new.php
 */

require_once __DIR__ . "/protocol_manager.php";

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
    error_log("🔍 [VIEW_PROTOCOL_DETAIL_NEW] Запрос на просмотр деталей протокола");
    
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
    if (!isset($data['meroId']) || !isset($data['class']) || !isset($data['sex']) || !isset($data['distance']) || !isset($data['type'])) {
        throw new Exception('Отсутствуют обязательные поля: meroId, class, sex, distance, type');
    }
    
    $meroId = (int)$data['meroId'];
    $class = $data['class'];
    $sex = $data['sex'];
    $distance = $data['distance'];
    $type = $data['type'];
    $ageGroup = $data['ageGroup'] ?? null;
    
    error_log("🔍 [VIEW_PROTOCOL_DETAIL_NEW] Загрузка протокола: $class $sex $distance $type" . ($ageGroup ? " ($ageGroup)" : ""));
    
    // Создаем менеджер протоколов
    $protocolManager = new ProtocolManager();
    
    // Загружаем протокол
    $protocol = $protocolManager->loadProtocol($meroId, $class, $sex, $distance, $type, $ageGroup);
    
    if (!$protocol) {
        throw new Exception('Протокол не найден. Создайте протоколы заново.');
    }
    
    // Преобразуем данные протокола в формат для отображения
    $formattedProtocol = formatProtocolForDisplay($protocol, $type);
    
    $result = [
        'success' => true,
        'protocol' => $formattedProtocol,
        'originalProtocol' => $protocol // Для отладки
    ];
    
    error_log("🔍 [VIEW_PROTOCOL_DETAIL_NEW] Протокол загружен: " . count($formattedProtocol['participants'] ?? []) . " участников");
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("❌ [VIEW_PROTOCOL_DETAIL_NEW] Ошибка: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Форматирование протокола для отображения
 */
function formatProtocolForDisplay($protocol, $type) {
    $participants = [];
    
    // Обрабатываем заезды и извлекаем участников
    if (isset($protocol['heats']) && is_array($protocol['heats'])) {
        foreach ($protocol['heats'] as $heat) {
            if (isset($heat['lanes']) && is_array($heat['lanes'])) {
                foreach ($heat['lanes'] as $lane => $participant) {
                    if ($participant) {
                        // Добавляем информацию о заезде
                        $participant['heatType'] = $heat['heatType'] ?? '';
                        $participant['heatNumber'] = $heat['heatNumber'] ?? 1;
                        
                        // Рассчитываем возрастную группу для отображения
                        if (isset($participant['birthdata'])) {
                            $age = AgeGroupCalculator::calculateAgeOnDecember31($participant['birthdata']);
                            $participant['ageGroup'] = calculateDisplayAgeGroup($age, $protocol['sex']);
                        }
                        
                        $participants[] = $participant;
                    }
                }
            }
        }
    }
    
    // Сортируем участников по дорожкам
    usort($participants, function($a, $b) {
        return ($a['lane'] ?? 0) <=> ($b['lane'] ?? 0);
    });
    
    return [
        'meroId' => $protocol['meroId'],
        'class' => $protocol['class'],
        'sex' => $protocol['sex'],
        'distance' => $protocol['distance'],
        'ageGroup' => $protocol['ageGroup'],
        'type' => $protocol['type'],
        'participants' => $participants,
        'totalParticipants' => count($participants),
        'maxLanes' => $protocol['maxLanes'] ?? 9,
        'created_at' => $protocol['created_at']
    ];
}

/**
 * Расчет возрастной группы для отображения
 */
function calculateDisplayAgeGroup($age, $sex) {
    $genderPrefix = $sex === 'М' ? 'Мужчины' : 'Женщины';
    
    if ($age <= 12) {
        return "$genderPrefix (младшие)";
    } elseif ($age <= 15) {
        return "$genderPrefix (средние)";
    } elseif ($age <= 18) {
        return "$genderPrefix (старшие)";
    } elseif ($age <= 23) {
        return "$genderPrefix (юниоры)";
    } elseif ($age <= 39) {
        return "$genderPrefix (взрослые)";
    } else {
        return "$genderPrefix (ветераны)";
    }
}
?> 
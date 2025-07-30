<?php
/**
 * Получение дисциплин мероприятия с возрастными группами
 * Файл: www/lks/php/secretary/get_event_disciplines.php
 */

require_once __DIR__ . "/../db/Database.php";

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
    error_log("📋 [GET_EVENT_DISCIPLINES] Запрос на получение дисциплин мероприятия");
    
    // Получаем параметры из GET запроса
    $meroId = isset($_GET['meroId']) ? (int)$_GET['meroId'] : 0;
    
    if ($meroId <= 0) {
        throw new Exception('Не указан ID мероприятия');
    }
    
    // Создаем подключение к БД
    $db = Database::getInstance();
    
    // Получаем информацию о мероприятии
    $stmt = $db->prepare("SELECT class_distance FROM meros WHERE champn = ?");
    $stmt->execute([$meroId]);
    $classDistance = json_decode($stmt->fetchColumn(), true);
    
    if (!$classDistance) {
        throw new Exception('Некорректная структура дисциплин в мероприятии');
    }
    
    $disciplines = [];
    
    // Парсим дисциплины из class_distance
    foreach ($classDistance as $class => $data) {
        if (isset($data['sex']) && isset($data['dist']) && isset($data['age_group'])) {
            $sexes = is_array($data['sex']) ? $data['sex'] : [$data['sex']];
            $distances = is_array($data['dist']) ? $data['dist'] : [$data['dist']];
            $ageGroups = is_array($data['age_group']) ? $data['age_group'] : [$data['age_group']];
            
            foreach ($distances as $distanceStr) {
                $individualDistances = explode(',', $distanceStr);
                
                foreach ($individualDistances as $distance) {
                    $cleanDistance = trim($distance);
                    
                    if (empty($cleanDistance) || !is_numeric($cleanDistance)) {
                        continue;
                    }
                    
                    foreach ($sexes as $sexIndex => $sex) {
                        $cleanSex = trim($sex);
                        
                        // Получаем возрастные группы для данного пола
                        $ageGroupString = isset($ageGroups[$sexIndex]) ? $ageGroups[$sexIndex] : '';
                        $ageGroupsList = parseAgeGroups($ageGroupString);
                        
                        $disciplines[] = [
                            'class' => $class,
                            'sex' => $cleanSex,
                            'distance' => $cleanDistance,
                            'ageGroups' => $ageGroupsList
                        ];
                    }
                }
            }
        }
    }
    
    // Удаляем дубликаты
    $uniqueDisciplines = [];
    $seen = [];
    
    foreach ($disciplines as $discipline) {
        $key = $discipline['class'] . '_' . $discipline['sex'] . '_' . $discipline['distance'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $uniqueDisciplines[] = $discipline;
        }
    }
    
    error_log("✅ [GET_EVENT_DISCIPLINES] Получено дисциплин: " . count($uniqueDisciplines));
    
    $result = [
        'success' => true,
        'disciplines' => $uniqueDisciplines,
        'count' => count($uniqueDisciplines)
    ];
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("❌ [GET_EVENT_DISCIPLINES] Ошибка: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Разбор строки возрастных групп
 */
function parseAgeGroups($ageGroupString) {
    if (empty($ageGroupString)) {
        return [];
    }
    
    $groups = [];
    
    // Разбиваем по ", "
    $groupStrings = explode(', ', $ageGroupString);
    
    foreach ($groupStrings as $groupString) {
        // Разбиваем по ": "
        $parts = explode(': ', $groupString);
        if (count($parts) === 2) {
            $groupName = trim($parts[0]);
            $range = trim($parts[1]);
            
            // Разбиваем диапазон по "-"
            $rangeParts = explode('-', $range);
            if (count($rangeParts) === 2) {
                $minAge = (int)trim($rangeParts[0]);
                $maxAge = (int)trim($rangeParts[1]);
                
                $groups[] = [
                    'name' => $groupName,
                    'min_age' => $minAge,
                    'max_age' => $maxAge,
                    'full_name' => $groupString
                ];
            }
        }
    }
    
    return $groups;
}
?> 
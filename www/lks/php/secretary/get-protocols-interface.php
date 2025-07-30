<?php
/**
 * API для получения интерфейса протоколов
 * Только для секретарей
 */

// Заголовки JSON и CORS
if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Origin: *');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Methods: POST, OPTIONS');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

// Проверка авторизации
if (!isset($_SESSION)) {
    session_start();
}

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/Auth.php";

$auth = new Auth();

// В режиме тестирования пропускаем проверку аутентификации
if (!defined('TEST_MODE')) {
    // Проверка авторизации и прав секретаря
    if (!$auth->isAuthenticated() || !$auth->hasAnyRole(['Admin', 'Secretary', 'SuperUser'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Доступ запрещен'
        ]);
        exit;
    }
}

// Проверка метода запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Метод не поддерживается'
    ]);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    
    // Чтение JSON данных
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['event_id'])) {
        throw new Exception('Не указан ID мероприятия');
    }
    
    $eventId = $input['event_id'];
    
    // Получаем данные мероприятия
    $sql = "SELECT * FROM meros WHERE champn = :champn";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':champn', $eventId);
    $stmt->execute();
    
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$event) {
        throw new Exception('Мероприятие не найдено');
    }
    
    // Парсим классы и дистанции
    $classDistance = json_decode($event['class_distance'], true);
    
    if (!$classDistance) {
        throw new Exception('Некорректные данные мероприятия');
    }
    
    // Формируем список протоколов
    $protocols = [];
    $protocolId = 1;
    $uniqueDisciplines = []; // Для дедупликации
    
    foreach ($classDistance as $boatClass => $data) {
        if (!isset($data['sex']) || !isset($data['dist']) || !isset($data['age_group'])) {
            continue;
        }
        
        $sexOptions = is_array($data['sex']) ? $data['sex'] : [$data['sex']];
        $distances = is_array($data['dist']) ? $data['dist'] : [$data['dist']];
        $ageGroups = is_array($data['age_group']) ? $data['age_group'] : [$data['age_group']];
        
        // ИСПРАВЛЕНО: Обрабатываем каждую дистанцию только один раз
        foreach ($distances as $distanceStr) {
            // Разбиваем строку дистанций на отдельные значения
            $individualDistances = explode(',', $distanceStr);
            
            foreach ($individualDistances as $distance) {
                $cleanDistance = trim($distance);
                
                if (empty($cleanDistance) || !is_numeric($cleanDistance)) {
                    continue; // Пропускаем пустые и нечисловые значения
                }
                
                foreach ($sexOptions as $sex) {
                    $cleanSex = trim($sex);
                    
                    foreach ($ageGroups as $ageGroup) {
                        $cleanAgeGroup = trim($ageGroup);
                        
                        // Создаем уникальный ключ для дедупликации
                        $uniqueKey = $boatClass . '_' . $cleanSex . '_' . $cleanDistance . '_' . $cleanAgeGroup;
                        
                        // Проверяем, не создали ли мы уже такую дисциплину
                        if (!isset($uniqueDisciplines[$uniqueKey])) {
                            $uniqueDisciplines[$uniqueKey] = true;
                            
                            // Получаем количество участников для этой дисциплины
                            $sql = "
                                SELECT COUNT(*) as participants_count
                                FROM listreg lr
                                JOIN users u ON lr.users_oid = u.oid
                                JOIN meros m ON lr.meros_oid = m.oid
                                WHERE m.champn = :champn 
                                AND lr.discipline::text LIKE :class_distance
                                AND u.sex = :sex
                                AND lr.status NOT IN ('Дисквалифицирован', 'Неявка')
                            ";
                            
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute([
                                ':champn' => $eventId,
                                ':class_distance' => '%' . $boatClass . '%',
                                ':sex' => $cleanSex
                            ]);
                            
                            $participantsCount = $stmt->fetch(PDO::FETCH_ASSOC)['participants_count'];
                            
                            // Стартовый протокол
                            $protocols[] = [
                                'id' => $protocolId++,
                                'type' => 'start',
                                'boat_class' => $boatClass,
                                'sex' => $cleanSex,
                                'distance' => $cleanDistance,
                                'age_group' => $cleanAgeGroup,
                                'participants_count' => $participantsCount,
                                'status' => 'not_created',
                                'title' => "{$boatClass} {$cleanSex}, {$cleanAgeGroup} {$cleanDistance}м"
                            ];
                            
                            // Финишный протокол
                            $protocols[] = [
                                'id' => $protocolId++,
                                'type' => 'finish',
                                'boat_class' => $boatClass,
                                'sex' => $cleanSex,
                                'distance' => $cleanDistance,
                                'age_group' => $cleanAgeGroup,
                                'participants_count' => $participantsCount,
                                'status' => 'not_created',
                                'title' => "{$boatClass} {$cleanSex}, {$cleanAgeGroup} {$cleanDistance}м"
                            ];
                        }
                    }
                }
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'event' => [
            'id' => $event['champn'],
            'name' => $event['meroname'],
            'status' => $event['status']
        ],
        'protocols' => $protocols
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка получения интерфейса протоколов: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
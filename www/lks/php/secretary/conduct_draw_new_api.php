<?php
session_start();
require_once __DIR__ . '/../common/Auth.php';
require_once __DIR__ . '/../db/Database.php';
require_once __DIR__ . '/SecretaryEventManager.php';

// Проверка авторизации и прав доступа
$auth = new Auth();
if (!$auth->isAuthenticated() || !$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPDO();

// Подключение к Redis
try {
    $redis = new Redis();
    $redis->connect('redis', 6379);
} catch (Exception $e) {
    error_log("Ошибка подключения к Redis: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка подключения к базе данных']);
    exit;
}

// Получение данных из POST запроса
$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_disciplines':
            handleGetDisciplines($pdo, $redis, $input);
            break;
            
        case 'conduct_draw':
            handleConductDraw($pdo, $redis, $input);
            break;
            
        case 'create_protocols':
            handleCreateProtocols($pdo, $redis, $input);
            break;
            
        case 'get_protocols':
            handleGetProtocols($pdo, $redis, $input);
            break;
            
        case 'save_results':
            handleSaveResults($pdo, $redis, $input);
            break;
            
        case 'get_final_results':
            handleGetFinalResults($pdo, $redis, $input);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Неизвестное действие']);
            break;
    }
} catch (Exception $e) {
    error_log("Ошибка API секретаря: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера: ' . $e->getMessage()]);
}

/**
 * Получение доступных дисциплин для жеребьевки
 */
function handleGetDisciplines($pdo, $redis, $input) {
    $meroId = $input['mero_id'] ?? null;
    
    if (!$meroId) {
        echo json_encode(['success' => false, 'message' => 'Не указан ID мероприятия']);
        return;
    }
    
    try {
        $manager = new SecretaryEventManager($pdo, $redis, $meroId);
        $disciplines = $manager->getAvailableDisciplines();
        
        echo json_encode([
            'success' => true,
            'disciplines' => $disciplines,
            'total_disciplines' => count($disciplines)
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

/**
 * Проведение жеребьевки
 */
function handleConductDraw($pdo, $redis, $input) {
    $meroId = $input['mero_id'] ?? null;
    $selectedDisciplines = $input['disciplines'] ?? [];
    
    if (!$meroId || empty($selectedDisciplines)) {
        echo json_encode(['success' => false, 'message' => 'Не указан ID мероприятия или дисциплины']);
        return;
    }
    
    try {
        $manager = new SecretaryEventManager($pdo, $redis, $meroId);
        $results = $manager->conductDraw($selectedDisciplines);
        
        echo json_encode([
            'success' => true,
            'message' => 'Жеребьевка проведена успешно',
            'results' => $results,
            'total_results' => count($results)
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

/**
 * Создание протоколов
 */
function handleCreateProtocols($pdo, $redis, $input) {
    $meroId = $input['mero_id'] ?? null;
    
    if (!$meroId) {
        echo json_encode(['success' => false, 'message' => 'Не указан ID мероприятия']);
        return;
    }
    
    try {
        $manager = new SecretaryEventManager($pdo, $redis, $meroId);
        $drawResults = $manager->getDrawResults();
        
        if (empty($drawResults)) {
            echo json_encode(['success' => false, 'message' => 'Нет результатов жеребьевки']);
            return;
        }
        
        $protocols = $manager->createProtocols($drawResults);
        
        echo json_encode([
            'success' => true,
            'message' => 'Протоколы созданы успешно',
            'protocols' => $protocols,
            'total_protocols' => count($protocols)
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

/**
 * Получение протоколов
 */
function handleGetProtocols($pdo, $redis, $input) {
    $meroId = $input['mero_id'] ?? null;
    
    if (!$meroId) {
        echo json_encode(['success' => false, 'message' => 'Не указан ID мероприятия']);
        return;
    }
    
    try {
        $manager = new SecretaryEventManager($pdo, $redis, $meroId);
        $protocols = $manager->getProtocols();
        
        echo json_encode([
            'success' => true,
            'protocols' => $protocols,
            'total_protocols' => count($protocols)
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

/**
 * Сохранение результатов финиша
 */
function handleSaveResults($pdo, $redis, $input) {
    $meroId = $input['mero_id'] ?? null;
    $disciplineKey = $input['discipline_key'] ?? null;
    $ageGroup = $input['age_group'] ?? null;
    $heatNumber = $input['heat_number'] ?? null;
    $results = $input['results'] ?? [];
    
    if (!$meroId || !$disciplineKey || !$ageGroup || !$heatNumber || empty($results)) {
        echo json_encode(['success' => false, 'message' => 'Не все параметры указаны']);
        return;
    }
    
    try {
        $manager = new SecretaryEventManager($pdo, $redis, $meroId);
        $success = $manager->saveFinishResults($disciplineKey, $ageGroup, $heatNumber, $results);
        
        if ($success) {
            echo json_encode([
                'success' => true,
                'message' => 'Результаты сохранены успешно'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Ошибка сохранения результатов']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

/**
 * Получение итоговых результатов
 */
function handleGetFinalResults($pdo, $redis, $input) {
    $meroId = $input['mero_id'] ?? null;
    
    if (!$meroId) {
        echo json_encode(['success' => false, 'message' => 'Не указан ID мероприятия']);
        return;
    }
    
    try {
        $manager = new SecretaryEventManager($pdo, $redis, $meroId);
        $results = $manager->getFinalResults();
        
        echo json_encode([
            'success' => true,
            'results' => $results,
            'total_results' => count($results)
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
?> 
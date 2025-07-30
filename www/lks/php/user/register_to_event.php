<?php
/**
 * API для регистрации на мероприятие
 * Обрабатывает POST запросы для создания новых регистраций
 */

// Включаем отображение ошибок для отладки
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!headers_sent()) {
    session_start();
} else {
    @session_start();
}
require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/EventRegistration.php";

if (!headers_sent()) {
    if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');
}

// Логируем начало выполнения
error_log("register_to_event.php: Начало выполнения");

try {
    // Проверка авторизации или тестовый режим
    $isTestMode = defined('TEST_MODE') || isset($_GET['test']) || isset($_COOKIE['test_user']);
    error_log("register_to_event.php: isTestMode = " . ($isTestMode ? 'true' : 'false'));

    if (!isset($_SESSION['user_id']) && !$isTestMode) {
        http_response_code(401);
        echo json_encode(['error' => 'Необходима авторизация']);
        throw new Exception('JSON-ответ отправлен: Необходима авторизация');
    }

    // Получаем данные пользователя
    if ($isTestMode) {
        // Тестовый режим
        $userId = isset($_COOKIE['test_user']) ? intval($_COOKIE['test_user']) : 1102;
        $userRole = 'Sportsman';
        
        // Проверяем существование пользователя в БД
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT accessrights FROM users WHERE userid = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $userRole = $user['accessrights'];
        }
    } else {
        // Обычный режим
        $userId = $_SESSION['user_id'];
        $userRole = $_SESSION['user_role'];
    }
    
    error_log("register_to_event.php: userId = {$userId}, userRole = {$userRole}");

    // Проверяем метод запроса
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Метод не поддерживается']);
        throw new Exception('JSON-ответ отправлен: Метод не поддерживается');
    }

    // Получаем данные из POST запроса
    $input = json_decode(file_get_contents('php://input'), true);
    error_log("register_to_event.php: Получены данные: " . json_encode($input));
    
    if (!$input) {
        throw new Exception('Некорректные данные запроса');
    }
    
    // Валидация обязательных полей
    $required = ['event_id', 'class', 'sex', 'distance'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("Поле '{$field}' обязательно для заполнения");
        }
    }
    
    $eventId = $input['event_id'];
    $class = $input['class'];
    $sex = $input['sex'];
    $distance = $input['distance'];
    
    error_log("register_to_event.php: Параметры - eventId={$eventId}, class={$class}, sex={$sex}, distance={$distance}");
    
    // Создаем экземпляр класса регистрации
    error_log("register_to_event.php: Создаем EventRegistration...");
    $eventRegistration = new EventRegistration($userId, $userRole);
    error_log("register_to_event.php: EventRegistration создан");
    
    // Проверяем доступность мероприятия
    $eventInfo = $eventRegistration->getEventInfo($eventId);
    if (!$eventInfo) {
        throw new Exception('Мероприятие не найдено');
    }
    
    if ($eventInfo['status'] !== 'Регистрация') {
        throw new Exception('Регистрация на мероприятие закрыта');
    }
    
    // Определяем тип лодки
    $boatType = $eventRegistration->getBoatType($class);
    $maxParticipants = $eventRegistration->getMaxParticipants($class);
    
    // Проверяем доступность классов и дистанций
    $availableClasses = $eventRegistration->getAvailableClasses($eventId);
    $distances = explode(', ', $distance); // Разделяем дистанции если их несколько
    
    foreach ($distances as $singleDistance) {
        $classFound = false;
        $distanceFound = false;
        
        foreach ($availableClasses as $availableClass) {
            if ($availableClass['class'] === $class) {
                $classFound = true;
                if (in_array(trim($singleDistance), $availableClass['distances'])) {
                    $distanceFound = true;
                }
                break;
            }
        }
        
        if (!$classFound) {
            throw new Exception('Выбранный класс лодки недоступен для этого мероприятия');
        }
        
        if (!$distanceFound) {
            throw new Exception("Дистанция {$singleDistance}м недоступна для класса {$class}");
        }
    }
    
    // Проверяем доступность пола
    $availableSexes = $eventRegistration->getAvailableSexes($eventId, $class);
    if (!in_array($sex, $availableSexes)) {
        throw new Exception('Выбранный пол недоступен для этого класса');
    }
    
    // Обрабатываем разные режимы регистрации
    if (isset($input['participant_data'])) {
        // Новый режим: регистрация с данными участника (для командных регистраций)
        $result = handleTeamParticipantRegistration($eventRegistration, $eventId, $input, $eventInfo);
    } else {
        // Старый режим: одиночная регистрация или регистрация команды целиком
        $result = handleStandardRegistration($eventRegistration, $eventId, $input, $eventInfo, $boatType, $maxParticipants, $userId);
    }
    
    error_log("register_to_event.php: Результат регистрации: " . json_encode($result));
    
    if ($result['success']) {
        http_response_code(201);
        echo json_encode($result);
    } else {
        http_response_code(400);
        echo json_encode($result);
    }
    
} catch (Exception $e) {
    error_log("register_to_event.php: Исключение: " . $e->getMessage());
    error_log("register_to_event.php: Стек: " . $e->getTraceAsString());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} catch (Error $e) {
    error_log("register_to_event.php: FATAL ERROR: " . $e->getMessage());
    error_log("register_to_event.php: Стек: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Внутренняя ошибка сервера: ' . $e->getMessage()
    ]);
}

/**
 * Обработка регистрации участника команды с его данными
 */
function handleTeamParticipantRegistration($eventRegistration, $eventId, $input, $eventInfo) {
    $participantData = $input['participant_data'];
    $teamMode = $input['team_mode'] ?? 'single_team';
    
    // Формируем данные для регистрации
    $registrationData = [
        'class' => $input['class'],
        'sex' => $input['sex'],
        'distance' => $input['distance'], // Может содержать несколько дистанций через запятую
        'cost' => $eventInfo['defcost'] ?? '0',
        'fio' => $participantData['fio'],
        'email' => $participantData['email'] ?? '',
        'phone' => $participantData['phone'] ?? '',
        'sport_number' => $participantData['sport_number'] ?? '',
        'team_role' => $participantData['team_role'] ?? 'участник',
        'team_name' => $input['team_name'] ?? '',
        'team_city' => $input['team_city'] ?? ''
    ];
    
    error_log("register_to_event.php: Регистрируем участника команды: " . json_encode($registrationData));
    
    // Регистрируем участника
    return $eventRegistration->registerTeamParticipant($eventId, $registrationData, $teamMode);
}

/**
 * Обработка стандартной регистрации (старый формат)
 */
function handleStandardRegistration($eventRegistration, $eventId, $input, $eventInfo, $boatType, $maxParticipants, $userId) {
    // Формируем данные для регистрации
    $participantData = [
        'class' => $input['class'],
        'sex' => $input['sex'],
        'distance' => $input['distance'],
        'cost' => $eventInfo['defcost'] ?? '0'
    ];
    
    // Обрабатываем основного участника
    if (isset($input['participant_id']) && $input['participant_id'] != $userId) {
        // Регистрация другого пользователя (для организаторов/секретарей)
        if (!$eventRegistration->canRegisterOthers()) {
            throw new Exception('У вас нет прав на регистрацию других участников');
        }
        $participantData['userid'] = $input['participant_id'];
    } else {
        // Самостоятельная регистрация или регистрация себя в команде
        $participantData['userid'] = $userId;
    }
    
    // Добавляем роль основного участника если передана
    if (isset($input['team_role'])) {
        $participantData['team_role'] = $input['team_role'];
    }
    
    // Обрабатываем командные лодки
    if ($boatType === 'team') {
        // Название и город команды
        $participantData['team_name'] = $input['team_name'] ?? "Команда #" . time();
        $participantData['team_city'] = $input['team_city'] ?? '';
        
        // Участники команды
        if (isset($input['team_members']) && is_array($input['team_members'])) {
            $teamMembers = [];
            
            foreach ($input['team_members'] as $member) {
                if (!empty($member['userid'])) {
                    $teamMemberData = [
                        'userid' => $member['userid']
                    ];
                    
                    // Добавляем роль участника команды если передана
                    if (isset($member['team_role'])) {
                        $teamMemberData['team_role'] = $member['team_role'];
                    }
                    
                    $teamMembers[] = $teamMemberData;
                }
            }
            
            // Проверяем количество участников
            $totalParticipants = 1 + count($teamMembers); // Основной участник + члены команды
            if ($totalParticipants > $maxParticipants) {
                throw new Exception("Превышено максимальное количество участников для класса {$input['class']} (максимум: {$maxParticipants})");
            }
            
            if ($totalParticipants < $maxParticipants) {
                throw new Exception("Недостаточно участников для класса {$input['class']} (требуется: {$maxParticipants})");
            }
            
            $participantData['team_members'] = $teamMembers;
        } else {
            throw new Exception("Для командных лодок необходимо указать всех участников команды");
        }
    }
    
    // Выполняем регистрацию
    error_log("register_to_event.php: Выполняем стандартную регистрацию...");
    return $eventRegistration->registerParticipant($eventId, $participantData);
}
?> 
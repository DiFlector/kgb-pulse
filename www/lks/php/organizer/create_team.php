<?php
/**
 * API для создания новой команды
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/../db/Database.php";
// Требуется для getBoatCapacity() и сопутствующих хелперов до первого вызова ниже
require_once __DIR__ . "/../helpers.php";

$auth = new Auth();
if (!$auth->isAuthenticated() || !in_array($auth->getUserRole(), ['Organizer', 'SuperUser', 'Admin', 'Secretary'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

try {
    // Поддержка тестового ввода без HTTP: если задан $GLOBALS['__TEST_INPUT__'], используем его
    $rawInput = file_get_contents('php://input');
    if (isset($GLOBALS['__TEST_INPUT__']) && is_string($GLOBALS['__TEST_INPUT__']) && $GLOBALS['__TEST_INPUT__'] !== '') {
        $rawInput = $GLOBALS['__TEST_INPUT__'];
    }
    $input = json_decode($rawInput, true);
    
    if (!$input) {
        throw new Exception('Неверный формат данных');
    }
    
    $eventId = $input['eventId'] ?? null;
    $classType = $input['classType'] ?? null;
    $teamName = $input['teamName'] ?? null;
    $teamCity = $input['teamCity'] ?? null;
    $participants = $input['participants'] ?? [];
    $distances = $input['distances'] ?? [];
    
    // На старте проверяем только обязательные всегда параметры
    if (!$eventId || !$classType || empty($participants)) {
        throw new Exception('Не указаны обязательные параметры');
    }
    
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    
    // Начинаем транзакцию
    $pdo->beginTransaction();
    
    try {
        // Получаем информацию о мероприятии
        $event = $db->fetchOne("
            SELECT oid, champn, meroname, class_distance, defcost
            FROM meros 
            WHERE oid = ?
        ", [$eventId]);
        
        if (!$event) {
            throw new Exception('Мероприятие не найдено');
        }
        
        // Проверяем, что класс существует в мероприятии
        $classDistance = json_decode($event['class_distance'], true);
        if (!isset($classDistance[$classType])) {
            throw new Exception('Указанный класс не поддерживается данным мероприятием');
        }
        
        // Для драконов название и город команды обязательны; для остальных — необязательно
        $isDragonClass = (strpos($classType, 'D-') === 0);
        if ($isDragonClass) {
            if (!$teamName || trim($teamName) === '') {
                throw new Exception('Для класса драконов необходимо указать название команды');
            }
            if (!$teamCity || trim($teamCity) === '') {
                throw new Exception('Для класса драконов необходимо указать город команды');
            }
        }

        // Определяем максимальное количество участников
        $maxParticipants = getBoatCapacity($classType);
        if (count($participants) > $maxParticipants) {
            throw new Exception("Превышено максимальное количество участников для класса {$classType}. Максимум: {$maxParticipants}");
        }
        
        // Генерируем последовательный teamid (MAX(teamid)+1)
        $teamId = generateSequentialTeamId($db);
        
        // Определяем persons_all (для D-10 учитываем расширенный состав)
        $personsAll = getPersonsAllForClass($classType, $maxParticipants);

        // Создаем запись команды
        $stmt = $pdo->prepare("
            INSERT INTO teams (teamid, teamname, teamcity, class, persons_amount, persons_all)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $teamId,
            $teamName,
            $teamCity,
            $classType,
            count($participants),
            $personsAll
        ]);
        
        // Надёжно получаем oid созданной команды по teamid (PostgreSQL)
        $createdTeam = $db->fetchOne("SELECT oid FROM teams WHERE teamid = ?", [$teamId]);
        if (!$createdTeam || empty($createdTeam['oid'])) {
            throw new Exception('Ошибка создания команды: не удалось получить oid');
        }
        $teamOid = (int)$createdTeam['oid'];
        
        // Создаем discipline для команды (все дистанции для выбранного класса)
        $discipline = [
            $classType => [
                'sex' => $classDistance[$classType]['sex'] ?? ['М', 'Ж'],
                'dist' => $distances
            ]
        ];
        
        // Создаем записи регистраций для каждого участника
        foreach ($participants as $participant) {
            $userId = $participant['userId'];
            $role = $participant['role'] ?? 'member';
            // Для драконов роли капитана нет — приводим к member
            if ($isDragonClass && $role === 'captain') {
                $role = 'member';
            }
            
            // Пропускаем, если уже есть запись для этой команды и мероприятия
            $exists = $db->fetchOne(
                "SELECT 1 FROM listreg WHERE users_oid = ? AND meros_oid = ? AND teams_oid = ? LIMIT 1",
                [$userId, $eventId, $teamOid]
            );
            if ($exists) {
                continue;
            }

            // Создаем новую регистрацию
            $stmt = $pdo->prepare("
                INSERT INTO listreg (users_oid, meros_oid, teams_oid, discipline, role, status, cost, oplata)
                VALUES (?, ?, ?, ?, ?, 'Ожидание команды', ?, false)
            ");
            $stmt->execute([
                $userId,
                $eventId,
                $teamOid,
                json_encode($discipline, JSON_UNESCAPED_UNICODE),
                $role,
                $event['defcost'] ?? 0
            ]);
        }
        
        // Подтверждаем транзакцию
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Команда успешно создана',
            'teamId' => $teamId,
            'teamOid' => $teamOid
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Ошибка создания команды: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// getBoatCapacity подключён выше из helpers.php

/**
 * persons_all = максимальное количество людей в команде из класса лодки
 * Для драконов учитываем расширенный состав (например, D-10 = 12 или 14 при учёте резерва).
 */
function getPersonsAllForClass(string $classType, int $baseCapacity): int {
    $normalized = strtoupper(trim($classType));
    if (strpos($normalized, 'D-') === 0) {
        // Базовые роли: 10 гребцов + рулевой + барабанщик = 12
        // Если в проекте принято учитывать резерв при persons_all, расширяем до 14
        // Используем 14 по принятой логике интерфейса секретаря/организатора
        return ($baseCapacity === 10) ? 14 : $baseCapacity;
    }
    return $baseCapacity;
}

/**
 * Генерация нового teamid как MAX(teamid)+1 (последовательно в рамках БД)
 */
function generateSequentialTeamId($db): int {
    $row = $db->fetchOne("SELECT COALESCE(MAX(teamid), 0) AS max_id FROM teams", []);
    $next = isset($row['max_id']) ? ((int)$row['max_id'] + 1) : 1;
    return $next > 0 ? $next : 1;
}

/**
 * Генерирует уникальный teamid
 */
function generateUniqueTeamId($db) {
    do {
        $teamId = rand(1000, 9999);
        $existing = $db->fetchOne("SELECT COUNT(*) as count FROM teams WHERE teamid = ?", [$teamId]);
    } while ($existing['count'] > 0);
    
    return $teamId;
}
?> 
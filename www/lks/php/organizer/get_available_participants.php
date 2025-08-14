<?php
/**
 * API для получения списка участников, доступных для добавления в команду
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/../db/Database.php";

$auth = new Auth();
if (!$auth->isAuthenticated() || !in_array($auth->getUserRole(), ['Organizer', 'SuperUser', 'Admin', 'Secretary'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

try {
    $eventId = $_GET['eventId'] ?? null;
    $classType = $_GET['classType'] ?? null;
    
    if (!$eventId || !$classType) {
        throw new Exception('Не указаны обязательные параметры');
    }
    
    $db = Database::getInstance();
    
    /**
     * Преобразование PostgreSQL массива (например, "{D-10,K-1}") в PHP-массив
     */
    $parsePgArray = static function ($value): array {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return [];
        }
        // Удаляем фигурные скобки и пробелы по краям
        $trimmed = trim($value);
        if (strlen($trimmed) >= 2 && $trimmed[0] === '{' && substr($trimmed, -1) === '}') {
            $trimmed = substr($trimmed, 1, -1);
        }
        if ($trimmed === '') {
            return [];
        }
        // Удаляем кавычки у элементов и разбиваем по запятой
        $parts = array_map(static function ($item) {
            $item = trim($item);
            // Убираем обрамляющие кавычки, если есть
            if ((str_starts_with($item, '"') && str_ends_with($item, '"')) || (str_starts_with($item, "'") && str_ends_with($item, "'"))) {
                $item = substr($item, 1, -1);
            }
            return $item;
        }, explode(',', $trimmed));
        
        // Фильтруем пустые значения
        return array_values(array_filter($parts, static fn($v) => $v !== ''));
    };
    
    // Получаем участников, которые зарегистрированы на мероприятие, но не в командах
    $participants = $db->fetchAll("
        SELECT 
            u.oid,
            u.userid,
            u.fio,
            u.email,
            u.telephone,
            u.sex,
            u.boats,
            lr.oid as registration_id,
            lr.status,
            lr.discipline
        FROM users u
        JOIN listreg lr ON u.oid = lr.users_oid
        WHERE lr.meros_oid = ? 
        AND lr.status IN ('В очереди', 'Подтверждён', 'Зарегистрирован', 'Ожидание команды')
        ORDER BY u.fio ASC
    ", [$eventId]);
    
    // Утилита: проверка, что JSON дисциплины содержит нужный класс
    $disciplineHasClass = static function ($discipline, string $classType): bool {
        if (!$discipline) {
            return false;
        }
        if (is_string($discipline)) {
            $decoded = json_decode($discipline, true);
        } else {
            $decoded = $discipline;
        }
        if (!is_array($decoded)) {
            return false;
        }
        // Вариант 1: структура { "D-10": { ... } }
        if (isset($decoded[$classType])) {
            return true;
        }
        // Вариант 2: структура { "class": "D-10", ... }
        if (isset($decoded['class']) && $decoded['class'] === $classType) {
            return true;
        }
        // Вариант 3: массив однотипных структур [{"class":"D-10",...}, ...]
        $isList = array_keys($decoded) === range(0, count($decoded) - 1);
        if ($isList) {
            foreach ($decoded as $item) {
                if (is_array($item) && isset($item['class']) && $item['class'] === $classType) {
                    return true;
                }
            }
        }
        return false;
    };

    // Фильтруем участников по классу лодки
    $filteredParticipants = [];
    foreach ($participants as $participant) {
        $boats = $participant['boats'] ?? [];
        $discipline = $participant['discipline'];
        
        // Проверяем, может ли участник участвовать в данном классе
        $canParticipate = false;
        
        // Проверяем boats пользователя
        $boatsArray = $parsePgArray($boats);
        if (!empty($boatsArray) && in_array($classType, $boatsArray, true)) {
            $canParticipate = true;
        }
        
        // Проверяем discipline регистрации
        if (!$canParticipate && $disciplineHasClass($discipline, $classType)) {
            $canParticipate = true;
        }
        
        // Доп. правило для D-10: если в дисциплине указан пол экипажа, фильтруем по полу участника
        if ($canParticipate && strpos($classType, 'D-10') !== false) {
            $disc = is_string($discipline) ? json_decode($discipline, true) : $discipline;
            $crewSex = null;
            if (is_array($disc)) {
                if (isset($disc[$classType]['sex']) && is_array($disc[$classType]['sex']) && count($disc[$classType]['sex']) === 1) {
                    $crewSex = $disc[$classType]['sex'][0];
                } elseif (isset($disc['sex']) && (is_string($disc['sex']) || (is_array($disc['sex']) && count($disc['sex']) === 1))) {
                    $crewSex = is_array($disc['sex']) ? $disc['sex'][0] : $disc['sex'];
                }
            }
            if ($crewSex !== null) {
                $normalize = static function ($s) {
                    $map = [
                        'M' => 'М', 'W' => 'Ж', 'F' => 'Ж', 'Male' => 'М', 'Female' => 'Ж',
                        'М' => 'М', 'Ж' => 'Ж'
                    ];
                    return $map[$s] ?? $s;
                };
                if ($normalize($participant['sex']) !== $normalize($crewSex)) {
                    $canParticipate = false;
                }
            }
        }
        
        if ($canParticipate) {
            $filteredParticipants[] = [
                'oid' => $participant['oid'],
                'userid' => $participant['userid'],
                'fio' => $participant['fio'],
                'email' => $participant['email'],
                'telephone' => $participant['telephone'],
                'sex' => $participant['sex'],
                'registration_id' => $participant['registration_id'],
                'status' => $participant['status']
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'participants' => $filteredParticipants
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Ошибка получения доступных участников: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
<?php
session_start();
require_once __DIR__ . '/../common/Auth.php';
require_once __DIR__ . '/../db/Database.php';

// Включаем вывод ошибок для отладки
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Проверка авторизации и прав доступа
$auth = new Auth();

if (!$auth->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Не авторизован']);
    exit;
}

if (!$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Нет прав доступа']);
    exit;
}

// Получение данных из запроса
$input = json_decode(file_get_contents('php://input'), true);
$meroId = $input['meroId'] ?? null;
$protocolIndex = $input['protocolIndex'] ?? null;

if (!$meroId) {
    echo json_encode(['success' => false, 'message' => 'ID мероприятия не указан']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Получаем данные мероприятия
    $stmt = $db->prepare("SELECT * FROM meros WHERE oid = ?");
    $stmt->execute([$meroId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$event) {
        echo json_encode(['success' => false, 'message' => 'Мероприятие не найдено']);
        exit;
    }
    
    // Получаем всех зарегистрированных участников
    $stmt = $db->prepare("
        SELECT 
            u.oid, u.userid, u.fio, u.sex, u.birthdata, u.sportzvanie, u.city,
            lr.discipline, lr.status
        FROM users u
        JOIN listreg lr ON u.oid = lr.users_oid
        WHERE lr.meros_oid = ? 
        AND lr.status IN ('Зарегистрирован', 'Подтверждён')
        AND u.accessrights = 'Sportsman'
        ORDER BY u.fio
    ");
    $stmt->execute([$meroId]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($participants)) {
        echo json_encode(['success' => false, 'message' => 'Нет участников для жеребьевки']);
        exit;
    }
    
    $drawConducted = false;
    $updatedParticipants = [];
    
    // Логируем количество участников
    error_log("Проведение жеребьевки для мероприятия $meroId. Участников: " . count($participants));
    
    // Группируем участников по дисциплинам и проводим жеребьевку
    $processedClasses = [];
    
    foreach ($participants as $participant) {
        $discipline = json_decode($participant['discipline'], true);
        
        if ($discipline) {
            foreach ($discipline as $boatClass => $classData) {
                // Проверяем, не обрабатывали ли мы уже эту комбинацию класс+пол
                $classKey = $boatClass . '_' . $participant['sex'];
                if (in_array($classKey, $processedClasses)) {
                    continue;
                }
                
                // Определяем максимальное количество дорожек
                $maxLanes = ($boatClass === 'D-10') ? 6 : 9;
                
                // Получаем участников для этой дисциплины
                $classParticipants = getParticipantsForClass($db, $meroId, $boatClass, $participant['sex']);
                
                if (!empty($classParticipants)) {
                    error_log("Обработка дисциплины: $boatClass, пол: {$participant['sex']}, участников: " . count($classParticipants));
                    
                    // Перемешиваем участников
                    shuffle($classParticipants);
                    
                    // Назначаем дорожки
                    $lane = 1;
                    foreach ($classParticipants as $classParticipant) {
                        if ($lane > $maxLanes) {
                            $lane = 1;
                        }
                        
                        // Обновляем данные в базе
                        try {
                            // Получаем текущие данные дисциплины
                            $stmt = $db->prepare("
                                SELECT discipline FROM listreg 
                                WHERE users_oid = ? AND meros_oid = ?
                            ");
                            $stmt->execute([$classParticipant['oid'], $meroId]);
                            $currentData = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($currentData) {
                                $disciplineData = json_decode($currentData['discipline'], true);
                                
                                // Добавляем дорожку к указанному классу лодки
                                if (isset($disciplineData[$boatClass])) {
                                    $disciplineData[$boatClass]['lane'] = $lane;
                                    
                                    // Обновляем данные
                                    $stmt = $db->prepare("
                                        UPDATE listreg 
                                        SET discipline = ?
                                        WHERE users_oid = ? AND meros_oid = ?
                                    ");
                                    $stmt->execute([json_encode($disciplineData), $classParticipant['oid'], $meroId]);
                                }
                            }
                        } catch (Exception $e) {
                            error_log("Ошибка обновления дорожки для участника {$classParticipant['oid']}: " . $e->getMessage());
                            throw $e;
                        }
                        
                        $lane++;
                    }
                    
                    $processedClasses[] = $classKey;
                    $drawConducted = true;
                }
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => $drawConducted ? 'Жеребьевка проведена успешно' : 'Нет участников для жеребьевки',
        'drawConducted' => $drawConducted,
        'participantsCount' => count($participants)
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка проведения жеребьевки: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка проведения жеребьевки: ' . $e->getMessage()
    ]);
}

/**
 * Получение участников для конкретной дисциплины
 */
function getParticipantsForClass($db, $meroId, $boatClass, $sex) {
    $stmt = $db->prepare("
        SELECT DISTINCT
            u.oid, u.userid, u.fio, u.sex, u.birthdata, u.sportzvanie, u.city,
            lr.discipline
        FROM users u
        JOIN listreg lr ON u.oid = lr.users_oid
        WHERE lr.meros_oid = ? 
        AND u.sex = ?
        AND lr.status IN ('Зарегистрирован', 'Подтверждён')
        AND u.accessrights = 'Sportsman'
        AND lr.discipline::text LIKE ?
    ");
    $classPattern = '%"' . $boatClass . '"%';
    $stmt->execute([$meroId, $sex, $classPattern]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $filteredParticipants = [];
    
    foreach ($participants as $participant) {
        $discipline = json_decode($participant['discipline'], true);
        
        if ($discipline && isset($discipline[$boatClass])) {
            $filteredParticipants[] = $participant;
        }
    }
    
    return $filteredParticipants;
}
?> 
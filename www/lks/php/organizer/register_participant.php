<?php
/**
 * API для регистрации участников организаторами
 * Позволяет организаторам регистрировать спортсменов на мероприятия
 */

if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/EventRegistration.php";

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

// Проверка авторизации и прав
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Необходима авторизация']);
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'];

// Проверяем права организатора
if (!in_array($userRole, ['Organizer', 'Admin', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Недостаточно прав доступа']);
    exit;
}

try {
    $eventRegistration = new EventRegistration($userId, $userRole);
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_my_events':
            // Получить мероприятия созданные организатором
            $db = Database::getInstance();
            $stmt = $db->prepare("
                SELECT m.champn, m.meroname, m.merodata, m.class_distance, m.defcost, m.status,
                       (SELECT COUNT(*) FROM listreg WHERE meros_oid = m.oid) as participants_count
                FROM meros m 
                WHERE m.created_by = ? OR ? = 'Admin' OR ? = 'SuperUser'
                ORDER BY m.merodata DESC
            ");
            $stmt->execute([$userId, $userRole, $userRole]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Декодируем JSON данные
            foreach ($events as &$event) {
                if ($event['class_distance']) {
                    $event['class_distance'] = json_decode($event['class_distance'], true);
                }
            }
            
            echo json_encode(['success' => true, 'events' => $events]);
            break;
            
        case 'get_event_participants':
            // Получить участников мероприятия
            $eventId = $_GET['event_id'] ?? 0;
            if (!$eventId) {
                throw new Exception('Не указан ID мероприятия');
            }
            
            $db = Database::getInstance();
            
            // Проверяем права на мероприятие
            if (!in_array($userRole, ['Admin', 'SuperUser'])) {
                $stmt = $db->prepare("SELECT created_by FROM meros WHERE champn = ?");
                $stmt->execute([$eventId]);
                $createdBy = $stmt->fetchColumn();
                
                if ($createdBy != $userId) {
                    throw new Exception('У вас нет прав на просмотр участников этого мероприятия');
                }
            }
            
            $stmt = $db->prepare("
                SELECT lr.oid, lr.discipline, lr.status, lr.oplata, lr.cost,
                       u.fio, u.email, u.sex, u.city, u.sportzvanie, u.userid,
                       t.teamname, t.teamcity, t.teamid
                FROM listreg lr
                JOIN users u ON lr.users_oid = u.oid
                LEFT JOIN teams t ON lr.teams_oid = t.oid
                JOIN meros m ON lr.meros_oid = m.oid
                WHERE m.champn = ?
                ORDER BY lr.oid DESC
            ");
            $stmt->execute([$eventId]);
            $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Декодируем JSON данные
            foreach ($participants as &$participant) {
                if ($participant['discipline']) {
                    $participant['discipline'] = json_decode($participant['discipline'], true);
                }
            }
            
            echo json_encode(['success' => true, 'participants' => $participants]);
            break;
            
        case 'update_participant_status':
            // Обновить статус участника
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Метод не поддерживается');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $registrationId = $input['registration_id'] ?? 0;
            $newStatus = $input['status'] ?? '';
            
            if (!$registrationId || !$newStatus) {
                throw new Exception('Некорректные данные запроса');
            }
            
            // Проверяем валидность статуса
            $validStatuses = ['В очереди', 'Подтверждён', 'Зарегистрирован', 'Ожидание команды', 'Дисквалифицирован', 'Неявка'];
            if (!in_array($newStatus, $validStatuses)) {
                throw new Exception('Некорректный статус');
            }
            
            $db = Database::getInstance();
            
            // Проверяем права на регистрацию
            $stmt = $db->prepare("
                SELECT m.champn, m.created_by 
                FROM listreg lr 
                JOIN meros m ON lr.meros_oid = m.oid 
                WHERE lr.oid = ?
            ");
            $stmt->execute([$registrationId]);
            $regData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$regData) {
                throw new Exception('Регистрация не найдена');
            }
            
            if (!in_array($userRole, ['Admin', 'SuperUser']) && $regData['created_by'] != $userId) {
                throw new Exception('У вас нет прав на изменение этой регистрации');
            }
            
            // Обновляем статус
            $stmt = $db->prepare("UPDATE listreg SET status = ? WHERE oid = ?");
            $stmt->execute([$newStatus, $registrationId]);
            
            // Отправляем уведомление участнику
            $stmt = $db->prepare("SELECT u.userid FROM listreg l JOIN users u ON l.users_oid = u.oid WHERE l.oid = ?");
            $stmt->execute([$registrationId]);
            $participantId = $stmt->fetchColumn();
            
            if ($participantId) {
                $notification = new Notification();
                $notification->send($participantId, 'status_change', 
                    'Изменение статуса регистрации', 
                    "Статус вашей регистрации изменен на: {$newStatus}"
                );
            }
            
            echo json_encode(['success' => true, 'message' => 'Статус успешно обновлен']);
            break;
            
        case 'delete_registration':
            // Удалить регистрацию
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Метод не поддерживается');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $registrationId = $input['registration_id'] ?? 0;
            
            if (!$registrationId) {
                throw new Exception('Не указан ID регистрации');
            }
            
            $db = Database::getInstance();
            
            // Проверяем права
            $stmt = $db->prepare("
                SELECT m.champn, u.userid, lr.teams_oid, m.created_by 
                FROM listreg lr 
                JOIN meros m ON lr.meros_oid = m.oid 
                JOIN users u ON lr.users_oid = u.oid
                WHERE lr.oid = ?
            ");
            $stmt->execute([$registrationId]);
            $regData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$regData) {
                throw new Exception('Регистрация не найдена');
            }
            
            if (!in_array($userRole, ['Admin', 'SuperUser']) && $regData['created_by'] != $userId) {
                throw new Exception('У вас нет прав на удаление этой регистрации');
            }
            
            $db->beginTransaction();
            
            try {
                // Удаляем регистрацию
                $stmt = $db->prepare("DELETE FROM listreg WHERE oid = ?");
                $stmt->execute([$registrationId]);
                
                // Если есть команда, проверяем не осталась ли она пустой
                if ($regData['teams_oid']) {
                    $stmt = $db->prepare("SELECT COUNT(*) FROM listreg WHERE teams_oid = ?");
                    $stmt->execute([$regData['teams_oid']]);
                    $teamMembersCount = $stmt->fetchColumn();
                    
                    if ($teamMembersCount == 0) {
                        // Удаляем пустую команду
                        $stmt = $db->prepare("DELETE FROM teams WHERE oid = ?");
                        $stmt->execute([$regData['teams_oid']]);
                    } else {
                        // Обновляем количество участников в команде
                        $stmt = $db->prepare("UPDATE teams SET persons_amount = ? WHERE oid = ?");
                        $stmt->execute([$teamMembersCount, $regData['teams_oid']]);
                    }
                }
                
                // Уведомляем участника
                if ($regData['userid']) {
                    $notification = new Notification();
                    $notification->send($regData['userid'], 'status_change', 
                        'Регистрация отменена', 
                        'Ваша регистрация была отменена организатором'
                    );
                }
                
                $db->commit();
                echo json_encode(['success' => true, 'message' => 'Регистрация успешно удалена']);
                
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;
            
        default:
            // Перенаправляем на общий API
            $query = http_build_query($_GET);
            include __DIR__ . '/../user/get_registration_form.php';
            break;
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?> 
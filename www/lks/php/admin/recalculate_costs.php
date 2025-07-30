<?php
/**
 * API для пересчета стоимости участия в мероприятиях
 * Доступен только администраторам
 */

session_start();
require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/TeamCostManager.php";

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

// Проверка авторизации и прав
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}

if (!$auth->isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Недостаточно прав']);
    exit;
}

$db = Database::getInstance();
$costManager = new TeamCostManager();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
        
        switch ($action) {
            case 'recalculate_event':
                // Пересчет для конкретного мероприятия
                $eventId = $input['event_id'] ?? null;
                if (!$eventId) {
                    throw new Exception('Не указан ID мероприятия');
                }
                
                // Проверяем существование мероприятия
                $event = $db->fetchOne("SELECT oid, meroname FROM meros WHERE oid = ? OR champn = ?", [$eventId, $eventId]);
                if (!$event) {
                    throw new Exception('Мероприятие не найдено');
                }
                
                $stats = $costManager->recalculateEventCosts($eventId);
                
                echo json_encode([
                    'success' => true,
                    'message' => "Пересчет завершен для мероприятия: {$event['meroname']}",
                    'stats' => $stats
                ]);
                break;
                
            case 'recalculate_all':
                // Пересчет для всех активных мероприятий
                $events = $db->query("
                    SELECT oid, meroname 
                    FROM meros 
                    WHERE status::text IN ('Регистрация', 'Регистрация закрыта')
                ")->fetchAll(PDO::FETCH_ASSOC);
                
                $totalStats = [
                    'updated_registrations' => 0,
                    'updated_teams' => 0,
                    'errors' => 0,
                    'events_processed' => 0
                ];
                
                foreach ($events as $event) {
                    $eventStats = $costManager->recalculateEventCosts($event['oid']);
                    $totalStats['updated_registrations'] += $eventStats['updated_registrations'];
                    $totalStats['updated_teams'] += $eventStats['updated_teams'];
                    $totalStats['errors'] += $eventStats['errors'];
                    $totalStats['events_processed']++;
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => "Пересчет завершен для {$totalStats['events_processed']} мероприятий",
                    'stats' => $totalStats
                ]);
                break;
                
            case 'recalculate_team':
                // Пересчет для конкретной команды
                $teamId = $input['team_id'] ?? null;
                $eventId = $input['event_id'] ?? null;
                
                if (!$teamId || !$eventId) {
                    throw new Exception('Не указаны ID команды или мероприятия');
                }
                
                $success = $costManager->recalculateTeamCosts($teamId, $eventId);
                
                if ($success) {
                    echo json_encode([
                        'success' => true,
                        'message' => "Стоимость для команды $teamId пересчитана"
                    ]);
                } else {
                    throw new Exception('Ошибка пересчета стоимости команды');
                }
                break;
                
            case 'update_registration':
                // Пересчет для конкретной регистрации
                $registrationId = $input['registration_id'] ?? null;
                if (!$registrationId) {
                    throw new Exception('Не указан ID регистрации');
                }
                
                $success = $costManager->updateRegistrationCost($registrationId);
                
                if ($success) {
                    echo json_encode([
                        'success' => true,
                        'message' => "Стоимость для регистрации $registrationId обновлена"
                    ]);
                } else {
                    throw new Exception('Ошибка обновления стоимости регистрации');
                }
                break;
                
            default:
                throw new Exception('Неизвестное действие');
        }
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Получение статистики по стоимости
        $eventId = $_GET['event_id'] ?? null;
        
        if ($eventId) {
            // Статистика для конкретного мероприятия
            $stats = $db->fetchOne("
                SELECT 
                    COUNT(*) as total_registrations,
                    COUNT(CASE WHEN cost > 0 THEN 1 END) as paid_registrations,
                    SUM(cost) as total_cost,
                    AVG(cost) as avg_cost
                FROM listreg 
                WHERE meros_oid = ?
            ", [$eventId]);
            
            $teamStats = $db->fetchOne("
                SELECT 
                    COUNT(DISTINCT teams_oid) as total_teams
                FROM listreg 
                WHERE meros_oid = ? AND teams_oid IS NOT NULL
            ", [$eventId]);
            
            echo json_encode([
                'success' => true,
                'event_stats' => array_merge($stats ?: [], $teamStats ?: [])
            ]);
        } else {
            // Общая статистика
            $stats = $db->fetchOne("
                SELECT 
                    COUNT(*) as total_registrations,
                    COUNT(CASE WHEN cost > 0 THEN 1 END) as paid_registrations,
                    SUM(cost) as total_cost,
                    COUNT(DISTINCT meros_oid) as total_events
                FROM listreg
            ");
            
            echo json_encode([
                'success' => true,
                'general_stats' => $stats ?: []
            ]);
        }
    } else {
        throw new Exception('Метод не поддерживается');
    }
    
} catch (Exception $e) {
    error_log("Ошибка в recalculate_costs.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
?> 
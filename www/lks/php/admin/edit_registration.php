<?php
/**
 * API для редактирования регистраций
 * Только для администраторов
 */

// Заголовки JSON и CORS
if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Origin: *');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

// Проверка авторизации
if (!isset($_SESSION)) {
    session_start();
}

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/Auth.php";

$auth = new Auth();

// Проверка авторизации и прав администратора
if (!$auth->isAuthenticated() || !$auth->hasAnyRole(['SuperUser', 'Admin'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Доступ запрещен'
    ]);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Получение данных регистрации для редактирования
        $regId = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if ($regId <= 0) {
            throw new Exception('Не указан ID регистрации');
        }
        
        $sql = "
            SELECT 
                lr.oid,
                lr.users_oid,
                lr.meros_oid,
                lr.teams_oid,
                lr.discipline,
                lr.oplata,
                lr.cost,
                lr.status,
                lr.role,
                u.fio,
                u.email,
                u.telephone,
                m.meroname
            FROM listreg lr
            LEFT JOIN users u ON lr.users_oid = u.oid
            LEFT JOIN meros m ON lr.meros_oid = m.oid
            WHERE lr.oid = ?
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$regId]);
        $registration = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$registration) {
            throw new Exception('Регистрация не найдена');
        }
        
        // Декодируем JSON данные
        $registration['discipline'] = json_decode($registration['discipline'], true);
        
        echo json_encode([
            'success' => true,
            'registration' => $registration
        ]);
        
    } else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Обновление данных регистрации
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['registrationId'])) {
            throw new Exception('Не указан ID регистрации');
        }
        
        $regId = intval($input['registrationId']);
        $cost = isset($input['cost']) ? trim($input['cost']) : null;
        $status = isset($input['status']) ? $input['status'] : null;
        $classDistance = isset($input['class_distance']) ? $input['class_distance'] : null;
        
        if ($regId <= 0) {
            throw new Exception('Некорректный ID регистрации');
        }
        
        // Валидация статуса
        if ($status) {
            $validStatuses = ['В очереди', 'Зарегистрирован', 'Подтверждён', 'Ожидание команды', 'Дисквалифицирован', 'Неявка'];
            if (!in_array($status, $validStatuses)) {
                throw new Exception('Недопустимый статус');
            }
        }
        
        // Обновляем поля по отдельности, чтобы избежать проблем с типизацией
        $updated = false;
        
        // Обновляем cost
        if ($cost !== null) {
            $sql = "UPDATE listreg SET cost = ? WHERE oid = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$cost, $regId]);
            $updated = true;
        }
        
        // Обновляем status
        if ($status !== null) {
            $sql = "UPDATE listreg SET status = ? WHERE oid = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$status, $regId]);
            $updated = true;
        }
        
        // Обновляем oplata отдельным запросом с принудительным boolean кастингом
        if (isset($input['oplata'])) {
            // Определяем boolean значение максимально просто
            $boolValue = null;
            if (is_bool($input['oplata'])) {
                $boolValue = $input['oplata'] ? 'true' : 'false';
            } else {
                $strValue = strtolower(trim($input['oplata']));
                if ($strValue === 'true' || $strValue === '1' || $strValue === 'on') {
                    $boolValue = 'true';
                } else {
                    $boolValue = 'false';
                }
            }
            
            // Используем текстовое представление boolean для PostgreSQL
            $sql = "UPDATE listreg SET oplata = ?::boolean WHERE oid = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$boolValue, $regId]);
            $updated = true;
        }
        
        // Обновляем discipline
        if ($classDistance !== null) {
            $sql = "UPDATE listreg SET discipline = ? WHERE oid = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([json_encode($classDistance), $regId]);
            $updated = true;
        }
        
        if (!$updated) {
            throw new Exception('Нет данных для обновления');
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Регистрация успешно обновлена'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Ошибка редактирования регистрации: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
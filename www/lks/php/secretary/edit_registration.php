<?php
/**
 * API для редактирования регистраций секретарем
 * Включает изменение статуса, стоимости и оплаты
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/../db/Database.php";

$auth = new Auth();
if (!$auth->isAuthenticated() || !in_array($auth->getUserRole(), ['Secretary', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Получение данных регистрации для редактирования
        $regId = $_GET['id'] ?? null;
        
        if (!$regId) {
            throw new Exception('Не указан ID регистрации');
        }
        
        // Получаем данные регистрации
        $stmt = $pdo->prepare("
            SELECT 
                lr.oid,
                lr.status,
                lr.cost,
                lr.oplata,
                lr.discipline,
                u.fio,
                u.email,
                u.telephone,
                u.userid,
                m.meroname,
                m.champn,
                t.teamname,
                t.teamid
            FROM listreg lr
            JOIN users u ON lr.users_oid = u.oid
            JOIN meros m ON lr.meros_oid = m.oid
            LEFT JOIN teams t ON lr.teams_oid = t.oid
            WHERE lr.oid = ?
        ");
        
        $stmt->execute([$regId]);
        $registration = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$registration) {
            throw new Exception('Регистрация не найдена');
        }
        
        echo json_encode([
            'success' => true,
            'registration' => $registration
        ]);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Обновление данных регистрации
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            throw new Exception('Неверный формат данных');
        }
        
        $regId = $input['registrationId'] ?? null;
        $status = $input['status'] ?? null;
        $cost = $input['cost'] ?? null;
        $oplata = $input['oplata'] ?? null;
        
        if (!$regId) {
            throw new Exception('Не указан ID регистрации');
        }
        
        // Проверяем права доступа
        $accessSql = "
            SELECT lr.oid, lr.status, lr.cost, lr.oplata
            FROM listreg lr
            WHERE lr.oid = ?
        ";
        
        $stmt = $pdo->prepare($accessSql);
        $stmt->execute([$regId]);
        
        $accessData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$accessData) {
            throw new Exception('Регистрация не найдена');
        }
        
        // Валидация статуса
        if ($status) {
            $validStatuses = ['В очереди', 'Зарегистрирован', 'Подтверждён', 'Ожидание команды', 'Дисквалифицирован', 'Неявка'];
            if (!in_array($status, $validStatuses)) {
                throw new Exception('Недопустимый статус');
            }
        }
        
        // Валидация стоимости
        if ($cost !== null) {
            if (!is_numeric($cost) || $cost < 0) {
                throw new Exception('Недопустимая стоимость');
            }
        }
        
        // Обновляем поля по отдельности
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
        
        // Обновляем oplata
        if ($oplata !== null) {
            $boolValue = $oplata ? 'true' : 'false';
            $sql = "UPDATE listreg SET oplata = ?::boolean WHERE oid = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$boolValue, $regId]);
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
    error_log("Ошибка редактирования регистрации (секретарь): " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
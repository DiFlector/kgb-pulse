<?php
require_once '../common/Auth.php';
require_once '../db/Database.php';

header('Content-Type: application/json');

try {
    $auth = new Auth();
    
    // Проверяем аутентификацию
    if (!$auth->isAuthenticated()) {
        throw new Exception('Не авторизован');
    }
    
    // Получаем данные текущего пользователя
    $userData = $auth->getCurrentUser();
    
    if (!$userData || !in_array($userData['accessrights'], ['Admin', 'Organizer', 'Secretary', 'SuperUser'])) {
        throw new Exception('Недостаточно прав доступа');
    }
    
    // Определяем уровень доступа (как в registrations.php)
    $hasFullAccess = $userData['accessrights'] === 'SuperUser' ||
        ($userData['accessrights'] === 'Admin');
    
    $database = Database::getInstance();
    $pdo = $database->getPDO();
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Получение данных регистрации
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
        
        // Все организаторы имеют доступ ко всем регистрациям
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$regId]);
        
        $registration = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$registration) {
            throw new Exception('Регистрация не найдена или нет прав доступа');
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
        
        // Проверяем права доступа к регистрации
        $accessSql = "
            SELECT lr.oplata, m.created_by 
            FROM listreg lr
            LEFT JOIN meros m ON lr.meros_oid = m.oid
            WHERE lr.oid = ?
        ";
        
        // Все организаторы имеют доступ ко всем регистрациям
        $stmt = $pdo->prepare($accessSql);
        $stmt->execute([$regId]);
        
        $accessData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$accessData) {
            throw new Exception('Регистрация не найдена или нет прав доступа');
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
        
        // Обновляем oplata с ограничениями для организатора
        if (isset($input['oplata'])) {
            $newOplataValue = null;
            
            // Определяем новое boolean значение
            if (is_bool($input['oplata'])) {
                $newOplataValue = $input['oplata'];
            } else {
                $strValue = strtolower(trim($input['oplata']));
                $newOplataValue = ($strValue === 'true' || $strValue === '1' || $strValue === 'on');
            }
            
            // Проверяем ограничения для организатора (но не для тех, кто имеет полный доступ)
            if ($userData['accessrights'] === 'Organizer' && !$hasFullAccess) {
                $currentOplata = (bool)$accessData['oplata'];
                
                // Организатор может только включать оплату, но не выключать
                if ($currentOplata && !$newOplataValue) {
                    throw new Exception('Организатор не может снимать оплату. Обратитесь к администратору.');
                }
            }
            
            // Обновляем oplata отдельным запросом с принудительным boolean кастингом
            $boolValue = $newOplataValue ? 'true' : 'false';
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
    error_log("Ошибка редактирования регистрации (организатор): " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
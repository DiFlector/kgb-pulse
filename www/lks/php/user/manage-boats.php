<?php
/**
 * Управление лодками пользователя
 * Позволяет получать и обновлять список лодок пользователя
 */

session_start();
require_once __DIR__ . "/../db/Database.php";

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? '';

// Проверка прав доступа - только определенные роли могут работать с лодками
$allowedRoles = ['Sportsman', 'Organizer', 'SuperUser', 'Admin'];
if (!in_array($userRole, $allowedRoles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Недостаточно прав']);
    exit;
}

try {
    $db = Database::getInstance()->getPDO();
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Получение списка доступных лодок и лодок пользователя
        
        // Получаем доступные типы лодок из ENUM
        $enumQuery = "SELECT unnest(enum_range(NULL::boats)) as boat_type";
        $enumStmt = $db->prepare($enumQuery);
        $enumStmt->execute();
        $availableBoats = $enumStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Получаем лодки пользователя
        $userQuery = "SELECT boats FROM users WHERE userid = :userid";
        $userStmt = $db->prepare($userQuery);
        $userStmt->bindParam(':userid', $userId, PDO::PARAM_INT);
        $userStmt->execute();
        $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        $userBoats = [];
        if ($userRow && $userRow['boats']) {
            // Парсим PostgreSQL array
            $boatsString = trim($userRow['boats'], '{}');
            if (!empty($boatsString)) {
                $userBoats = explode(',', $boatsString);
                $userBoats = array_map('trim', $userBoats);
            }
        }
        
        echo json_encode([
            'success' => true,
            'available_boats' => $availableBoats,
            'user_boats' => $userBoats
        ]);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Обновление лодок пользователя
        
        // Проверка прав на изменение - только Sportsman и SuperUser
        if (!in_array($userRole, ['Sportsman', 'SuperUser', 'Admin'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Недостаточно прав для изменения']);
            exit;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['boats']) || !is_array($input['boats'])) {
            echo json_encode(['success' => false, 'error' => 'Неверный формат данных']);
            exit;
        }
        
        $selectedBoats = $input['boats'];
        
        // Получаем доступные типы лодок для валидации
        $enumQuery = "SELECT unnest(enum_range(NULL::boats)) as boat_type";
        $enumStmt = $db->prepare($enumQuery);
        $enumStmt->execute();
        $availableBoats = $enumStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Валидация выбранных лодок
        foreach ($selectedBoats as $boat) {
            if (!in_array($boat, $availableBoats)) {
                echo json_encode(['success' => false, 'error' => 'Недопустимый тип лодки: ' . $boat]);
                exit;
            }
        }
        
        // Формируем PostgreSQL array
        if (empty($selectedBoats)) {
            $boatsArray = NULL;
        } else {
            $boatsArray = '{' . implode(',', $selectedBoats) . '}';
        }
        
        // Обновляем лодки пользователя
        $updateQuery = "UPDATE users SET boats = :boats WHERE userid = :userid";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':boats', $boatsArray);
        $updateStmt->bindParam(':userid', $userId, PDO::PARAM_INT);
        
        if ($updateStmt->execute()) {
            // Логируем действие пользователя
            error_log("Пользователь $userId обновил свои лодки: " . implode(', ', $selectedBoats));
            
            echo json_encode(['success' => true, 'message' => 'Лодки успешно обновлены']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Ошибка при сохранении в базу данных']);
        }
        
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Метод не поддерживается']);
    }
    
} catch (Exception $e) {
    error_log("Ошибка в manage-boats.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Внутренняя ошибка сервера']);
}
?> 
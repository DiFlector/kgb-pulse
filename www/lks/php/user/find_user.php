<?php
/**
 * Поиск пользователей для автозаполнения в формах регистрации
 */

session_start();
require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../helpers.php";

// Проверяем авторизацию
if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Необходима авторизация'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Метод не поддерживается'], 405);
}

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    
    $user = null;
    
    // Поиск по email
    if (!empty($_POST['email'])) {
        $email = trim($_POST['email']);
        $stmt = $pdo->prepare("SELECT userid, fio, email, telephone FROM users WHERE email = ? AND userid >= 1000");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    // Поиск по телефону
    elseif (!empty($_POST['phone'])) {
        $phone = trim($_POST['phone']);
        // Очищаем телефон от лишних символов
        $cleanPhone = preg_replace('/[^\d+]/', '', $phone);
        
        $stmt = $pdo->prepare("
                    SELECT userid, fio, email, telephone
        FROM users 
        WHERE (telephone = ? OR telephone = ? OR REPLACE(REPLACE(REPLACE(telephone, ' ', ''), '-', ''), '(', '') LIKE ?) 
            AND userid >= 1000
        ");
        $stmt->execute([$phone, $cleanPhone, '%' . $cleanPhone . '%']);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    // Поиск по ID пользователя
    elseif (!empty($_POST['userid'])) {
        $userid = intval($_POST['userid']);
        
        // Проверяем права доступа - только организаторы и админы могут искать по ID
        $currentUserRole = getUserRole($_SESSION['user_id']);
        if (!in_array($currentUserRole, ['admin', 'organizer'])) {
            jsonResponse(['success' => false, 'message' => 'Недостаточно прав для поиска по ID']);
        }
        
        $stmt = $pdo->prepare("SELECT userid, fio, email, telephone FROM users WHERE userid = ? AND userid >= 1000");
        $stmt->execute([$userid]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    else {
        jsonResponse(['success' => false, 'message' => 'Не указан критерий поиска']);
    }
    
    if ($user) {
        jsonResponse([
            'success' => true,
            'user' => $user,
            'message' => 'Пользователь найден'
        ]);
    } else {
        jsonResponse([
            'success' => false,
            'message' => 'Пользователь не найден'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Ошибка поиска пользователя: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Ошибка поиска пользователя'], 500);
}

/**
 * Получает роль пользователя
 */
function getUserRole($userid) {
    if ($userid >= 1 && $userid <= 50) return 'admin';
    if ($userid >= 51 && $userid <= 150) return 'organizer';
    if ($userid >= 151 && $userid <= 250) return 'secretary';
    return 'user';
}
?> 
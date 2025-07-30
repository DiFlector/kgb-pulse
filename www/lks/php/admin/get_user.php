<?php
/**
 * API для получения данных пользователя администратором
 */

// Заголовки JSON и CORS
if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Origin: *');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Methods: GET, OPTIONS');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

// Проверка авторизации
if (!defined('TEST_MODE') && !isset($_SESSION)) {
    session_start();
}

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/Auth.php";

$auth = new Auth();

// Проверка авторизации и прав администратора
if (!$auth->isAuthenticated() || !$auth->hasAnyRole(['Admin', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Доступ запрещен'
    ]);
    exit;
}

// УДАЛЁН fallback для тестов: никаких подстановок user_id и user_role

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    
    $action = $_GET['action'] ?? null;
    $userId = $_GET['userId'] ?? null;
    
    if ($action === 'get_users') {
        // Получаем список всех пользователей
        $sql = "SELECT userid, email, fio, sex, telephone, birthdata, country, city, accessrights, boats, sportzvanie FROM users ORDER BY userid";
        $stmt = $pdo->query($sql);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as &$user) {
            if ($user['boats']) {
                $boats = str_replace(['{', '}'], '', $user['boats']);
                $user['boats'] = explode(',', $boats);
            } else {
                $user['boats'] = [];
            }
            if ($user['birthdata']) {
                $user['birthdata'] = date('Y-m-d', strtotime($user['birthdata']));
            }
        }
        $jsonOutput = json_encode([
            'success' => true,
            'users' => $users
        ]);
        if (defined('TEST_MODE')) {
            $GLOBALS['test_json_response'] = $jsonOutput;
        } else {
            echo $jsonOutput;
            exit;
        }
    } elseif ($userId) {
        // Получаем данные одного пользователя
        $sql = "SELECT userid, email, fio, sex, telephone, birthdata, country, city, accessrights, boats, sportzvanie FROM users WHERE userid = :userid";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':userid', $userId);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            $jsonOutput = json_encode([
                'success' => false,
                'message' => 'Пользователь не найден'
            ]);
            if (defined('TEST_MODE')) {
                $GLOBALS['test_json_response'] = $jsonOutput;
            } else {
                echo $jsonOutput;
                exit;
            }
        }
        if ($user['boats']) {
            $boats = str_replace(['{', '}'], '', $user['boats']);
            $user['boats'] = explode(',', $boats);
        } else {
            $user['boats'] = [];
        }
        if ($user['birthdata']) {
            $user['birthdata'] = date('Y-m-d', strtotime($user['birthdata']));
        }
        $jsonOutput = json_encode([
            'success' => true,
            'user' => $user
        ]);
        if (defined('TEST_MODE')) {
            $GLOBALS['test_json_response'] = $jsonOutput;
        } else {
            echo $jsonOutput;
        }
        if (!defined('TEST_MODE')) {
            exit;
        }
    } else {
        $jsonOutput = json_encode([
            'success' => false,
            'message' => 'Не указан ID пользователя или action'
        ]);
        if (defined('TEST_MODE')) {
            $GLOBALS['test_json_response'] = $jsonOutput;
        } else {
            echo $jsonOutput;
        }
        if (!defined('TEST_MODE')) {
            exit;
        }
    }
    
} catch (Exception $e) {
    error_log("Ошибка получения данных пользователя: " . $e->getMessage());
    
    $jsonOutput = json_encode([
        'success' => false,
        'message' => 'Ошибка получения данных пользователя: ' . $e->getMessage()
    ]);
    if (defined('TEST_MODE')) {
        $GLOBALS['test_json_response'] = $jsonOutput;
    } else {
        echo $jsonOutput;
    }
    if (!defined('TEST_MODE')) {
        exit;
    }
}
?>
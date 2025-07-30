<?php
// API для входа пользователя (логин)
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../php/common/Auth.php';
require_once __DIR__ . '/../../php/db/Database.php';

session_start();

// Проверяем метод запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Метод не поддерживается. Используйте POST.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Получаем email и пароль из POST
$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Не указан email или пароль.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $auth = new Auth();
    $result = $auth->login($email, $password);
    if ($result['success']) {
        // Успешная авторизация, сессия уже установлена
        echo json_encode([
            'success' => true,
            'message' => 'Вход выполнен успешно',
            'user' => $result['user'],
            'session_id' => session_id()
        ], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => $result['message'] ?? 'Ошибка авторизации'
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Внутренняя ошибка: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} 
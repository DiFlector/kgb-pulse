<?php
session_start();
require_once __DIR__ . '/../../common/Auth.php';

// Включаем вывод ошибок для отладки
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

echo json_encode([
    'session_id' => session_id(),
    'session_data' => $_SESSION,
    'is_authenticated' => (new Auth())->isAuthenticated(),
    'user_role' => $_SESSION['user_role'] ?? 'not set',
    'user_id' => $_SESSION['user_id'] ?? 'not set',
    'has_secretary_role' => (new Auth())->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])
]);
?> 
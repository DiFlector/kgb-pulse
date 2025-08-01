<?php
session_start();
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/../db/Database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $auth = new Auth();
    
    // Проверка авторизации
    if (!$auth->isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    $user = $auth->getCurrentUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    
    require_once __DIR__ . '/Notification.php';
    
    $notification = new Notification();
    // Используем oid пользователя для работы с уведомлениями
    $unreadNotifications = $notification->getUnreadNotifications($user['oid']);
    
    echo json_encode([
        'success' => true,
        'notifications' => $unreadNotifications,
        'count' => count($unreadNotifications)
    ]);
    
} catch (Exception $e) {
    error_log('Ошибка при получении уведомлений: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка сервера',
        'message' => $e->getMessage()
    ]);
} 
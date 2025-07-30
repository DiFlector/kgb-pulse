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
    
    if (!isset($_POST['notification_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing notification_id']);
        exit;
    }
    
    require_once __DIR__ . '/Notification.php';
    
    $notification = new Notification();
    $success = $notification->markAsRead($_POST['notification_id'], $user['userid']);
    
    echo json_encode([
        'success' => $success,
        'message' => $success ? 'Уведомление отмечено как прочитанное' : 'Ошибка при обновлении уведомления'
    ]);
} catch (Exception $e) {
    error_log('Ошибка при отметке уведомления: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка сервера',
        'message' => $e->getMessage()
    ]);
} 
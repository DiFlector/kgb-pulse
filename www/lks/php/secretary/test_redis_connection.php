<?php
// Тестовый файл для проверки подключения к Redis
header('Content-Type: application/json; charset=utf-8');

try {
    // Проверяем подключение к Redis
    $redis = new Redis();
    $connected = $redis->connect('redis', 6379);
    
    if ($connected) {
        echo json_encode([
            'success' => true,
            'message' => 'Redis подключение успешно',
            'redis_info' => $redis->info()
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Не удалось подключиться к Redis'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка Redis: ' . $e->getMessage()
    ]);
}
?> 
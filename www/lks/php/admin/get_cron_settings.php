<?php
// Получение настроек cron (количество дней до автозакрытия регистрации)

session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (!in_array($_SESSION['user_role'] ?? '', ['Admin', 'SuperUser']))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещён']);
    exit;
}

try {
    $configPath = __DIR__ . '/../../files/json/cron_settings.json';
    $default = ['days_before_close' => 5];

    if (!is_file($configPath)) {
        echo json_encode(['success' => true, 'data' => $default]);
        exit;
    }

    $raw = file_get_contents($configPath);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = $default;
    }

    if (!isset($data['days_before_close']) || !is_numeric($data['days_before_close'])) {
        $data['days_before_close'] = $default['days_before_close'];
    }

    echo json_encode(['success' => true, 'data' => [
        'days_before_close' => (int)$data['days_before_close']
    ]]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка чтения настроек']);
}
<?php


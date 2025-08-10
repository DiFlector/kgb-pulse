<?php
// Сохранение настроек cron (количество дней до автозакрытия регистрации)

session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (!in_array($_SESSION['user_role'] ?? '', ['Admin', 'SuperUser']))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещён']);
    exit;
}

try {
    $input = file_get_contents('php://input');
    $payload = json_decode($input, true);
    if (!is_array($payload)) {
        throw new Exception('Некорректные данные');
    }

    $days = $payload['days_before_close'] ?? null;
    if (!is_numeric($days)) {
        throw new Exception('Некорректное значение дней');
    }

    $days = (int)$days;
    if ($days < 0) { $days = 0; }
    if ($days > 365) { $days = 365; }

    $configDir = __DIR__ . '/../../files/json';
    if (!is_dir($configDir)) {
        if (!mkdir($configDir, 0775, true) && !is_dir($configDir)) {
            throw new Exception('Не удалось создать директорию настроек');
        }
    }

    $configPath = $configDir . '/cron_settings.json';
    $data = ['days_before_close' => $days];
    $ok = file_put_contents($configPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
    if (!$ok) {
        throw new Exception('Ошибка записи настроек');
    }

    echo json_encode(['success' => true, 'message' => 'Настройки сохранены', 'data' => $data]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
<?php


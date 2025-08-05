<?php
/**
 * Тестовый файл для проверки удаления участника через веб-запрос
 */

// Симулируем POST запрос к remove_participant.php
$testData = [
    'groupKey' => 'protocol:1:K-1:M:200:группа 1',
    'userId' => 1001
];

echo "🧪 Тестирование удаления участника через веб-запрос\n";
echo "📤 Отправляемые данные: " . json_encode($testData, JSON_UNESCAPED_UNICODE) . "\n\n";

// Создаем контекст для POST запроса
$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/json',
        'content' => json_encode($testData)
    ]
]);

// Отправляем запрос к remove_participant.php
$url = 'http://localhost/lks/php/secretary/remove_participant.php';
$response = file_get_contents($url, false, $context);

echo "📥 Ответ сервера:\n";
echo $response . "\n";

// Проверяем HTTP заголовки
$headers = $http_response_header ?? [];
echo "\n📋 HTTP заголовки:\n";
foreach ($headers as $header) {
    echo "  $header\n";
} 
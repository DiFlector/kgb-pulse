<?php
// Тестовый файл для проверки прав доступа к файлам
header('Content-Type: application/json; charset=utf-8');

$testDir = '/var/www/html/lks/files/protocol/';
$testFile = $testDir . 'test_file.txt';

try {
    $results = [];
    
    // Проверяем существование директории
    $results['dir_exists'] = is_dir($testDir);
    $results['dir_path'] = $testDir;
    
    // Проверяем права на запись
    $results['dir_writable'] = is_writable($testDir);
    
    // Пытаемся создать тестовый файл
    $testContent = "Тестовый файл создан: " . date('Y-m-d H:i:s');
    $fileCreated = file_put_contents($testFile, $testContent);
    $results['file_created'] = $fileCreated !== false;
    $results['file_size'] = $fileCreated;
    
    // Проверяем созданный файл
    $results['file_exists'] = file_exists($testFile);
    $results['file_readable'] = is_readable($testFile);
    
    // Удаляем тестовый файл
    $fileDeleted = unlink($testFile);
    $results['file_deleted'] = $fileDeleted;
    
    // Проверяем права пользователя
    $results['current_user'] = get_current_user();
    $results['process_user'] = posix_getpwuid(posix_geteuid())['name'] ?? 'unknown';
    
    echo json_encode([
        'success' => true,
        'message' => 'Тест прав доступа завершен',
        'results' => $results
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка теста: ' . $e->getMessage(),
        'results' => $results ?? []
    ]);
}
?> 
<?php
/**
 * API для очистки временных файлов экспорта
 */
session_start();

// Проверка авторизации
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Не авторизован']);
    exit;
}

// Проверка прав администратора или суперпользователя
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Admin', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
    exit;
}

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

try {
    $tempDir = __DIR__ . '/../../files/temp';
    
    if (!is_dir($tempDir)) {
        throw new Exception('Временная директория не найдена');
    }
    
    $deletedCount = 0;
    $totalSize = 0;
    $errorCount = 0;
    $deletedFiles = [];
    $maxAge = $_POST['max_age'] ?? 3600; // По умолчанию 1 час
    
    $files = glob($tempDir . '/*');
    
    foreach ($files as $file) {
        if (is_file($file)) {
            $fileAge = time() - filemtime($file);
            $fileSize = filesize($file);
            $fileName = basename($file);
            
            if ($fileAge > $maxAge) {
                if (unlink($file)) {
                    $deletedCount++;
                    $totalSize += $fileSize;
                    $deletedFiles[] = [
                        'name' => $fileName,
                        'age_minutes' => round($fileAge / 60),
                        'size_kb' => round($fileSize / 1024)
                    ];
                } else {
                    $errorCount++;
                }
            }
        }
    }
    
    // Проверяем оставшиеся файлы
    $remainingFiles = glob($tempDir . '/*');
    $remainingCount = count($remainingFiles);
    $remainingSize = 0;
    
    foreach ($remainingFiles as $file) {
        if (is_file($file)) {
            $remainingSize += filesize($file);
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Очистка завершена',
        'deleted_count' => $deletedCount,
        'deleted_size_kb' => round($totalSize / 1024),
        'error_count' => $errorCount,
        'remaining_count' => $remainingCount,
        'remaining_size_kb' => round($remainingSize / 1024),
        'deleted_files' => $deletedFiles,
        'max_age_hours' => round($maxAge / 3600, 1)
    ]);
    
} catch (Exception $e) {
    error_log('Ошибка при очистке временных файлов: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка при очистке: ' . $e->getMessage()
    ]);
}
?> 
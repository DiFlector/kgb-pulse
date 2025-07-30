<?php
// Резервное копирование базы данных - API для администратора
session_start();

// Проверка авторизации
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Не авторизован']);
    exit;
}

// Проверка прав администратора
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Admin', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
    exit;
}

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

// Получение данных запроса
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['action']) || $input['action'] !== 'create_backup') {
    echo json_encode(['success' => false, 'message' => 'Неверное действие']);
    exit;
}

try {
    // Параметры подключения к БД (из переменных окружения или конфига)
    $host = 'postgres'; // имя контейнера PostgreSQL в Docker
    $port = '5432';
    $database = 'pulse_db';
    $username = 'pulse_user';
    
    // Создание директории для бэкапов, если её нет
    $backupDir = '/app/data/db/backup';
    if (!file_exists($backupDir)) {
        if (!mkdir($backupDir, 0755, true)) {
            throw new Exception('Не удалось создать директорию для бэкапов');
        }
    }
    
    // Имя файла бэкапа
    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = $backupDir . '/' . $filename;
    
    // Команда для создания дампа PostgreSQL
    $command = sprintf(
        'PGPASSWORD=%s pg_dump -h %s -p %s -U %s -d %s > %s 2>&1',
        escapeshellarg($_ENV['POSTGRES_PASSWORD'] ?? 'pulse_password'),
        escapeshellarg($host),
        escapeshellarg($port),
        escapeshellarg($username),
        escapeshellarg($database),
        escapeshellarg($filepath)
    );
    
    // Выполнение команды
    $output = [];
    $returnCode = 0;
    exec($command, $output, $returnCode);
    
    if ($returnCode !== 0) {
        throw new Exception('Ошибка при создании дампа: ' . implode("\n", $output));
    }
    
    // Проверка, что файл создан и не пустой
    if (!file_exists($filepath) || filesize($filepath) === 0) {
        throw new Exception('Файл бэкапа не был создан или пустой');
    }
    
    // Сжатие файла (опционально)
    $gzippedFile = $filepath . '.gz';
    if (function_exists('gzencode')) {
        $sqlContent = file_get_contents($filepath);
        $compressedContent = gzencode($sqlContent, 9);
        if (file_put_contents($gzippedFile, $compressedContent)) {
            unlink($filepath); // Удаляем несжатый файл
            $filepath = $gzippedFile;
            $filename .= '.gz';
        }
    }
    
    // Логирование успешного создания бэкапа
    error_log("Создан бэкап базы данных: $filename");
    
    echo json_encode([
        'success' => true,
        'message' => 'Резервная копия создана успешно',
        'filename' => $filename,
        'size' => filesize($filepath),
        'path' => $filepath
    ]);
    
} catch (Exception $e) {
    error_log('Ошибка при создании резервной копии: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка при создании резервной копии: ' . $e->getMessage()
    ]);
}
?> 
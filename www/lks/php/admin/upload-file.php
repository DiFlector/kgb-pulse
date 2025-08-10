<?php
/**
 * API для загрузки файлов в файловый менеджер
 * Администратор - Загрузка файлов
 */
session_start();

// Проверка авторизации
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Не авторизован']);
    exit;
}

// Проверка прав администратора
if (!isset($_SESSION['user_role']) || ($_SESSION['user_role'] !== 'Admin' && $_SESSION['user_role'] !== 'SuperUser')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
    exit;
}

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

try {
    // Проверка метода запроса
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Метод не поддерживается');
    }

    // Проверка данных
    if (!isset($_POST['folder']) || empty($_POST['folder'])) {
        throw new Exception('Не указана папка назначения');
    }

    if (!isset($_FILES['files']) || empty($_FILES['files']['name'][0])) {
        throw new Exception('Файлы не выбраны');
    }

    $folder = $_POST['folder'];
    
    // Разрешенные папки
    $allowedFolders = [
        'excel', 'pdf', 'results', 'protocol', 
        'template', 'polojenia', 'sluzebnoe'
    ];

    if (!in_array($folder, $allowedFolders)) {
        throw new Exception('Недопустимая папка назначения');
    }

    // Путь к папке
    $uploadDir = __DIR__ . '/../../files/' . $folder . '/';
    
    // Создаем папку если не существует
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            throw new Exception('Не удалось создать папку назначения');
        }
    }

    // Проверяем доступность на запись (мягкая проверка: не прерываем, а логируем)
    if (!is_writable($uploadDir)) {
        @chmod($uploadDir, 0775);
        clearstatcache();
        if (!is_writable($uploadDir)) {
            error_log('Warning: upload dir is not writable by check: ' . $uploadDir);
        }
    }

    $uploadedFiles = [];
    $errors = [];

    // Обработка каждого файла
    $fileCount = count($_FILES['files']['name']);
    
    for ($i = 0; $i < $fileCount; $i++) {
        $fileName = $_FILES['files']['name'][$i];
        $fileTmpName = $_FILES['files']['tmp_name'][$i];
        $fileSize = $_FILES['files']['size'][$i];
        $fileError = $_FILES['files']['error'][$i];

        // Проверка на ошибки загрузки
        if ($fileError !== UPLOAD_ERR_OK) {
            $errors[] = "Ошибка загрузки файла: $fileName";
            continue;
        }

        // Проверка размера файла (максимум 50MB)
        if ($fileSize > 50 * 1024 * 1024) {
            $errors[] = "Файл $fileName слишком большой (максимум 50MB)";
            continue;
        }

        // Получение расширения файла
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        // Разрешенные расширения для каждой папки
        // ВАЖНО: в шаблонах допускаем также csv (например, technical_results.csv)
        $allowedExtensions = [
            'excel' => ['xls', 'xlsx', 'csv'],
            'pdf' => ['pdf'],
            'results' => ['txt', 'json', 'xml', 'csv'],
            'protocol' => ['pdf', 'doc', 'docx'],
            'template' => ['xls', 'xlsx', 'csv'],
            'polojenia' => ['pdf', 'doc', 'docx'],
            'sluzebnoe' => ['txt', 'log', 'json', 'xml']
        ];

        if (!in_array($fileExtension, $allowedExtensions[$folder])) {
            $errors[] = "Недопустимое расширение файла: $fileName";
            continue;
        }

        // Генерация безопасного имени файла
        $safeFileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
        $finalPath = $uploadDir . $safeFileName;

        // Проверка на существование файла
        if (file_exists($finalPath)) {
            $pathInfo = pathinfo($safeFileName);
            $baseName = $pathInfo['filename'];
            $extension = $pathInfo['extension'] ?? '';
            $counter = 1;
            
            do {
                $newFileName = $baseName . "_$counter";
                if ($extension) {
                    $newFileName .= ".$extension";
                }
                $finalPath = $uploadDir . $newFileName;
                $counter++;
            } while (file_exists($finalPath));
            
            $safeFileName = $newFileName;
        }

        // Перемещение файла
        if (move_uploaded_file($fileTmpName, $finalPath)) {
            $uploadedFiles[] = [
                'original_name' => $fileName,
                'saved_name' => $safeFileName,
                'size' => $fileSize,
                'folder' => $folder
            ];
            
            // Логирование действия
            error_log("Admin uploaded file: $safeFileName to folder: $folder");
        } else {
            $lastError = error_get_last();
            $reason = $lastError['message'] ?? 'неизвестная причина';
            $errors[] = "Не удалось сохранить файл: $fileName (" . $reason . ")";
        }
    }

    // Формирование ответа
    $uploadedCount = count($uploadedFiles);
    $response = [
        'uploaded' => $uploadedCount,
        'total' => $fileCount,
        'files' => $uploadedFiles
    ];

    if (!empty($errors)) {
        $response['errors'] = $errors;
        if ($uploadedCount === 0) {
            $response['success'] = false;
            $response['message'] = 'Не удалось загрузить файлы';
        } else {
            $response['success'] = true;
            $response['message'] = 'Частично загружено: ' . $uploadedCount . ' из ' . $fileCount;
        }
    } else {
        $response['success'] = true;
        $response['message'] = 'Все файлы успешно загружены';
    }

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    error_log("File upload error: " . $e->getMessage());
}
?> 
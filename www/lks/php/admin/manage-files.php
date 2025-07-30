<?php
/**
 * API для управления файлами в файловом менеджере
 * Администратор - Операции с файлами
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

    // Получение данных
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }

    if (!isset($input['action'])) {
        throw new Exception('Не указано действие');
    }

    $action = $input['action'];
    
    // Разрешенные папки
    $allowedFolders = [
        'excel', 'pdf', 'results', 'protocol', 
        'template', 'polojenia', 'sluzebnoe'
    ];

    switch ($action) {
        case 'rename':
            handleRename($input, $allowedFolders);
            break;
            
        case 'delete':
            handleDelete($input, $allowedFolders);
            break;
            
        case 'clear_folder':
            handleClearFolder($input, $allowedFolders);
            break;
            
        case 'create_folder':
            handleCreateFolder($input);
            break;
            
        case 'rename_folder':
            handleRenameFolder($input, $allowedFolders);
            break;
            
        case 'delete_folder':
            handleDeleteFolder($input, $allowedFolders);
            break;
            
        default:
            throw new Exception('Неизвестное действие');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    error_log("File management error: " . $e->getMessage());
}

/**
 * Переименование файла
 */
function handleRename($input, $allowedFolders) {
    if (!isset($input['folder']) || !isset($input['old_name']) || !isset($input['new_name'])) {
        throw new Exception('Недостаточно данных для переименования');
    }

    $folder = $input['folder'];
    $oldName = $input['old_name'];
    $newName = $input['new_name'];

    if (!in_array($folder, $allowedFolders)) {
        throw new Exception('Недопустимая папка');
    }

    // Безопасное имя файла
    $safeNewName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $newName);
    
    $folderPath = __DIR__ . '/../../files/' . $folder . '/';
    $oldPath = $folderPath . $oldName;
    $newPath = $folderPath . $safeNewName;

    if (!file_exists($oldPath)) {
        throw new Exception('Файл не найден');
    }

    if (file_exists($newPath)) {
        throw new Exception('Файл с таким именем уже существует');
    }

    if (rename($oldPath, $newPath)) {
        echo json_encode([
            'success' => true,
            'message' => 'Файл успешно переименован',
            'old_name' => $oldName,
            'new_name' => $safeNewName
        ]);
        
        error_log("Admin renamed file: $oldName to $safeNewName in folder: $folder");
    } else {
        throw new Exception('Не удалось переименовать файл');
    }
}

/**
 * Удаление файла
 */
function handleDelete($input, $allowedFolders) {
    if (!isset($input['folder']) || !isset($input['filename'])) {
        throw new Exception('Недостаточно данных для удаления');
    }

    $folder = $input['folder'];
    $filename = $input['filename'];

    if (!in_array($folder, $allowedFolders)) {
        throw new Exception('Недопустимая папка');
    }

    $filePath = __DIR__ . '/../../files/' . $folder . '/' . $filename;

    if (!file_exists($filePath)) {
        throw new Exception('Файл не найден');
    }

    if (unlink($filePath)) {
        echo json_encode([
            'success' => true,
            'message' => 'Файл успешно удален',
            'filename' => $filename,
            'folder' => $folder
        ]);
        
        error_log("Admin deleted file: $filename from folder: $folder");
    } else {
        throw new Exception('Не удалось удалить файл');
    }
}

/**
 * Очистка папки
 */
function handleClearFolder($input, $allowedFolders) {
    if (!isset($input['folder'])) {
        throw new Exception('Не указана папка для очистки');
    }

    $folder = $input['folder'];

    if (!in_array($folder, $allowedFolders)) {
        throw new Exception('Недопустимая папка');
    }

    $folderPath = __DIR__ . '/../../files/' . $folder . '/';

    if (!is_dir($folderPath)) {
        throw new Exception('Папка не найдена');
    }

    $files = array_diff(scandir($folderPath), array('.', '..'));
    $deletedCount = 0;
    $errors = [];

    foreach ($files as $file) {
        $filePath = $folderPath . $file;
        if (is_file($filePath)) {
            if (unlink($filePath)) {
                $deletedCount++;
            } else {
                $errors[] = $file;
            }
        }
    }

    $response = [
        'success' => true,
        'message' => "Удалено файлов: $deletedCount",
        'deleted_count' => $deletedCount,
        'folder' => $folder
    ];

    if (!empty($errors)) {
        $response['errors'] = $errors;
        $response['message'] .= '. Ошибки при удалении: ' . count($errors);
    }

    echo json_encode($response);
    error_log("Admin cleared folder: $folder, deleted: $deletedCount files");
}

/**
 * Создание новой папки
 */
function handleCreateFolder($input) {
    if (!isset($input['folder_name'])) {
        throw new Exception('Не указано имя папки');
    }

    $folderName = $input['folder_name'];
    $description = $input['description'] ?? '';

    // Безопасное имя папки
    $safeFolderName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $folderName);
    
    if (empty($safeFolderName)) {
        throw new Exception('Недопустимое имя папки');
    }

    $folderPath = __DIR__ . '/../../files/' . $safeFolderName . '/';

    if (is_dir($folderPath)) {
        throw new Exception('Папка с таким именем уже существует');
    }

    if (mkdir($folderPath, 0755, true)) {
        // Создаем файл описания
        if ($description) {
            file_put_contents($folderPath . 'README.txt', $description);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Папка успешно создана',
            'folder_name' => $safeFolderName,
            'description' => $description
        ]);
        
        error_log("Admin created folder: $safeFolderName");
    } else {
        throw new Exception('Не удалось создать папку');
    }
}

/**
 * Переименование папки
 */
function handleRenameFolder($input, $allowedFolders) {
    if (!isset($input['old_name']) || !isset($input['new_name'])) {
        throw new Exception('Недостаточно данных для переименования папки');
    }

    $oldName = $input['old_name'];
    $newName = $input['new_name'];

    if (!in_array($oldName, $allowedFolders)) {
        throw new Exception('Нельзя переименовать системную папку');
    }

    // Безопасное имя папки
    $safeNewName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $newName);
    
    if (empty($safeNewName)) {
        throw new Exception('Недопустимое имя папки');
    }

    $oldPath = __DIR__ . '/../../files/' . $oldName . '/';
    $newPath = __DIR__ . '/../../files/' . $safeNewName . '/';

    if (!is_dir($oldPath)) {
        throw new Exception('Папка не найдена');
    }

    if (is_dir($newPath)) {
        throw new Exception('Папка с таким именем уже существует');
    }

    if (rename($oldPath, $newPath)) {
        echo json_encode([
            'success' => true,
            'message' => 'Папка успешно переименована',
            'old_name' => $oldName,
            'new_name' => $safeNewName
        ]);
        
        error_log("Admin renamed folder: $oldName to $safeNewName");
    } else {
        throw new Exception('Не удалось переименовать папку');
    }
}

/**
 * Удаление папки со всем содержимым
 */
function handleDeleteFolder($input, $allowedFolders) {
    if (!isset($input['folder'])) {
        throw new Exception('Не указана папка для удаления');
    }

    $folder = $input['folder'];

    if (!in_array($folder, $allowedFolders)) {
        throw new Exception('Нельзя удалить системную папку');
    }

    $folderPath = __DIR__ . '/../../files/' . $folder . '/';

    if (!is_dir($folderPath)) {
        throw new Exception('Папка не найдена');
    }

    // Рекурсивное удаление папки и всего содержимого
    if (deleteDirectory($folderPath)) {
        echo json_encode([
            'success' => true,
            'message' => 'Папка полностью удалена',
            'folder' => $folder
        ]);
        
        error_log("Admin deleted folder: $folder");
    } else {
        throw new Exception('Не удалось удалить папку');
    }
}

/**
 * Рекурсивное удаление директории
 */
function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return false;
    }

    $files = array_diff(scandir($dir), array('.', '..'));
    
    foreach ($files as $file) {
        $filePath = $dir . $file;
        if (is_dir($filePath)) {
            deleteDirectory($filePath . '/');
        } else {
            unlink($filePath);
        }
    }
    
    return rmdir($dir);
}
?> 
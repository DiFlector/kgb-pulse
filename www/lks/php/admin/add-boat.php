<?php
/**
 * API для добавления новой лодки в ENUM
 */

session_start();

// Проверка авторизации
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Admin' && $_SESSION['user_role'] !== 'SuperUser')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

require_once '../db/Database.php';

// Получаем данные из POST
$input = json_decode(file_get_contents('php://input'), true);

$boatCode = $input['boat_code'] ?? '';
$boatName = $input['boat_name'] ?? '';
$boatCapacity = $input['boat_capacity'] ?? '';
$boatCategory = $input['boat_category'] ?? '';

if (empty($boatCode) || empty($boatName) || empty($boatCapacity) || empty($boatCategory)) {
    echo json_encode(['success' => false, 'message' => 'Не все обязательные поля заполнены']);
    exit;
}

// Проверяем формат кода лодки
if (!preg_match('/^[A-Z]{1,2}-[0-9]{1,2}$/', $boatCode)) {
    echo json_encode(['success' => false, 'message' => 'Неверный формат кода лодки. Используйте формат: буквы-цифра (например: K-1, C-4)']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Проверяем, существует ли уже такая лодка
    $checkQuery = "
        SELECT COUNT(*) 
        FROM pg_enum 
        WHERE enumtypid = (SELECT oid FROM pg_type WHERE typname = 'boats')
        AND enumlabel = ?
    ";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([$boatCode]);
    
    if ($checkStmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'Класс лодки "' . $boatCode . '" уже существует']);
        exit;
    }
    
    // Добавляем новое значение в ENUM
    $addEnumQuery = "ALTER TYPE boats ADD VALUE ?";
    $addEnumStmt = $db->prepare($addEnumQuery);
    $addEnumStmt->execute([$boatCode]);
    
    // Создаем директорию для данных, если её нет
    $dataDir = __DIR__ . '/../../files/boat_description';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }
    
    // Сохраняем описание в файл
    $descriptionsFile = $dataDir . '/boat_descriptions.json';
    $descriptions = [];
    
    if (file_exists($descriptionsFile)) {
        $content = file_get_contents($descriptionsFile);
        if ($content) {
            $descriptions = json_decode($content, true) ?: [];
        }
    }
    
    // Добавляем новое описание
    $descriptions[$boatCode] = $boatName;
    
    // Сохраняем обратно в файл
    if (!file_put_contents($descriptionsFile, json_encode($descriptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        // Если не удалось сохранить описание, откатываем добавление ENUM
        $removeEnumQuery = "ALTER TYPE boats DROP VALUE ?";
        $removeEnumStmt = $db->prepare($removeEnumQuery);
        $removeEnumStmt->execute([$boatCode]);
        
        echo json_encode(['success' => false, 'message' => 'Ошибка при сохранении описания лодки']);
        exit;
    }
    
    // Логируем действие
    $logQuery = "
        INSERT INTO user_actions (users_oid, action, ip_address, created_at)
        VALUES (?, ?, ?, NOW())
    ";
    $logStmt = $db->prepare($logQuery);
    $logStmt->execute([
        $_SESSION['user_id'],
        "Добавлен новый класс лодки: $boatCode - $boatName (категория: $boatCategory, вместимость: $boatCapacity)",
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Класс лодки "' . $boatCode . '" успешно добавлен'
    ]);
    
} catch (Exception $e) {
    error_log('Error in add-boat.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера: ' . $e->getMessage()]);
}
?> 
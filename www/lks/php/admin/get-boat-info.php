<?php
/**
 * API для получения информации о лодке
 */

session_start();

// Проверка авторизации
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Admin' && $_SESSION['user_role'] !== 'SuperUser')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

require_once '../db/Database.php';

// Получаем код лодки
$boatCode = $_GET['boat'] ?? '';

if (empty($boatCode)) {
    echo json_encode(['success' => false, 'message' => 'Код лодки не указан']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Проверяем, существует ли лодка в ENUM
    $checkQuery = "
        SELECT COUNT(*) 
        FROM pg_enum 
        WHERE enumtypid = (SELECT oid FROM pg_type WHERE typname = 'boats')
        AND enumlabel = ?
    ";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([$boatCode]);
    
    if ($checkStmt->fetchColumn() == 0) {
        echo json_encode(['success' => false, 'message' => 'Класс лодки не найден']);
        exit;
    }
    
    // Загружаем описание из файла
    $descriptionsFile = __DIR__ . '/../../files/boat_description/boat_descriptions.json';
    $description = '';
    $name = $boatCode;
    
    if (file_exists($descriptionsFile)) {
        $content = file_get_contents($descriptionsFile);
        if ($content) {
            $descriptions = json_decode($content, true);
            if (isset($descriptions[$boatCode])) {
                $description = $descriptions[$boatCode];
            }
        }
    }
    
    // Если нет описания, генерируем дефолтное
    if (empty($description)) {
        require_once '../helpers.php';
        $description = generateBoatDescription($boatCode);
    }
    
    // Получаем статистику использования
    $usageQuery = "SELECT COUNT(*) FROM users WHERE boats @> ARRAY[?]::boats[]";
    $usageStmt = $db->prepare($usageQuery);
    $usageStmt->execute([$boatCode]);
    $usageCount = $usageStmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'boat' => [
            'code' => $boatCode,
            'name' => $name,
            'description' => $description,
            'usage_count' => $usageCount
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Error in get-boat-info.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера']);
}
?> 
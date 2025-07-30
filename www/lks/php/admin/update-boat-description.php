<?php
/**
 * API для обновления описания лодки
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
$description = $input['description'] ?? '';

if (empty($boatCode) || empty($description)) {
    echo json_encode(['success' => false, 'message' => 'Не все обязательные поля заполнены']);
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
    
    // Создаем директорию для данных, если её нет
    $dataDir = __DIR__ . '/../../files/boat_description';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }
    
    // Загружаем существующие описания
    $descriptionsFile = $dataDir . '/boat_descriptions.json';
    $descriptions = [];
    
    if (file_exists($descriptionsFile)) {
        $content = file_get_contents($descriptionsFile);
        if ($content) {
            $descriptions = json_decode($content, true) ?: [];
        }
    }
    
    // Обновляем описание
    $descriptions[$boatCode] = $description;
    
    // Сохраняем обратно в файл
    if (file_put_contents($descriptionsFile, json_encode($descriptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        // Логируем действие
        $logQuery = "
            INSERT INTO user_actions (users_oid, action, ip_address, created_at)
            VALUES (?, ?, ?, NOW())
        ";
        $logStmt = $db->prepare($logQuery);
        $logStmt->execute([
            $_SESSION['user_id'],
            "Обновлено описание лодки: $boatCode - $description",
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Описание успешно обновлено']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Ошибка при сохранении описания']);
    }
    
} catch (Exception $e) {
    error_log('Error in update-boat-description.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера']);
}
?> 
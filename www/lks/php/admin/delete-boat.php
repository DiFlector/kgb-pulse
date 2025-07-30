<?php
/**
 * API для удаления лодки из ENUM
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
    
    // Проверяем, используется ли лодка спортсменами
    $usersQuery = "SELECT COUNT(*) FROM users WHERE boats @> ARRAY[?]::boats[]";
    $usersStmt = $db->prepare($usersQuery);
    $usersStmt->execute([$boatCode]);
    $usersCount = $usersStmt->fetchColumn();
    
    if ($usersCount > 0) {
        echo json_encode([
            'success' => false, 
            'message' => "Невозможно удалить класс лодки '$boatCode'. Он используется $usersCount спортсменами. Сначала удалите все ссылки на этот класс."
        ]);
        exit;
    }
    
    // Проверяем, используется ли лодка в регистрациях
    $regsQuery = "SELECT COUNT(*) FROM listreg WHERE discipline::text LIKE ?";
    $regsStmt = $db->prepare($regsQuery);
    $regsStmt->execute(['%"' . $boatCode . '"%']);
    $regsCount = $regsStmt->fetchColumn();
    
    if ($regsCount > 0) {
        echo json_encode([
            'success' => false, 
            'message' => "Невозможно удалить класс лодки '$boatCode'. Он используется в $regsCount регистрациях. Сначала удалите все ссылки на этот класс."
        ]);
        exit;
    }
    
    // Проверяем, используется ли лодка в мероприятиях
    $eventsQuery = "SELECT COUNT(*) FROM meros WHERE class_distance::text LIKE ?";
    $eventsStmt = $db->prepare($eventsQuery);
    $eventsStmt->execute(['%"' . $boatCode . '"%']);
    $eventsCount = $eventsStmt->fetchColumn();
    
    if ($eventsCount > 0) {
        echo json_encode([
            'success' => false, 
            'message' => "Невозможно удалить класс лодки '$boatCode'. Он используется в $eventsCount мероприятиях. Сначала удалите все ссылки на этот класс."
        ]);
        exit;
    }
    
    // Удаляем значение из ENUM
    // В PostgreSQL нельзя напрямую удалить значение из ENUM, если оно используется
    // Поэтому создаем новый тип без этого значения
    
    // Получаем все значения ENUM кроме удаляемого
    $enumValuesQuery = "
        SELECT enumlabel 
        FROM pg_enum 
        WHERE enumtypid = (SELECT oid FROM pg_type WHERE typname = 'boats')
        AND enumlabel != ?
        ORDER BY enumsortorder
    ";
    $enumValuesStmt = $db->prepare($enumValuesQuery);
    $enumValuesStmt->execute([$boatCode]);
    $remainingValues = $enumValuesStmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($remainingValues)) {
        echo json_encode(['success' => false, 'message' => 'Невозможно удалить последний класс лодки']);
        exit;
    }
    
    // Начинаем транзакцию
    $db->beginTransaction();
    
    try {
        // Создаем новый тип ENUM
        $newEnumValues = "'" . implode("', '", $remainingValues) . "'";
        $createNewEnumQuery = "CREATE TYPE boats_new AS ENUM ($newEnumValues)";
        $db->exec($createNewEnumQuery);
        
        // Обновляем существующие данные
        // Обновляем массив boats в таблице users
        $updateUsersQuery = "
            UPDATE users 
            SET boats = array_remove(boats, ?::boats_new)
            WHERE boats @> ARRAY[?]::boats[]
        ";
        $updateUsersStmt = $db->prepare($updateUsersQuery);
        $updateUsersStmt->execute([$boatCode, $boatCode]);
        
        // Удаляем старый тип и переименовываем новый
        $db->exec("DROP TYPE boats");
        $db->exec("ALTER TYPE boats_new RENAME TO boats");
        
        // Удаляем описание из файла
        $descriptionsFile = __DIR__ . '/../../files/boat_description/boat_descriptions.json';
        if (file_exists($descriptionsFile)) {
            $content = file_get_contents($descriptionsFile);
            if ($content) {
                $descriptions = json_decode($content, true);
                if (isset($descriptions[$boatCode])) {
                    unset($descriptions[$boatCode]);
                    file_put_contents($descriptionsFile, json_encode($descriptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
            }
        }
        
        // Логируем действие
        $logQuery = "
            INSERT INTO user_actions (users_oid, action, ip_address, created_at)
            VALUES (?, ?, ?, NOW())
        ";
        $logStmt = $db->prepare($logQuery);
        $logStmt->execute([
            $_SESSION['user_id'],
            "Удален класс лодки: $boatCode",
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        $db->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Класс лодки "' . $boatCode . '" успешно удален'
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log('Error in delete-boat.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера: ' . $e->getMessage()]);
}
?> 
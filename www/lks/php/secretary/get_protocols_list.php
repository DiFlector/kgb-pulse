<?php
/**
 * Получение списка протоколов мероприятия
 */

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/protocol_utils.php";

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['meroId'])) {
        throw new Exception('Неверные параметры запроса');
    }
    
    $meroId = $input['meroId'];
    
    // Получаем список файлов протоколов
    $protocolFiles = getProtocolFilesForEvent($meroId);
    
    if (empty($protocolFiles)) {
        echo json_encode([
            'success' => true,
            'protocols' => [],
            'message' => 'Протоколы не найдены. Проведите жеребьевку для создания протоколов.'
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'protocols' => $protocolFiles,
            'count' => count($protocolFiles)
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 
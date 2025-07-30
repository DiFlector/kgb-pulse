<?php
/**
 * API для скачивания протоколов в формате Excel
 * Файл: www/lks/php/secretary/download_protocol_new.php
 */

require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/ProtocolManagerNew.php";
require_once __DIR__ . "/../common/ExcelGenerator.php";

try {
    // Проверяем авторизацию
    $auth = new Auth();
    if (!$auth->isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Не авторизован']);
        exit;
    }

    // Проверяем права секретаря
    if (!$auth->hasRole('Secretary') && !$auth->hasRole('Admin') && !$auth->hasRole('SuperUser')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
        exit;
    }

    // Получаем параметры
    $redisKey = $_GET['redisKey'] ?? null;
    $protocolType = $_GET['type'] ?? 'start'; // 'start' или 'finish'
    
    if (!$redisKey) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Не указан ключ протокола']);
        exit;
    }

    // Получаем протокол
    $protocolManager = new ProtocolManagerNew();
    $protocol = $protocolManager->getProtocol($redisKey);
    
    if (!$protocol) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Протокол не найден']);
        exit;
    }

    // Проверяем, что протокол готов для скачивания
    if (empty($protocol['participants'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Протокол пуст. Сначала проведите жеребьевку.']);
        exit;
    }

    // Добавляем флаг типа протокола
    $protocol['isFinish'] = ($protocolType === 'finish');
    
    // Генерируем Excel файл
    $excelGenerator = new ExcelGenerator();
    $excelFile = $excelGenerator->generateProtocolExcel($protocol);
    
    if (!$excelFile || !file_exists($excelFile)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Ошибка генерации файла']);
        exit;
    }

    // Формируем имя файла
    $protocolTypeText = $protocolType === 'finish' ? 'финишный' : 'стартовый';
    $filename = "Протокол_{$protocolTypeText}_{$protocol['class']}_{$protocol['sex']}_{$protocol['distance']}м_{$protocol['ageGroup']}.xlsx";
    
    // Устанавливаем заголовки для скачивания
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($excelFile));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    
    // Отправляем файл
    readfile($excelFile);
    
    // Удаляем временный файл
    unlink($excelFile);
    
} catch (Exception $e) {
    error_log("❌ [DOWNLOAD_PROTOCOL_NEW] Ошибка: " . $e->getMessage());
    
    // Если заголовки еще не отправлены, отправляем JSON ошибку
    if (!headers_sent()) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Ошибка сервера: ' . $e->getMessage()
        ]);
    }
}
?> 
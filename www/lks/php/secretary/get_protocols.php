<?php
/**
 * Получение списка существующих протоколов
 * Файл: www/lks/php/secretary/get_protocols.php
 * Обновлено: переход с GET на POST для безопасности
 */

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../helpers.php";

// Включаем CORS и устанавливаем JSON заголовки (только если заголовки еще не отправлены)
if (!headers_sent()) {
    if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');
    if (!defined('TEST_MODE')) header('Access-Control-Allow-Origin: *');
    if (!defined('TEST_MODE')) header('Access-Control-Allow-Methods: POST, OPTIONS');
    if (!defined('TEST_MODE')) header('Access-Control-Allow-Headers: Content-Type');
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    error_log("🔍 [GET_PROTOCOLS] Запрос на получение протоколов");
    
    // Проверяем метод запроса
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Метод не поддерживается. Используйте POST.');
    }
    
    // Читаем JSON данные из тела запроса
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Неверный формат JSON данных');
    }
    
    // Получаем параметры из JSON
    $meroId = intval($input['meroId'] ?? 0);
    $disciplinesJson = $input['disciplines'] ?? '';
    
    if ($meroId <= 0) {
        throw new Exception('Неверный ID мероприятия');
    }
    
    if (empty($disciplinesJson)) {
        throw new Exception('Не переданы дисциплины');
    }
    
    $disciplines = json_decode($disciplinesJson, true);
    if (!is_array($disciplines)) {
        throw new Exception('Неверный формат дисциплин');
    }
    
    error_log("🔍 [GET_PROTOCOLS] Мероприятие: $meroId, дисциплин: " . count($disciplines));
    
    // Папка с протоколами
    $protocolDir = '/var/www/html/lks/files/protocol/';
    $webPath = '/lks/files/protocol/';
    
    $startProtocols = [];
    $finishProtocols = [];
    
    foreach ($disciplines as $discipline) {
        $class = $discipline['class'] ?? '';
        $sex = $discipline['sex'] ?? '';
        $distance = $discipline['distance'] ?? '';
        
        if (empty($class) || empty($sex) || empty($distance)) {
            continue;
        }
        
        // Формируем имена файлов
        $startFileName = "start_{$meroId}_{$class}_{$sex}_{$distance}.xlsx";
        $finishFileName = "finish_{$meroId}_{$class}_{$sex}_{$distance}.xlsx";
        
        $startFilePath = $protocolDir . $startFileName;
        $finishFilePath = $protocolDir . $finishFileName;
        
        // Проверяем существование стартового протокола
        if (file_exists($startFilePath)) {
            $participantsCount = getParticipantsCountFromFile($startFilePath);
            $startProtocols[] = [
                'discipline' => $class,
                'sex' => $sex,
                'distance' => $distance,
                'participantsCount' => $participantsCount,
                'file' => $webPath . $startFileName,
                'created' => date('Y-m-d H:i:s', filemtime($startFilePath))
            ];
            error_log("🔍 [GET_PROTOCOLS] Найден стартовый протокол: $startFileName");
        }
        
        // Проверяем существование финишного протокола
        if (file_exists($finishFilePath)) {
            $participantsCount = getParticipantsCountFromFile($finishFilePath);
            $finishProtocols[] = [
                'discipline' => $class,
                'sex' => $sex,
                'distance' => $distance,
                'participantsCount' => $participantsCount,
                'file' => $webPath . $finishFileName,
                'created' => date('Y-m-d H:i:s', filemtime($finishFilePath))
            ];
            error_log("🔍 [GET_PROTOCOLS] Найден финишный протокол: $finishFileName");
        }
    }
    
    $result = [
        'success' => true,
        'startProtocols' => $startProtocols,
        'finishProtocols' => $finishProtocols,
        'totalStart' => count($startProtocols),
        'totalFinish' => count($finishProtocols)
    ];
    
    error_log("🔍 [GET_PROTOCOLS] Найдено протоколов: старт=" . count($startProtocols) . ", финиш=" . count($finishProtocols));
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("🔍 [GET_PROTOCOLS] Ошибка: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Получение количества участников из Excel файла
 */
function getParticipantsCountFromFile($filePath) {
    try {
        // Простая проверка - читаем размер файла как индикатор количества данных
        if (!file_exists($filePath)) {
            return 0;
        }
        
        $fileSize = filesize($filePath);
        
        // Примерная оценка: файл больше 10KB = есть данные
        if ($fileSize > 10240) {
            // Можно здесь добавить более точный подсчет через PhpSpreadsheet
            return "~"; // Показываем что есть данные
        }
        
        return 0;
        
    } catch (Exception $e) {
        error_log("🔍 [GET_PROTOCOLS] Ошибка чтения файла $filePath: " . $e->getMessage());
        return 0;
    }
}
?> 
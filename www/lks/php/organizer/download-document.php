<?php
/**
 * API для скачивания документов мероприятий
 * Организатор - Скачивание положений, протоколов, результатов
 */

if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo 'Доступ запрещен: требуется авторизация';
    exit;
}

// Проверка прав доступа
$allowedRoles = ['Organizer', 'SuperUser', 'Admin', 'Secretary'];
if (!in_array($_SESSION['user_role'], $allowedRoles)) {
    http_response_code(403);
    echo 'Доступ запрещен: недостаточно прав';
    exit;
}

require_once __DIR__ . "/../db/Database.php";

try {
    $db = Database::getInstance();
    
    // Получение параметров
    $eventId = $_GET['event_id'] ?? 0;
    $docType = $_GET['type'] ?? '';
    
    if (empty($eventId) || empty($docType)) {
        throw new Exception('Не указаны обязательные параметры');
    }
    
    // Получение информации о мероприятии
    $stmt = $db->prepare("
        SELECT champn, meroname, filepolojenie, fileprotokol, fileresults, created_by
        FROM meros 
        WHERE champn = ?
    ");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$event) {
        throw new Exception('Мероприятие не найдено');
    }
    
    // Проверка прав доступа к мероприятию
    if ($_SESSION['user_role'] === 'Organizer' && $event['created_by'] != $_SESSION['user_id']) {
        throw new Exception('Доступ запрещен: вы не являетесь организатором этого мероприятия');
    }
    
    // Определение файла для скачивания
    $filePath = '';
    $fileName = '';
    
    switch ($docType) {
        case 'polojenie':
            $filePath = $event['filepolojenie'];
            $fileName = 'Положение_' . transliterate($event['meroname']) . '.pdf';
            break;
            
        case 'protokol':
            $filePath = $event['fileprotokol'];
            $fileName = 'Протокол_' . transliterate($event['meroname']) . '.xlsx';
            break;
            
        case 'results':
            $filePath = $event['fileresults'];
            $fileName = 'Результаты_' . transliterate($event['meroname']) . '.xlsx';
            break;
            
        default:
            throw new Exception('Неизвестный тип документа');
    }
    
    if (empty($filePath)) {
        throw new Exception('Документ не загружен');
    }
    
    // Полный путь к файлу (относительно каталога lks)
    $fullPath = dirname(__DIR__, 2) . '/' . ltrim($filePath, '/');
    
    if (!file_exists($fullPath)) {
        throw new Exception('Файл не найден на сервере');
    }
    
    // Определение MIME типа
    $mimeType = mime_content_type($fullPath);
    if (!$mimeType) {
        $extension = pathinfo($fullPath, PATHINFO_EXTENSION);
        switch (strtolower($extension)) {
            case 'pdf':
                $mimeType = 'application/pdf';
                break;
            case 'xlsx':
                $mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                break;
            case 'xls':
                $mimeType = 'application/vnd.ms-excel';
                break;
            case 'doc':
                $mimeType = 'application/msword';
                break;
            case 'docx':
                $mimeType = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                break;
            default:
                $mimeType = 'application/octet-stream';
        }
    }
    
    // Логирование скачивания
    error_log("Скачивание документа: пользователь {$_SESSION['user_id']}, мероприятие {$eventId}, тип {$docType}, файл {$filePath}");
    
    // Установка заголовков для скачивания
    if (!defined('TEST_MODE')) header('Content-Type: ' . $mimeType);
    if (!defined('TEST_MODE')) header('Content-Disposition: attachment; filename="' . $fileName . '"');
    if (!defined('TEST_MODE')) header('Content-Length: ' . filesize($fullPath));
    if (!defined('TEST_MODE')) header('Cache-Control: must-revalidate');
    if (!defined('TEST_MODE')) header('Pragma: public');
    if (!defined('TEST_MODE')) header('Expires: 0');
    
    // Очистка буфера вывода
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Отправка файла
    readfile($fullPath);
    exit;
    
} catch (Exception $e) {
    error_log("Ошибка скачивания документа: " . $e->getMessage());
    http_response_code(404);
    echo 'Ошибка: ' . $e->getMessage();
}

/**
 * Транслитерация русских символов для имени файла
 */
function transliterate($text) {
    $translitMap = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sch', 'ь' => '', 'ы' => 'y', 'ъ' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D',
        'Е' => 'E', 'Ё' => 'Yo', 'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I',
        'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N',
        'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T',
        'У' => 'U', 'Ф' => 'F', 'Х' => 'H', 'Ц' => 'C', 'Ч' => 'Ch',
        'Ш' => 'Sh', 'Щ' => 'Sch', 'Ь' => '', 'Ы' => 'Y', 'Ъ' => '',
        'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya'
    ];
    
    $result = strtr($text, $translitMap);
    $result = preg_replace('/[^a-zA-Z0-9_-]/', '_', $result);
    $result = preg_replace('/_+/', '_', $result);
    $result = trim($result, '_');
    
    return $result;
}
?> 
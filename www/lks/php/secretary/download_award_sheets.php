<?php
/**
 * API для скачивания наградных ведомостей
 * Файл: www/lks/php/secretary/download_award_sheets.php
 */

require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/protocol_numbering.php";

try {
    // Проверяем авторизацию
    $auth = new Auth();
    if (!$auth->isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Не авторизован']);
        exit;
    }

    // Проверяем права секретаря
    if (!$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
        exit;
    }

    // Получаем данные из POST запроса
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['meroId'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Не указан ID мероприятия']);
        exit;
    }

    $meroId = intval($input['meroId']);

    // Получаем информацию о мероприятии
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT * FROM meros WHERE champn = ?");
    $stmt->execute([$meroId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Мероприятие не найдено']);
        exit;
    }

    $classDistance = json_decode($event['class_distance'], true);
    
    if (!$classDistance) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Некорректная структура дисциплин']);
        exit;
    }

    // Подключаемся к Redis
    $redis = new Redis();
    try {
        $connected = $redis->connect('redis', 6379, 5);
        if (!$connected) {
            throw new Exception('Не удалось подключиться к Redis');
        }
    } catch (Exception $e) {
        error_log("Ошибка подключения к Redis: " . $e->getMessage());
        $redis = null;
    }

    // Создаем временную директорию для файлов
    $tempDir = sys_get_temp_dir() . '/award_sheets_' . $meroId . '_' . time();
    if (!mkdir($tempDir, 0777, true)) {
        throw new Exception('Не удалось создать временную директорию');
    }

    // Получаем все протоколы мероприятия
    $protocols = ProtocolNumbering::getProtocolsStructure($classDistance, $_SESSION['selected_disciplines'] ?? null);
    
    $awardSheets = [];
    
    foreach ($protocols as $protocol) {
        $key = ProtocolNumbering::getProtocolKey($meroId, $protocol['class'], $protocol['sex'], $protocol['distance'], $protocol['ageGroup']);
        
        if ($redis) {
            $protocolData = $redis->get($key);
            
            if ($protocolData) {
                $data = json_decode($protocolData, true);
                
                if ($data && isset($data['participants'])) {
                    // Создаем наградную ведомость только для протоколов с результатами
                    $hasResults = false;
                    foreach ($data['participants'] as $participant) {
                        if (isset($participant['place']) && is_numeric($participant['place']) && 
                            isset($participant['finishTime']) && !empty($participant['finishTime'])) {
                            $hasResults = true;
                            break;
                        }
                    }
                    
                    if ($hasResults) {
                        $awardSheetContent = generateAwardSheet($event, $protocol, $data['participants']);
                        $fileName = "award_sheet_{$protocol['number']}_{$protocol['class']}_{$protocol['sex']}_{$protocol['distance']}.html";
                        $filePath = $tempDir . '/' . $fileName;
                        
                        if (file_put_contents($filePath, $awardSheetContent)) {
                            $awardSheets[] = [
                                'path' => $filePath,
                                'name' => $fileName,
                                'protocol' => $protocol
                            ];
                        }
                    }
                }
            }
        }
    }

    if (empty($awardSheets)) {
        throw new Exception('Нет данных для создания наградных ведомостей');
    }

    // Создаем ZIP архив
    $zipPath = $tempDir . '/award_sheets.zip';
    $zip = new ZipArchive();
    
    if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
        throw new Exception('Не удалось создать ZIP архив');
    }
    
    foreach ($awardSheets as $sheet) {
        $zip->addFile($sheet['path'], $sheet['name']);
    }
    
    $zip->close();
    
    // Отправляем архив
    if (file_exists($zipPath)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="award_sheets_' . $meroId . '.zip"');
        header('Content-Length: ' . filesize($zipPath));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        
        readfile($zipPath);
        
        // Удаляем временные файлы
        foreach ($awardSheets as $sheet) {
            if (file_exists($sheet['path'])) {
                unlink($sheet['path']);
            }
        }
        if (file_exists($zipPath)) {
            unlink($zipPath);
        }
        if (is_dir($tempDir)) {
            rmdir($tempDir);
        }
    } else {
        throw new Exception('Ошибка создания ZIP архива');
    }

} catch (Exception $e) {
    error_log("Ошибка создания наградных ведомостей: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка создания наградных ведомостей: ' . $e->getMessage()
    ]);
}

/**
 * Генерация наградной ведомости для протокола
 */
function generateAwardSheet($event, $protocol, $participants) {
    // Сортируем участников по месту
    usort($participants, function($a, $b) {
        $placeA = isset($a['place']) && is_numeric($a['place']) ? intval($a['place']) : 999;
        $placeB = isset($b['place']) && is_numeric($b['place']) ? intval($b['place']) : 999;
        return $placeA <=> $placeB;
    });
    
    // Фильтруем только участников с местами 1, 2, 3
    $winners = array_filter($participants, function($participant) {
        return isset($participant['place']) && is_numeric($participant['place']) && 
               intval($participant['place']) >= 1 && intval($participant['place']) <= 3;
    });
    
    if (empty($winners)) {
        return '';
    }
    
    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Наградная ведомость</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { text-align: center; margin-bottom: 30px; }
            .event-info { margin-bottom: 20px; }
            .protocol-info { margin-bottom: 20px; font-weight: bold; }
            .winners { margin-top: 30px; }
            .winner { margin-bottom: 20px; padding: 15px; border: 2px solid #ddd; border-radius: 5px; }
            .gold { border-color: #FFD700; background-color: #FFF8DC; }
            .silver { border-color: #C0C0C0; background-color: #F5F5F5; }
            .bronze { border-color: #CD7F32; background-color: #FFF8DC; }
            .place { font-size: 24px; font-weight: bold; margin-bottom: 10px; }
            .participant-info { margin-bottom: 5px; }
            .medal-icon { font-size: 20px; margin-right: 10px; }
        </style>
    </head>
    <body>';
    
    $html .= '<div class="header">
        <h1>НАГРАДНАЯ ВЕДОМОСТЬ</h1>
        <h2>' . htmlspecialchars($event['meroname']) . '</h2>
    </div>';
    
    $html .= '<div class="event-info">
        <p><strong>Дата проведения:</strong> ' . htmlspecialchars($event['merodata']) . '</p>
        <p><strong>Номер мероприятия:</strong> ' . htmlspecialchars($event['champn']) . '</p>
    </div>';
    
    $html .= '<div class="protocol-info">
        Протокол №' . $protocol['number'] . ' - ' . 
        htmlspecialchars($protocol['class']) . ' ' . 
        htmlspecialchars($protocol['sex']) . ' ' . 
        htmlspecialchars($protocol['distance']) . 'м - ' . 
        htmlspecialchars($protocol['ageGroup']['name']) . '
    </div>';
    
    $html .= '<div class="winners">';
    
    foreach ($winners as $participant) {
        $place = intval($participant['place']);
        $placeClass = '';
        $medalIcon = '';
        
        if ($place === 1) {
            $placeClass = 'gold';
            $medalIcon = '🥇';
        } elseif ($place === 2) {
            $placeClass = 'silver';
            $medalIcon = '🥈';
        } elseif ($place === 3) {
            $placeClass = 'bronze';
            $medalIcon = '🥉';
        }
        
        $html .= '<div class="winner ' . $placeClass . '">
            <div class="place">
                <span class="medal-icon">' . $medalIcon . '</span>
                ' . $place . ' место
            </div>
            <div class="participant-info">
                <strong>Номер спортсмена:</strong> ' . (isset($participant['userId']) ? $participant['userId'] : '-') . '
            </div>
            <div class="participant-info">
                <strong>ФИО:</strong> ' . htmlspecialchars($participant['fio'] ?? '-') . '
            </div>
            <div class="participant-info">
                <strong>Время:</strong> ' . htmlspecialchars($participant['finishTime'] ?? '-') . '
            </div>';
        
        if (isset($participant['teamName']) && !empty($participant['teamName'])) {
            $html .= '<div class="participant-info">
                <strong>Команда:</strong> ' . htmlspecialchars($participant['teamName']) . '
            </div>';
        }
        
        $html .= '</div>';
    }
    
    $html .= '</div></body></html>';
    
    return $html;
}
?> 
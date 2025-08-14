<?php
/**
 * API для скачивания итогового протокола
 * Файл: www/lks/php/secretary/download_final_protocol.php
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
    // МАССИВ front-end секретаря передаёт oid (внутренний ключ), поэтому выбираем по oid
    $stmt = $db->prepare("SELECT * FROM meros WHERE oid = ?");
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

    // Получаем все протоколы мероприятия
    $protocols = ProtocolNumbering::getProtocolsStructure($classDistance, $_SESSION['selected_disciplines'] ?? null);
    
    // Собираем данные для итогового протокола
    $finalProtocolData = [
        'event' => [
            'name' => $event['meroname'],
            'date' => $event['merodata'],
            'number' => $event['champn']
        ],
        'protocols' => []
    ];

    foreach ($protocols as $protocol) {
        $key = ProtocolNumbering::getProtocolKey($meroId, $protocol['class'], $protocol['sex'], $protocol['distance'], $protocol['ageGroup']);
        
        if ($redis) {
            $protocolData = $redis->get($key);
            
            if ($protocolData) {
                $data = json_decode($protocolData, true);
                
                if ($data && isset($data['participants'])) {
                    $finalProtocolData['protocols'][] = [
                        'number' => $protocol['number'],
                        'class' => $protocol['class'],
                        'sex' => $protocol['sex'],
                        'distance' => $protocol['distance'],
                        'ageGroup' => $protocol['ageGroup']['name'],
                        'displayName' => $protocol['displayName'],
                        'participants' => $data['participants']
                    ];
                }
            }
        }
    }

    // Создаем PDF документ
    $pdfContent = generateFinalProtocolPDF($finalProtocolData);
    
    // Устанавливаем заголовки для скачивания
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="final_protocol_' . $meroId . '.pdf"');
    header('Content-Length: ' . strlen($pdfContent));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    
    echo $pdfContent;

} catch (Exception $e) {
    error_log("Ошибка создания итогового протокола: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка создания итогового протокола: ' . $e->getMessage()
    ]);
}

/**
 * Генерация PDF итогового протокола
 */
function generateFinalProtocolPDF($data) {
    // Простая реализация PDF с использованием HTML
    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Итоговый протокол</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { text-align: center; margin-bottom: 30px; }
            .event-info { margin-bottom: 20px; }
            .protocol { margin-bottom: 30px; page-break-inside: avoid; }
            .protocol-title { font-weight: bold; margin-bottom: 10px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; }
            .place { font-weight: bold; }
            .gold { color: #FFD700; }
            .silver { color: #C0C0C0; }
            .bronze { color: #CD7F32; }
        </style>
    </head>
    <body>';
    
    $html .= '<div class="header">
        <h1>ИТОГОВЫЙ ПРОТОКОЛ</h1>
        <h2>' . htmlspecialchars($data['event']['name']) . '</h2>
    </div>';
    
    $html .= '<div class="event-info">
        <p><strong>Дата проведения:</strong> ' . htmlspecialchars($data['event']['date']) . '</p>
        <p><strong>Номер мероприятия:</strong> ' . htmlspecialchars($data['event']['number']) . '</p>
    </div>';
    
    foreach ($data['protocols'] as $protocol) {
        $html .= '<div class="protocol">
            <div class="protocol-title">
                Протокол №' . $protocol['number'] . ' - ' . 
                htmlspecialchars($protocol['class']) . ' ' . 
                htmlspecialchars($protocol['sex']) . ' ' . 
                htmlspecialchars($protocol['distance']) . 'м - ' . 
                htmlspecialchars($protocol['ageGroup']) . '
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Место</th>
                        <th>Номер</th>
                        <th>ФИО</th>
                        <th>Время</th>
                        <th>Команда</th>
                    </tr>
                </thead>
                <tbody>';
        
        // Сортируем участников по месту
        usort($protocol['participants'], function($a, $b) {
            $placeA = isset($a['place']) && is_numeric($a['place']) ? intval($a['place']) : 999;
            $placeB = isset($b['place']) && is_numeric($b['place']) ? intval($b['place']) : 999;
            return $placeA <=> $placeB;
        });
        
        foreach ($protocol['participants'] as $participant) {
            $placeClass = '';
            if (isset($participant['place'])) {
                if ($participant['place'] == 1) $placeClass = 'gold';
                elseif ($participant['place'] == 2) $placeClass = 'silver';
                elseif ($participant['place'] == 3) $placeClass = 'bronze';
            }
            
            $html .= '<tr>
                <td class="place ' . $placeClass . '">' . (isset($participant['place']) ? $participant['place'] : '-') . '</td>
                <td>' . (isset($participant['userId']) ? $participant['userId'] : '-') . '</td>
                <td>' . htmlspecialchars($participant['fio'] ?? '-') . '</td>
                <td>' . htmlspecialchars($participant['finishTime'] ?? '-') . '</td>
                <td>' . htmlspecialchars($participant['teamName'] ?? '-') . '</td>
            </tr>';
        }
        
        $html .= '</tbody></table></div>';
    }
    
    $html .= '</body></html>';
    
    // В реальной реализации здесь нужно использовать библиотеку для создания PDF
    // Например, TCPDF, mPDF или wkhtmltopdf
    // Пока возвращаем HTML, который можно конвертировать в PDF
    
    return $html;
}
?> 
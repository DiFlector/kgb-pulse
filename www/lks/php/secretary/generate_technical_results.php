<?php
/**
 * Генерация файла технических результатов по шаблону technical_results.xlsx
 * без отдачи файла в ответ. Обновляет meros.fileresults путём сохранения
 * относительного пути к файлу для дальнейшего скачивания.
 */

require_once __DIR__ . '/../common/Auth.php';
require_once __DIR__ . '/../db/Database.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/protocol_numbering.php';
require_once __DIR__ . '/../common/JsonProtocolManager.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Авторизация и права
    $auth = new Auth();
    if (!$auth->isAuthenticated() || !$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Доступ запрещён']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $champn = isset($input['champn']) ? (int)$input['champn'] : 0;
    if ($champn <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Не указан champn мероприятия']);
        exit;
    }

    $db = Database::getInstance();
    $pdo = $db->getPDO();

    // Получаем мероприятие по champn (внешний идентификатор)
    $stmt = $pdo->prepare('SELECT oid, champn, meroname, merodata, class_distance FROM meros WHERE champn = ?');
    $stmt->execute([$champn]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Мероприятие не найдено']);
        exit;
    }

    // Инициализация Redis (необязателен)
    $redis = null;
    try {
        if (class_exists('Redis')) {
            $redis = new Redis();
            $redis->connect('redis', 6379, 3);
        }
    } catch (Throwable $e) {
        $redis = null; // продолжаем без Redis
    }

    // Готовим PHPExcel (библиотека из includes)
    $phpexcelPath = __DIR__ . '/../../includes/phpexcel/PHPExcel.php';
    if (!file_exists($phpexcelPath)) {
        // Тихий выход без ошибки 500, чтобы не засорять консоль
        echo json_encode(['success' => false, 'message' => 'PHPExcel не установлен. Генерация пропущена.']);
        exit;
    }
    require_once $phpexcelPath;

    // Загружаем шаблон
    $templatePath = __DIR__ . '/../../files/template/technical_results.xlsx';
    if (!file_exists($templatePath)) {
        throw new Exception('Не найден шаблон technical_results.xlsx');
    }
    $excel = PHPExcel_IOFactory::load($templatePath);
    $sheet = $excel->getActiveSheet();

    // Шапка
    $sheet->setCellValue('A1', 'ТЕХНИЧЕСКИЕ РЕЗУЛЬТАТЫ');
    $sheet->setCellValue('A2', (string)$event['meroname']);
    $sheet->setCellValue('A3', (string)$event['merodata']);

    $currentRow = 6;

    // Собираем данные групп из JSON протоколов, при отсутствии — пробуем Redis
    $groups = [];
    $classDistance = json_decode($event['class_distance'] ?? '[]', true) ?: [];
    $protocols = ProtocolNumbering::getProtocolsStructure($classDistance, $_SESSION['selected_disciplines'] ?? null);
    $jsonManager = JsonProtocolManager::getInstance();

    foreach ($protocols as $protocol) {
        $ageGroupName = isset($protocol['ageGroup']['full_name']) ? $protocol['ageGroup']['full_name'] : $protocol['ageGroup']['name'];
        $groupKey = implode('_', [$protocol['class'], $protocol['sex'], $protocol['distance'], $ageGroupName]);
        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'title' => trim("{$protocol['class']} {$protocol['sex']} {$protocol['distance']}м — {$ageGroupName}"),
                'class' => $protocol['class'],
                'sex' => $protocol['sex'],
                'distance' => $protocol['distance'],
                'ageGroup' => $ageGroupName,
                'participants' => []
            ];
        }

        // Пробуем загрузить протокол из JSON
        $jsonKey = "protocol:{$champn}:{$protocol['class']}:{$protocol['sex']}:{$protocol['distance']}:{$ageGroupName}";
        $data = $jsonManager->loadProtocol($jsonKey);

        // Если JSON не найден — пробуем Redis (обратная совместимость)
        if (!$data && $redis) {
            try {
                $redisKey = ProtocolNumbering::getProtocolKey($champn, $protocol['class'], $protocol['sex'], $protocol['distance'], $ageGroupName);
                $raw = $redis->get($redisKey);
                $data = $raw ? json_decode($raw, true) : null;
            } catch (Throwable $e) {
                $data = null;
            }
        }

        if (!$data || empty($data['participants'])) {
            continue;
        }

        foreach ($data['participants'] as $p) {
            $groups[$groupKey]['participants'][] = [
                'place' => $p['place'] ?? null,
                'fio' => $p['fio'] ?? '',
                'birthYear' => $p['birthYear'] ?? ($p['birthdate'] ?? ''),
                'ageGroup' => $p['ageGroup'] ?? $ageGroupName,
                'team' => $p['team'] ?? ($p['teamName'] ?? ''),
                'city' => $p['city'] ?? '',
                'semifinalTime' => $p['semifinalTime'] ?? null,
                'finalTime' => $p['time'] ?? ($p['finishTime'] ?? null),
            ];
        }
    }

    ksort($groups);

    // Выгрузка в Excel
    foreach ($groups as $group) {
        // Заголовок дисциплины
        $sheet->setCellValue("A{$currentRow}", $group['title']);
        $sheet->getStyle("A{$currentRow}")->getFont()->setBold(true);
        $currentRow++;

        // Шапка таблицы
        $headers = ['Место', 'ФИО', 'Год рождения', 'Группа', 'Спорт. организация', 'Город', 'Время прох. П.Ф.', 'Время прох. Финал'];
        $col = 'A';
        foreach ($headers as $h) {
            $sheet->setCellValue($col . $currentRow, $h);
            $sheet->getStyle($col . $currentRow)->getFont()->setBold(true);
            $col++;
        }
        $currentRow++;

        usort($group['participants'], function ($a, $b) {
            $pa = is_numeric($a['place'] ?? null) ? (int)$a['place'] : 9999;
            $pb = is_numeric($b['place'] ?? null) ? (int)$b['place'] : 9999;
            return $pa <=> $pb;
        });

        foreach ($group['participants'] as $p) {
            $sheet->setCellValue("A{$currentRow}", $p['place']);
            $sheet->setCellValue("B{$currentRow}", $p['fio']);
            // Год рождения: поддержка разных форматов
            $birthYear = '';
            if (!empty($p['birthYear'])) {
                $birthYear = is_numeric($p['birthYear']) ? (string)$p['birthYear'] : date('Y', strtotime($p['birthYear']));
            }
            $sheet->setCellValue("C{$currentRow}", $birthYear);
            $sheet->setCellValue("D{$currentRow}", $p['ageGroup']);
            $sheet->setCellValue("E{$currentRow}", $p['team'] ?: '-');
            $sheet->setCellValue("F{$currentRow}", $p['city']);
            $sheet->setCellValue("G{$currentRow}", $p['semifinalTime'] ?: '-');
            $sheet->setCellValue("H{$currentRow}", $p['finalTime'] ?: '-');
            $currentRow++;
        }

        // Отступ между группами
        $currentRow += 2;
    }

    // Подписи
    $currentRow += 2;
    $sheet->setCellValue("A{$currentRow}", 'Главный судья соревнований');
    $currentRow += 2;
    $sheet->setCellValue("A{$currentRow}", 'Судья всероссийской категории');
    $currentRow += 2;
    $sheet->setCellValue("A{$currentRow}", 'Главный секретарь');

    // Имя файла и сохранение
    $safeName = transliterate($event['meroname']);
    $filename = 'technical_results_' . $safeName . '_' . date('Y-m-d') . '.xlsx';
    $resultsDir = __DIR__ . '/../../files/results/';
    if (!is_dir($resultsDir)) {
        @mkdir($resultsDir, 0775, true);
    }
    $absPath = $resultsDir . $filename;

    $writer = PHPExcel_IOFactory::createWriter($excel, 'Excel2007');
    $writer->save($absPath);

    // Сохраняем в БД относительный путь для фронта
    $relPath = '/lks/files/results/' . $filename;
    $upd = $pdo->prepare('UPDATE meros SET fileresults = ? WHERE champn = ?');
    $upd->execute([$relPath, $champn]);

    echo json_encode(['success' => true, 'file' => $relPath]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>


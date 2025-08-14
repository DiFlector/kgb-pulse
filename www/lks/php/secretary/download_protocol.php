<?php
/**
 * Скачивание протокола в формате CSV
 * Файл: www/lks/php/secretary/download_protocol.php
 * Обновлено: поддержка новой системы JSON файлов и формат CSV
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

// Вспомогательные функции для работы с датами и временем
function extractYearFromBirthdate($birthdate) {
    if (empty($birthdate)) return '';
    $date = DateTime::createFromFormat('Y-m-d', $birthdate);
    return $date ? $date->format('Y') : '';
}

function extractMinutesFromTime($time) {
    if (empty($time)) return '';
    $parts = explode(':', $time);
    return isset($parts[0]) ? $parts[0] : '';
}

function extractSecondsFromTime($time) {
    if (empty($time)) return '';
    $parts = explode(':', $time);
    return isset($parts[1]) ? $parts[1] : '';
}

session_start();

// Проверка авторизации и прав доступа
require_once '../common/Auth.php';
$auth = new Auth();

if (!$auth->isAuthenticated()) {
    http_response_code(403);
    echo 'Доступ запрещен. Пользователь не авторизован.';
    exit();
}

if (!$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
    http_response_code(403);
    echo 'Доступ запрещен. Требуются права Secretary, SuperUser или Admin.';
    exit();
}

// Получение параметров
$groupKey = $_GET['group_key'] ?? null;
$meroId = $_GET['mero_id'] ?? null;
$protocolType = $_GET['protocol_type'] ?? 'start';

if (!$groupKey || !$meroId) {
    http_response_code(400);
    echo 'Не указаны необходимые параметры';
    exit();
}

try {
    require_once '../db/Database.php';
    require_once '../common/JsonProtocolManager.php';

    $db = Database::getInstance();
    $protocolManager = JsonProtocolManager::getInstance();

    // Получение информации о мероприятии
    // ВАЖНО: секретарь передаёт внутренний ID (oid)
    $stmt = $db->prepare("SELECT meroname, merodata FROM meros WHERE oid = ?");
    $stmt->execute([$meroId]);
    $mero = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$mero) {
        http_response_code(404);
        echo 'Мероприятие не найдено';
        exit();
    }

    // Загружаем данные протокола из JSON файла
    $protocolData = $protocolManager->loadProtocol($groupKey);
    
    if (!$protocolData) {
        http_response_code(404);
        echo 'Протокол не найден';
        exit();
    }

    // Проверяем полноту финишного протокола
    if ($protocolType === 'finish') {
        $isComplete = true;
        foreach ($protocolData['participants'] as $participant) {
            if (empty($participant['place']) || empty($participant['finishTime'])) {
                $isComplete = false;
                break;
            }
        }
        if (!$isComplete) {
            http_response_code(400);
            echo 'Финишный протокол не заполнен полностью';
            exit();
        }
    }

    // Формируем CSV данные
    $csvData = [];
    $raceNumber = 1;
    $discipline = $protocolData['discipline'] . ' ' . $protocolData['distance'] . 'м ' . $protocolData['sex'];
    $ageGroup = $protocolData['ageGroup'];

    $isDragon = ($protocolData['discipline'] === 'D-10');

    $csvData[] = [$raceNumber . ';;' . $discipline . ';;;' . $ageGroup . ';;;;'];

    if ($isDragon) {
        // Таблица для D-10: на уровне команд
        if ($protocolType === 'start') {
            $csvData[] = ['СТАРТ;Время заезда;Вода;-;Название команды;Город команды;Возрастная группа команды'];
        } else {
            $csvData[] = ['ФИНИШ;Место в заезде;Вода;-;Время прохождения;Минуты;Секунды;Название команды;Город команды;Возрастная группа команды'];
        }

        // Группируем участников по командам
        $teams = [];
        foreach ($protocolData['participants'] as $p) {
            $teamKey = $p['teamId'] ?? ($p['team_id'] ?? (($p['teamCity'] ?? '') . '|' . ($p['teamName'] ?? '')));
            if (!isset($teams[$teamKey])) {
                $teams[$teamKey] = [
                    'lane' => $p['lane'] ?? ($p['water'] ?? ''),
                    'teamName' => $p['teamName'] ?? ($p['teamname'] ?? ''),
                    'teamCity' => $p['teamCity'] ?? ($p['teamcity'] ?? ''),
                    'place' => $p['place'] ?? '',
                    'finishTime' => $p['finishTime'] ?? '',
                    'teamAgeGroup' => $p['teamAgeGroupLabel'] ?? ($p['ageGroupLabel'] ?? $ageGroup),
                ];
            } else {
                if ($teams[$teamKey]['lane'] === '' && isset($p['lane'])) $teams[$teamKey]['lane'] = $p['lane'];
                if ($teams[$teamKey]['place'] === '' && isset($p['place'])) $teams[$teamKey]['place'] = $p['place'];
                if ($teams[$teamKey]['finishTime'] === '' && isset($p['finishTime'])) $teams[$teamKey]['finishTime'] = $p['finishTime'];
            }
        }

        foreach ($teams as $team) {
            if ($protocolType === 'start') {
                $csvData[] = [';;' . ($team['lane'] ?? '') . ';-;' . ($team['teamName'] ?? '') . ';' . ($team['teamCity'] ?? '') . ';' . ($team['teamAgeGroup'] ?? $ageGroup)];
            } else {
                $time = $team['finishTime'] ?? '';
                $csvData[] = [';' . ($team['place'] ?? '') . ';' . ($team['lane'] ?? '') . ';-;' . $time . ';' . extractMinutesFromTime($time) . ';' . extractSecondsFromTime($time) . ';' . ($team['teamName'] ?? '') . ';' . ($team['teamCity'] ?? '') . ';' . ($team['teamAgeGroup'] ?? $ageGroup)];
            }
            // Добавляем состав команды строками ниже
            $csvData[] = [';;;;Номер спортсмена;ФИО;Год рождения;Спортивный разряд'];
            foreach ($team['members'] as $member) {
                $csvData[] = [';;;;' .
                    ($member['userId'] ?? ($member['userid'] ?? '')) . ';' .
                    ($member['fio'] ?? '') . ';' .
                    extractYearFromBirthdate($member['birthdata'] ?? '') . ';' .
                    ($member['sportzvanie'] ?? '')
                ];
            }
        }
    } else {
        // Обычная лодка
        if ($protocolType === 'start') {
            $csvData[] = ['СТАРТ;Время заезда;Вода;-;Номер;ФИО;Год рождения;Группа;Спортивный разряд;Спорт.организация'];
        } else {
            $csvData[] = ['ФИНИШ;Место в заезде;Вода;-;Время прохождения;Минуты;Секунды;Номер;ФИО;Год рождения;Группа;Спортивный разряд;Спорт.организация'];
        }
        $maxLanes = 10;
        for ($lane = 1; $lane <= $maxLanes; $lane++) {
            $participant = null;
            foreach ($protocolData['participants'] as $p) {
                if (($p['lane'] ?? 0) == $lane) { $participant = $p; break; }
            }
            if ($participant) {
                if ($protocolType === 'start') {
                    $csvData[] = [';;' . $lane . ';-;' . ($participant['userId'] ?? '') . ';' . ($participant['fio'] ?? '') . ';' . extractYearFromBirthdate($participant['birthdata'] ?? '') . ';' . $ageGroup . ';' . ($participant['sportzvanie'] ?? 'Б/р') . ';' . ($participant['city'] ?? 'Москва')];
                } else {
                    $csvData[] = [';' . ($participant['place'] ?? '') . ';' . $lane . ';-;' . ($participant['finishTime'] ?? '') . ';' . extractMinutesFromTime($participant['finishTime'] ?? '') . ';' . extractSecondsFromTime($participant['finishTime'] ?? '') . ';' . ($participant['userId'] ?? '') . ';' . ($participant['fio'] ?? '') . ';' . extractYearFromBirthdate($participant['birthdata'] ?? '') . ';' . $ageGroup . ';' . ($participant['sportzvanie'] ?? 'Б/р') . ';' . ($participant['city'] ?? 'Москва')];
                }
            } else {
                $csvData[] = [$protocolType === 'start' ? (';;' . $lane . ';-;;;;;;') : (';;' . $lane . ';-;;;;;;;')];
            }
        }
    }

    // Очищаем буфер вывода и отправляем заголовки
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="protocol_' . $groupKey . '_' . $protocolType . '.csv"');
    header('Cache-Control: max-age=0');
    if (function_exists('mb_internal_encoding')) mb_internal_encoding('UTF-8');

    // Добавляем BOM для совместимости с Excel
    echo "\xEF\xBB\xBF";

    // Выводим строки
    foreach ($csvData as $row) {
        echo $row[0] . "\r\n";
    }
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo 'Ошибка создания протокола: ' . $e->getMessage();
}
?> 
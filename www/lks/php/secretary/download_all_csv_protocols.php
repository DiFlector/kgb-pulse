<?php
/**
 * Скачивание всех протоколов в формате CSV одним файлом
 * Файл: www/lks/php/secretary/download_all_csv_protocols.php
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
require_once __DIR__ . '/../common/Auth.php';
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
$meroId = $_GET['mero_id'] ?? null;
$protocolType = $_GET['protocol_type'] ?? 'start';

if (!$meroId) {
    http_response_code(400);
    echo 'Не указан ID мероприятия';
    exit();
}

try {
    require_once __DIR__ . '/../db/Database.php';
    require_once __DIR__ . '/../common/JsonProtocolManager.php';

    $db = Database::getInstance();
    $protocolManager = JsonProtocolManager::getInstance();

    // Получение информации о мероприятии
    // ВАЖНО: в секретаре мы передаём внутренний идентификатор мероприятия (oid)
    $stmt = $db->prepare("SELECT meroname, merodata FROM meros WHERE oid = ?");
    $stmt->execute([$meroId]);
    $mero = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$mero) {
        http_response_code(404);
        echo 'Мероприятие не найдено';
        exit();
    }

    // Получаем все протоколы для мероприятия
    $allProtocols = $protocolManager->getEventProtocols($meroId);
    
    if (empty($allProtocols)) {
        http_response_code(404);
        echo 'Протоколы не найдены';
        exit();
    }

    // Фильтруем протоколы по типу и заполненности
    $filteredProtocols = [];
    foreach ($allProtocols as $groupKey => $protocolData) {
        $data = $protocolData['data'] ?? $protocolData;
        
        // Проверяем наличие участников
        if (!isset($data['participants']) || !is_array($data['participants']) || count($data['participants']) === 0) {
            continue; // Пропускаем пустые протоколы
        }

        if ($protocolType === 'finish') {
            // Для финишных протоколов проверяем полноту
            $isComplete = true;
            foreach ($data['participants'] as $participant) {
                if (empty($participant['place']) || empty($participant['finishTime'])) {
                    $isComplete = false;
                    break;
                }
            }
            if (!$isComplete) {
                continue; // Пропускаем незаполненные финишные протоколы
            }
        }

        $filteredProtocols[$groupKey] = $protocolData;
    }

    if (empty($filteredProtocols)) {
        http_response_code(400);
        echo 'Нет протоколов для скачивания';
        exit();
    }

    // Очищаем буфер вывода и отправляем заголовки
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="protocols_' . $protocolType . '_' . $meroId . '.csv"');
    header('Cache-Control: max-age=0');
    if (function_exists('mb_internal_encoding')) mb_internal_encoding('UTF-8');

    // Добавляем BOM для совместимости с Excel
    echo "\xEF\xBB\xBF";

    // Генерируем CSV для каждого протокола
    $raceNumber = 1;
    foreach ($filteredProtocols as $groupKey => $protocolData) {
        // Извлекаем данные из структуры JSON
        $data = $protocolData['data'] ?? $protocolData;
        
        // Извлекаем информацию о дисциплине из redisKey и название группы из data.name
        $redisKey = $data['redisKey'] ?? $groupKey;
        $parts = explode(':', $redisKey);
        $discipline = '';
        $ageGroup = '';
        $boatType = '';
        $sex = '';
        $distance = '';

        if (count($parts) >= 6) {
            // Формат: protocol:<oid>:<boat>:<sex>:<dist>:<age>
            $boatType = $parts[2];
            $sex = $parts[3];
            $distance = $parts[4];
            $discipline = $boatType . ' ' . $distance . 'м ' . $sex;
        }

        // Используем правильное название группы из data.name
        $ageGroup = $data['name'] ?? 'группа';

        $isDragon = ($boatType === 'D-10');

        // Заголовок протокола (строка с номером заезда и описанием)
        if ($protocolType === 'start') {
            echo $raceNumber . ';;' . $discipline . ';;;' . $ageGroup . ';;;;' . "\r\n";
        } else {
            echo $raceNumber . ';;' . $discipline . ';;;;;;' . $ageGroup . ';;;;' . "\r\n";
        }

        if ($isDragon) {
            // Заголовок таблицы для D-10
            if ($protocolType === 'start') {
                echo 'СТАРТ;Время заезда;Вода;-;Название команды;Город команды;Возрастная группа команды' . "\r\n";
            } else {
                echo 'ФИНИШ;Место в заезде;Вода;-;Время прохождения;Минуты;Секунды;Название команды;Город команды;Возрастная группа команды' . "\r\n";
            }

            // Группируем участников по командам и накапливаем их состав
            $teams = [];
            foreach ($data['participants'] as $p) {
                $teamKey = $p['teamId'] ?? ($p['team_id'] ?? (($p['teamCity'] ?? '') . '|' . ($p['teamName'] ?? '')));
                if (!isset($teams[$teamKey])) {
                    $teams[$teamKey] = [
                        'lane' => $p['lane'] ?? ($p['water'] ?? ''),
                        'teamName' => $p['teamName'] ?? ($p['teamname'] ?? ''),
                        'teamCity' => $p['teamCity'] ?? ($p['teamcity'] ?? ''),
                        'place' => $p['place'] ?? '',
                        'finishTime' => $p['finishTime'] ?? '',
                        'teamAgeGroup' => $p['teamAgeGroupLabel'] ?? ($p['ageGroupLabel'] ?? $ageGroup),
                        'members' => []
                    ];
                }
                $teams[$teamKey]['members'][] = $p;
                if ($teams[$teamKey]['lane'] === '' && isset($p['lane'])) $teams[$teamKey]['lane'] = $p['lane'];
                if ($teams[$teamKey]['place'] === '' && isset($p['place'])) $teams[$teamKey]['place'] = $p['place'];
                if ($teams[$teamKey]['finishTime'] === '' && isset($p['finishTime'])) $teams[$teamKey]['finishTime'] = $p['finishTime'];
            }

            foreach ($teams as $team) {
                if ($protocolType === 'start') {
                    $row = ';;' . ($team['lane'] ?? '') . ';-;' .
                           ($team['teamName'] ?? '') . ';' .
                           ($team['teamCity'] ?? '') . ';' .
                           ($team['teamAgeGroup'] ?? $ageGroup);
                } else {
                    $time = $team['finishTime'] ?? '';
                    $row = ';' . ($team['place'] ?? '') . ';' . ($team['lane'] ?? '') . ';-;' .
                           $time . ';' .
                           extractMinutesFromTime($time) . ';' .
                           extractSecondsFromTime($time) . ';' .
                           ($team['teamName'] ?? '') . ';' .
                           ($team['teamCity'] ?? '') . ';' .
                           ($team['teamAgeGroup'] ?? $ageGroup);
                }
                echo $row . "\r\n";

                // Добавляем состав команды строками ниже
                // Подзаголовок состава
                echo ';;;;Номер спортсмена;ФИО;Год рождения;Спортивный разряд' . "\r\n";
                foreach ($team['members'] as $member) {
                    $memberRow = ';;;;' .
                        ($member['userId'] ?? ($member['userid'] ?? '')) . ';' .
                        ($member['fio'] ?? '') . ';' .
                        extractYearFromBirthdate($member['birthdata'] ?? '') . ';' .
                        ($member['sportzvanie'] ?? '');
                    echo $memberRow . "\r\n";
                }
            }
        } else {
            // Обычная одиночная/парная лодка: прежний формат
            if ($protocolType === 'start') {
                echo 'СТАРТ;Время заезда;Вода;-;Номер;ФИО;Год рождения;Группа;Спортивный разряд;Спорт.организация' . "\r\n";
            } else {
                echo 'ФИНИШ;Место в заезде;Вода;-;Время прохождения;Минуты;Секунды;Номер;ФИО;Год рождения;Группа;Спортивный разряд;Спорт.организация' . "\r\n";
            }

            $maxLanes = 10;
            for ($lane = 1; $lane <= $maxLanes; $lane++) {
                $participant = null;
                foreach ($data['participants'] as $p) {
                    if (($p['lane'] ?? 0) == $lane) {
                        $participant = $p;
                        break;
                    }
                }

                if ($participant) {
                    if ($protocolType === 'start') {
                        $row = ';;' . ($lane - 1) . ';-;' .
                               ($participant['userId'] ?? '') . ';' .
                               ($participant['fio'] ?? '') . ';' .
                               extractYearFromBirthdate($participant['birthdata'] ?? '') . ';' .
                               $ageGroup . ';' .
                               ($participant['sportzvanie'] ?? 'Б/р') . ';' .
                               ($participant['city'] ?? 'Москва');
                    } else {
                        $row = ';' . ($participant['place'] ?? '') . ';' . ($lane - 1) . ';-;' .
                               ($participant['finishTime'] ?? '') . ';' .
                               extractMinutesFromTime($participant['finishTime'] ?? '') . ';' .
                               extractSecondsFromTime($participant['finishTime'] ?? '') . ';' .
                               ($participant['userId'] ?? '') . ';' .
                               ($participant['fio'] ?? '') . ';' .
                               extractYearFromBirthdate($participant['birthdata'] ?? '') . ';' .
                               $ageGroup . ';' .
                               ($participant['sportzvanie'] ?? 'Б/р') . ';' .
                               ($participant['city'] ?? 'Москва');
                    }
                    echo $row . "\r\n";
                } else {
                    if ($protocolType === 'start') {
                        echo ';;' . ($lane - 1) . ';-;;;;;;' . "\r\n";
                    } else {
                        echo ';;' . ($lane - 1) . ';-;;;;;;;' . "\r\n";
                    }
                }
            }
        }
        
        $raceNumber++;
    }

    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo 'Ошибка создания протоколов: ' . $e->getMessage();
}
?> 
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
    $stmt = $db->prepare("SELECT meroname, merodata FROM meros WHERE champn = ?");
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
    $csvData[] = [$raceNumber . ';;' . $discipline . ';;;' . $ageGroup . ';;;;'];
    if ($protocolType === 'start') {
        $csvData[] = ['СТАРТ;Время заезда;Вода;-;Номер;ФИО;Год рождения;Группа;Спортивный разряд;Спорт.организация'];
    } else {
        $csvData[] = ['ФИНИШ;Место в заезде;Вода;-;Время прохождения;Минуты;Секунды;Номер;ФИО;Год рождения;Группа;Спортивный разряд;Спорт.организация'];
    }
    $maxLanes = 10;
    for ($lane = 1; $lane <= $maxLanes; $lane++) {
        $participant = null;
        foreach ($protocolData['participants'] as $p) {
            if (($p['lane'] ?? 0) == $lane) {
                $participant = $p;
                break;
            }
        }
        if ($participant) {
            if ($protocolType === 'start') {
                $row = [
                    ';;' . $lane . ';-;' . 
                    ($participant['userId'] ?? '') . ';' . 
                    ($participant['fio'] ?? '') . ';' . 
                    extractYearFromBirthdate($participant['birthdata'] ?? '') . ';' . 
                    $ageGroup . ';' . 
                    ($participant['sportzvanie'] ?? 'Б/р') . ';' . 
                    ($participant['city'] ?? 'Москва')
                ];
            } else {
                $row = [
                    ';' . ($participant['place'] ?? '') . ';' . $lane . ';-;' . 
                    ($participant['finishTime'] ?? '') . ';' . 
                    extractMinutesFromTime($participant['finishTime'] ?? '') . ';' . 
                    extractSecondsFromTime($participant['finishTime'] ?? '') . ';' . 
                    ($participant['userId'] ?? '') . ';' . 
                    ($participant['fio'] ?? '') . ';' . 
                    extractYearFromBirthdate($participant['birthdata'] ?? '') . ';' . 
                    $ageGroup . ';' . 
                    ($participant['sportzvanie'] ?? 'Б/р') . ';' . 
                    ($participant['city'] ?? 'Москва')
                ];
            }
            $csvData[] = $row;
        } else {
            if ($protocolType === 'start') {
                $row = [';;' . $lane . ';-;;;;;;'];
            } else {
                $row = [';;' . $lane . ';-;;;;;;;'];
            }
            $csvData[] = $row;
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
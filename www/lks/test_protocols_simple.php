<?php
/**
 * Упрощенная тестовая страница для отладки протоколов
 * Файл: www/lks/test_protocols_simple.php
 */

require_once __DIR__ . '/php/common/Auth.php';
require_once __DIR__ . '/php/db/Database.php';
require_once __DIR__ . '/php/secretary/protocol_numbering.php';
require_once __DIR__ . '/php/helpers.php';

// Инициализация
$db = Database::getInstance();
$pdo = $db->getPDO();

// Получаем любое существующее мероприятие
$stmt = $pdo->prepare("SELECT * FROM meros ORDER BY oid LIMIT 1");
$stmt->execute();
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    die("Мероприятия не найдены в базе данных");
}

$meroId = $event['champn'];
$classDistance = json_decode($event['class_distance'], true);

echo "<!DOCTYPE html>";
echo "<html><head><title>Тест протоколов</title>";
echo "<script src='https://code.jquery.com/jquery-3.7.1.min.js'></script>";
echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>";
echo "</head><body class='container mt-4'>";

echo "<h1>Тестовая страница отладки протоколов</h1>";
echo "<h2>Мероприятие: {$event['meroname']} (champn: {$meroId}, oid: {$event['oid']})</h2>";

// 1. Получаем структуру протоколов
echo "<h3>1. Структура протоколов:</h3>";
$protocolsStructure = ProtocolNumbering::getProtocolsStructure($meroId, $classDistance);
echo "<p>Найдено протоколов: " . count($protocolsStructure) . "</p>";

// 2. Получаем участников мероприятия
echo "<h3>2. Участники мероприятия:</h3>";
$stmt = $pdo->prepare("
    SELECT u.oid, u.userid, u.fio, u.sex, u.birthdata, u.sportzvanie, 
           t.teamname, lr.discipline
    FROM users u
    LEFT JOIN listreg lr ON u.oid = lr.users_oid
    LEFT JOIN teams t ON lr.teams_oid = t.oid
    WHERE lr.meros_oid = ? AND lr.status IN ('Зарегистрирован', 'Подтверждён')
    ORDER BY u.fio
");
$stmt->execute([$event['oid']]);
$participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Найдено участников: " . count($participants) . "</p>";

// 3. Распределяем участников по группам
echo "<h3>3. Распределение участников по группам:</h3>";
$participantsByGroup = [];

foreach ($participants as $participant) {
    $age = AgeGroupCalculator::calculateAgeOnDecember31($participant['birthdata']);
    $discipline = json_decode($participant['discipline'], true);
    
    if ($discipline) {
        foreach ($discipline as $class => $classData) {
            $sex = $classData['sex'][0] ?? 'М';
            $distances = $classData['dist'][0] ?? '';
            
            if ($distances) {
                $distanceArray = array_map('trim', explode(',', $distances));
                
                foreach ($distanceArray as $distance) {
                    // Находим возрастную группу
                    $ageGroup = calculateAgeGroupFromClassDistance($classDistance, $class, $sex, $age);
                    
                    if ($ageGroup) {
                        $normalizedSex = normalizeSexToEnglish($sex);
                        $groupKey = "{$meroId}_{$class}_{$normalizedSex}_{$distance}_{$ageGroup}";
                        
                        if (!isset($participantsByGroup[$groupKey])) {
                            $participantsByGroup[$groupKey] = [];
                        }
                        
                        $participantsByGroup[$groupKey][] = [
                            'userId' => $participant['userid'],
                            'fio' => $participant['fio'],
                            'age' => $age,
                            'teamName' => $participant['teamname'] ?? 'Н/Д',
                            'sportzvanie' => $participant['sportzvanie'] ?? 'БР'
                        ];
                    }
                }
            }
        }
    }
}

echo "<p>Групп с участниками: " . count($participantsByGroup) . "</p>";
foreach ($participantsByGroup as $groupKey => $groupParticipants) {
    echo "<p><strong>{$groupKey}:</strong> " . count($groupParticipants) . " участников</p>";
}

// 4. Создаем HTML таблицы
echo "<h3>4. Таблицы протоколов:</h3>";
echo "<div id='protocols-container'>";

foreach ($protocolsStructure as $protocol) {
    $class = $protocol['class'];
    $sex = $protocol['sex'];
    $distance = $protocol['distance'];
    $ageGroup = $protocol['ageGroup'];
    
    // Нормализуем пол для groupKey
    $normalizedSex = normalizeSexToEnglish($sex);
    $ageGroupName = $ageGroup['full_name'] ?? $ageGroup['name'];
    $groupKey = "{$meroId}_{$class}_{$normalizedSex}_{$distance}_{$ageGroupName}";
    
    // Получаем участников для этой группы
    $groupParticipants = $participantsByGroup[$groupKey] ?? [];
    
    echo "<div class='protocol-section mb-4'>";
    echo "<h4>{$protocol['displayName']} - {$distance}м</h4>";
    echo "<p><strong>Ключ группы:</strong> {$groupKey}</p>";
    echo "<p><strong>Участников:</strong> " . count($groupParticipants) . "</p>";
    
    echo "<div class='table-responsive'>";
    echo "<table class='table table-striped table-bordered' data-group='{$groupKey}'>";
    echo "<thead class='table-dark'>";
    echo "<tr>";
    echo "<th>№</th>";
    echo "<th>ФИО</th>";
    echo "<th>Команда</th>";
    echo "<th>Возраст</th>";
    echo "<th>Спортивное звание</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody data-group='{$groupKey}'>";
    
    if (count($groupParticipants) > 0) {
        foreach ($groupParticipants as $index => $participant) {
            echo "<tr>";
            echo "<td>" . ($index + 1) . "</td>";
            echo "<td>{$participant['fio']}</td>";
            echo "<td>{$participant['teamName']}</td>";
            echo "<td>{$participant['age']}</td>";
            echo "<td>{$participant['sportzvanie']}</td>";
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='5' class='text-center text-muted'>Нет участников</td></tr>";
    }
    
    echo "</tbody>";
    echo "</table>";
    echo "</div>";
    echo "</div>";
}

echo "</div>";

echo "<script>";
echo "console.log('Страница загружена');";
echo "console.log('Найдено таблиц:', document.querySelectorAll('table[data-group]').length);";
echo "document.querySelectorAll('table[data-group]').forEach(table => {";
echo "    const groupKey = table.dataset.group;";
echo "    const tbody = table.querySelector('tbody[data-group]');";
echo "    console.log('Группа:', groupKey, 'tbody найден:', !!tbody);";
echo "});";
echo "</script>";

echo "</body></html>";
?> 
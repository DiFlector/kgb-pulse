<?php
// Упрощенная версия генерации протоколов без авторизации
require_once '/var/www/html/vendor/autoload.php';

try {
    require_once '/var/www/html/lks/php/db/Database.php';
    
    $db = Database::getInstance();
    
    echo "=== ГЕНЕРАЦИЯ ПРОТОКОЛОВ (УПРОЩЕННАЯ) ===\n";
    
    // Получаем информацию о мероприятии
    $stmt = $db->prepare("SELECT meroname, merodata, class_distance FROM meros WHERE champn = ?");
    $stmt->execute([1]);
    $mero = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$mero) {
        echo "Мероприятие не найдено\n";
        exit;
    }
    
    echo "Мероприятие: {$mero['meroname']}\n";
    echo "Дата: {$mero['merodata']}\n\n";
    
    // Парсим class_distance
    $classDistance = json_decode($mero['class_distance'], true);
    
    if (!$classDistance) {
        echo "Ошибка парсинга class_distance\n";
        exit;
    }
    
    echo "Дисциплины в мероприятии:\n";
    foreach ($classDistance as $discipline => $config) {
        echo "- $discipline\n";
    }
    echo "\n";
    
    // Генерируем протоколы для каждой дисциплины
    $protocolsData = [];
    
    foreach ($classDistance as $discipline => $config) {
        echo "Обрабатываем дисциплину: $discipline\n";
        
        $sexes = $config['sex'] ?? [];
        $distances = $config['dist'] ?? [];
        $ageGroups = $config['age_group'] ?? [];
        
        foreach ($sexes as $sexIndex => $sex) {
            echo "  Пол: $sex\n";
            
            // Получаем дистанции и возрастные группы для данного пола
            $distancesForSex = is_array($distances) ? ($distances[$sexIndex] ?? []) : explode(', ', $distances);
            $ageGroupsForSex = is_array($ageGroups) ? ($ageGroups[$sexIndex] ?? []) : explode(', ', $ageGroups);
            
            // Разбиваем дистанции по запятой
            $distanceArray = explode(', ', $distancesForSex);
            
            foreach ($distanceArray as $distance) {
                $distance = trim($distance);
                echo "    Дистанция: $distance\n";
                
                // Парсим возрастные группы
                $ageGroupStrings = explode(', ', $ageGroupsForSex);
                
                foreach ($ageGroupStrings as $ageGroupString) {
                    if (preg_match('/группа\s+(\w+):\s*(\d+)-(\d+)/', $ageGroupString, $matches)) {
                        $groupName = $matches[1];
                        $minAge = (int)$matches[2];
                        $maxAge = (int)$matches[3];
                        
                        echo "      Возрастная группа: $groupName ($minAge-$maxAge лет)\n";
                        
                        // Получаем участников для этой группы
                        $participants = getParticipantsForGroup($db, 1, $discipline, $sex, $distance, $minAge, $maxAge);
                        
                        echo "        Найдено участников: " . count($participants) . "\n";
                        
                        if (count($participants) > 0) {
                            $protocolsData[] = [
                                'meroId' => 1,
                                'discipline' => $discipline,
                                'sex' => $sex,
                                'distance' => $distance,
                                'ageGroups' => [
                                    [
                                        'name' => "группа $groupName: $minAge-$maxAge",
                                        'protocol_number' => count($protocolsData) + 1,
                                        'participants' => $participants,
                                        'redisKey' => "protocol:start:1:$discipline:$sex:$distance:группа_{$minAge}_{$maxAge}",
                                        'protected' => false,
                                        'maxLanes' => ($discipline === 'D-10') ? 6 : 9
                                    ]
                                ],
                                'created_at' => date('Y-m-d H:i:s')
                            ];
                        }
                    }
                }
            }
        }
    }
    
    echo "\n=== РЕЗУЛЬТАТ ===\n";
    echo "Сгенерировано протоколов: " . count($protocolsData) . "\n\n";
    
    // Проверяем каждый протокол
    foreach ($protocolsData as $protocol) {
        echo "Протокол: {$protocol['discipline']} - {$protocol['distance']}м - {$protocol['sex']}\n";
        echo "Возрастные группы:\n";
        
        foreach ($protocol['ageGroups'] as $ageGroup) {
            echo "  - {$ageGroup['name']}: " . count($ageGroup['participants']) . " участников\n";
            
            if (count($ageGroup['participants']) > 0) {
                echo "    Участники:\n";
                foreach ($ageGroup['participants'] as $participant) {
                    echo "      - {$participant['fio']} (ID: {$participant['userId']})\n";
                }
            }
        }
        echo "\n";
    }
    
    // Сохраняем в JSON файл
    $jsonFile = '/var/www/html/lks/files/json/protocols/protocols_1_simple.json';
    $jsonData = json_encode($protocolsData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
    if (file_put_contents($jsonFile, $jsonData)) {
        echo "JSON файл сохранен: $jsonFile\n";
        echo "Размер файла: " . strlen($jsonData) . " байт\n";
    } else {
        echo "Ошибка сохранения JSON файла\n";
    }
    
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
    echo "Файл: " . $e->getFile() . "\n";
    echo "Строка: " . $e->getLine() . "\n";
}

// Функция для получения участников группы (копия из conduct_draw.php)
function getParticipantsForGroup($db, $meroId, $boatClass, $sex, $distance, $minAge, $maxAge) {
    $currentYear = date('Y');
    $yearEnd = $currentYear . '-12-31';
    
    $sql = "
        SELECT 
            u.oid, u.userid, u.fio, u.sex, u.birthdata, u.sportzvanie, u.city,
            t.teamname, t.teamcity
        FROM users u
        LEFT JOIN listreg lr ON u.oid = lr.users_oid
        LEFT JOIN teams t ON lr.teams_oid = t.oid
        JOIN meros m ON lr.meros_oid = m.oid
        WHERE m.champn = ?
        AND u.sex = ?
        AND u.accessrights = 'Sportsman'
        AND lr.status IN ('Зарегистрирован', 'Подтверждён')
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$meroId, $sex]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $filteredParticipants = [];
    
    foreach ($participants as $participant) {
        // Проверяем возраст
        $birthDate = new DateTime($participant['birthdata']);
        $yearEndDate = new DateTime($yearEnd);
        $age = $yearEndDate->diff($birthDate)->y;
        
        if ($age >= $minAge && $age <= $maxAge) {
            // Проверяем, что участник зарегистрирован на эту дисциплину
            $disciplineSql = "
                SELECT discipline 
                FROM listreg lr
                JOIN meros m ON lr.meros_oid = m.oid
                WHERE lr.users_oid = ? AND m.champn = ?
            ";
            $disciplineStmt = $db->prepare($disciplineSql);
            $disciplineStmt->execute([$participant['oid'], $meroId]);
            $disciplineData = $disciplineStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($disciplineData) {
                $discipline = json_decode($disciplineData['discipline'], true);
                if ($discipline && isset($discipline[$boatClass])) {
                    $filteredParticipants[] = [
                        'userId' => $participant['userid'],
                        'fio' => $participant['fio'],
                        'sex' => $participant['sex'],
                        'birthdata' => $participant['birthdata'],
                        'sportzvanie' => $participant['sportzvanie'],
                        'teamName' => $participant['teamname'] ?? '',
                        'teamCity' => $participant['teamcity'] ?? '',
                        'lane' => null,
                        'place' => null,
                        'finishTime' => null,
                        'addedManually' => false,
                        'addedAt' => date('Y-m-d H:i:s'),
                        'discipline' => $boatClass,
                        'groupKey' => "protocol:start:{$meroId}:{$boatClass}:{$sex}:{$distance}:группа_{$minAge}_{$maxAge}"
                    ];
                }
            }
        }
    }
    
    return $filteredParticipants;
}
?> 
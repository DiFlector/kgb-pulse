<?php
// Тестовый файл для проверки функции getParticipantsForGroup
require_once '/var/www/html/vendor/autoload.php';

try {
    require_once '/var/www/html/lks/php/db/Database.php';
    
    $db = Database::getInstance();
    
    // Тестируем функцию getParticipantsForGroup
    function getParticipantsForGroup($db, $meroId, $boatClass, $sex, $distance, $minAge, $maxAge) {
        $currentYear = date('Y');
        $yearEnd = $currentYear . '-12-31';
        
        echo "=== ПАРАМЕТРЫ ФУНКЦИИ ===\n";
        echo "meroId: $meroId\n";
        echo "boatClass: $boatClass\n";
        echo "sex: $sex\n";
        echo "distance: $distance\n";
        echo "minAge: $minAge\n";
        echo "maxAge: $maxAge\n";
        echo "yearEnd: $yearEnd\n\n";
        
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
        
        echo "SQL запрос:\n$sql\n\n";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$meroId, $sex]);
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Найдено участников: " . count($participants) . "\n\n";
        
        $filteredParticipants = [];
        
        foreach ($participants as $participant) {
            // Проверяем возраст
            $birthDate = new DateTime($participant['birthdata']);
            $yearEndDate = new DateTime($yearEnd);
            $age = $yearEndDate->diff($birthDate)->y;
            
            echo "Участник: {$participant['fio']}, возраст: $age\n";
            
            if ($age >= $minAge && $age <= $maxAge) {
                echo "  -> Подходит по возрасту\n";
                
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
                    echo "  -> Дисциплина найдена: {$disciplineData['discipline']}\n";
                    $discipline = json_decode($disciplineData['discipline'], true);
                    if ($discipline && isset($discipline[$boatClass])) {
                        echo "  -> Подходит по дисциплине $boatClass\n";
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
                    } else {
                        echo "  -> НЕ подходит по дисциплине $boatClass\n";
                    }
                } else {
                    echo "  -> Дисциплина НЕ найдена\n";
                }
            } else {
                echo "  -> НЕ подходит по возрасту\n";
            }
            echo "\n";
        }
        
        echo "Итого отфильтровано участников: " . count($filteredParticipants) . "\n";
        return $filteredParticipants;
    }
    
    // Тестируем функцию
    $participants = getParticipantsForGroup($db, 1, 'K-1', 'М', '200', 11, 12);
    
    echo "\n=== РЕЗУЛЬТАТ ===\n";
    foreach ($participants as $participant) {
        echo "- {$participant['fio']} (ID: {$participant['userId']})\n";
    }
    
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
    echo "Файл: " . $e->getFile() . "\n";
    echo "Строка: " . $e->getLine() . "\n";
}
?> 
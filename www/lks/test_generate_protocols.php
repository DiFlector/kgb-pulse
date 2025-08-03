<?php
// Тестовый файл для проверки генерации протоколов
require_once '/var/www/html/vendor/autoload.php';

try {
    require_once '/var/www/html/lks/php/db/Database.php';
    
    $db = Database::getInstance();
    
    // Проверяем, есть ли участники для мероприятия 1
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM listreg l 
        JOIN users u ON l.users_oid = u.oid 
        JOIN meros m ON l.meros_oid = m.oid 
        WHERE m.champn = 1 
        AND u.accessrights = 'Sportsman' 
        AND l.status IN ('Зарегистрирован', 'Подтверждён')
    ");
    $stmt->execute();
    $totalParticipants = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    echo "Всего участников для мероприятия 1: " . $totalParticipants . "\n";
    
    // Проверяем участников по полу
    $stmt = $db->prepare("
        SELECT u.sex, COUNT(*) as count 
        FROM listreg l 
        JOIN users u ON l.users_oid = u.oid 
        JOIN meros m ON l.meros_oid = m.oid 
        WHERE m.champn = 1 
        AND u.accessrights = 'Sportsman' 
        AND l.status IN ('Зарегистрирован', 'Подтверждён')
        GROUP BY u.sex
    ");
    $stmt->execute();
    $participantsBySex = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Участники по полу:\n";
    foreach ($participantsBySex as $row) {
        echo "- {$row['sex']}: {$row['count']}\n";
    }
    
    // Проверяем участников по возрасту для группы Ю1 (11-12 лет)
    $stmt = $db->prepare("
        SELECT u.fio, u.sex, u.birthdata, 
               EXTRACT(YEAR FROM AGE('2025-12-31'::date, u.birthdata)) as age
        FROM listreg l 
        JOIN users u ON l.users_oid = u.oid 
        JOIN meros m ON l.meros_oid = m.oid 
        WHERE m.champn = 1 
        AND u.accessrights = 'Sportsman' 
        AND l.status IN ('Зарегистрирован', 'Подтверждён')
        AND u.sex = 'М'
        AND EXTRACT(YEAR FROM AGE('2025-12-31'::date, u.birthdata)) BETWEEN 11 AND 12
        ORDER BY age
    ");
    $stmt->execute();
    $youngParticipants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nУчастники 11-12 лет (М):\n";
    foreach ($youngParticipants as $row) {
        echo "- {$row['fio']} ({$row['age']} лет)\n";
    }
    
    // Проверяем дисциплины участников
    $stmt = $db->prepare("
        SELECT u.fio, l.discipline
        FROM listreg l 
        JOIN users u ON l.users_oid = u.oid 
        JOIN meros m ON l.meros_oid = m.oid 
        WHERE m.champn = 1 
        AND u.accessrights = 'Sportsman' 
        AND l.status IN ('Зарегистрирован', 'Подтверждён')
        AND u.sex = 'М'
        AND EXTRACT(YEAR FROM AGE('2025-12-31'::date, u.birthdata)) BETWEEN 11 AND 12
        LIMIT 3
    ");
    $stmt->execute();
    $disciplines = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nДисциплины участников 11-12 лет (М):\n";
    foreach ($disciplines as $row) {
        echo "- {$row['fio']}: {$row['discipline']}\n";
    }
    
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}
?> 
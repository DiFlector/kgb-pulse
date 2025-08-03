<?php
// Тестовый файл для проверки генерации JSON протоколов
require_once '/var/www/html/vendor/autoload.php';

try {
    require_once '/var/www/html/lks/php/db/Database.php';
    require_once '/var/www/html/lks/php/secretary/conduct_draw.php';
    
    $db = Database::getInstance();
    
    echo "=== ГЕНЕРАЦИЯ ПРОТОКОЛОВ ===\n";
    
    // Генерируем протоколы для мероприятия 1
    $protocolsData = generateProtocolsData(1);
    
    if ($protocolsData === false) {
        echo "Ошибка генерации протоколов\n";
        exit;
    }
    
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
    $jsonFile = '/var/www/html/lks/files/json/protocols/protocols_1_test.json';
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
?> 
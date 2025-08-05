<?php
/**
 * Тестовый файл для проверки новой структуры папок JSON протоколов
 */

require_once __DIR__ . "/lks/php/common/JsonProtocolManager.php";

echo "🧪 Тестирование новой структуры JSON протоколов\n";

try {
    $manager = JsonProtocolManager::getInstance();
    
    // Тестовые данные
    $testKey = "protocol:1:K-1:М:200:группа 1";
    $testData = [
        'name' => 'группа 1',
        'protocol_number' => 1,
        'participants' => [
            [
                'userId' => 1001,
                'fio' => 'Иванов Иван',
                'lane' => 1,
                'water' => 1,
                'protected' => false
            ],
            [
                'userId' => 1002,
                'fio' => 'Петров Петр',
                'lane' => 2,
                'water' => 2,
                'protected' => false
            ]
        ],
        'redisKey' => $testKey
    ];
    
    echo "📝 Сохраняем тестовый протокол...\n";
    $result = $manager->saveProtocol($testKey, $testData);
    echo $result ? "✅ Протокол сохранен\n" : "❌ Ошибка сохранения\n";
    
    echo "📖 Загружаем протокол...\n";
    $loadedData = $manager->loadProtocol($testKey);
    echo $loadedData ? "✅ Протокол загружен\n" : "❌ Ошибка загрузки\n";
    
    echo "🔍 Проверяем существование...\n";
    $exists = $manager->protocolExists($testKey);
    echo $exists ? "✅ Протокол существует\n" : "❌ Протокол не найден\n";
    
    echo "📁 Проверяем структуру папок...\n";
    $protocolDir = __DIR__ . "/lks/files/json/protocols/protocol_1/";
    if (is_dir($protocolDir)) {
        echo "✅ Папка мероприятия создана: $protocolDir\n";
        $files = glob($protocolDir . "*.json");
        echo "📄 Файлов в папке: " . count($files) . "\n";
        foreach ($files as $file) {
            echo "  - " . basename($file) . "\n";
        }
    } else {
        echo "❌ Папка мероприятия не создана\n";
    }
    
    if ($loadedData) {
        echo "📊 Данные протокола:\n";
        echo "- Название: " . $loadedData['name'] . "\n";
        echo "- Участников: " . count($loadedData['participants']) . "\n";
        foreach ($loadedData['participants'] as $participant) {
            echo "  - {$participant['fio']} (дорога {$participant['lane']})\n";
        }
    }
    
    echo "🧹 Очищаем тестовые данные...\n";
    $manager->deleteProtocol($testKey);
    echo "✅ Тест завершен\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
} 
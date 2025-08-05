<?php
/**
 * Тестовый файл для проверки обновления дорожек
 */

require_once __DIR__ . "/lks/php/common/JsonProtocolManager.php";

echo "🧪 Тестирование обновления дорожек\n";

try {
    $manager = JsonProtocolManager::getInstance();
    
    // Тестовые данные
    $testKey = "protocol:1:K-1:M:200:группа 1";
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
    
    if ($loadedData) {
        echo "📊 Исходные данные:\n";
        foreach ($loadedData['participants'] as $participant) {
            echo "  - {$participant['fio']} (дорога {$participant['lane']})\n";
        }
        
        // Симулируем обновление дорожки
        echo "\n🔄 Обновляем дорожку участника 1001 на дорожку 3...\n";
        
        // Ищем участника и обновляем дорожку
        foreach ($loadedData['participants'] as &$participant) {
            if ($participant['userId'] == 1001) {
                $oldLane = $participant['lane'];
                $participant['lane'] = 3;
                $participant['water'] = 3;
                echo "✅ Дорожка обновлена: {$participant['fio']} с дорожки $oldLane на дорожку 3\n";
                break;
            }
        }
        
        // Сохраняем обновленный протокол
        $manager->updateProtocol($testKey, $loadedData);
        echo "💾 Протокол сохранен\n";
        
        // Загружаем снова для проверки
        echo "\n📖 Загружаем обновленный протокол...\n";
        $updatedData = $manager->loadProtocol($testKey);
        
        if ($updatedData) {
            echo "📊 Обновленные данные:\n";
            foreach ($updatedData['participants'] as $participant) {
                echo "  - {$participant['fio']} (дорога {$participant['lane']})\n";
            }
        }
    }
    
    echo "\n🧹 Очищаем тестовые данные...\n";
    $manager->deleteProtocol($testKey);
    echo "✅ Тест завершен\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
} 
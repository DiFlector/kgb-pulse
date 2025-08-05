<?php
/**
 * Тестовый файл для проверки удаления участников
 */

require_once __DIR__ . "/lks/php/common/JsonProtocolManager.php";

echo "🧪 Тестирование удаления участников\n";

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
                'userid' => 1001,
                'fio' => 'Иванов Иван',
                'lane' => 1,
                'water' => 1,
                'protected' => false
            ],
            [
                'userId' => 1002,
                'userid' => 1002,
                'fio' => 'Петров Петр',
                'lane' => 2,
                'water' => 2,
                'protected' => false
            ],
            [
                'userId' => 1003,
                'userid' => 1003,
                'fio' => 'Сидоров Сидор',
                'lane' => 3,
                'water' => 3,
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
            echo "  - {$participant['fio']} (userId: {$participant['userId']}, дорога: {$participant['lane']})\n";
        }
        
        // Симулируем удаление участника
        echo "\n🔄 Удаляем участника 1002...\n";
        
        // Ищем участника и удаляем его
        $participantIndex = -1;
        foreach ($loadedData['participants'] as $index => $participant) {
            if ((isset($participant['userId']) && $participant['userId'] == 1002) || 
                (isset($participant['userid']) && $participant['userid'] == 1002)) {
                $participantIndex = $index;
                break;
            }
        }
        
        if ($participantIndex !== -1) {
            $removedParticipant = $loadedData['participants'][$participantIndex];
            unset($loadedData['participants'][$participantIndex]);
            $loadedData['participants'] = array_values($loadedData['participants']);
            
            echo "✅ Участник удален: {$removedParticipant['fio']}\n";
            
            // Сохраняем обновленный протокол
            $manager->updateProtocol($testKey, $loadedData);
            echo "💾 Протокол сохранен\n";
            
            // Загружаем снова для проверки
            echo "\n📖 Загружаем обновленный протокол...\n";
            $updatedData = $manager->loadProtocol($testKey);
            
            if ($updatedData) {
                echo "📊 Обновленные данные:\n";
                foreach ($updatedData['participants'] as $participant) {
                    echo "  - {$participant['fio']} (userId: {$participant['userId']}, дорога: {$participant['lane']})\n";
                }
            }
        } else {
            echo "❌ Участник 1002 не найден\n";
        }
    }
    
    echo "\n🧹 Очищаем тестовые данные...\n";
    $manager->deleteProtocol($testKey);
    echo "✅ Тест завершен\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
} 
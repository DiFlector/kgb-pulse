<?php
/**
 * Тест обновления данных участника
 */

require_once __DIR__ . "/php/common/JsonProtocolManager.php";
require_once __DIR__ . "/php/db/Database.php";

echo "=== ТЕСТ ОБНОВЛЕНИЯ ДАННЫХ УЧАСТНИКА ===\n\n";

try {
    $protocolManager = JsonProtocolManager::getInstance();
    
    // Используем конкретный существующий протокол
    $testProtocolKey = "protocol:1:K-1:Ж:10000:группа Ю4";
    
    echo "Тестируем протокол: $testProtocolKey\n";
    
    // Загружаем протокол
    $protocolData = $protocolManager->loadProtocol($testProtocolKey);
    
    if (!$protocolData) {
        echo "❌ Протокол не найден: $testProtocolKey\n";
        exit;
    }
    
    $data = $protocolData['data'] ?? $protocolData;
    echo "✅ Протокол загружен успешно\n";
    echo "Участников в протоколе: " . count($data['participants']) . "\n\n";
    
    // Показываем текущие данные участников
    echo "Текущие данные участников:\n";
    foreach (array_slice($data['participants'], 0, 3) as $index => $participant) {
        echo "  " . ($index + 1) . ". {$participant['fio']} (ID: {$participant['userId']})\n";
        echo "      Место: " . ($participant['place'] ?? 'не задано') . "\n";
        echo "      Время: " . ($participant['finishTime'] ?? 'не задано') . "\n";
    }
    echo "\n";
    
    // Тестируем обновление данных участника
    echo "=== ТЕСТ ОБНОВЛЕНИЯ ДАННЫХ ===\n\n";
    
    // Выбираем первого участника для тестирования
    $testParticipant = $data['participants'][0];
    $participantUserId = $testParticipant['userId'];
    
    echo "Тестируем обновление участника ID: $participantUserId\n";
    
    // Симулируем обновление места
    $newPlace = rand(1, 10);
    echo "Новое место: $newPlace\n";
    
    // Симулируем обновление времени
    $newTime = sprintf('%d:%02d.%02d', rand(1, 5), rand(0, 59), rand(0, 99));
    echo "Новое время: $newTime\n\n";
    
    // Обновляем данные в протоколе
    foreach ($data['participants'] as &$participant) {
        if ($participant['userId'] == $participantUserId) {
            $participant['place'] = $newPlace;
            $participant['finishTime'] = $newTime;
            break;
        }
    }
    
    // Обновляем время изменения
    $data['updated_at'] = date('Y-m-d H:i:s');
    
    // Сохраняем обновленный протокол
    $success = $protocolManager->updateProtocol($testProtocolKey, $data);
    
    if ($success) {
        echo "✅ Протокол обновлен успешно\n\n";
        
        // Загружаем обновленный протокол
        $updatedProtocolData = $protocolManager->loadProtocol($testProtocolKey);
        $updatedData = $updatedProtocolData['data'] ?? $updatedProtocolData;
        
        echo "Обновленные данные участников:\n";
        foreach (array_slice($updatedData['participants'], 0, 3) as $index => $participant) {
            echo "  " . ($index + 1) . ". {$participant['fio']} (ID: {$participant['userId']})\n";
            echo "      Место: " . ($participant['place'] ?? 'не задано') . "\n";
            echo "      Время: " . ($participant['finishTime'] ?? 'не задано') . "\n";
        }
        echo "\n";
        
    } else {
        echo "❌ Ошибка обновления протокола\n";
    }
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    echo "Файл: " . $e->getFile() . "\n";
    echo "Строка: " . $e->getLine() . "\n";
}

echo "\n=== ТЕСТ ЗАВЕРШЕН ===\n";
?> 
<?php
/**
 * Тест API сохранения протоколов
 */

require_once __DIR__ . "/php/common/JsonProtocolManager.php";
require_once __DIR__ . "/php/db/Database.php";

echo "=== ТЕСТ API СОХРАНЕНИЯ ПРОТОКОЛОВ ===\n\n";

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
    
    // Тестируем API сохранения
    echo "=== ТЕСТ API СОХРАНЕНИЯ ===\n\n";
    
    // Создаем тестовые данные для сохранения
    $testParticipants = [];
    foreach (array_slice($data['participants'], 0, 2) as $index => $participant) {
        $testParticipants[] = [
            'userId' => $participant['userId'],
            'place' => $index + 1,
            'finishTime' => sprintf('%d:%02d.%02d', rand(1, 5), rand(0, 59), rand(0, 99))
        ];
    }
    
    $testProtocolData = [
        'participants' => $testParticipants
    ];
    
    echo "Тестовые данные для сохранения:\n";
    foreach ($testParticipants as $participant) {
        echo "  Участник ID {$participant['userId']}: место {$participant['place']}, время {$participant['finishTime']}\n";
    }
    echo "\n";
    
    // Симулируем API запрос
    $_POST = [
        'protocolId' => '1:K-1:Ж:10000:группа Ю4',
        'data' => $testProtocolData
    ];
    
    // Симулируем сессию
    $_SESSION['selected_event'] = ['id' => 1];
    
    // Включаем файл API
    ob_start();
    include __DIR__ . "/php/secretary/save-protocol.php";
    $apiResponse = ob_get_clean();
    
    echo "Ответ API:\n";
    echo $apiResponse . "\n\n";
    
    // Парсим JSON ответ
    $responseData = json_decode($apiResponse, true);
    
    if ($responseData && isset($responseData['success']) && $responseData['success']) {
        echo "✅ API ответ успешен\n\n";
        
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
        echo "❌ Ошибка в ответе API\n";
        if ($responseData) {
            echo "Сообщение: " . ($responseData['message'] ?? 'неизвестная ошибка') . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    echo "Файл: " . $e->getFile() . "\n";
    echo "Строка: " . $e->getLine() . "\n";
}

echo "\n=== ТЕСТ ЗАВЕРШЕН ===\n";
?> 
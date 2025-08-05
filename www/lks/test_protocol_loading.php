<?php
/**
 * Тест загрузки протоколов через API
 */

require_once __DIR__ . "/php/common/JsonProtocolManager.php";
require_once __DIR__ . "/php/db/Database.php";

echo "=== ТЕСТ ЗАГРУЗКИ ПРОТОКОЛОВ ЧЕРЕЗ API ===\n\n";

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
    
    // Показываем данные участников
    echo "Данные участников:\n";
    foreach (array_slice($data['participants'], 0, 3) as $index => $participant) {
        echo "  " . ($index + 1) . ". {$participant['fio']} (ID: {$participant['userId']})\n";
        echo "      Место: " . ($participant['place'] ?? 'не задано') . "\n";
        echo "      Время: " . ($participant['finishTime'] ?? 'не задано') . "\n";
    }
    echo "\n";
    
    // Тестируем загрузку конкретного протокола
    echo "=== ТЕСТ ЗАГРУЗКИ КОНКРЕТНОГО ПРОТОКОЛА ===\n\n";
    
    $loadedProtocolData = $protocolManager->loadProtocol($testProtocolKey);
    
    if ($loadedProtocolData) {
        $loadedData = $loadedProtocolData['data'] ?? $loadedProtocolData;
        echo "✅ Протокол загружен успешно\n";
        echo "Участников в загруженном протоколе: " . count($loadedData['participants']) . "\n\n";
        
        echo "Данные загруженного протокола:\n";
        foreach (array_slice($loadedData['participants'], 0, 3) as $index => $participant) {
            echo "  " . ($index + 1) . ". {$participant['fio']} (ID: {$participant['userId']})\n";
            echo "      Место: " . ($participant['place'] ?? 'не задано') . "\n";
            echo "      Время: " . ($participant['finishTime'] ?? 'не задано') . "\n";
        }
        echo "\n";
        
    } else {
        echo "❌ Ошибка загрузки протокола\n";
    }
    
    // Тестируем симуляцию API запроса
    echo "=== ТЕСТ СИМУЛЯЦИИ API ЗАПРОСА ===\n\n";
    
    // Симулируем запрос к get_protocols_new.php
    $_GET['meroId'] = 1;
    
    // Включаем файл API
    ob_start();
    include __DIR__ . "/php/secretary/get_protocols_new.php";
    $apiResponse = ob_get_clean();
    
    echo "Ответ API:\n";
    echo $apiResponse . "\n\n";
    
    // Парсим JSON ответ
    $responseData = json_decode($apiResponse, true);
    
    if ($responseData && isset($responseData['success']) && $responseData['success']) {
        echo "✅ API ответ успешен\n";
        echo "Количество протоколов в ответе: " . ($responseData['count'] ?? 0) . "\n\n";
        
        // Ищем наш тестовый протокол в ответе
        if (isset($responseData['protocols'])) {
            foreach ($responseData['protocols'] as $protocol) {
                if (($protocol['redisKey'] ?? '') === $testProtocolKey) {
                    echo "✅ Найден тестовый протокол в ответе API\n";
                    echo "Участников в ответе API: " . count($protocol['participants']) . "\n";
                    break;
                }
            }
        }
        
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
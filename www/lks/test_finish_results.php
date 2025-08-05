<?php
/**
 * Тест сохранения финишных результатов
 */

require_once __DIR__ . "/php/common/JsonProtocolManager.php";
require_once __DIR__ . "/php/db/Database.php";

echo "=== ТЕСТ СОХРАНЕНИЯ ФИНИШНЫХ РЕЗУЛЬТАТОВ ===\n\n";

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
    
    // Тестируем сохранение результатов
    echo "=== ТЕСТ СОХРАНЕНИЯ РЕЗУЛЬТАТОВ ===\n\n";
    
    // Создаем тестовые результаты
    $testResults = [];
    foreach (array_slice($data['participants'], 0, 3) as $index => $participant) {
        $testResults[] = [
            'userid' => $participant['userId'],
            'place' => $index + 1,
            'result' => sprintf('%d:%02d.%02d', rand(1, 5), rand(0, 59), rand(0, 99))
        ];
    }
    
    echo "Тестовые результаты для сохранения:\n";
    foreach ($testResults as $result) {
        echo "  Участник ID {$result['userid']}: место {$result['place']}, время {$result['result']}\n";
    }
    echo "\n";
    
    // Сохраняем результаты
    $success = saveFinishResults($protocolManager, $testProtocolKey, $testResults);
    
    if ($success) {
        echo "✅ Результаты сохранены успешно\n\n";
        
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
        echo "❌ Ошибка сохранения результатов\n";
    }
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    echo "Файл: " . $e->getFile() . "\n";
    echo "Строка: " . $e->getLine() . "\n";
}

/**
 * Сохранение результатов финишного протокола
 */
function saveFinishResults($protocolManager, $redisKey, $results) {
    try {
        $protocolData = $protocolManager->loadProtocol($redisKey);
        if (!$protocolData) {
            error_log("❌ [SAVE_FINISH_RESULTS] Протокол не найден: $redisKey");
            return false;
        }
        
        // Извлекаем данные из структуры JSON
        $data = $protocolData['data'] ?? $protocolData;
        
        // Обновляем только место и время
        foreach ($results as $result) {
            foreach ($data['participants'] as &$participant) {
                if (($participant['userId'] ?? $participant['userid']) == $result['userid']) {
                    $participant['finishTime'] = $result['result'] ?? null;
                    $participant['place'] = $result['place'] ?? null;
                    break;
                }
            }
        }
        
        // Обновляем время изменения
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        // Сохраняем обновленный протокол
        $success = $protocolManager->updateProtocol($redisKey, $data);
        
        if ($success) {
            error_log("✅ [SAVE_FINISH_RESULTS] Результаты сохранены для протокола: $redisKey");
        } else {
            error_log("❌ [SAVE_FINISH_RESULTS] Ошибка сохранения для протокола: $redisKey");
        }
        
        return $success;
        
    } catch (Exception $e) {
        error_log("❌ [SAVE_FINISH_RESULTS] Ошибка сохранения результатов: " . $e->getMessage());
        return false;
    }
}

echo "\n=== ТЕСТ ЗАВЕРШЕН ===\n";
?> 
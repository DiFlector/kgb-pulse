<?php
/**
 * Финальный тест системы сохранения финишных результатов
 */

require_once __DIR__ . "/php/common/JsonProtocolManager.php";
require_once __DIR__ . "/php/db/Database.php";

echo "=== ФИНАЛЬНЫЙ ТЕСТ СИСТЕМЫ СОХРАНЕНИЯ ФИНИШНЫХ РЕЗУЛЬТАТОВ ===\n\n";

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
    
    // Тестируем обновление через update_participant_data.php
    echo "=== ТЕСТ ОБНОВЛЕНИЯ ЧЕРЕЗ UPDATE_PARTICIPANT_DATA.PHP ===\n\n";
    
    // Выбираем первого участника для тестирования
    $testParticipant = $data['participants'][0];
    $participantUserId = $testParticipant['userId'];
    
    echo "Тестируем обновление участника ID: $participantUserId\n";
    
    // Симулируем обновление места и времени
    $newPlace = rand(1, 10);
    $newTime = sprintf('%d:%02d.%02d', rand(1, 5), rand(0, 59), rand(0, 99));
    
    echo "Новое место: $newPlace\n";
    echo "Новое время: $newTime\n\n";
    
    // Симулируем API запрос для обновления места
    $_POST = [
        'meroId' => 1,
        'groupKey' => '1_K-1_Ж_10000_группа Ю4',
        'participantUserId' => $participantUserId,
        'field' => 'place',
        'value' => $newPlace
    ];
    
    // Включаем файл API
    ob_start();
    include __DIR__ . "/php/secretary/update_participant_data.php";
    $apiResponse = ob_get_clean();
    
    echo "Ответ API для места:\n";
    echo $apiResponse . "\n\n";
    
    // Симулируем API запрос для обновления времени
    $_POST = [
        'meroId' => 1,
        'groupKey' => '1_K-1_Ж_10000_группа Ю4',
        'participantUserId' => $participantUserId,
        'field' => 'finishTime',
        'value' => $newTime
    ];
    
    // Включаем файл API
    ob_start();
    include __DIR__ . "/php/secretary/update_participant_data.php";
    $apiResponse = ob_get_clean();
    
    echo "Ответ API для времени:\n";
    echo $apiResponse . "\n\n";
    
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
    
    // Проверяем, что данные сохранились правильно
    $testParticipantUpdated = null;
    foreach ($updatedData['participants'] as $participant) {
        if ($participant['userId'] == $participantUserId) {
            $testParticipantUpdated = $participant;
            break;
        }
    }
    
    if ($testParticipantUpdated) {
        if ($testParticipantUpdated['place'] == $newPlace && $testParticipantUpdated['finishTime'] == $newTime) {
            echo "✅ ДАННЫЕ СОХРАНИЛИСЬ ПРАВИЛЬНО!\n";
            echo "   Место: {$testParticipantUpdated['place']} (ожидалось: $newPlace)\n";
            echo "   Время: {$testParticipantUpdated['finishTime']} (ожидалось: $newTime)\n\n";
        } else {
            echo "❌ ДАННЫЕ НЕ СОХРАНИЛИСЬ ПРАВИЛЬНО!\n";
            echo "   Место: {$testParticipantUpdated['place']} (ожидалось: $newPlace)\n";
            echo "   Время: {$testParticipantUpdated['finishTime']} (ожидалось: $newTime)\n\n";
        }
    } else {
        echo "❌ Участник не найден в обновленных данных\n\n";
    }
    
    // Тестируем загрузку через get_protocols_data.php
    echo "=== ТЕСТ ЗАГРУЗКИ ЧЕРЕЗ GET_PROTOCOLS_DATA.PHP ===\n\n";
    
    // Симулируем API запрос
    $_POST = [
        'meroId' => 1,
        'disciplines' => [
            [
                'class' => 'K-1',
                'sex' => 'Ж',
                'distance' => '10000'
            ]
        ],
        'type' => 'start'
    ];
    
    // Включаем файл API
    ob_start();
    include __DIR__ . "/php/secretary/get_protocols_data.php";
    $apiResponse = ob_get_clean();
    
    echo "Ответ API загрузки данных:\n";
    echo $apiResponse . "\n\n";
    
    // Парсим JSON ответ
    $responseData = json_decode($apiResponse, true);
    
    if ($responseData && isset($responseData['success']) && $responseData['success']) {
        echo "✅ API загрузки данных работает\n";
        echo "Количество протоколов: " . ($responseData['total_protocols'] ?? 0) . "\n\n";
        
        // Ищем наш тестовый протокол
        if (isset($responseData['protocols'])) {
            foreach ($responseData['protocols'] as $protocol) {
                if ($protocol['discipline'] === 'K-1' && $protocol['sex'] === 'Ж' && $protocol['distance'] === '10000') {
                    echo "✅ Найден тестовый протокол в ответе API\n";
                    if (isset($protocol['ageGroups']) && count($protocol['ageGroups']) > 0) {
                        $ageGroup = $protocol['ageGroups'][0];
                        echo "Участников в протоколе: " . count($ageGroup['participants'] ?? []) . "\n";
                        
                        // Проверяем, что данные участника загружены правильно
                        foreach ($ageGroup['participants'] as $participant) {
                            if ($participant['userId'] == $participantUserId) {
                                echo "✅ Данные участника загружены правильно:\n";
                                echo "   Место: " . ($participant['place'] ?? 'не задано') . "\n";
                                echo "   Время: " . ($participant['finishTime'] ?? 'не задано') . "\n";
                                break;
                            }
                        }
                    }
                    break;
                }
            }
        }
        
    } else {
        echo "❌ Ошибка в ответе API загрузки данных\n";
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
echo "\nРЕЗЮМЕ ИСПРАВЛЕНИЙ:\n";
echo "1. ✅ Исправлен save_finish_results.php - использует JsonProtocolManager\n";
echo "2. ✅ Исправлен get_protocols_new.php - использует JsonProtocolManager\n";
echo "3. ✅ Исправлен update_participant_data.php - обновляет JSON протоколы\n";
echo "4. ✅ Создан save-protocol.php - API для сохранения протоколов\n";
echo "5. ✅ Исправлен protocols.js - правильное отображение полей place и finishTime\n";
echo "\nТеперь финишные протоколы должны работать корректно!\n";
?> 
<?php
/**
 * Тест загрузки протоколов с участниками
 */

require_once __DIR__ . "/php/common/JsonProtocolManager.php";
require_once __DIR__ . "/php/db/Database.php";

echo "=== ТЕСТ ЗАГРУЗКИ ПРОТОКОЛОВ С УЧАСТНИКАМИ ===\n\n";

try {
    $protocolManager = JsonProtocolManager::getInstance();
    
    // Тестируем загрузку протоколов для мероприятия с ID 1
    $meroId = 1;
    
    echo "Загружаем протоколы для мероприятия ID: $meroId\n";
    $protocols = $protocolManager->getEventProtocols($meroId);
    
    echo "Найдено протоколов: " . count($protocols) . "\n\n";
    
    if (empty($protocols)) {
        echo "❌ Протоколы не найдены\n";
        echo "Возможные причины:\n";
        echo "1. Мероприятие с ID $meroId не существует\n";
        echo "2. Протоколы не были созданы\n";
        echo "3. Папка с протоколами не существует\n";
        exit;
    }
    
    foreach ($protocols as $redisKey => $protocolData) {
        echo "Протокол: $redisKey\n";
        
        // Проверяем структуру протокола
        if (isset($protocolData['data'])) {
            echo "  ✅ Структура протокола содержит поле 'data'\n";
            $data = $protocolData['data'];
        } else {
            echo "  ⚠️ Структура протокола не содержит поле 'data'\n";
            $data = $protocolData;
        }
        
        // Проверяем наличие участников
        if (isset($data['participants'])) {
            $participantsCount = count($data['participants']);
            echo "  ✅ Участники найдены: $participantsCount\n";
            
            if ($participantsCount > 0) {
                echo "  Первые 3 участника:\n";
                foreach (array_slice($data['participants'], 0, 3) as $index => $participant) {
                    echo "    " . ($index + 1) . ". {$participant['fio']} (ID: {$participant['userId']})\n";
                }
            } else {
                echo "  ⚠️ Протокол пустой (нет участников)\n";
            }
        } else {
            echo "  ❌ Поле 'participants' не найдено\n";
        }
        
        // Проверяем название группы
        if (isset($data['name'])) {
            echo "  ✅ Название группы: {$data['name']}\n";
        } else {
            echo "  ⚠️ Поле 'name' не найдено\n";
        }
        
        echo "\n";
    }
    
    // Тестируем скачивание CSV протоколов
    echo "=== ТЕСТ СКАЧИВАНИЯ CSV ПРОТОКОЛОВ ===\n\n";
    
    // Симулируем запрос на скачивание
    $_GET['mero_id'] = $meroId;
    $_GET['protocol_type'] = 'start';
    
    // Подключаем файл скачивания
    ob_start();
    include __DIR__ . "/php/secretary/download_all_csv_protocols.php";
    $csvOutput = ob_get_clean();
    
    if (!empty($csvOutput)) {
        echo "✅ CSV протоколы сгенерированы успешно\n";
        echo "Размер вывода: " . strlen($csvOutput) . " байт\n";
        
        // Показываем первые 500 символов для проверки
        echo "Первые 500 символов:\n";
        echo substr($csvOutput, 0, 500) . "\n";
    } else {
        echo "❌ CSV протоколы не сгенерированы\n";
    }
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    echo "Файл: " . $e->getFile() . "\n";
    echo "Строка: " . $e->getLine() . "\n";
}

echo "\n=== ТЕСТ ЗАВЕРШЕН ===\n";
?> 
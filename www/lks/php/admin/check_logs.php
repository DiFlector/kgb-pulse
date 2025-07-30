<?php
/**
 * Проверка PHP логов для диагностики HTTP 500
 */

echo "<h1>Диагностика PHP логов</h1>";

// Информация о PHP
echo "<h2>Информация о PHP</h2>";
echo "PHP версия: " . phpversion() . "<br>";
echo "Текущая директория: " . __DIR__ . "<br>";
echo "Document root: " . $_SERVER['DOCUMENT_ROOT'] . "<br>";

// Проверка настроек логирования
echo "<h2>Настройки логирования</h2>";
echo "log_errors: " . (ini_get('log_errors') ? 'включено' : 'отключено') . "<br>";
echo "display_errors: " . (ini_get('display_errors') ? 'включено' : 'отключено') . "<br>";
echo "error_log: " . ini_get('error_log') . "<br>";
echo "error_reporting: " . error_reporting() . "<br>";

// Проверка файлов логов
echo "<h2>Проверка файлов логов</h2>";

$logPaths = [
    '/var/log/php_errors.log',
    '/var/log/nginx/error.log',
    '/var/log/apache2/error.log',
    ini_get('error_log'),
    __DIR__ . '/../../../../logs/php_errors.log',
    __DIR__ . '/../../../../logs/nginx/error.log'
];

foreach ($logPaths as $path) {
    if (empty($path)) continue;
    
    echo "<h3>Лог: $path</h3>";
    if (file_exists($path)) {
        echo "✅ Существует<br>";
        $size = filesize($path);
        echo "Размер: " . number_format($size) . " байт<br>";
        
        if (is_readable($path)) {
            echo "✅ Доступен для чтения<br>";
            $content = file_get_contents($path);
            $lines = explode("\n", $content);
            $recent = array_slice($lines, -10); // Последние 10 строк
            
            echo "<pre style='background:#f0f0f0; padding:10px; max-height:200px; overflow:auto;'>";
            echo "=== ПОСЛЕДНИЕ 10 СТРОК ===\n";
            foreach ($recent as $line) {
                if (trim($line)) {
                    echo htmlspecialchars($line) . "\n";
                }
            }
            echo "</pre>";
        } else {
            echo "❌ Недоступен для чтения<br>";
        }
    } else {
        echo "❌ Не существует<br>";
    }
}

// Тест простой ошибки
echo "<h2>Тест генерации ошибки</h2>";
try {
    // Намеренная ошибка для тестирования логирования
    $test = 1 / 0;
} catch (DivisionByZeroError $e) {
    echo "✅ Ошибка деления на ноль поймана: " . $e->getMessage() . "<br>";
} catch (Exception $e) {
    echo "✅ Исключение поймано: " . $e->getMessage() . "<br>";
}

echo "<hr>";
echo "<p><strong>Проверьте логи выше для поиска ошибок import-registrations.php</strong></p>";
?> 
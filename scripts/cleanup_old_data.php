<?php
/**
 * Скрипт очистки старых данных
 * Файл: scripts/cleanup_old_data.php
 */

require_once __DIR__ . "/../www/lks/php/common/JsonProtocolManager.php";

// Настройки логирования
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/cleanup.log');

echo "🔄 [CLEANUP] Начинаем очистку старых данных...\n";

try {
    // Инициализируем менеджер JSON протоколов
    $protocolManager = JsonProtocolManager::getInstance();
    
    // Очищаем старые протоколы (старше 7 дней)
    $deletedProtocols = $protocolManager->cleanupOldProtocols(7);
    echo "✅ [CLEANUP] Удалено старых протоколов: $deletedProtocols\n";
    
    // Очищаем временные файлы
    $tempDir = __DIR__ . '/../www/lks/files/temp/';
    if (is_dir($tempDir)) {
        $tempFiles = glob($tempDir . '*');
        $deletedTempFiles = 0;
        
        foreach ($tempFiles as $file) {
            if (is_file($file)) {
                $fileAge = time() - filemtime($file);
                if ($fileAge > 86400) { // Старше 24 часов
                    if (unlink($file)) {
                        $deletedTempFiles++;
                    }
                }
            }
        }
        
        echo "✅ [CLEANUP] Удалено временных файлов: $deletedTempFiles\n";
    }
    
    // Очищаем старые логи
    $logDir = __DIR__ . '/../logs/';
    if (is_dir($logDir)) {
        $logFiles = glob($logDir . '*.log');
        $deletedLogFiles = 0;
        
        foreach ($logFiles as $file) {
            if (is_file($file)) {
                $fileAge = time() - filemtime($file);
                if ($fileAge > 604800) { // Старше 7 дней
                    if (unlink($file)) {
                        $deletedLogFiles++;
                    }
                }
            }
        }
        
        echo "✅ [CLEANUP] Удалено старых логов: $deletedLogFiles\n";
    }
    
    echo "✅ [CLEANUP] Очистка завершена успешно!\n";
    
} catch (Exception $e) {
    echo "❌ [CLEANUP] Ошибка очистки: " . $e->getMessage() . "\n";
    exit(1);
} 
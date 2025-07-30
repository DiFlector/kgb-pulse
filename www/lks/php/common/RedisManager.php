<?php
/**
 * Менеджер Redis для работы с протоколами и кэшем
 * Файл: www/lks/php/common/RedisManager.php
 */

class RedisManager {
    private static $instance = null;
    private $redis = null;
    private $isConnected = false;
    
    private function __construct() {
        $this->connect();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Подключение к Redis
     */
    private function connect() {
        try {
            $this->redis = new Redis();
            $this->isConnected = $this->redis->connect('redis', 6379, 5);
            
            if (!$this->isConnected) {
                error_log("❌ [REDIS] Не удалось подключиться к Redis");
                $this->redis = null;
            } else {
                error_log("✅ [REDIS] Подключение к Redis успешно");
            }
        } catch (Exception $e) {
            error_log("❌ [REDIS] Ошибка подключения к Redis: " . $e->getMessage());
            $this->redis = null;
            $this->isConnected = false;
        }
    }
    
    /**
     * Проверка подключения
     */
    public function isConnected() {
        return $this->isConnected && $this->redis !== null;
    }
    
    /**
     * Получение значения по ключу
     */
    public function get($key) {
        if (!$this->isConnected()) {
            return null;
        }
        
        try {
            return $this->redis->get($key);
        } catch (Exception $e) {
            error_log("❌ [REDIS] Ошибка получения ключа $key: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Установка значения с TTL
     */
    public function setex($key, $ttl, $value) {
        if (!$this->isConnected()) {
            return false;
        }
        
        try {
            return $this->redis->setex($key, $ttl, $value);
        } catch (Exception $e) {
            error_log("❌ [REDIS] Ошибка установки ключа $key: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Установка значения без TTL
     */
    public function set($key, $value) {
        if (!$this->isConnected()) {
            return false;
        }
        
        try {
            return $this->redis->set($key, $value);
        } catch (Exception $e) {
            error_log("❌ [REDIS] Ошибка установки ключа $key: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Удаление ключа
     */
    public function del($key) {
        if (!$this->isConnected()) {
            return false;
        }
        
        try {
            return $this->redis->del($key);
        } catch (Exception $e) {
            error_log("❌ [REDIS] Ошибка удаления ключа $key: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Поиск ключей по паттерну
     */
    public function keys($pattern) {
        if (!$this->isConnected()) {
            return [];
        }
        
        try {
            return $this->redis->keys($pattern);
        } catch (Exception $e) {
            error_log("❌ [REDIS] Ошибка поиска ключей $pattern: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Проверка существования ключа
     */
    public function exists($key) {
        if (!$this->isConnected()) {
            return false;
        }
        
        try {
            return $this->redis->exists($key);
        } catch (Exception $e) {
            error_log("❌ [REDIS] Ошибка проверки ключа $key: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Получение TTL ключа
     */
    public function ttl($key) {
        if (!$this->isConnected()) {
            return -1;
        }
        
        try {
            return $this->redis->ttl($key);
        } catch (Exception $e) {
            error_log("❌ [REDIS] Ошибка получения TTL ключа $key: " . $e->getMessage());
            return -1;
        }
    }
    
    /**
     * Закрытие соединения
     */
    public function close() {
        if ($this->redis !== null) {
            try {
                $this->redis->close();
            } catch (Exception $e) {
                error_log("❌ [REDIS] Ошибка закрытия соединения: " . $e->getMessage());
            }
        }
        $this->redis = null;
        $this->isConnected = false;
    }
    
    /**
     * Сохранение протокола в Redis и JSON файл
     */
    public function saveProtocol($key, $data, $ttl = 86400 * 7) {
        $success = true;
        
        // Сохраняем в Redis
        if ($this->isConnected()) {
            $success = $this->setex($key, $ttl, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        
        // Сохраняем в JSON файл как резервную копию
        $jsonSuccess = $this->saveProtocolToFile($key, $data);
        
        return $success || $jsonSuccess;
    }
    
    /**
     * Загрузка протокола из Redis или JSON файла
     */
    public function loadProtocol($key) {
        // Пробуем загрузить из Redis
        if ($this->isConnected()) {
            $data = $this->get($key);
            if ($data !== null) {
                return json_decode($data, true);
            }
        }
        
        // Загружаем из JSON файла
        return $this->loadProtocolFromFile($key);
    }
    
    /**
     * Сохранение протокола в JSON файл
     */
    private function saveProtocolToFile($key, $data) {
        try {
            $protocolsDir = __DIR__ . "/../../files/protocol/";
            
            if (!is_dir($protocolsDir)) {
                mkdir($protocolsDir, 0755, true);
            }
            
            $filename = str_replace([':', '/'], ['_', '_'], $key) . '.json';
            $filepath = $protocolsDir . $filename;
            
            $result = file_put_contents($filepath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            
            if ($result !== false) {
                error_log("✅ [REDIS] Протокол сохранен в файл: $filepath");
                return true;
            } else {
                error_log("❌ [REDIS] Ошибка сохранения протокола в файл: $filepath");
                return false;
            }
        } catch (Exception $e) {
            error_log("❌ [REDIS] Ошибка сохранения протокола в файл: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Загрузка протокола из JSON файла
     */
    private function loadProtocolFromFile($key) {
        try {
            $protocolsDir = __DIR__ . "/../../files/protocol/";
            $filename = str_replace([':', '/'], ['_', '_'], $key) . '.json';
            $filepath = $protocolsDir . $filename;
            
            if (!file_exists($filepath)) {
                return null;
            }
            
            $content = file_get_contents($filepath);
            if ($content === false) {
                return null;
            }
            
            $data = json_decode($content, true);
            if ($data === null) {
                error_log("❌ [REDIS] Ошибка парсинга JSON файла: $filepath");
                return null;
            }
            
            error_log("✅ [REDIS] Протокол загружен из файла: $filepath");
            return $data;
        } catch (Exception $e) {
            error_log("❌ [REDIS] Ошибка загрузки протокола из файла: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Получение всех протоколов мероприятия
     */
    public function getEventProtocols($meroId) {
        $protocols = [];
        
        // Получаем из Redis
        if ($this->isConnected()) {
            $pattern = "protocol:*:{$meroId}:*";
            $keys = $this->keys($pattern);
            
            foreach ($keys as $key) {
                $data = $this->get($key);
                if ($data) {
                    $protocol = json_decode($data, true);
                    if ($protocol) {
                        $protocols[] = $protocol;
                    }
                }
            }
        }
        
        // Если в Redis нет данных, получаем из файлов
        if (empty($protocols)) {
            $protocols = $this->getEventProtocolsFromFiles($meroId);
        }
        
        return $protocols;
    }
    
    /**
     * Получение протоколов мероприятия из файлов
     */
    private function getEventProtocolsFromFiles($meroId) {
        $protocols = [];
        $protocolsDir = __DIR__ . "/../../files/protocol/";
        
        if (!is_dir($protocolsDir)) {
            return $protocols;
        }
        
        $pattern = "protocol_*_{$meroId}_*.json";
        $files = glob($protocolsDir . $pattern);
        
        foreach ($files as $file) {
            try {
                $content = file_get_contents($file);
                if ($content !== false) {
                    $protocol = json_decode($content, true);
                    if ($protocol) {
                        $protocols[] = $protocol;
                    }
                }
            } catch (Exception $e) {
                error_log("❌ [REDIS] Ошибка чтения файла протокола: " . $e->getMessage());
            }
        }
        
        return $protocols;
    }
}
?> 
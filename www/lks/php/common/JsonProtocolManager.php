<?php
/**
 * Менеджер JSON протоколов для секретаря
 * Файл: www/lks/php/common/JsonProtocolManager.php
 */

class JsonProtocolManager {
    private static $instance = null;
    private $protocolsDir;
    
    private function __construct() {
        $this->protocolsDir = __DIR__ . '/../../files/json/protocols/';
        $this->ensureDirectoryExists();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Создание директории для протоколов
     */
    private function ensureDirectoryExists() {
        if (!is_dir($this->protocolsDir)) {
            mkdir($this->protocolsDir, 0755, true);
        }
    }
    
    /**
     * Генерация ключа файла из redisKey
     */
    private function getFilePathFromRedisKey($redisKey) {
        // Преобразуем redisKey в структуру папок
        // protocol:1:K-1:М:200:группа 1 -> protocol_1/K-1_M_200_gruppa_1.json
        
        // Извлекаем ID мероприятия
        $parts = explode(':', $redisKey);
        if (count($parts) < 6) {
            throw new Exception('Неверный формат redisKey: ' . $redisKey);
        }
        
        $meroId = $parts[1];
        $discipline = $parts[2];
        $sex = $parts[3];
        $distance = $parts[4];
        $ageGroup = $parts[5];
        
        // Заменяем кириллические символы на латинские
        $sex = str_replace(['М', 'Ж'], ['M', 'Z'], $sex);
        
        // Создаем имя файла
        $filename = $discipline . '_' . $sex . '_' . $distance . '_' . $this->sanitizeAgeGroup($ageGroup) . '.json';
        
        // Создаем путь к папке мероприятия
        $protocolDir = $this->protocolsDir . 'protocol_' . $meroId . '/';
        
        // Создаем папку, если её нет
        if (!is_dir($protocolDir)) {
            if (!mkdir($protocolDir, 0755, true)) {
                throw new Exception('Не удалось создать папку: ' . $protocolDir);
            }
        }
        
        return $protocolDir . $filename;
    }
    
    /**
     * Очистка названия возрастной группы для использования в имени файла
     */
    private function sanitizeAgeGroup($ageGroup) {
        // Заменяем пробелы и специальные символы на подчеркивания
        $sanitized = preg_replace('/[^a-zA-Zа-яА-Я0-9]/', '_', $ageGroup);
        
        // Убираем двойные подчеркивания
        $sanitized = str_replace('__', '_', $sanitized);
        
        // Убираем подчеркивания в начале и конце
        $sanitized = trim($sanitized, '_');
        
        // Транслитерация кириллических символов
        $translit = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
            'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
            'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
            'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
            'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Е' => 'E', 'Ё' => 'YO',
            'Ж' => 'ZH', 'З' => 'Z', 'И' => 'I', 'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M',
            'Н' => 'N', 'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'У' => 'U',
            'Ф' => 'F', 'Х' => 'H', 'Ц' => 'TS', 'Ч' => 'CH', 'Ш' => 'SH', 'Щ' => 'SCH',
            'Ъ' => '', 'Ы' => 'Y', 'Ь' => '', 'Э' => 'E', 'Ю' => 'YU', 'Я' => 'YA'
        ];
        
        $sanitized = strtr($sanitized, $translit);
        
        // Дополнительная очистка - убираем все не-ASCII символы
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $sanitized);
        
        return $sanitized;
    }
    
    /**
     * Сохранение протокола в JSON файл
     */
    public function saveProtocol($redisKey, $data, $protected = false) {
        try {
            $filepath = $this->getFilePathFromRedisKey($redisKey);
            
            // Добавляем метаданные
            $protocolData = [
                'data' => $data,
                'protected' => $protected,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'redisKey' => $redisKey
            ];
            
            $jsonData = json_encode($protocolData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            
            if (file_put_contents($filepath, $jsonData) === false) {
                error_log("❌ [JSON_PROTOCOL] Ошибка сохранения протокола: $filepath");
                return false;
            }
            
            error_log("✅ [JSON_PROTOCOL] Протокол сохранен: $filepath");
            return true;
            
        } catch (Exception $e) {
            error_log("❌ [JSON_PROTOCOL] Ошибка сохранения протокола: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Загрузка протокола из JSON файла
     */
    public function loadProtocol($redisKey) {
        try {
            $filepath = $this->getFilePathFromRedisKey($redisKey);
            
            if (!file_exists($filepath)) {
                // Файл отсутствует — это нормальный сценарий при первичном создании протокола
                // Возвращаем null без логирования предупреждений
                return null;
            }
            
            $jsonData = file_get_contents($filepath);
            if ($jsonData === false) {
                error_log("❌ [JSON_PROTOCOL] Ошибка чтения файла: $filepath");
                return null;
            }
            
            $protocolData = json_decode($jsonData, true);
            if ($protocolData === null) {
                error_log("❌ [JSON_PROTOCOL] Ошибка парсинга JSON: $filepath");
                return null;
            }
            
            error_log("✅ [JSON_PROTOCOL] Протокол загружен: $filepath");
            return $protocolData['data'];
            
        } catch (Exception $e) {
            error_log("❌ [JSON_PROTOCOL] Ошибка загрузки протокола: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Обновление протокола
     */
    public function updateProtocol($redisKey, $data, $protected = false) {
        try {
            $filepath = $this->getFilePathFromRedisKey($redisKey);
            
            $existingData = null;
            if (file_exists($filepath)) {
                $jsonData = file_get_contents($filepath);
                $existingData = json_decode($jsonData, true);
            }
            
            // Сохраняем существующие защищенные данные
            $protectedData = [];
            if ($existingData && isset($existingData['data']['participants'])) {
                foreach ($existingData['data']['participants'] as $participant) {
                    if (isset($participant['protected']) && $participant['protected']) {
                        $protectedData[$participant['userId']] = $participant;
                    }
                }
            }
            
            // Объединяем с новыми данными
            if (isset($data['participants'])) {
                foreach ($data['participants'] as &$participant) {
                    if (isset($protectedData[$participant['userId']])) {
                        // Сохраняем защищенные данные (дороги, места, время)
                        $protected = $protectedData[$participant['userId']];
                        $participant['lane'] = $protected['lane'] ?? $participant['lane'];
                        $participant['water'] = $protected['water'] ?? $participant['water'];
                        $participant['place'] = $protected['place'] ?? $participant['place'];
                        $participant['finishTime'] = $protected['finishTime'] ?? $participant['finishTime'];
                        $participant['protected'] = true;
                    }
                }
            }
            
            // Добавляем метаданные
            $protocolData = [
                'data' => $data,
                'protected' => $protected,
                'created_at' => $existingData['created_at'] ?? date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'redisKey' => $redisKey
            ];
            
            $jsonData = json_encode($protocolData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            
            if (file_put_contents($filepath, $jsonData) === false) {
                error_log("❌ [JSON_PROTOCOL] Ошибка обновления протокола: $filepath");
                return false;
            }
            
            error_log("✅ [JSON_PROTOCOL] Протокол обновлен: $filepath");
            return true;
            
        } catch (Exception $e) {
            error_log("❌ [JSON_PROTOCOL] Ошибка обновления протокола: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Проверка существования протокола
     */
    public function protocolExists($redisKey) {
        $filepath = $this->getFilePathFromRedisKey($redisKey);
        return file_exists($filepath);
    }
    
    /**
     * Удаление протокола
     */
    public function deleteProtocol($redisKey) {
        try {
            $filepath = $this->getFilePathFromRedisKey($redisKey);
            
            if (file_exists($filepath)) {
                if (unlink($filepath)) {
                    error_log("✅ [JSON_PROTOCOL] Протокол удален: $filepath");
                    return true;
                } else {
                    error_log("❌ [JSON_PROTOCOL] Ошибка удаления протокола: $filepath");
                    return false;
                }
            }
            
            return true; // Файл не существовал
            
        } catch (Exception $e) {
            error_log("❌ [JSON_PROTOCOL] Ошибка удаления протокола: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Получение всех протоколов для мероприятия
     */
    public function getEventProtocols($meroId) {
        try {
            $protocols = [];
            $protocolDir = $this->protocolsDir . "protocol_{$meroId}/";
            
            // Проверяем, существует ли папка мероприятия
            if (!is_dir($protocolDir)) {
                error_log("⚠️ [JSON_PROTOCOL] Папка мероприятия не найдена: $protocolDir");
                return [];
            }
            
            // Ищем все JSON файлы в папке мероприятия
            $pattern = $protocolDir . "*.json";
            $files = glob($pattern);
            
            if (empty($files)) {
                error_log("⚠️ [JSON_PROTOCOL] Протоколы не найдены в папке: $protocolDir");
                return [];
            }
            
            foreach ($files as $filepath) {
                try {
                    $jsonData = file_get_contents($filepath);
                    if ($jsonData === false) {
                        error_log("❌ [JSON_PROTOCOL] Ошибка чтения файла: $filepath");
                        continue;
                    }
                    
                    $protocolData = json_decode($jsonData, true);
                    if ($protocolData === null) {
                        error_log("❌ [JSON_PROTOCOL] Ошибка парсинга JSON: $filepath");
                        continue;
                    }
                    
                    // Извлекаем redisKey из имени файла или данных
                    $filename = basename($filepath, '.json');
                    $redisKey = $this->reconstructRedisKey($meroId, $filename);
                    
                    $protocols[$redisKey] = $protocolData; // Возвращаем полную структуру протокола
                    
                } catch (Exception $e) {
                    error_log("❌ [JSON_PROTOCOL] Ошибка обработки файла $filepath: " . $e->getMessage());
                    continue;
                }
            }
            
            error_log("✅ [JSON_PROTOCOL] Загружено протоколов для мероприятия $meroId: " . count($protocols));
            return $protocols;
            
        } catch (Exception $e) {
            error_log("❌ [JSON_PROTOCOL] Ошибка получения протоколов мероприятия: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Восстановление redisKey из имени файла
     */
    private function reconstructRedisKey($meroId, $filename) {
        // Пример: K-1_M_200_gruppa_1 -> protocol:1:K-1:М:200:группа 1
        
        // Разбиваем имя файла на части
        $parts = explode('_', $filename);
        
        if (count($parts) < 4) {
            // Если не удалось разобрать, возвращаем базовый ключ
            return "protocol:{$meroId}:K-1:М:200:группа 1";
        }
        
        $discipline = $parts[0];
        $sex = str_replace(['M', 'Z'], ['М', 'Ж'], $parts[1]);
        $distance = $parts[2];
        
        // Восстанавливаем название группы (может содержать подчеркивания)
        $ageGroupParts = array_slice($parts, 3);
        $ageGroup = implode(' ', $ageGroupParts);
        
        // Обратная транслитерация
        $translit = [
            'a' => 'а', 'b' => 'б', 'v' => 'в', 'g' => 'г', 'd' => 'д', 'e' => 'е', 'yo' => 'ё',
            'zh' => 'ж', 'z' => 'з', 'i' => 'и', 'y' => 'й', 'k' => 'к', 'l' => 'л', 'm' => 'м',
            'n' => 'н', 'o' => 'о', 'p' => 'п', 'r' => 'р', 's' => 'с', 't' => 'т', 'u' => 'у',
            'f' => 'ф', 'h' => 'х', 'ts' => 'ц', 'ch' => 'ч', 'sh' => 'ш', 'sch' => 'щ',
            'yu' => 'ю', 'ya' => 'я'
        ];
        
        $ageGroup = strtr($ageGroup, $translit);
        
        return "protocol:{$meroId}:{$discipline}:{$sex}:{$distance}:{$ageGroup}";
    }
    
    /**
     * Очистка старых протоколов (старше 7 дней)
     */
    public function cleanupOldProtocols($days = 7) {
        try {
            $cutoffTime = time() - ($days * 24 * 60 * 60);
            $files = glob($this->protocolsDir . "*.json");
            $deletedCount = 0;
            
            foreach ($files as $filepath) {
                if (filemtime($filepath) < $cutoffTime) {
                    if (unlink($filepath)) {
                        $deletedCount++;
                    }
                }
            }
            
            error_log("✅ [JSON_PROTOCOL] Удалено старых протоколов: $deletedCount");
            return $deletedCount;
            
        } catch (Exception $e) {
            error_log("❌ [JSON_PROTOCOL] Ошибка очистки старых протоколов: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Проверка защищенных данных в протоколе
     */
    public function hasProtectedData($redisKey) {
        try {
            $filepath = $this->getFilePathFromRedisKey($redisKey);
            
            if (!file_exists($filepath)) {
                return false;
            }
            
            $jsonData = file_get_contents($filepath);
            $protocolData = json_decode($jsonData, true);
            
            if (!$protocolData || !isset($protocolData['data']['participants'])) {
                return false;
            }
            
            // Проверяем, есть ли участники с защищенными данными
            foreach ($protocolData['data']['participants'] as $participant) {
                if (isset($participant['protected']) && $participant['protected']) {
                    return true;
                }
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("❌ [JSON_PROTOCOL] Ошибка проверки защищенных данных: " . $e->getMessage());
            return false;
        }
    }
} 
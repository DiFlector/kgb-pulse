<?php
/**
 * Менеджер протоколов - работа без Redis
 * Файл: www/lks/php/secretary/protocol_manager.php
 */

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../helpers.php";
require_once __DIR__ . "/age_group_calculator.php";

class ProtocolManager {
    private $db;
    private $protocolsDir;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->protocolsDir = __DIR__ . "/../../files/protocol/";
        
        // Создаем директорию если не существует
        if (!is_dir($this->protocolsDir)) {
            mkdir($this->protocolsDir, 0755, true);
        }
    }
    
    /**
     * Создание всех протоколов для мероприятия
     */
    public function createAllProtocols($meroId, $type, $disciplines) {
        try {
            $results = [];
            $errors = [];
            
            // Получаем информацию о мероприятии для class_distance
            $stmt = $this->db->prepare("SELECT class_distance FROM meros WHERE champn = ?");
            $stmt->execute([$meroId]);
            $classDistance = json_decode($stmt->fetchColumn(), true);
            
            foreach ($disciplines as $discipline) {
                $class = $discipline['class'];
                $sex = $discipline['sex'];
                $distance = $discipline['distance'];
                
                try {
                    $protocols = $this->createProtocolsForDiscipline($meroId, $class, $sex, $distance, $type, $classDistance);
                    $results = array_merge($results, $protocols);
                } catch (Exception $e) {
                    $errorMsg = "Дисциплина $class $sex $distance: " . $e->getMessage();
                    $errors[] = $errorMsg;
                    error_log("⚠️ [PROTOCOL_MANAGER] $errorMsg");
                    // Продолжаем обработку других дисциплин
                }
            }
            
            $success = !empty($results) || empty($errors);
            $message = '';
            
            if (!empty($results)) {
                $message = "Протоколы созданы успешно! Создано " . count($results) . " протоколов.";
            }
            
            if (!empty($errors)) {
                if (!empty($message)) {
                    $message .= " ";
                }
                $message .= "Ошибки: " . implode("; ", $errors);
            }
            
            if (empty($results) && empty($errors)) {
                $message = "Нет участников для создания протоколов";
            }
            
            return [
                'success' => $success,
                'message' => $message,
                'protocols' => $results,
                'errors' => $errors
            ];
            
        } catch (Exception $e) {
            error_log("❌ [PROTOCOL_MANAGER] Критическая ошибка создания протоколов: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Ошибка создания протоколов: ' . $e->getMessage(),
                'protocols' => [],
                'errors' => [$e->getMessage()]
            ];
        }
    }
    
    /**
     * Создание протоколов для конкретной дисциплины (по возрастным группам)
     */
    private function createProtocolsForDiscipline($meroId, $class, $sex, $distance, $type, $classDistance) {
        // Получаем oid мероприятия
        $stmt = $this->db->prepare("SELECT oid FROM meros WHERE champn = ?");
        $stmt->execute([$meroId]);
        $meroOid = $stmt->fetchColumn();
        
        if (!$meroOid) {
            throw new Exception("Мероприятие не найдено");
        }
        
        // Получаем участников
        $participants = $this->getParticipants($meroOid, $class, $sex, $distance);
        
        if (empty($participants)) {
            throw new Exception("Нет участников для дисциплины $class $sex $distance");
        }
        
        // Получаем возрастные группы для данной дисциплины
        $ageGroups = AgeGroupCalculator::getAgeGroupsForDiscipline($classDistance, $class, $sex);
        
        // Если возрастные группы не определены, используем упрощенные
        if (empty($ageGroups)) {
            $ageGroups = [
                ['name' => 'Открытый класс', 'min_age' => 15, 'max_age' => 39],
                ['name' => 'Синьоры А', 'min_age' => 40, 'max_age' => 59],
                ['name' => 'Синьоры B', 'min_age' => 60, 'max_age' => 150]
            ];
        }
        
        // Группируем участников по возрастным группам
        $groupedParticipants = $this->groupParticipantsByAgeGroups($participants, $ageGroups, $classDistance, $class, $sex, $distance);
        
        $protocols = [];
        
        // Создаем протокол только для возрастных групп с участниками
        foreach ($groupedParticipants as $ageGroupName => $groupParticipants) {
            // Создаем протокол только если есть участники
            if (!empty($groupParticipants)) {
                $protocol = $this->createProtocolForAgeGroup($meroId, $meroOid, $class, $sex, $distance, $ageGroupName, $groupParticipants, $type);
                $protocols[] = $protocol;
            }
        }
        
        return $protocols;
    }
    
    /**
     * Группировка участников по возрастным группам
     */
    private function groupParticipantsByAgeGroups($participants, $ageGroups, $classDistance, $class, $sex, $distance) {
        $grouped = [];
        
        // Инициализируем группы с правильными названиями (с префиксом пола)
        foreach ($ageGroups as $ageGroup) {
            $genderPrefix = $sex === 'М' ? 'Мужчины' : 'Женщины';
            // Используем полное название возрастной группы с названием и возрастным диапазоном
            $ageGroupFullName = isset($ageGroup['full_name']) ? $ageGroup['full_name'] : $ageGroup['name'];
            $fullGroupName = "$genderPrefix ({$ageGroupFullName})";
            $grouped[$fullGroupName] = [];
        }
        
        foreach ($participants as $participant) {
            $age = AgeGroupCalculator::calculateAgeOnDecember31($participant['birthdata']);
            $assignedGroup = null;
            
            // Ищем подходящую возрастную группу
            foreach ($ageGroups as $ageGroup) {
                if ($age >= $ageGroup['min_age'] && $age <= $ageGroup['max_age']) {
                    $genderPrefix = $sex === 'М' ? 'Мужчины' : 'Женщины';
                    // Используем полное название возрастной группы с названием и возрастным диапазоном
                    $ageGroupFullName = isset($ageGroup['full_name']) ? $ageGroup['full_name'] : $ageGroup['name'];
                    $assignedGroup = "$genderPrefix ({$ageGroupFullName})";
                    break;
                }
            }
            
            // Если группа не найдена, добавляем в первую доступную
            if (!$assignedGroup && !empty($ageGroups)) {
                $genderPrefix = $sex === 'М' ? 'Мужчины' : 'Женщины';
                // Используем полное название возрастной группы с названием и возрастным диапазоном
                $ageGroupFullName = isset($ageGroups[0]['full_name']) ? $ageGroups[0]['full_name'] : $ageGroups[0]['name'];
                $assignedGroup = "$genderPrefix ({$ageGroupFullName})";
            }
            
            if ($assignedGroup && isset($grouped[$assignedGroup])) {
                $grouped[$assignedGroup][] = $participant;
            }
        }
        
        return $grouped;
    }
    
    /**
     * Создание протокола для конкретной возрастной группы
     */
    private function createProtocolForAgeGroup($meroId, $meroOid, $class, $sex, $distance, $ageGroupName, $participants, $type) {
        // Определяем количество дорожек в зависимости от типа лодки
        $maxLanes = ($class === 'D-10') ? 6 : 9;
        
        // Создаем заезды
        $heats = $this->createHeatsForAgeGroup($participants, $ageGroupName, $maxLanes);
        
        // Формируем данные протокола
        $protocolData = [
            'meroId' => $meroId,
            'meroOid' => $meroOid,
            'class' => $class,
            'sex' => $sex,
            'distance' => $distance,
            'ageGroup' => $ageGroupName,
            'type' => $type,
            'participants' => $participants,
            'heats' => $heats,
            'participantsCount' => count($participants), // Добавляем количество участников
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Сохраняем протокол в файл
        $this->saveProtocolToFile($protocolData);
        
        return [
            'meroId' => $meroId,
            'class' => $class,
            'sex' => $sex,
            'distance' => $distance,
            'ageGroup' => $ageGroupName,
            'type' => $type,
            'participantsCount' => count($participants), // Добавляем количество участников
            'heats' => $heats,
            'created_at' => $protocolData['created_at']
        ];
    }
    
    /**
     * Получение участников для дисциплины
     */
    private function getParticipants($meroOid, $class, $sex, $distance) {
        $stmt = $this->db->prepare("
            SELECT l.*, u.fio, u.birthdata, u.city, u.sportzvanie, u.sex, u.userid
            FROM listreg l 
            JOIN users u ON l.users_oid = u.oid 
            WHERE l.meros_oid = ? 
            AND l.status IN ('Подтверждён', 'Зарегистрирован')
            AND u.sex = ?
        ");
        $stmt->execute([$meroOid, $sex]);
        $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $participants = [];
        
        foreach ($registrations as $reg) {
            $discipline = json_decode($reg['discipline'], true);
            
            if (isset($discipline[$class])) {
                $regDistances = is_array($discipline[$class]['dist']) ? 
                    $discipline[$class]['dist'] : 
                    explode(', ', $discipline[$class]['dist']);
                
                if (in_array($distance, array_map('trim', $regDistances))) {
                    $birthYear = date('Y', strtotime($reg['birthdata']));
                    $age = AgeGroupCalculator::calculateAgeOnDecember31($reg['birthdata']);
                    
                    $participants[] = [
                        'userid' => $reg['userid'], // Используем userid вместо пустого значения
                        'fio' => $reg['fio'],
                        'birthYear' => $birthYear,
                        'birthdata' => $reg['birthdata'],
                        'age' => $age,
                        'city' => $reg['city'],
                        'sportzvanie' => $reg['sportzvanie'] ?? 'БР',
                        'teamName' => $reg['city'],
                        'lane' => null,
                        'startNumber' => null,
                        'result' => null,
                        'place' => null,
                        'notes' => ''
                    ];
                }
            }
        }
        
        return $participants;
    }
    
    /**
     * Создание заездов для возрастной группы
     */
    private function createHeatsForAgeGroup($participants, $ageGroupName, $maxLanes) {
        $heats = [];
        $startNumber = 1;
        
        $participantCount = count($participants);
        
        if ($participantCount === 0) {
            // Создаем пустой заезд
            $heats[] = $this->createEmptyHeat($ageGroupName, $maxLanes);
            return $heats;
        }
        
        if ($participantCount <= $maxLanes) {
            // Один финальный заезд
            $heat = $this->createHeat($participants, $ageGroupName, 'Финал', 1, $startNumber, $maxLanes);
            $heats[] = $heat;
            
        } elseif ($participantCount <= $maxLanes * 2) {
            // Полуфинал + финал
            $shuffled = $participants;
            shuffle($shuffled);
            
            $heat1Participants = array_slice($shuffled, 0, ceil($participantCount / 2));
            $heat2Participants = array_slice($shuffled, ceil($participantCount / 2));
            
            $heat1 = $this->createHeat($heat1Participants, $ageGroupName, 'Полуфинал', 1, $startNumber, $maxLanes);
            $heats[] = $heat1;
            $startNumber += count($heat1Participants);
            
            $heat2 = $this->createHeat($heat2Participants, $ageGroupName, 'Полуфинал', 2, $startNumber, $maxLanes);
            $heats[] = $heat2;
            
        } else {
            // Предварительные + полуфинал + финал
            $shuffled = $participants;
            shuffle($shuffled);
            
            $heatCount = ceil($participantCount / $maxLanes);
            
            for ($i = 0; $i < $heatCount; $i++) {
                $heatParticipants = array_slice($shuffled, $i * $maxLanes, $maxLanes);
                if (!empty($heatParticipants)) {
                    $heat = $this->createHeat($heatParticipants, $ageGroupName, 'Предварительный', $i + 1, $startNumber, $maxLanes);
                    $heats[] = $heat;
                    $startNumber += count($heatParticipants);
                }
            }
        }
        
        return $heats;
    }
    
    /**
     * Создание пустого заезда
     */
    private function createEmptyHeat($ageGroupName, $maxLanes) {
        $lanes = [];
        
        for ($lane = 1; $lane <= $maxLanes; $lane++) {
            $lanes[$lane] = null;
        }
        
        return [
            'ageGroup' => $ageGroupName,
            'heatType' => 'Финал',
            'heatNumber' => 1,
            'lanes' => $lanes,
            'participantCount' => 0
        ];
    }
    
    /**
     * Создание заезда
     */
    private function createHeat($participants, $ageGroupName, $heatType, $heatNumber, $startNumber, $maxLanes) {
        shuffle($participants); // Случайное распределение по дорожкам
        
        $lanes = [];
        $currentStartNumber = $startNumber;
        
        for ($lane = 1; $lane <= $maxLanes; $lane++) {
            if (isset($participants[$lane - 1])) {
                $participant = $participants[$lane - 1];
                $participant['lane'] = $lane;
                $participant['startNumber'] = $currentStartNumber++;
                $lanes[$lane] = $participant;
            } else {
                $lanes[$lane] = null;
            }
        }
        
        return [
            'ageGroup' => $ageGroupName,
            'heatType' => $heatType,
            'heatNumber' => $heatNumber,
            'lanes' => $lanes,
            'participantCount' => count(array_filter($lanes))
        ];
    }
    
    /**
     * Сохранение протокола в файл
     */
    private function saveProtocolToFile($protocolData) {
        $ageGroupKey = str_replace(['(', ')', ' ', ':', '-'], ['', '', '_', '_', '_'], $protocolData['ageGroup']);
        $filename = sprintf(
            'protocol_%s_%s_%s_%s_%s_%s.json',
            $protocolData['meroId'],
            $protocolData['class'],
            $protocolData['sex'],
            $protocolData['distance'],
            $ageGroupKey,
            $protocolData['type']
        );
        
        $filepath = $this->protocolsDir . $filename;
        file_put_contents($filepath, json_encode($protocolData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        
        return $filename;
    }
    
    /**
     * Загрузка протокола из файла
     */
    public function loadProtocol($meroId, $class, $sex, $distance, $type, $ageGroup = null) {
        if ($ageGroup) {
            $ageGroupKey = str_replace(['(', ')', ' ', ':', '-'], ['', '', '_', '_', '_'], $ageGroup);
            $filename = sprintf(
                'protocol_%s_%s_%s_%s_%s_%s.json',
                $meroId,
                $class,
                $sex,
                $distance,
                $ageGroupKey,
                $type
            );
        } else {
            // Поиск по паттерну (для обратной совместимости)
            $pattern = sprintf(
                'protocol_%s_%s_%s_%s_*_%s.json',
                $meroId,
                $class,
                $sex,
                $distance,
                $type
            );
            $files = glob($this->protocolsDir . $pattern);
            
            if (empty($files)) {
                return null;
            }
            
            $filename = basename($files[0]);
        }
        
        $filepath = $this->protocolsDir . $filename;
        
        if (!file_exists($filepath)) {
            return null;
        }
        
        $content = file_get_contents($filepath);
        return json_decode($content, true);
    }
    
    /**
     * Получение списка всех протоколов мероприятия
     */
    public function getEventProtocols($meroId) {
        $pattern = "protocol_{$meroId}_*.json";
        $files = glob($this->protocolsDir . $pattern);
        
        $protocols = [];
        
        foreach ($files as $file) {
            $filename = basename($file);
            $content = file_get_contents($file);
            $data = json_decode($content, true);
            
            if ($data) {
                $protocols[] = [
                    'filename' => $filename,
                    'class' => $data['class'],
                    'sex' => $data['sex'],
                    'distance' => $data['distance'],
                    'ageGroup' => $data['ageGroup'] ?? 'Неопределенная группа',
                    'type' => $data['type'],
                    'participants' => $data['totalParticipants'],
                    'heats' => count($data['heats']),
                    'maxLanes' => $data['maxLanes'] ?? 9,
                    'created_at' => $data['created_at']
                ];
            }
        }
        
        return $protocols;
    }
    
    /**
     * Обновление протокола
     */
    public function updateProtocol($protocolData) {
        $ageGroupKey = str_replace(['(', ')', ' ', ':', '-'], ['', '', '_', '_', '_'], $protocolData['ageGroup']);
        $filename = sprintf(
            'protocol_%s_%s_%s_%s_%s_%s.json',
            $protocolData['meroId'],
            $protocolData['class'],
            $protocolData['sex'],
            $protocolData['distance'],
            $ageGroupKey,
            $protocolData['type']
        );
        
        $filepath = $this->protocolsDir . $filename;
        file_put_contents($filepath, json_encode($protocolData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        
        return $filename;
    }
    
    /**
     * Удаление протоколов мероприятия
     */
    public function deleteEventProtocols($meroId) {
        $pattern = "protocol_{$meroId}_*.json";
        $files = glob($this->protocolsDir . $pattern);
        
        $deleted = 0;
        foreach ($files as $file) {
            if (unlink($file)) {
                $deleted++;
            }
        }
        
        return $deleted;
    }
}
?> 
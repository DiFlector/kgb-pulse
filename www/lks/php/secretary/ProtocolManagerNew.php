<?php
/**
 * Новый менеджер протоколов с единой структурой данных для старта и финиша
 * Файл: www/lks/php/secretary/ProtocolManagerNew.php
 */

require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/RedisManager.php";
require_once __DIR__ . "/age_group_calculator.php";

class ProtocolManagerNew {
    private $db;
    private $redis;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->redis = RedisManager::getInstance();
    }
    
    /**
     * Автоматическое создание пустых протоколов (единый data-протокол)
     */
    public function autoCreateEmptyProtocols($meroId, $selectedDisciplines, $type = 'both') {
        try {
            error_log("🔄 [PROTOCOL_MANAGER_NEW] Автоматическое создание пустых протоколов для мероприятия $meroId (тип: $type)");
            
            $stmt = $this->db->prepare("SELECT class_distance FROM meros WHERE champn = ?");
            $stmt->execute([$meroId]);
            $classDistance = json_decode($stmt->fetchColumn(), true);
            if (!$classDistance) throw new Exception('Некорректная структура дисциплин в мероприятии');
            
            $createdProtocols = [];
            foreach ($selectedDisciplines as $discipline) {
                $class = $discipline['class'];
                $sex = $discipline['sex'];
                $distance = $discipline['distance'];
                $ageGroups = $this->getAgeGroupsForDiscipline($classDistance, $class, $sex);
                if (empty($ageGroups)) continue;
                
                foreach ($ageGroups as $ageGroup) {
                    // Создаем протоколы в зависимости от типа
                    if ($type === 'start' || $type === 'both') {
                        $protocol = $this->createEmptyDataProtocol($meroId, $class, $sex, $distance, $ageGroup, 'start');
                        if ($protocol) $createdProtocols[] = $protocol;
                    }
                    
                    if ($type === 'finish' || $type === 'both') {
                        $protocol = $this->createEmptyDataProtocol($meroId, $class, $sex, $distance, $ageGroup, 'finish');
                        if ($protocol) $createdProtocols[] = $protocol;
                    }
                }
            }
            
            $typeText = $type === 'start' ? 'стартовых' : ($type === 'finish' ? 'финишных' : 'всех');
            return [
                'success' => true,
                'protocols' => $createdProtocols,
                'message' => "Пустые $typeText протоколов созданы автоматически"
            ];
        } catch (Exception $e) {
            error_log("❌ [PROTOCOL_MANAGER_NEW] Ошибка создания пустых протоколов: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'protocols' => []
            ];
        }
    }

    /**
     * Создание пустого data-протокола
     */
    private function createEmptyDataProtocol($meroId, $class, $sex, $distance, $ageGroup, $type = 'both') {
        try {
            $stmt = $this->db->prepare("SELECT oid FROM meros WHERE champn = ?");
            $stmt->execute([$meroId]);
            $meroOid = $stmt->fetchColumn();
            if (!$meroOid) return null;
            
            $maxLanes = ($class === 'D-10') ? 6 : 9;
            $ageGroupKey = str_replace(['(', ')', ' ', ':', '-'], ['', '', '_', '_', '_'], $ageGroup['full_name']);
            $redisKey = "protocol:data:{$meroId}:{$class}:{$sex}:{$distance}:{$ageGroupKey}";
            
            // Проверяем, существует ли уже протокол
            if ($this->redis->exists($redisKey)) {
                // Если протокол уже существует, возвращаем информацию о нем
                $existingData = $this->redis->loadProtocol($redisKey);
                if ($existingData) {
                    return [
                        'redisKey' => $redisKey,
                        'class' => $class,
                        'sex' => $sex,
                        'distance' => $distance,
                        'ageGroup' => $ageGroup['full_name'],
                        'participantsCount' => count($existingData['participants'] ?? []),
                        'maxLanes' => $maxLanes,
                        'type' => $type
                    ];
                }
            }
            
            $protocolData = [
                'meroId' => $meroId,
                'meroOid' => $meroOid,
                'class' => $class,
                'sex' => $sex,
                'distance' => $distance,
                'ageGroup' => $ageGroup['full_name'],
                'ageGroupName' => $ageGroup['name'],
                'participants' => [],
                'maxLanes' => $maxLanes,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            $this->redis->saveProtocol($redisKey, $protocolData);
            
            return [
                'redisKey' => $redisKey,
                'class' => $class,
                'sex' => $sex,
                'distance' => $distance,
                'ageGroup' => $ageGroup['full_name'],
                'participantsCount' => 0,
                'maxLanes' => $maxLanes,
                'type' => $type
            ];
        } catch (Exception $e) {
            error_log("❌ [PROTOCOL_MANAGER_NEW] Ошибка создания data-протокола: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Проведение жеребьевки: распределяет участников, сохраняет их в data-протокол
     */
    public function conductDraw($meroId) {
        try {
            error_log("🎲 [PROTOCOL_MANAGER_NEW] Проведение жеребьевки для мероприятия $meroId");
            $protocols = $this->getEventProtocols($meroId);
            if (empty($protocols)) throw new Exception('Нет протоколов для жеребьевки');
            $drawnProtocols = [];
            foreach ($protocols as $protocol) {
                $drawnProtocol = $this->conductDrawForProtocol($protocol);
                if ($drawnProtocol) $drawnProtocols[] = $drawnProtocol;
            }
            return [
                'success' => true,
                'protocols' => $drawnProtocols,
                'message' => 'Жеребьевка проведена успешно'
            ];
        } catch (Exception $e) {
            error_log("❌ [PROTOCOL_MANAGER_NEW] Ошибка жеребьевки: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'protocols' => []
            ];
        }
    }

    /**
     * Жеребьевка для одного data-протокола
     */
    private function conductDrawForProtocol($protocol) {
        try {
            $redisKey = $protocol['redisKey'];
            $class = $protocol['class'];
            $sex = $protocol['sex'];
            $distance = $protocol['distance'];
            $ageGroup = $protocol['ageGroup'];
            $meroOid = $protocol['meroOid'];
            $participants = $this->getParticipantsForAgeGroup($meroOid, $class, $sex, $distance, $ageGroup);
            if (empty($participants)) return null;
            $drawnParticipants = $this->drawParticipants($participants, $protocol['maxLanes']);
            $protocolData = $this->redis->loadProtocol($redisKey);
            $protocolData['participants'] = $drawnParticipants;
            $protocolData['updated_at'] = date('Y-m-d H:i:s');
            $this->redis->saveProtocol($redisKey, $protocolData);
            return [
                'redisKey' => $redisKey,
                'class' => $class,
                'sex' => $sex,
                'distance' => $distance,
                'ageGroup' => $ageGroup,
                'participantsCount' => count($drawnParticipants)
            ];
        } catch (Exception $e) {
            error_log("❌ [PROTOCOL_MANAGER_NEW] Ошибка жеребьевки протокола: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Получение участников для возрастной группы
     */
    private function getParticipantsForAgeGroup($meroOid, $class, $sex, $distance, $ageGroup) {
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
                    $age = AgeGroupCalculator::calculateAgeOnDecember31($reg['birthdata']);
                    if ($this->isParticipantInAgeGroup($age, $ageGroup)) {
                        $birthYear = date('Y', strtotime($reg['birthdata']));
                        $participants[] = [
                            'userid' => $reg['userid'],
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
        }
        return $participants;
    }

    /**
     * Проверка, входит ли участник в возрастную группу
     */
    private function isParticipantInAgeGroup($age, $ageGroup) {
        $groups = $this->parseAgeGroups($ageGroup);
        foreach ($groups as $group) {
            if ($age >= $group['min_age'] && $age <= $group['max_age']) return true;
        }
        return false;
    }

    /**
     * Проведение жеребьевки участников
     */
    private function drawParticipants($participants, $maxLanes) {
        shuffle($participants);
        $drawnParticipants = [];
        $startNumber = 1;
        foreach ($participants as $index => $participant) {
            $lane = ($index % $maxLanes) + 1;
            $participant['lane'] = $lane;
            $participant['startNumber'] = $startNumber++;
            $drawnParticipants[] = $participant;
        }
        return $drawnParticipants;
    }

    /**
     * Получение всех data-протоколов мероприятия
     */
    public function getEventProtocols($meroId) {
        return $this->redis->getEventProtocols($meroId, 'data');
    }

    /**
     * Получение протокола по ключу
     */
    public function getProtocol($redisKey) {
        return $this->redis->loadProtocol($redisKey);
    }

    /**
     * Обновление протокола (например, при ручном добавлении участника)
     */
    public function updateProtocol($redisKey, $protocolData) {
        $protocolData['updated_at'] = date('Y-m-d H:i:s');
        return $this->redis->saveProtocol($redisKey, $protocolData);
    }

    /**
     * Сохранение результатов финишного протокола (только место и время)
     */
    public function saveFinishResults($redisKey, $results) {
        try {
            $protocol = $this->redis->loadProtocol($redisKey);
            if (!$protocol) throw new Exception('Протокол не найден');
            // Обновляем только место и время
            foreach ($results as $result) {
                foreach ($protocol['participants'] as &$participant) {
                    if ($participant['userid'] == $result['userid']) {
                        $participant['result'] = $result['result'] ?? null;
                        $participant['place'] = $result['place'] ?? null;
                        break;
                    }
                }
            }
            $protocol['updated_at'] = date('Y-m-d H:i:s');
            $this->redis->saveProtocol($redisKey, $protocol);
            return true;
        } catch (Exception $e) {
            error_log("❌ [PROTOCOL_MANAGER_NEW] Ошибка сохранения результатов: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Получение возрастных групп для дисциплины и пола
     */
    private function getAgeGroupsForDiscipline($classDistance, $class, $sex) {
        if (!isset($classDistance[$class])) return [];
        $disciplineData = $classDistance[$class];
        if (!isset($disciplineData['sex']) || !isset($disciplineData['age_group'])) return [];
        $sexes = $disciplineData['sex'];
        $ageGroups = $disciplineData['age_group'];
        $sexIndex = array_search($sex, $sexes);
        if ($sexIndex === false) return [];
        if (!isset($ageGroups[$sexIndex])) return [];
        $ageGroupString = $ageGroups[$sexIndex];
        return $this->parseAgeGroups($ageGroupString);
    }
    private function parseAgeGroups($ageGroupString) {
        $groups = [];
        $groupStrings = explode(', ', $ageGroupString);
        foreach ($groupStrings as $groupString) {
            $parts = explode(': ', $groupString);
            if (count($parts) === 2) {
                $groupName = trim($parts[0]);
                $range = trim($parts[1]);
                $rangeParts = explode('-', $range);
                if (count($rangeParts) === 2) {
                    $minAge = (int)trim($rangeParts[0]);
                    $maxAge = (int)trim($rangeParts[1]);
                    $groups[] = [
                        'name' => $groupName,
                        'min_age' => $minAge,
                        'max_age' => $maxAge,
                        'full_name' => $groupString
                    ];
                }
            }
        }
        return $groups;
    }
}
?> 
<?php
/**
 * Класс для управления мероприятиями секретаря
 * Полная система жеребьевки, создания протоколов и обработки результатов
 */
class SecretaryEventManager {
    private $pdo;
    private $redis;
    private $meroId;
    private $meroOid;
    private $eventData;
    
    public function __construct($pdo, $redis, $meroId) {
        $this->pdo = $pdo;
        $this->redis = $redis;
        $this->meroId = $meroId;
        $this->loadEventData();
    }
    
    /**
     * Загрузка данных мероприятия
     */
    private function loadEventData() {
        $stmt = $this->pdo->prepare("
            SELECT oid, champn, meroname, merodata, class_distance, status::text as status 
            FROM meros 
            WHERE champn = ?
        ");
        $stmt->execute([$this->meroId]);
        $this->eventData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$this->eventData) {
            throw new Exception("Мероприятие не найдено");
        }
        
        $this->meroOid = $this->eventData['oid'];
    }
    
    /**
     * Получение доступных дисциплин для жеребьевки
     */
    public function getAvailableDisciplines() {
        $classDistance = json_decode($this->eventData['class_distance'], true);
        $disciplines = [];
        
        if (!$classDistance) {
            return $disciplines;
        }
        
        foreach ($classDistance as $class => $classData) {
            if (!isset($classData['sex']) || !isset($classData['dist']) || !isset($classData['age_group'])) {
                continue;
            }
            
            $sexes = $classData['sex'];
            $distances = $classData['dist'];
            $ageGroups = $classData['age_group'];
            
            // Обрабатываем каждую комбинацию пол/дистанция
            for ($i = 0; $i < count($sexes); $i++) {
                $sex = $sexes[$i];
                $distanceString = $distances[$i];
                $ageGroupString = $ageGroups[$i];
                
                // Разбираем дистанции
                $distanceArray = array_map('trim', explode(',', $distanceString));
                
                foreach ($distanceArray as $distance) {
                    $disciplineKey = "{$class}_{$sex}_{$distance}";
                    
                    // Проверяем, есть ли участники для данной дисциплины
                    $participantsCount = $this->getParticipantsCountForDiscipline($class, $sex, $distance);
                    
                    if ($participantsCount > 0) {
                        $disciplines[] = [
                            'key' => $disciplineKey,
                            'class' => $class,
                            'sex' => $sex,
                            'distance' => $distance,
                            'participants_count' => $participantsCount,
                            'age_groups' => $this->parseAgeGroups($ageGroupString)
                        ];
                    }
                }
            }
        }
        
        return $disciplines;
    }
    
    /**
     * Получение количества участников для дисциплины
     */
    private function getParticipantsCountForDiscipline($class, $sex, $distance) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count
            FROM listreg lr
            JOIN users u ON lr.users_oid = u.oid
            WHERE lr.meros_oid = ?
            AND lr.status IN ('Подтверждён', 'Зарегистрирован')
            AND lr.discipline::text LIKE ?
            AND u.sex = ?
        ");
        
        $classPattern = '%"' . $class . '"%';
        $stmt->execute([$this->meroOid, $classPattern, $sex]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['count'];
    }
    
    /**
     * Парсинг возрастных групп
     */
    private function parseAgeGroups($ageGroupString) {
        $groups = [];
        $groupArray = array_map('trim', explode(',', $ageGroupString));
        
        foreach ($groupArray as $group) {
            if (preg_match('/^(.+?):\s*(\d+)-(\d+)$/', $group, $matches)) {
                $groups[] = [
                    'name' => trim($matches[1]),
                    'min_age' => (int)$matches[2],
                    'max_age' => (int)$matches[3],
                    'display_name' => trim($matches[1]) . ': ' . $matches[2] . '-' . $matches[3]
                ];
            }
        }
        
        return $groups;
    }
    
    /**
     * Проведение жеребьевки для выбранных дисциплин
     */
    public function conductDraw($selectedDisciplines) {
        $protocols = [];
        
        error_log("Начинаем жеребьевку для дисциплин: " . var_export($selectedDisciplines, true));
        
        foreach ($selectedDisciplines as $disciplineString) {
            error_log("Обрабатываем дисциплину: $disciplineString");
            
            // Парсим строку дисциплины в массив
            $discipline = $this->parseDisciplineString($disciplineString);
            
            if (!$discipline) {
                error_log("Не удалось распарсить дисциплину: $disciplineString");
                continue;
            }
            
            error_log("Распарсенная дисциплина: " . var_export($discipline, true));
            
            $participants = $this->getParticipantsForDiscipline($discipline);
            
            if (empty($participants)) {
                continue;
            }
            
            // Группируем участников по возрастным группам
            $groupedParticipants = $this->groupParticipantsByAgeGroups($participants, $discipline);
            
            // Создаем структуру протокола для JavaScript
            $protocol = [
                'discipline' => $discipline['class'],
                'distance' => $discipline['distance'],
                'sex' => $discipline['sex'],
                'ageGroups' => []
            ];
            
            // Проводим жеребьевку для каждой группы
            foreach ($groupedParticipants as $ageGroup => $groupParticipants) {
                $drawResult = $this->conductDrawForGroup($groupParticipants, $discipline, $ageGroup);
                
                // Создаем возрастную группу для JavaScript
                $ageGroupData = [
                    'name' => $ageGroup, // Используем полное название группы с диапазоном возрастов
                    'protocol_number' => count($protocol['ageGroups']) + 1,
                    'redisKey' => "protocol:{$this->meroOid}:{$discipline['class']}:{$discipline['sex']}:{$discipline['distance']}:{$ageGroup}",
                    'protected' => false,
                    'participants' => []
                ];
                
                // Добавляем участников в возрастную группу
                foreach ($drawResult['heats'] as $heatIndex => $heat) {
                    foreach ($heat as $participant) {
                        $ageGroupData['participants'][] = [
                            'user_id' => $participant['user_id'],
                            'userid' => $participant['userid'],
                            'fio' => $participant['fio'],
                            'birthdata' => $participant['birthdata'],
                            'sportzvanie' => $participant['sportzvanie'],
                            'city' => $participant['city'],
                            'teamname' => $participant['teamname'] ?? '',
                            'teamcity' => $participant['teamcity'] ?? '',
                            'lane' => $participant['lane'],
                            'start_number' => $participant['start_number'],
                            'place' => null,
                            'finishTime' => null,
                            'water' => '' // Добавляем поле "вода" с пустым значением по умолчанию
                        ];
                    }
                }
                
                $protocol['ageGroups'][] = $ageGroupData;
            }
            
            $protocols[] = $protocol;
        }
        
        // Сохраняем результаты в Redis
        $this->saveDrawResults($protocols);
        
        return $protocols;
    }
    
    /**
     * Парсинг строки дисциплины в массив
     */
    private function parseDisciplineString($disciplineString) {
        // Формат: "C-1_М_200" -> {class: "C-1", sex: "М", distance: "200"}
        $parts = explode('_', $disciplineString);
        
        if (count($parts) !== 3) {
            error_log("Неверный формат дисциплины: $disciplineString");
            return null;
        }
        
        return [
            'class' => $parts[0],
            'sex' => $parts[1],
            'distance' => $parts[2]
        ];
    }
    
    /**
     * Получение участников для дисциплины
     */
    private function getParticipantsForDiscipline($discipline) {
        // Проверяем, что дисциплина является массивом
        if (!is_array($discipline)) {
            error_log("Дисциплина не является массивом: " . var_export($discipline, true));
            return [];
        }
        
        // Проверяем наличие обязательных полей
        if (!isset($discipline['class']) || !isset($discipline['sex'])) {
            error_log("Отсутствуют обязательные поля в дисциплине: " . var_export($discipline, true));
            return [];
        }
        
        $stmt = $this->pdo->prepare("
            SELECT 
                u.oid as user_id,
                u.userid,
                u.fio,
                u.sex,
                u.birthdata,
                u.sportzvanie,
                u.city,
                lr.discipline,
                lr.status,
                t.teamname,
                t.teamcity
            FROM listreg lr
            JOIN users u ON lr.users_oid = u.oid
            LEFT JOIN teams t ON lr.teams_oid = t.oid
            WHERE lr.meros_oid = ?
            AND lr.status IN ('Подтверждён', 'Зарегистрирован')
            AND lr.discipline::text LIKE ?
            AND u.sex = ?
        ");
        
        $classPattern = '%"' . $discipline['class'] . '"%';
        $stmt->execute([$this->meroOid, $classPattern, $discipline['sex']]);
        
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Добавляем возраст и проверяем участие в дистанции
        foreach ($participants as &$participant) {
            $participant['age'] = $this->calculateAge($participant['birthdata']);
            $participant['participates_in_distance'] = $this->checkParticipatesInDistance($participant, $discipline);
            $participant['water'] = ''; // Добавляем поле "вода" с пустым значением по умолчанию
        }
        
        return array_filter($participants, function($p) {
            return $p['participates_in_distance'];
        });
    }
    
    /**
     * Расчет возраста на 31 декабря текущего года
     */
    private function calculateAge($birthdata) {
        $birthYear = date('Y', strtotime($birthdata));
        $currentYear = date('Y');
        return $currentYear - $birthYear;
    }
    
    /**
     * Проверка участия в дистанции
     */
    private function checkParticipatesInDistance($participant, $discipline) {
        $disciplineData = json_decode($participant['discipline'], true);
        
        if (!isset($disciplineData[$discipline['class']])) {
            return false;
        }
        
        $classData = $disciplineData[$discipline['class']];
        
        if (!isset($classData['dist'])) {
            return false;
        }
        
        $distances = $classData['dist'];
        return in_array($discipline['distance'], $distances);
    }
    
    /**
     * Группировка участников по возрастным группам
     */
    private function groupParticipantsByAgeGroups($participants, $discipline) {
        // Получаем возрастные группы из class_distance
        $classDistance = json_decode($this->eventData['class_distance'], true);
        
        if (!isset($classDistance[$discipline['class']])) {
            error_log("Класс {$discipline['class']} не найден в class_distance");
            return ['Общая группа' => $participants];
        }
        
        $classData = $classDistance[$discipline['class']];
        
        if (!isset($classData['sex']) || !isset($classData['age_group'])) {
            error_log("Отсутствуют данные о полах или возрастных группах для класса {$discipline['class']}");
            return ['Общая группа' => $participants];
        }
        
        // Находим индекс пола
        $sexIndex = array_search($discipline['sex'], $classData['sex']);
        
        if ($sexIndex === false) {
            error_log("Пол {$discipline['sex']} не найден для класса {$discipline['class']}");
            return ['Общая группа' => $participants];
        }
        
        // Получаем строку возрастных групп для данного пола
        $ageGroupString = $classData['age_group'][$sexIndex];
        
        // Парсим возрастные группы
        $ageGroups = $this->parseAgeGroups($ageGroupString);
        
        if (empty($ageGroups)) {
            error_log("Не удалось распарсить возрастные группы: $ageGroupString");
            return ['Общая группа' => $participants];
        }
        
        // Группируем участников по возрастным группам
        $grouped = [];
        
        foreach ($ageGroups as $ageGroup) {
            $grouped[$ageGroup['display_name']] = [];
        }
        
        foreach ($participants as $participant) {
            $age = $this->calculateAge($participant['birthdata']);
            $assignedGroup = null;
            
            // Ищем подходящую возрастную группу
            foreach ($ageGroups as $ageGroup) {
                if ($age >= $ageGroup['min_age'] && $age <= $ageGroup['max_age']) {
                    $assignedGroup = $ageGroup['display_name'];
                    break;
                }
            }
            
            if ($assignedGroup) {
                $grouped[$assignedGroup][] = $participant;
            } else {
                error_log("Участник {$participant['fio']} (возраст: $age) не подходит ни под одну возрастную группу");
            }
        }
        
        // Удаляем пустые группы
        $grouped = array_filter($grouped, function($participants) {
            return !empty($participants);
        });
        
        if (empty($grouped)) {
            error_log("Нет участников в возрастных группах, возвращаем общую группу");
            return ['Общая группа' => $participants];
        }
        
        return $grouped;
    }
    
    /**
     * Проведение жеребьевки для группы участников
     */
    private function conductDrawForGroup($participants, $discipline, $ageGroup) {
        // Случайное перемешивание участников
        shuffle($participants);
        
        // Определяем количество дорожек
        $maxLanes = $this->getMaxLanesForBoat($discipline['class']);
        
        // Распределяем по дорожкам
        $heats = $this->distributeToHeats($participants, $maxLanes);
        
        // Присваиваем стартовые номера
        $startNumber = 1;
        foreach ($heats as &$heat) {
            foreach ($heat as &$participant) {
                $participant['start_number'] = $startNumber++;
                $participant['lane'] = $participant['lane'] ?? 1;
            }
        }
        
        return [
            'discipline' => $discipline,
            'age_group' => $ageGroup,
            'heats' => $heats,
            'total_participants' => count($participants),
            'created_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Определение максимального количества дорожек для типа лодки
     */
    private function getMaxLanesForBoat($boatClass) {
        switch ($boatClass) {
            case 'D-10':
                return 6; // Драконы - 6 дорожек
            case 'K-1':
            case 'C-1':
                return 9; // Одиночные - 9 дорожек
            case 'K-2':
            case 'C-2':
                return 9; // Двойки - 9 дорожек
            case 'K-4':
            case 'C-4':
                return 9; // Четверки - 9 дорожек
            default:
                return 9; // По умолчанию 9 дорожек
        }
    }
    
    /**
     * Распределение участников по заездам
     */
    private function distributeToHeats($participants, $maxLanes) {
        $heats = [];
        $currentHeat = [];
        
        foreach ($participants as $index => $participant) {
            $lane = ($index % $maxLanes) + 1;
            $participant['lane'] = $lane;
            $currentHeat[] = $participant;
            
            // Если дорожка заполнена или это последний участник
            if ($lane == $maxLanes || $index == count($participants) - 1) {
                $heats[] = $currentHeat;
                $currentHeat = [];
            }
        }
        
        return $heats;
    }
    
    /**
     * Сохранение результатов жеребьевки в Redis
     */
    private function saveDrawResults($results) {
        $key = "draw_results:{$this->meroId}";
        $this->redis->setex($key, 86400, json_encode($results)); // TTL 24 часа
    }
    
    /**
     * Получение результатов жеребьевки
     */
    public function getDrawResults() {
        $key = "draw_results:{$this->meroId}";
        $data = $this->redis->get($key);
        return $data ? json_decode($data, true) : [];
    }
    
    /**
     * Создание протоколов на основе результатов жеребьевки
     */
    public function createProtocols($drawResults) {
        $protocols = [];
        
        foreach ($drawResults as $result) {
            $startProtocol = $this->createStartProtocol($result);
            $finishProtocol = $this->createFinishProtocol($result);
            
            $protocols[] = [
                'discipline' => $result['discipline'],
                'age_group' => $result['age_group'],
                'start_protocol' => $startProtocol,
                'finish_protocol' => $finishProtocol
            ];
        }
        
        // Сохраняем протоколы в Redis
        $this->saveProtocols($protocols);
        
        return $protocols;
    }
    
    /**
     * Создание стартового протокола
     */
    private function createStartProtocol($result) {
        $protocol = [
            'type' => 'start',
            'discipline' => $result['discipline'],
            'age_group' => $result['age_group'],
            'heats' => [],
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        foreach ($result['heats'] as $heatIndex => $heat) {
            $protocol['heats'][] = [
                'heat_number' => $heatIndex + 1,
                'participants' => $heat
            ];
        }
        
        return $protocol;
    }
    
    /**
     * Создание финишного протокола
     */
    private function createFinishProtocol($result) {
        $protocol = [
            'type' => 'finish',
            'discipline' => $result['discipline'],
            'age_group' => $result['age_group'],
            'heats' => [],
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        foreach ($result['heats'] as $heatIndex => $heat) {
            $finishHeat = [];
            foreach ($heat as $participant) {
                $finishHeat[] = [
                    'user_id' => $participant['user_id'],
                    'fio' => $participant['fio'],
                    'start_number' => $participant['start_number'],
                    'lane' => $participant['lane'],
                    'result_time' => null,
                    'place' => null,
                    'notes' => ''
                ];
            }
            
            $protocol['heats'][] = [
                'heat_number' => $heatIndex + 1,
                'participants' => $finishHeat
            ];
        }
        
        return $protocol;
    }
    
    /**
     * Сохранение протоколов в Redis
     */
    private function saveProtocols($protocols) {
        $key = "protocols:{$this->meroId}";
        $this->redis->setex($key, 86400, json_encode($protocols)); // TTL 24 часа
    }
    
    /**
     * Получение протоколов
     */
    public function getProtocols() {
        $key = "protocols:{$this->meroId}";
        $data = $this->redis->get($key);
        return $data ? json_decode($data, true) : [];
    }
    
    /**
     * Сохранение результатов финиша
     */
    public function saveFinishResults($disciplineKey, $ageGroup, $heatNumber, $results) {
        $protocols = $this->getProtocols();
        
        foreach ($protocols as &$protocol) {
            if ($protocol['discipline']['key'] === $disciplineKey && 
                $protocol['age_group'] === $ageGroup) {
                
                foreach ($protocol['finish_protocol']['heats'] as &$heat) {
                    if ($heat['heat_number'] == $heatNumber) {
                        foreach ($heat['participants'] as &$participant) {
                            foreach ($results as $result) {
                                if ($result['user_id'] == $participant['user_id']) {
                                    $participant['result_time'] = $result['result_time'];
                                    $participant['place'] = $result['place'];
                                    $participant['notes'] = $result['notes'] ?? '';
                                    break;
                                }
                            }
                        }
                        break;
                    }
                }
                break;
            }
        }
        
        $this->saveProtocols($protocols);
        return true;
    }
    
    /**
     * Получение итоговых результатов
     */
    public function getFinalResults() {
        $protocols = $this->getProtocols();
        $results = [];
        
        foreach ($protocols as $protocol) {
            $disciplineResults = [
                'discipline' => $protocol['discipline'],
                'age_group' => $protocol['age_group'],
                'participants' => []
            ];
            
            foreach ($protocol['finish_protocol']['heats'] as $heat) {
                foreach ($heat['participants'] as $participant) {
                    if ($participant['result_time']) {
                        $disciplineResults['participants'][] = $participant;
                    }
                }
            }
            
            // Сортируем по времени
            usort($disciplineResults['participants'], function($a, $b) {
                return strtotime($a['result_time']) - strtotime($b['result_time']);
            });
            
            // Присваиваем места
            foreach ($disciplineResults['participants'] as $index => &$participant) {
                $participant['final_place'] = $index + 1;
            }
            
            $results[] = $disciplineResults;
        }
        
        return $results;
    }
}
?> 
<?php
/**
 * Менеджер для управления стоимостью участия в командах
 * Обрабатывает расчет и пересчет стоимости согласно правилам системы
 */

require_once __DIR__ . '/../db/Database.php';

class TeamCostManager {
    
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Определение одиночной лодки
     */
    private function isSingleBoat($boatClass) {
        $normalizedClass = $this->normalizeBoatClass($boatClass);
        return in_array($normalizedClass, ['K-1', 'C-1', 'HD-1', 'OD-1', 'OC-1']);
    }
    
    /**
     * Получение вместимости лодки
     */
    private function getBoatCapacity($boatClass) {
        $normalizedClass = $this->normalizeBoatClass($boatClass);
        $capacities = [
            'K-1' => 1, 'C-1' => 1,
            'K-2' => 2, 'C-2' => 2,
            'K-4' => 4, 'C-4' => 4,
            'D-10' => 10, 'HD-1' => 1, 'OD-1' => 1, 'OD-2' => 2, 'OC-1' => 1
        ];
        return $capacities[$normalizedClass] ?? 1;
    }
    
    /**
     * Нормализация класса лодки
     */
    private function normalizeBoatClass($boatClass) {
        if (empty($boatClass)) return null;
        
        $boatClass = trim($boatClass);
        $boatClass = str_replace(['К', 'к'], 'K', $boatClass);
        $boatClass = str_replace(['С', 'с'], 'C', $boatClass);
        $boatClass = str_replace(['Д', 'д'], 'D', $boatClass);
        $boatClass = strtoupper($boatClass);
        
        $mapping = [
            'D-10' => 'D-10', 'D10' => 'D-10', 'D10M' => 'D-10', 'D10W' => 'D-10',
            'K-1' => 'K-1', 'K1' => 'K-1', 'K-2' => 'K-2', 'K2' => 'K-2', 'K-4' => 'K-4', 'K4' => 'K-4',
            'C-1' => 'C-1', 'C1' => 'C-1', 'C-2' => 'C-2', 'C2' => 'C-2', 'C-4' => 'C-4', 'C4' => 'C-4',
            'HD-1' => 'HD-1', 'HD1' => 'HD-1', 'OD-1' => 'OD-1', 'OD1' => 'OD-1', 'OD-2' => 'OD-2', 'OD2' => 'OD-2', 'OC-1' => 'OC-1', 'OC1' => 'OC-1'
        ];
        
        return $mapping[$boatClass] ?? $boatClass;
    }
    
    /**
     * Расчет стоимости команды
     * @param int $teamOid OID команды
     * @param int $eventOid OID мероприятия
     * @return float Стоимость команды
     */
    public function calculateTeamCost($teamOid, $eventOid) {
        try {
            // Получаем информацию о команде
            $team = $this->db->fetchOne("SELECT persons_amount, class FROM teams WHERE oid = ?", [$teamOid]);
            if (!$team) {
                error_log("Команда не найдена: oid = {$teamOid}");
                return 0;
            }
            
            // Получаем базовую стоимость мероприятия
            $event = $this->db->fetchOne("SELECT defcost FROM meros WHERE oid = ?", [$eventOid]);
            if (!$event || !$event['defcost']) {
                error_log("Мероприятие не найдено или не имеет стоимости: oid = {$eventOid}");
                return 0;
            }
            
            // Рассчитываем стоимость на основе количества участников
            $baseCost = floatval($event['defcost']);
            $participantsCount = intval($team['persons_amount']);
            
            // Для драконов (D-10) стоимость рассчитывается по 10 участникам
            if (strpos($team['class'], 'D-') !== false) {
                $participantsCount = 10;
            }
            
            $totalCost = $baseCost * $participantsCount;
            
            error_log("Расчет стоимости команды: команда {$teamOid}, мероприятие {$eventOid}, участников: {$participantsCount}, базовая стоимость: {$baseCost}, итоговая стоимость: {$totalCost}");
            
            return $totalCost;
            
        } catch (Exception $e) {
            error_log("Ошибка расчета стоимости команды: " . $e->getMessage());
            return 0;
        }
    }
    

    
    /**
     * Расчет стоимости участия согласно правилам
     * @param string $boatClass Класс лодки
     * @param int $eventId ID мероприятия
     * @param int|null $teamId ID команды (для групповых)
     * @param int $distanceCount Количество дистанций
     * @return float Стоимость участия
     */
    public function calculateParticipationCost($boatClass, $eventId, $teamId = null, $distanceCount = 1) {
        try {
            // Получаем базовую стоимость мероприятия
            $event = $this->db->fetchOne("SELECT defcost FROM meros WHERE oid = ?", [$eventId]);
            if (!$event || !$event['defcost']) {
                return 0; // Если стоимость не указана
            }
            
            $baseCost = (float)$event['defcost'];
            
            if ($this->isSingleBoat($boatClass)) {
                // Одиночки: полная стоимость
                return $baseCost;
            }
            
            if ($boatClass === 'D-10') {
                // Драконы: базовая стоимость * количество дистанций / количество людей в команде
                if ($teamId) {
                    $team = $this->db->fetchOne("SELECT persons_amount FROM teams WHERE oid = ?", [$teamId]);
                    $teamSize = $team ? max(1, $team['persons_amount']) : 10; // Минимум 1 для избежания деления на 0
                    return ($baseCost * $distanceCount) / $teamSize;
                }
                return ($baseCost * $distanceCount) / 10; // По умолчанию на 10 человек
            }
            
            // Остальные групповые лодки: базовая стоимость / количество участников в лодке
            $boatCapacity = $this->getBoatCapacity($boatClass);
            return $baseCost / $boatCapacity;
            
        } catch (Exception $e) {
            error_log("Ошибка расчета стоимости участия: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Пересчет стоимости для всех участников команды драконов
     * @param int $teamId ID команды
     * @param int $eventId ID мероприятия
     * @return bool Успешность операции
     */
    public function recalculateTeamCosts($teamId, $eventId) {
        try {
            // Получаем всех участников команды
            $teamMembers = $this->db->fetchAll("
                SELECT oid, discipline 
                FROM listreg 
                WHERE teams_oid = ? AND meros_oid = ?
            ", [$teamId, $eventId]);
            
            if (empty($teamMembers)) {
                return true;
            }
            
            // Получаем информацию о команде
            $team = $this->db->fetchOne("SELECT persons_amount FROM teams WHERE oid = ?", [$teamId]);
            if (!$team) {
                return false;
            }
            
            // Получаем базовую стоимость
            $event = $this->db->fetchOne("SELECT defcost FROM meros WHERE oid = ?", [$eventId]);
            if (!$event || !$event['defcost']) {
                return false;
            }
            
            $baseCost = (float)$event['defcost'];
            $teamSize = max(1, $team['persons_amount']);
            
            // Пересчитываем стоимость для каждого участника
            foreach ($teamMembers as $member) {
                try {
                    $classDistance = json_decode($member['discipline'], true);
                    if (!$classDistance) {
                        continue;
                    }
                    
                    $totalDistances = 0;
                    foreach ($classDistance as $boatClass => $data) {
                        if (isset($data['dist']) && is_array($data['dist'])) {
                            $totalDistances += count($data['dist']);
                        }
                    }
                    
                    $newCost = ($baseCost * $totalDistances) / $teamSize;
                    
                    $this->db->execute("UPDATE listreg SET cost = ? WHERE oid = ?", [$newCost, $member['oid']]);
                    
                } catch (Exception $e) {
                    error_log("Ошибка пересчета стоимости для участника {$member['oid']}: " . $e->getMessage());
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("Ошибка пересчета стоимости команды $teamId: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Обновление стоимости при добавлении участника в команду
     * @param int $teamId ID команды
     * @param int $eventId ID мероприятия
     * @return bool Успешность операции
     */
    public function onMemberAdded($teamId, $eventId) {
        return $this->recalculateTeamCosts($teamId, $eventId);
    }
    
    /**
     * Обновление стоимости при удалении участника из команды
     * @param int $teamId ID команды
     * @param int $eventId ID мероприятия
     * @return bool Успешность операции
     */
    public function onMemberRemoved($teamId, $eventId) {
        return $this->recalculateTeamCosts($teamId, $eventId);
    }
    
    /**
     * Расчет и установка правильной стоимости для регистрации
     * @param int $registrationId ID регистрации в listreg
     * @return bool Успешность операции
     */
    public function updateRegistrationCost($registrationId) {
        try {
            // Получаем данные регистрации
            $registration = $this->db->fetchOne("
                SELECT oid, users_oid, meros_oid, teams_oid, discipline 
                FROM listreg 
                WHERE oid = ?
            ", [$registrationId]);
            
            if (!$registration) {
                return false;
            }
            
            $classDistance = json_decode($registration['discipline'], true);
            if (!$classDistance) {
                return false;
            }
            
            $totalCost = 0;
            $distanceCount = 0;
            
            foreach ($classDistance as $boatClass => $data) {
                if (isset($data['dist']) && is_array($data['dist'])) {
                    $distances = count($data['dist']);
                    $distanceCount += $distances;
                    
                    $cost = $this->calculateParticipationCost(
                        $boatClass, 
                        $registration['meros_oid'], 
                        $registration['teams_oid'], 
                        $distances
                    );
                    
                    $totalCost += $cost;
                }
            }
            
            // Обновляем стоимость регистрации
            $this->db->execute("UPDATE listreg SET cost = ? WHERE oid = ?", [$totalCost, $registrationId]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Ошибка обновления стоимости регистрации $registrationId: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Массовый пересчет стоимости для мероприятия
     * @param int $eventId ID мероприятия
     * @return array Статистика операции
     */
    public function recalculateEventCosts($eventId) {
        try {
            $stats = [
                'updated_registrations' => 0,
                'updated_teams' => 0,
                'errors' => 0
            ];
            
            // Получаем все регистрации мероприятия
            $registrations = $this->db->fetchAll("
                SELECT oid FROM listreg WHERE meros_oid = ?
            ", [$eventId]);
            
            foreach ($registrations as $reg) {
                if ($this->updateRegistrationCost($reg['oid'])) {
                    $stats['updated_registrations']++;
                } else {
                    $stats['errors']++;
                }
            }
            
            // Пересчитываем команды драконов
            $dragonTeams = $this->db->fetchAll("
                SELECT DISTINCT lr.teams_oid
                FROM listreg lr
                WHERE lr.meros_oid = ? 
                AND lr.teams_oid IS NOT NULL
                AND lr.discipline::text LIKE '%D-10%'
            ", [$eventId]);
            
            foreach ($dragonTeams as $team) {
                if ($this->recalculateTeamCosts($team['teams_oid'], $eventId)) {
                    $stats['updated_teams']++;
                }
            }
            
            return $stats;
            
        } catch (Exception $e) {
            error_log("Ошибка массового пересчета для мероприятия $eventId: " . $e->getMessage());
            return ['updated_registrations' => 0, 'updated_teams' => 0, 'errors' => 1];
        }
    }
}
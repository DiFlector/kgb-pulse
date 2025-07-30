<?php
/**
 * Калькулятор возрастных групп для участников
 * Файл: www/lks/php/secretary/age_group_calculator.php
 */

class AgeGroupCalculator {
    
    /**
     * Расчет возраста участника на 31 декабря текущего года
     */
    public static function calculateAgeOnDecember31($birthDate) {
        if (empty($birthDate)) {
            return null;
        }
        
        try {
            $birth = new DateTime($birthDate);
            $currentYear = date('Y');
            $december31 = new DateTime($currentYear . '-12-31');
            
            $age = $birth->diff($december31)->y;
            return $age;
            
        } catch (Exception $e) {
            error_log("❌ [AGE_GROUP_CALCULATOR] Ошибка расчета возраста: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Определение возрастной группы участника
     */
    public static function determineAgeGroup($age, $ageGroups) {
        if ($age === null || empty($ageGroups)) {
            return null;
        }
        
        foreach ($ageGroups as $group) {
            if ($age >= $group['min_age'] && $age <= $group['max_age']) {
                return $group;
            }
        }
        
        return null;
    }
    
    /**
     * Расчет среднего возраста для групповых лодок
     */
    public static function calculateAverageAge($participants) {
        if (empty($participants)) {
            return null;
        }
        
        $totalAge = 0;
        $validParticipants = 0;
        
        foreach ($participants as $participant) {
            $age = self::calculateAgeOnDecember31($participant['birthdata']);
            if ($age !== null) {
                $totalAge += $age;
                $validParticipants++;
            }
        }
        
        if ($validParticipants === 0) {
            return null;
        }
        
        return round($totalAge / $validParticipants);
    }
    
    /**
     * Определение типа команды для D-10 (драконы)
     */
    public static function determineDragonTeamType($participants) {
        if (empty($participants)) {
            return 'MIX';
        }
        
        $maleCount = 0;
        $femaleCount = 0;
        
        foreach ($participants as $participant) {
            if ($participant['sex'] === 'М') {
                $maleCount++;
            } elseif ($participant['sex'] === 'Ж') {
                $femaleCount++;
            }
        }
        
        // Правила для определения типа команды D-10
        if ($maleCount === 0) {
            return 'Ж'; // Женская команда
        } elseif ($femaleCount === 0) {
            return 'М'; // Мужская команда
        } else {
            return 'MIX'; // Смешанная команда
        }
    }
    
    /**
     * Получение возрастных групп для дисциплины и пола
     */
    public static function getAgeGroupsForDiscipline($classDistance, $class, $sex) {
        if (!isset($classDistance[$class])) {
            return [];
        }
        
        $disciplineData = $classDistance[$class];
        
        if (!isset($disciplineData['sex']) || !isset($disciplineData['age_group'])) {
            return [];
        }
        
        $sexes = $disciplineData['sex'];
        $ageGroups = $disciplineData['age_group'];
        
        // Находим индекс пола
        $sexIndex = array_search($sex, $sexes);
        if ($sexIndex === false) {
            return [];
        }
        
        // Получаем строку возрастных групп для данного пола
        if (!isset($ageGroups[$sexIndex])) {
            return [];
        }
        
        $ageGroupString = $ageGroups[$sexIndex];
        
        // Разбираем строку возрастных групп
        return self::parseAgeGroups($ageGroupString);
    }
    
    /**
     * Разбор строки возрастных групп
     */
    public static function parseAgeGroups($ageGroupString) {
        if (empty($ageGroupString)) {
            return [];
        }
        
        $groups = [];
        
        // Разбиваем по ", "
        $groupStrings = explode(', ', $ageGroupString);
        
        foreach ($groupStrings as $groupString) {
            // Разбиваем по ": "
            $parts = explode(': ', $groupString);
            if (count($parts) === 2) {
                $groupName = trim($parts[0]);
                $range = trim($parts[1]);
                
                // Разбиваем диапазон по "-"
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
    
    /**
     * Проверка, входит ли участник в возрастную группу
     */
    public static function isParticipantInAgeGroup($age, $ageGroup) {
        if ($age === null || empty($ageGroup)) {
            return false;
        }
        
        // Разбираем возрастную группу
        $groups = self::parseAgeGroups($ageGroup);
        
        foreach ($groups as $group) {
            if ($age >= $group['min_age'] && $age <= $group['max_age']) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Получение всех возрастных групп для мероприятия
     */
    public static function getAllAgeGroups($classDistance) {
        $allGroups = [];
        
        foreach ($classDistance as $class => $data) {
            if (isset($data['sex']) && isset($data['age_group'])) {
                $sexes = $data['sex'];
                $ageGroups = $data['age_group'];
                
                foreach ($sexes as $sexIndex => $sex) {
                    if (isset($ageGroups[$sexIndex])) {
                        $groups = self::parseAgeGroups($ageGroups[$sexIndex]);
                        
                        foreach ($groups as $group) {
                            $key = $class . '_' . $sex . '_' . $group['name'];
                            $allGroups[$key] = [
                                'class' => $class,
                                'sex' => $sex,
                                'group' => $group
                            ];
                        }
                    }
                }
            }
        }
        
        return $allGroups;
    }
}
?> 
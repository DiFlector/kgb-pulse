<?php
/**
 * Простой тест правильности названий протоколов
 */

echo "=== ТЕСТ НАЗВАНИЙ ПРОТОКОЛОВ ===\n\n";

// Тестовые данные мероприятия
$testClassDistance = [
    "K-1" => [
        "sex" => ["М", "Ж"],
        "dist" => ["200, 500", "500, 1000"],
        "age_group" => [
            "группа 1: 18-29, группа 2: 30-39",   // для М
            "группа 1: 18-29, группа 2: 30-39"    // для Ж
        ]
    ]
];

// Функция для вычисления возрастной группы
function calculateAgeGroupFromClassDistance($age, $sex, $classDistance, $class) {
    if (!isset($classDistance[$class])) {
        return null;
    }
    
    $classData = $classDistance[$class];
    $sexes = $classData['sex'] ?? [];
    $ageGroups = $classData['age_group'] ?? [];
    
    // Находим индекс пола
    $sexIndex = array_search($sex, $sexes);
    if ($sexIndex === false) {
        return null;
    }
    
    // Получаем строку возрастных групп для данного пола
    $ageGroupString = $ageGroups[$sexIndex] ?? '';
    if (empty($ageGroupString)) {
        return null;
    }
    
    // Разбираем возрастные группы
    $availableAgeGroups = array_map('trim', explode(',', $ageGroupString));
    
    foreach ($availableAgeGroups as $ageGroupString) {
        // Разбираем группу: "группа 1: 18-29" -> ["группа 1", "18-29"]
        $parts = explode(': ', $ageGroupString);
        if (count($parts) !== 2) {
            continue;
        }
        
        $groupName = trim($parts[0]);
        $ageRange = trim($parts[1]);
        
        // Разбираем диапазон: "18-29" -> [18, 29]
        $ageLimits = explode('-', $ageRange);
        if (count($ageLimits) !== 2) {
            continue;
        }
        
        $minAge = (int)$ageLimits[0];
        $maxAge = (int)$ageLimits[1];
        
        // Проверяем, входит ли возраст в диапазон
        if ($age >= $minAge && $age <= $maxAge) {
            return $ageGroupString; // Возвращаем полное название группы
        }
    }
    
    // Если группа не найдена, возвращаем null
    return null;
}

// Тестируем разные возрасты и полы
$testCases = [
    ['age' => 25, 'sex' => 'М', 'class' => 'K-1', 'expected' => 'группа 1: 18-29'],
    ['age' => 35, 'sex' => 'М', 'class' => 'K-1', 'expected' => 'группа 2: 30-39'],
    ['age' => 20, 'sex' => 'Ж', 'class' => 'K-1', 'expected' => 'группа 1: 18-29'],
    ['age' => 15, 'sex' => 'М', 'class' => 'K-1', 'expected' => null], // Не подходит по возрасту
];

foreach ($testCases as $testCase) {
    $result = calculateAgeGroupFromClassDistance(
        $testCase['age'], 
        $testCase['sex'], 
        $testClassDistance, 
        $testCase['class']
    );
    
    $status = ($result === $testCase['expected']) ? '✅' : '❌';
    echo "{$status} Возраст: {$testCase['age']}, Пол: {$testCase['sex']}, Класс: {$testCase['class']}\n";
    echo "   Ожидалось: {$testCase['expected']}\n";
    echo "   Получено: " . ($result ?? 'null') . "\n\n";
}

echo "=== ТЕСТ ЗАВЕРШЕН ===\n";
echo "Проверьте, что все протоколы создаются с правильными названиями возрастных групп\n";
echo "Названия должны содержать диапазон возрастов, например: 'группа 1: 18-29'\n";
?> 
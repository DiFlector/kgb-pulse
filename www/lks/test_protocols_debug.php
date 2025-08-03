<?php
/**
 * Тестовая страница для отладки протоколов
 * Файл: www/lks/test_protocols_debug.php
 */

require_once __DIR__ . '/php/common/Auth.php';
require_once __DIR__ . '/php/db/Database.php';
require_once __DIR__ . '/php/secretary/protocol_numbering.php';
require_once __DIR__ . '/php/helpers.php';

// Инициализация
$db = Database::getInstance();
$pdo = $db->getPDO();

// Получаем любое существующее мероприятие
$stmt = $pdo->prepare("SELECT * FROM meros ORDER BY oid LIMIT 1");
$stmt->execute();
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    die("Мероприятия не найдены в базе данных");
}

$meroId = $event['champn'];
$classDistance = json_decode($event['class_distance'], true);

echo "<h1>Тестовая страница отладки протоколов</h1>";
echo "<h2>Мероприятие: {$event['meroname']} (champn: {$meroId}, oid: {$event['oid']})</h2>";

// 1. Получаем структуру протоколов
echo "<h3>1. Структура протоколов:</h3>";
$structure = ProtocolNumbering::getProtocolsStructure($classDistance);
echo "<pre>" . json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";

// 2. Получаем участников мероприятия
echo "<h3>2. Участники мероприятия:</h3>";
$stmt = $pdo->prepare("
    SELECT l.*, u.fio, u.birthdata, u.sex, u.sportzvanie, u.city, u.userid
    FROM listreg l 
    JOIN users u ON l.users_oid = u.oid 
    WHERE l.meros_oid = ? 
    AND l.status IN ('Подтверждён', 'Зарегистрирован')
    ORDER BY u.fio
");
$stmt->execute([$event['oid']]);
$participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Всего участников: " . count($participants) . "</p>";
echo "<pre>" . json_encode(array_slice($participants, 0, 3), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";

// 3. Тестируем распределение участников по возрастным группам
echo "<h3>3. Распределение участников по возрастным группам:</h3>";

$participantsByGroup = [];

foreach ($participants as $participant) {
    $birthYear = date('Y', strtotime($participant['birthdata']));
    $currentYear = date('Y');
    $age = $currentYear - $birthYear;
    
    $disciplineData = json_decode($participant['discipline'], true);
    if (!$disciplineData) continue;
    
    foreach ($disciplineData as $class => $classInfo) {
        if (!isset($classDistance[$class])) continue;
        
        $sexes = is_array($classInfo['sex']) ? $classInfo['sex'] : [$classInfo['sex']];
        $distances = [];
        if (isset($classInfo['dist'])) {
            if (is_array($classInfo['dist'])) {
                $distances = $classInfo['dist'];
            } else {
                $distString = $classInfo['dist'];
                if (strpos($distString, ',') !== false) {
                    $distances = array_map('trim', explode(',', $distString));
                } else {
                    $distances = [trim($distString)];
                }
            }
        }
        
        foreach ($sexes as $sex) {
            if ($participant['sex'] !== $sex) continue;
            
            foreach ($distances as $distance) {
                $distance = trim($distance);
                
                // Определяем возрастную группу
                $ageGroup = calculateAgeGroupFromClassDistance($age, $sex, $classDistance, $class);
                
                if ($ageGroup) {
                    $normalizedSex = normalizeSexToEnglish($sex);
                    // Используем полное название возрастной группы
                    $groupKey = "{$meroId}_{$class}_{$normalizedSex}_{$distance}_{$ageGroup}";
                    
                    // Добавляем отладочную информацию
                    echo "<!-- PHP создает ключ группы: {$groupKey} для участника {$participant['fio']} -->";
                    
                    // Отладочная информация в консоль
                    echo "<script>console.log('PHP создает ключ: {$groupKey}');</script>";
                    
                    if (!isset($participantsByGroup[$groupKey])) {
                        $participantsByGroup[$groupKey] = [];
                    }
                    
                    $participantsByGroup[$groupKey][] = [
                        'userId' => $participant['userid'],
                        'fio' => $participant['fio'],
                        'sex' => $participant['sex'],
                        'birthdata' => $participant['birthdata'],
                        'sportzvanie' => $participant['sportzvanie'] ?? 'БР',
                        'city' => $participant['city'],
                        'age' => $age,
                        'ageGroup' => $ageGroup
                    ];
                }
            }
        }
    }
}

echo "<p>Групп с участниками: " . count($participantsByGroup) . "</p>";
echo "<h4>Ключи групп в PHP:</h4>";
echo "<ul>";
foreach ($participantsByGroup as $groupKey => $groupParticipants) {
    echo "<li><strong>{$groupKey}:</strong> " . count($groupParticipants) . " участников</li>";
    // Показываем первых 3 участников для отладки
    if (count($groupParticipants) > 0) {
        echo "<ul>";
        foreach (array_slice($groupParticipants, 0, 3) as $participant) {
            echo "<li>{$participant['fio']} (ID: {$participant['userId']}, возраст: {$participant['age']})</li>";
        }
        if (count($groupParticipants) > 3) {
            echo "<li>... и еще " . (count($groupParticipants) - 3) . " участников</li>";
        }
        echo "</ul>";
    }
}
echo "</ul>";

// 4. Тестируем отображение протоколов
echo "<h3>4. Тестовое отображение протоколов:</h3>";
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тест протоколов</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .protocol-group { margin-bottom: 30px; }
        .protocol-title { color: #007bff; font-weight: bold; margin-bottom: 15px; }
        .distance-title { color: #495057; font-weight: 600; margin-bottom: 10px; }
        .sex-title { color: #6c757d; font-weight: 500; margin-bottom: 8px; }
        .age-title { color: #28a745; font-weight: 600; margin-bottom: 10px; }
        .protocol-table { font-size: 0.9rem; }
        .protocol-table th { background-color: #f8f9fa; font-weight: 600; }
        .no-data { color: #6c757d; font-style: italic; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-6">
                <h4><i class="fas fa-flag-checkered"></i> Стартовые протоколы</h4>
                <div id="start-protocols"></div>
            </div>
            <div class="col-md-6">
                <h4><i class="fas fa-trophy"></i> Финишные протоколы</h4>
                <div id="finish-protocols"></div>
            </div>
        </div>
    </div>

    <script>
        // Данные для тестирования
        const testData = {
            meroId: <?php echo $meroId; ?>,
            structure: <?php echo json_encode($structure); ?>,
            participantsByGroup: <?php echo json_encode($participantsByGroup); ?>
        };

        console.log('Тестовые данные:', testData);

        class TestProtocolsManager {
            constructor() {
                this.currentMeroId = testData.meroId;
                this.protocolsData = {};
                this.init();
            }

            init() {
                this.loadProtocolsStructure();
                this.loadExistingData();
            }

            loadProtocolsStructure() {
                console.log('Загружаем структуру протоколов...');
                this.renderProtocolsStructure(testData.structure);
            }

            renderProtocolsStructure(structure) {
                console.log('Отрисовываем структуру:', structure);
                
                const startContainer = document.getElementById('start-protocols');
                const finishContainer = document.getElementById('finish-protocols');
                
                if (startContainer) {
                    const startHTML = this.generateProtocolsHTML(structure, 'start');
                    startContainer.innerHTML = startHTML;
                }

                if (finishContainer) {
                    const finishHTML = this.generateProtocolsHTML(structure, 'finish');
                    finishContainer.innerHTML = finishHTML;
                }
            }

            generateProtocolsHTML(structure, type) {
                if (!structure || !Array.isArray(structure) || structure.length === 0) {
                    return '<div class="text-center text-muted py-4">Нет данных для отображения протоколов</div>';
                }
                
                let html = '<div class="protocols-container">';
                
                // Группируем протоколы по классам лодок
                const groupedByClass = {};
                
                structure.forEach(discipline => {
                    const boatClass = discipline.class;
                    if (!groupedByClass[boatClass]) {
                        groupedByClass[boatClass] = [];
                    }
                    groupedByClass[boatClass].push(discipline);
                });
                
                // Сортируем классы лодок
                const boatClassOrder = ['D-10', 'K-1', 'C-1', 'K-2', 'C-2', 'K-4', 'C-4', 'H-1', 'H-2', 'H-4', 'O-1', 'O-2', 'O-4', 'HD-1', 'OD-1', 'OD-2', 'OC-1'];
                const sortedClasses = Object.keys(groupedByClass).sort((a, b) => {
                    const indexA = boatClassOrder.indexOf(a);
                    const indexB = boatClassOrder.indexOf(b);
                    return indexA - indexB;
                });
                
                for (const boatClass of sortedClasses) {
                    const disciplines = groupedByClass[boatClass];
                    const boatClassName = this.getBoatClassName(boatClass);
                    
                    html += `<div class="protocol-group">`;
                    html += `<h5 class="protocol-title">${boatClassName}</h5>`;
                    
                    // Группируем по дистанциям
                    const groupedByDistance = {};
                    disciplines.forEach(discipline => {
                        const distance = discipline.distance;
                        if (!groupedByDistance[distance]) {
                            groupedByDistance[distance] = [];
                        }
                        groupedByDistance[distance].push(discipline);
                    });
                    
                    // Сортируем дистанции численно
                    const sortedDistances = Object.keys(groupedByDistance).sort((a, b) => {
                        const numA = parseInt(a);
                        const numB = parseInt(b);
                        return numA - numB;
                    });
                    
                    for (const distance of sortedDistances) {
                        const distanceDisciplines = groupedByDistance[distance];
                        html += `<div class="distance-group mb-3">`;
                        html += `<h6 class="distance-title">Дистанция: ${distance} м</h6>`;
                        
                        // Группируем по полу
                        const groupedBySex = {};
                        distanceDisciplines.forEach(discipline => {
                            const sex = discipline.sex;
                            if (!groupedBySex[sex]) {
                                groupedBySex[sex] = [];
                            }
                            groupedBySex[sex].push(discipline);
                        });
                        
                        // Сортируем по полу
                        const sexOrder = ['M', 'М', 'Ж', 'MIX'];
                        const sortedSexes = Object.keys(groupedBySex).sort((a, b) => {
                            const indexA = sexOrder.indexOf(a);
                            const indexB = sexOrder.indexOf(b);
                            return indexA - indexB;
                        });
                        
                        for (const sex of sortedSexes) {
                            const sexDisciplines = groupedBySex[sex];
                            const sexName = sex === 'M' || sex === 'М' ? 'Мужчины' : (sex === 'Ж' ? 'Женщины' : 'Смешанные команды');
                            html += `<div class="sex-group mb-2">`;
                            html += `<h7 class="sex-title">${sexName}</h7>`;
                            
                            sexDisciplines.forEach(discipline => {
                                if (discipline.ageGroups && discipline.ageGroups.length > 0) {
                                    discipline.ageGroups.forEach(ageGroup => {
                                        // Нормализуем пол для groupKey (используем латиницу)
                                        const normalizedSex = sex === 'М' ? 'M' : sex;
                                        // Используем полное название возрастной группы с названием и возрастным диапазоном
                                        const ageGroupName = ageGroup.full_name || ageGroup.name;
                                        
                                        // Отладочная информация
                                        console.log(`ageGroup.full_name:`, ageGroup.full_name);
                                        console.log(`ageGroup.name:`, ageGroup.name);
                                        console.log(`ageGroupName:`, ageGroupName);
                                        const groupKey = `${this.currentMeroId}_${boatClass}_${normalizedSex}_${distance}_${ageGroupName}`;
                                        
                                        // Добавляем отладочную информацию для каждого создаваемого ключа
                                        console.log(`Создаем HTML для группы: ${groupKey}`);
                                        console.log(`ageGroup.full_name:`, ageGroup.full_name);
                                        console.log(`ageGroup.name:`, ageGroup.name);
                                        console.log(`ageGroupName:`, ageGroupName);
                                        
                                        // Добавляем отладочную информацию для каждого создаваемого ключа
                                        console.log(`Создаем HTML для группы: ${groupKey}`);
                                        
                                        console.log(`Создаем группу: ${groupKey}`);
                                        console.log(`ageGroup.full_name:`, ageGroup.full_name);
                                        console.log(`ageGroup.name:`, ageGroup.name);
                                        console.log(`ageGroupName:`, ageGroupName);
                                        
                                        const isDragonProtocol = boatClass === 'D-10';
                                        
                                        // Формируем название протокола
                                        const protocolTitle = `Протокол №${ageGroup.number} - ${boatClassName}, ${distance}м, ${sexName}, ${ageGroup.displayName}`;
                                        
                                        // Создаем HTML для протокола
                                        const protocolHtml = `
                                            <div class="protocol-section mb-4">
                                                <h4>${ageGroup.displayName} - ${distance}м</h4>
                                                <div class="table-responsive">
                                                    <table class="table table-striped table-bordered" data-group="${groupKey}">
                                                        <thead class="table-dark">
                                                            <tr>
                                                                <th>№</th>
                                                                <th>ФИО</th>
                                                                <th>Команда</th>
                                                                <th>Возраст</th>
                                                                <th>Спортивное звание</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody data-group="${groupKey}">
                                                            <!-- Данные участников будут добавлены здесь -->
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        `;
                                        
                                        html += protocolHtml;
                                    });
                                }
                            });
                            
                            html += `</div>`;
                        }
                        
                        html += `</div>`;
                    }
                    
                    html += `</div>`;
                }
                
                html += '</div>';
                return html;
            }

            getBoatClassName(boatClass) {
                const boatNames = {
                    'D-10': 'Драконы (D-10)',
                    'K-1': 'Байдарка-одиночка (K-1)',
                    'K-2': 'Байдарка-двойка (K-2)',
                    'K-4': 'Байдарка-четверка (K-4)',
                    'C-1': 'Каноэ-одиночка (C-1)',
                    'C-2': 'Каноэ-двойка (C-2)',
                    'C-4': 'Каноэ-четверка (C-4)',
                    'HD-1': 'Специальная лодка (HD-1)',
                    'OD-1': 'Специальная лодка (OD-1)',
                    'OD-2': 'Специальная лодка (OD-2)',
                    'OC-1': 'Специальная лодка (OC-1)'
                };
                
                return boatNames[boatClass] || boatClass;
            }

            loadExistingData() {
                console.log('Загружаем существующие данные...');
                
                // Имитируем загрузку данных из testData.participantsByGroup
                this.protocolsData = {};
                
                for (const [groupKey, participants] of Object.entries(testData.participantsByGroup)) {
                    this.protocolsData[groupKey] = {
                        meroId: this.currentMeroId,
                        type: 'start',
                        participants: participants,
                        created_at: new Date().toISOString()
                    };
                }
                
                // Добавляем отладочную информацию
                console.log('Ключи групп в данных:', Object.keys(this.protocolsData));
                console.log('Пример данных группы:', Object.values(this.protocolsData)[0]);
                
                // Показываем первые несколько ключей для сравнения
                const firstKeys = Object.keys(this.protocolsData).slice(0, 5);
                console.log('Первые 5 ключей в данных:', firstKeys);
                
                console.log('Загруженные данные:', this.protocolsData);
                this.renderProtocolsData();
            }

            renderProtocolsData() {
                console.log('Отрисовываем данные протоколов...');
                console.log('Доступные ключи групп:', Object.keys(this.protocolsData));
                
                // Показываем все доступные tbody на странице
                const allTbodies = document.querySelectorAll('tbody[data-group]');
                console.log('Все доступные tbody на странице:', Array.from(allTbodies).map(t => t.dataset.group));
                
                // Показываем первые несколько HTML ключей для сравнения
                const firstHtmlKeys = Array.from(allTbodies).slice(0, 5).map(t => t.dataset.group);
                console.log('Первые 5 ключей в HTML:', firstHtmlKeys);
                
                // Сравниваем ключи
                const dataKeys = Object.keys(this.protocolsData);
                const htmlKeys = Array.from(allTbodies).map(t => t.dataset.group);
                
                console.log('Ключи в данных:', dataKeys);
                console.log('Ключи в HTML:', htmlKeys);
                
                // Находим несовпадения
                const missingInHTML = dataKeys.filter(key => !htmlKeys.includes(key));
                const missingInData = htmlKeys.filter(key => !dataKeys.includes(key));
                
                if (missingInHTML.length > 0) {
                    console.log('Ключи, которые есть в данных, но нет в HTML:', missingInHTML);
                }
                if (missingInData.length > 0) {
                    console.log('Ключи, которые есть в HTML, но нет в данных:', missingInData);
                }
                
                for (const [groupKey, protocolData] of Object.entries(this.protocolsData)) {
                    const tbody = document.querySelector(`tbody[data-group="${groupKey}"]`);
                    
                    console.log(`Проверяем группу: ${groupKey}`);
                    console.log(`Найден tbody:`, !!tbody);
                    console.log(`Данные группы:`, protocolData);
                    
                    if (tbody) {
                        tbody.innerHTML = '';
                        
                        if (protocolData.participants && protocolData.participants.length > 0) {
                            protocolData.participants.forEach((participant, index) => {
                                const row = document.createElement('tr');
                                row.innerHTML = `
                                    <td>${index + 1}</td>
                                    <td>${participant.fio || 'Н/Д'}</td>
                                    <td>${participant.city || 'Н/Д'}</td>
                                    <td>${participant.age || 'Н/Д'}</td>
                                    <td>${participant.sportzvanie || 'Н/Д'}</td>
                                `;
                                tbody.appendChild(row);
                            });
                        } else {
                            const row = document.createElement('tr');
                            row.innerHTML = '<td colspan="5" class="text-center text-muted">Нет участников</td>';
                            tbody.appendChild(row);
                        }
                    } else {
                        console.log(`Не найден tbody для группы ${groupKey}`);
                    }
                }
            }

            createParticipantRow(participant, number, groupKey) {
                const row = document.createElement('tr');
                row.className = 'participant-row';
                row.dataset.participantId = participant.userId;
                row.dataset.groupKey = groupKey;
                
                const table = document.querySelector(`table[data-group="${groupKey}"]`);
                const type = table ? table.dataset.type : 'start';
                const isDragonProtocol = groupKey.includes('D-10');
                
                let cells = '';
                
                if (type === 'start') {
                    cells += `<td>${number}</td>`;
                    cells += `<td>${participant.userId}</td>`;
                    cells += `<td>${participant.fio}</td>`;
                    cells += `<td>${new Date(participant.birthdata).getFullYear()}</td>`;
                    cells += `<td>${participant.ageGroup}</td>`;
                    cells += `<td>${participant.sportzvanie}</td>`;
                    if (isDragonProtocol) {
                        cells += `<td>${participant.city || ''}</td>`;
                        cells += `<td>-</td>`;
                    }
                } else {
                    cells += `<td>-</td>`;
                    cells += `<td>-</td>`;
                    cells += `<td>${number}</td>`;
                    cells += `<td>${participant.userId}</td>`;
                    cells += `<td>${participant.fio}</td>`;
                    cells += `<td>${new Date(participant.birthdata).getFullYear()}</td>`;
                    cells += `<td>${participant.ageGroup}</td>`;
                    cells += `<td>${participant.sportzvanie}</td>`;
                    if (isDragonProtocol) {
                        cells += `<td>${participant.city || ''}</td>`;
                        cells += `<td>-</td>`;
                    }
                }
                
                cells += `<td><button class="btn btn-sm btn-outline-danger">Удалить</button></td>`;
                
                row.innerHTML = cells;
                return row;
            }
        }

        // Инициализация тестового менеджера
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Инициализируем тестовый менеджер протоколов...');
            
            // Создаем экземпляр менеджера
            window.testManager = new TestProtocolsManager();
            
            // Сначала генерируем HTML структуру
            window.testManager.generateProtocolsHTML();
            
            // Затем загружаем данные
            window.testManager.loadExistingData();
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
/**
 * Функция для вычисления возрастной группы
 */
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
    
    return null;
}
?> 
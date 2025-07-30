<?php
require_once '../../php/common/Auth.php';
require_once '../../php/db/Database.php';

$auth = new Auth();
$user = $auth->checkRole(['Secretary', 'SuperUser', 'Admin']);
if (!$user) {
    header('Location: ../../login.php');
    exit();
}

// Получаем данные из сессии
$selectedEvent = $_SESSION['selected_event'] ?? null;

if (!$selectedEvent) {
    echo '<div class="alert alert-danger">Данные мероприятия не найдены. Вернитесь к списку мероприятий.</div>';
    echo '<a href="main.php" class="btn btn-primary">Вернуться к списку мероприятий</a>';
    exit();
}

$eventId = $selectedEvent['id'];
$drawType = $selectedEvent['draw_type'] ?? 'semifinal_final';

$db = Database::getInstance();

// Получаем информацию о мероприятии
try {
    $stmt = $db->prepare("SELECT * FROM meros WHERE champn = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    
    if (!$event) {
        echo '<div class="alert alert-danger">Мероприятие не найдено</div>';
        echo '<a href="main.php" class="btn btn-primary">Вернуться к списку мероприятий</a>';
        exit();
    }
    
    $classDistance = json_decode($event['class_distance'], true);
    
    // Отладочная информация
    if (empty($classDistance)) {
        error_log("DEBUG: Пустые данные class_distance для мероприятия " . $eventId);
        error_log("DEBUG: Содержимое class_distance: " . $event['class_distance']);
    }
} catch (Exception $e) {
    error_log("DEBUG: Ошибка в select-disciplines.php: " . $e->getMessage());
    echo '<div class="alert alert-danger">Ошибка загрузки данных мероприятия</div>';
    echo '<a href="main.php" class="btn btn-primary">Вернуться к списку мероприятий</a>';
    exit();
}

$pageTitle = 'Выбор дисциплин для жеребьевки';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - KGB-Pulse</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../../css/style.css" rel="stylesheet">
    <style>
        .discipline-item {
            transition: all 0.3s ease;
            cursor: pointer;
            min-height: 80px;
        }
        
        .discipline-item:hover {
            border-color: #0d6efd !important;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
        }
        
        .discipline-item.border-success {
            border-color: #198754 !important;
            background-color: #f8fff9 !important;
        }
        
        .form-check-input:checked {
            background-color: #198754;
            border-color: #198754;
        }
        
        .form-check-input {
            width: 1.5rem;
            height: 1.5rem;
        }
        
        .form-check-label {
            cursor: pointer;
            font-size: 0.95rem;
        }
        
        #selectedCount {
            font-size: 1rem;
            padding: 0.5rem 1rem;
        }
        
        .btn-lg {
            padding: 0.75rem 1.5rem;
            font-size: 1.1rem;
        }
        
        .badge.bg-success {
            font-size: 0.9rem;
            padding: 0.6rem;
        }
        
        .btn-close-white {
            filter: invert(1) grayscale(100%) brightness(200%);
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Заголовок -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0"><?= htmlspecialchars($event['meroname']) ?></h1>
                    <p class="text-muted mb-0"><?= htmlspecialchars($event['merodata']) ?></p>
                </div>
                <a href="main.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Назад к мероприятиям
                </a>
            </div>

            <!-- Блок выбора дисциплин -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-target me-2"></i>Выбери дисциплины для жеребьевки:
                        </h5>
                        <div>
                            <span class="badge bg-light text-dark" id="selectedCount">
                                <i class="bi bi-check-circle"></i> Выбрано: <span id="countNumber">0</span>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($classDistance)): ?>
                        <div class="alert alert-warning">
                            <h5><i class="bi bi-exclamation-triangle"></i> Внимание!</h5>
                            <p>Для данного мероприятия не настроены дисциплины (классы и дистанции).</p>
                            <p>Обратитесь к организатору для настройки дисциплин или проверьте правильность создания мероприятия.</p>
                            <hr>
                            <p class="mb-0"><strong>Данные class_distance:</strong> <code><?= htmlspecialchars($event['class_distance']) ?></code></p>
                        </div>
                    <?php else: ?>
                    <?php
                    // Загружаем универсальные функции для работы с лодками
                    require_once __DIR__ . '/../../php/helpers.php';
                    
                    // ИСПРАВЛЕННАЯ ЛОГИКА: создаем отдельные дисциплины для каждой комбинации класс+пол+дистанция
                    $allDisciplines = [];
                    $uniqueDisciplines = []; // Для дедупликации
                    
                    // ВРЕМЕННАЯ ОТЛАДКА - покажем что получили из базы
                    echo "<!-- DEBUG: Исходные данные class_distance: " . htmlspecialchars(json_encode($classDistance)) . " -->";
                    
                    foreach ($classDistance as $class => $details) {
                        if (!isset($details['sex']) || !isset($details['dist'])) {
                            continue; // Пропускаем некорректные данные
                        }
                        
                        $sexes = is_array($details['sex']) ? $details['sex'] : [$details['sex']];
                        $distances = is_array($details['dist']) ? $details['dist'] : [$details['dist']];
                        
                        // ИСПРАВЛЕНО: Обрабатываем каждую дистанцию только один раз
                        foreach ($distances as $distanceStr) {
                            // Разбиваем строку дистанций на отдельные значения
                            // Из "200, 500, 1000" получаем ["200", "500", "1000"]
                            $individualDistances = explode(',', $distanceStr);
                            
                            foreach ($individualDistances as $distance) {
                                // Очищаем дистанцию от лишних символов и пробелов
                                $cleanDistance = trim($distance);
                                
                                if (empty($cleanDistance)) continue; // Пропускаем пустые
                                
                                foreach ($sexes as $sex) {
                                    $cleanSex = trim($sex);
                                    
                                    // Создаем уникальный ключ для дедупликации
                                    $uniqueKey = $class . '_' . $cleanSex . '_' . $cleanDistance;
                                    
                                    // Проверяем, не создали ли мы уже такую дисциплину
                                    if (!isset($uniqueDisciplines[$uniqueKey])) {
                                        $uniqueDisciplines[$uniqueKey] = true;
                                        
                                        $allDisciplines[] = [
                                            'class' => $class,
                                            'sex' => $cleanSex,
                                            'distance' => $cleanDistance,
                                            'type' => isDragonBoat($class) ? 'dragon' : (isGroupBoat($class) ? 'group' : 'solo')
                                        ];
                                    }
                                }
                            }
                        }
                    }
                    
                    // Группируем дисциплины по типам для отображения
                    $soloBoats = array_filter($allDisciplines, fn($d) => $d['type'] === 'solo');
                    $groupBoats = array_filter($allDisciplines, fn($d) => $d['type'] === 'group');
                    $dragonBoats = array_filter($allDisciplines, fn($d) => $d['type'] === 'dragon');
                    
                    // ВРЕМЕННАЯ ОТЛАДКА - покажем что получилось после парсинга
                    echo "<!-- DEBUG: Всего дисциплин после парсинга: " . count($allDisciplines) . " -->";
                    echo "<!-- DEBUG: Одиночки: " . count($soloBoats) . ", Групповые: " . count($groupBoats) . ", Драконы: " . count($dragonBoats) . " -->";
                    if (!empty($allDisciplines)) {
                        echo "<!-- DEBUG: Первые 3 дисциплины: " . htmlspecialchars(json_encode(array_slice($allDisciplines, 0, 3))) . " -->";
                    }
                    ?>

                    <!-- Одиночки -->
                    <?php if (!empty($soloBoats)): ?>
                        <div class="mb-4">
                            <h6 class="text-primary mb-3">
                                <i class="bi bi-person me-2"></i>Одиночки
                            </h6>
                            <div class="row g-2">
                                <?php foreach ($soloBoats as $discipline): ?>
                                    <div class="col-md-3 col-sm-4 col-6">
                                        <div class="form-check d-flex align-items-center border rounded p-3 h-100 discipline-item">
                                            <input class="form-check-input me-3" type="checkbox" 
                                                   id="discipline_<?= $discipline['class'] ?>_<?= $discipline['sex'] ?>_<?= $discipline['distance'] ?>"
                                                   data-class="<?= $discipline['class'] ?>" 
                                                   data-sex="<?= $discipline['sex'] ?>" 
                                                   data-distance="<?= $discipline['distance'] ?>"
                                                   onchange="toggleDiscipline(this)">
                                            <label class="form-check-label w-100 text-center" 
                                                   for="discipline_<?= $discipline['class'] ?>_<?= $discipline['sex'] ?>_<?= $discipline['distance'] ?>">
                                                <strong><?= $discipline['class'] ?> <?= $discipline['sex'] ?> <?= $discipline['distance'] ?>м</strong>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Двойки и групповые -->
                    <?php if (!empty($groupBoats)): ?>
                        <div class="mb-4">
                            <h6 class="text-success mb-3">
                                <i class="bi bi-people me-2"></i>Двойки и групповые
                            </h6>
                            <div class="row g-2">
                                <?php foreach ($groupBoats as $discipline): ?>
                                    <div class="col-md-3 col-sm-4 col-6">
                                        <div class="form-check d-flex align-items-center border rounded p-3 h-100 discipline-item">
                                            <input class="form-check-input me-3" type="checkbox" 
                                                   id="discipline_<?= $discipline['class'] ?>_<?= $discipline['sex'] ?>_<?= $discipline['distance'] ?>"
                                                   data-class="<?= $discipline['class'] ?>" 
                                                   data-sex="<?= $discipline['sex'] ?>" 
                                                   data-distance="<?= $discipline['distance'] ?>"
                                                   onchange="toggleDiscipline(this)">
                                            <label class="form-check-label w-100 text-center" 
                                                   for="discipline_<?= $discipline['class'] ?>_<?= $discipline['sex'] ?>_<?= $discipline['distance'] ?>">
                                                <strong><?= $discipline['class'] ?> <?= $discipline['sex'] ?> <?= $discipline['distance'] ?>м</strong>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Драконы -->
                    <?php if (!empty($dragonBoats)): ?>
                        <div class="mb-4">
                            <h6 class="text-warning mb-3">
                                <i class="bi bi-fire me-2"></i>Лодки дракон
                            </h6>
                            <div class="row g-2">
                                <?php foreach ($dragonBoats as $discipline): ?>
                                    <div class="col-md-3 col-sm-4 col-6">
                                        <div class="form-check d-flex align-items-center border rounded p-3 h-100 discipline-item">
                                            <input class="form-check-input me-3" type="checkbox" 
                                                   id="discipline_<?= $discipline['class'] ?>_<?= $discipline['sex'] ?>_<?= $discipline['distance'] ?>"
                                                   data-class="<?= $discipline['class'] ?>" 
                                                   data-sex="<?= $discipline['sex'] ?>" 
                                                   data-distance="<?= $discipline['distance'] ?>"
                                                   onchange="toggleDiscipline(this)">
                                            <label class="form-check-label w-100 text-center" 
                                                   for="discipline_<?= $discipline['class'] ?>_<?= $discipline['sex'] ?>_<?= $discipline['distance'] ?>">
                                                <strong><?= $discipline['class'] ?> <?= $discipline['sex'] ?> <?= $discipline['distance'] ?>м</strong>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Блок управления выбранными дисциплинами -->
                    <div class="d-flex justify-content-between align-items-center mt-4">
                        <div class="btn-group">
                            <button type="button" class="btn btn-outline-primary" onclick="selectAllDisciplines()">
                                <i class="bi bi-check-all"></i> Выбрать все
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="clearAllDisciplines()">
                                <i class="bi bi-x-circle"></i> Очистить все
                            </button>
                        </div>
                        
                        <button type="button" class="btn btn-success btn-lg" id="proceedBtn" onclick="createProtocols()" disabled>
                            <i class="bi bi-arrow-right-circle"></i> 
                            Создать протоколы (<span id="proceedCount">0</span>)
                        </button>
                    </div>
                    
                    <?php endif; ?>
                </div>
            </div>

            <!-- Выбранные дисциплины -->
            <div id="selectedDisciplines" style="display: none;">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-check-circle"></i> Выбранные дисциплины: 
                            <span id="selectedDisciplinesCount">0</span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row" id="selectedDisciplinesList">
                            <!-- Здесь будут отображаться выбранные дисциплины -->
                        </div>
                        <div class="mt-3">
                            <button class="btn btn-success btn-lg" onclick="createProtocols()">
                                <i class="bi bi-play-circle me-2"></i>Создать протоколы для выбранных дисциплин
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let selectedDisciplines = [];
        const eventId = <?= $eventId ?>;

        // Обновление счетчика выбранных дисциплин
        function updateSelectedCount() {
            const count = selectedDisciplines.length;
            document.getElementById('countNumber').textContent = count;
            document.getElementById('proceedCount').textContent = count;
            document.getElementById('selectedDisciplinesCount').textContent = count;
            
            // Активируем/деактивируем кнопку
            const proceedBtn = document.getElementById('proceedBtn');
            proceedBtn.disabled = count === 0;
            
            // Показываем/скрываем блок выбранных дисциплин
            const selectedBlock = document.getElementById('selectedDisciplines');
            if (count > 0) {
                selectedBlock.style.display = 'block';
                updateSelectedList();
            } else {
                selectedBlock.style.display = 'none';
            }
        }

        // Обновление списка выбранных дисциплин
        function updateSelectedList() {
            const listContainer = document.getElementById('selectedDisciplinesList');
            listContainer.innerHTML = '';
            
            selectedDisciplines.forEach((discipline, index) => {
                const disciplineHtml = `
                    <div class="col-md-4 col-sm-6 mb-2">
                        <div class="badge bg-success p-2 w-100 d-flex justify-content-between align-items-center">
                            <span>${discipline.class} ${discipline.sex} ${discipline.distance}м</span>
                            <button type="button" class="btn-close btn-close-white btn-sm" 
                                    onclick="removeDiscipline(${index})" 
                                    aria-label="Удалить"></button>
                        </div>
                    </div>
                `;
                listContainer.innerHTML += disciplineHtml;
            });
        }

        // Добавление/удаление дисциплины при изменении checkbox
        function toggleDiscipline(checkbox) {
            const classType = checkbox.dataset.class;
            const sex = checkbox.dataset.sex;
            const distance = checkbox.dataset.distance;
            
            const discipline = {
                class: classType,
                sex: sex,
                distance: distance
            };
            
            if (checkbox.checked) {
                // Добавляем дисциплину
                selectedDisciplines.push(discipline);
                checkbox.closest('.discipline-item').classList.add('border-success', 'bg-light');
            } else {
                // Удаляем дисциплину
                selectedDisciplines = selectedDisciplines.filter(d => 
                    !(d.class === classType && d.sex === sex && d.distance === distance)
                );
                checkbox.closest('.discipline-item').classList.remove('border-success', 'bg-light');
            }
            
            updateSelectedCount();
        }

        // Удаление дисциплины из списка выбранных
        function removeDiscipline(index) {
            const discipline = selectedDisciplines[index];
            
            // Снимаем галочку с соответствующего checkbox
            const checkbox = document.querySelector(`input[data-class="${discipline.class}"][data-sex="${discipline.sex}"][data-distance="${discipline.distance}"]`);
            if (checkbox) {
                checkbox.checked = false;
                checkbox.closest('.discipline-item').classList.remove('border-success', 'bg-light');
            }
            
            // Удаляем из массива
            selectedDisciplines.splice(index, 1);
            updateSelectedCount();
        }

        // Выбрать все дисциплины
        function selectAllDisciplines() {
            document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                if (!checkbox.checked) {
                    checkbox.checked = true;
                    toggleDiscipline(checkbox);
                }
            });
        }

        // Очистить все выбранные дисциплины
        function clearAllDisciplines() {
            document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                if (checkbox.checked) {
                    checkbox.checked = false;
                    checkbox.closest('.discipline-item').classList.remove('border-success', 'bg-light');
                }
            });
            selectedDisciplines = [];
            updateSelectedCount();
        }

        // Создание протоколов для выбранных дисциплин
        function createProtocols() {
            if (selectedDisciplines.length === 0) {
                alert('Выберите хотя бы одну дисциплину');
                return;
            }

            // Создаем скрытую форму для отправки данных
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'process-event-selection.php';
            
            // Добавляем поля формы
            const eventIdField = document.createElement('input');
            eventIdField.type = 'hidden';
            eventIdField.name = 'event_id';
            eventIdField.value = eventId;
            form.appendChild(eventIdField);
            
            const actionField = document.createElement('input');
            actionField.type = 'hidden';
            actionField.name = 'action';
            actionField.value = 'protocols';
            form.appendChild(actionField);
            
            const disciplinesField = document.createElement('input');
            disciplinesField.type = 'hidden';
            disciplinesField.name = 'disciplines';
            disciplinesField.value = JSON.stringify(selectedDisciplines);
            form.appendChild(disciplinesField);
            
            // Добавляем форму на страницу и отправляем
            document.body.appendChild(form);
            form.submit();
        }

        // Инициализация при загрузке страницы
        document.addEventListener('DOMContentLoaded', function() {
            updateSelectedCount();
        });
    </script>
</body>
</html> 
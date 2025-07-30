<?php
require_once '../../php/common/Auth.php';
require_once '../../php/db/Database.php';

$auth = new Auth();
$user = $auth->checkRole(['Secretary', 'SuperUser', 'Admin']);
if (!$user) {
    header('Location: ../../login.php');
    exit();
}

$db = Database::getInstance();

// Получаем мероприятия, доступные для проведения
try {
    $eventsStmt = $db->prepare("
        SELECT champn, meroname, merodata, status::text as status, class_distance,
               (SELECT COUNT(*) FROM listreg WHERE meros_oid = m.oid) as participants_count
        FROM meros m
        WHERE TRIM(status::text) IN ('Регистрация закрыта', 'В процессе', 'Результаты')
        ORDER BY 
            CASE 
                WHEN TRIM(status::text) = 'Регистрация закрыта' THEN 1
                WHEN TRIM(status::text) = 'В процессе' THEN 2
                WHEN TRIM(status::text) = 'Результаты' THEN 3
                ELSE 4
            END,
            champn DESC
    ");
    $eventsStmt->execute();
    $events = $eventsStmt->fetchAll();

} catch (Exception $e) {
    error_log("Ошибка получения мероприятий: " . $e->getMessage());
    $events = [];
}

// Функция для определения статуса
function getStatusInfo($status) {
    $trimmedStatus = trim($status);
    
    switch($trimmedStatus) {
        case 'Регистрация закрыта':
            return [
                'class' => 'warning',
                'icon' => 'bi-lock',
                'text' => 'Готово к проведению'
            ];
        case 'В процессе':
            return [
                'class' => 'info',
                'icon' => 'bi-play-circle',
                'text' => 'Проводится'
            ];
        case 'Результаты':
            return [
                'class' => 'primary',
                'icon' => 'bi-file-earmark-text',
                'text' => 'Результаты готовы'
            ];
        default:
            return [
                'class' => 'secondary',
                'icon' => 'bi-question-circle',
                'text' => $trimmedStatus
            ];
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Проведение мероприятий - KGB-Pulse</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../../css/style.css" rel="stylesheet">
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Основной контейнер для работы секретаря -->
            <div id="content_mero">
                <!-- Заголовок -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3 mb-0">
                        <i class="bi bi-award text-primary"></i>
                        Проведение мероприятий
                    </h1>
                    <div>
                        <button class="btn btn-outline-secondary" onclick="location.href='index.php'">
                            <i class="bi bi-arrow-left"></i> Назад к панели
                        </button>
                    </div>
                </div>

                <!-- Информация о странице -->
                <div class="alert alert-info mb-4">
                    <div class="row">
                        <div class="col-md-8">
                            <h5 class="alert-heading">
                                <i class="bi bi-info-circle"></i> 
                                Добро пожаловать в систему проведения мероприятий!
                            </h5>
                            <p class="mb-0">
                                Здесь вы можете проводить спортивные соревнования: выбирать тип жеребьевки, 
                                создавать протоколы, вводить результаты и формировать итоговые документы.
                            </p>
                        </div>
                        <div class="col-md-4 text-center">
                            <i class="bi bi-trophy display-4 text-warning"></i>
                        </div>
                    </div>
                </div>

                <!-- Страница отображения всех мероприятий -->
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-calendar-event"></i> Мероприятия для судейства
                        </h5>
                        <small class="opacity-75">Найдено мероприятий: <?= count($events) ?></small>
                    </div>
                    <div class="card-body">
                        <?php if (empty($events)): ?>
                            <div class="text-center text-muted py-5">
                                <i class="bi bi-calendar-x display-1 text-muted"></i>
                                <h4 class="mt-3">Нет мероприятий для проведения</h4>
                                <p class="text-muted">
                                    В данный момент нет мероприятий, готовых к проведению или находящихся в процессе.
                                </p>
                                <div class="mt-4">
                                    <a href="events.php" class="btn btn-outline-primary">
                                        <i class="bi bi-calendar-event"></i> Посмотреть все мероприятия
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="5%">ID</th>
                                            <th width="25%">Наименование соревнований</th>
                                            <th width="15%">Классы лодок</th>
                                            <th width="15%">Дистанции</th>
                                            <th width="10%">Участники</th>
                                            <th width="15%">Статус мероприятия</th>
                                            <th width="15%">Действия</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($events as $event): ?>
                                            <?php
                                            // Парсим class_distance для отображения
                                            $classData = json_decode($event['class_distance'], true);
                                            $classes = [];
                                            $distances = [];
                                            
                                            if ($classData) {
                                                foreach ($classData as $class => $data) {
                                                    $classes[] = $class;
                                                    if (isset($data['dist'])) {
                                                        if (is_array($data['dist'])) {
                                                            $distances = array_merge($distances, $data['dist']);
                                                        } else {
                                                            $distances[] = $data['dist'];
                                                        }
                                                    }
                                                }
                                                $classes = array_unique($classes);
                                                $distances = array_unique($distances);
                                            }
                                            
                                            $statusInfo = getStatusInfo($event['status']);
                                            ?>
                                            <tr>
                                                <td>
                                                    <span class="badge bg-secondary"><?= htmlspecialchars($event['champn']) ?></span>
                                                </td>
                                                <td>
                                                    <div>
                                                        <strong><?= htmlspecialchars($event['meroname']) ?></strong>
                                                        <br>
                                                        <small class="text-muted">
                                                            <i class="bi bi-calendar3"></i> 
                                                            <?= htmlspecialchars($event['merodata']) ?>
                                                        </small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if (!empty($classes)): ?>
                                                        <?php foreach (array_slice($classes, 0, 3) as $class): ?>
                                                            <span class="badge bg-secondary me-1 mb-1"><?= htmlspecialchars($class) ?></span>
                                                        <?php endforeach; ?>
                                                        <?php if (count($classes) > 3): ?>
                                                            <small class="text-muted">+<?= count($classes) - 3 ?> еще</small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Не указаны</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($distances)): ?>
                                                        <?php foreach (array_slice($distances, 0, 2) as $dist): ?>
                                                            <span class="badge bg-info me-1 mb-1"><?= htmlspecialchars($dist) ?>м</span>
                                                        <?php endforeach; ?>
                                                        <?php if (count($distances) > 2): ?>
                                                            <small class="text-muted">+<?= count($distances) - 2 ?> еще</small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Не указаны</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-primary fs-6">
                                                        <?= $event['participants_count'] ?>
                                                    </span>
                                                    <small class="text-muted d-block">участников</small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= $statusInfo['class'] ?> fs-6">
                                                        <i class="<?= $statusInfo['icon'] ?>"></i> 
                                                        <?= $statusInfo['text'] ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $trimmedStatus = trim($event['status']);
                                                    if ($trimmedStatus === 'Регистрация закрыта'): ?>
                                                        <div class="btn-group btn-group-sm">
                                                            <button class="btn btn-success btn-sm" onclick="selectDrawType(<?= $event['champn'] ?>)">
                                                                <i class="bi bi-play-circle"></i> Начать судейство
                                                            </button>
                                                            <a href="conduct_event.php?event_id=<?= $event['champn'] ?>" class="btn btn-warning btn-sm">
                                                                <i class="bi bi-shuffle"></i> Новая система
                                                            </a>
                                                        </div>
                                                    <?php elseif ($trimmedStatus === 'В процессе'): ?>
                                                        <div class="btn-group btn-group-sm">
                                                            <button class="btn btn-primary btn-sm" onclick="continueEvent(<?= $event['champn'] ?>)">
                                                                <i class="bi bi-gear"></i> Продолжить
                                                            </button>
                                                            <a href="conduct_event.php?event_id=<?= $event['champn'] ?>" class="btn btn-warning btn-sm">
                                                                <i class="bi bi-shuffle"></i> Новая система
                                                            </a>
                                                        </div>
                                                    <?php elseif ($trimmedStatus === 'Результаты'): ?>
                                                        <div class="btn-group btn-group-sm">
                                                            <button class="btn btn-info" onclick="viewResults(<?= $event['champn'] ?>)">
                                                                <i class="bi bi-eye"></i> Просмотр
                                                            </button>
                                                            <button class="btn btn-warning" onclick="finalizeEvent(<?= $event['champn'] ?>)">
                                                                <i class="bi bi-check-circle"></i> Завершить
                                                            </button>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Информационные блоки с инструкциями -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card border-primary">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="bi bi-info-circle"></i> Порядок проведения</h6>
                            </div>
                            <div class="card-body">
                                <h6 class="text-primary">Этапы работы:</h6>
                                <ol class="small">
                                    <li><strong>Выбор мероприятия</strong> - выберите мероприятие со статусом "Регистрация закрыта"</li>
                                    <li><strong>Тип жеребьевки</strong> - выберите тип проведения соревнований</li>
                                    <li><strong>Дисциплины</strong> - выберите дисциплины для жеребьевки</li>
                                    <li><strong>Протоколы</strong> - создайте стартовые и финишные протоколы</li>
                                    <li><strong>Результаты</strong> - обработайте финишные данные</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card border-warning">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Важные замечания</h6>
                            </div>
                            <div class="card-body">
                                <h6 class="text-warning">Перед началом работы:</h6>
                                <ul class="small">
                                    <li>Убедитесь, что все участники зарегистрированы</li>
                                    <li>Проверьте наличие всех необходимых документов</li>
                                    <li>Сохраняйте протоколы после каждого этапа</li>
                                    <li>При технических проблемах обратитесь к администратору</li>
                                </ul>
                                
                                <div class="mt-3">
                                    <small class="text-muted">
                                        <i class="bi bi-info-circle"></i>
                                        Система автоматически сохраняет данные через Redis
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
            </div>
        </div>
    </div>

    <!-- Модальное окно выбора типа жеребьевки -->
    <div class="modal fade" id="drawTypeModal" tabindex="-1" aria-labelledby="drawTypeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="drawTypeModalLabel">
                        <i class="bi bi-trophy"></i> Выбор типа жеребьевки
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card border-info h-100">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0">Информация о мероприятии</h6>
                                </div>
                                <div class="card-body">
                                    <p><strong>ID:</strong> <span id="modalEventId"></span></p>
                                    <p><strong>Название:</strong> <span id="modalEventName"></span></p>
                                    <p><strong>Дата:</strong> <span id="modalEventDate"></span></p>
                                    <p><strong>Участников:</strong> <span id="modalParticipants"></span></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-success h-100">
                                <div class="card-header bg-success text-white">
                                    <h6 class="mb-0">Выбор типа жеребьевки</h6>
                                </div>
                                <div class="card-body">
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="radio" name="drawType" id="drawTypeFinals" value="finals" checked>
                                        <label class="form-check-label" for="drawTypeFinals">
                                            <strong>Полуфиналы и Финалы</strong>
                                        </label>
                                        <small class="d-block text-muted">
                                            Классическая схема проведения соревнований
                                        </small>
                                    </div>
                                    
                                    <div class="alert alert-info">
                                        <h6 class="alert-heading">
                                            <i class="bi bi-info-circle"></i> Логика формирования заездов:
                                        </h6>
                                        <ul class="small mb-0">
                                            <li><strong>До 9 участников:</strong> сразу финальный заезд</li>
                                            <li><strong>9-18 участников:</strong> полуфинальные заезды + финал</li>
                                            <li><strong>18+ участников:</strong> предварительные + полуфинал + финал</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-12">
                            <div class="alert alert-warning">
                                <h6 class="alert-heading">
                                    <i class="bi bi-exclamation-triangle"></i> Внимание!
                                </h6>
                                <p class="mb-0">
                                    После выбора типа жеребьевки вы перейдете к выбору дисциплин для проведения соревнований.
                                    Убедитесь, что все участники зарегистрированы и подтверждены.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Отменить
                    </button>
                    <button type="button" class="btn btn-success" onclick="proceedWithDraw()">
                        <i class="bi bi-arrow-right-circle"></i> Продолжить к выбору дисциплин
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript для интерактивности -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Глобальная переменная для хранения ID выбранного мероприятия
        let selectedEventId = null;
        
        // Функция выбора типа жеребьевки
        function selectDrawType(eventId) {
            selectedEventId = eventId;
            
            // Находим данные мероприятия в таблице
            const row = document.querySelector(`[onclick*="${eventId}"]`).closest('tr');
            const eventName = row.cells[1].querySelector('strong').textContent;
                         const eventDate = row.cells[1].querySelector('small').textContent.trim();
            const participants = row.cells[4].querySelector('.badge').textContent;
            
            // Заполняем модальное окно данными
            document.getElementById('modalEventId').textContent = eventId;
            document.getElementById('modalEventName').textContent = eventName;
            document.getElementById('modalEventDate').textContent = eventDate;
            document.getElementById('modalParticipants').textContent = participants;
            
            // Показываем модальное окно
            const modal = new bootstrap.Modal(document.getElementById('drawTypeModal'));
            modal.show();
        }
        
        // Функция продолжения к выбору дисциплин
        function proceedWithDraw() {
            const selectedDrawType = document.querySelector('input[name="drawType"]:checked').value;
            
            // Закрываем модальное окно
            const modal = bootstrap.Modal.getInstance(document.getElementById('drawTypeModal'));
            modal.hide();
            
            // Создаем скрытую форму для отправки данных
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'process-event-selection.php';
            
            // Добавляем поля формы
            const eventIdField = document.createElement('input');
            eventIdField.type = 'hidden';
            eventIdField.name = 'event_id';
            eventIdField.value = selectedEventId;
            form.appendChild(eventIdField);
            
            const actionField = document.createElement('input');
            actionField.type = 'hidden';
            actionField.name = 'action';
            actionField.value = 'select_disciplines';
            form.appendChild(actionField);
            
            const drawTypeField = document.createElement('input');
            drawTypeField.type = 'hidden';
            drawTypeField.name = 'draw_type';
            drawTypeField.value = selectedDrawType;
            form.appendChild(drawTypeField);
            
            // Добавляем форму на страницу и отправляем
            document.body.appendChild(form);
            form.submit();
        }
        
        // Функция продолжения мероприятия
        function continueEvent(eventId) {
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
            
            // Добавляем форму на страницу и отправляем
            document.body.appendChild(form);
            form.submit();
        }
        
        // Функция просмотра результатов
        function viewResults(eventId) {
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
            actionField.value = 'results';
            form.appendChild(actionField);
            
            // Добавляем форму на страницу и отправляем
            document.body.appendChild(form);
            form.submit();
        }
        
        // Функция завершения мероприятия
        function finalizeEvent(eventId) {
            if (confirm('Вы уверены, что хотите завершить мероприятие? Это действие нельзя отменить.')) {
                // TODO: Реализовать завершение мероприятия
                alert('Функция завершения мероприятия ' + eventId + ' будет реализована');
            }
        }
    </script>
</body>
</html> 
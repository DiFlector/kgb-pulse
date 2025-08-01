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

// Устанавливаем заголовок страницы
$pageTitle = 'Проведение мероприятий - KGB-Pulse';

include '../includes/header.php';
?>

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

    <!-- Список мероприятий -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-calendar-event"></i>
                        Мероприятия для проведения
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($events)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-calendar-x display-1 text-muted"></i>
                            <h4 class="text-muted mt-3">Нет мероприятий для проведения</h4>
                            <p class="text-muted">Мероприятия появятся здесь после закрытия регистрации организатором</p>
                            <a href="index.php" class="btn btn-primary">
                                <i class="bi bi-arrow-left"></i> Вернуться к панели
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Мероприятие</th>
                                        <th>Дата</th>
                                        <th>Участники</th>
                                        <th>Статус</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($events as $event): ?>
                                        <?php $statusInfo = getStatusInfo($event['status']); ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($event['meroname']) ?></strong>
                                                <br>
                                                <small class="text-muted">№<?= $event['champn'] ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($event['merodata']) ?></td>
                                            <td>
                                                <span class="badge bg-primary"><?= $event['participants_count'] ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= $statusInfo['class'] ?>">
                                                    <i class="bi <?= $statusInfo['icon'] ?>"></i>
                                                    <?= $statusInfo['text'] ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                $trimmedStatus = trim($event['status']);
                                                if ($trimmedStatus === 'Регистрация закрыта'): ?>
                                                    <button class="btn btn-sm btn-success" onclick="startEvent(<?= $event['champn'] ?>)">
                                                        <i class="bi bi-play-circle"></i> Начать судейство
                                                    </button>
                                                <?php elseif ($trimmedStatus === 'В процессе'): ?>
                                                    <button class="btn btn-sm btn-primary" onclick="continueEvent(<?= $event['champn'] ?>)">
                                                        <i class="bi bi-gear"></i> Продолжить
                                                    </button>
                                                <?php elseif ($trimmedStatus === 'Результаты'): ?>
                                                    <button class="btn btn-sm btn-info" onclick="viewResults(<?= $event['champn'] ?>)">
                                                        <i class="bi bi-eye"></i> Результаты
                                                    </button>
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
        </div>
    </div>
</div>

<!-- Модальное окно для выбора типа жеребьевки -->
<div class="modal fade" id="drawTypeModal" tabindex="-1" aria-labelledby="drawTypeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="drawTypeModalLabel">Выберите тип жеребьевки</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="drawType" id="drawType1" value="semifinals_finals" checked>
                    <label class="form-check-label" for="drawType1">
                        Полуфиналы и Финалы
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="drawType" id="drawType2" value="direct_finals" disabled>
                    <label class="form-check-label text-muted" for="drawType2">
                        Прямые финалы (скоро)
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" onclick="proceedWithDraw()">Продолжить</button>
            </div>
        </div>
    </div>
</div>

<script>
    let selectedEventId = null;

    // Функция начала мероприятия
    function startEvent(eventId) {
        selectedEventId = eventId;
        
        // Показываем модальное окно для выбора типа жеребьевки
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

<?php include '../includes/footer.php'; ?> 
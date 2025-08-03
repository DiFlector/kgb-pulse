<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Secretary' && $_SESSION['user_role'] !== 'SuperUser' && $_SESSION['user_role'] !== 'Admin')) {
    header('Location: /lks/login.php');
    exit;
}

require_once '../../php/db/Database.php';

$db = Database::getInstance();

// Получаем статистику для секретаря
try {
    // Общее количество мероприятий
    $totalEventsStmt = $db->prepare("SELECT COUNT(*) as count FROM meros");
    $totalEventsStmt->execute();
    $totalEvents = $totalEventsStmt->fetch()['count'];

    // Мероприятия готовые к проведению (статус "Регистрация закрыта")
    $readyEventsStmt = $db->prepare("SELECT COUNT(*) as count FROM meros WHERE TRIM(status::text) = 'Регистрация закрыта'");
    $readyEventsStmt->execute();
    $readyEvents = $readyEventsStmt->fetch()['count'];

    // Проводимые мероприятия (статус "В процессе")
    $inProgressStmt = $db->prepare("SELECT COUNT(*) as count FROM meros WHERE TRIM(status::text) = 'В процессе'");
    $inProgressStmt->execute();
    $inProgressEvents = $inProgressStmt->fetch()['count'];

    // Завершенные мероприятия
    $completedStmt = $db->prepare("SELECT COUNT(*) as count FROM meros WHERE TRIM(status::text) IN ('Результаты', 'Завершено')");
    $completedStmt->execute();
    $completedEvents = $completedStmt->fetch()['count'];

    // Мероприятия, требующие внимания секретаря
    $eventsStmt = $db->prepare("
        SELECT champn, meroname, merodata, status::text as status, class_distance,
               (SELECT COUNT(*) FROM listreg l JOIN meros m2 ON l.meros_oid = m2.oid WHERE m2.champn = m.champn) as participants_count
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
        LIMIT 10
    ");
    $eventsStmt->execute();
    $events = $eventsStmt->fetchAll();

    // Последние созданные протоколы (если будет таблица protocols)
    $recentProtocols = []; // Заглушка, пока нет таблицы протоколов

} catch (Exception $e) {
    error_log("Ошибка получения статистики секретаря: " . $e->getMessage());
    $totalEvents = $readyEvents = $inProgressEvents = $completedEvents = 0;
    $events = $recentProtocols = [];
}

function getStatusColor($status) {
    $trimmedStatus = trim($status);
    switch($trimmedStatus) {
        case 'В ожидании': return 'secondary';
        case 'Регистрация': return 'success';
        case 'Регистрация закрыта': return 'warning';
        case 'В процессе': return 'info';
        case 'Результаты': return 'primary';
        case 'Завершено': return 'dark';
        default: return 'light';
    }
}

include '../includes/header.php';
?>

<!-- Заголовок панели -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Панель секретаря</h1>
    <div class="btn-group">
        <a href="main.php" class="btn btn-success">
            <i class="bi bi-play-circle"></i> Проведение мероприятий
        </a>
        <a href="events.php" class="btn btn-primary">
            <i class="bi bi-calendar-event"></i> Все мероприятия
        </a>
    </div>
</div>

<!-- Карточки статистики -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card stat-card border-left-primary shadow h-100">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                            Всего мероприятий
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $totalEvents ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-calendar-event text-primary" style="font-size: 2em;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card stat-card border-left-warning shadow h-100">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                            Готовы к проведению
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $readyEvents ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-clock text-warning" style="font-size: 2em;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card stat-card border-left-info shadow h-100">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                            В процессе
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $inProgressEvents ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-activity text-info" style="font-size: 2em;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card stat-card border-left-success shadow h-100">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                            Завершённые
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $completedEvents ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-check-circle text-success" style="font-size: 2em;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Мероприятия для работы -->
<div class="row">
    <div class="col-lg-8 mb-4">
        <div class="card shadow">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold">Мероприятия для работы</h6>
                <a href="main.php" class="btn btn-sm btn-outline-light">Проведение мероприятий</a>
            </div>
            <div class="card-body">
                <?php if (empty($events)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-calendar-x display-4"></i>
                        <p class="mt-2">Нет мероприятий для проведения</p>
                        <small>Мероприятия появятся здесь после закрытия регистрации организатором</small>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Наименование соревнований</th>
                                    <th>Дата</th>
                                    <th>Участники</th>
                                    <th>Статус</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($events as $event): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($event['meroname']) ?></strong>
                                        </td>
                                        <td><?= htmlspecialchars($event['merodata']) ?></td>
                                        <td>
                                            <span class="badge bg-primary"><?= $event['participants_count'] ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= getStatusColor($event['status']) ?>">
                                                <?= htmlspecialchars(trim($event['status'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $trimmedStatus = trim($event['status']);
                                            if ($trimmedStatus === 'Регистрация закрыта'): ?>
                                                <a href="main.php?event=<?= $event['champn'] ?>" class="btn btn-sm btn-success">
                                                    <i class="bi bi-play-circle"></i> Начать судейство
                                                </a>
                                            <?php elseif ($trimmedStatus === 'В процессе'): ?>
                                                <a href="main.php?event=<?= $event['champn'] ?>" class="btn btn-sm btn-primary">
                                                    <i class="bi bi-gear"></i> Продолжить
                                                </a>
                                            <?php elseif ($trimmedStatus === 'Результаты'): ?>
                                                <a href="results.php?event=<?= $event['champn'] ?>" class="btn btn-sm btn-info">
                                                    <i class="bi bi-eye"></i> Результаты
                                                </a>
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

    <!-- Полезная информация -->
    <div class="col-lg-4 mb-4">
        <div class="card shadow">
            <div class="card-header bg-info text-white">
                <h6 class="m-0 font-weight-bold">Инструкции</h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <h6 class="font-weight-bold text-primary">
                        <i class="bi bi-info-circle"></i> Порядок работы
                    </h6>
                    <ol class="small">
                        <li>Выберите мероприятие со статусом "Регистрация закрыта"</li>
                        <li>Выберите тип жеребьевки (пока доступны "Полуфиналы и Финалы")</li>
                        <li>Создайте стартовые и финишные протоколы</li>
                        <li>Заполните результаты по мере проведения</li>
                        <li>Завершите мероприятие для формирования итогов</li>
                    </ol>
                </div>
                
                <div class="mb-3">
                    <h6 class="font-weight-bold text-warning">
                        <i class="bi bi-exclamation-triangle"></i> Важные замечания
                    </h6>
                    <ul class="small">
                        <li>Убедитесь, что все участники зарегистрированы</li>
                        <li>Проверьте наличие всех необходимых документов</li>
                        <li>Сохраняйте протоколы после каждого этапа</li>
                        <li>При технических проблемах обратитесь к администратору</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?> 
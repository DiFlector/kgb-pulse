<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Organizer' && $_SESSION['user_role'] !== 'SuperUser' && $_SESSION['user_role'] !== 'Admin')) {
    header('Location: /lks/login.php');
    exit;
}

require_once '../../php/db/Database.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'];

// Получаем статистику для панели
try {
    // Статистика мероприятий организатора
    $myEventsStmt = $db->prepare("SELECT COUNT(*) as count FROM meros WHERE created_by = ?");
    $myEventsStmt->execute([$userId]);
    $myEventsCount = $myEventsStmt->fetch()['count'];

    // Активные мероприятия
    $activeEventsStmt = $db->prepare("SELECT COUNT(*) as count FROM meros WHERE created_by = ? AND status IN ('В ожидании', 'Регистрация')");
    $activeEventsStmt->execute([$userId]);
    $activeEventsCount = $activeEventsStmt->fetch()['count'];

    // Всего регистраций на мероприятия организатора
    $registrationsStmt = $db->prepare("SELECT COUNT(*) as count FROM listreg l INNER JOIN meros m ON (l.meros_oid = m.oid) WHERE m.created_by = ?");
    $registrationsStmt->execute([$userId]);
    $totalRegistrations = $registrationsStmt->fetch()['count'];

    // Завершённые мероприятия
    $completedEventsStmt = $db->prepare("SELECT COUNT(*) as count FROM meros WHERE created_by = ? AND status::text = 'Завершено'");
    $completedEventsStmt->execute([$userId]);
    $completedEventsCount = $completedEventsStmt->fetch()['count'];

    // Последние мероприятия
    $recentEventsStmt = $db->prepare("
        SELECT oid, meroname, merodata, status, class_distance 
        FROM meros 
        WHERE created_by = ? 
        ORDER BY oid DESC 
        LIMIT 5
    ");
    $recentEventsStmt->execute([$userId]);
    $recentEvents = $recentEventsStmt->fetchAll();

    // Последние регистрации
    $recentRegistrationsStmt = $db->prepare("SELECT u.fio, m.meroname, l.status, l.oid, u.userid FROM listreg l INNER JOIN users u ON l.users_oid = u.oid INNER JOIN meros m ON l.meros_oid = m.oid WHERE m.created_by = ? ORDER BY l.oid DESC LIMIT 5");
    $recentRegistrationsStmt->execute([$userId]);
    $recentRegistrations = $recentRegistrationsStmt->fetchAll();

} catch (Exception $e) {
    error_log("Ошибка получения статистики организатора: " . $e->getMessage());
    $myEventsCount = $activeEventsCount = $totalRegistrations = $completedEventsCount = 0;
    $recentEvents = $recentRegistrations = [];
}

include '../includes/header.php';
?>

<!-- Заголовок панели -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Панель организатора</h1>
    <div class="btn-group">
        <button class="btn btn-success" onclick="location.href='create-event.php'">
            <i class="bi bi-plus-circle"></i> Создать мероприятие
        </button>
        <button class="btn btn-primary" onclick="location.href='events.php'">
            <i class="bi bi-calendar-event"></i> Все мероприятия
        </button>
        <button class="btn btn-info" onclick="location.href='test-event-parser.php'">
            <i class="bi bi-file-earmark-spreadsheet"></i> Тест парсера
        </button>
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
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $myEventsCount ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-calendar-event text-primary" style="font-size: 2em;"></i>
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
                            Активные
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $activeEventsCount ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-activity text-success" style="font-size: 2em;"></i>
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
                            Всего регистраций
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $totalRegistrations ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-people text-info" style="font-size: 2em;"></i>
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
                            Завершённые
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $completedEventsCount ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-check-circle text-warning" style="font-size: 2em;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Последние мероприятия -->
    <div class="col-lg-8 mb-4">
        <div class="card shadow">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold">Последние мероприятия</h6>
                <a href="events.php" class="btn btn-sm btn-outline-light">Все мероприятия</a>
            </div>
            <div class="card-body">
                <?php if (empty($recentEvents)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-calendar-x display-4"></i>
                        <p class="mt-2">Нет созданных мероприятий</p>
                        <a href="create-event.php" class="btn btn-success">Создать первое мероприятие</a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Название</th>
                                    <th>Дата</th>
                                    <th>Статус</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentEvents as $event): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($event['meroname']) ?></strong>
                                    </td>
                                    <td><?= htmlspecialchars($event['merodata']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= getStatusColor($event['status']) ?>">
                                            <?= htmlspecialchars($event['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="events.php?edit=<?= $event['oid'] ?>" class="btn btn-outline-primary" title="Редактировать">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="/lks/enter/organizer/registrations.php?event=<?= $event['oid'] ?>" class="btn btn-outline-info" title="Регистрации">
                                                <i class="bi bi-people"></i>
                                            </a>
                                        </div>
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

    <!-- Последние регистрации -->
    <div class="col-lg-4 mb-4">
        <div class="card shadow h-100">
            <div class="card-header bg-success text-white">
                <h6 class="m-0 font-weight-bold">Последние регистрации</h6>
            </div>
            <div class="card-body">
                <?php if (empty($recentRegistrations)): ?>
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-inbox display-4"></i>
                        <p class="mt-2">Нет регистраций</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recentRegistrations as $registration): ?>
                    <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                        <div>
                            <div class="fw-bold"><?= htmlspecialchars($registration['fio']) ?></div>
                            <small class="text-muted"><?= htmlspecialchars($registration['meroname']) ?></small>
                        </div>
                        <span class="badge bg-<?= getRegStatusColor($registration['status']) ?>">
                            <?= htmlspecialchars($registration['status']) ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
function getStatusColor($status) {
    switch($status) {
        case 'В ожидании': return 'secondary';
        case 'Регистрация': return 'success';
        case 'Регистрация закрыта': return 'warning';
        case 'Перенесено': return 'info';
        case 'Результаты': return 'primary';
        case 'Завершено': return 'dark';
        default: return 'light';
    }
}

function getRegStatusColor($status) {
    switch($status) {
        case 'Зарегистрирован': return 'primary';
        case 'Подтверждён': return 'success';
        case 'В ожидании': return 'warning';
        case 'Дисквалифицирован': return 'danger';
        case 'Неявка': return 'secondary';
        default: return 'secondary';
    }
}

include '../includes/footer.php';
?> 
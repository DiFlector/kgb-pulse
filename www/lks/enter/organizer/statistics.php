<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Organizer' && $_SESSION['user_role'] !== 'SuperUser' && $_SESSION['user_role'] !== 'Admin')) {
    header('Location: /lks/login.php');
    exit;
}

require_once '../../php/db/Database.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'];

// Логика доступа по ролям
$hasFullAccess = $_SESSION['user_role'] === 'SuperUser' ||
                ($_SESSION['user_role'] === 'Admin' && $userId >= 1 && $userId <= 50) ||
                ($_SESSION['user_role'] === 'Organizer' && $userId >= 51 && $userId <= 100) ||
                ($_SESSION['user_role'] === 'Secretary' && $userId >= 151 && $userId <= 200);

// Добавим отладочную информацию
error_log("Statistics Debug: UserID={$userId}, Role={$userRole}, HasFullAccess=" . ($hasFullAccess ? 'YES' : 'NO'));

try {
    // Сначала проверим, есть ли поле created_by в таблице meros
    $checkColumnStmt = $db->prepare("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'meros' AND column_name = 'created_by'
    ");
    $checkColumnStmt->execute();
    $hasCreatedByColumn = $checkColumnStmt->rowCount() > 0;
    
    error_log("Statistics Debug: created_by column exists: " . ($hasCreatedByColumn ? 'YES' : 'NO'));

    // Статистика мероприятий
    if ($hasFullAccess || !$hasCreatedByColumn) {
        // Полный доступ или нет поля created_by - показываем все
        $eventsStmt = $db->prepare("
            SELECT COUNT(*) as total_events,
                   COUNT(CASE WHEN status = 'Регистрация' THEN 1 END) as active_events,
                   COUNT(CASE WHEN status = 'Завершено' THEN 1 END) as completed_events
            FROM meros
        ");
        $eventsStmt->execute();
        error_log("Statistics Debug: Using full access query for events");
    } else {
        // Ограниченный доступ - только свои мероприятия
        $eventsStmt = $db->prepare("
            SELECT COUNT(*) as total_events,
                   COUNT(CASE WHEN status = 'Регистрация' THEN 1 END) as active_events,
                   COUNT(CASE WHEN status = 'Завершено' THEN 1 END) as completed_events
            FROM meros WHERE created_by = ?
        ");
        $eventsStmt->execute([$userId]);
        error_log("Statistics Debug: Using restricted access query for events, UserID: {$userId}");
    }
    $eventStats = $eventsStmt->fetch();
    error_log("Statistics Debug: Event stats: " . json_encode($eventStats));

    // Статистика регистраций
    if ($hasFullAccess || !$hasCreatedByColumn) {
        // Полный доступ или нет поля created_by - показываем все
        $regStmt = $db->prepare("
            SELECT COUNT(*) as total_registrations,
                   COUNT(CASE WHEN l.status = 'Подтверждён' THEN 1 END) as confirmed_registrations,
                   COUNT(CASE WHEN l.oplata = true THEN 1 END) as paid_registrations
            FROM listreg l
            LEFT JOIN meros m ON l.meros_oid = m.oid
        ");
        $regStmt->execute();
        error_log("Statistics Debug: Using full access query for registrations");
    } else {
        // Ограниченный доступ - только регистрации на свои мероприятия
        $regStmt = $db->prepare("
            SELECT COUNT(*) as total_registrations,
                   COUNT(CASE WHEN l.status = 'Подтверждён' THEN 1 END) as confirmed_registrations,
                   COUNT(CASE WHEN l.oplata = true THEN 1 END) as paid_registrations
            FROM listreg l
            INNER JOIN meros m ON l.meros_oid = m.oid
            WHERE m.created_by = ?
        ");
        $regStmt->execute([$userId]);
        error_log("Statistics Debug: Using restricted access query for registrations, UserID: {$userId}");
    }
    $regStats = $regStmt->fetch();
    error_log("Statistics Debug: Registration stats: " . json_encode($regStats));

    // Статистика по месяцам (последние 12 месяцев)
    // Упрощенный запрос без парсинга дат для начала
    if ($hasFullAccess || !$hasCreatedByColumn) {
        // Полный доступ или нет поля created_by - показываем все
        $monthlyStmt = $db->prepare("
            SELECT merodata, COUNT(*) as events_count
            FROM meros 
            GROUP BY merodata
            ORDER BY merodata DESC
            LIMIT 12
        ");
        $monthlyStmt->execute();
        error_log("Statistics Debug: Using full access query for monthly stats");
    } else {
        // Ограниченный доступ - только свои мероприятия
        $monthlyStmt = $db->prepare("
            SELECT merodata, COUNT(*) as events_count
            FROM meros 
            WHERE created_by = ?
            GROUP BY merodata
            ORDER BY merodata DESC
            LIMIT 12
        ");
        $monthlyStmt->execute([$userId]);
        error_log("Statistics Debug: Using restricted access query for monthly stats, UserID: {$userId}");
    }
    $monthlyStats = $monthlyStmt->fetchAll();
    error_log("Statistics Debug: Monthly stats count: " . count($monthlyStats));

    // Топ мероприятий по регистрациям
    if ($hasFullAccess || !$hasCreatedByColumn) {
        // Полный доступ или нет поля created_by - показываем все
        $topEventsStmt = $db->prepare("
            SELECT m.meroname, m.merodata, COUNT(l.oid) as registrations_count
            FROM meros m
            LEFT JOIN listreg l ON m.oid = l.meros_oid
            GROUP BY m.oid, m.meroname, m.merodata
            ORDER BY registrations_count DESC
            LIMIT 10
        ");
        $topEventsStmt->execute();
        error_log("Statistics Debug: Using full access query for top events");
    } else {
        // Ограниченный доступ - только свои мероприятия
        $topEventsStmt = $db->prepare("
            SELECT m.meroname, m.merodata, COUNT(l.oid) as registrations_count
            FROM meros m
            LEFT JOIN listreg l ON m.oid = l.meros_oid
            WHERE m.created_by = ?
            GROUP BY m.oid, m.meroname, m.merodata
            ORDER BY registrations_count DESC
            LIMIT 10
        ");
        $topEventsStmt->execute([$userId]);
        error_log("Statistics Debug: Using restricted access query for top events, UserID: {$userId}");
    }
    $topEvents = $topEventsStmt->fetchAll();
    error_log("Statistics Debug: Top events count: " . count($topEvents));

} catch (Exception $e) {
    error_log("Statistics page error: " . $e->getMessage());
    $eventStats = ['total_events' => 0, 'active_events' => 0, 'completed_events' => 0];
    $regStats = ['total_registrations' => 0, 'confirmed_registrations' => 0, 'paid_registrations' => 0];
    $monthlyStats = [];
    $topEvents = [];
}

include '../includes/header.php';
?>

<!-- Заголовок страницы -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">
        <?php if ($hasFullAccess): ?>
            Общая статистика
            <small class="text-muted">(Полный доступ)</small>
        <?php else: ?>
            Моя статистика
        <?php endif; ?>
    </h1>
    <div class="btn-group">
        <a href="events.php" class="btn btn-primary">
            <i class="bi bi-calendar-event"></i> К мероприятиям
        </a>
        <a href="index.php" class="btn btn-secondary">
            <i class="bi bi-house"></i> Главная
        </a>
    </div>
</div>

<!-- Основная статистика -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-primary shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                            Всего мероприятий
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                            <?= $eventStats['total_events'] ?>
                        </div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-calendar-event fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-success shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                            Активных мероприятий
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                            <?= $eventStats['active_events'] ?>
                        </div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-play-circle fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-info shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                            Всего регистраций
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                            <?= $regStats['total_registrations'] ?>
                        </div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-people fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-warning shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                            Завершённых мероприятий
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                            <?= $eventStats['completed_events'] ?>
                        </div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-check-circle fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Дополнительная статистика -->
<div class="row mb-4">
    <div class="col-xl-4 col-md-6 mb-4">
        <div class="card border-left-success shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                            Подтверждённые регистрации
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                            <?= $regStats['confirmed_registrations'] ?>
                        </div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-check-square fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-4 col-md-6 mb-4">
        <div class="card border-left-info shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                            Оплаченные регистрации
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                            <?= $regStats['paid_registrations'] ?>
                        </div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-credit-card fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-4 col-md-12 mb-4">
        <div class="card border-left-primary shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                            Процент оплаты
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                            <?php 
                            $paymentPercent = $regStats['total_registrations'] > 0 
                                ? round(($regStats['paid_registrations'] / $regStats['total_registrations']) * 100, 1) 
                                : 0;
                            echo $paymentPercent . '%';
                            ?>
                        </div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-percent fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Топ мероприятий по регистрациям -->
<div class="row">
    <div class="col-lg-8 mb-4">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h6 class="m-0 font-weight-bold">Топ мероприятий по количеству регистраций</h6>
            </div>
            <div class="card-body">
                <?php if (empty($topEvents)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-inbox display-4"></i>
                        <p class="mt-3">Нет данных для отображения</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Мероприятие</th>
                                    <th>Дата</th>
                                    <th>Регистраций</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topEvents as $event): ?>
                                <tr>
                                    <td><?= htmlspecialchars($event['meroname']) ?></td>
                                    <td><?= htmlspecialchars($event['merodata']) ?></td>
                                    <td>
                                        <span class="badge bg-primary"><?= $event['registrations_count'] ?></span>
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

    <!-- Статистика по месяцам -->
    <div class="col-lg-4 mb-4">
        <div class="card shadow">
            <div class="card-header bg-info text-white">
                <h6 class="m-0 font-weight-bold">Мероприятия по месяцам</h6>
            </div>
            <div class="card-body">
                <?php if (empty($monthlyStats)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-calendar-x display-4"></i>
                        <p class="mt-3">Нет данных</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($monthlyStats as $month): ?>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span><?= htmlspecialchars($month['merodata']) ?></span>
                        <span class="badge bg-info"><?= $month['events_count'] ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?> 
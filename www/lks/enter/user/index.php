<?php
/**
 * Личный кабинет спортсмена
 */

// Проверка авторизации
session_start();
require_once '../../php/helpers.php';

if (!hasAccess('Sportsman')) {
    header('Location: /lks/login.php');
    exit;
}

require_once '../../php/db/Database.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'];

// Получаем данные пользователя
try {
    $userStmt = $db->prepare("SELECT * FROM users WHERE userid = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception("Пользователь не найден");
    }
} catch (Exception $e) {
    error_log("Ошибка получения данных пользователя: " . $e->getMessage());
    header('Location: /lks/login.php');
    exit;
}

// Получаем статистику спортсмена
try {
    // Получаем oid пользователя
    $userOidStmt = $db->prepare("SELECT oid FROM users WHERE userid = ?");
    $userOidStmt->execute([$userId]);
    $userOid = $userOidStmt->fetchColumn();
    
    // Количество регистраций
    $registrationsStmt = $db->prepare("SELECT COUNT(*) as count FROM listreg WHERE users_oid = ?");
    $registrationsStmt->execute([$userOid]);
    $totalRegistrations = $registrationsStmt->fetch()['count'];

    // Количество мероприятий, в которых участвовал
    $eventsStmt = $db->prepare("
        SELECT COUNT(DISTINCT m.champn) as count 
        FROM listreg l
        JOIN meros m ON l.meros_oid = m.oid
        WHERE l.users_oid = ? AND l.status NOT IN ('В очереди', 'Дисквалифицирован')
    ");
    $eventsStmt->execute([$userOid]);
    $totalEvents = $eventsStmt->fetch()['count'];

    // Призовые места
    $prizesStmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM user_statistic 
        WHERE users_oid = ? AND place IN ('1', '2', '3')
    ");
    $prizesStmt->execute([$userOid]);
    $prizePlaces = $prizesStmt->fetch()['count'];

    // Активные регистрации
    $activeRegStmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM listreg l
        INNER JOIN meros m ON l.meros_oid = m.oid
        WHERE l.users_oid = ? AND m.status IN ('Регистрация', 'Регистрация закрыта')
    ");
    $activeRegStmt->execute([$userOid]);
    $activeRegistrations = $activeRegStmt->fetch()['count'];

    // Последние результаты
    $recentResultsStmt = $db->prepare("
        SELECT meroname, place, time, race_type, data 
        FROM user_statistic 
        WHERE users_oid = ? 
        ORDER BY data DESC 
        LIMIT 5
    ");
    $recentResultsStmt->execute([$userOid]);
    $recentResults = $recentResultsStmt->fetchAll();

    // Ближайшие мероприятия
    $upcomingEventsStmt = $db->prepare("
        SELECT m.champn, m.meroname, m.merodata, m.status, l.status as reg_status
        FROM meros m
        INNER JOIN listreg l ON m.oid = l.meros_oid
        WHERE l.users_oid = ? AND m.status IN ('Регистрация', 'Регистрация закрыта', 'В ожидании')
        ORDER BY m.merodata ASC
        LIMIT 5
    ");
    $upcomingEventsStmt->execute([$userOid]);
    $upcomingEvents = $upcomingEventsStmt->fetchAll();

    // Доступные мероприятия для регистрации
    $availableEventsStmt = $db->prepare("
        SELECT m.champn, m.meroname, m.merodata, m.status, m.defcost
        FROM meros m
        WHERE m.status::text = 'Регистрация' 
        AND m.oid NOT IN (
            SELECT meros_oid FROM listreg WHERE users_oid = ?
        )
        ORDER BY m.merodata ASC
        LIMIT 3
    ");
    $availableEventsStmt->execute([$userOid]);
    $availableEvents = $availableEventsStmt->fetchAll();

} catch (Exception $e) {
    error_log("Ошибка получения статистики пользователя: " . $e->getMessage());
    $totalRegistrations = $totalEvents = $prizePlaces = $activeRegistrations = 0;
    $recentResults = $upcomingEvents = $availableEvents = [];
}

include '../includes/header.php';
?>

<!-- Приветствие -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h1 class="h3 mb-1 text-white fw-bold">Добро пожаловать, <?= htmlspecialchars($user['fio']) ?>!</h1>
                        <p class="mb-0 text-white opacity-75">Управляйте своими регистрациями и отслеживайте результаты в личном кабинете</p>
                    </div>
                    <div class="col-md-4 text-end">
                        <i class="bi bi-person-circle display-1 text-white opacity-75"></i>
                    </div>
                </div>
            </div>
        </div>
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
                            Всего регистраций
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $totalRegistrations ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-card-list text-primary" style="font-size: 2em;"></i>
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
                            Участие в мероприятиях
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $totalEvents ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-calendar-event text-success" style="font-size: 2em;"></i>
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
                            Призовые места
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $prizePlaces ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-trophy text-warning" style="font-size: 2em;"></i>
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
                            Активные регистрации
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $activeRegistrations ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-clock text-info" style="font-size: 2em;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Ближайшие мероприятия -->
    <div class="col-lg-6 mb-4">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h6 class="m-0 font-weight-bold">
                    <i class="bi bi-calendar-event me-2"></i>Ближайшие мероприятия
                </h6>
            </div>
            <div class="card-body">
                <?php if (empty($upcomingEvents)): ?>
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-calendar-x display-5"></i>
                        <p class="mt-2">Нет запланированных мероприятий</p>
                        <a href="calendar.php" class="btn btn-primary btn-sm">Посмотреть календарь</a>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($upcomingEvents as $event): ?>
                            <div class="list-group-item">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><?= htmlspecialchars($event['meroname']) ?></h6>
                                    <small class="text-muted"><?= htmlspecialchars($event['merodata']) ?></small>
                                </div>
                                <p class="mb-1">
                                    <span class="badge bg-<?= getStatusColor($event['status']) ?>">
                                        <?= htmlspecialchars($event['status']) ?>
                                    </span>
                                    <span class="badge bg-<?= getRegStatusColor($event['reg_status']) ?> ms-1">
                                        <?= htmlspecialchars($event['reg_status']) ?>
                                    </span>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="card-footer bg-transparent">
                        <a href="calendar.php" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-calendar3"></i> Все мероприятия
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Последние результаты -->
    <div class="col-lg-6 mb-4">
        <div class="card shadow">
            <div class="card-header bg-success text-white">
                <h6 class="m-0 font-weight-bold">
                    <i class="bi bi-trophy me-2"></i>Последние результаты
                </h6>
            </div>
            <div class="card-body">
                <?php if (empty($recentResults)): ?>
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-award display-5"></i>
                        <p class="mt-2">Результатов пока нет</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($recentResults as $result): ?>
                            <div class="list-group-item">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><?= htmlspecialchars($result['meroname']) ?></h6>
                                    <span class="badge bg-<?= getPlaceColor($result['place']) ?>">
                                        <?= htmlspecialchars($result['place']) ?> место
                                    </span>
                                </div>
                                <p class="mb-1">
                                    <small class="text-muted">
                                        <?= htmlspecialchars($result['race_type']) ?>
                                        <?php if ($result['time']): ?>
                                            - Время: <?= htmlspecialchars($result['time']) ?>
                                        <?php endif; ?>
                                    </small>
                                </p>
                                <small class="text-muted"><?= htmlspecialchars($result['data']) ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Доступные мероприятия для регистрации -->
<?php if (!empty($availableEvents)): ?>
<div class="row">
    <div class="col-12">
        <div class="card shadow">
            <div class="card-header bg-info text-white">
                <h6 class="m-0 font-weight-bold">
                    <i class="bi bi-plus-circle me-2"></i>Доступные мероприятия для регистрации
                </h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($availableEvents as $event): ?>
                        <div class="col-md-4 mb-3">
                            <div class="card border-info">
                                <div class="card-body">
                                    <h6 class="card-title"><?= htmlspecialchars($event['meroname']) ?></h6>
                                    <p class="card-text">
                                        <small class="text-muted">
                                            <i class="bi bi-calendar3"></i> <?= htmlspecialchars($event['merodata']) ?>
                                        </small>
                                    </p>
                                    <p class="card-text">
                                        <span class="badge bg-success"><?= htmlspecialchars($event['status']) ?></span>
                                        <?php if ($event['defcost']): ?>
                                            <span class="badge bg-secondary ms-1"><?= htmlspecialchars($event['defcost']) ?> ₽</span>
                                        <?php endif; ?>
                                    </p>
                                    <a href="calendar.php?register=<?= $event['champn'] ?>" class="btn btn-primary btn-sm">
                                        <i class="bi bi-person-plus"></i> Зарегистрироваться
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
function getStatusColor($status) {
    switch($status) {
        case 'В ожидании': return 'secondary';
        case 'Регистрация': return 'success';
        case 'Регистрация закрыта': return 'warning';
        case 'В процессе': return 'info';
        case 'Результаты': return 'primary';
        case 'Завершено': return 'dark';
        default: return 'light';
    }
}

function getRegStatusColor($status) {
    switch($status) {
        case 'В очереди': return 'secondary';
        case 'Зарегистрирован': return 'primary';
        case 'Подтверждён': return 'success';
        case 'Ожидание команды': return 'warning';
        case 'Дисквалифицирован': return 'danger';
        case 'Неявка': return 'dark';
        default: return 'light';
    }
}

function getPlaceColor($place) {
    switch($place) {
        case '1': return 'warning';
        case '2': return 'secondary';
        case '3': return 'danger';
        default: return 'info';
    }
}

include '../includes/footer.php';
?> 
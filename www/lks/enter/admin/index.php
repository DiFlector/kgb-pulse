<?php
/**
 * Главная страница администратора
 */

// Проверка авторизации
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Admin' && $_SESSION['user_role'] !== 'SuperUser')) {
    header('Location: /lks/login.php');
    exit;
}

require_once '../../php/db/Database.php';

// Получаем статистику для дашборда
try {
    $db = Database::getInstance();
    
    // Статистика пользователей
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN accessrights = 'Sportsman' THEN 1 END) as sportsmen,
            COUNT(CASE WHEN accessrights = 'Admin' THEN 1 END) as admins,
            COUNT(CASE WHEN accessrights = 'Organizer' THEN 1 END) as organizers,
            COUNT(CASE WHEN accessrights = 'Secretary' THEN 1 END) as secretaries
        FROM users
    ");
    $usersStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Статистика мероприятий
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN status = 'Регистрация' THEN 1 END) as registration_open,
            COUNT(CASE WHEN status = 'Регистрация закрыта' THEN 1 END) as registration_closed,
            COUNT(CASE WHEN status = 'Завершено' THEN 1 END) as completed
        FROM meros
    ");
    $eventsStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Статистика регистраций
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN status = 'Зарегистрирован' THEN 1 END) as registered,
            COUNT(CASE WHEN status = 'Подтверждён' THEN 1 END) as confirmed,
            COUNT(CASE WHEN status = 'Ожидание команды' THEN 1 END) as waiting_team,
            COUNT(CASE WHEN status = 'В очереди' THEN 1 END) as in_queue,
            COUNT(CASE WHEN oplata = true THEN 1 END) as paid
        FROM listreg
    ");
    $registrationsStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Последние регистрации
    $stmt = $db->query("
        SELECT lr.*, u.fio, m.meroname 
        FROM listreg lr
        JOIN users u ON lr.users_oid = u.oid
        JOIN meros m ON lr.meros_oid = m.oid
        ORDER BY lr.oid DESC
        LIMIT 5
    ");
    $recentRegistrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Предстоящие мероприятия
    $stmt = $db->query("
        SELECT * FROM meros 
        WHERE status IN ('В ожидании', 'Регистрация', 'Регистрация закрыта')
        ORDER BY champn ASC
        LIMIT 5
    ");
    $upcomingEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Admin dashboard error: " . $e->getMessage());
    $usersStats = $eventsStats = $registrationsStats = [];
    $recentRegistrations = $upcomingEvents = [];
}

include '../includes/header.php';
?>

<style>
/* Стили для админ панели */
.stat-card {
    transition: transform 0.2s ease;
}

.stat-card:hover {
    transform: translateY(-2px);
}

.border-left-primary {
    border-left: 4px solid #4e73df !important;
}

.border-left-success {
    border-left: 4px solid #1cc88a !important;
}

.border-left-info {
    border-left: 4px solid #36b9cc !important;
}

.border-left-warning {
    border-left: 4px solid #f6c23e !important;
}

.text-primary { color: #4e73df !important; }
.text-success { color: #1cc88a !important; }
.text-info { color: #36b9cc !important; }
.text-warning { color: #f6c23e !important; }
</style>

<!-- Заголовок страницы -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Панель управления</h1>
    <div class="btn-group">
        <button class="btn btn-primary" onclick="location.reload()">
            <i class="bi bi-arrow-clockwise me-1"></i>Обновить
        </button>
    </div>
</div>

<!-- Статистические карточки -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card stat-card border-left-primary h-100 shadow">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                            Всего пользователей
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                            <?= number_format($usersStats['total'] ?? 0) ?>
                        </div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-people-fill text-primary" style="font-size: 2em;"></i>
                    </div>
                </div>
                <div class="mt-2">
                    <small class="text-muted">
                        Спортсменов: <?= $usersStats['sportsmen'] ?? 0 ?> |
                        Организаторов: <?= $usersStats['organizers'] ?? 0 ?>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card stat-card border-left-success h-100 shadow">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                            Мероприятий
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                            <?= number_format($eventsStats['total'] ?? 0) ?>
                        </div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-calendar-event-fill text-success" style="font-size: 2em;"></i>
                    </div>
                </div>
                <div class="mt-2">
                    <small class="text-muted">
                        Активных: <?= $eventsStats['registration_open'] ?? 0 ?> |
                        Завершено: <?= $eventsStats['completed'] ?? 0 ?>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card stat-card border-left-info h-100 shadow">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                            Регистраций
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                            <?= number_format($registrationsStats['total'] ?? 0) ?>
                        </div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-clipboard-check-fill text-info" style="font-size: 2em;"></i>
                    </div>
                </div>
                <div class="mt-2">
                    <small class="text-muted">
                        В ожидании: <?= ($registrationsStats['waiting_team'] ?? 0) + ($registrationsStats['in_queue'] ?? 0) ?> |
                        Оплачено: <?= $registrationsStats['paid'] ?? 0 ?>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card stat-card border-left-warning h-100 shadow">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                            Система
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                            Работает
                        </div>
                    </div>
                    <div class="col-auto">
                        <i class="bi bi-gear-fill text-warning" style="font-size: 2em;"></i>
                    </div>
                </div>
                <div class="mt-2">
                    <small class="text-muted">
                        БД: <span class="text-success">●</span> |
                        Redis: <span class="text-success">●</span>
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Быстрые действия -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h6 class="m-0 font-weight-bold">Быстрые действия</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <a href="users.php" class="btn btn-outline-primary btn-block">
                            <i class="bi bi-people me-2"></i>Управление пользователями
                        </a>
                    </div>
                    <div class="col-md-3 mb-3">
                        <a href="events.php" class="btn btn-outline-success btn-block">
                            <i class="bi bi-calendar-event me-2"></i>Мероприятия
                        </a>
                    </div>
                    <div class="col-md-3 mb-3">
                        <a href="data.php" class="btn btn-outline-info btn-block">
                            <i class="bi bi-database me-2"></i>Работа с данными
                        </a>
                    </div>
                    <div class="col-md-3 mb-3">
                        <a href="files.php" class="btn btn-outline-warning btn-block">
                            <i class="bi bi-file-earmark me-2"></i>Файлы
                        </a>
                    </div>
                    <div class="col-md-3 mb-3">
                        <a href="boats.php" class="btn btn-outline-primary btn-block">
                            <i class="fa-solid fa-ship me-2"></i>Классы лодок
                        </a>
                    </div>
                    <div class="col-md-3 mb-3">
                        <a href="statistics.php" class="btn btn-outline-info btn-block">
                            <i class="bi bi-bar-chart me-2"></i>Статистика
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Таблицы с данными -->
<div class="row">
    <!-- Последние регистрации -->
    <div class="col-lg-6 mb-4">
        <div class="card shadow h-100">
            <div class="card-header bg-info text-white">
                <h6 class="m-0 font-weight-bold">Последние регистрации</h6>
            </div>
            <div class="card-body">
                <?php if (empty($recentRegistrations)): ?>
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-inbox display-4"></i>
                        <p class="mt-2">Нет регистраций</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Участник</th>
                                    <th>Мероприятие</th>
                                    <th>Статус</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentRegistrations as $reg): ?>
                                <tr>
                                    <td>
                                        <small><?= htmlspecialchars($reg['fio']) ?></small>
                                    </td>
                                    <td>
                                        <small><?= htmlspecialchars($reg['meroname']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= getStatusBadgeClass($reg['status']) ?>">
                                            <?= htmlspecialchars($reg['status']) ?>
                                        </span>
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

    <!-- Предстоящие мероприятия -->
    <div class="col-lg-6 mb-4">
        <div class="card shadow h-100">
            <div class="card-header bg-success text-white">
                <h6 class="m-0 font-weight-bold">Предстоящие мероприятия</h6>
            </div>
            <div class="card-body">
                <?php if (empty($upcomingEvents)): ?>
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-calendar-x display-4"></i>
                        <p class="mt-2">Нет предстоящих мероприятий</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Название</th>
                                    <th>Статус</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($upcomingEvents as $event): ?>
                                <tr>
                                    <td>
                                        <small><?= htmlspecialchars($event['meroname']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= getEventStatusBadgeClass($event['status']) ?>">
                                            <?= htmlspecialchars($event['status']) ?>
                                        </span>
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

<?php

// Функции для стилизации бейджей
function getStatusBadgeClass($status) {
    switch ($status) {
        case 'Зарегистрирован': return 'primary';
        case 'Подтверждён': return 'success';
        case 'В ожидании': return 'warning';
        case 'Дисквалифицирован': return 'danger';
        case 'Неявка': return 'secondary';
        default: return 'secondary';
    }
}

function getEventStatusBadgeClass($status) {
    switch ($status) {
        case 'Регистрация': return 'success';
        case 'Регистрация закрыта': return 'warning';
        case 'В ожидании': return 'info';
        case 'Завершено': return 'secondary';
        default: return 'secondary';
    }
}

include '../includes/footer.php';
?> 
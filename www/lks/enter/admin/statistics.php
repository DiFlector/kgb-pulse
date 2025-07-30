<?php
// Статистика системы - Администратор
session_start();

// Проверка авторизации
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: /lks/login.php');
    exit;
}

// Проверка прав администратора
if (!isset($_SESSION['user_role']) || ($_SESSION['user_role'] !== 'Admin' && $_SESSION['user_role'] !== 'SuperUser')) {
    header('Location: /lks/login.php');
    exit;
}

require_once __DIR__ . '/../../php/db/Database.php';
require_once __DIR__ . '/../../php/common/Auth.php';

$auth = new Auth();
$currentUser = $auth->getCurrentUser();

if (!$currentUser) {
    header('Location: /lks/login.php');
    exit;
}

$db = Database::getInstance();

// Получение общей статистики
$totalUsers = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalEvents = $db->query("SELECT COUNT(*) FROM meros")->fetchColumn();
$totalRegistrations = $db->query("SELECT COUNT(*) FROM listreg")->fetchColumn();
$totalTeams = $db->query("SELECT COUNT(*) FROM teams")->fetchColumn();

// Статистика по ролям
$adminCount = $db->query("SELECT COUNT(*) FROM users WHERE accessrights = 'Admin'")->fetchColumn();
$organizerCount = $db->query("SELECT COUNT(*) FROM users WHERE accessrights = 'Organizer'")->fetchColumn();
$secretaryCount = $db->query("SELECT COUNT(*) FROM users WHERE accessrights = 'Secretary'")->fetchColumn();
$sportsmanCount = $db->query("SELECT COUNT(*) FROM users WHERE accessrights = 'Sportsman'")->fetchColumn();

// Статистика по мероприятиям
$eventsByStatus = $db->query("SELECT status, COUNT(*) as count FROM meros GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);

// Статистика по регистрациям
$regsByStatus = $db->query("SELECT status, COUNT(*) as count FROM listreg GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);

// Статистика за последние 30 дней
$recentRegs = $db->query("SELECT COUNT(*) FROM listreg WHERE oid IN (SELECT oid FROM listreg ORDER BY oid DESC LIMIT 1000)")->fetchColumn();

$pageTitle = 'Статистика системы';
$additionalCSS = [];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - KGB Pulse</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="/lks/css/style.css" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>
    
    <!-- Глобальные переменные для JavaScript -->
    <script>
        window.userRole = <?= json_encode($_SESSION['user_role']) ?>;
        window.userId = <?= json_encode($_SESSION['user_id']) ?>;
    </script>
</head>
<body class="authenticated-page" data-user-role="<?= htmlspecialchars($_SESSION['user_role']) ?>">

<?php include '../includes/header.php'; ?>

<main id="mainContent" class="main-content">
    <div class="container-fluid px-4">
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
            <h1 class="h2"><?= $pageTitle ?></h1>
            <div class="btn-toolbar mb-2 mb-md-0">
                <button class="btn btn-primary me-2" onclick="location.reload()">
                    <i class="bi bi-arrow-clockwise"></i> Обновить
                </button>
                <button class="btn btn-success" onclick="exportReport()">
                    <i class="bi bi-download"></i> Экспорт отчета
                </button>
            </div>
        </div>

        <!-- Общая статистика -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-white bg-primary">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="card-title"><?= $totalUsers ?></h4>
                                <p class="card-text">Всего пользователей</p>
                            </div>
                            <div class="align-self-center">
                                <i class="bi bi-people fs-1"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-success">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="card-title"><?= $totalEvents ?></h4>
                                <p class="card-text">Мероприятий</p>
                            </div>
                            <div class="align-self-center">
                                <i class="bi bi-calendar-event fs-1"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-warning">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="card-title"><?= $totalRegistrations ?></h4>
                                <p class="card-text">Регистраций</p>
                            </div>
                            <div class="align-self-center">
                                <i class="bi bi-clipboard-check fs-1"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-info">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h4 class="card-title"><?= $totalTeams ?></h4>
                                <p class="card-text">Команд</p>
                            </div>
                            <div class="align-self-center">
                                <i class="bi bi-gear fs-1"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Компактные графики -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-pie-chart"></i> Распределение пользователей по ролям</h6>
                    </div>
                    <div class="card-body p-3">
                        <div style="position: relative; height: 200px; width: 100%;">
                            <canvas id="rolesChart"></canvas>
                        </div>
                        <p id="rolesStatus" class="text-center text-muted mt-2">Ожидание инициализации</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-bar-chart"></i> Статусы мероприятий</h6>
                    </div>
                    <div class="card-body p-3">
                        <div style="position: relative; height: 200px; width: 100%;">
                            <canvas id="eventsChart"></canvas>
                        </div>
                        <p id="eventsStatus" class="text-center text-muted mt-2">Ожидание инициализации</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Детальная статистика -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-people"></i> Пользователи по ролям</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr>
                                        <th>Роль</th>
                                        <th>Количество</th>
                                        <th>Процент</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><i class="bi bi-shield-check text-danger"></i> Администраторы</td>
                                        <td><?= $adminCount ?></td>
                                        <td><?= $totalUsers > 0 ? round(($adminCount / $totalUsers) * 100, 1) : 0 ?>%</td>
                                    </tr>
                                    <tr>
                                        <td><i class="bi bi-calendar3 text-success"></i> Организаторы</td>
                                        <td><?= $organizerCount ?></td>
                                        <td><?= $totalUsers > 0 ? round(($organizerCount / $totalUsers) * 100, 1) : 0 ?>%</td>
                                    </tr>
                                    <tr>
                                        <td><i class="bi bi-clipboard text-info"></i> Секретари</td>
                                        <td><?= $secretaryCount ?></td>
                                        <td><?= $totalUsers > 0 ? round(($secretaryCount / $totalUsers) * 100, 1) : 0 ?>%</td>
                                    </tr>
                                    <tr>
                                        <td><i class="bi bi-person-running text-warning"></i> Спортсмены</td>
                                        <td><?= $sportsmanCount ?></td>
                                        <td><?= $totalUsers > 0 ? round(($sportsmanCount / $totalUsers) * 100, 1) : 0 ?>%</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-clipboard-check"></i> Регистрации по статусам</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr>
                                        <th>Статус</th>
                                        <th>Количество</th>
                                        <th>Процент</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($regsByStatus as $status): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($status['status']) ?></td>
                                        <td><?= $status['count'] ?></td>
                                        <td><?= $totalRegistrations > 0 ? round(($status['count'] / $totalRegistrations) * 100, 1) : 0 ?>%</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Системная информация -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-server"></i> Система</h6>
                    </div>
                    <div class="card-body">
                        <p class="mb-1"><strong>PHP версия:</strong> <?= PHP_VERSION ?></p>
                        <p class="mb-1"><strong>Память:</strong> <?= ini_get('memory_limit') ?></p>
                        <p class="mb-1"><strong>Загрузка файлов:</strong> <?= ini_get('upload_max_filesize') ?></p>
                        <p class="mb-0"><strong>Время выполнения:</strong> <?= ini_get('max_execution_time') ?>с</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-database"></i> База данных</h6>
                    </div>
                    <div class="card-body">
                        <?php
                        $dbVersion = $db->query("SELECT version()")->fetchColumn();
                        $dbSize = $db->query("SELECT pg_size_pretty(pg_database_size(current_database()))")->fetchColumn();
                        ?>
                        <p class="mb-1"><strong>PostgreSQL:</strong> <?= substr($dbVersion, 0, 30) ?>...</p>
                        <p class="mb-1"><strong>Размер БД:</strong> <?= $dbSize ?></p>
                        <p class="mb-0"><strong>Подключений:</strong> Активно</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-hdd"></i> Дисковое пространство</h6>
                    </div>
                    <div class="card-body">
                        <?php
                        $totalSpace = disk_total_space(__DIR__);
                        $freeSpace = disk_free_space(__DIR__);
                        $usedSpace = $totalSpace - $freeSpace;
                        $usedPercent = round(($usedSpace / $totalSpace) * 100, 1);
                        ?>
                        <p class="mb-1"><strong>Всего:</strong> <?= formatBytes($totalSpace) ?></p>
                        <p class="mb-1"><strong>Используется:</strong> <?= formatBytes($usedSpace) ?></p>
                        <p class="mb-2"><strong>Свободно:</strong> <?= formatBytes($freeSpace) ?></p>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-<?= $usedPercent > 80 ? 'danger' : ($usedPercent > 60 ? 'warning' : 'success') ?>" 
                                 role="progressbar" style="width: <?= $usedPercent ?>%">
                            </div>
                        </div>
                        <small class="text-muted"><?= $usedPercent ?>% использовано</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include '../includes/footer.php'; ?>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Ждем загрузки DOM и Chart.js
document.addEventListener('DOMContentLoaded', function() {
    // Проверяем, что Chart.js загружен
    if (typeof Chart === 'undefined') {
        console.error('❌ Chart.js не загружен!');
        document.getElementById('rolesStatus').textContent = 'Ошибка: Chart.js не загружен';
        document.getElementById('eventsStatus').textContent = 'Ошибка: Chart.js не загружен';
        return;
    }

    // График распределения пользователей по ролям
    try {
        const rolesCtx = document.getElementById('rolesChart');
        if (!rolesCtx) {
            console.error('❌ Canvas rolesChart не найден!');
            document.getElementById('rolesStatus').textContent = 'Ошибка: Canvas не найден';
            return;
        }
        

        const rolesChart = new Chart(rolesCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Администраторы', 'Организаторы', 'Секретари', 'Спортсмены'],
                datasets: [{
                    data: [<?= $adminCount ?>, <?= $organizerCount ?>, <?= $secretaryCount ?>, <?= $sportsmanCount ?>],
                    backgroundColor: [
                        '#dc3545',
                        '#28a745', 
                        '#17a2b8',
                        '#ffc107'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            font: {
                                size: 11
                            }
                        }
                    }
                }
            }
        });
        

        document.getElementById('rolesStatus').textContent = ' ';
        
    } catch (error) {
        console.error('❌ Ошибка создания графика ролей:', error);
        document.getElementById('rolesStatus').textContent = 'Ошибка: ' + error.message;
    }

    // График статусов мероприятий
    try {
        const eventsCtx = document.getElementById('eventsChart');
        if (!eventsCtx) {
            console.error('❌ Canvas eventsChart не найден!');
            document.getElementById('eventsStatus').textContent = 'Ошибка: Canvas не найден';
            return;
        }
        

        const eventsChart = new Chart(eventsCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: [<?= "'" . implode("', '", array_column($eventsByStatus, 'status')) . "'" ?>],
                datasets: [{
                    label: 'Количество мероприятий',
                    data: [<?= implode(', ', array_column($eventsByStatus, 'count')) ?>],
                    backgroundColor: [
                        '#007bff',
                        '#28a745',
                        '#ffc107',
                        '#17a2b8',
                        '#6f42c1',
                        '#fd7e14'
                    ],
                    borderWidth: 1,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            font: {
                                size: 10
                            }
                        }
                    },
                    x: {
                        ticks: {
                            font: {
                                size: 10
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
        

        document.getElementById('eventsStatus').textContent = ' ';
        
    } catch (error) {
        console.error('❌ Ошибка создания графика мероприятий:', error);
        document.getElementById('eventsStatus').textContent = 'Ошибка: ' + error.message;
    }
    
    
});

function exportReport() {
    const format = prompt('Выберите формат экспорта:\n1 - Excel (CSV)\n2 - PDF (HTML)\n3 - JSON', '1');
    
    let formatType = 'excel';
    switch(format) {
        case '2':
            formatType = 'pdf';
            break;
        case '3':
            formatType = 'json';
            break;
        default:
            formatType = 'excel';
    }
    
    window.open('/lks/php/admin/export_statistics.php?format=' + formatType + '&type=full', '_blank');
}
</script>

</body>
</html>

<?php
function formatBytes($size, $precision = 2) {
    $base = log($size, 1024);
    $suffixes = array('', 'K', 'M', 'G', 'T');   
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)] . 'B';
}
?> 
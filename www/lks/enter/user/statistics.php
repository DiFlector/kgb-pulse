<?php
/**
 * Страница статистики спортсмена
 * Показывает результаты участия в соревнованиях
 */

session_start();
require_once '../../php/db/Database.php';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header('Location: /lks/login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? '';

// Проверка роли - только спортсмены и суперпользователи могут видеть статистику
if (!in_array($userRole, ['Sportsman', 'SuperUser'])) {
    header('Location: /lks/enter/' . strtolower($userRole) . '/');
    exit;
}

try {
    $db = Database::getInstance()->getPDO();
    
    // Получаем информацию о пользователе
    $userQuery = "SELECT fio, city, sportzvanie FROM users WHERE userid = :userid";
    $userStmt = $db->prepare($userQuery);
    $userStmt->bindParam(':userid', $userId, PDO::PARAM_INT);
    $userStmt->execute();
    $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    // Получаем статистику пользователя
    $statsQuery = "
        SELECT 
            meroname,
            place,
            time,
            team,
            data,
            race_type
        FROM user_statistic 
        WHERE users_oid = :userid 
        ORDER BY data DESC, meroname ASC
    ";
    $statsStmt = $db->prepare($statsQuery);
    $statsStmt->bindParam(':userid', $userId, PDO::PARAM_INT);
    $statsStmt->execute();
    $statistics = $statsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Считаем общую статистику
    $totalRaces = count($statistics);
    $medals = ['gold' => 0, 'silver' => 0, 'bronze' => 0];
    $topThree = 0;
    
    foreach ($statistics as $stat) {
        $place = intval($stat['place']);
        if ($place <= 3 && $place > 0) {
            $topThree++;
            if ($place == 1) $medals['gold']++;
            elseif ($place == 2) $medals['silver']++;
            elseif ($place == 3) $medals['bronze']++;
        }
    }
    
    // Группируем статистику по годам
    $statsByYear = [];
    foreach ($statistics as $stat) {
        $year = date('Y', strtotime($stat['data']));
        if (!isset($statsByYear[$year])) {
            $statsByYear[$year] = [];
        }
        $statsByYear[$year][] = $stat;
    }
    
} catch (Exception $e) {
    error_log("Ошибка в statistics.php: " . $e->getMessage());
    $statistics = [];
    $statsByYear = [];
    $totalRaces = 0;
    $medals = ['gold' => 0, 'silver' => 0, 'bronze' => 0];
    $topThree = 0;
}

include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <!-- Заголовок страницы -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-1">
                        <i class="bi bi-trophy me-2 text-warning"></i>Статистика выступлений
                    </h2>
                    <p class="text-muted mb-0">
                        <?= htmlspecialchars($userInfo['fio'] ?? 'Спортсмен') ?>
                        <?php if (!empty($userInfo['city'])): ?>
                            • <?= htmlspecialchars($userInfo['city']) ?>
                        <?php endif; ?>
                        <?php if (!empty($userInfo['sportzvanie'])): ?>
                            • <?= htmlspecialchars($userInfo['sportzvanie']) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <a href="/lks/enter/user/" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-2"></i>Назад
                </a>
            </div>

            <!-- Общая статистика -->
            <div class="row mb-4">
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card h-100 border-primary">
                        <div class="card-body text-center">
                            <div class="display-6 text-primary mb-2">
                                <i class="bi bi-flag"></i>
                            </div>
                            <h3 class="mb-1"><?= $totalRaces ?></h3>
                            <p class="card-text text-muted mb-0">Всего стартов</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card h-100 border-warning">
                        <div class="card-body text-center">
                            <div class="display-6 text-warning mb-2">
                                <i class="bi bi-award"></i>
                            </div>
                            <h3 class="mb-1"><?= $medals['gold'] ?></h3>
                            <p class="card-text text-muted mb-0">Золотых медалей</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card h-100 border-secondary">
                        <div class="card-body text-center">
                            <div class="display-6 text-secondary mb-2">
                                <i class="bi bi-award"></i>
                            </div>
                            <h3 class="mb-1"><?= $medals['silver'] ?></h3>
                            <p class="card-text text-muted mb-0">Серебряных медалей</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card h-100 border-danger">
                        <div class="card-body text-center">
                            <div class="display-6 text-danger mb-2">
                                <i class="bi bi-award"></i>
                            </div>
                            <h3 class="mb-1"><?= $medals['bronze'] ?></h3>
                            <p class="card-text text-muted mb-0">Бронзовых медалей</p>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (empty($statistics)): ?>
                <!-- Нет данных -->
                <div class="card">
                    <div class="card-body text-center py-5">
                        <div class="display-1 text-muted mb-3">
                            <i class="bi bi-clipboard-data"></i>
                        </div>
                        <h4 class="text-muted">Пока нет результатов</h4>
                        <p class="text-muted mb-4">
                            Ваши результаты соревнований будут отображаться здесь после участия в мероприятиях.
                        </p>
                        <a href="/lks/enter/user/calendar.php" class="btn btn-primary">
                            <i class="bi bi-calendar-event me-2"></i>Посмотреть мероприятия
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <!-- Статистика по годам -->
                <?php foreach ($statsByYear as $year => $yearStats): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-calendar3 me-2"></i><?= $year ?> год
                                <span class="badge bg-primary ms-2"><?= count($yearStats) ?> стартов</span>
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Дата</th>
                                            <th>Соревнование</th>
                                            <th>Дисциплина</th>
                                            <th>Команда</th>
                                            <th>Место</th>
                                            <th>Время</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($yearStats as $stat): ?>
                                            <tr>
                                                <td>
                                                    <span class="text-nowrap">
                                                        <?= date('d.m.Y', strtotime($stat['data'])) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <strong><?= htmlspecialchars($stat['meroname']) ?></strong>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info">
                                                        <?= htmlspecialchars($stat['race_type'] ?? 'Не указано') ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($stat['team'] ?? 'Индивидуально') ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $place = intval($stat['place']);
                                                    $placeClass = '';
                                                    $placeIcon = '';
                                                    
                                                    if ($place == 1) {
                                                        $placeClass = 'text-warning fw-bold';
                                                        $placeIcon = '<i class="bi bi-trophy-fill me-1"></i>';
                                                    } elseif ($place == 2) {
                                                        $placeClass = 'text-secondary fw-bold';
                                                        $placeIcon = '<i class="bi bi-award-fill me-1"></i>';
                                                    } elseif ($place == 3) {
                                                        $placeClass = 'text-danger fw-bold';
                                                        $placeIcon = '<i class="bi bi-award-fill me-1"></i>';
                                                    } elseif ($place <= 10 && $place > 0) {
                                                        $placeClass = 'text-success';
                                                    }
                                                    ?>
                                                    <span class="<?= $placeClass ?>">
                                                        <?= $placeIcon ?><?= htmlspecialchars($stat['place']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($stat['time'])): ?>
                                                        <code><?= htmlspecialchars($stat['time']) ?></code>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border: 1px solid rgba(0, 0, 0, 0.125);
}

.card:hover {
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

.table th {
    border-top: none;
    font-weight: 600;
    color: #495057;
}

.badge {
    font-size: 0.75em;
}

@media (max-width: 768px) {
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .display-6 {
        font-size: 2rem;
    }
}
</style>

<?php include '../includes/footer.php'; ?> 
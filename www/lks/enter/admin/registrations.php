<?php
// Управление регистрациями - Администратор
session_start();

// Проверка авторизации - доступ для админов, организаторов и секретарей
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Admin', 'SuperUser', 'Organizer', 'Secretary'])) {
    header('Location: /lks/login.php');
    exit;
}

require_once __DIR__ . '/../../php/db/Database.php';
require_once __DIR__ . '/../../php/helpers.php';

$db = Database::getInstance();

// Получение параметров фильтрации
$eventFilter = $_GET['event'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$paymentFilter = $_GET['payment'] ?? '';

// Получение регистраций с данными пользователей и мероприятий
    $query = "
    SELECT 
        l.oid,
        l.users_oid,
        l.meros_oid,
        l.teams_oid,
        l.status,
        l.role,
        l.oplata,
        l.cost,
        l.discipline,
        u.fio,
        u.email,
        u.telephone,
        u.userid as user_number,
        m.meroname,
        m.merodata,
        m.class_distance,
        m.champn,
        t.teamid,
        t.teamname,
        t.teamcity
    FROM listreg l
    LEFT JOIN users u ON l.users_oid = u.oid
    LEFT JOIN meros m ON l.meros_oid = m.oid
    LEFT JOIN teams t ON l.teams_oid = t.oid
    WHERE 1=1
";

$params = [];
if ($eventFilter) {
    $query .= " AND l.meros_oid = ?";
    $params[] = $eventFilter;
}
if ($statusFilter) {
    $query .= " AND l.status = ?";
    $params[] = $statusFilter;
}
if ($paymentFilter !== '') {
    $query .= " AND l.oplata = ?";
    $params[] = (int)$paymentFilter;
}

$query .= " ORDER BY l.meros_oid DESC, l.teams_oid, l.oid DESC LIMIT 100";

$stmt = $db->prepare($query);
$stmt->execute($params);
$registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получение полной статистики (не только отфильтрованных записей)
$statsQuery = "
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN status = 'Подтверждён' THEN 1 END) as confirmed,
        COUNT(CASE WHEN status IN ('В очереди', 'Ожидание команды') THEN 1 END) as waiting,
        COUNT(CASE WHEN oplata = true THEN 1 END) as paid
    FROM listreg
";
$statsStmt = $db->query($statsQuery);
$fullStats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Получение списка мероприятий для фильтра
$events = $db->query("SELECT oid, champn, meroname FROM meros ORDER BY oid DESC")->fetchAll(PDO::FETCH_ASSOC);

// Группируем регистрации по командам
$groupedRegistrations = [];
$individualRegistrations = [];

foreach ($registrations as $registration) {
    if (!empty($registration['teams_oid'])) {
        $teamKey = $registration['meros_oid'] . '_' . $registration['teams_oid'];
        if (!isset($groupedRegistrations[$teamKey])) {
            $groupedRegistrations[$teamKey] = [
                'teamid' => $registration['teamid'], // Используем настоящий teamid
                'teams_oid' => $registration['teams_oid'], // Сохраняем и внутренний ключ для API
                'teamname' => $registration['teamname'],
                'teamcity' => $registration['teamcity'],
                'champn' => $registration['champn'],
                'meroname' => $registration['meroname'],
                'merodata' => $registration['merodata'],
                'members' => [],
                'status' => $registration['status'],
                'total_cost' => 0,
                'paid_count' => 0,
                'member_count' => 0
            ];
        }
        $groupedRegistrations[$teamKey]['members'][] = $registration;
        $groupedRegistrations[$teamKey]['total_cost'] += (float)$registration['cost'];
        if ($registration['oplata']) {
            $groupedRegistrations[$teamKey]['paid_count']++;
        }
        $groupedRegistrations[$teamKey]['member_count']++;
        
        // Определяем общий статус команды
        if ($registration['status'] === 'Ожидание команды') {
            $groupedRegistrations[$teamKey]['status'] = 'Ожидание команды';
        } elseif ($registration['status'] === 'Подтверждён' && $groupedRegistrations[$teamKey]['status'] !== 'Ожидание команды') {
            $groupedRegistrations[$teamKey]['status'] = 'Подтверждён';
        }
    } else {
        $individualRegistrations[] = $registration;
    }
}

// Функция перевода ролей уже определена в helpers.php

include '../includes/header.php';
?>

<!-- Заголовок страницы -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Управление регистрациями</h1>
    <div class="btn-group">
        <button type="button" class="btn btn-info me-2" onclick="openImportModal()">
            <i class="bi bi-upload"></i> Импорт регистраций
        </button>
        <button type="button" class="btn btn-success me-2" onclick="exportRegistrations()">
            <i class="bi bi-download"></i> Экспорт
        </button>
        <button type="button" class="btn btn-warning me-2" onclick="mergeTeamsModal()" title="Объединить команды">
            <i class="bi bi-people"></i> Объединить команды
        </button>
    </div>
</div>

<!-- Фильтры -->
<div class="card mb-4 shadow">
    <div class="card-header bg-primary text-white">
        <h6 class="m-0 font-weight-bold">Фильтры</h6>
    </div>
    <div class="card-body">
        <form method="GET" class="row">
            <div class="col-md-3">
                <label for="eventSelect" class="form-label">Мероприятие</label>
                <select class="form-select" id="eventSelect" name="event">
                    <option value="">Все мероприятия</option>
                    <?php foreach ($events as $event): ?>
                    <option value="<?= $event['oid'] ?>" <?= $eventFilter == $event['oid'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($event['meroname']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="statusSelect" class="form-label">Статус</label>
                <select class="form-select" id="statusSelect" name="status">
                    <option value="">Все статусы</option>
                    <option value="В очереди" <?= $statusFilter === 'В очереди' ? 'selected' : '' ?>>В очереди</option>
                    <option value="Зарегистрирован" <?= $statusFilter === 'Зарегистрирован' ? 'selected' : '' ?>>Зарегистрирован</option>
                    <option value="Подтверждён" <?= $statusFilter === 'Подтверждён' ? 'selected' : '' ?>>Подтверждён</option>
                    <option value="Ожидание команды" <?= $statusFilter === 'Ожидание команды' ? 'selected' : '' ?>>Ожидание команды</option>
                    <option value="Дисквалифицирован" <?= $statusFilter === 'Дисквалифицирован' ? 'selected' : '' ?>>Дисквалифицирован</option>
                    <option value="Неявка" <?= $statusFilter === 'Неявка' ? 'selected' : '' ?>>Неявка</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="paymentSelect" class="form-label">Оплата</label>
                <select class="form-select" id="paymentSelect" name="payment">
                    <option value="">Все</option>
                    <option value="1" <?= $paymentFilter === '1' ? 'selected' : '' ?>>Оплачено</option>
                    <option value="0" <?= $paymentFilter === '0' ? 'selected' : '' ?>>Не оплачено</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">Применить</button>
            </div>
        </form>
    </div>
</div>

<!-- Статистика -->
<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-primary shadow">
            <div class="card-body">
                <h6 class="card-title">Всего регистраций</h6>
                <h3 class="mb-0"><?= $fullStats['total'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-success shadow">
            <div class="card-body">
                <h6 class="card-title">Подтверждённых</h6>
                <h3 class="mb-0"><?= $fullStats['confirmed'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-warning shadow">
            <div class="card-body">
                <h6 class="card-title">В ожидании</h6>
                <h3 class="mb-0"><?= $fullStats['waiting'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-info shadow">
            <div class="card-body">
                <h6 class="card-title">Оплачено</h6>
                <h3 class="mb-0"><?= $fullStats['paid'] ?></h3>
            </div>
        </div>
    </div>
</div>

<!-- Таблица регистраций -->
<div class="card shadow">
    <div class="card-header bg-info text-white">
        <h6 class="m-0 font-weight-bold">Список регистраций</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>
                            <input type="checkbox" id="selectAll" class="form-check-input">
                        </th>
                        <th>№ спортсмена</th>
                        <th>Участник</th>
                        <th>Мероприятие</th>
                        <th>Дистанции</th>
                        <th>Статус</th>
                        <th>Оплата</th>
                        <th>Стоимость</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($registrations)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">
                            <i class="bi bi-inbox display-4"></i>
                            <p class="mt-2">Нет регистраций по заданным критериям</p>
                        </td>
                    </tr>
                    <?php else: ?>
                        <!-- Отображение команд -->
                        <?php foreach ($groupedRegistrations as $teamKey => $team): ?>
                        <tr class="table-secondary">
                            <td>
                                <input type="checkbox" class="team-checkbox" value="<?= $team['teamid'] ?>" data-event="<?= $team['champn'] ?>">
                            </td>
                            <td colspan="8">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><i class="bi bi-people-fill me-2"></i><?= !empty($team['teamname']) ? htmlspecialchars($team['teamname']) : 'Команда #' . htmlspecialchars($team['teamid']) ?></strong>
                                        <span class="badge bg-info ms-2"><?= $team['member_count'] ?> участников</span>
                                        <?php
                                        // Подсчёт ролей для статуса "полная/не полная команда"
                                        $roleCounts = ['member' => 0, 'coxswain' => 0, 'drummer' => 0];
                                        foreach ($team['members'] as $reg) {
                                            if (isset($reg['role'])) {
                                                if ($reg['role'] === 'member') $roleCounts['member']++;
                                                if ($reg['role'] === 'coxswain') $roleCounts['coxswain']++;
                                                if ($reg['role'] === 'drummer') $roleCounts['drummer']++;
                                            }
                                        }
                                        $isFull = $roleCounts['member'] >= 1 && $roleCounts['coxswain'] >= 0 && $roleCounts['drummer'] >= 0;
                                        ?>
                                        <span class="badge bg-<?= $isFull ? 'success' : 'danger' ?> ms-2">
                                            <?= $isFull ? 'Полная' : 'Не полная' ?>
                                        </span>
                                        <span class="badge bg-<?= $team['status'] === 'Подтверждён' ? 'success' : ($team['status'] === 'Ожидание команды' ? 'warning' : 'secondary') ?> ms-2">
                                            <?= htmlspecialchars($team['status']) ?>
                                        </span>
                                    </div>
                                    <div>
                                        <span class="text-muted me-3">Оплачено: <?= $team['paid_count'] ?>/<?= $team['member_count'] ?></span>
                                        <strong><?= number_format($team['total_cost'], 0, '', '') ?> ₽</strong>
                                        <button class="btn btn-sm btn-primary ms-2" onclick="showEditTeamModal(<?= $team['teamid'] ?>, '<?= $team['champn'] ?>')" title="Редактировать команду">
                                            <i class="bi bi-pencil-square"></i> Редактировать
                                        </button>
                                        <?php if ($team['status'] === 'Ожидание команды'): ?>
                                            <button class="btn btn-sm btn-success ms-2" onclick="confirmTeam(<?= $team['teamid'] ?>, '<?= $team['champn'] ?>')" title="Подтвердить команду">
                                                <i class="bi bi-check-circle"></i> Подтвердить команду
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php foreach ($team['members'] as $registration): ?>
                        <tr class="table-light" data-team-id="<?= $team['teamid'] ?>">
                            <td>
                                <input type="checkbox" class="form-check-input registration-checkbox" value="<?= $registration['oid'] ?>">
                            </td>
                            <td><small><?= htmlspecialchars($registration['user_number']) ?></small></td>
                            <td>
                                <div>
                                    <strong><?= htmlspecialchars($registration['fio']) ?></strong>
                                    <?php if (!empty($registration['role'])): ?>
                                        <span class="badge bg-info ms-2"><?= htmlspecialchars(translateRole($registration['role'])) ?></span>
                                    <?php endif; ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($registration['email']) ?></small><br>
                                    <small class="text-muted"><?= htmlspecialchars($registration['telephone']) ?></small>
                                </div>
                            </td>
                            <td>
                                <div>
                                    <strong><?= htmlspecialchars($registration['meroname']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($registration['merodata']) ?></small>
                                </div>
                            </td>
                            <td>
                                <?php 
                                // Отображаем выбранные участником дисциплины
                                $userDisciplines = json_decode($registration['discipline'], true);
                                if ($userDisciplines && is_array($userDisciplines)) {
                                    foreach ($userDisciplines as $class => $details) {
                                        echo "<div class='mb-1'>";
                                        echo "<small class='badge bg-primary me-1'>" . htmlspecialchars($class) . "</small>";
                                        
                                        if (is_array($details)) {
                                            $disciplinesInfo = [];
                                            
                                            // Обрабатываем структуру с sex и dist
                                            if (isset($details['sex']) && isset($details['dist'])) {
                                                $sexValues = is_array($details['sex']) ? $details['sex'] : [$details['sex']];
                                                $distValues = is_array($details['dist']) ? $details['dist'] : [$details['dist']];
                                                
                                                foreach ($distValues as $distance) {
                                                    // Убираем лишнее форматирование для дистанций
                                                    $cleanDistance = str_replace(['м', 'м.', ' '], '', $distance);
                                                    foreach ($sexValues as $sex) {
                                                        $sexLabel = $sex === 'M' ? 'М' : ($sex === 'W' ? 'Ж' : $sex);
                                                        $disciplinesInfo[] = $cleanDistance . "м " . $sexLabel;
                                                    }
                                                }
                                            }
                                            // Обрабатываем старые форматы
                                            else {
                                                foreach ($details as $key => $value) {
                                                    if (is_array($value)) {
                                                        foreach ($value as $item) {
                                                            $disciplinesInfo[] = $key . " " . $item;
                                                        }
                                                    } else {
                                                        $disciplinesInfo[] = $key . " " . $value;
                                                    }
                                                }
                                            }
                                            
                                            if (!empty($disciplinesInfo)) {
                                                echo "<br><small class='text-muted'>" . htmlspecialchars(implode(', ', $disciplinesInfo)) . "</small>";
                                            }
                                        }
                                        echo "</div>";
                                    }
                                } else {
                                    echo "<small class='text-muted'>Дисциплины не указаны</small>";
                                }
                                ?>
                            </td>
                            <td>
                                <select class="form-select form-select-sm status-select" data-registration-id="<?= $registration['oid'] ?>">
                                    <option value="В очереди" <?= $registration['status'] === 'В очереди' ? 'selected' : '' ?>>В очереди</option>
                                    <option value="Зарегистрирован" <?= $registration['status'] === 'Зарегистрирован' ? 'selected' : '' ?>>Зарегистрирован</option>
                                    <option value="Подтверждён" <?= $registration['status'] === 'Подтверждён' ? 'selected' : '' ?>>Подтверждён</option>
                                    <option value="Ожидание команды" <?= $registration['status'] === 'Ожидание команды' ? 'selected' : '' ?>>Ожидание команды</option>
                                    <option value="Дисквалифицирован" <?= $registration['status'] === 'Дисквалифицирован' ? 'selected' : '' ?>>Дисквалифицирован</option>
                                    <option value="Неявка" <?= $registration['status'] === 'Неявка' ? 'selected' : '' ?>>Неявка</option>
                                </select>
                            </td>
                            <td>
                                <div class="form-check form-switch">
                                    <input class="form-check-input payment-switch" type="checkbox" data-registration-id="<?= $registration['oid'] ?>" <?= $registration['oplata'] ? 'checked' : '' ?>>
                                </div>
                            </td>
                            <td><strong><?= number_format($registration['cost'], 0, '', '') ?> ₽</strong></td>
                            <td>
                                <div class="btn-group-vertical d-grid gap-1" role="group">
                                    <div class="btn-group" role="group">
                                        <button class="btn btn-sm btn-outline-primary" onclick="editRegistration('<?= $registration['oid'] ?>')" title="Редактировать">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" onclick="deleteRegistration('<?= $registration['oid'] ?>')" title="Удалить">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                        
                        <!-- Отображение индивидуальных участников -->
                        <?php if (!empty($individualRegistrations)): ?>
                        <tr class="table-info">
                            <td colspan="9">
                                <strong><i class="bi bi-person-fill me-2"></i>Индивидуальные участники</strong>
                            </td>
                        </tr>
                        <?php foreach ($individualRegistrations as $registration): ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="form-check-input registration-checkbox" value="<?= $registration['oid'] ?>">
                            </td>
                            <td><strong><?= htmlspecialchars($registration['user_number']) ?></strong></td>
                            <td>
                                <div>
                                    <strong><?= htmlspecialchars($registration['fio']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($registration['email']) ?></small><br>
                                    <small class="text-muted"><?= htmlspecialchars($registration['telephone']) ?></small>
                                </div>
                            </td>
                            <td>
                                <div>
                                    <strong><?= htmlspecialchars($registration['meroname']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($registration['merodata']) ?></small>
                                </div>
                            </td>
                            <td>
                                <?php 
                                // Отображаем выбранные участником дисциплины (индивидуальные участники)
                                $userDisciplines = json_decode($registration['discipline'], true);
                                if ($userDisciplines && is_array($userDisciplines)) {
                                    foreach ($userDisciplines as $class => $details) {
                                        echo "<div class='mb-1'>";
                                        echo "<strong class='badge bg-primary me-1'>" . htmlspecialchars($class) . "</strong>";
                                        
                                        if (is_array($details)) {
                                            $disciplinesInfo = [];
                                            
                                            // Обрабатываем структуру с sex и dist
                                            if (isset($details['sex']) && isset($details['dist'])) {
                                                $sexValues = is_array($details['sex']) ? $details['sex'] : [$details['sex']];
                                                $distValues = is_array($details['dist']) ? $details['dist'] : [$details['dist']];
                                                
                                                foreach ($distValues as $distance) {
                                                    // Убираем лишнее форматирование для дистанций
                                                    $cleanDistance = str_replace(['м', 'м.', ' '], '', $distance);
                                                    foreach ($sexValues as $sex) {
                                                        $sexLabel = $sex === 'M' ? 'М' : ($sex === 'W' ? 'Ж' : $sex);
                                                        $disciplinesInfo[] = $cleanDistance . "м " . $sexLabel;
                                                    }
                                                }
                                            }
                                            // Обрабатываем старые форматы
                                            else {
                                                foreach ($details as $key => $value) {
                                                    if (is_array($value)) {
                                                        foreach ($value as $item) {
                                                            $disciplinesInfo[] = $key . " " . $item;
                                                        }
                                                    } else {
                                                        $disciplinesInfo[] = $key . " " . $value;
                                                    }
                                                }
                                            }
                                            
                                            if (!empty($disciplinesInfo)) {
                                                echo "<br><small class='text-muted'>" . htmlspecialchars(implode(', ', $disciplinesInfo)) . "</small>";
                                            }
                                        }
                                        echo "</div>";
                                    }
                                } else {
                                    echo "<small class='text-muted'>Дисциплины не указаны</small>";
                                }
                                ?>
                            </td>
                            <td>
                                <select class="form-select form-select-sm status-select" data-registration-id="<?= $registration['oid'] ?>">
                                    <option value="В очереди" <?= $registration['status'] === 'В очереди' ? 'selected' : '' ?>>В очереди</option>
                                    <option value="Зарегистрирован" <?= $registration['status'] === 'Зарегистрирован' ? 'selected' : '' ?>>Зарегистрирован</option>
                                    <option value="Подтверждён" <?= $registration['status'] === 'Подтверждён' ? 'selected' : '' ?>>Подтверждён</option>
                                    <option value="Ожидание команды" <?= $registration['status'] === 'Ожидание команды' ? 'selected' : '' ?>>Ожидание команды</option>
                                    <option value="Дисквалифицирован" <?= $registration['status'] === 'Дисквалифицирован' ? 'selected' : '' ?>>Дисквалифицирован</option>
                                    <option value="Неявка" <?= $registration['status'] === 'Неявка' ? 'selected' : '' ?>>Неявка</option>
                                </select>
                            </td>
                            <td>
                                <div class="form-check form-switch">
                                    <input class="form-check-input payment-switch" type="checkbox" data-registration-id="<?= $registration['oid'] ?>" <?= $registration['oplata'] ? 'checked' : '' ?>>
                                </div>
                            </td>
                            <td><strong><?= number_format($registration['cost'], 0, '', '') ?> ₽</strong></td>
                            <td>
                                <div class="btn-group-vertical d-grid gap-1" role="group">
                                    <!-- Кнопки быстрого изменения статуса -->
                                    <?php if ($registration['status'] === 'В очереди'): ?>
                                        <button class="btn btn-sm btn-success" onclick="changeRegistrationStatus('<?= $registration['oid'] ?>', 'Подтверждён')" title="Подтвердить участие">
                                            <i class="bi bi-check-circle"></i> Подтвердить
                                        </button>
                                    <?php elseif ($registration['status'] === 'Подтверждён'): ?>
                                        <button class="btn btn-sm btn-primary" onclick="changeRegistrationStatus('<?= $registration['oid'] ?>', 'Зарегистрирован')" title="Зарегистрировать на месте">
                                            <i class="bi bi-person-check"></i> Зарегистрировать
                                        </button>
                                    <?php elseif ($registration['status'] === 'Зарегистрирован'): ?>
                                        <button class="btn btn-sm btn-warning" onclick="changeRegistrationStatus('<?= $registration['oid'] ?>', 'Неявка')" title="Отметить неявку">
                                            <i class="bi bi-x-circle"></i> Неявка
                                        </button>
                                    <?php endif; ?>
                                    
                                    <!-- Кнопка "Неявка" доступна для всех статусов, кроме "Дисквалифицирован", "Зарегистрирован" и "Неявка" -->
                                    <?php if (!in_array($registration['status'], ['Дисквалифицирован', 'Зарегистрирован', 'Неявка'])): ?>
                                        <button class="btn btn-sm btn-outline-danger" onclick="changeRegistrationStatus('<?= $registration['oid'] ?>', 'Неявка')" title="Отметить неявку">
                                            <i class="bi bi-person-x"></i> Неявка
                                        </button>
                                    <?php endif; ?>
                                    
                                    <!-- Обычные кнопки действий -->
                                    <div class="btn-group" role="group">
                                        <button class="btn btn-sm btn-outline-primary" onclick="editRegistration('<?= $registration['oid'] ?>')" title="Редактировать">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" onclick="deleteRegistration('<?= $registration['oid'] ?>')" title="Удалить">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Обработка изменения статуса регистрации
document.addEventListener('DOMContentLoaded', function() {
    const statusSelects = document.querySelectorAll('.status-select');
    const paymentSwitches = document.querySelectorAll('.payment-switch');
    
    statusSelects.forEach(select => {
        select.addEventListener('change', async function() {
            const registrationId = this.dataset.registrationId;
            const newStatus = this.value;
            const previousIndex = this.dataset.previousIndex || 0;
            
            // Блокируем селект на время запроса
            this.disabled = true;
            
            try {
                const response = await fetch('/lks/php/admin/update_registration_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        registrationId: parseInt(registrationId),
                        status: newStatus
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification(`Статус изменён на "${newStatus}"`, 'success');
                    // Обновляем статистику
                    updatePaymentStatistics();
                } else {
                    showNotification('Ошибка: ' + result.message, 'error');
                    this.selectedIndex = previousIndex;
                }
            } catch (error) {
                showNotification('Ошибка при изменении статуса: ' + error.message, 'error');
                this.selectedIndex = previousIndex;
            } finally {
                // Разблокируем селект
                this.disabled = false;
            }
        });
        
        select.addEventListener('focus', function() {
            this.dataset.previousIndex = this.selectedIndex;
        });
    });
    
    paymentSwitches.forEach(switchEl => {
        switchEl.addEventListener('change', async function() {
            const registrationId = this.dataset.registrationId;
            const isChecked = this.checked;
            
            // Блокируем переключатель на время запроса
            this.disabled = true;
            
            try {
                const response = await fetch('/lks/php/admin/update_payment_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        registrationId: parseInt(registrationId),
                        paid: isChecked
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Показываем уведомление об успехе
                    showNotification(
                        isChecked ? 'Оплата отмечена' : 'Оплата снята', 
                        'success'
                    );
                    
                    // Обновляем статистику команды если это член команды
                    updateTeamPaymentStatistics(registrationId, isChecked);
                    
                    // Обновляем общую статистику оплаты
                    updatePaymentStatistics();
                } else {
                    // Возвращаем переключатель в исходное состояние
                    this.checked = !isChecked;
                    showNotification('Ошибка: ' + result.message, 'error');
                }
            } catch (error) {
                // Возвращаем переключатель в исходное состояние  
                this.checked = !isChecked;
                showNotification('Ошибка при изменении статуса оплаты: ' + error.message, 'error');
            } finally {
                // Разблокируем переключатель
                this.disabled = false;
            }
        });
    });
    
    // Обработка выбора всех чекбоксов
    document.getElementById('selectAll').addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.registration-checkbox');
        checkboxes.forEach(cb => cb.checked = this.checked);
    });
});

function exportRegistrations() {
    const selectedIds = Array.from(document.querySelectorAll('.registration-checkbox:checked'))
        .map(cb => cb.value);
    
    if (selectedIds.length === 0) {
        alert('Выберите регистрации для экспорта');
        return;
    }
    
    // Формирование ссылки на экспорт
    const params = new URLSearchParams();
    params.append('ids', selectedIds.join(','));
    window.open('/lks/php/admin/export_registrations.php?' + params.toString(), '_blank');
}

function mergeTeamsModal() {
    const selectedTeams = Array.from(document.querySelectorAll('.team-checkbox:checked'));
    
    if (selectedTeams.length < 2) {
        alert('Выберите минимум 2 команды для объединения');
        return;
    }
    
    // Создаем модальное окно объединения команд
    const modalHtml = `
        <div class="modal fade" id="mergeTeamsModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-people"></i> Объединение команд
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-info">
                            <i class="bi bi-info-circle"></i> 
                            Выберите команды для объединения. Все участники будут перемещены в основную команду.
                        </p>
                        
                        <!-- Основная команда -->
                        <div class="mb-3">
                            <label class="form-label">Основная команда (останется):</label>
                            <select class="form-select" id="mainTeam">
                                <option value="">Выберите основную команду...</option>
                            </select>
                        </div>
                        
                        <!-- Команды для объединения -->
                        <div class="mb-3">
                            <label class="form-label">Команды для объединения (будут удалены):</label>
                            <div id="teamsToMerge" class="border rounded p-3 bg-light">
                                <em class="text-muted">Команды не выбраны</em>
                            </div>
                        </div>
                        
                        <!-- Новое название команды -->
                        <div class="mb-3">
                            <label for="newTeamName" class="form-label">Новое название команды (необязательно):</label>
                            <input type="text" class="form-control" id="newTeamName" placeholder="Оставьте пустым для сохранения текущего названия">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="button" class="btn btn-warning" onclick="executeMergeTeams()">
                            <i class="bi bi-people"></i> Объединить команды
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Удаляем существующее модальное окно если есть
    const existingModal = document.getElementById('mergeTeamsModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Добавляем модальное окно в DOM
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // Заполняем селект основной команды
    const mainTeamSelect = document.getElementById('mainTeam');
    selectedTeams.forEach(checkbox => {
        const teamId = checkbox.value;
        const eventId = checkbox.dataset.event;
        const row = checkbox.closest('tr').nextElementSibling;
        const teamInfo = row ? row.querySelector('strong').textContent : `Команда ${teamId}`;
        
        const option = document.createElement('option');
        option.value = `${teamId}_${eventId}`;
        option.textContent = `${teamInfo}`;
        mainTeamSelect.appendChild(option);
    });
    
    // Показываем выбранные команды
    const teamsToMergeDiv = document.getElementById('teamsToMerge');
    teamsToMergeDiv.innerHTML = selectedTeams.map(checkbox => {
        const teamId = checkbox.value;
        return `<div class="badge bg-primary me-2 mb-2">Команда ${teamId}</div>`;
    }).join('');
    
    // Показываем модальное окно
    const modal = new bootstrap.Modal(document.getElementById('mergeTeamsModal'));
    modal.show();
}

function executeMergeTeams() {
    const mainTeam = document.getElementById('mainTeam').value;
    const newTeamName = document.getElementById('newTeamName').value;
    const selectedTeams = Array.from(document.querySelectorAll('.team-checkbox:checked'));
    
    if (!mainTeam) {
        alert('Выберите основную команду');
        return;
    }
    
    if (selectedTeams.length < 2) {
        alert('Выберите минимум 2 команды для объединения');
        return;
    }
    
    const [mainTeamId, mainEventId] = mainTeam.split('_');
    const teamsToMerge = selectedTeams
        .map(cb => ({teamId: cb.value, eventId: cb.dataset.event}))
        .filter(team => team.teamId !== mainTeamId);
    
    if (teamsToMerge.length === 0) {
        alert('Нет команд для объединения');
        return;
    }
    
    // Отправляем запрос на сервер
    fetch('/lks/php/admin/merge_teams.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            mainTeamId: mainTeamId,
            eventId: mainEventId,
            teamsToMerge: teamsToMerge,
            newTeamName: newTeamName
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Команды успешно объединены!', 'success');
            // Закрываем модальное окно
            const modal = bootstrap.Modal.getInstance(document.getElementById('mergeTeamsModal'));
            modal.hide();
            // Перезагружаем страницу
            setTimeout(() => location.reload(), 1000);
        } else {
            // Отображаем детальные ошибки совместимости
            let errorMessage = data.message;
            if (data.errors && Array.isArray(data.errors)) {
                errorMessage += '\n\nДетали:\n' + data.errors.join('\n');
            }
            showNotification('Ошибка объединения: ' + errorMessage, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Ошибка при отправке запроса', 'error');
    });
}

// Быстрое изменение статуса регистрации
async function changeRegistrationStatus(registrationId, newStatus) {
    try {
        const response = await fetch('/lks/php/admin/update_registration_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `registration_id=${registrationId}&new_status=${newStatus}`
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification(`Статус изменён на "${newStatus}"`, 'success');
            
            // Обновляем статус в интерфейсе без перезагрузки
            const statusSelect = document.querySelector(`select[data-registration-id="${registrationId}"]`);
            if (statusSelect) {
                statusSelect.value = newStatus;
            }
            
            // Обновляем статистику
            updatePaymentStatistics();
        } else {
            showNotification('Ошибка: ' + (result.error || result.message), 'error');
        }
    } catch (error) {
        showNotification('Ошибка при изменении статуса: ' + error.message, 'error');
        console.error('Error:', error);
    }
}

// Подтверждение команды
async function confirmTeam(teamid, champn) {
    if (!confirm('Подтвердить всю команду? Все участники команды получат статус "Подтверждён".')) {
        return;
    }
    
    try {
        const response = await fetch('/lks/php/organizer/confirm_team.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `teamid=${teamid}&champn=${champn}`
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification(`Команда подтверждена! Обновлено участников: ${result.confirmed_count}`, 'success');
            
            // Обновляем статусы всех участников команды в интерфейсе
            const teamRows = document.querySelectorAll(`tr[data-team-id="${teamid}"]`);
            teamRows.forEach(row => {
                const statusSelect = row.querySelector('.status-select');
                if (statusSelect) {
                    statusSelect.value = 'Подтверждён';
                }
            });
            
            // Скрываем кнопку подтверждения команды
            const confirmButton = document.querySelector(`button[onclick*="confirmTeam('${teamid}'"]`);
            if (confirmButton) {
                confirmButton.style.display = 'none';
            }
            
            // Обновляем статистику
            updatePaymentStatistics();
        } else {
            showNotification('Ошибка: ' + (result.error || result.message), 'error');
        }
    } catch (error) {
        showNotification('Ошибка при подтверждении команды: ' + error.message, 'error');
        console.error('Error:', error);
    }
}

function editRegistration(registrationId) {
    // Открыть модальное окно редактирования регистрации
    fetch('/lks/php/admin/edit_registration.php?id=' + registrationId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showEditRegistrationModal(data.registration);
            } else {
                showNotification('Ошибка загрузки данных регистрации: ' + data.message, 'error');
            }
        })
        .catch(error => {
            showNotification('Ошибка загрузки данных регистрации', 'error');
            console.error('Error:', error);
        });
}

function deleteRegistration(registrationId) {
    if (confirm('Вы уверены, что хотите удалить эту регистрацию?\nЭто действие нельзя отменить!')) {
        fetch('/lks/php/admin/delete_registration.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                registrationId: registrationId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification(data.message, 'success');
                // Перезагружаем страницу через 2 секунды
                setTimeout(() => {
                    location.reload();
                }, 2000);
            } else {
                showNotification('Ошибка: ' + data.message, 'error');
            }
        })
        .catch(error => {
            showNotification('Ошибка удаления регистрации', 'error');
            console.error('Error:', error);
        });
    }
}

/**
 * Показать модальное окно редактирования регистрации
 */
function showEditRegistrationModal(registration) {
    // Создаем HTML для модального окна
    const modalHtml = `
        <div class="modal fade" id="editRegistrationModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Редактирование регистрации</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="editRegistrationForm">
                            <input type="hidden" id="editRegId" value="${registration.oid}">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Участник</label>
                                        <input type="text" class="form-control" value="${registration.fio || ''}" readonly>
                                        <small class="text-muted">${registration.email || ''}</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Мероприятие</label>
                                        <textarea class="form-control" rows="2" readonly>${registration.meroname || ''}</textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="editRegStatus" class="form-label">Статус</label>
                                        <select class="form-select" id="editRegStatus">
                                            <option value="В очереди" ${registration.status === 'В очереди' ? 'selected' : ''}>В очереди</option>
                                            <option value="Зарегистрирован" ${registration.status === 'Зарегистрирован' ? 'selected' : ''}>Зарегистрирован</option>
                                            <option value="Подтверждён" ${registration.status === 'Подтверждён' ? 'selected' : ''}>Подтверждён</option>
                                            <option value="Ожидание команды" ${registration.status === 'Ожидание команды' ? 'selected' : ''}>Ожидание команды</option>
                                            <option value="Дисквалифицирован" ${registration.status === 'Дисквалифицирован' ? 'selected' : ''}>Дисквалифицирован</option>
                                            <option value="Неявка" ${registration.status === 'Неявка' ? 'selected' : ''}>Неявка</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="editRegCost" class="form-label">Стоимость</label>
                                        <input type="number" class="form-control" id="editRegCost" value="${registration.cost || ''}" min="0">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Оплата</label>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="editRegPaid" ${registration.oplata ? 'checked' : ''}>
                                            <label class="form-check-label" for="editRegPaid">Оплачено</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отменить</button>
                        <button type="button" class="btn btn-primary" onclick="saveRegistrationChanges()">Сохранить</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Удаляем предыдущее модальное окно если есть
    const existingModal = document.getElementById('editRegistrationModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Добавляем новое модальное окно
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // Показываем модальное окно
    const modal = new bootstrap.Modal(document.getElementById('editRegistrationModal'));
    modal.show();
}

/**
 * Сохранение изменений регистрации
 */
function saveRegistrationChanges() {
    const regId = document.getElementById('editRegId').value;
    const status = document.getElementById('editRegStatus').value;
    const cost = document.getElementById('editRegCost').value;
    
    const paidElement = document.getElementById('editRegPaid');
    
    const paid = paidElement ? paidElement.checked : false;
    
    const requestData = {
        registrationId: parseInt(regId),
        status: status,
        cost: cost,
        oplata: paid
    };
    
    fetch('/lks/php/admin/edit_registration.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(requestData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            
            // Закрываем модальное окно
            const modal = bootstrap.Modal.getInstance(document.getElementById('editRegistrationModal'));
            modal.hide();
            
            // Обновляем данные в таблице без перезагрузки
            const regId = document.getElementById('editRegId').value;
            updateRegistrationInTable(regId, {
                status: document.getElementById('editRegStatus').value,
                cost: document.getElementById('editRegCost').value,
                paid: document.getElementById('editRegPaid').checked
            });
            
            // Обновляем статистику
            updatePaymentStatistics();
        } else {
            showNotification('Ошибка: ' + data.message, 'error');
        }
    })
    .catch(error => {
        showNotification('Ошибка сохранения изменений', 'error');
    });
}

/**
 * Показать уведомление
 */
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type === 'error' ? 'danger' : type === 'success' ? 'success' : 'info'} alert-dismissible fade show position-fixed`;
    notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    notification.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(notification);
    
    // Автоматически удаляем через 5 секунд
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 5000);
}

/**
 * Открыть модальное окно импорта регистраций
 */
function openImportModal() {
    // Создаем модальное окно для импорта
    const modalHtml = `
        <div class="modal fade" id="importModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Импорт регистраций</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted">
                            Загрузите Excel-файл с регистрациями на мероприятие. 
                            Файл должен содержать данные о спортсменах и их регистрациях на различные дистанции.
                        </p>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="modalEventSelect" class="form-label">Выберите мероприятие</label>
                                <select class="form-select" id="modalEventSelect" required>
                                    <option value="">Выберите мероприятие...</option>
                                    <?php foreach ($events as $event): ?>
                                    <option value="<?= $event['champn'] ?>"><?= htmlspecialchars($event['meroname']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="modalRegistrationsFile" class="form-label">Excel-файл с регистрациями</label>
                                <input type="file" class="form-control" id="modalRegistrationsFile" accept=".xlsx,.xls" disabled>
                                <div class="form-text" id="modalFileHelpText">Сначала выберите мероприятие</div>
                            </div>
                        </div>
                        
                        <div id="modalProgress" class="mt-3" style="display: none;">
                            <div class="progress">
                                <div class="progress-bar bg-success" role="progressbar" style="width: 0%"></div>
                            </div>
                            <div class="mt-2" id="modalStatus"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="button" class="btn btn-outline-info" onclick="downloadImportTemplate()">
                            <i class="bi bi-download me-1"></i>Скачать шаблон
                        </button>
                        <button type="button" class="btn btn-success" onclick="executeImport()">
                            <i class="bi bi-upload me-1"></i>Импортировать
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Удаляем существующее модальное окно если есть
    const existingModal = document.getElementById('importModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Добавляем модальное окно в DOM
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // Инициализируем обработчики для модального окна
    initImportModalHandlers();
    
    // Показываем модальное окно
    const modal = new bootstrap.Modal(document.getElementById('importModal'));
    modal.show();
}

/**
 * Инициализация обработчиков для модального окна импорта
 */
function initImportModalHandlers() {
    const eventSelect = document.getElementById('modalEventSelect');
    const fileInput = document.getElementById('modalRegistrationsFile');
    const fileHelpText = document.getElementById('modalFileHelpText');
    
    if (eventSelect && fileInput) {
        eventSelect.addEventListener('change', function() {
            if (this.value && this.value.trim() !== '' && this.selectedIndex > 0) {
                fileInput.disabled = false;
                fileInput.style.backgroundColor = '#ffffff';
                fileInput.style.cursor = 'pointer';
                
                if (fileHelpText) {
                    fileHelpText.textContent = 'Выберите Excel файл с регистрациями';
                    fileHelpText.className = 'form-text text-success';
                }
            } else {
                fileInput.disabled = true;
                fileInput.value = '';
                fileInput.style.backgroundColor = '#e9ecef';
                fileInput.style.cursor = 'not-allowed';
                
                if (fileHelpText) {
                    fileHelpText.textContent = 'Сначала выберите мероприятие';
                    fileHelpText.className = 'form-text text-muted';
                }
            }
        });
        
        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                if (fileHelpText) {
                    fileHelpText.textContent = `Выбран файл: ${this.files[0].name}`;
                    fileHelpText.className = 'form-text text-info';
                }
            }
        });
    }
}

/**
 * Выполнение импорта из модального окна
 */
function executeImport() {
    const eventSelect = document.getElementById('modalEventSelect');
    const fileInput = document.getElementById('modalRegistrationsFile');
    
    if (!eventSelect.value) {
        showNotification('Выберите мероприятие', 'error');
        return;
    }
    
    if (!fileInput.files[0]) {
        showNotification('Выберите файл для загрузки', 'error');
        return;
    }
    
    // Показываем прогресс
    document.getElementById('modalProgress').style.display = 'block';
    document.getElementById('modalStatus').textContent = 'Загрузка регистраций...';
    
    const formData = new FormData();
    formData.append('file', fileInput.files[0]);
    formData.append('event', eventSelect.value);
    formData.append('type', 'registrations');
    
    fetch('/lks/php/admin/import-registrations.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        const progressBar = document.querySelector('#modalProgress .progress-bar');
        progressBar.style.width = '100%';
        
        if (data.success) {
            document.getElementById('modalStatus').textContent = 'Импорт завершен успешно!';
            showNotification('Импорт регистраций завершен успешно', 'success');
            
            // Закрываем модальное окно через 2 секунды
            setTimeout(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('importModal'));
                modal.hide();
                // Обновляем статистику вместо перезагрузки
                updatePaymentStatistics();
                showNotification('Для просмотра новых данных используйте фильтры или обновите страницу', 'info');
            }, 2000);
        } else {
            document.getElementById('modalStatus').textContent = 'Ошибка импорта!';
            showNotification('Ошибка импорта: ' + data.message, 'error');
        }
    })
    .catch(error => {
        document.getElementById('modalStatus').textContent = 'Ошибка импорта!';
        showNotification('Ошибка импорта: ' + error.message, 'error');
    });
}

/**
 * Скачивание шаблона для импорта
 */
function downloadImportTemplate() {
    const link = document.createElement('a');
    link.href = '/lks/php/admin/download-template.php?type=registrations';
    link.target = '_blank';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    showNotification('Скачивание шаблона начато', 'info');
}

/**
 * Обновление статистики оплаты без перезагрузки страницы
 */
async function updatePaymentStatistics() {
    try {
        const response = await fetch('/lks/php/admin/get_registration_stats.php');
        const data = await response.json();
        
        if (data.success) {
            // Обновляем счетчики в карточках статистики
            const cards = document.querySelectorAll('.card h3');
            if (cards.length >= 4) {
                cards[0].textContent = data.stats.total || 0;      // Всего регистраций
                cards[1].textContent = data.stats.confirmed || 0;  // Подтверждённых
                cards[2].textContent = data.stats.waiting || 0;    // В ожидании
                cards[3].textContent = data.stats.paid || 0;       // Оплачено
            }
        }
    } catch (error) {
        console.error('Ошибка обновления статистики:', error);
    }
}

/**
 * Обновление данных регистрации в таблице
 */
function updateRegistrationInTable(registrationId, data) {
    // Находим все элементы для данной регистрации
    const statusSelect = document.querySelector(`select[data-registration-id="${registrationId}"]`);
    const paymentSwitch = document.querySelector(`input[data-registration-id="${registrationId}"]`);
    const costCell = statusSelect?.closest('tr')?.querySelector('td:nth-last-child(2)');
    
    // Обновляем статус
    if (statusSelect && data.status) {
        statusSelect.value = data.status;
    }
    
    // Обновляем переключатель оплаты
    if (paymentSwitch && typeof data.paid === 'boolean') {
        paymentSwitch.checked = data.paid;
    }
    
    // Обновляем стоимость
    if (costCell && data.cost) {
        const formattedCost = Math.floor(parseFloat(data.cost));
        costCell.innerHTML = `<strong>${formattedCost} ₽</strong>`;
    }
}

/**
 * Обновление статистики оплаты команды
 */
function updateTeamPaymentStatistics(registrationId, isPaid) {
    // Находим строку участника команды
    const registrationRow = document.querySelector(`tr[data-team-id] input[data-registration-id="${registrationId}"]`)?.closest('tr');
    if (!registrationRow) return;
    
    // Получаем ID команды
    const teamId = registrationRow.getAttribute('data-team-id');
    if (!teamId) return;
    
    // Находим все строки участников этой команды
    const teamMemberRows = document.querySelectorAll(`tr[data-team-id="${teamId}"]`);
    
    // Подсчитываем оплаченных участников команды
    let paidCount = 0;
    let totalCount = teamMemberRows.length;
    
    teamMemberRows.forEach(row => {
        const paymentSwitch = row.querySelector('.payment-switch');
        if (paymentSwitch && paymentSwitch.checked) {
            paidCount++;
        }
    });
    
    // Находим строку заголовка команды (идем назад от первой строки участника)
    let teamHeaderRow = teamMemberRows[0];
    while (teamHeaderRow && teamHeaderRow.previousElementSibling) {
        teamHeaderRow = teamHeaderRow.previousElementSibling;
        if (teamHeaderRow.querySelector('td[colspan="8"]')) {
            break;
        }
    }
    
    if (teamHeaderRow && teamHeaderRow.querySelector('td[colspan="8"]')) {
        // Ищем span с классом text-muted, который содержит статистику оплаты
        const paymentStatsSpan = teamHeaderRow.querySelector('span.text-muted.me-3');
        
        if (paymentStatsSpan && paymentStatsSpan.textContent.includes('Оплачено:')) {
            // Обновляем текст статистики оплаты
            paymentStatsSpan.textContent = `Оплачено: ${paidCount}/${totalCount}`;
        }
    }
}

// Функции для редактирования команд
function editTeamModal() {
    // Проверяем выбранные команды
    const selectedTeams = getSelectedTeams();
    
    if (selectedTeams.length === 0) {
        showNotification('Выберите команду для редактирования', 'warning');
        return;
    }
    
    if (selectedTeams.length > 1) {
        showNotification('Выберите только одну команду для редактирования', 'warning');
        return;
    }
    
    const teamData = selectedTeams[0];
    showEditTeamModal(teamData.teamid, teamData.eventId);
}

function getSelectedTeams() {
    const selectedTeams = [];
    const teamCheckboxes = document.querySelectorAll('.team-checkbox:checked');
    
    teamCheckboxes.forEach(checkbox => {
        selectedTeams.push({
            teamid: checkbox.value,
            eventId: checkbox.dataset.event
        });
    });
    
    return selectedTeams;
}

function showEditTeamModal(teamId, eventId) {
    // Загружаем данные команды
    fetch(`/lks/php/organizer/get_team_details.php?teamId=${teamId}&eventId=${eventId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                createEditTeamModal(data.team);
            } else {
                showNotification('Ошибка загрузки данных команды: ' + data.message, 'error');
            }
        })
        .catch(error => {
            showNotification('Ошибка загрузки данных команды', 'error');
        });
}

function createEditTeamModal(teamData) {
    // Определяем составы по ролям
    const captain = teamData.captain || null;
    const coxswain = teamData.coxswain || null;
    const drummer = teamData.drummer || null;
    const members = teamData.members || [];
    const reserves = teamData.reserves || [];
    
    // Создаем HTML для модального окна
    const modalHtml = `
        <div class="modal fade" id="editTeamModal" tabindex="-1">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-people-fill me-2"></i>
                            Редактирование команды драконов
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <!-- Информация о команде -->
                        <div class="alert alert-primary mb-4">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="editTeamName" class="form-label"><strong>Название команды:</strong></label>
                                        <input type="text" class="form-control" id="editTeamName" value="${teamData.teamname || 'Команда #' + teamData.teamid}" placeholder="Введите название команды">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="editTeamCity" class="form-label"><strong>Город команды:</strong></label>
                                        <input type="text" class="form-control" id="editTeamCity" value="${teamData.teamcity || ''}" placeholder="Введите город команды">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label"><strong>Мероприятие:</strong></label>
                                        <div class="form-control-plaintext">${teamData.meroname}</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label"><strong>Статус:</strong></label>
                                        <div><span class="badge bg-${teamData.status === 'Сформирована' ? 'success' : 'warning'}">${teamData.status}</span></div>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-12">
                                    <button type="button" class="btn btn-primary btn-sm" onclick="updateTeamInfo()">
                                        <i class="bi bi-save"></i> Сохранить изменения
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Кнопка добавления участника -->
                        <div class="alert alert-success mb-4">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6><i class="bi bi-person-plus me-2"></i>Добавление участников в команду</h6>
                                    <p class="mb-0">Нажмите кнопку справа, чтобы добавить нового участника в команду</p>
                                </div>
                                <button type="button" class="btn btn-success" onclick="openAddParticipantModal()">
                                    <i class="bi bi-person-plus"></i> Добавить участника
                                </button>
                            </div>
                        </div>
                        
                        <div class="row">
                            <!-- Капитан -->
                            <div class="col-md-3 mb-4">
                                <div class="card">
                                    <div class="card-header bg-success text-white">
                                        <h6 class="mb-0"><i class="bi bi-star-fill me-2"></i>Капитан (1)</h6>
                                    </div>
                                    <div class="card-body captain-dropzone" data-role="captain" style="min-height: 120px;">
                                        ${captain ? createMemberCard(captain) : '<div class="text-muted text-center py-3">Капитан не назначен<br><small>Перетащите участника сюда</small></div>'}
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Рулевой -->
                            <div class="col-md-3 mb-4">
                                <div class="card">
                                    <div class="card-header bg-info text-white">
                                        <h6 class="mb-0"><i class="bi bi-compass me-2"></i>Рулевой (1)</h6>
                                    </div>
                                                            <div class="card-body coxswain-dropzone" data-role="coxswain" style="min-height: 120px;">
                            ${coxswain ? createMemberCard(coxswain) : '<div class="text-muted text-center py-3">Рулевой не назначен<br><small>Перетащите участника сюда</small></div>'}
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Барабанщик -->
                            <div class="col-md-3 mb-4">
                                <div class="card">
                                    <div class="card-header bg-secondary text-white">
                                        <h6 class="mb-0"><i class="bi bi-music-note me-2"></i>Барабанщик (1)</h6>
                                    </div>
                                    <div class="card-body drummer-dropzone" data-role="drummer" style="min-height: 120px;">
                                        ${drummer ? createMemberCard(drummer) : '<div class="text-muted text-center py-3">Барабанщик не назначен<br><small>Перетащите участника сюда</small></div>'}
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Резерв -->
                            <div class="col-md-3 mb-4">
                                <div class="card">
                                    <div class="card-header bg-warning text-white">
                                        <h6 class="mb-0">
                                            <i class="bi bi-hourglass-split me-2"></i>
                                            Резерв (<span id="reserves-count">${reserves.length}</span>)
                                        </h6>
                                    </div>
                                    <div class="card-body reserves-dropzone" data-role="reserve" style="min-height: 120px;">
                                        ${reserves.length > 0 ? reserves.map(member => createMemberCard(member)).join('') : '<div class="text-muted text-center py-3">Резервисты могут заменить любого игрока<br><small>Перетащите участников сюда</small></div>'}
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Основной состав (гребцы) -->
                        <div class="row">
                            <div class="col-12 mb-4">
                                <div class="card">
                                    <div class="card-header bg-primary text-white">
                                        <h6 class="mb-0">
                                            <i class="bi bi-people-fill me-2"></i>
                                            Основной состав - Гребцы (<span id="members-count">${members.length}</span>)
                                        </h6>
                                    </div>
                                    <div class="card-body members-dropzone" data-role="member" style="min-height: 200px; max-height: 300px; overflow-y: auto;">
                                        ${members.length > 0 ? members.map(member => createMemberCard(member)).join('') : '<div class="text-muted text-center py-3">Гребцы не назначены<br><small>Перетащите участников сюда</small></div>'}
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <input type="hidden" id="editTeamId" value="${teamData.teamid}">
                        <input type="hidden" id="editEventId" value="${teamData.eventId}">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отменить</button>
                        <button type="button" class="btn btn-success" onclick="autoAssignDragonRoles()">Авто-распределение ролей</button>
                        <button type="button" class="btn btn-primary" onclick="saveDragonTeamChanges()">Сохранить изменения</button>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
            .member-card {
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 8px;
                padding: 12px;
                margin-bottom: 8px;
                cursor: move;
                transition: all 0.2s ease;
                user-select: none;
            }
            
            .member-card:hover {
                background: #e9ecef;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            
            .member-card.dragging {
                opacity: 0.5;
                transform: rotate(5deg);
            }
            
            .dropzone {
                border: 2px dashed transparent;
                border-radius: 8px;
                transition: all 0.3s ease;
            }
            
            .dropzone.drag-over {
                border-color: #007bff;
                background-color: rgba(0, 123, 255, 0.1);
            }
            
            .dropzone.invalid-drop {
                border-color: #dc3545;
                background-color: rgba(220, 53, 69, 0.1);
            }
            
            .member-name {
                font-weight: bold;
                font-size: 14px;
                line-height: 1.2;
            }
            
            .member-email {
                font-size: 12px;
                color: #6c757d;
                line-height: 1.2;
            }
            
            .member-status {
                font-size: 11px;
                margin-top: 4px;
            }
        </style>
    `;
    
    // Удаляем предыдущее модальное окно если есть
    const existingModal = document.getElementById('editTeamModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Добавляем новое модальное окно
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // Показываем модальное окно
    const modal = new bootstrap.Modal(document.getElementById('editTeamModal'));
    modal.show();
    
    // Инициализируем Drag & Drop после показа модального окна
    modal._element.addEventListener('shown.bs.modal', function() {
        initializeDragonTeamDragDrop();
    }, { once: true });
}

function createMemberCard(member) {
    return `
        <div class="member-card" draggable="true" data-member-id="${member.oid}" data-current-role="${member.role}">
            <div class="member-name">${member.fio}</div>
            <div class="member-email">${member.email}</div>
            <div class="member-status">
                <span class="badge bg-${member.status === 'Подтверждён' ? 'success' : 'warning'}">${member.status}</span>
                ${member.telephone ? '<small class="text-muted ms-2">' + member.telephone + '</small>' : ''}
            </div>
        </div>
    `;
}

function initializeDragonTeamDragDrop() {
    const dropzones = document.querySelectorAll('.captain-dropzone, .members-dropzone, .reserves-dropzone, .coxswain-dropzone, .drummer-dropzone');
    const memberCards = document.querySelectorAll('.member-card');
    
    // Добавляем класс dropzone для всех зон
    dropzones.forEach(zone => {
        zone.classList.add('dropzone');
    });
    
    // Настройка drag для карточек участников
    memberCards.forEach(card => {
        card.addEventListener('dragstart', handleDragStart);
        card.addEventListener('dragend', handleDragEnd);
    });
    
    // Настройка drop для зон
    dropzones.forEach(zone => {
        zone.addEventListener('dragover', handleDragOver);
        zone.addEventListener('dragenter', handleDragEnter);
        zone.addEventListener('dragleave', handleDragLeave);
        zone.addEventListener('drop', handleDrop);
    });
}

let draggedElement = null;

function handleDragStart(e) {
    draggedElement = this;
    this.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/html', this.outerHTML);
}

function handleDragEnd(e) {
    this.classList.remove('dragging');
    draggedElement = null;
}

function handleDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
}

function handleDragEnter(e) {
    e.preventDefault();
    const targetRole = this.dataset.role;
    
    // Проверяем ограничения
    if (canDropInZone(targetRole)) {
        this.classList.add('drag-over');
        this.classList.remove('invalid-drop');
    } else {
        this.classList.add('invalid-drop');
        this.classList.remove('drag-over');
    }
}

function handleDragLeave(e) {
    // Проверяем что мы действительно покинули зону
    if (!this.contains(e.relatedTarget)) {
        this.classList.remove('drag-over', 'invalid-drop');
    }
}

function handleDrop(e) {
    e.preventDefault();
    this.classList.remove('drag-over', 'invalid-drop');
    
    if (!draggedElement) return;
    
    const targetRole = this.dataset.role;
    
    // Проверяем возможность размещения
    if (!canDropInZone(targetRole)) {
        showNotification('Нельзя разместить участника в эту зону', 'warning');
        return;
    }
    
    // Специальная логика для капитана - если капитан из гребцов, то дублируем
    if (targetRole === 'captain') {
        const oldRole = draggedElement.dataset.currentRole;
        
        // Если капитан был из гребцов, удаляем его из гребцов
        if (oldRole === 'member') {
            // Создаем копию для капитана
            const captainCopy = draggedElement.cloneNode(true);
            captainCopy.dataset.currentRole = 'captain';
            captainCopy.addEventListener('dragstart', handleDragStart);
            captainCopy.addEventListener('dragend', handleDragEnd);
            
            // Удаляем оригинал из гребцов
            draggedElement.remove();
            
            // Добавляем копию в зону капитана
            const emptyMessage = this.querySelector('.text-muted');
            if (emptyMessage) {
                emptyMessage.remove();
            }
            this.appendChild(captainCopy);
            
            showNotification('Капитан назначен (дублирован из гребцов)', 'success');
        } else {
            // Обычное перемещение в капитаны
            const oldParent = draggedElement.parentNode;
            draggedElement.remove();
            
            const emptyMessage = this.querySelector('.text-muted');
            if (emptyMessage) {
                emptyMessage.remove();
            }
            
            draggedElement.dataset.currentRole = targetRole;
            this.appendChild(draggedElement);
            
            showNotification('Капитан назначен', 'success');
        }
    } else {
        // Обычная логика для других ролей
        const oldParent = draggedElement.parentNode;
        draggedElement.remove();
        
        const emptyMessage = this.querySelector('.text-muted');
        if (emptyMessage) {
            emptyMessage.remove();
        }
        
        draggedElement.dataset.currentRole = targetRole;
        this.appendChild(draggedElement);
        
        showNotification('Участник перемещен', 'success');
    }
    
    // Обновляем счетчики
    updateTeamCounts();
    
    // Переинициализируем drag & drop для нового элемента
    if (targetRole !== 'captain' || draggedElement.dataset.currentRole !== 'captain') {
        draggedElement.addEventListener('dragstart', handleDragStart);
        draggedElement.addEventListener('dragend', handleDragEnd);
    }
}

function canDropInZone(targetRole) {
    if (!draggedElement) return false;
    
    const currentCount = document.querySelectorAll(`[data-role="${targetRole}"] .member-card`).length;
    
    switch (targetRole) {
        case 'captain':
            return currentCount === 0; // Только один капитан
        case 'member':
            return true; // Без ограничений на количество гребцов
        case 'reserve':
            return true; // Без ограничений на количество резервистов
        case 'coxswain':
            return currentCount === 0; // Только один рулевой
        case 'drummer':
            return currentCount === 0; // Только один барабанщик
        default:
            return false;
    }
}

function updateTeamCounts() {
    // Обновляем счетчики в заголовках
    const membersCount = document.querySelectorAll('.members-dropzone .member-card').length;
    const reservesCount = document.querySelectorAll('.reserves-dropzone .member-card').length;
    const coxswainCount = document.querySelectorAll('.coxswain-dropzone .member-card').length;
    const drummerCount = document.querySelectorAll('.drummer-dropzone .member-card').length;
    
    document.getElementById('members-count').textContent = membersCount;
    document.getElementById('reserves-count').textContent = reservesCount;
    
    // Добавляем сообщения в пустые зоны
    const dropzones = [
        {
            element: document.querySelector('.captain-dropzone'),
            emptyMessage: 'Капитан не назначен<br><small>Перетащите участника сюда</small>'
        },
        {
            element: document.querySelector('.coxswain-dropzone'),
            emptyMessage: 'Рулевой не назначен<br><small>Перетащите участника сюда</small>'
        },
        {
            element: document.querySelector('.drummer-dropzone'),
            emptyMessage: 'Барабанщик не назначен<br><small>Перетащите участника сюда</small>'
        },
        {
            element: document.querySelector('.members-dropzone'),
            emptyMessage: 'Гребцы не назначены<br><small>Перетащите участников сюда</small>'
        },
        {
            element: document.querySelector('.reserves-dropzone'),
            emptyMessage: 'Резервисты могут заменить любого игрока<br><small>Перетащите участников сюда</small>'
        }
    ];
    
    dropzones.forEach(zone => {
        const hasMembers = zone.element.querySelector('.member-card');
        const hasMessage = zone.element.querySelector('.text-muted');
        
        if (!hasMembers && !hasMessage) {
            zone.element.innerHTML = `<div class="text-muted text-center py-3">${zone.emptyMessage}</div>`;
        } else if (hasMembers && hasMessage) {
            hasMessage.remove();
        }
    });
}

function autoAssignDragonRoles() {
    const allMembers = document.querySelectorAll('.member-card');
    if (allMembers.length === 0) {
        showNotification('Нет участников для распределения', 'warning');
        return;
    }
    
    // Очищаем все зоны
    document.querySelector('.captain-dropzone').innerHTML = '';
    document.querySelector('.coxswain-dropzone').innerHTML = '';
    document.querySelector('.drummer-dropzone').innerHTML = '';
    document.querySelector('.members-dropzone').innerHTML = '';
    document.querySelector('.reserves-dropzone').innerHTML = '';
    
    // Распределяем участников
    allMembers.forEach((member, index) => {
        if (index === 0) {
            // Первый - капитан
            member.dataset.currentRole = 'captain';
            document.querySelector('.captain-dropzone').appendChild(member);
        } else if (index === 1) {
            // Второй - рулевой
            member.dataset.currentRole = 'coxswain';
            document.querySelector('.coxswain-dropzone').appendChild(member);
        } else if (index === 2) {
            // Третий - барабанщик
            member.dataset.currentRole = 'drummer';
            document.querySelector('.drummer-dropzone').appendChild(member);
        } else {
            // Остальные - гребцы
            member.dataset.currentRole = 'member';
            document.querySelector('.members-dropzone').appendChild(member);
        }
    });
    
    updateTeamCounts();
    showNotification('Роли автоматически распределены', 'success');
}

function saveDragonTeamChanges() {
    const teamId = document.getElementById('editTeamId').value;
    const eventId = document.getElementById('editEventId').value;
    
    // Собираем все изменения
    const changes = [];
    const allMembers = document.querySelectorAll('.member-card');
    
    allMembers.forEach(member => {
        const memberId = member.dataset.memberId;
        const newRole = member.dataset.currentRole;
        
        changes.push({
            oid: memberId,
            newRole: newRole
        });
    });
    
    if (changes.length === 0) {
        showNotification('Нет изменений для сохранения', 'warning');
        return;
    }
    
    // Проверяем что есть капитан (обязательно)
    const hasCaptain = changes.some(change => change.newRole === 'captain');
    if (!hasCaptain) {
        showNotification('Должен быть назначен капитан команды', 'error');
        return;
    }
    
    // Проверяем что есть минимум 1 гребец
    const hasMembers = changes.some(change => change.newRole === 'member');
    if (!hasMembers) {
        showNotification('Должен быть назначен минимум 1 гребец', 'error');
        return;
    }
    
    fetch('/lks/php/organizer/save_team_changes.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            teamId: parseInt(teamId),
            champn: eventId,
            changes: changes
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(`Изменения сохранены! Команда готова к соревнованиям.`, 'success');
            
            // Закрываем модальное окно
            const modal = bootstrap.Modal.getInstance(document.getElementById('editTeamModal'));
            modal.hide();
            
            // Перезагружаем страницу через 2 секунды
            setTimeout(() => {
                location.reload();
            }, 2000);
        } else {
            showNotification('Ошибка: ' + data.error, 'error');
        }
    })
    .catch(error => {
        showNotification('Ошибка сохранения изменений: ' + error.message, 'error');
        console.error('Error:', error);
    });
}

/**
 * Обновление информации о команде (название, город)
 */
function updateTeamInfo() {
    const teamId = document.getElementById('editTeamId').value;
    const eventId = document.getElementById('editEventId').value;
    const teamName = document.getElementById('editTeamName').value.trim();
    const teamCity = document.getElementById('editTeamCity').value.trim();
    
    if (!teamName) {
        showNotification('Название команды не может быть пустым', 'error');
        return;
    }
    
    if (!teamCity) {
        showNotification('Город команды не может быть пустым', 'error');
        return;
    }
    
    fetch('/lks/php/organizer/update_team_info.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            teamId: teamId,
            eventId: eventId,
            teamName: teamName,
            teamCity: teamCity
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            
            // Обновляем отображаемую информацию в модальном окне
            const teamNameElement = document.querySelector('.alert-primary .col-md-6:first-child .form-control-plaintext');
            const teamCityElement = document.querySelector('.alert-primary .col-md-6:nth-child(2) .form-control-plaintext');
            
            if (teamNameElement) {
                teamNameElement.textContent = teamName;
            }
            if (teamCityElement) {
                teamCityElement.textContent = teamCity;
            }
            
        } else {
            showNotification('Ошибка: ' + data.error, 'error');
        }
    })
    .catch(error => {
        showNotification('Ошибка обновления информации о команде: ' + error.message, 'error');
        console.error('Error:', error);
    });
}

function fixDragonTeamRoles() {
    if (!confirm('Исправить роли во всех командах драконов D-10?\n\nЭто действие автоматически распределит роли:\n- Первый участник → Капитан\n- Участники 2-13 → Гребцы\n- Участники 14+ → Резервисты')) {
        return;
    }
    
    fetch('/lks/php/admin/fix_existing_dragon_teams.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(`Роли исправлены! Обработано команд: ${data.statistics.fixed_teams}, участников: ${data.statistics.updated_participants}`, 'success');
            
            // Перезагружаем страницу через 3 секунды для отображения изменений
            setTimeout(() => {
                location.reload();
            }, 3000);
        } else {
            showNotification('Ошибка: ' + data.message, 'error');
        }
    })
    .catch(error => {
        showNotification('Ошибка исправления ролей: ' + error.message, 'error');
        console.error('Error:', error);
    });
}

// Удаление участника из команды
function removeMemberFromTeam(memberId, teamId, eventId) {
    if (!confirm('Удалить участника из команды?')) {
        return;
    }
    
    fetch('/lks/php/organizer/remove_member_from_team.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            memberId: parseInt(memberId),
            teamId: parseInt(teamId),
            eventId: eventId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Участник удален из команды', 'success');
            
            // Удаляем карточку участника
            const memberCard = document.querySelector(`[data-member-id="${memberId}"]`);
            if (memberCard) {
                memberCard.remove();
                updateTeamCounts();
            }
        } else {
            showNotification('Ошибка: ' + data.error, 'error');
        }
    })
    .catch(error => {
        showNotification('Ошибка удаления участника: ' + error.message, 'error');
        console.error('Error:', error);
    });
}

/**
 * Открытие модального окна добавления участника
 */
function openAddParticipantModal() {
    const currentTeamId = document.getElementById('editTeamId')?.value;
    const currentEventId = document.getElementById('editEventId')?.value;
    
    if (!currentTeamId || !currentEventId) {
        showNotification('Ошибка: не удалось определить команду или мероприятие', 'error');
        return;
    }
    
    // Получаем класс команды
    let teamClass = '';
    fetch(`/lks/php/organizer/get_team_details.php?teamId=${currentTeamId}&eventId=${currentEventId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.team) {
                teamClass = data.team.class || '';
                // Обновляем скрытое поле с классом команды
                const teamClassInput = document.getElementById('teamClass');
                if (teamClassInput) {
                    teamClassInput.value = teamClass;
                }
            }
        })
        .catch(error => {
            console.error('Ошибка получения данных команды:', error);
        });
    
    // Создаем HTML для модального окна добавления участника
    const modalHtml = `
        <div class="modal fade" id="addParticipantModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-person-plus me-2"></i>
                            Добавление участника в команду
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <!-- Поиск участника -->
                        <div class="mb-4">
                            <h6><i class="bi bi-search me-2"></i>Поиск участника</h6>
                            <div class="input-group">
                                <input type="text" class="form-control" id="participantSearch" 
                                       placeholder="Введите номер спортсмена или email">
                                <button class="btn btn-primary" type="button" onclick="searchParticipant()">
                                    <i class="bi bi-search"></i> Найти
                                </button>
                            </div>
                            <small class="text-muted">Введите номер спортсмена (например: 1001) или email для поиска</small>
                        </div>
                        
                        <!-- Результаты поиска -->
                        <div id="searchResults" class="mb-4" style="display: none;">
                            <h6>Результаты поиска:</h6>
                            <div id="participantsList"></div>
                        </div>
                        
                        <!-- Форма регистрации нового участника -->
                        <div id="newParticipantForm" class="mb-4" style="display: none;">
                            <h6><i class="bi bi-person-plus me-2"></i>Регистрация нового участника</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="newFio" class="form-label">ФИО *</label>
                                        <input type="text" class="form-control" id="newFio" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="newEmail" class="form-label">Email *</label>
                                        <input type="email" class="form-control" id="newEmail" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="newTelephone" class="form-label">Телефон *</label>
                                        <input type="tel" class="form-control" id="newTelephone" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="newSex" class="form-label">Пол</label>
                                        <select class="form-select" id="newSex">
                                            <option value="">Выберите пол</option>
                                            <option value="М">Мужской</option>
                                            <option value="Ж">Женский</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label for="newBirthdata" class="form-label">Дата рождения</label>
                                        <input type="date" class="form-control" id="newBirthdata">
                                    </div>
                                    <div class="mb-3">
                                        <label for="newCountry" class="form-label">Страна</label>
                                        <input type="text" class="form-control" id="newCountry" value="Россия">
                                    </div>
                                    <div class="mb-3">
                                        <label for="newCity" class="form-label">Город</label>
                                        <input type="text" class="form-control" id="newCity">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <input type="hidden" id="teamClass" name="team_class" value="${teamClass}">
                                        <div class="alert alert-info">
                                            <i class="bi bi-info-circle me-2"></i>
                                            <strong>Типы лодок будут автоматически определены</strong><br>
                                            <small>На основе класса команды участнику будут назначены подходящие типы лодок</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="newSportzvanie" class="form-label">Спортивное звание</label>
                                        <select class="form-select" id="newSportzvanie">
                                            <option value="БР">Без разряда</option>
                                            <option value="3вр">3-й разряд</option>
                                            <option value="2вр">2-й разряд</option>
                                            <option value="1вр">1-й разряд</option>
                                            <option value="КМС">КМС</option>
                                            <option value="МСсуч">МСсуч</option>
                                            <option value="МСР">МСР</option>
                                            <option value="МССССР">МССССР</option>
                                            <option value="МСМК">МСМК</option>
                                            <option value="ЗМС">ЗМС</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Выбор дисциплины для добавления -->
                        <div id="disciplineSelection" class="mb-4" style="display: none;">
                            <h6><i class="bi bi-list-check me-2"></i>Выбор дисциплины</h6>
                            <div class="mb-3">
                                <label for="participantDiscipline" class="form-label">Дисциплина участника (необязательно)</label>
                                <select class="form-select" id="participantDiscipline">
                                    <option value="">Автоматически (на основе команды)</option>
                                    <option value="D-10">D-10 (Драконы)</option>
                                    <option value="K-1">K-1 (Байдарка одиночка)</option>
                                    <option value="K-2">K-2 (Байдарка двойка)</option>
                                    <option value="K-4">K-4 (Байдарка четверка)</option>
                                    <option value="C-1">C-1 (Каноэ одиночка)</option>
                                    <option value="C-2">C-2 (Каноэ двойка)</option>
                                    <option value="C-4">C-4 (Каноэ четверка)</option>
                                </select>
                                <small class="text-muted">Дисциплина будет установлена автоматически на основе дисциплины команды. Выбор необязателен.</small>
                            </div>
                        </div>
                        
                        <input type="hidden" id="addTeamId" value="${currentTeamId}">
                        <input type="hidden" id="addEventId" value="${currentEventId}">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отменить</button>
                        <button type="button" class="btn btn-primary" onclick="registerNewParticipant()" id="registerNewBtn" style="display: none;">
                            <i class="bi bi-person-plus"></i> Зарегистрировать и добавить
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Удаляем существующее модальное окно если есть
    const existingModal = document.getElementById('addParticipantModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Добавляем модальное окно в DOM
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // Показываем модальное окно
    const modal = new bootstrap.Modal(document.getElementById('addParticipantModal'));
    modal.show();
}

/**
 * Поиск участника
 */
function searchParticipant() {
    const searchTerm = document.getElementById('participantSearch').value.trim();
    const eventId = document.getElementById('addEventId').value;
    
    if (!searchTerm) {
        showNotification('Введите номер спортсмена или email для поиска', 'warning');
        return;
    }
    
    // Показываем индикатор загрузки
    document.getElementById('searchResults').style.display = 'block';
    document.getElementById('participantsList').innerHTML = '<div class="text-center"><i class="bi bi-hourglass-split"></i> Поиск...</div>';
    
    fetch(`/lks/php/organizer/search_participant.php?search=${encodeURIComponent(searchTerm)}&event_id=${eventId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.found) {
                    displaySearchResults(data.participants);
                } else {
                    // Участник не найден, показываем форму регистрации
                    document.getElementById('searchResults').style.display = 'none';
                    document.getElementById('newParticipantForm').style.display = 'block';
                    document.getElementById('registerNewBtn').style.display = 'block';
                    showNotification('Участник не найден. Заполните форму для регистрации нового участника.', 'info');
                }
            } else {
                showNotification('Ошибка поиска: ' + data.message, 'error');
                document.getElementById('searchResults').style.display = 'none';
            }
        })
        .catch(error => {
            showNotification('Ошибка поиска участника: ' + error.message, 'error');
            document.getElementById('searchResults').style.display = 'none';
        });
}

/**
 * Отображение результатов поиска
 */
function displaySearchResults(participants) {
    const container = document.getElementById('participantsList');
    
    // Показываем поле выбора дисциплины
    document.getElementById('disciplineSelection').style.display = 'block';
    
    const html = participants.map(participant => {
        const isRegistered = participant.is_registered;
        const registrationInfo = participant.registration;
        
        let statusBadge = '';
        if (isRegistered) {
            const statusClass = registrationInfo.status === 'Подтверждён' ? 'success' : 'warning';
            statusBadge = `<span class="badge bg-${statusClass}">${registrationInfo.status}</span>`;
        } else {
            statusBadge = '<span class="badge bg-secondary">Не зарегистрирован</span>';
        }
        
        return `
            <div class="card mb-2">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h6 class="mb-1">${participant.fio}</h6>
                            <p class="mb-1 text-muted">
                                <small>
                                    №${participant.userid} | ${participant.email} | ${participant.telephone}
                                </small>
                            </p>
                            <p class="mb-0">
                                ${statusBadge}
                                ${isRegistered && registrationInfo.teamname ? 
                                    `<span class="badge bg-info ms-1">Команда: ${registrationInfo.teamname}</span>` : ''}
                            </p>
                        </div>
                        <div class="col-md-4 text-end">
                            ${!isRegistered ? 
                                `<button class="btn btn-success btn-sm" onclick="addParticipantToTeam(${participant.oid})">
                                    <i class="bi bi-person-plus"></i> Добавить в команду
                                </button>` :
                                `<button class="btn btn-warning btn-sm" onclick="addParticipantToTeam(${participant.oid})">
                                    <i class="bi bi-arrow-repeat"></i> Добавить в команду
                                </button>`
                            }
                        </div>
                    </div>
                </div>
            </div>
        `;
    }).join('');
    
    container.innerHTML = html;
}

/**
 * Добавление участника в команду
 */
function addParticipantToTeam(userId) {
    const teamId = document.getElementById('addTeamId').value;
    const eventId = document.getElementById('addEventId').value;
    const discipline = document.getElementById('participantDiscipline').value;
    
    // Дисциплина будет установлена автоматически на основе дисциплины команды
    // Если пользователь выбрал дисциплину, используем её, иначе будет установлена автоматически
    let disciplineData = null;
    if (discipline) {
        disciplineData = {
            [discipline]: {
                "sex": ["M", "W"],
                "dist": ["200", "500", "1000"]
            }
        };
    }
    
    fetch('/lks/php/organizer/add_participant_to_team.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            user_id: userId,
            team_id: teamId,
            event_id: eventId,
            role: 'member',
            discipline: disciplineData
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            // Закрываем модальное окно
            const modal = bootstrap.Modal.getInstance(document.getElementById('addParticipantModal'));
            modal.hide();
            // Перезагружаем страницу для обновления данных
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification('Ошибка: ' + data.message, 'error');
        }
    })
    .catch(error => {
        showNotification('Ошибка добавления участника: ' + error.message, 'error');
    });
}

/**
 * Регистрация нового участника
 */
function registerNewParticipant() {
    const fio = document.getElementById('newFio').value.trim();
    const email = document.getElementById('newEmail').value.trim();
    const telephone = document.getElementById('newTelephone').value.trim();
    const sex = document.getElementById('newSex').value;
    const birthdata = document.getElementById('newBirthdata').value;
    const country = document.getElementById('newCountry').value.trim();
    const city = document.getElementById('newCity').value.trim();
    const sportzvanie = document.getElementById('newSportzvanie').value;
    const teamClass = document.getElementById('teamClass').value;
    
    // Валидация
    if (!fio || !email || !telephone) {
        showNotification('Заполните все обязательные поля', 'warning');
        return;
    }
    
    const participantData = {
        fio: fio,
        email: email,
        telephone: telephone,
        sex: sex,
        birthdata: birthdata,
        country: country,
        city: city,
        sportzvanie: sportzvanie,
        team_class: teamClass
    };
    
    fetch('/lks/php/organizer/register_new_participant.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(participantData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Участник успешно зарегистрирован! Пароль отправлен на email.', 'success');
            
            // Автоматически добавляем участника в команду
            setTimeout(() => {
                addParticipantToTeam(data.user.oid);
            }, 1000);
        } else {
            showNotification('Ошибка регистрации: ' + data.message, 'error');
        }
    })
    .catch(error => {
        showNotification('Ошибка регистрации участника: ' + error.message, 'error');
    });
}
</script>

<?php include '../includes/footer.php'; ?> 
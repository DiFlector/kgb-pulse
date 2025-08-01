<?php
require_once '../../php/common/Auth.php';
require_once '../../php/db/Database.php';
require_once '../../php/secretary/protocol_numbering.php';

$auth = new Auth();
$user = $auth->checkRole(['Secretary', 'SuperUser', 'Admin']);
if (!$user) {
    header('Location: ../../login.php');
    exit();
}

// Получаем данные из сессии
$selectedEvent = $_SESSION['selected_event'] ?? null;

if (!$selectedEvent) {
    echo '<div class="alert alert-danger">Данные мероприятия не найдены. Вернитесь к списку мероприятий.</div>';
    echo '<a href="main.php" class="btn btn-primary">Вернуться к списку мероприятий</a>';
    exit();
}

$eventId = $selectedEvent['id'];

$db = Database::getInstance();

// Получаем информацию о мероприятии
try {
    $stmt = $db->prepare("SELECT * FROM meros WHERE champn = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    
    if (!$event) {
        echo '<div class="alert alert-danger">Мероприятие не найдено</div>';
        echo '<a href="main.php" class="btn btn-primary">Вернуться к списку мероприятий</a>';
        exit();
    }
    
    $classDistance = json_decode($event['class_distance'], true);
    
} catch (Exception $e) {
    error_log("Ошибка в complete-event.php: " . $e->getMessage());
    echo '<div class="alert alert-danger">Ошибка загрузки данных мероприятия</div>';
    echo '<a href="main.php" class="btn btn-primary">Вернуться к списку мероприятий</a>';
    exit();
}

// Подключаемся к Redis для получения данных о медалях
$redis = new Redis();
try {
    $connected = $redis->connect('redis', 6379, 5);
    if (!$connected) {
        throw new Exception('Не удалось подключиться к Redis');
    }
} catch (Exception $e) {
    error_log("Ошибка подключения к Redis: " . $e->getMessage());
    $redis = null;
}

// Получаем статистику медалей
$medalsStats = [];
if ($redis) {
    try {
        $protocols = ProtocolNumbering::getProtocolsStructure($classDistance, $_SESSION['selected_disciplines'] ?? null);
        
        foreach ($protocols as $protocol) {
            $key = ProtocolNumbering::getProtocolKey($eventId, $protocol['class'], $protocol['sex'], $protocol['distance'], $protocol['ageGroup']);
            $protocolData = $redis->get($key);
            
            if ($protocolData) {
                $data = json_decode($protocolData, true);
                if ($data && isset($data['participants'])) {
                    $disciplineKey = $protocol['class'] . '_' . $protocol['sex'] . '_' . $protocol['distance'];
                    
                    if (!isset($medalsStats[$disciplineKey])) {
                        $medalsStats[$disciplineKey] = [
                            'discipline' => $disciplineKey,
                            'gold' => 0,
                            'silver' => 0,
                            'bronze' => 0,
                            'total' => 0
                        ];
                    }
                    
                    // Подсчитываем медали (участники с местами 1, 2, 3)
                    foreach ($data['participants'] as $participant) {
                        if (isset($participant['place']) && is_numeric($participant['place'])) {
                            $place = intval($participant['place']);
                            if ($place === 1) {
                                $medalsStats[$disciplineKey]['gold']++;
                            } elseif ($place === 2) {
                                $medalsStats[$disciplineKey]['silver']++;
                            } elseif ($place === 3) {
                                $medalsStats[$disciplineKey]['bronze']++;
                            }
                            $medalsStats[$disciplineKey]['total']++;
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Ошибка получения статистики медалей: " . $e->getMessage());
    }
}

$pageTitle = 'Завершение мероприятия';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - KGB-Pulse</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../../css/style.css" rel="stylesheet">
    <style>
        .medal-card {
            transition: all 0.3s ease;
        }
        .medal-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .gold { color: #FFD700; }
        .silver { color: #C0C0C0; }
        .bronze { color: #CD7F32; }
        .action-btn {
            min-height: 60px;
            font-size: 1.1rem;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Заголовок -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0"><?= htmlspecialchars($event['meroname']) ?></h1>
                    <p class="text-muted mb-0"><?= htmlspecialchars($event['merodata']) ?></p>
                    <p class="text-success mb-0"><i class="bi bi-check-circle"></i> Мероприятие готово к завершению</p>
                </div>
                <a href="select-disciplines.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Назад к дисциплинам
                </a>
            </div>

            <!-- Информация о мероприятии -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-info-circle"></i> Информация о мероприятии</h5>
                        </div>
                        <div class="card-body">
                            <p><strong>Название:</strong> <?= htmlspecialchars($event['meroname']) ?></p>
                            <p><strong>Дата:</strong> <?= htmlspecialchars($event['merodata']) ?></p>
                            <p><strong>Номер:</strong> <?= htmlspecialchars($event['champn']) ?></p>
                            <p><strong>Статус:</strong> <span class="badge bg-success">Готово к завершению</span></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="bi bi-trophy"></i> Статистика медалей</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($medalsStats)): ?>
                                <div class="row text-center">
                                    <div class="col-md-4">
                                        <div class="gold fs-4"><i class="bi bi-award"></i></div>
                                        <div class="fw-bold"><?= array_sum(array_column($medalsStats, 'gold')) ?></div>
                                        <small class="text-muted">Золотых</small>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="silver fs-4"><i class="bi bi-award"></i></div>
                                        <div class="fw-bold"><?= array_sum(array_column($medalsStats, 'silver')) ?></div>
                                        <small class="text-muted">Серебряных</small>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="bronze fs-4"><i class="bi bi-award"></i></div>
                                        <div class="fw-bold"><?= array_sum(array_column($medalsStats, 'bronze')) ?></div>
                                        <small class="text-muted">Бронзовых</small>
                                    </div>
                                </div>
                            <?php else: ?>
                                <p class="text-muted">Статистика медалей будет доступна после заполнения результатов</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Детальная таблица медалей -->
            <?php if (!empty($medalsStats)): ?>
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-table"></i> Детальная таблица медалей</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Дисциплина</th>
                                    <th class="text-center"><span class="gold"><i class="bi bi-award"></i></span> Золото</th>
                                    <th class="text-center"><span class="silver"><i class="bi bi-award"></i></span> Серебро</th>
                                    <th class="text-center"><span class="bronze"><i class="bi bi-award"></i></span> Бронза</th>
                                    <th class="text-center">Всего</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($medalsStats as $discipline => $stats): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($discipline) ?></strong></td>
                                    <td class="text-center"><span class="badge bg-warning"><?= $stats['gold'] ?></span></td>
                                    <td class="text-center"><span class="badge bg-secondary"><?= $stats['silver'] ?></span></td>
                                    <td class="text-center"><span class="badge bg-danger"><?= $stats['bronze'] ?></span></td>
                                    <td class="text-center"><strong><?= $stats['total'] ?></strong></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Действия по завершению -->
            <div class="row">
                <div class="col-md-4 mb-3">
                    <div class="card medal-card h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-file-earmark-pdf fs-1 text-danger mb-3"></i>
                            <h5 class="card-title">Итоговый протокол</h5>
                            <p class="card-text">Скачать PDF документ со всеми участниками по дисциплинам и возрастным группам</p>
                            <button class="btn btn-danger action-btn w-100" onclick="downloadFinalProtocol()">
                                <i class="bi bi-download"></i> Скачать протокол
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4 mb-3">
                    <div class="card medal-card h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-file-earmark-zip fs-1 text-warning mb-3"></i>
                            <h5 class="card-title">Наградные ведомости</h5>
                            <p class="card-text">Скачать архив с PDF документами по наградным листам для каждого протокола</p>
                            <button class="btn btn-warning action-btn w-100" onclick="downloadAwardSheets()">
                                <i class="bi bi-download"></i> Скачать ведомости
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4 mb-3">
                    <div class="card medal-card h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-flag-checkered fs-1 text-success mb-3"></i>
                            <h5 class="card-title">Завершить мероприятие</h5>
                            <p class="card-text">Окончательно завершить мероприятие и сохранить результаты в базе данных</p>
                            <button class="btn btn-success action-btn w-100" onclick="finalizeEvent()">
                                <i class="bi bi-check-circle"></i> Завершить
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const eventId = <?= $eventId ?>;
        
        // Скачивание итогового протокола
        async function downloadFinalProtocol() {
            try {
                const response = await fetch('/lks/php/secretary/download_final_protocol.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        meroId: eventId
                    })
                });

                if (response.ok) {
                    const blob = await response.blob();
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `final_protocol_${eventId}.pdf`;
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                } else {
                    const data = await response.json();
                    alert('Ошибка скачивания: ' + (data.message || 'Неизвестная ошибка'));
                }
            } catch (error) {
                console.error('Ошибка скачивания:', error);
                alert('Ошибка скачивания протокола');
            }
        }
        
        // Скачивание наградных ведомостей
        async function downloadAwardSheets() {
            try {
                const response = await fetch('/lks/php/secretary/download_award_sheets.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        meroId: eventId
                    })
                });

                if (response.ok) {
                    const blob = await response.blob();
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `award_sheets_${eventId}.zip`;
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                } else {
                    const data = await response.json();
                    alert('Ошибка скачивания: ' + (data.message || 'Неизвестная ошибка'));
                }
            } catch (error) {
                console.error('Ошибка скачивания:', error);
                alert('Ошибка скачивания наградных ведомостей');
            }
        }
        
        // Финальное завершение мероприятия
        async function finalizeEvent() {
            if (!confirm('Вы уверены, что хотите окончательно завершить мероприятие? Это действие нельзя отменить.')) {
                return;
            }
            
            try {
                const response = await fetch('/lks/php/secretary/finalize_event.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        meroId: eventId
                    })
                });

                const data = await response.json();
                
                if (data.success) {
                    alert('Мероприятие успешно завершено!');
                    window.location.href = 'main.php';
                } else {
                    alert('Ошибка завершения: ' + (data.message || 'Неизвестная ошибка'));
                }
            } catch (error) {
                console.error('Ошибка завершения:', error);
                alert('Ошибка завершения мероприятия');
            }
        }
    </script>
</body>
</html> 
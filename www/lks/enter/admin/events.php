<?php
/**
 * Управление мероприятиями - Администратор
 */

session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Admin' && $_SESSION['user_role'] !== 'SuperUser')) {
    header('Location: /lks/login.php');
    exit;
}

require_once '../../php/db/Database.php';

try {
    $db = Database::getInstance();
    
    // Получение всех мероприятий
    $events = $db->query("SELECT champn, meroname, merodata, status, defcost FROM meros ORDER BY champn DESC")->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Events page error: " . $e->getMessage());
    $events = [];
}

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

include '../includes/header.php';
?>

<!-- Заголовок страницы -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Управление мероприятиями</h1>
    <div class="alert alert-info mb-0" role="alert">
        <i class="bi bi-info-circle"></i>
        Создание мероприятий доступно организаторам
    </div>
</div>

<!-- Фильтры -->
<div class="card mb-4 shadow">
    <div class="card-header bg-primary text-white">
        <h6 class="m-0 font-weight-bold">Фильтры</h6>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <label for="statusFilter" class="form-label">Статус</label>
                <select class="form-select" id="statusFilter">
                    <option value="">Все статусы</option>
                    <option value="В ожидании">В ожидании</option>
                    <option value="Регистрация">Регистрация</option>
                    <option value="Регистрация закрыта">Регистрация закрыта</option>
                    <option value="Перенесено">Перенесено</option>
                    <option value="Результаты">Результаты</option>
                    <option value="Завершено">Завершено</option>
                </select>
            </div>
            <div class="col-md-6">
                <label for="searchInput" class="form-label">Поиск</label>
                <input type="text" class="form-control" id="searchInput" placeholder="Поиск по названию...">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button class="btn btn-outline-secondary me-2" onclick="clearFilters()">Очистить</button>
                <button class="btn btn-primary" onclick="applyFilters()">Применить</button>
            </div>
        </div>
    </div>
</div>

<!-- Таблица мероприятий -->
<div class="card shadow">
    <div class="card-header bg-info text-white">
        <h6 class="m-0 font-weight-bold">Список мероприятий</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="eventsTable">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Название</th>
                        <th>Дата</th>
                        <th>Статус</th>
                        <th>Стоимость</th>
                        <th>Регистраций</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($events)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            <i class="bi bi-calendar-x display-4"></i>
                            <p class="mt-2">Нет мероприятий</p>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($events as $event): ?>
                        <tr data-status="<?= htmlspecialchars($event['status']) ?>">
                            <td><strong><?= htmlspecialchars($event['champn']) ?></strong></td>
                            <td><?= htmlspecialchars($event['meroname']) ?></td>
                            <td><?= htmlspecialchars($event['merodata']) ?></td>
                            <td>
                                <span class="badge bg-<?= getStatusColor($event['status']) ?>">
                                    <?= htmlspecialchars($event['status']) ?>
                                </span>
                            </td>
                            <td><strong><?= htmlspecialchars($event['defcost']) ?> ₽</strong></td>
                            <td>
                                <?php
                                try {
                                    $regCount = $db->prepare("SELECT COUNT(*) FROM listreg l JOIN meros m ON l.meros_oid = m.oid WHERE m.champn = ?");
                                    $regCount->execute([$event['champn']]);
                                    echo '<span class="badge bg-primary">' . $regCount->fetchColumn() . '</span>';
                                } catch (Exception $e) {
                                    echo '<span class="badge bg-secondary">0</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <div class="btn-group" role="group">
                                    <button class="btn btn-sm btn-outline-primary" onclick="editEvent(<?= $event['champn'] ?>)" title="Редактировать">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-info" onclick="viewRegistrations(<?= $event['champn'] ?>)" title="Регистрации">
                                        <i class="bi bi-people"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-success" onclick="exportEvent(<?= $event['champn'] ?>)" title="Экспорт">
                                        <i class="bi bi-download"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteEvent(<?= $event['champn'] ?>)" title="Удалить">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function applyFilters() {
    const statusFilter = document.getElementById("statusFilter").value;
    const searchInput = document.getElementById("searchInput").value.toLowerCase();
    const table = document.getElementById("eventsTable");
    const rows = table.getElementsByTagName("tbody")[0].getElementsByTagName("tr");

    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        if (!row.dataset.status) continue; // Пропускаем строку "Нет мероприятий"
        
        const status = row.getAttribute("data-status");
        const name = row.cells[1].textContent.toLowerCase();
        
        let showRow = true;
        
        if (statusFilter && status !== statusFilter) {
            showRow = false;
        }
        
        if (searchInput && !name.includes(searchInput)) {
            showRow = false;
        }
        
        row.style.display = showRow ? "" : "none";
    }
}

function clearFilters() {
    document.getElementById("statusFilter").value = "";
    document.getElementById("searchInput").value = "";
    applyFilters();
}

function editEvent(eventId) {
    // Переход на страницу редактирования мероприятия (у организаторов)
    window.location.href = "/lks/enter/organizer/create-event.php?edit=" + eventId;
}

function viewRegistrations(eventId) {
    window.location.href = "/lks/enter/admin/registrations.php?event=" + eventId;
}

function exportEvent(eventId) {
    // Экспорт мероприятия в Excel
    window.location.href = "/lks/php/admin/export.php?type=event&id=" + eventId + "&format=xlsx";
    showNotification("Экспорт мероприятия начат", "info");
}

function deleteEvent(eventId) {
    if (confirm("Вы уверены, что хотите удалить это мероприятие?\nВсе связанные регистрации также будут удалены!")) {
        fetch('/lks/php/admin/delete_event.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                eventId: eventId
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
            showNotification('Ошибка удаления мероприятия', 'error');
        });
    }
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

// Инициализация фильтров при загрузке
document.addEventListener("DOMContentLoaded", function() {
    // Автофильтрация при вводе
    document.getElementById("searchInput").addEventListener("input", applyFilters);
    document.getElementById("statusFilter").addEventListener("change", applyFilters);
});
</script>

<?php include '../includes/footer.php'; ?> 
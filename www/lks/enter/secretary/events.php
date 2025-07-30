<?php
/**
 * Мероприятия - Секретарь
 */

session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Secretary' && $_SESSION['user_role'] !== 'SuperUser' && $_SESSION['user_role'] !== 'Admin')) {
    header('Location: /lks/login.php');
    exit;
}

require_once '../../php/db/Database.php';

try {
    $db = Database::getInstance();
    $userId = $_SESSION['user_id'];
    
    // Получение всех мероприятий
    $events = $db->query("
        SELECT champn, merodata, meroname, class_distance, defcost, filepolojenie, status::text as status,
               EXTRACT(YEAR FROM CURRENT_DATE) as year,
               (SELECT COUNT(*) FROM listreg WHERE champn = m.champn) as registrations_count
        FROM meros m
        WHERE TRIM(status::text) IN ('В ожидании', 'Регистрация', 'Регистрация закрыта', 'В процессе', 'Результаты', 'Завершено')
        ORDER BY 
            CASE 
                WHEN TRIM(status::text) = 'Регистрация закрыта' THEN 1
                WHEN TRIM(status::text) = 'В процессе' THEN 2
                WHEN TRIM(status::text) = 'Результаты' THEN 3
                ELSE 4
            END,
            champn DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Events page error: " . $e->getMessage());
    $events = [];
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

// Функция для извлечения года из даты мероприятия
function extractYear($merodata) {
    if (preg_match('/(\d{4})/', $merodata, $matches)) {
        return $matches[1];
    }
    return date('Y');
}

// Функция для парсинга class_distance JSON
function parseClassDistance($classDistance) {
    if (empty($classDistance)) return [];
    
    $decoded = json_decode($classDistance, true);
    if (!$decoded) return [];
    
    return $decoded;
}

include '../includes/header.php';
?>

<!-- Заголовок страницы -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Мероприятия</h1>
    <div class="btn-group">
        <a href="main.php" class="btn btn-success">
            <i class="bi bi-play-circle"></i> Проведение мероприятий
        </a>
        <a href="main.php" class="btn btn-primary">
            <i class="bi bi-award"></i> Проведение мероприятия
        </a>
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
                    <option value="В процессе">В процессе</option>
                    <option value="Результаты">Результаты</option>
                    <option value="Завершено">Завершено</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="yearFilter" class="form-label">Год</label>
                <select class="form-select" id="yearFilter">
                    <option value="">Все годы</option>
                    <?php for($year = date('Y'); $year >= 2020; $year--): ?>
                        <option value="<?= $year ?>"><?= $year ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="searchInput" class="form-label">Поиск</label>
                <input type="text" class="form-control" id="searchInput" placeholder="Поиск по названию...">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-outline-secondary me-2" onclick="clearFilters()">Очистить</button>
            </div>
        </div>
    </div>
</div>

<!-- Список мероприятий -->
<div class="card shadow">
    <div class="card-header bg-info text-white">
        <h6 class="m-0 font-weight-bold">Список мероприятий</h6>
    </div>
    <div class="card-body">
        <?php if (empty($events)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-calendar-x display-1"></i>
                <h4 class="mt-3">Нет доступных мероприятий</h4>
                <p class="text-muted">В настоящее время нет мероприятий для проведения</p>
            </div>
        <?php else: ?>
            <div class="row" id="eventsContainer">
                <?php foreach ($events as $event): 
                    $classData = parseClassDistance($event['class_distance']);
                    $year = extractYear($event['merodata']);
                ?>
                <div class="col-md-6 col-lg-4 mb-4 event-card" 
                     data-status="<?= htmlspecialchars($event['status']) ?>"
                     data-year="<?= htmlspecialchars($year) ?>"
                     data-name="<?= htmlspecialchars(strtolower($event['meroname'])) ?>">
                    <div class="card h-100 border-left-<?= getStatusColor($event['status']) ?>">
                        <div class="card-header bg-light">
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted">ID: <?= htmlspecialchars($event['champn']) ?></small>
                                <span class="badge bg-<?= getStatusColor($event['status']) ?>">
                                    <?= htmlspecialchars($event['status']) ?>
                                </span>
                            </div>
                            <h6 class="card-title mb-0 mt-2">
                                <?= htmlspecialchars($event['meroname']) ?>
                            </h6>
                        </div>
                        <div class="card-body">
                            <!-- Год -->
                            <div class="mb-2">
                                <strong class="text-primary">Год:</strong> 
                                <span class="badge bg-secondary"><?= htmlspecialchars($year) ?></span>
                            </div>
                            
                            <!-- Сроки проведения -->
                            <div class="mb-3">
                                <strong class="text-primary">Сроки проведения:</strong><br>
                                <span class="text-info"><?= htmlspecialchars($event['merodata']) ?></span>
                            </div>
                            
                            <!-- Участники -->
                            <div class="mb-3">
                                <strong class="text-primary">Участников:</strong> 
                                <span class="badge bg-info"><?= $event['registrations_count'] ?></span>
                            </div>
                            
                            <!-- Классы, пол и дистанции -->
                            <?php if (!empty($classData)): ?>
                            <div class="mb-3">
                                <strong class="text-primary">Классы:</strong>
                                <div class="mt-2">
                                    <?php foreach ($classData as $class => $details): ?>
                                    <div class="border rounded p-2 mb-2 bg-light">
                                        <strong class="text-dark"><?= htmlspecialchars($class) ?></strong>
                                        
                                        <!-- Пол -->
                                        <?php if (isset($details['sex']) && is_array($details['sex'])): ?>
                                        <div class="mt-1">
                                            <small class="text-muted">Пол:</small>
                                            <?php foreach ($details['sex'] as $sex): ?>
                                                <span class="badge bg-info me-1"><?= htmlspecialchars($sex) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <!-- Дистанции -->
                                        <?php if (isset($details['dist']) && is_array($details['dist'])): ?>
                                        <div class="mt-1">
                                            <small class="text-muted">Дистанции:</small><br>
                                            <small><?= htmlspecialchars(implode(', ', $details['dist'])) ?></small>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="card-footer bg-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <!-- Документы -->
                                <div>
                                    <?php if (!empty($event['filepolojenie'])): ?>
                                        <a href="/lks/files/polojenia/<?= htmlspecialchars(basename($event['filepolojenie'])) ?>" 
                                           class="btn btn-sm btn-outline-info" target="_blank">
                                            <i class="bi bi-file-earmark-text"></i> Положение
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small">Нет документов</span>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Действия для секретаря -->
                                <div>
                                    <?php if ($event['status'] === 'Регистрация закрыта'): ?>
                                        <button class="btn btn-warning btn-sm" onclick="startDraw(<?= $event['champn'] ?>)">
                                            <i class="bi bi-shuffle"></i> Жеребьевка
                                        </button>
                                    <?php elseif ($event['status'] === 'В процессе'): ?>
                                        <button class="btn btn-info btn-sm" onclick="manageEvent(<?= $event['champn'] ?>)">
                                            <i class="bi bi-gear"></i> Управление
                                        </button>
                                    <?php elseif ($event['status'] === 'Результаты'): ?>
                                        <button class="btn btn-primary btn-sm" onclick="viewResults(<?= $event['champn'] ?>)">
                                            <i class="bi bi-trophy"></i> Результаты
                                        </button>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?= htmlspecialchars($event['status']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function clearFilters() {
    document.getElementById("statusFilter").value = "";
    document.getElementById("yearFilter").value = "";
    document.getElementById("searchInput").value = "";
    applyFilters();
}

function applyFilters() {
    const statusFilter = document.getElementById("statusFilter").value;
    const yearFilter = document.getElementById("yearFilter").value;
    const searchInput = document.getElementById("searchInput").value.toLowerCase();
    const cards = document.querySelectorAll('.event-card');

    cards.forEach(card => {
        const status = card.getAttribute("data-status");
        const year = card.getAttribute("data-year");
        const name = card.getAttribute("data-name");
        
        let showCard = true;
        
        if (statusFilter && status !== statusFilter) {
            showCard = false;
        }
        
        if (yearFilter && year !== yearFilter) {
            showCard = false;
        }
        
        if (searchInput && !name.includes(searchInput)) {
            showCard = false;
        }
        
        card.style.display = showCard ? "block" : "none";
    });
}

function startDraw(eventId) {
    // Создаем скрытую форму для отправки данных
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'process-event-selection.php';
    
    // Добавляем поля формы
    const eventIdField = document.createElement('input');
    eventIdField.type = 'hidden';
    eventIdField.name = 'event_id';
    eventIdField.value = eventId;
    form.appendChild(eventIdField);
    
    const actionField = document.createElement('input');
    actionField.type = 'hidden';
    actionField.name = 'action';
    actionField.value = 'select_disciplines';
    form.appendChild(actionField);
    
    // Добавляем форму на страницу и отправляем
    document.body.appendChild(form);
    form.submit();
}

function manageEvent(eventId) {
    // Создаем скрытую форму для отправки данных
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'process-event-selection.php';
    
    // Добавляем поля формы
    const eventIdField = document.createElement('input');
    eventIdField.type = 'hidden';
    eventIdField.name = 'event_id';
    eventIdField.value = eventId;
    form.appendChild(eventIdField);
    
    const actionField = document.createElement('input');
    actionField.type = 'hidden';
    actionField.name = 'action';
    actionField.value = 'main';
    form.appendChild(actionField);
    
    // Добавляем форму на страницу и отправляем
    document.body.appendChild(form);
    form.submit();
}

function viewResults(eventId) {
    // Создаем скрытую форму для отправки данных
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'process-event-selection.php';
    
    // Добавляем поля формы
    const eventIdField = document.createElement('input');
    eventIdField.type = 'hidden';
    eventIdField.name = 'event_id';
    eventIdField.value = eventId;
    form.appendChild(eventIdField);
    
    const actionField = document.createElement('input');
    actionField.type = 'hidden';
    actionField.name = 'action';
    actionField.value = 'results';
    form.appendChild(actionField);
    
    // Добавляем форму на страницу и отправляем
    document.body.appendChild(form);
    form.submit();
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
    document.getElementById("yearFilter").addEventListener("change", applyFilters);
});
</script>

<?php include '../includes/footer.php'; ?> 
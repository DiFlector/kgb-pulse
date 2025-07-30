<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Organizer' && $_SESSION['user_role'] !== 'SuperUser' && $_SESSION['user_role'] !== 'Admin')) {
    header('Location: /lks/login.php');
    exit;
}

require_once '../../php/db/Database.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'];

// Получение всех мероприятий для календаря
try {
    $eventsStmt = $db->prepare("
        SELECT oid, meroname, merodata, status, defcost, class_distance, filepolojenie,
               (SELECT COUNT(*) FROM listreg WHERE champn = m.oid) as registrations_count
        FROM meros m 
        ORDER BY merodata ASC
    ");
    $eventsStmt->execute();
    $events = $eventsStmt->fetchAll();
} catch (Exception $e) {
    error_log("Calendar page error: " . $e->getMessage());
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

/**
 * Извлекает год из поля merodata
 */
function extractYearFromMerodata($merodata) {
    if (preg_match('/(\d{4})/', $merodata, $matches)) {
        return $matches[1];
    }
    return date('Y');
}

/**
 * Извлекает период проведения из поля merodata (без года)
 */
function extractPeriodFromMerodata($merodata) {
    // Убираем год из строки
    $period = preg_replace('/\s*\d{4}\s*/', '', $merodata);
    return trim($period);
}

/**
 * Парсит JSON с классами и дистанциями
 */
function parseEventClasses($classDistance) {
    if (empty($classDistance)) return [];
    
    $decoded = json_decode($classDistance, true);
    if (!$decoded) return [];
    
    return $decoded;
}

/**
 * Извлекает уникальные полы из данных классов
 */
function extractSexes($classData) {
    $sexes = [];
    foreach ($classData as $classInfo) {
        if (isset($classInfo['sex']) && is_array($classInfo['sex'])) {
            $sexes = array_merge($sexes, $classInfo['sex']);
        }
    }
    return array_unique($sexes);
}

/**
 * Извлекает уникальные дистанции из данных классов
 */
function extractDistances($classData) {
    $distances = [];
    foreach ($classData as $classInfo) {
        if (isset($classInfo['dist']) && is_array($classInfo['dist'])) {
            $distances = array_merge($distances, $classInfo['dist']);
        }
    }
    $distances = array_unique($distances);
    sort($distances);
    return $distances;
}

include '../includes/header.php';
?>

<!-- Стили для модального окна регистрации -->
<style>
    .step-section {
        margin-bottom: 2rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #e9ecef;
        opacity: 0;
        animation: slideIn 0.5s ease-out forwards;
    }
    
    .step-section:last-child {
        border-bottom: none;
    }
    
    .section-title {
        color: #495057;
        font-weight: 600;
        margin-bottom: 1rem;
    }
    
    .list-group-item {
        cursor: pointer;
        border: 1px solid #dee2e6;
    }
    
    .btn-group .btn {
        flex: 1;
    }
    
    .modal-xl {
        max-width: 90%;
    }
    
    /* Увеличиваем z-index для модального окна регистрации */
    #registrationModal {
        z-index: 1060 !important;
    }
    
    #registrationModal .modal-dialog {
        z-index: 1061 !important;
    }
    
    #registrationModal .modal-backdrop {
        z-index: 1059 !important;
    }
    
    @media (max-width: 768px) {
        .modal-xl {
            max-width: 95%;
            margin: 1rem;
        }
        
        .btn-group {
            flex-direction: column;
        }
        
        .btn-group .btn {
            margin-bottom: 0.5rem;
            border-radius: 0.375rem !important;
        }
    }
    
    @keyframes slideIn {
        from { opacity: 0; transform: translateX(-20px); }
        to { opacity: 1; transform: translateX(0); }
    }
</style>

<!-- Заголовок страницы -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Календарь соревнований</h1>
    <!-- Убраны кнопки создания мероприятия и списка мероприятий -->
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
            <div class="col-md-3">
                <label for="monthFilter" class="form-label">Месяц</label>
                <select class="form-select" id="monthFilter">
                    <option value="">Все месяцы</option>
                    <?php
                    $months = [
                        '01' => 'Январь', '02' => 'Февраль', '03' => 'Март',
                        '04' => 'Апрель', '05' => 'Май', '06' => 'Июнь',
                        '07' => 'Июль', '08' => 'Август', '09' => 'Сентябрь',
                        '10' => 'Октябрь', '11' => 'Ноябрь', '12' => 'Декабрь'
                    ];
                    foreach ($months as $num => $name): ?>
                        <option value="<?= $num ?>"><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
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

<!-- Таблица календаря -->
<div class="card shadow">
    <div class="card-header bg-info text-white">
        <h6 class="m-0 font-weight-bold">Календарь мероприятий</h6>
    </div>
    <div class="card-body">
        <?php if (empty($events)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-calendar-x display-1"></i>
                <h4 class="mt-3">Нет запланированных мероприятий</h4>
                <p class="text-muted">Создайте ваше первое мероприятие</p>
                <a href="create-event.php" class="btn btn-success btn-lg">
                    <i class="bi bi-plus-circle"></i> Создать мероприятие
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="calendarTable">
                    <thead class="table-dark">
                        <tr>
                            <th width="10%">Год</th>
                            <th width="15%">Сроки проведения</th>
                            <th width="20%">Наименование соревнований</th>
                            <th width="35%">Классы, полы и дистанции</th>
                            <th width="10%">Документы</th>
                            <th width="10%">Регистрация</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): 
                            $classData = parseEventClasses($event['class_distance']);
                        ?>
                        <tr data-status="<?= htmlspecialchars($event['status']) ?>" 
                            data-date="<?= htmlspecialchars($event['merodata']) ?>">
                            <td><strong><?= extractYearFromMerodata($event['merodata']) ?></strong></td>
                            <td><?= extractPeriodFromMerodata($event['merodata']) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($event['meroname']) ?></strong>
                                <br>
                                <span class="badge bg-<?= getStatusColor($event['status']) ?>">
                                    <?= htmlspecialchars($event['status']) ?>
                                </span>
                            </td>
                            <td colspan="3">
                                <?php
                                // Красивое отображение классов, полов и дистанций
                                $classData = parseEventClasses($event['class_distance']);
                                if (!empty($classData)): ?>
                                    <div class="row">
                                        <?php foreach ($classData as $class => $details): ?>
                                            <div class="col-12 mb-2">
                                                <div class="border rounded p-2 bg-light">
                                                    <strong class="text-dark"><?= htmlspecialchars($class) ?></strong>
                                                    
                                                    <!-- Пол -->
                                                    <?php if (isset($details['sex']) && is_array($details['sex']) && !empty($details['sex'])): ?>
                                                        <div class="mt-1">
                                                            <small class="text-muted">Пол:</small>
                                                            <?php foreach ($details['sex'] as $sex): ?>
                                                                <span class="badge bg-info text-white me-1"><?= htmlspecialchars($sex) ?></span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Дистанции -->
                                                    <?php if (isset($details['dist']) && is_array($details['dist']) && !empty($details['dist'])): ?>
                                                        <div class="mt-1">
                                                            <small class="text-muted">Дистанции:</small>
                                                            <?php 
                                                            $allDistances = [];
                                                            foreach ($details['dist'] as $distString) {
                                                                if (is_string($distString) && strpos($distString, ',') !== false) {
                                                                    // Если дистанции записаны строкой через запятую
                                                                    $distances = explode(', ', $distString);
                                                                    $allDistances = array_merge($allDistances, $distances);
                                                                } else {
                                                                    $allDistances[] = $distString;
                                                                }
                                                            }
                                                            $allDistances = array_unique(array_filter($allDistances));
                                                            foreach ($allDistances as $dist): ?>
                                                                <span class="badge bg-secondary text-white me-1"><?= htmlspecialchars($dist) ?>м</span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <small class="text-muted">Нет данных о классах</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($event['filepolojenie'])): ?>
                                    <button class="btn btn-sm btn-outline-primary" title="Скачать положение" onclick="downloadDocument('<?= $event['oid'] ?>', 'polojenie')">
                                        <i class="bi bi-file-earmark-pdf"></i> Положение
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted small">Нет документов</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($event['status'] === 'Регистрация'): ?>
                                    <button class="btn btn-sm btn-success" onclick="registerForEvent(<?= $event['oid'] ?>)">
                                        <i class="bi bi-person-plus"></i> Регистрация
                                    </button>
                                <?php elseif (in_array($event['status'], ['Регистрация закрыта', 'В процессе'])): ?>
                                    <span class="text-muted">Регистрация закрыта</span>
                                <?php else: ?>
                                    <span class="text-muted"><?= htmlspecialchars($event['status']) ?></span>
                                <?php endif; ?>
                                <br>
                                <small class="text-muted">
                                    <i class="bi bi-people"></i> <?= $event['registrations_count'] ?> регистраций
                                </small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function applyFilters() {
    const statusFilter = document.getElementById("statusFilter").value;
    const monthFilter = document.getElementById("monthFilter").value;
    const searchInput = document.getElementById("searchInput").value.toLowerCase();
    const table = document.getElementById("calendarTable");
    const rows = table ? table.getElementsByTagName("tbody")[0].getElementsByTagName("tr") : [];

    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        if (!row.dataset.status) continue;
        
        const status = row.getAttribute("data-status");
        const date = row.getAttribute("data-date");
        const name = row.cells[2].textContent.toLowerCase();
        
        let showRow = true;
        
        if (statusFilter && status !== statusFilter) {
            showRow = false;
        }
        
        if (monthFilter && date) {
            const eventMonth = date.split('-')[1] || date.split('.')[1];
            if (eventMonth !== monthFilter) {
                showRow = false;
            }
        }
        
        if (searchInput && !name.includes(searchInput)) {
            showRow = false;
        }
        
        row.style.display = showRow ? "" : "none";
    }
}

function clearFilters() {
    document.getElementById("statusFilter").value = "";
    document.getElementById("monthFilter").value = "";
    document.getElementById("searchInput").value = "";
    applyFilters();
}

function registerForEvent(eventId) {
    try {
        // Удаляем существующее модальное окно если есть
        let existingModal = document.getElementById('registrationModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        // Создаем новое модальное окно
        const modal = createRegistrationModal();
        document.body.appendChild(modal);
        
        // Показываем модальное окно
        const bsModal = new bootstrap.Modal(modal, {
            backdrop: 'static',
            keyboard: false
        });
        bsModal.show();
        
        // Загружаем форму регистрации
        loadRegistrationForm(eventId, modal);
        
    } catch (error) {
        console.error('❌ Критическая ошибка:', error);
        alert(`Ошибка открытия модального окна: ${error.message}`);
    }
}

/**
 * Создание модального окна регистрации
 */
function createRegistrationModal() {
    const modal = document.createElement('div');
    modal.className = 'modal fade';
    modal.id = 'registrationModal';
    modal.tabIndex = -1;
    modal.setAttribute('aria-labelledby', 'registrationModalLabel');
    modal.setAttribute('aria-hidden', 'true');
    
    modal.innerHTML = `
        <div class="modal-dialog modal-xl" style="z-index: 1061;">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-user-plus me-2"></i>
                        Регистрация на мероприятие: <span id="eventName"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Загрузка...</span>
                        </div>
                        <p class="mt-2">Загрузка формы регистрации...</p>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    return modal;
}

/**
 * Инициализация EventRegistration при загрузке страницы
 */
document.addEventListener('DOMContentLoaded', function() {
    // Если EventRegistration загружен, но объект не создан
    if (typeof EventRegistration !== 'undefined' && typeof eventRegistration === 'undefined') {
        try {
            window.eventRegistration = new EventRegistration();
        } catch (error) {
            console.error('❌ Ошибка создания eventRegistration:', error);
        }
    }
});

/**
 * Загрузка формы регистрации через EventRegistration
 */
async function loadRegistrationForm(eventId, modal) {
    const modalBody = modal.querySelector('.modal-body');
    
    try {
        
        // Создаем структуру как у пользователя
        modalBody.innerHTML = `
            <div class="container-fluid px-0">
                <!-- Шаг 1: Выбор класса лодки -->
                <div class="step-section">
                    <h6 class="section-title">
                        <i class="bi bi-ship me-2"></i>
                        Шаг 1: Выбор класса лодки
                    </h6>
                    <div id="class-selection"></div>
                </div>
                <!-- Шаг 2: Выбор пола -->
                <div class="step-section">
                    <div id="sex-selection"></div>
                </div>
                <!-- Шаг 3: Выбор дистанции -->
                <div class="step-section">
                    <div id="distance-selection"></div>
                </div>
                <!-- Информация о типе лодки -->
                <div id="boat-type-info"></div>
                <!-- Шаг 4: Форма участников -->
                <div class="step-section">
                    <div id="participant-form"></div>
                </div>
            </div>
        `;
        
        // Устанавливаем название мероприятия
        document.getElementById('eventName').textContent = 'Загрузка...';
        
        // Проверяем, инициализирован ли объект eventRegistration
        if (typeof EventRegistration !== 'undefined' && typeof eventRegistration === 'undefined') {
            window.eventRegistration = new EventRegistration();
        }
        
        // Ждем инициализации объекта eventRegistration
        setTimeout(() => {
            if (typeof eventRegistration !== 'undefined') {
                eventRegistration.selectEvent(eventId);
            } else {
                console.error('❌ eventRegistration не инициализирован');
                modalBody.innerHTML = `
                    <div class="alert alert-danger">
                        <h6><i class="bi bi-exclamation-triangle me-2"></i>Ошибка инициализации</h6>
                        <p>Не удалось инициализировать систему регистрации</p>
                        <button class="btn btn-outline-danger btn-sm" onclick="loadRegistrationForm(${eventId}, document.getElementById('registrationModal'))">
                            <i class="bi bi-arrow-clockwise me-1"></i> Попробовать снова
                        </button>
                    </div>
                `;
            }
        }, 500);
        
    } catch (error) {
        console.error('❌ Ошибка загрузки формы регистрации:', error);
        modalBody.innerHTML = `
            <div class="alert alert-danger">
                <h6><i class="bi bi-exclamation-triangle me-2"></i>Ошибка загрузки формы</h6>
                <p>Не удалось загрузить форму регистрации: ${error.message}</p>
                <button class="btn btn-outline-danger btn-sm" onclick="loadRegistrationForm(${eventId}, document.getElementById('registrationModal'))">
                    <i class="bi bi-arrow-clockwise me-1"></i> Попробовать снова
                </button>
            </div>
        `;
    }
}

/**
 * Скачивание документа
 */
function downloadDocument(eventId, docType) {
    const url = `/lks/php/organizer/download-document.php?event_id=${eventId}&type=${docType}`;
    
    // Создаем временную ссылку для скачивания
    const link = document.createElement('a');
    link.href = url;
    link.download = '';
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    showNotification('Скачивание документа начато', 'info');
}

/**
 * Показать уведомление
 */
function showNotification(message, type = 'info') {
    // Создаем уведомление
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
    document.getElementById("monthFilter").addEventListener("change", applyFilters);
});
</script>

<?php include '../includes/footer.php'; ?> 
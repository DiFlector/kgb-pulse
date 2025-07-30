<?php
/**
 * Календарь соревнований - Пользователь
 */

// Проверка авторизации
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo 'PHP OK<br>';

require_once __DIR__ . '/../../php/helpers.php';
echo 'helpers.php OK<br>';

require_once __DIR__ . '/../../php/db/Database.php';
echo 'Database.php OK<br>';

try {
    $db = Database::getInstance();
    echo 'Database connect OK<br>';
    $stmt = $db->prepare('SELECT 1');
    $stmt->execute();
    echo 'SQL OK<br>';
} catch (Exception $e) {
    echo 'DB ERROR: ' . $e->getMessage();
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'];

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

// Получаем все мероприятия
try {
    $eventsStmt = $db->query("
        SELECT champn, meroname, merodata, status, class_distance, defcost, filepolojenie
        FROM meros 
        ORDER BY champn ASC
    ");
    $events = $eventsStmt->fetchAll();
    
    // Отладка: логируем количество найденных мероприятий
    error_log("DEBUG: Найдено мероприятий: " . count($events));
    if (empty($events)) {
        error_log("DEBUG: Мероприятия не найдены. Проверяем таблицу meros...");
        
        // Проверяем что есть в таблице meros
        $checkStmt = $db->query("SELECT COUNT(*) as total FROM meros");
        $totalEvents = $checkStmt->fetch(PDO::FETCH_ASSOC)['total'];
        error_log("DEBUG: Всего мероприятий в БД: " . $totalEvents);
        
        // Проверяем статусы
        $statusStmt = $db->query("SELECT DISTINCT status FROM meros");
        $statuses = $statusStmt->fetchAll(PDO::FETCH_COLUMN);
        error_log("DEBUG: Доступные статусы: " . implode(', ', $statuses));
    }

    // Получаем oid пользователя
    $userOidStmt = $db->prepare("SELECT oid FROM users WHERE userid = ?");
    $userOidStmt->execute([$userId]);
    $userOid = $userOidStmt->fetchColumn();
    
    // Получаем регистрации пользователя
    $userRegistrationsStmt = $db->prepare("
        SELECT m.champn, l.status as reg_status 
        FROM listreg l
        JOIN meros m ON l.meros_oid = m.oid
        WHERE l.users_oid = ?
    ");
    $userRegistrationsStmt->execute([$userOid]);
    $userRegistrations = $userRegistrationsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

} catch (Exception $e) {
    error_log("Ошибка получения мероприятий: " . $e->getMessage());
    $events = [];
    $userRegistrations = [];
}

include '../includes/header.php';
?>

<!-- Добавляем стили для модального окна регистрации -->
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

<!-- Заголовок -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Календарь соревнований</h1>
    <div>
        <button class="btn btn-outline-primary me-2" onclick="filterCurrentYear()">
            <i class="bi bi-calendar-check me-1"></i>Текущий год
        </button>
        <a href="index.php" class="btn btn-success">
            <i class="bi bi-person-check me-1"></i>Мои регистрации
        </a>
    </div>
</div>

<!-- Фильтры -->
<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Год</label>
                <select class="form-select" id="yearFilter">
                    <option value="">Все годы</option>
                    <?php 
                    $currentYear = date('Y');
                    for ($year = $currentYear + 1; $year >= 2020; $year--): 
                    ?>
                        <option value="<?= $year ?>" <?= $year == $currentYear ? 'selected' : '' ?>>
                            <?= $year ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Статус</label>
                <select class="form-select" id="statusFilter">
                    <option value="">Все статусы</option>
                    <option value="Регистрация">Открыта регистрация</option>
                    <option value="Регистрация закрыта">Регистрация закрыта</option>
                    <option value="В процессе">Проводится</option>
                    <option value="Завершено">Завершено</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Поиск</label>
                <input type="text" class="form-control" id="searchFilter" 
                       placeholder="Название соревнований...">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-outline-secondary w-100" onclick="clearFilters()">
                    <i class="bi bi-x-circle me-1"></i>Очистить
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Календарь соревнований -->
<div class="card">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="bi bi-calendar-range me-2"></i>Календарь соревнований
        </h5>
    </div>
    <div class="card-body">
        <?php if (empty($events)): ?>
            <div class="text-center py-5">
                <i class="bi bi-calendar-x display-1 text-muted"></i>
                <h4 class="mt-3 text-muted">Мероприятий не найдено</h4>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover" id="calendarTable">
                    <thead class="table-light">
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
                        <?php foreach ($events as $event): ?>
                            <?php 
                            $year = extractEventYear($event['merodata']);
                            $classDistance = json_decode($event['class_distance'], true);
                            $classes = array_keys($classDistance ?? []);
                            $genders = [];
                            $distances = [];
                            foreach ($classDistance ?? [] as $classData) {
                                if (isset($classData['sex'])) {
                                    $genders = array_merge($genders, $classData['sex']);
                                }
                                if (isset($classData['dist'])) {
                                    $distances = array_merge($distances, $classData['dist']);
                                }
                            }
                            $genders = array_unique($genders);
                            $distances = array_unique($distances);
                            sort($distances);
                            $eventDate = $event['merodata'];
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($year) ?></td>
                                <td><?= htmlspecialchars($eventDate) ?></td>
                                <td><strong><?= htmlspecialchars($event['meroname']) ?></strong></td>
                                <td>
                                    <?php if (!empty($classDistance)): ?>
                                        <div class="row">
                                            <?php foreach ($classDistance as $class => $details): ?>
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
                                    <?php if ($event['filepolojenie']): ?>
                                        <a href="<?= htmlspecialchars($event['filepolojenie']) ?>" class="btn btn-outline-primary btn-sm" target="_blank">
                                            <i class="bi bi-file-earmark-pdf"></i> Положение
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $isRegistered = isset($userRegistrations[$event['champn']]);
                                    ?>
                                    <?php if ($event['status'] === 'Регистрация' && !$isRegistered): ?>
                                        <button class="btn btn-success btn-sm register-btn" data-event-id="<?= $event['champn'] ?>">
                                            <i class="bi bi-person-plus"></i>
                                        </button>
                                    <?php elseif ($isRegistered): ?>
                                        <span class="badge bg-success">
                                            <i class="bi bi-check-circle"></i>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-<?= getStatusColor($event['status']) ?>">
                                            <?= getStatusText($event['status']) ?>
                                        </span>
                                    <?php endif; ?>
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
// Фильтрация таблицы
function filterTable() {
    const yearFilter = document.getElementById('yearFilter').value;
    const statusFilter = document.getElementById('statusFilter').value;
    const searchFilter = document.getElementById('searchFilter').value.toLowerCase();
    
    const rows = document.querySelectorAll('#calendarTable tbody tr');
    
    rows.forEach(row => {
        const year = row.cells[0].textContent.trim();
        const status = row.cells[3].textContent.trim();
        const name = row.cells[2].textContent.trim().toLowerCase();
        
        const yearMatch = !yearFilter || year === yearFilter;
        const statusMatch = !statusFilter || status === statusFilter;
        const nameMatch = !searchFilter || name.includes(searchFilter);
        
        if (yearMatch && statusMatch && nameMatch) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function filterCurrentYear() {
    document.getElementById('yearFilter').value = new Date().getFullYear();
    filterTable();
}

function clearFilters() {
    document.getElementById('yearFilter').value = '';
    document.getElementById('statusFilter').value = '';
    document.getElementById('searchFilter').value = '';
    filterTable();
}

// Функция для исправления наложения модальных окон
function fixModalLayering() {
    // Находим все открытые модальные окна
    const openModals = document.querySelectorAll('.modal.show');
    
    if (openModals.length > 1) {
        // Устанавливаем z-index для каждого модального окна
        openModals.forEach((modal, index) => {
            const baseZIndex = 1050;
            const zIndexIncrement = 10;
            const newZIndex = baseZIndex + (index * zIndexIncrement);
            
            modal.style.zIndex = newZIndex.toString();
            
            // Если это модальное окно регистрации, устанавливаем самый высокий z-index
            if (modal.id === 'registrationModal') {
                modal.style.zIndex = (baseZIndex + (openModals.length * zIndexIncrement)).toString();
            }
        });
    }
}

function registerToEvent(champn) {
    try {
        // Удаляем существующее модальное окно если есть
        let existingModal = document.getElementById('registrationModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        // Удаляем все существующие backdrop'ы перед созданием нового модального окна
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => {
            backdrop.style.zIndex = '1040'; // Понижаем z-index существующих backdrops
        });
        
        // Создаем новое модальное окно
        const modalElement = createRegistrationModal();
        document.body.appendChild(modalElement);
        
        // Открываем модальное окно
        const modal = new bootstrap.Modal(modalElement, {
            backdrop: 'static',
            keyboard: false
        });
        
        // Устанавливаем высокий z-index для модального окна
        modalElement.style.zIndex = '1060';
        
        modal.show();
        
        // Исправляем наложение модальных окон
        setTimeout(fixModalLayering, 100);
        
        // Устанавливаем название мероприятия
        document.getElementById('eventName').textContent = 'Загрузка...';
        
        // Проверяем, инициализирован ли объект eventRegistration
        if (typeof EventRegistration !== 'undefined' && typeof eventRegistration === 'undefined') {
            window.eventRegistration = new EventRegistration();
        }
        
        // Ждем инициализации объекта eventRegistration
        setTimeout(() => {
            if (typeof eventRegistration !== 'undefined') {
                eventRegistration.selectEvent(champn);
            } else {
                console.error('❌ eventRegistration не инициализирован');
                document.querySelector('.modal-body').innerHTML = `
                    <div class="alert alert-danger">
                        <h6><i class="fas fa-exclamation-triangle me-2"></i>Ошибка инициализации</h6>
                        <p>Не удалось инициализировать систему регистрации</p>
                        <button class="btn btn-outline-danger btn-sm" onclick="registerToEvent(${champn})">
                            <i class="fas fa-sync-alt me-1"></i> Попробовать снова
                        </button>
                    </div>
                `;
            }
        }, 500);
    } catch (error) {
        console.error('❌ Критическая ошибка:', error);
        alert(`Ошибка открытия модального окна: ${error.message}`);
    }
}

function createRegistrationModal() {
    const modal = document.createElement('div');
    modal.className = 'modal fade';
    modal.id = 'registrationModal';
    modal.tabIndex = -1;
    modal.setAttribute('aria-hidden', 'true');
    modal.style.zIndex = '1060';
    
    modal.innerHTML = `
        <div class="modal-dialog modal-xl" style="z-index: 1061;">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus me-2"></i>
                        Регистрация на мероприятие: <span id="eventName"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                </div>
                <div class="modal-body">
                    <div class="container-fluid px-0">
                        <!-- Шаг 1: Выбор класса лодки -->
                        <div class="step-section">
                            <h6 class="section-title">
                                <i class="fas fa-ship me-2"></i>
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
                </div>
            </div>
        </div>
    `;
    
    return modal;
}

// Добавляем обработчики фильтров
document.getElementById('yearFilter').addEventListener('change', filterTable);
document.getElementById('statusFilter').addEventListener('change', filterTable);
document.getElementById('searchFilter').addEventListener('input', filterTable);

// Добавляем обработчики для кнопок регистрации
document.addEventListener('DOMContentLoaded', function() {
    // Находим все кнопки регистрации
    const registerButtons = document.querySelectorAll('.register-btn');
    
    // Добавляем обработчик для каждой кнопки
    registerButtons.forEach(button => {
        button.addEventListener('click', function(event) {
            event.preventDefault();
            const eventId = this.getAttribute('data-event-id');
            if (eventId) {
                registerToEvent(eventId);
            }
        });
    });
});

// Инициализация - показываем текущий год
filterCurrentYear();

// Полностью отключаем backdrop для всех модальных окон
document.addEventListener('DOMContentLoaded', function() {
    const originalModalShow = bootstrap.Modal.prototype.show;
    bootstrap.Modal.prototype.show = function() {
        this._config.backdrop = false; // Отключаем backdrop
        this._config.keyboard = false; // Отключаем закрытие по ESC
        return originalModalShow.call(this);
    };
    
    // Удаляем все существующие backdrop'ы
    function removeBackdrops() {
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => backdrop.remove());
    }
    
    // Проверяем и удаляем backdrop'ы регулярно
    setInterval(removeBackdrops, 100);
    
    // Добавляем обработчик для закрытия модального окна регистрации
    document.body.addEventListener('hidden.bs.modal', function(event) {
        if (event.target.id === 'registrationModal') {
            removeBackdrops();
            
            // Восстанавливаем z-index для других модальных окон
            const otherModals = document.querySelectorAll('.modal:not(#registrationModal)');
            otherModals.forEach(modal => {
                modal.style.zIndex = ''; // Сбрасываем z-index
            });
        }
    });
    
    // Добавляем обработчик для показа модального окна
    document.body.addEventListener('shown.bs.modal', function(event) {
        fixModalLayering();
    });
    
    // Добавляем обработчик изменения размера окна
    window.addEventListener('resize', fixModalLayering);
    
    // Исправляем наложение модальных окон при загрузке страницы
    setTimeout(fixModalLayering, 500);
    
    // Если EventRegistration загружен, но объект не создан
    if (typeof EventRegistration !== 'undefined' && typeof eventRegistration === 'undefined') {
        try {
            window.eventRegistration = new EventRegistration();
        } catch (error) {
            console.error('❌ Ошибка создания eventRegistration:', error);
        }
    }
});
</script>

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

function getStatusText($status) {
    switch($status) {
        case 'В ожидании': return 'Ожидание';
        case 'Регистрация': return 'Открыта';
        case 'Регистрация закрыта': return 'Закрыта';
        case 'В процессе': return 'Проводится';
        case 'Результаты': return 'Результаты';
        case 'Завершено': return 'Завершено';
        default: return $status;
    }
}

include '../includes/footer.php';
?> 
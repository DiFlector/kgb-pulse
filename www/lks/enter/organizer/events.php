<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Organizer' && $_SESSION['user_role'] !== 'SuperUser' && $_SESSION['user_role'] !== 'Admin')) {
    header('Location: /lks/login.php');
    exit;
}

require_once '../../php/db/Database.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'];

// Получение мероприятий организатора
try {
    // Логика доступа по ролям:
    // SuperUser, главные админы (1-10), обычные админы (11-50) - полный доступ
    // Главные организаторы (51-100) - полный доступ  
    // Главные секретари (151-200) - полный доступ
    // Остальные - только свои мероприятия
    $hasFullAccess = $_SESSION['user_role'] === 'SuperUser' ||
                    ($_SESSION['user_role'] === 'Admin' && $userId >= 1 && $userId <= 50) ||
                    ($_SESSION['user_role'] === 'Organizer' && $userId >= 51 && $userId <= 100) ||
                    ($_SESSION['user_role'] === 'Secretary' && $userId >= 151 && $userId <= 200);
    
    if ($hasFullAccess) {
        $eventsStmt = $db->prepare("
            SELECT oid, meroname, merodata, status, defcost, class_distance, filepolojenie,
                   (SELECT COUNT(*) FROM listreg l JOIN meros m2 ON l.meros_oid = m2.oid WHERE m2.oid = m.oid) as registrations_count,
                   created_by
            FROM meros m 
            ORDER BY oid DESC
        ");
        $eventsStmt->execute();
    } else {
        $eventsStmt = $db->prepare("
            SELECT oid, meroname, merodata, status, defcost, class_distance, filepolojenie,
                   (SELECT COUNT(*) FROM listreg l JOIN meros m2 ON l.meros_oid = m2.oid WHERE m2.oid = m.oid) as registrations_count,
                   created_by
            FROM meros m 
            WHERE created_by = ? 
            ORDER BY oid DESC
        ");
        $eventsStmt->execute([$userId]);
    }
    $events = $eventsStmt->fetchAll();
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

<style>
/* Стили для disabled кнопок */
.btn.disabled, .btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.btn.disabled:hover, .btn:disabled:hover {
    opacity: 0.5;
    transform: none;
}
</style>

<!-- Заголовок страницы -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">
        <?php if ($hasFullAccess): ?>
            Все мероприятия
            <?php if ($_SESSION['user_role'] === 'SuperUser'): ?>
                <small class="text-muted">(Суперпользователь)</small>
            <?php elseif ($_SESSION['user_role'] === 'Admin' && $userId >= 1 && $userId <= 10): ?>
                <small class="text-muted">(Главный администратор)</small>
            <?php elseif ($_SESSION['user_role'] === 'Admin' && $userId >= 11 && $userId <= 50): ?>
                <small class="text-muted">(Администратор)</small>
            <?php elseif ($_SESSION['user_role'] === 'Organizer' && $userId >= 51 && $userId <= 100): ?>
                <small class="text-muted">(Главный организатор)</small>
            <?php elseif ($_SESSION['user_role'] === 'Secretary' && $userId >= 151 && $userId <= 200): ?>
                <small class="text-muted">(Главный секретарь)</small>
            <?php endif; ?>
        <?php else: ?>
            Мои мероприятия
        <?php endif; ?>
    </h1>
    <div class="btn-group">
        <a href="create-event.php" class="btn btn-success">
            <i class="bi bi-plus-circle"></i> Создать мероприятие
        </a>
        <a href="calendar.php" class="btn btn-primary">
            <i class="bi bi-calendar-event"></i> Календарь
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
                    <option value="Перенесено">Перенесено</option>
                    <option value="Результаты">Результаты</option>
                    <option value="Завершено">Завершено</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="yearFilter" class="form-label">Год</label>
                <select class="form-select" id="yearFilter">
                    <option value="">Все годы</option>
                    <?php for($year = date('Y'); $year >= 2020; $year--): ?>
                        <option value="<?= $year ?>"><?= $year ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-5">
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
                <h4 class="mt-3">Нет созданных мероприятий</h4>
                <p class="text-muted">Создайте ваше первое мероприятие, чтобы начать работу</p>
                <a href="create-event.php" class="btn btn-success btn-lg">
                    <i class="bi bi-plus-circle"></i> Создать мероприятие
                </a>
            </div>
        <?php else: ?>
            <div id="eventsContainer">
                <?php 
                function extractYear($merodata) {
                    if (preg_match('/(\d{4})/', $merodata, $matches)) {
                        return $matches[1];
                    }
                    return date('Y');
                }
                
                function parseClassDistance($classDistance) {
                    if (empty($classDistance)) return [];
                    
                    $decoded = json_decode($classDistance, true);
                    if (!$decoded) return [];
                    
                    return $decoded;
                }
                
                foreach ($events as $event): 
                    $classData = parseClassDistance($event['class_distance']);
                    $year = extractYear($event['merodata']);
                    
                    // Проверяем права на редактирование этого конкретного мероприятия
                    $canEdit = $hasFullAccess || ($event['created_by'] == $userId);
                ?>
                <div class="mb-4 event-card" 
                     data-status="<?= htmlspecialchars($event['status']) ?>"
                     data-year="<?= htmlspecialchars($year) ?>"
                     data-name="<?= htmlspecialchars(strtolower($event['meroname'])) ?>">
                    <div class="card border-left-<?= getStatusColor($event['status']) ?> shadow-sm">
                        <div class="row g-0">
                            <!-- Левая часть: Основная информация -->
                            <div class="col-md-9">
                                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                    <div>
                                        <small class="text-muted">ID: <?= htmlspecialchars($event['oid']) ?></small>
                                        <span class="badge bg-<?= getStatusColor($event['status']) ?> ms-2">
                                            <?= htmlspecialchars($event['status']) ?>
                                        </span>
                                        <?php if ($hasFullAccess): ?>
                                            <small class="text-muted ms-2">Организатор: <?= htmlspecialchars($event['created_by']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($year) ?></span>
                                </div>
                                <div class="card-body">
                                    <h5 class="card-title text-primary mb-3">
                                        <?= htmlspecialchars($event['meroname']) ?>
                                    </h5>
                                    
                                    <div class="row">
                                        <div class="col-md-4">
                                            <!-- Сроки проведения -->
                                            <div class="mb-3">
                                                <strong class="text-dark">Сроки проведения:</strong><br>
                                                <span class="text-info"><?= htmlspecialchars($event['merodata']) ?></span>
                                            </div>
                                            
                                            <!-- Участники и стоимость -->
                                            <div class="mb-3">
                                                <strong class="text-dark">Регистраций:</strong> 
                                                <span class="badge bg-info"><?= $event['registrations_count'] ?></span>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <strong class="text-dark">Стоимость:</strong> 
                                                <span class="text-success fw-bold"><?= htmlspecialchars($event['defcost']) ?> ₽</span>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-8">
                                            <!-- Дисциплины -->
                                            <?php if (!empty($classData)): ?>
                                            <div class="mb-3">
                                                <strong class="text-dark">Дисциплины:</strong>
                                                <div class="mt-2">
                                                    <?php foreach ($classData as $class => $details): ?>
                                                        <div class="mb-3 p-3 border rounded bg-light">
                                                            <h6 class="text-primary mb-2 fw-bold"><?= htmlspecialchars($class) ?></h6>
                                                            <?php if (isset($details['sex']) && isset($details['dist']) && 
                                                                      is_array($details['sex']) && is_array($details['dist'])): ?>
                                                                <div class="row">
                                                                    <?php 
                                                                    $sexCount = count($details['sex']);
                                                                    $distCount = count($details['dist']);
                                                                    
                                                                    // Проходим по каждому полу
                                                                    for ($i = 0; $i < max($sexCount, $distCount); $i++): 
                                                                        $sex = isset($details['sex'][$i]) ? $details['sex'][$i] : (isset($details['sex'][0]) ? $details['sex'][0] : 'М');
                                                                        $distances = '';
                                                                        
                                                                        if (isset($details['dist'][$i])) {
                                                                            if (is_array($details['dist'][$i])) {
                                                                                $distances = implode(', ', $details['dist'][$i]);
                                                                            } else {
                                                                                $distances = $details['dist'][$i];
                                                                            }
                                                                        }
                                                                    ?>
                                                                    <div class="col-md-6 mb-2">
                                                                        <div class="d-flex align-items-center">
                                                                            <span class="badge bg-<?= $sex === 'М' ? 'primary' : ($sex === 'Ж' ? 'danger' : 'secondary') ?> me-2" style="min-width: 25px;"><?= htmlspecialchars($sex) ?></span>
                                                                            <span class="text-muted small"><?= htmlspecialchars($distances) ?></span>
                                                                        </div>
                                                                    </div>
                                                                    <?php endfor; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Правая часть: Действия и документы -->
                            <div class="col-md-3 border-start">
                                <div class="card-body h-100 d-flex flex-column justify-content-between">
                                    <!-- Документы -->
                                    <div class="mb-3">
                                        <h6 class="text-dark mb-2">Документы:</h6>
                                        <?php if (!empty($event['filepolojenie'])): ?>
                                            <a href="/lks/<?= htmlspecialchars($event['filepolojenie']) ?>" 
                                               class="btn btn-sm btn-outline-info w-100 mb-2" target="_blank">
                                                <i class="bi bi-file-earmark-text"></i> Скачать положение
                                            </a>
                                        <?php else: ?>
                                            <p class="text-muted small mb-2">Документы не загружены</p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Основные действия -->
                                    <div class="mb-3">
                                        <h6 class="text-dark mb-2">Действия:</h6>
                                        <div class="d-grid gap-2">
                                            <button class="btn btn-sm btn-outline-primary <?= !$canEdit ? 'disabled' : '' ?>" 
                                                    onclick="<?= $canEdit && !empty($event['oid']) ? 'editEvent(' . intval($event['oid']) . ')' : 'showAccessDenied()' ?>"
                                                    <?= !$canEdit ? 'disabled' : '' ?>
                                                    data-event-id="<?= htmlspecialchars($event['oid']) ?>">
                                                <i class="bi bi-pencil"></i> Редактировать
                                            </button>
                                            <a href="/lks/enter/organizer/registrations.php?event=<?= $event['oid'] ?>" 
                                               class="btn btn-sm btn-outline-info">
                                                <i class="bi bi-people"></i> Регистрации (<?= $event['registrations_count'] ?>)
                                            </a>
                                            <button class="btn btn-sm btn-outline-success" onclick="exportEvent(<?= $event['oid'] ?>)">
                                                <i class="bi bi-download"></i> Экспорт в Excel
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- Статус-специфичные действия -->
                                    <div class="mb-3">
                                        <?php if ($event['status'] === 'Регистрация'): ?>
                                            <button class="btn btn-sm btn-warning w-100 mb-2 <?= !$canEdit ? 'disabled' : '' ?>" 
                                                    onclick="<?= $canEdit ? 'closeRegistration(' . $event['oid'] . ')' : 'showAccessDenied()' ?>"
                                                    <?= !$canEdit ? 'disabled' : '' ?>>
                                                <i class="bi bi-stop-circle"></i> Закрыть регистрацию
                                            </button>
                                        <?php endif; ?>
                                        
                                        <button class="btn btn-sm btn-outline-secondary w-100 mb-2 <?= !$canEdit ? 'disabled' : '' ?>" 
                                                onclick="<?= $canEdit ? 'changeStatus(' . $event['oid'] . ')' : 'showAccessDenied()' ?>"
                                                <?= !$canEdit ? 'disabled' : '' ?>>
                                            <i class="bi bi-gear"></i> Изменить статус
                                        </button>
                                    </div>
                                    
                                    <!-- Опасные действия -->
                                    <div class="border-top pt-3">
                                        <button class="btn btn-sm btn-outline-danger w-100 <?= !$canEdit ? 'disabled' : '' ?>" 
                                                onclick="<?= $canEdit ? 'deleteEvent(' . $event['oid'] . ', \'' . addslashes(htmlspecialchars($event['meroname'])) . '\')' : 'showAccessDenied()' ?>"
                                                <?= !$canEdit ? 'disabled' : '' ?>>
                                            <i class="bi bi-trash"></i> Удалить мероприятие
                                        </button>
                                    </div>
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

function clearFilters() {
    document.getElementById("statusFilter").value = "";
    document.getElementById("yearFilter").value = "";
    document.getElementById("searchInput").value = "";
    applyFilters();
}

function editEvent(eventId) {
    // Переход на страницу редактирования мероприятия
    window.location.href = "/lks/enter/organizer/create-event.php?edit=" + eventId;
}

function exportEvent(eventId) {
    // Экспорт регистраций мероприятия в Excel (используем organizer экспорт)
    window.location.href = "/lks/php/organizer/export-registrations.php?event=" + eventId;
    showNotification("Экспорт регистраций мероприятия начат", "info");
}

function deleteEvent(eventId, eventName) {
    // Подтверждение удаления
    if (!confirm(`Вы действительно хотите удалить мероприятие "${eventName}"?\n\nВНИМАНИЕ: Это действие нельзя отменить!\nВсе связанные регистрации также будут удалены.`)) {
        return;
    }
    
    // Дополнительное подтверждение
    if (!confirm("Подтвердите удаление еще раз. Это действие необратимо!")) {
        return;
    }
    
    // Показываем индикатор загрузки - ищем кнопку по eventId
    const deleteButton = document.querySelector(`button[onclick*="deleteEvent(${eventId},"]`);
    if (deleteButton) {
        deleteButton.disabled = true;
        deleteButton.innerHTML = '<i class="bi bi-hourglass-split"></i> Удаление...';
    }
    
    // Отправляем запрос на удаление
    fetch('/lks/php/organizer/delete-event.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            event_id: eventId
        })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
    })
    .then(text => {
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Ответ сервера не является валидным JSON:', text);
            throw new Error('Сервер вернул некорректный ответ');
        }
        
        if (data.success) {
            showNotification("Мероприятие успешно удалено", "success");
            // Ищем карточку мероприятия по более надежному селектору
            const eventCards = document.querySelectorAll('.event-card');
            eventCards.forEach(card => {
                // Ищем карточку, которая содержит кнопку с нашим eventId
                const cardDeleteButton = card.querySelector(`button[onclick*="deleteEvent(${eventId},"]`);
                if (cardDeleteButton) {
                    card.style.transition = 'opacity 0.3s ease';
                    card.style.opacity = '0';
                    setTimeout(() => {
                        card.remove();
                    }, 300);
                }
            });
        } else {
            throw new Error(data.error || data.message || 'Неизвестная ошибка');
        }
    })
    .catch(error => {
        console.error('Ошибка при удалении мероприятия:', error);
        showNotification("Ошибка при удалении: " + error.message, "error");
        
        // Восстанавливаем кнопку
        if (deleteButton) {
            deleteButton.disabled = false;
            deleteButton.innerHTML = '<i class="bi bi-trash"></i> Удалить мероприятие';
        }
    });
}

function changeStatus(eventId) {
    // Показать модальное окно для изменения статуса
    const statuses = [
        'В ожидании',
        'Регистрация', 
        'Регистрация закрыта',
        'Перенесено',
        'Результаты',
        'Завершено'
    ];
    
    let options = statuses.map(status => `<option value="${status}">${status}</option>`).join('');
    
    const html = `
        <div class="form-group">
            <label for="newStatus">Выберите новый статус:</label>
            <select class="form-control" id="newStatus">
                ${options}
            </select>
        </div>
    `;
    
    if (confirm("Изменить статус мероприятия?")) {
        // Временное решение через prompt
        const newStatus = prompt("Введите новый статус:\n" + statuses.join('\n'));
        if (newStatus && statuses.includes(newStatus)) {
            updateEventStatus(eventId, newStatus);
        }
    }
}

/**
 * Обновление статуса мероприятия
 */
function updateEventStatus(eventId, newStatus) {
    fetch('/lks/php/organizer/update_event_status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            eventId: eventId,
            status: newStatus
        })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
    })
    .then(text => {
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Ответ сервера не является валидным JSON:', text);
            throw new Error('Сервер вернул некорректный ответ: ' + text.substring(0, 100));
        }
        
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
        showNotification('Ошибка изменения статуса мероприятия: ' + error.message, 'error');
        console.error('Error:', error);
    });
}

/**
 * Показать сообщение о недостатке прав доступа
 */
function showAccessDenied() {
    showNotification("У вас нет прав для выполнения этого действия", "error");
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

function closeRegistration(eventId) {
    if (confirm("Закрыть регистрацию на мероприятие?")) {
        updateEventStatus(eventId, 'Регистрация закрыта');
    }
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
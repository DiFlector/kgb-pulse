<?php
session_start();

// Проверяем авторизацию
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Organizer' && $_SESSION['user_role'] !== 'SuperUser' && $_SESSION['user_role'] !== 'Admin')) {
    header('Location: /lks/login.php');
    exit;
}

require_once __DIR__ . '/../../php/db/Database.php';

$db = Database::getInstance();
$user = [
    'userid' => $_SESSION['user_id'],
    'fio' => $_SESSION['user_name'] ?? 'Пользователь',
    'role' => $_SESSION['user_role']
];

// Настройки страницы
$pageTitle = 'Очередь спортсменов';
$pageHeader = 'Очередь спортсменов';
$showBreadcrumb = true;
$breadcrumb = [
    ['href' => '/lks/enter/organizer/', 'title' => 'Организатор'],
    ['href' => '#', 'title' => 'Очередь спортсменов']
];

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <!-- Основной контент -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">
            <i class="fas fa-clock me-2"></i>Очередь спортсменов
        </h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <button type="button" class="btn btn-primary" onclick="refreshData()">
                <i class="fas fa-sync-alt"></i> Обновить
            </button>
        </div>
    </div>

    <!-- Фильтры и поиск -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h6 class="m-0 font-weight-bold">Поиск и фильтры</h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <label for="searchInput" class="form-label">Поиск</label>
                    <input type="text" class="form-control" id="searchInput" 
                           placeholder="ФИО или номер спортсмена" onkeyup="filterData()">
                </div>
                <div class="col-md-4">
                    <label for="eventFilter" class="form-label">Мероприятие</label>
                    <select class="form-select" id="eventFilter" onchange="filterData()">
                        <option value="">Все мероприятия</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="sortFilter" class="form-label">Сортировка</label>
                    <select class="form-select" id="sortFilter" onchange="filterData()">
                        <option value="fio">По ФИО</option>
                        <option value="userid">По номеру спортсмена</option>
                        <option value="status">По статусу регистрации</option>
                        <option value="oplata">По статусу оплаты</option>
                        <option value="event">По мероприятию</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Индикатор загрузки -->
    <div class="loading-spinner text-center py-4">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Загрузка...</span>
        </div>
        <p class="mt-2">Загрузка данных...</p>
    </div>

    <!-- Таблица с данными -->
    <div class="table-responsive">
        <table class="table table-striped table-hover" id="queueTable">
            <thead class="table-dark">
                <tr>
                    <th>№</th>
                    <th>ФИО</th>
                    <th>Номер спортсмена</th>
                    <th>Мероприятие</th>
                    <th>Статус регистрации</th>
                    <th>Оплата</th>
                    <th>Команда</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody id="queueTableBody">
                <!-- Данные будут загружены через JavaScript -->
            </tbody>
        </table>
    </div>
</div>

<style>
    .team-card {
        border-left: 4px solid #dc3545;
        background: #fff8f8;
    }
    .queue-card {
        border-left: 4px solid #ffc107;
        background: #fffbf0;
    }
    .participant-row {
        border-bottom: 1px solid #eee;
        padding: 10px 0;
    }
    .participant-row:last-child {
        border-bottom: none;
    }
    .role-badge {
        font-size: 0.75rem;
    }
    .loading-spinner {
        display: none;
    }
</style>

<script>
    // Загружаем данные при загрузке страницы
    document.addEventListener('DOMContentLoaded', function() {
        loadQueueData();
        loadEvents();
    });

    // Загрузка данных очереди
    async function loadQueueData() {
        const spinner = document.querySelector('.loading-spinner');
        const tableBody = document.getElementById('queueTableBody');
        
        spinner.style.display = 'block';
        tableBody.innerHTML = '';
        
        try {
            const response = await fetch('/lks/php/organizer/get_queue.php');
            const data = await response.json();
            
            if (data.success) {
                displayQueueData(data.queue);
            } else {
                showError(data.error || 'Ошибка загрузки данных');
            }
        } catch (error) {
            showError('Ошибка соединения: ' + error.message);
            console.error('Error:', error);
        } finally {
            spinner.style.display = 'none';
        }
    }

    // Загрузка списка мероприятий для фильтра
    async function loadEvents() {
        try {
            const response = await fetch('/lks/php/organizer/get_events.php');
            const data = await response.json();
            
            if (data.success) {
                const eventFilter = document.getElementById('eventFilter');
                data.events.forEach(event => {
                    const option = document.createElement('option');
                    option.value = event.champn;
                    option.textContent = event.meroname;
                    eventFilter.appendChild(option);
                });
            }
        } catch (error) {
            console.error('Ошибка загрузки мероприятий:', error);
        }
    }

    // Отображение данных очереди
    function displayQueueData(queueData) {
        const tableBody = document.getElementById('queueTableBody');
        tableBody.innerHTML = '';
        
        if (!queueData || queueData.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="8" class="text-center">Нет данных для отображения</td></tr>';
            return;
        }
        
        queueData.forEach((item, index) => {
            const row = document.createElement('tr');
            row.className = item.is_team ? 'team-card' : 'queue-card';
            
            row.innerHTML = `
                <td>${index + 1}</td>
                <td>${escapeHtml(item.fio)}</td>
                <td>${item.userid}</td>
                <td>${escapeHtml(item.meroname)}</td>
                <td>
                    <span class="badge bg-${getStatusColor(item.status)}">${item.status}</span>
                </td>
                <td>
                    <span class="badge bg-${item.oplata ? 'success' : 'warning'}">
                        ${item.oplata ? 'Оплачено' : 'Не оплачено'}
                    </span>
                </td>
                <td>${item.teamname ? escapeHtml(item.teamname) : '-'}</td>
                <td>
                    <div class="btn-group btn-group-sm" role="group">
                        ${getActionButtons(item)}
                    </div>
                </td>
            `;
            
            tableBody.appendChild(row);
        });
    }

    // Получение цвета статуса
    function getStatusColor(status) {
        const colors = {
            'В очереди': 'secondary',
            'Подтверждён': 'info',
            'Зарегистрирован': 'success',
            'Ожидание команды': 'warning',
            'Неявка': 'danger',
            'Дисквалифицирован': 'dark'
        };
        return colors[status] || 'secondary';
    }

    // Получение кнопок действий
    function getActionButtons(item) {
        let buttons = '';
        
        if (item.status === 'В очереди') {
            buttons += `
                <button type="button" class="btn btn-success btn-sm" 
                        onclick="confirmRegistration(${item.userid}, ${item.champn})" 
                        title="Подтвердить регистрацию">
                    <i class="fas fa-check"></i>
                </button>
            `;
        }
        
        if (item.status === 'Подтверждён' && !item.oplata) {
            buttons += `
                <button type="button" class="btn btn-primary btn-sm" 
                        onclick="confirmPayment(${item.userid}, ${item.champn})" 
                        title="Подтвердить оплату">
                    <i class="fas fa-credit-card"></i>
                </button>
            `;
        }
        
        if (item.is_team && item.status === 'В очереди') {
            buttons += `
                <button type="button" class="btn btn-info btn-sm" 
                        onclick="confirmTeam(${item.teamid}, ${item.champn})" 
                        title="Подтвердить команду">
                    <i class="fas fa-users"></i>
                </button>
            `;
        }
        
        if (item.is_team && item.status === 'Подтверждён' && !item.oplata) {
            buttons += `
                <button type="button" class="btn btn-warning btn-sm" 
                        onclick="confirmTeamPayment(${item.teamid}, ${item.champn})" 
                        title="Подтвердить оплату команды">
                    <i class="fas fa-credit-card"></i>
                </button>
            `;
        }
        
        return buttons || '<span class="text-muted">Нет действий</span>';
    }

    // Фильтрация данных
    function filterData() {
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        const eventFilter = document.getElementById('eventFilter').value;
        const sortFilter = document.getElementById('sortFilter').value;
        
        // Здесь можно добавить логику фильтрации
        // Пока просто перезагружаем данные
        loadQueueData();
    }

    // Обновление данных
    function refreshData() {
        loadQueueData();
    }

    // Подтверждение регистрации
    async function confirmRegistration(userid, champn) {
        if (!confirm('Подтвердить регистрацию спортсмена?')) {
            return;
        }

        try {
            const response = await fetch('/lks/php/organizer/confirm_registration.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `userid=${userid}&champn=${champn}`
            });
            
            const result = await response.json();
            
            if (result.success) {
                showSuccess('Регистрация подтверждена');
                loadQueueData(); // Перезагружаем данные
            } else {
                showError(result.error || result.message || 'Ошибка при подтверждении регистрации');
            }
        } catch (error) {
            showError('Ошибка соединения: ' + error.message);
            console.error('Error:', error);
        }
    }

    // Подтверждение оплаты
    async function confirmPayment(userid, champn) {
        if (!confirm('Подтвердить оплату спортсмена?')) {
            return;
        }

        try {
            const response = await fetch('/lks/php/organizer/confirm_payment.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `userid=${userid}&champn=${champn}`
            });
            
            const result = await response.json();
            
            if (result.success) {
                showSuccess('Оплата подтверждена');
                loadQueueData(); // Перезагружаем данные
            } else {
                showError(result.error || result.message || 'Ошибка при подтверждении оплаты');
            }
        } catch (error) {
            showError('Ошибка соединения: ' + error.message);
            console.error('Error:', error);
        }
    }

    // Подтверждение команды организатором
    async function confirmTeam(teamid, champn) {
        if (!confirm('Подтвердить участие всей команды в мероприятии?')) {
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
                showSuccess(`Команда подтверждена. ${result.confirmed_count} участников уведомлены.`);
                loadQueueData(); // Перезагружаем данные
            } else {
                showError(result.error || result.message || 'Ошибка при подтверждении команды');
            }
        } catch (error) {
            showError('Ошибка соединения: ' + error.message);
            console.error('Error:', error);
        }
    }

    // Подтверждение оплаты команды организатором
    async function confirmTeamPayment(teamid, champn) {
        if (!confirm('Подтвердить оплату всей команды?')) {
            return;
        }

        try {
            const response = await fetch('/lks/php/organizer/confirm_team_payment.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `teamid=${teamid}&champn=${champn}`
            });
            
            const result = await response.json();
            
            if (result.success) {
                showSuccess(`Оплата команды подтверждена. ${result.paid_count} участников уведомлены.`);
                loadQueueData(); // Перезагружаем данные
            } else {
                showError(result.error || result.message || 'Ошибка при подтверждении оплаты команды');
            }
        } catch (error) {
            showError('Ошибка соединения: ' + error.message);
            console.error('Error:', error);
        }
    }

    function showSuccess(message) {
        showNotification(message, 'success');
    }

    function showError(message) {
        showNotification(message, 'error');
    }

    function showNotification(message, type = 'info') {
        // Создаем уведомление
        const notification = document.createElement('div');
        notification.className = `alert alert-${type === 'error' ? 'danger' : type === 'success' ? 'success' : 'info'} alert-dismissible fade show position-fixed`;
        notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.innerHTML = `
            <strong>${type === 'error' ? 'Ошибка!' : type === 'success' ? 'Успех!' : 'Информация'}</strong>
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

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?> 
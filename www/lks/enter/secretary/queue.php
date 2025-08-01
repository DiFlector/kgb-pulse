<?php
session_start();

// Проверяем авторизацию
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Secretary' && $_SESSION['user_role'] !== 'SuperUser' && $_SESSION['user_role'] !== 'Admin')) {
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
$pageTitle = 'Очередь спортсменов - Панель секретаря';
$pageHeader = 'Очередь спортсменов';
$showBreadcrumb = true;
$breadcrumb = [
    ['href' => '/lks/enter/secretary/', 'title' => 'Секретарь'],
    ['href' => '#', 'title' => 'Очередь спортсменов']
];

include __DIR__ . '/../includes/header.php';
?>

<!-- Основной контент -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">
        <i class="fas fa-clock me-2"></i>Очередь спортсменов
    </h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <button type="button" class="btn btn-success me-2" onclick="openCreateTeamModal()" title="Создать новую команду">
            <i class="fas fa-plus-circle"></i> Создать команду
        </button>
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
    <p class="mt-2 text-muted">Загрузка данных...</p>
</div>

<!-- Контейнер для данных -->
<div id="dataContainer" style="display: none;">
    <!-- Данные будут загружены через JavaScript -->
</div>

<!-- Модальное окно создания команды -->
<div class="modal fade" id="createTeamModal" tabindex="-1" aria-labelledby="createTeamModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createTeamModalLabel">Создание новой команды</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="createTeamForm">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="teamName" class="form-label">Название команды *</label>
                                <input type="text" class="form-control" id="teamName" name="teamName" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="teamCity" class="form-label">Город команды</label>
                                <input type="text" class="form-control" id="teamCity" name="teamCity">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="teamClass" class="form-label">Класс лодки</label>
                                <select class="form-select" id="teamClass" name="teamClass">
                                    <option value="">Выберите класс</option>
                                    <option value="D-10">D-10 (Драконы)</option>
                                    <option value="K-1">K-1 (Байдарка одиночка)</option>
                                    <option value="K-2">K-2 (Байдарка двойка)</option>
                                    <option value="K-4">K-4 (Байдарка четверка)</option>
                                    <option value="C-1">C-1 (Каноэ одиночка)</option>
                                    <option value="C-2">C-2 (Каноэ двойка)</option>
                                    <option value="C-4">C-4 (Каноэ четверка)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="teamSize" class="form-label">Количество мест</label>
                                <input type="number" class="form-control" id="teamSize" name="teamSize" min="1" max="20" value="14">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="teamDescription" class="form-label">Описание команды</label>
                        <textarea class="form-control" id="teamDescription" name="teamDescription" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" onclick="createTeam()">Создать команду</button>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно редактирования участника -->
<div class="modal fade" id="editParticipantModal" tabindex="-1" aria-labelledby="editParticipantModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editParticipantModalLabel">Редактирование участника</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editParticipantForm">
                    <input type="hidden" id="editParticipantId" name="participantId">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="editFio" class="form-label">ФИО</label>
                                <input type="text" class="form-control" id="editFio" name="fio" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="editUserid" class="form-label">Номер спортсмена</label>
                                <input type="text" class="form-control" id="editUserid" name="userid" readonly>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="editStatus" class="form-label">Статус регистрации</label>
                                <select class="form-select" id="editStatus" name="status">
                                    <option value="В очереди">В очереди</option>
                                    <option value="Подтверждён">Подтверждён</option>
                                    <option value="Зарегистрирован">Зарегистрирован</option>
                                    <option value="Ожидание команды">Ожидание команды</option>
                                    <option value="Неявка">Неявка</option>
                                    <option value="Дисквалифицирован">Дисквалифицирован</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="editOplata" class="form-label">Статус оплаты</label>
                                <select class="form-select" id="editOplata" name="oplata">
                                    <option value="0">Не оплачено</option>
                                    <option value="1">Оплачено</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="editTeam" class="form-label">Команда</label>
                                <select class="form-select" id="editTeam" name="team">
                                    <option value="">Без команды</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="editCost" class="form-label">Стоимость участия</label>
                                <input type="number" class="form-control" id="editCost" name="cost" step="0.01">
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" onclick="saveParticipantChanges()">Сохранить изменения</button>
            </div>
        </div>
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
// Глобальные переменные
let allData = [];
let filteredData = [];
let teams = [];

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    loadData();
    loadTeams();
});

// Загрузка данных
async function loadData() {
    showLoading(true);
    
    try {
        const response = await fetch('/lks/php/secretary/get_queue_data.php');
        const data = await response.json();
        
        if (data.success) {
            allData = data.participants || [];
            filteredData = [...allData];
            updateDisplay();
            populateFilters();
        } else {
            showError('Ошибка загрузки данных: ' + (data.message || 'Неизвестная ошибка'));
        }
    } catch (error) {
        console.error('Ошибка загрузки данных:', error);
        showError('Ошибка загрузки данных: ' + error.message);
    } finally {
        showLoading(false);
    }
}

// Загрузка команд
async function loadTeams() {
    try {
        const response = await fetch('/lks/php/secretary/get_teams.php');
        const data = await response.json();
        
        if (data.success) {
            teams = data.teams || [];
            updateTeamSelects();
        }
    } catch (error) {
        console.error('Ошибка загрузки команд:', error);
    }
}

// Обновление отображения
function updateDisplay() {
    const container = document.getElementById('dataContainer');
    
    if (filteredData.length === 0) {
        container.innerHTML = `
            <div class="text-center py-5">
                <i class="fas fa-search fa-3x text-muted mb-3"></i>
                <h4 class="text-muted">Данные не найдены</h4>
                <p class="text-muted">Попробуйте изменить фильтры или поисковый запрос</p>
            </div>
        `;
        return;
    }
    
    // Группируем данные по командам
    const groupedData = groupDataByTeams(filteredData);
    
    let html = '';
    
    // Сначала показываем участников без команд
    if (groupedData.noTeam && groupedData.noTeam.length > 0) {
        html += createQueueSection(groupedData.noTeam);
    }
    
    // Затем показываем команды
    if (groupedData.teams) {
        Object.keys(groupedData.teams).forEach(teamId => {
            const teamData = groupedData.teams[teamId];
            html += createTeamSection(teamData);
        });
    }
    
    container.innerHTML = html;
}

// Группировка данных по командам
function groupDataByTeams(data) {
    const grouped = {
        noTeam: [],
        teams: {}
    };
    
    data.forEach(participant => {
        if (participant.teamid) {
            if (!grouped.teams[participant.teamid]) {
                grouped.teams[participant.teamid] = {
                    team: participant.team,
                    participants: []
                };
            }
            grouped.teams[participant.teamid].participants.push(participant);
        } else {
            grouped.noTeam.push(participant);
        }
    });
    
    return grouped;
}

// Создание секции очереди
function createQueueSection(participants) {
    return `
        <div class="card mb-4 queue-card">
            <div class="card-header bg-warning text-dark">
                <h6 class="mb-0">
                    <i class="fas fa-clock me-2"></i>
                    Очередь спортсменов (${participants.length})
                </h6>
            </div>
            <div class="card-body">
                ${participants.map(participant => createParticipantRow(participant)).join('')}
            </div>
        </div>
    `;
}

// Создание секции команды
function createTeamSection(teamData) {
    return `
        <div class="card mb-4 team-card">
            <div class="card-header bg-danger text-white">
                <h6 class="mb-0">
                    <i class="fas fa-users me-2"></i>
                    ${teamData.team.teamname || 'Команда'} (${teamData.participants.length}/${teamData.team.persons_all || 14})
                </h6>
            </div>
            <div class="card-body">
                ${teamData.participants.map(participant => createParticipantRow(participant)).join('')}
            </div>
        </div>
    `;
}

// Создание строки участника
function createParticipantRow(participant) {
    const statusClass = getStatusClass(participant.status);
    const oplataClass = participant.oplata ? 'success' : 'danger';
    
    return `
        <div class="participant-row">
            <div class="row align-items-center">
                <div class="col-md-3">
                    <strong>${participant.fio}</strong>
                    <br>
                    <small class="text-muted">№${participant.userid}</small>
                </div>
                <div class="col-md-2">
                    <span class="badge bg-${statusClass}">${participant.status}</span>
                    <br>
                    <span class="badge bg-${oplataClass}">${participant.oplata ? 'Оплачено' : 'Не оплачено'}</span>
                </div>
                <div class="col-md-2">
                    <small class="text-muted">${participant.event_name || 'Не указано'}</small>
                </div>
                <div class="col-md-2">
                    <small class="text-muted">${participant.city || 'Не указано'}</small>
                </div>
                <div class="col-md-3">
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary" onclick="editParticipant(${participant.oid})" title="Редактировать">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-outline-success" onclick="assignToTeam(${participant.oid})" title="Назначить в команду">
                            <i class="fas fa-user-plus"></i>
                        </button>
                        <button class="btn btn-outline-warning" onclick="markNoShow(${participant.oid})" title="Отметить неявку">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
}

// Получение класса статуса
function getStatusClass(status) {
    switch(status) {
        case 'В очереди': return 'secondary';
        case 'Подтверждён': return 'info';
        case 'Зарегистрирован': return 'success';
        case 'Ожидание команды': return 'warning';
        case 'Неявка': return 'danger';
        case 'Дисквалифицирован': return 'dark';
        default: return 'light';
    }
}

// Заполнение фильтров
function populateFilters() {
    const eventFilter = document.getElementById('eventFilter');
    const events = [...new Set(allData.map(p => p.event_name).filter(Boolean))];
    
    events.forEach(event => {
        const option = document.createElement('option');
        option.value = event;
        option.textContent = event;
        eventFilter.appendChild(option);
    });
}

// Обновление селектов команд
function updateTeamSelects() {
    const editTeam = document.getElementById('editTeam');
    editTeam.innerHTML = '<option value="">Без команды</option>';
    
    teams.forEach(team => {
        const option = document.createElement('option');
        option.value = team.oid;
        option.textContent = team.teamname;
        editTeam.appendChild(option);
    });
}

// Фильтрация данных
function filterData() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const eventFilter = document.getElementById('eventFilter').value;
    const sortFilter = document.getElementById('sortFilter').value;
    
    filteredData = allData.filter(participant => {
        const matchesSearch = !searchTerm || 
            participant.fio.toLowerCase().includes(searchTerm) ||
            participant.userid.toString().includes(searchTerm);
        
        const matchesEvent = !eventFilter || participant.event_name === eventFilter;
        
        return matchesSearch && matchesEvent;
    });
    
    // Сортировка
    filteredData.sort((a, b) => {
        switch(sortFilter) {
            case 'fio':
                return a.fio.localeCompare(b.fio);
            case 'userid':
                return a.userid - b.userid;
            case 'status':
                return a.status.localeCompare(b.status);
            case 'oplata':
                return b.oplata - a.oplata;
            case 'event':
                return (a.event_name || '').localeCompare(b.event_name || '');
            default:
                return 0;
        }
    });
    
    updateDisplay();
}

// Показать/скрыть загрузку
function showLoading(show) {
    const spinner = document.querySelector('.loading-spinner');
    const container = document.getElementById('dataContainer');
    
    if (show) {
        spinner.style.display = 'block';
        container.style.display = 'none';
    } else {
        spinner.style.display = 'none';
        container.style.display = 'block';
    }
}

// Показать ошибку
function showError(message) {
    const container = document.getElementById('dataContainer');
    container.innerHTML = `
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>
            ${message}
        </div>
    `;
    container.style.display = 'block';
}

// Обновить данные
function refreshData() {
    loadData();
    loadTeams();
}

// Открыть модальное окно создания команды
function openCreateTeamModal() {
    const modal = new bootstrap.Modal(document.getElementById('createTeamModal'));
    modal.show();
}

// Создать команду
async function createTeam() {
    const form = document.getElementById('createTeamForm');
    const formData = new FormData(form);
    
    try {
        const response = await fetch('/lks/php/secretary/create_team.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('createTeamModal')).hide();
            form.reset();
            loadTeams();
            showSuccess('Команда успешно создана');
        } else {
            showError('Ошибка создания команды: ' + (data.message || 'Неизвестная ошибка'));
        }
    } catch (error) {
        console.error('Ошибка создания команды:', error);
        showError('Ошибка создания команды: ' + error.message);
    }
}

// Редактировать участника
function editParticipant(participantId) {
    const participant = allData.find(p => p.oid === participantId);
    if (!participant) return;
    
    // Заполняем форму
    document.getElementById('editParticipantId').value = participant.oid;
    document.getElementById('editFio').value = participant.fio;
    document.getElementById('editUserid').value = participant.userid;
    document.getElementById('editStatus').value = participant.status;
    document.getElementById('editOplata').value = participant.oplata ? '1' : '0';
    document.getElementById('editCost').value = participant.cost || '';
    document.getElementById('editTeam').value = participant.teamid || '';
    
    // Показываем модальное окно
    const modal = new bootstrap.Modal(document.getElementById('editParticipantModal'));
    modal.show();
}

// Сохранить изменения участника
async function saveParticipantChanges() {
    const form = document.getElementById('editParticipantForm');
    const formData = new FormData(form);
    
    try {
        const response = await fetch('/lks/php/secretary/update_participant.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('editParticipantModal')).hide();
            loadData();
            showSuccess('Изменения сохранены');
        } else {
            showError('Ошибка сохранения: ' + (data.message || 'Неизвестная ошибка'));
        }
    } catch (error) {
        console.error('Ошибка сохранения:', error);
        showError('Ошибка сохранения: ' + error.message);
    }
}

// Назначить в команду
function assignToTeam(participantId) {
    // TODO: Реализовать назначение в команду
    alert('Функция назначения в команду будет реализована');
}

// Отметить неявку
function markNoShow(participantId) {
    if (confirm('Отметить участника как неявившегося?')) {
        // TODO: Реализовать отметку неявки
        alert('Функция отметки неявки будет реализована');
    }
}

// Показать успех
function showSuccess(message) {
    // TODO: Реализовать уведомления об успехе
    console.log('Успех:', message);
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?> 
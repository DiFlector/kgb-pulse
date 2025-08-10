<?php
require_once '../../php/common/Auth.php';

$auth = new Auth();
if (!$auth->isAuthenticated() || !in_array($auth->getUserRole(), ['Secretary', 'SuperUser'])) {
    header('Location: ../../login.php');
    exit;
}

$userRole = $auth->getUserRole();
$currentUser = $auth->getCurrentUser();
$userName = $currentUser['fio'] ?? 'Пользователь';

$pageTitle = 'Очередь спортсменов - Панель секретаря';
$pageHeader = 'Очередь спортсменов';
$pageIcon = 'fas fa-clock';

// Дополнительные стили для этой страницы
$additionalCSS = [
    '/lks/css/style-clean.css'
];

include '../includes/header.php'; 
?>

    <!-- Основной контент -->
    <div class="d-flex justify-content-end mb-3">
        <button type="button" class="btn btn-primary" onclick="refreshData()">
            <i class="fas fa-sync-alt"></i> Обновить
        </button>
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

    <!-- Статистика -->
    <div class="row mb-4" id="statistics" style="display: none;">
        <div class="col-md-6">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title text-warning">Ожидают</h5>
                    <h2 class="text-warning" id="queue-count">0</h2>
                    <p class="card-text">спортсменов ожидают</p>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title text-danger">Неполные команды</h5>
                    <h2 class="text-danger" id="incomplete-count">0</h2>
                    <p class="card-text">команд требуют доукомплектования</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Спортсмены в очереди -->
    <div class="card queue-card mb-4" id="queue-section" style="display: none;">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="fas fa-hourglass-half me-2"></i>Спортсмены в очереди
            </h5>
        </div>
        <div class="card-body" id="queue-participants">
            <!-- Данные загружаются через JavaScript -->
        </div>
    </div>

    <!-- Неполные команды -->
    <div class="card team-card" id="teams-section" style="display: none;">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="fas fa-users me-2"></i>Неполные команды
            </h5>
        </div>
        <div class="card-body" id="incomplete-teams">
            <!-- Данные загружаются через JavaScript -->
        </div>
    </div>

    <!-- Сообщение об отсутствии данных -->
    <div class="alert alert-info text-center" id="no-data" style="display: none;">
        <i class="fas fa-info-circle me-2"></i>
        Очередь пуста! Все спортсмены распределены.
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
        let allQueueData = {}; // Хранилище всех данных для фильтрации
        
        $(document).ready(function() {
            loadQueueData();
        });

        function loadQueueData() {
            $('.loading-spinner').show();
            $('#statistics, #queue-section, #teams-section, #no-data').hide();

            $.ajax({
                url: '../../php/secretary/get_queue.php',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        allQueueData = response.queue; // Используем правильное поле из ответа
                        populateEventFilter(allQueueData);
                        displayQueueData(allQueueData);
                    } else {
                        showError('Ошибка загрузки данных: ' + response.error);
                    }
                },
                error: function() {
                    showError('Ошибка соединения с сервером');
                },
                complete: function() {
                    $('.loading-spinner').hide();
                }
            });
        }
        
        function refreshData() {
            loadQueueData();
        }

        // Заполнение фильтра мероприятий
        function populateEventFilter(data) {
            const eventFilter = document.getElementById('eventFilter');
            const events = new Set();
            
            // Собираем уникальные мероприятия
            if (data && Array.isArray(data)) {
                data.forEach(p => {
                    if (p.meroname) events.add(p.meroname);
                });
            }
            
            // Очищаем и заполняем select
            eventFilter.innerHTML = '<option value="">Все мероприятия</option>';
            Array.from(events).sort().forEach(event => {
                const option = document.createElement('option');
                option.value = event;
                option.textContent = event;
                eventFilter.appendChild(option);
            });
        }

        // Фильтрация и сортировка данных
        function filterData() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const eventFilter = document.getElementById('eventFilter').value;
            const sortBy = document.getElementById('sortFilter').value;
            
            let filteredData = JSON.parse(JSON.stringify(allQueueData)); // Глубокая копия
            
            // Фильтрация участников очереди
            if (filteredData && Array.isArray(filteredData)) {
                filteredData = filteredData.filter(participant => {
                    const matchesSearch = !searchTerm || 
                        (participant.fio && participant.fio.toLowerCase().includes(searchTerm)) ||
                        (participant.userid && participant.userid.toString().includes(searchTerm));
                    
                    const matchesEvent = !eventFilter || 
                        (participant.meroname && participant.meroname === eventFilter);
                    
                    return matchesSearch && matchesEvent;
                });
                
                // Сортировка участников очереди
                filteredData.sort((a, b) => {
                    switch (sortBy) {
                        case 'fio':
                            return (a.fio || '').localeCompare(b.fio || '');
                        case 'userid':
                            return (a.userid || 0) - (b.userid || 0);
                        case 'status':
                            return (a.status || '').localeCompare(b.status || '');
                        case 'oplata':
                            return (b.oplata || false) - (a.oplata || false);
                        case 'event':
                            return (a.meroname || '').localeCompare(b.meroname || '');
                        default:
                            return 0;
                    }
                });
            }
            
            displayQueueData(filteredData);
        }

        function displayQueueData(data) {
            // Обрабатываем данные из API секретаря
            const queueParticipants = data || [];
            
            // Подсчитываем общее количество ожидающих участников
            let totalWaiting = queueParticipants.length;
            
            // Обновляем статистику
            $('#queue-count').text(totalWaiting);
            $('#incomplete-count').text('0'); // Пока не реализовано для команд
            $('#statistics').show();

            if (queueParticipants.length === 0) {
                $('#no-data').show();
                return;
            }

            // Отображаем спортсменов в очереди
            if (queueParticipants.length > 0) {
                displayQueueParticipants(queueParticipants);
                $('#queue-section').show();
            } else {
                $('#queue-section').hide();
            }

            // Скрываем секцию команд пока не реализовано
            $('#teams-section').hide();
        }

        function displayQueueParticipants(participants) {
            let html = `
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th width="25%">Участник</th>
                                <th width="20%">Мероприятие</th>
                                <th width="15%">Статус</th>
                                <th width="25%">Дисциплины</th>
                                <th width="15%">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            participants.forEach(function(participant) {
                const disciplinesHtml = formatDisciplines(participant.discipline);
                const paymentIcon = participant.oplata ? '💰' : '⏳';
                const statusClass = getStatusClass(participant.status);

                html += `
                    <tr>
                        <td>
                            <strong>${participant.fio}</strong>
                            <br><small class="text-primary">Спортсмен №${participant.userid || 'Не указан'}</small>
                            <br><small class="text-muted">${participant.email}</small>
                            <br><small class="text-muted">${participant.telephone || ''}</small>
                        </td>
                        <td>
                            <span class="text-info">${participant.meroname || 'Не указано'}</span>
                        </td>
                        <td>
                            <span class="badge ${statusClass}">${participant.status}</span>
                            <br><span title="${participant.oplata ? 'Оплачено' : 'Не оплачено'}">${paymentIcon}</span>
                            <small class="text-muted d-block">${participant.cost || 0} ₽</small>
                        </td>
                        <td>
                            <div class="disciplines-info">
                                ${disciplinesHtml}
                            </div>
                        </td>
                        <td>
                            <div class="btn-group-vertical btn-group-sm">
                                <button class="btn btn-primary btn-sm mb-1" onclick="editRegistration(${participant.oid})" 
                                        title="Редактировать регистрацию">
                                    <i class="fas fa-edit"></i> Редактировать
                                </button>
                                ${participant.status === 'Подтверждён' ? 
                                    `<button class="btn btn-success btn-sm mb-1" onclick="confirmParticipant(${participant.oid})" 
                                            title="Подтвердить участие">
                                        <i class="fas fa-check"></i> Зарегистрировать
                                    </button>` : 
                                    `<button class="btn btn-outline-success btn-sm mb-1" disabled title="Уже зарегистрирован">
                                        <i class="fas fa-check"></i> Зарегистрирован
                                    </button>`
                                }
                                ${!participant.oplata ? 
                                    `<button class="btn btn-warning btn-sm" onclick="confirmPayment(${participant.oid})" 
                                            title="Подтвердить оплату">
                                        <i class="fas fa-dollar-sign"></i> Оплата
                                    </button>` : 
                                    `<button class="btn btn-outline-warning btn-sm" disabled title="Уже оплачено">
                                        <i class="fas fa-dollar-sign"></i> Оплачено
                                    </button>`
                                }
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            html += `
                        </tbody>
                    </table>
                </div>
            `;
            
            document.getElementById('queue-participants').innerHTML = html;
        }

        function displayIncompleteTeams(teams) {
            let html = '';
            
            teams.forEach(function(team) {
                
                const reasonsHtml = team.reasons.map(reason => 
                    `<span class="badge bg-danger me-1">${reason}</span>`
                ).join('');

                html += `
                    <div class="card mb-3">
                        <div class="card-header">
                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <h6 class="mb-0">${team.teamname}</h6>
                                    <small class="text-muted">${team.meroname}</small>
                                </div>
                                <div class="col-md-6 text-end">
                                    ${reasonsHtml}
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-striped">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="25%">Участник</th>
                                            <th width="25%">Дисциплины</th>
                                            <th width="15%">Роль</th>
                                            <th width="15%">Статус</th>
                                            <th width="20%">Оплата</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                `;

                team.participants.forEach(function(participant) {
                    const disciplinesHtml = formatDisciplines(participant.discipline);

                    html += `
                        <tr>
                            <td>
                                <strong>${participant.fio}</strong>
                                <br><small class="text-muted">${participant.email}</small>
                            </td>
                            <td>
                                ${disciplinesHtml}
                            </td>
                            <td>
                                ${participant.role ? `<span class="badge bg-info role-badge">${translateRole(participant.role)}</span>` : ''}
                            </td>
                            <td>
                                <span class="badge bg-${getStatusColor(participant.status)}">${participant.status}</span>
                            </td>
                            <td>
                                ${participant.oplata ? '<span class="badge bg-success">Оплачено</span>' : '<span class="badge bg-danger">Не оплачено</span>'}
                            </td>
                        </tr>
                    `;
                });

                html += `
                                    </tbody>
                                </table>
                            </div>
                            <div class="card-footer text-end">
                                ${team.isDragon ? `<a href="edit-team.php?team_id=${team.teamid}&champn=${team.champn}" class="btn btn-primary btn-sm">
                                    <i class="fas fa-edit"></i> Редактировать команду
                                </a>` : ''}
                                ${team.isComplete ? 
                                    `<button class="btn btn-success btn-sm ms-2" onclick="confirmTeam('${team.teamid}', '${team.champn}')" title="Подтвердить участие всей команды">
                                        <i class="fas fa-check-circle"></i> Зарегистрировать команду
                                    </button>` : 
                                    `<button class="btn btn-outline-success btn-sm ms-2" disabled title="Нельзя зарегистрировать команду - команда не полная">
                                        <i class="fas fa-check-circle"></i> Команда не полная
                                    </button>`
                                }
                                ${team.isComplete ? 
                                    `<button class="btn btn-warning btn-sm ms-2" onclick="confirmTeamPayment('${team.teamid}', '${team.champn}')" title="Подтвердить оплату всей команды">
                                        <i class="fas fa-dollar-sign"></i> Подтвердить оплату
                                    </button>` : 
                                    `<button class="btn btn-outline-warning btn-sm ms-2" disabled title="Нельзя подтвердить оплату - команда не полная">
                                        <i class="fas fa-dollar-sign"></i> Оплата недоступна
                                    </button>`
                                }
                            </div>
                        </div>
                    </div>
                `;
            });

            $('#incomplete-teams').html(html);
        }

        function formatDisciplines(classDistanceJson) {
            try {
                const classDistances = JSON.parse(classDistanceJson || '{}');
                let html = '';
                
                for (const [classType, details] of Object.entries(classDistances)) {
                    html += `<div class="mb-1">`;
                    html += `<strong class="badge bg-primary me-1">${classType}</strong>`;
                    
                    if (details && typeof details === 'object') {
                        const disciplinesInfo = [];
                        
                        // Обрабатываем новую структуру с sex и dist
                        if (details.sex && details.dist) {
                            const sexValues = Array.isArray(details.sex) ? details.sex : [details.sex];
                            const distValues = Array.isArray(details.dist) ? details.dist : [details.dist];
                            
                            distValues.forEach(distance => {
                                sexValues.forEach(sex => {
                                    disciplinesInfo.push(`${distance}м ${sex}`);
                                });
                            });
                        }
                        // Обрабатываем старую структуру (дистанция => группы)
                        else {
                            for (const [distance, groups] of Object.entries(details)) {
                                if (Array.isArray(groups)) {
                                    groups.forEach(group => {
                                        disciplinesInfo.push(`${distance}м ${group}`);
                                    });
                                } else {
                                    disciplinesInfo.push(`${distance}м ${groups}`);
                                }
                            }
                        }
                        
                        if (disciplinesInfo.length > 0) {
                            html += `<br><small class="text-muted">${disciplinesInfo.join(', ')}</small>`;
                        }
                    }
                    html += `</div>`;
                }
                
                return html || '<small class="text-muted">Дисциплины не указаны</small>';
            } catch (e) {
                return '<small class="text-muted">Ошибка в данных дисциплин</small>';
            }
        }

        function getStatusColor(status) {
            switch(status) {
                case 'В очереди': return 'warning';
                case 'Подтверждён': return 'success';
                case 'Зарегистрирован': return 'primary';
                case 'Ожидание команды': return 'warning';
                case 'Дисквалифицирован': return 'danger';
                case 'Неявка': return 'secondary';
                default: return 'secondary';
            }
        }

        // Функция перевода ролей на русский язык
        function translateRole(role) {
            const roleTranslations = {
                'captain': 'Капитан',
                'member': 'Гребец',
                'coxswain': 'Рулевой',
                'drummer': 'Барабанщик',
                'reserve': 'Резерв'
            };
            return roleTranslations[role] || role;
        }

        // Помощник для определения класса статуса
        function getStatusClass(status) {
            switch(status) {
                case 'Подтверждён': return 'bg-success';
                case 'В очереди': return 'bg-warning';
                case 'Ожидание команды': return 'bg-info';
                case 'Зарегистрирован': return 'bg-primary';
                default: return 'bg-secondary';
            }
        }

        // Подтверждение участия секретарем (из "Подтверждён" в "Зарегистрирован")
        async function confirmParticipant(oid) {
            if (!confirm('Зарегистрировать участника в мероприятии?')) {
                return;
            }

            try {
                const response = await fetch('/lks/php/secretary/update_registration_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `registration_id=${oid}&new_status=Зарегистрирован`
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showSuccess('Участник зарегистрирован. Уведомление отправлено.');
                    loadQueueData(); // Перезагружаем данные
                } else {
                    showError(result.error || result.message || 'Ошибка при регистрации');
                }
            } catch (error) {
                showError('Ошибка соединения: ' + error.message);
                console.error('Error:', error);
            }
        }

        // Подтверждение оплаты секретарем (только подтверждение, отмена недоступна)
        async function confirmPayment(oid) {
            if (!confirm('Подтвердить оплату участия?')) {
                return;
            }

            try {
                const response = await fetch('/lks/php/secretary/confirm_payment.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `registration_id=${oid}&oplata=1`
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showSuccess('Оплата подтверждена.');
                    loadQueueData(); // Перезагружаем данные
                } else {
                    showError(result.error || result.message || 'Ошибка при подтверждении оплаты');
                }
            } catch (error) {
                showError('Ошибка соединения: ' + error.message);
                console.error('Error:', error);
            }
        }

        // Подтверждение команды секретарем
        async function confirmTeam(teamid, champn) {
            
            if (!confirm('Зарегистрировать всю команду в мероприятии?')) {
                return;
            }

            try {
                const response = await fetch('/lks/php/secretary/confirm_team.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `teamid=${teamid}&champn=${champn}`
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showSuccess(`Команда зарегистрирована. ${result.confirmed_count} участников уведомлены.`);
                    loadQueueData(); // Перезагружаем данные
                } else {
                    showError(result.error || result.message || 'Ошибка при регистрации команды');
                }
            } catch (error) {
                showError('Ошибка соединения: ' + error.message);
                console.error('Error:', error);
            }
        }

        // Подтверждение оплаты команды секретарем
        async function confirmTeamPayment(teamid, champn) {
            
            if (!confirm('Подтвердить оплату всей команды?')) {
                return;
            }

            try {
                const response = await fetch('/lks/php/secretary/confirm_team_payment.php', {
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

        // Редактирование регистрации
        function editRegistration(oid) {
            // Перенаправляем на страницу редактирования регистрации с параметром возврата
            window.location.href = `edit-registration.php?oid=${oid}&return=queue.php`;
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
    </script>
    
<?php include '../includes/footer.php'; ?> 
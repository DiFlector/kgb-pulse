<?php
session_start();

// Проверяем авторизацию
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Admin' && $_SESSION['user_role'] !== 'SuperUser')) {
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
$pageTitle = 'Очередь спортсменов - Панель администратора';
$pageHeader = 'Очередь спортсменов';
$showBreadcrumb = false;
$breadcrumb = [];

include __DIR__ . '/../includes/header.php';
?>

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
                <h5 class="card-title text-warning">В очереди</h5>
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

<!-- Контейнер для данных -->
<div id="dataContainer" style="display: none;">
    <!-- Данные будут загружены через JavaScript -->
</div>
    
<!-- Специфичный JavaScript для страницы очереди -->
<script>
    // Ждем полной загрузки DOM и инициализации sidebar-manager
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Queue.php: DOM загружен');
        
        // Проверяем наличие sidebar-manager
        if (typeof SidebarManager !== 'undefined') {
            console.log('Queue.php: SidebarManager найден');
        } else {
            console.error('Queue.php: SidebarManager НЕ найден!');
        }
        
        // Загружаем данные после инициализации
        loadQueueData();
    });

        function loadQueueData() {
            $('.loading-spinner').show();
            $('#statistics, #queue-section, #teams-section, #no-data, #dataContainer').hide();

            $.ajax({
                url: '/lks/php/common/get_queue.php',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        displayQueueData(response.data);
                    } else {
                        showError('Ошибка загрузки данных: ' + response.error);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Ошибка AJAX:', xhr, status, error);
                    showError('Ошибка соединения с сервером');
                },
                complete: function() {
                    $('.loading-spinner').hide();
                }
            });
        }

        function displayQueueData(data) {
            const queueParticipants = data.queue_participants || [];
            const incompleteTeams = data.incomplete_teams || [];

            // Обновляем статистику
            $('#queue-count').text(queueParticipants.length);
            $('#incomplete-count').text(incompleteTeams.length);
            $('#statistics').show();

            if (queueParticipants.length === 0 && incompleteTeams.length === 0) {
                $('#no-data').show();
                return;
            }

            // Отображаем спортсменов в очереди
            if (queueParticipants.length > 0) {
                displayQueueParticipants(queueParticipants);
                $('#queue-section').show();
            }

            // Отображаем неполные команды
            if (incompleteTeams.length > 0) {
                displayIncompleteTeams(incompleteTeams);
                $('#teams-section').show();
            }
        }

        function displayQueueParticipants(participants) {
            let html = '';
            
            participants.forEach(function(participant) {
                const classDistances = JSON.parse(participant.class_distance || '{}');
                const classesHtml = Object.keys(classDistances).map(cls => 
                    `<span class="badge bg-secondary me-1">${cls}</span>`
                ).join('');

                html += `
                    <div class="participant-row">
                        <div class="row align-items-center">
                            <div class="col-md-3">
                                <strong>${participant.fio}</strong>
                                <br><small class="text-muted">${participant.email}</small>
                            </div>
                            <div class="col-md-3">
                                <strong>${participant.meroname}</strong>
                                <br><small class="text-muted">ID: ${participant.champn}</small>
                            </div>
                            <div class="col-md-3">
                                ${classesHtml}
                            </div>
                            <div class="col-md-2">
                                <span class="badge bg-warning">В очереди</span>
                                ${participant.oplata ? '<br><span class="badge bg-success mt-1">Оплачено</span>' : '<br><span class="badge bg-danger mt-1">Не оплачено</span>'}
                            </div>
                            <div class="col-md-1">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                        <i class="fas fa-cog"></i>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item" href="#" onclick="changeStatus(${participant.oid}, 'Подтверждён')">Подтвердить</a></li>
                                        <li><a class="dropdown-item" href="#" onclick="changeStatus(${participant.oid}, 'Зарегистрирован')">В регистрацию</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item text-danger" href="#" onclick="changeStatus(${participant.oid}, 'Дисквалифицирован')">Дисквалифицировать</a></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });

            $('#queue-participants').html(html);
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
                            <div class="row">
                                <div class="col-12">
                                    <h6>Участники команды (${team.participants.length}):</h6>
                `;

                team.participants.forEach(function(participant) {
                    const classDistances = JSON.parse(participant.class_distance || '{}');
                    const classesHtml = Object.keys(classDistances).map(cls => 
                        `<span class="badge bg-secondary me-1">${cls}</span>`
                    ).join('');

                    html += `
                        <div class="participant-row">
                            <div class="row align-items-center">
                                <div class="col-md-3">
                                    <strong>${participant.fio}</strong>
                                    <br><small class="text-muted">${participant.email}</small>
                                </div>
                                <div class="col-md-3">
                                    ${classesHtml}
                                </div>
                                <div class="col-md-2">
                                    ${participant.role ? `<span class="badge bg-info role-badge">${participant.role}</span>` : ''}
                                </div>
                                <div class="col-md-2">
                                    <span class="badge bg-${getStatusColor(participant.status)}">${participant.status}</span>
                                </div>
                                <div class="col-md-2">
                                    ${participant.oplata ? '<span class="badge bg-success">Оплачено</span>' : '<span class="badge bg-danger">Не оплачено</span>'}
                                </div>
                            </div>
                        </div>
                    `;
                });

                html += `
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });

            $('#incomplete-teams').html(html);
        }

        function getStatusColor(status) {
            switch(status) {
                case 'Подтверждён': return 'success';
                case 'Зарегистрирован': return 'primary';
                case 'Ожидание команды': return 'warning';
                case 'В очереди': return 'warning';
                default: return 'secondary';
            }
        }

        function changeStatus(registrationId, newStatus) {
            if (!confirm(`Изменить статус на "${newStatus}"?`)) {
                return;
            }

            $.ajax({
                url: '/lks/php/admin/update_registration_status.php',
                method: 'POST',
                data: {
                    registration_id: registrationId,
                    status: newStatus
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showSuccess('Статус успешно изменен');
                        loadQueueData(); // Перезагружаем данные
                    } else {
                        showError('Ошибка изменения статуса: ' + response.error);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Ошибка AJAX:', xhr, status, error);
                    showError('Ошибка соединения с сервером');
                }
            });
        }

        function refreshData() {
            loadQueueData();
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

<?php include __DIR__ . '/../includes/footer.php'; ?> 
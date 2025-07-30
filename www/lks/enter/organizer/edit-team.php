<?php
/**
 * Редактирование команды драконов
 * Возможность замены основного состава на резервистов
 */

session_start();
require_once '../../php/common/Auth.php';
require_once '../../php/db/Database.php';

$auth = new Auth();
if (!$auth->isAuthenticated() || !in_array($auth->getUserRole(), ['Organizer', 'SuperUser', 'Admin'])) {
    header('Location: ../../login.php');
    exit;
}

$userRole = $auth->getUserRole();
$currentUser = $auth->getCurrentUser();

// Получаем ID команды
$teamId = $_GET['team_id'] ?? null;
$champn = $_GET['champn'] ?? null;

// Отладочная информация
error_log("edit-team.php: team_id = " . var_export($teamId, true));
error_log("edit-team.php: champn = " . var_export($champn, true));

if (!$teamId || !$champn) {
    error_log("edit-team.php: Missing parameters, redirecting to queue.php");
    header('Location: queue.php');
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    
    // Получаем данные команды и участников
    $stmt = $pdo->prepare("
        SELECT 
            lr.oid,
            lr.users_oid,
            lr.role,
            lr.status,
            lr.discipline,
            u.fio,
            u.email,
            u.telephone,
            t.teamname,
            t.teamcity,
            m.meroname
        FROM listreg lr
        JOIN users u ON lr.users_oid = u.oid
        JOIN teams t ON lr.teams_oid = t.oid
        JOIN meros m ON lr.meros_oid = m.oid
        WHERE t.teamid = ? AND m.champn = ?
        ORDER BY 
            CASE lr.role 
                WHEN 'captain' THEN 1
                WHEN 'coxswain' THEN 2  
                WHEN 'drummer' THEN 3
                WHEN 'member' THEN 4
                WHEN 'reserve' THEN 5
            END,
            u.fio
    ");
    $stmt->execute([$teamId, $champn]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!$participants) {
        throw new Exception('Команда не найдена');
    }
    
    $teamInfo = $participants[0];
    
    // Проверяем, что это команда драконов
    $discipline = json_decode($teamInfo['discipline'], true);
    $isDragonTeam = false;
    if (is_array($discipline)) {
        foreach ($discipline as $boatType => $details) {
            if ($boatType === 'D-10') {
                $isDragonTeam = true;
                break;
            }
        }
    }
    
    if (!$isDragonTeam) {
        throw new Exception('Редактирование доступно только для команд драконов D-10');
    }
    
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: queue.php');
    exit;
}

include '../includes/header.php';
?>

<style>
.participant-card {
    transition: all 0.3s ease;
    cursor: grab;
}

.participant-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.participant-card.dragging {
    opacity: 0.5;
    cursor: grabbing;
}

.role-main {
    border-left: 4px solid #28a745;
    background: #f8fff9;
}

.role-reserve {
    border-left: 4px solid #ffc107;
    background: #fffdf0;
}

.role-special {
    border-left: 4px solid #17a2b8;
    background: #f0fdff;
}

.drop-zone {
    min-height: 60px;
    border: 2px dashed #dee2e6;
    border-radius: 5px;
    transition: all 0.3s ease;
}

.drop-zone.drag-over {
    border-color: #007bff;
    background-color: #f8f9fa;
}

.role-badge {
    font-size: 0.75rem;
    font-weight: bold;
}
</style>

<!-- Заголовок страницы -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">Редактирование команды драконов</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Главная</a></li>
                <li class="breadcrumb-item"><a href="queue.php">Очередь</a></li>
                <li class="breadcrumb-item active">Редактирование команды</li>
            </ol>
        </nav>
    </div>
    <div class="btn-group">
        <button type="button" class="btn btn-info me-2" onclick="showAddParticipantModal()">
            <i class="bi bi-person-plus"></i> Добавить участника
        </button>
        <button type="button" class="btn btn-success" onclick="saveChanges()">
            <i class="bi bi-check-circle"></i> Сохранить изменения
        </button>
        <a href="queue.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Назад
        </a>
    </div>
</div>

<!-- Информация о команде -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-info-circle"></i> Информация о команде</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <strong>Название команды:</strong><br>
                        <span class="text-muted"><?= htmlspecialchars($teamInfo['teamname']) ?></span>
                    </div>
                    <div class="col-md-3">
                        <strong>Город:</strong><br>
                        <span class="text-muted"><?= htmlspecialchars($teamInfo['teamcity'] ?? 'Не указан') ?></span>
                    </div>
                    <div class="col-md-3">
                        <strong>Мероприятие:</strong><br>
                        <span class="text-muted"><?= htmlspecialchars($teamInfo['meroname']) ?></span>
                    </div>
                    <div class="col-md-3">
                        <strong>Статус:</strong><br>
                        <span class="badge bg-warning"><?= htmlspecialchars($teamInfo['status']) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Инструкции -->
<div class="alert alert-info">
    <h6><i class="bi bi-lightbulb"></i> Инструкции по редактированию команды драконов D-10:</h6>
    <ul class="mb-0">
        <li>Перетаскивайте участников между разделами для изменения их ролей</li>
        <li><strong>Обязательные роли:</strong> 1 капитан (минимум 1 гребец для начала)</li>
        <li><strong>Гибкие роли:</strong> Рулевой и Барабанщик назначаются по необходимости</li>
        <li><strong>Максимум участников:</strong> 14 человек в команде (основной состав + резерв)</li>
        <li><strong>Оптимальный состав:</strong> 1 капитан + 9 гребцов + 1 рулевой + 1 барабанщик = 12 основных + до 2 резервных</li>
        <li>Изменения сохранятся только после нажатия кнопки "Сохранить изменения"</li>
    </ul>
</div>

<!-- Основной состав -->
<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-people-fill"></i> Основной состав (до 14 человек)</h5>
            </div>
            <div class="card-body">
                <!-- Капитан -->
                <div class="mb-3">
                    <h6 class="text-success">Капитан (1 обязательно)</h6>
                    <div id="captain-zone" class="drop-zone role-main p-2">
                        <!-- Участники будут добавлены через JavaScript -->
                    </div>
                </div>
                
                <!-- Гребцы -->
                <div class="mb-3">
                    <h6 class="text-success">Гребцы (минимум 1, оптимально 9)</h6>
                    <div id="paddlers-zone" class="drop-zone role-main p-2">
                        <!-- Участники будут добавлены через JavaScript -->
                    </div>
                </div>
                
                <!-- Рулевой -->
                <div class="mb-3">
                    <h6 class="text-info">Рулевой (0-1, назначается по необходимости)</h6>
                    <div id="coxswain-zone" class="drop-zone role-special p-2">
                        <!-- Участники будут добавлены через JavaScript -->
                    </div>
                </div>
                
                <!-- Барабанщик -->
                <div class="mb-3">
                    <h6 class="text-info">Барабанщик (0-1, назначается по необходимости)</h6>
                    <div id="drummer-zone" class="drop-zone role-special p-2">
                        <!-- Участники будут добавлены через JavaScript -->
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Резерв -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="bi bi-shield-check"></i> Резерв (остальные участники)</h5>
            </div>
            <div class="card-body">
                <div id="reserves-zone" class="drop-zone role-reserve p-2">
                    <!-- Участники будут добавлены через JavaScript -->
                </div>
                <small class="text-muted mt-2">
                    Резервисты могут заменить любого участника основного состава через drag&drop
                </small>
            </div>
        </div>
    </div>
</div>

<!-- Скрытые данные для JavaScript -->
<script>
const teamData = {
    teamId: <?= json_encode($teamId) ?>,
    champn: <?= json_encode($champn) ?>,
    participants: <?= json_encode($participants) ?>
};

let changes = [];

document.addEventListener('DOMContentLoaded', function() {
    initializeTeamEditor();
});

function initializeTeamEditor() {
    const participants = teamData.participants;
    
    // Распределяем участников по ролям
    participants.forEach(participant => {
        const card = createParticipantCard(participant);
        
        switch(participant.role) {
            case 'captain':
                document.getElementById('captain-zone').appendChild(card);
                break;
            case 'member':
                document.getElementById('paddlers-zone').appendChild(card);
                break;
            case 'coxswain':
                document.getElementById('coxswain-zone').appendChild(card);
                break;
            case 'drummer':
                document.getElementById('drummer-zone').appendChild(card);
                break;
            case 'reserve':
                document.getElementById('reserves-zone').appendChild(card);
                break;
        }
    });
    
    // Инициализируем drag & drop
    initializeDragAndDrop();
}

function createParticipantCard(participant) {
    const card = document.createElement('div');
    card.className = 'participant-card card mb-2';
    card.draggable = true;
    card.dataset.oid = participant.oid;
    card.dataset.userid = participant.userid;
    card.dataset.originalRole = participant.role;
    
    const roleNames = {
        'captain': 'Капитан',
        'member': 'Гребец',
        'coxswain': 'Рулевой',
        'drummer': 'Барабанщик',
        'reserve': 'Резерв'
    };
    
    const roleColors = {
        'captain': 'success',
        'member': 'primary',
        'coxswain': 'info',
        'drummer': 'info',
        'reserve': 'warning'
    };
    
    card.innerHTML = `
        <div class="card-body py-2">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <strong>${participant.fio}</strong>
                    <br><small class="text-muted">${participant.email}</small>
                </div>
                <div class="text-end">
                    <span class="badge bg-${roleColors[participant.role]} role-badge">
                        ${roleNames[participant.role]}
                    </span>
                    <br><small class="text-muted">${participant.telephone || ''}</small>
                </div>
            </div>
        </div>
    `;
    
    return card;
}

function initializeDragAndDrop() {
    // Добавляем обработчики для карточек участников
    document.querySelectorAll('.participant-card').forEach(card => {
        card.addEventListener('dragstart', handleDragStart);
        card.addEventListener('dragend', handleDragEnd);
    });
    
    // Добавляем обработчики для зон сброса
    document.querySelectorAll('.drop-zone').forEach(zone => {
        zone.addEventListener('dragover', handleDragOver);
        zone.addEventListener('drop', handleDrop);
        zone.addEventListener('dragenter', handleDragEnter);
        zone.addEventListener('dragleave', handleDragLeave);
    });
}

function handleDragStart(e) {
    e.dataTransfer.setData('text/plain', e.target.dataset.oid);
    e.target.classList.add('dragging');
}

function handleDragEnd(e) {
    e.target.classList.remove('dragging');
}

function handleDragOver(e) {
    e.preventDefault();
}

function handleDragEnter(e) {
    e.preventDefault();
    e.currentTarget.classList.add('drag-over');
}

function handleDragLeave(e) {
    e.currentTarget.classList.remove('drag-over');
}

function handleDrop(e) {
    e.preventDefault();
    e.currentTarget.classList.remove('drag-over');
    
    const oid = e.dataTransfer.getData('text/plain');
    const card = document.querySelector(`[data-oid="${oid}"]`);
    const targetZone = e.currentTarget;
    
    if (!card || !targetZone) return;
    
    // Проверяем лимиты ролей
    const targetRole = getZoneRole(targetZone.id);
    if (!canMoveToZone(targetZone, targetRole)) {
        alert('Превышен лимит участников для этой роли');
        return;
    }
    
    // Перемещаем карточку
    targetZone.appendChild(card);
    
    // Обновляем роль
    updateParticipantRole(card, targetRole);
    
    // Записываем изменение
    recordChange(oid, targetRole);
}

function getZoneRole(zoneId) {
    const roleMap = {
        'captain-zone': 'captain',
        'paddlers-zone': 'member',
        'coxswain-zone': 'coxswain',
        'drummer-zone': 'drummer',
        'reserves-zone': 'reserve'
    };
    return roleMap[zoneId];
}

function canMoveToZone(zone, role) {
    const limits = {
        'captain': 1,
        'coxswain': 1,
        'drummer': 1,
        'member': 9,
        'reserve': 2
    };
    
    const currentCount = zone.querySelectorAll('.participant-card').length;
    return currentCount < limits[role];
}

function updateParticipantRole(card, newRole) {
    const roleNames = {
        'captain': 'Капитан',
        'member': 'Гребец',
        'coxswain': 'Рулевой',
        'drummer': 'Барабанщик',
        'reserve': 'Резерв'
    };
    
    const roleColors = {
        'captain': 'success',
        'member': 'primary',
        'coxswain': 'info',
        'drummer': 'info',
        'reserve': 'warning'
    };
    
    const badge = card.querySelector('.role-badge');
    badge.className = `badge bg-${roleColors[newRole]} role-badge`;
    badge.textContent = roleNames[newRole];
}

function recordChange(oid, newRole) {
    // Удаляем предыдущее изменение для этого участника
    changes = changes.filter(change => change.oid !== oid);
    
    // Добавляем новое изменение
    changes.push({
        oid: oid,
        newRole: newRole
    });
    

}

function saveChanges() {
    if (changes.length === 0) {
        alert('Нет изменений для сохранения');
        return;
    }
    
    // Проверяем обязательные роли
    const requiredRoles = ['captain'];  // Убираем обязательность coxswain и drummer для гибкости
    const currentRoles = getCurrentRoles();
    
    for (let role of requiredRoles) {
        if (!currentRoles[role] || currentRoles[role].length === 0) {
            alert(`Отсутствует обязательная роль: ${getRoleName(role)}`);
            return;
        }
        if (currentRoles[role].length > 1) {
            alert(`Роль ${getRoleName(role)} должна быть только у одного участника`);
            return;
        }
    }
    
    // Проверяем минимальное количество гребцов
    if (!currentRoles['member'] || currentRoles['member'].length < 1) {
        alert('Недостаточно гребцов. Требуется минимум 1 гребец.');
        return;
    }
    
    // Проверяем общее количество участников (максимум 14 для драконов D-10)
    const totalParticipants = Object.values(currentRoles).reduce((sum, roleArray) => sum + roleArray.length, 0);
    if (totalParticipants > 14) {
        alert(`Максимальное количество участников в команде драконов D-10: 14. Сейчас: ${totalParticipants}`);
        return;
    }
    
    // Проверяем что специальные роли не дублируются
    const specialRoles = ['captain', 'coxswain', 'drummer'];
    for (let role of specialRoles) {
        if (currentRoles[role] && currentRoles[role].length > 1) {
            alert(`Роль ${getRoleName(role)} должна быть только у одного участника`);
            return;
        }
    }
    
    // Отправляем изменения на сервер
    fetch('../../php/organizer/save_team_changes.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            teamId: teamData.teamId,
            champn: teamData.champn,
            changes: changes
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Изменения сохранены успешно!');
            window.location.href = 'queue.php';
        } else {
            alert('Ошибка при сохранении: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Ошибка при отправке данных');
    });
}

function getCurrentRoles() {
    const roles = {};
    
    document.querySelectorAll('.drop-zone').forEach(zone => {
        const role = getZoneRole(zone.id);
        const participants = Array.from(zone.querySelectorAll('.participant-card'));
        roles[role] = participants.map(card => card.dataset.oid);
    });
    
    return roles;
}

function getRoleName(role) {
    const names = {
        'captain': 'Капитан',
        'member': 'Гребец',
        'coxswain': 'Рулевой',
        'drummer': 'Барабанщик',
        'reserve': 'Резерв'
    };
    return names[role];
}
</script>

<!-- Модальное окно добавления участника -->
<div class="modal fade" id="addParticipantModal" tabindex="-1" aria-labelledby="addParticipantModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addParticipantModalLabel">
                    <i class="bi bi-person-plus"></i> Добавить участника в команду
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Поиск участников -->
                <div class="mb-3">
                    <label for="participantSearch" class="form-label">Поиск участников</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="participantSearch" placeholder="Введите имя, email или телефон участника" onkeypress="if(event.key==='Enter') searchParticipants()">
                        <button class="btn btn-outline-secondary" type="button" onclick="searchParticipants()">
                            <i class="bi bi-search"></i> Найти
                        </button>
                    </div>
                    <small class="text-muted">Поиск среди зарегистрированных на это мероприятие участников</small>
                </div>
                
                <!-- Результаты поиска -->
                <div id="searchResults" class="mb-3" style="display: none;">
                    <h6>Результаты поиска:</h6>
                    <div id="searchResultsList" class="list-group">
                        <!-- Результаты будут добавлены через JavaScript -->
                    </div>
                </div>
                
                <!-- Выбранный участник -->
                <div id="selectedParticipant" class="mb-3" style="display: none;">
                    <h6>Выбранный участник:</h6>
                    <div class="card">
                        <div class="card-body">
                            <div id="selectedParticipantInfo">
                                <!-- Информация о выбранном участнике -->
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Выбор роли -->
                <div id="roleSelection" class="mb-3" style="display: none;">
                    <label for="participantRole" class="form-label">Роль в команде</label>
                    <select class="form-select" id="participantRole">
                        <option value="">Выберите роль</option>
                        <option value="captain">Капитан</option>
                        <option value="member">Гребец</option>
                        <option value="coxswain">Рулевой</option>
                        <option value="drummer">Барабанщик</option>
                        <option value="reserve">Резерв</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" id="addParticipantBtn" onclick="addParticipantToTeam()" disabled>
                    <i class="bi bi-person-plus"></i> Добавить в команду
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let selectedParticipantData = null;

// Функция показа модального окна
function showAddParticipantModal() {
    const modal = new bootstrap.Modal(document.getElementById('addParticipantModal'));
    modal.show();
    
    // Очищаем предыдущие данные
    document.getElementById('participantSearch').value = '';
    document.getElementById('searchResults').style.display = 'none';
    document.getElementById('selectedParticipant').style.display = 'none';
    document.getElementById('roleSelection').style.display = 'none';
    document.getElementById('addParticipantBtn').disabled = true;
    selectedParticipantData = null;
}

// Поиск участников
async function searchParticipants() {
    const searchTerm = document.getElementById('participantSearch').value.trim();
    
    if (searchTerm.length < 2) {
        alert('Введите минимум 2 символа для поиска');
        return;
    }
    
    try {
        const response = await fetch('../../php/organizer/search_participants.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                search: searchTerm,
                champn: teamData.champn,
                excludeTeamId: teamData.teamId
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            displaySearchResults(data.participants);
        } else {
            alert('Ошибка поиска: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Ошибка при поиске участников');
    }
}

// Отображение результатов поиска
function displaySearchResults(participants) {
    const resultsDiv = document.getElementById('searchResults');
    const resultsList = document.getElementById('searchResultsList');
    
    if (participants.length === 0) {
        resultsList.innerHTML = '<div class="list-group-item">Участники не найдены</div>';
    } else {
        let html = '';
        participants.forEach(participant => {
            html += `
                <div class="list-group-item list-group-item-action" onclick="selectParticipant(${JSON.stringify(participant).replace(/"/g, '&quot;')})">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${participant.fio}</strong>
                            <br><small class="text-muted">${participant.email}</small>
                            <br><small class="text-muted">${participant.telephone || ''}</small>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-${getStatusColor(participant.status)}">${participant.status}</span>
                            <br><small class="text-muted">${participant.teamid ? 'Команда #' + participant.teamid : 'Индивидуальный'}</small>
                        </div>
                    </div>
                </div>
            `;
        });
        resultsList.innerHTML = html;
    }
    
    resultsDiv.style.display = 'block';
}

// Выбор участника
function selectParticipant(participant) {
    selectedParticipantData = participant;
    
    // Показываем информацию о выбранном участнике
    const infoDiv = document.getElementById('selectedParticipantInfo');
    infoDiv.innerHTML = `
        <div class="row">
            <div class="col-md-6">
                <strong>${participant.fio}</strong>
                <br><small class="text-muted">${participant.email}</small>
                <br><small class="text-muted">${participant.telephone || ''}</small>
            </div>
            <div class="col-md-6 text-end">
                <span class="badge bg-${getStatusColor(participant.status)}">${participant.status}</span>
                <br><small class="text-muted">№ спортсмена: ${participant.userid}</small>
            </div>
        </div>
    `;
    
    document.getElementById('selectedParticipant').style.display = 'block';
    document.getElementById('roleSelection').style.display = 'block';
    
    // Обновляем доступность кнопки
    updateAddButtonState();
}

// Обновление состояния кнопки добавления
function updateAddButtonState() {
    const roleSelect = document.getElementById('participantRole');
    const addBtn = document.getElementById('addParticipantBtn');
    
    addBtn.disabled = !selectedParticipantData || !roleSelect.value;
    
    // Добавляем обработчик изменения роли
    roleSelect.onchange = updateAddButtonState;
}

// Добавление участника в команду
async function addParticipantToTeam() {
    if (!selectedParticipantData) {
        alert('Не выбран участник');
        return;
    }
    
    const role = document.getElementById('participantRole').value;
    if (!role) {
        alert('Не выбрана роль');
        return;
    }
    
    // Проверяем лимиты ролей
    const targetZone = getZoneByRole(role);
    if (!canMoveToZone(targetZone, role)) {
        alert('Превышен лимит участников для этой роли');
        return;
    }
    
    try {
        const response = await fetch('../../php/organizer/add_participant_to_team.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                oid: selectedParticipantData.oid,
                teamId: teamData.teamId,
                champn: teamData.champn,
                role: role
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Создаем карточку участника и добавляем в нужную зону
            const participantData = {
                ...selectedParticipantData,
                role: role
            };
            
            const card = createParticipantCard(participantData);
            targetZone.appendChild(card);
            
            // Добавляем обработчики drag & drop для новой карточки
            card.addEventListener('dragstart', handleDragStart);
            card.addEventListener('dragend', handleDragEnd);
            
            // Закрываем модальное окно
            const modal = bootstrap.Modal.getInstance(document.getElementById('addParticipantModal'));
            modal.hide();
            
            alert('Участник успешно добавлен в команду!');
        } else {
            alert('Ошибка при добавлении участника: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Ошибка при добавлении участника в команду');
    }
}

// Получение зоны по роли
function getZoneByRole(role) {
    const zoneMap = {
        'captain': document.getElementById('captain-zone'),
        'member': document.getElementById('paddlers-zone'),
        'coxswain': document.getElementById('coxswain-zone'),
        'drummer': document.getElementById('drummer-zone'),
        'reserve': document.getElementById('reserves-zone')
    };
    return zoneMap[role];
}

// Функция получения цвета статуса (если не определена)
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
</script>

<!-- Подключаем Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php include '../includes/footer.php'; ?> 
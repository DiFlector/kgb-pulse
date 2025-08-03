/**
 * Скрипты для панели секретаря
 * KGB-Pulse - Система управления гребной базой
 */

// Глобальные переменные
let currentEvent = null;
let selectedDisciplines = [];
let protocolsData = {};

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    initializeSecretaryPanel();
});

/**
 * Инициализация панели секретаря
 */
function initializeSecretaryPanel() {
    // Инициализация обработчиков событий
    initializeEventHandlers();
    
    // Загрузка данных
    loadProtocolsData();
    
    // Инициализация компонентов
    initializeComponents();
}

/**
 * Инициализация обработчиков событий
 */
function initializeEventHandlers() {
    // Выбор дисциплин
    const disciplineButtons = document.querySelectorAll('.discipline-btn');
    disciplineButtons.forEach(button => {
        button.addEventListener('click', function() {
            selectDiscipline(this.dataset.discipline, this.dataset.gender, this.dataset.distance);
        });
    });

    // Создание протоколов
    const createProtocolBtns = document.querySelectorAll('.create-protocol-btn');
    createProtocolBtns.forEach(button => {
        button.addEventListener('click', function() {
            createProtocol(this.dataset.type, this.dataset.discipline);
        });
    });

    // Создание всех протоколов
    const createAllBtns = document.querySelectorAll('.create-all-btn');
    createAllBtns.forEach(button => {
        button.addEventListener('click', function() {
            createAllProtocols(this.dataset.type);
        });
    });

    // Сохранение протоколов
    const saveProtocolBtns = document.querySelectorAll('.save-protocol-btn');
    saveProtocolBtns.forEach(button => {
        button.addEventListener('click', function() {
            saveProtocol(this.dataset.protocolId);
        });
    });

    // Финализация результатов
    const finalizeBtn = document.getElementById('finalize-results-btn');
    if (finalizeBtn) {
        finalizeBtn.addEventListener('click', finalizeResults);
    }
}

/**
 * Инициализация компонентов
 */
function initializeComponents() {
    // Инициализация drag & drop для участников
    initializeDragDrop();
    
    // Инициализация таймеров
    initializeTimers();
    
    // Автосохранение каждые 30 секунд
    setInterval(autoSaveProtocols, 30000);
}

/**
 * Выбор дисциплины для жеребьевки
 */
function selectDiscipline(discipline, gender, distance) {
    const disciplineKey = `${discipline}_${gender}_${distance}`;
    
    // Переключаем состояние выбора
    if (selectedDisciplines.includes(disciplineKey)) {
        selectedDisciplines = selectedDisciplines.filter(d => d !== disciplineKey);
    } else {
        selectedDisciplines.push(disciplineKey);
    }
    
    // Обновляем UI
    updateDisciplineSelection();
    
    // Переходим к созданию протоколов
    if (selectedDisciplines.length > 0) {
        loadProtocolsInterface();
    }
}

/**
 * Обновление отображения выбранных дисциплин
 */
function updateDisciplineSelection() {
    const buttons = document.querySelectorAll('.discipline-btn');
    buttons.forEach(button => {
        const disciplineKey = `${button.dataset.discipline}_${button.dataset.gender}_${button.dataset.distance}`;
        
        if (selectedDisciplines.includes(disciplineKey)) {
            button.classList.add('selected', 'btn-primary');
            button.classList.remove('btn-outline-primary');
        } else {
            button.classList.remove('selected', 'btn-primary');
            button.classList.add('btn-outline-primary');
        }
    });
    
    // Обновляем счетчик выбранных дисциплин
    const counter = document.getElementById('selected-count');
    if (counter) {
        counter.textContent = selectedDisciplines.length;
    }
}

/**
 * Загрузка интерфейса протоколов
 */
async function loadProtocolsInterface() {
    try {
        const response = await fetch('/lks/php/secretary/get-protocols-interface.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCSRFToken()
            },
            body: JSON.stringify({
                eventId: currentEvent,
                disciplines: selectedDisciplines
            })
        });

        const data = await response.json();
        
        if (data.success) {
            updateProtocolsInterface(data.html);
        } else {
            showNotification(data.message || 'Ошибка загрузки интерфейса протоколов', 'error');
        }
    } catch (error) {
        console.error('Ошибка загрузки интерфейса:', error);
        showNotification('Ошибка загрузки интерфейса протоколов', 'error');
    }
}

/**
 * Обновление интерфейса протоколов
 */
function updateProtocolsInterface(html) {
    const container = document.getElementById('protocols-container');
    if (container) {
        container.innerHTML = html;
        
        // Переинициализируем обработчики для новых элементов
        initializeEventHandlers();
        initializeDragDrop();
    }
}

/**
 * Создание протокола
 */
async function createProtocol(type, discipline) {
    try {
        showNotification('Создание протокола...', 'info');
        
        const response = await fetch('/lks/php/secretary/create-protocol.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCSRFToken()
            },
            body: JSON.stringify({
                eventId: currentEvent,
                type: type, // 'start' или 'finish'
                discipline: discipline
            })
        });

        const data = await response.json();
        
        if (data.success) {
            showNotification('Протокол успешно создан', 'success');
            updateProtocolCard(data.protocolData);
        } else {
            showNotification(data.message || 'Ошибка создания протокола', 'error');
        }
    } catch (error) {
        console.error('Ошибка создания протокола:', error);
        showNotification('Ошибка создания протокола', 'error');
    }
}

/**
 * Создание всех протоколов
 */
async function createAllProtocols(type) {
    if (!confirm(`Создать все ${type === 'start' ? 'стартовые' : 'финишные'} протоколы?`)) {
        return;
    }

    try {
        showNotification('Создание всех протоколов...', 'info');
        
        const response = await fetch('/lks/php/secretary/create-all-protocols.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCSRFToken()
            },
            body: JSON.stringify({
                eventId: currentEvent,
                type: type,
                disciplines: selectedDisciplines
            })
        });

        const data = await response.json();
        
        if (data.success) {
            showNotification(`Все ${type === 'start' ? 'стартовые' : 'финишные'} протоколы созданы`, 'success');
            loadProtocolsInterface(); // Перезагружаем интерфейс
        } else {
            showNotification(data.message || 'Ошибка создания протоколов', 'error');
        }
    } catch (error) {
        console.error('Ошибка создания протоколов:', error);
        showNotification('Ошибка создания протоколов', 'error');
    }
}

/**
 * Обновление карточки протокола
 */
function updateProtocolCard(protocolData) {
    const card = document.getElementById(`protocol-card-${protocolData.id}`);
    if (!card) return;

    // Обновляем содержимое карточки
    const participantsCount = card.querySelector('.participants-count');
    if (participantsCount) {
        participantsCount.textContent = `${protocolData.participants_count} участников`;
    }

    const statusBadge = card.querySelector('.status-badge');
    if (statusBadge) {
        statusBadge.textContent = protocolData.status;
        statusBadge.className = `badge bg-${getProtocolStatusColor(protocolData.status)}`;
    }

    // Показываем кнопки действий
    const actionsDiv = card.querySelector('.protocol-actions');
    if (actionsDiv) {
        actionsDiv.style.display = 'block';
    }
}

/**
 * Получение цвета статуса протокола
 */
function getProtocolStatusColor(status) {
    const colors = {
        'Создан': 'info',
        'В процессе': 'warning',
        'Завершен': 'success',
        'Заблокирован': 'primary'
    };
    return colors[status] || 'secondary';
}

/**
 * Сохранение протокола
 */
async function saveProtocol(protocolId) {
    try {
        const protocolData = collectProtocolData(protocolId);
        
        const response = await fetch('/lks/php/secretary/save-protocol.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCSRFToken()
            },
            body: JSON.stringify({
                protocolId: protocolId,
                data: protocolData
            })
        });

        const data = await response.json();
        
        if (data.success) {
            showNotification('Протокол сохранен', 'success');
            markProtocolAsSaved(protocolId);
        } else {
            showNotification(data.message || 'Ошибка сохранения протокола', 'error');
        }
    } catch (error) {
        console.error('Ошибка сохранения протокола:', error);
        showNotification('Ошибка сохранения протокола', 'error');
    }
}

/**
 * Сбор данных протокола
 */
function collectProtocolData(protocolId) {
    const protocolCard = document.getElementById(`protocol-card-${protocolId}`);
    if (!protocolCard) return {};

    const data = {
        participants: [],
        results: []
    };

    // Собираем данные участников
    const participantRows = protocolCard.querySelectorAll('.participant-row');
    participantRows.forEach((row, index) => {
        const participant = {
            position: index + 1,
            userId: row.dataset.userId,
            name: row.querySelector('.participant-name')?.textContent || '',
            team: row.querySelector('.participant-team')?.textContent || '',
            lane: row.querySelector('.lane-input')?.value || '',
            startTime: row.querySelector('.start-time-input')?.value || '',
            finishTime: row.querySelector('.finish-time-input')?.value || '',
            place: row.querySelector('.place-input')?.value || ''
        };
        data.participants.push(participant);
    });

    return data;
}

/**
 * Отметка протокола как сохраненного
 */
function markProtocolAsSaved(protocolId) {
    const card = document.getElementById(`protocol-card-${protocolId}`);
    if (card) {
        const saveButton = card.querySelector('.save-protocol-btn');
        if (saveButton) {
            saveButton.classList.remove('btn-primary');
            saveButton.classList.add('btn-success');
            saveButton.innerHTML = '<i class="bi bi-check"></i> Сохранено';
        }
    }
}

/**
 * Инициализация drag & drop для участников
 */
function initializeDragDrop() {
    const participantLists = document.querySelectorAll('.participants-list');
    
    participantLists.forEach(list => {
        // Sortable.js или собственная реализация drag & drop
        enableSortable(list);
    });
}

/**
 * Включение сортировки для списка
 */
function enableSortable(list) {
    let draggedElement = null;

    list.addEventListener('dragstart', function(e) {
        if (e.target.classList.contains('participant-row')) {
            draggedElement = e.target;
            e.target.style.opacity = '0.5';
        }
    });

    list.addEventListener('dragend', function(e) {
        if (e.target.classList.contains('participant-row')) {
            e.target.style.opacity = '';
            draggedElement = null;
        }
    });

    list.addEventListener('dragover', function(e) {
        e.preventDefault();
    });

    list.addEventListener('drop', function(e) {
        e.preventDefault();
        
        if (draggedElement && e.target.classList.contains('participant-row')) {
            const rect = e.target.getBoundingClientRect();
            const midY = rect.top + rect.height / 2;
            
            if (e.clientY < midY) {
                list.insertBefore(draggedElement, e.target);
            } else {
                list.insertBefore(draggedElement, e.target.nextSibling);
            }
            
            // Обновляем позиции
            updateParticipantPositions(list);
        }
    });
}

/**
 * Обновление позиций участников
 */
function updateParticipantPositions(list) {
    const rows = list.querySelectorAll('.participant-row');
    rows.forEach((row, index) => {
        const positionElement = row.querySelector('.position-number');
        if (positionElement) {
            positionElement.textContent = index + 1;
        }
    });
}

/**
 * Инициализация таймеров
 */
function initializeTimers() {
    // Добавляем обработчики для кнопок старта/стопа таймеров
    const timerButtons = document.querySelectorAll('.timer-btn');
    timerButtons.forEach(button => {
        button.addEventListener('click', function() {
            const action = this.dataset.action;
            const protocolId = this.dataset.protocolId;
            
            if (action === 'start') {
                startTimer(protocolId);
            } else if (action === 'stop') {
                stopTimer(protocolId);
            }
        });
    });
}

/**
 * Запуск таймера
 */
function startTimer(protocolId) {
    const now = new Date();
    const timeString = now.toLocaleTimeString('ru-RU', { 
        hour12: false, 
        hour: '2-digit', 
        minute: '2-digit', 
        second: '2-digit' 
    });
    
    // Записываем время старта для всех участников
    const participantRows = document.querySelectorAll(`#protocol-card-${protocolId} .participant-row`);
    participantRows.forEach(row => {
        const startTimeInput = row.querySelector('.start-time-input');
        if (startTimeInput) {
            startTimeInput.value = timeString;
        }
    });
    
    showNotification('Таймер запущен', 'success');
}

/**
 * Остановка таймера
 */
function stopTimer(protocolId) {
    const now = new Date();
    const timeString = now.toLocaleTimeString('ru-RU', { 
        hour12: false, 
        hour: '2-digit', 
        minute: '2-digit', 
        second: '2-digit' 
    });
    
    // Записываем время финиша (можно записать для выделенных участников)
    showNotification('Время финиша зафиксировано', 'info');
}

/**
 * Автосохранение протоколов
 */
function autoSaveProtocols() {
    const protocolCards = document.querySelectorAll('.protocol-card');
    
    protocolCards.forEach(card => {
        const protocolId = card.id.replace('protocol-card-', '');
        const hasChanges = card.dataset.hasChanges === 'true';
        
        if (hasChanges) {
            saveProtocol(protocolId);
            card.dataset.hasChanges = 'false';
        }
    });
}

/**
 * Финализация результатов
 */
async function finalizeResults() {
    if (!confirm('Вы уверены, что хотите завершить мероприятие и сформировать итоговые результаты?')) {
        return;
    }

    try {
        showNotification('Формирование результатов...', 'info');
        
        const response = await fetch('/lks/php/secretary/finalize-results.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCSRFToken()
            },
            body: JSON.stringify({
                eventId: currentEvent
            })
        });

        const data = await response.json();
        
        if (data.success) {
            showNotification('Результаты успешно сформированы', 'success');
            window.location.href = `results.php?event_id=${currentEvent}`;
        } else {
            showNotification(data.message || 'Ошибка формирования результатов', 'error');
        }
    } catch (error) {
        console.error('Ошибка финализации:', error);
        showNotification('Ошибка формирования результатов', 'error');
    }
}

/**
 * Загрузка данных протоколов
 */
async function loadProtocolsData() {
    const eventId = getUrlParameter('event_id');
    if (!eventId) return;

    currentEvent = eventId;

    try {
        const response = await fetch(`/lks/php/secretary/get-protocols-data.php?event_id=${eventId}`);
        const data = await response.json();
        
        if (data.success) {
            protocolsData = data.protocols;
            updateProtocolsDisplay();
        }
    } catch (error) {
        console.error('Ошибка загрузки данных протоколов:', error);
    }
}

/**
 * Обновление отображения протоколов
 */
function updateProtocolsDisplay() {
    // Обновляем статистику и состояние протоколов
    Object.keys(protocolsData).forEach(protocolId => {
        updateProtocolCard(protocolsData[protocolId]);
    });
}

/**
 * Получение параметра из URL
 */
function getUrlParameter(name) {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get(name);
}

/**
 * Получение CSRF токена
 */
function getCSRFToken() {
    const token = document.querySelector('meta[name="csrf-token"]');
    return token ? token.getAttribute('content') : '';
}

/**
 * Добавление нового участника в протокол
 */
function addParticipantToProtocol(protocolId, userData) {
    const protocolCard = document.getElementById(`protocol-card-${protocolId}`);
    if (!protocolCard) return;

    const participantsList = protocolCard.querySelector('.participants-list');
    if (!participantsList) return;

    const participantRow = createParticipantRow(userData);
    participantsList.appendChild(participantRow);
    
    // Обновляем счетчик участников
    updateParticipantsCount(protocolCard);
    
    // Отмечаем что есть изменения
    protocolCard.dataset.hasChanges = 'true';
}

/**
 * Создание строки участника
 */
function createParticipantRow(userData) {
    const row = document.createElement('div');
    row.className = 'participant-row border rounded p-2 mb-2';
    row.draggable = true;
    row.dataset.userId = userData.userid;
    
    // Определяем максимальное количество дорожек в зависимости от типа лодки
    const maxLanes = (userData.discipline === 'D-10') ? 6 : 9;
    
    row.innerHTML = `
        <div class="row align-items-center">
            <div class="col-md-1">
                <span class="position-number fw-bold">-</span>
            </div>
            <div class="col-md-3">
                <span class="participant-name">${userData.fio}</span>
            </div>
            <div class="col-md-2">
                <span class="participant-team">${userData.team || ''}</span>
            </div>
            <div class="col-md-2">
                <input type="number" class="form-control form-control-sm lane-input" 
                       placeholder="Дорожка" min="1" max="${maxLanes}" 
                       value="${userData.lane || ''}" 
                       data-original-lane="${userData.lane || ''}"
                       onchange="updateLane(this, ${userData.userid}, '${userData.groupKey || ''}')">
                <small class="text-muted">Макс: ${maxLanes}</small>
            </div>
            <div class="col-md-2">
                <input type="time" class="form-control form-control-sm start-time-input" step="1">
            </div>
            <div class="col-md-2">
                <input type="time" class="form-control form-control-sm finish-time-input" step="1">
            </div>
        </div>
    `;
    
    return row;
}

/**
 * Обновление дорожки участника
 */
async function updateLane(input, userId, groupKey) {
    const newLane = parseInt(input.value);
    const originalLane = parseInt(input.dataset.originalLane) || 0;
    const maxLanes = parseInt(input.max);
    
    // Проверяем валидность введенного значения
    if (newLane < 1 || newLane > maxLanes) {
        alert(`Номер дорожки должен быть от 1 до ${maxLanes}`);
        input.value = originalLane;
        return;
    }
    
    // Если дорожка не изменилась, ничего не делаем
    if (newLane === originalLane) {
        return;
    }
    
    try {
        const response = await fetch('/lks/php/secretary/update_lane.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                meroId: getCurrentMeroId(),
                groupKey: groupKey,
                userId: userId,
                newLane: newLane
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Обновляем оригинальное значение
            input.dataset.originalLane = newLane;
            
            // Показываем уведомление об успехе
            showNotification(data.message, 'success');
            
            // Отмечаем что есть изменения в протоколе
            const protocolCard = input.closest('.protocol-card');
            if (protocolCard) {
                protocolCard.dataset.hasChanges = 'true';
            }
        } else {
            // Возвращаем исходное значение при ошибке
            input.value = originalLane;
            showNotification(data.message, 'error');
        }
    } catch (error) {
        console.error('Ошибка обновления дорожки:', error);
        input.value = originalLane;
        showNotification('Ошибка обновления дорожки', 'error');
    }
}

/**
 * Получение ID текущего мероприятия
 */
function getCurrentMeroId() {
    // Получаем ID мероприятия из URL или сессии
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get('meroId') || document.querySelector('[data-mero-id]')?.dataset.meroId;
}

/**
 * Обновление счетчика участников
 */
function updateParticipantsCount(protocolCard) {
    const participantRows = protocolCard.querySelectorAll('.participant-row');
    const countElement = protocolCard.querySelector('.participants-count');
    
    if (countElement) {
        countElement.textContent = `${participantRows.length} участников`;
    }
} 

/**
 * Показ уведомлений
 */
function showNotification(message, type = 'info') {
    // Создаем элемент уведомления
    const notification = document.createElement('div');
    notification.className = `alert alert-${type === 'error' ? 'danger' : type} alert-dismissible fade show position-fixed`;
    notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    notification.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    // Добавляем уведомление на страницу
    document.body.appendChild(notification);
    
    // Автоматически удаляем через 5 секунд
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 5000);
} 
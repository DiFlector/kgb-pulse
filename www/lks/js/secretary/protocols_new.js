// Новая система управления протоколами на основе JSON
console.log('protocols_new.js загружен');

class ProtocolsManager {
    constructor() {
        console.log('ProtocolsManager: Конструктор вызван');
        this.currentMeroId = null;
        this.protocolsData = [];
        this.isLoadingAfterDraw = false; // Флаг для отслеживания загрузки после жеребьевки
        this.init();
    }

    // Инициализация системы
    init() {
        this.currentMeroId = document.getElementById('mero-id')?.value;
        if (!this.currentMeroId) {
            console.error('ID мероприятия не найден');
            this.showNotification('Ошибка: ID мероприятия не найден. Убедитесь, что мероприятие выбрано.', 'error');
            return;
        }

        console.log('ProtocolsManager: Инициализация с meroId =', this.currentMeroId);
        
        // Обновляем отладочную информацию
        this.updateDebugInfo();
        
        // Проверяем выбранные дисциплины
        const selectedDisciplines = this.getSelectedDisciplines();
        console.log('ProtocolsManager: Выбранные дисциплины при инициализации:', selectedDisciplines);

        this.bindEvents();
        
        // Сразу загружаем данные протоколов
        const userRole = document.body.getAttribute('data-user-role');
        if (this.currentMeroId && userRole) {
            console.log('ProtocolsManager: Пользователь авторизован с ролью', userRole, ', загружаем протоколы');
            this.loadProtocolsData();
        } else {
            console.log('ProtocolsManager: Пользователь не авторизован или нет данных мероприятия');
            this.showNotification('Для загрузки протоколов необходимо авторизоваться и выбрать мероприятие.', 'info');
        }
    }

    // Показать индикатор загрузки
    showLoadingIndicator(message = 'Загрузка...') {
        // Создаем индикатор загрузки если его нет
        let loadingIndicator = document.getElementById('loading-indicator');
        if (!loadingIndicator) {
            loadingIndicator = document.createElement('div');
            loadingIndicator.id = 'loading-indicator';
            loadingIndicator.className = 'loading-overlay';
            loadingIndicator.innerHTML = `
                <div class="loading-content">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Загрузка...</span>
                    </div>
                    <div class="loading-message mt-2">${message}</div>
                </div>
            `;
            document.body.appendChild(loadingIndicator);
        } else {
            // Обновляем сообщение
            const messageElement = loadingIndicator.querySelector('.loading-message');
            if (messageElement) {
                messageElement.textContent = message;
            }
        }
        
        loadingIndicator.style.display = 'flex';
    }

    // Скрыть индикатор загрузки
    hideLoadingIndicator() {
        const loadingIndicator = document.getElementById('loading-indicator');
        if (loadingIndicator) {
            loadingIndicator.style.display = 'none';
        }
    }

    // Загрузка данных протоколов
    async loadProtocolsData() {
        console.log('Загружаем данные протоколов для мероприятия:', this.currentMeroId);
        
        // Проверяем авторизацию перед загрузкой данных
        const userRole = document.body.getAttribute('data-user-role');
        if (!userRole) {
            console.log('Пользователь не авторизован, пропускаем загрузку данных');
            this.showNotification('Для загрузки протоколов необходимо авторизоваться.', 'warning');
            return;
        }

        try {
            console.log('Загружаем данные протоколов для мероприятия:', this.currentMeroId);
            
            console.log('Отправляем запрос к API с данными:', {
                meroId: this.currentMeroId
            });
            
            const response = await fetch('/lks/php/secretary/load_protocols_data.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    meroId: this.currentMeroId
                })
            });

            console.log('Статус ответа:', response.status);
            console.log('Заголовки ответа:', response.headers);

            // Проверяем тип контента
            const contentType = response.headers.get('content-type');
            console.log('Content-Type:', contentType);

            if (!response.ok) {
                const errorText = await response.text();
                console.error('Ошибка HTTP:', response.status, errorText);
                
                if (response.status === 401) {
                    this.showNotification('Ошибка авторизации. Пожалуйста, войдите в систему.', 'error');
                    return;
                } else if (response.status === 403) {
                    this.showNotification('Нет прав доступа к протоколам.', 'error');
                    return;
                } else if (response.status === 404) {
                    this.showNotification('Файл протоколов не найден. Сначала сгенерируйте протоколы.', 'warning');
                    return;
                } else {
                    this.showNotification(`Ошибка сервера: ${response.status} - ${errorText}`, 'error');
                    return;
                }
            }

            // Проверяем, что ответ действительно JSON
            if (!contentType || !contentType.includes('application/json')) {
                const responseText = await response.text();
                console.error('Неверный Content-Type:', contentType);
                console.error('Ответ сервера:', responseText);
                this.showNotification(`Неверный Content-Type: ${contentType}. Ответ: ${responseText}`, 'error');
                return;
            }

            const data = await response.json();
            console.log('Ответ от API загрузки данных:', data);
            console.log('Статус ответа:', response.status);
            console.log('Заголовки ответа:', Object.fromEntries(response.headers.entries()));
            
            if (data.success) {
                this.protocolsData = data.protocols;
                this.renderProtocols();
                console.log('Протоколы загружены успешно, количество:', this.protocolsData.length);
                this.showNotification(`Данные протоколов загружены. Протоколов: ${data.debug?.totalProtocols || this.protocolsData.length}`, 'success');
            } else {
                console.error('Ошибка загрузки данных:', data.message);
                this.showNotification('Ошибка загрузки данных: ' + data.message, 'error');
            }
        } catch (error) {
            console.error('Ошибка загрузки данных протоколов:', error);
            if (error.name === 'SyntaxError') {
                this.showNotification('Ошибка парсинга ответа сервера. Возможно, проблема с авторизацией.', 'error');
            } else {
                this.showNotification('Ошибка загрузки данных протоколов: ' + error.message, 'error');
            }
        }
    }

    // Обновление отладочной информации
    updateDebugInfo() {
        const startContainer = document.getElementById('start-protocols');
        const finishContainer = document.getElementById('finish-protocols');
        
        const debugStartContainer = document.getElementById('debug-start-container');
        const debugFinishContainer = document.getElementById('debug-finish-container');
        const debugCssTest = document.getElementById('debug-css-test');
        
        if (debugStartContainer) {
            const startContent = startContainer ? startContainer.innerHTML : 'Не найден';
            const startLength = startContent.length;
            debugStartContainer.textContent = `Найден (${startLength} символов)`;
            console.log('Отладочная информация обновлена для стартовых протоколов:', startLength, 'символов');
        }
        
        if (debugFinishContainer) {
            const finishContent = finishContainer ? finishContainer.innerHTML : 'Не найден';
            const finishLength = finishContent.length;
            debugFinishContainer.textContent = `Найден (${finishLength} символов)`;
            console.log('Отладочная информация обновлена для финишных протоколов:', finishLength, 'символов');
        }
        
        // Проверяем CSS стили
        if (debugCssTest && startContainer && finishContainer) {
            const startStyle = window.getComputedStyle(startContainer);
            const finishStyle = window.getComputedStyle(finishContainer);
            
            const cssInfo = {
                start: {
                    display: startStyle.display,
                    visibility: startStyle.visibility,
                    opacity: startStyle.opacity,
                    height: startStyle.height,
                    overflow: startStyle.overflow
                },
                finish: {
                    display: finishStyle.display,
                    visibility: finishStyle.visibility,
                    opacity: finishStyle.opacity,
                    height: finishStyle.height,
                    overflow: finishStyle.overflow
                }
            };
            
            debugCssTest.textContent = `Стартовые: ${cssInfo.start.display}, ${cssInfo.start.visibility}, ${cssInfo.start.opacity} | Финишные: ${cssInfo.finish.display}, ${cssInfo.finish.visibility}, ${cssInfo.finish.opacity}`;
            console.log('CSS стили контейнеров:', cssInfo);
        }
    }

    // Получение правильного названия класса лодки
    getBoatClassName(boatClass) {
        const boatNames = {
            'D-10': 'Драконы (D-10)',
            'K-1': 'Байдарка-одиночка (K-1)',
            'K-2': 'Байдарка-двойка (K-2)',
            'K-4': 'Байдарка-четверка (K-4)',
            'C-1': 'Каноэ-одиночка (C-1)',
            'C-2': 'Каноэ-двойка (C-2)',
            'C-4': 'Каноэ-четверка (C-4)',
            'HD-1': 'Специальная лодка (HD-1)',
            'OD-1': 'Специальная лодка (OD-1)',
            'OD-2': 'Специальная лодка (OD-2)',
            'OC-1': 'Специальная лодка (OC-1)'
        };
        
        return boatNames[boatClass] || boatClass;
    }

    // Привязка событий
    bindEvents() {
        // Кнопка жеребьевки
        const drawButton = document.getElementById('conduct-draw-btn');
        if (drawButton) {
            drawButton.addEventListener('click', () => this.conductDraw());
        }

        // Обработчики для редактирования полей
        document.addEventListener('click', (e) => {
            if (e.target && e.target.classList && e.target.classList.contains('edit-field')) {
                this.makeFieldEditable(e.target);
            }
        });

        // Обработчики для сохранения изменений
        document.addEventListener('blur', (e) => {
            if (e.target && e.target.classList && e.target.classList.contains('editing')) {
                this.saveFieldValue(e.target);
            }
        }, true);

        // Обработчики для Enter и Escape
        document.addEventListener('keydown', (e) => {
            if (e.target && e.target.classList && e.target.classList.contains('editing')) {
                if (e.key === 'Enter') {
                    this.saveFieldValue(e.target);
                } else if (e.key === 'Escape') {
                    this.cancelEdit(e.target);
                }
            }
        });

        // Обработчики для добавления участников
        this.bindAddParticipantEvents();
    }

    // Привязка событий для добавления участников
    bindAddParticipantEvents() {
        // Поиск участников
        const searchBtn = document.getElementById('searchBtn');
        const searchInput = document.getElementById('participantSearch');
        
        if (searchBtn) {
            searchBtn.addEventListener('click', () => this.searchParticipants());
        }
        
        if (searchInput) {
            searchInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    this.searchParticipants();
                }
            });
        }

        // Форма регистрации нового участника
        const newParticipantForm = document.getElementById('newParticipantForm');
        if (newParticipantForm) {
            newParticipantForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.registerNewParticipant();
            });
        }

        // Делегирование событий для кнопок добавления участников
        document.addEventListener('click', (e) => {
            if (e.target && e.target.classList && e.target.classList.contains('add-participant-btn')) {
                const groupKey = e.target.dataset.groupKey;
                this.openAddParticipantModal(groupKey);
            } else if (e.target && e.target.closest && e.target.closest('.add-participant-btn')) {
                const button = e.target.closest('.add-participant-btn');
                const groupKey = button.dataset.groupKey;
                this.openAddParticipantModal(groupKey);
            }
        });
    }

    // Проведение жеребьевки
    async conductDraw() {
        const button = document.getElementById('conduct-draw-btn');
        if (button) {
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Проводится жеребьевка...';
        }

        try {
            console.log('Начинаем жеребьевку для мероприятия:', this.currentMeroId);
            
            const response = await fetch('/lks/php/secretary/conduct_draw.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    meroId: this.currentMeroId
                })
            });

            console.log('Статус ответа:', response.status);
            console.log('Заголовки ответа:', response.headers);

            // Проверяем тип контента
            const contentType = response.headers.get('content-type');
            console.log('Content-Type:', contentType);

            if (!response.ok) {
                const errorText = await response.text();
                console.error('Ошибка HTTP:', response.status, errorText);
                throw new Error(`HTTP ${response.status}: ${errorText}`);
            }

            // Проверяем, что ответ действительно JSON
            if (!contentType || !contentType.includes('application/json')) {
                const responseText = await response.text();
                console.error('Неверный Content-Type:', contentType);
                console.error('Ответ сервера:', responseText);
                throw new Error(`Неверный Content-Type: ${contentType}. Ответ: ${responseText}`);
            }

            const data = await response.json();
            console.log('Ответ от API жеребьевки:', data);
            
            if (data.success) {
                console.log('Жеребьевка успешна, получены данные:', data);
                
                // Используем данные, полученные от жеребьевки
                if (data.protocols && Array.isArray(data.protocols) && data.protocols.length > 0) {
                    console.log('Используем данные от жеребьевки, количество протоколов:', data.protocols.length);
                    this.protocolsData = data.protocols;
                    this.renderProtocols();
                    this.showNotification('Жеребьевка проведена успешно! Участники перераспределены по дорожкам.', 'success');
                } else {
                    console.log('Данные от жеребьевки отсутствуют или пусты, загружаем отдельно');
                    // Если данных нет в ответе, загружаем их отдельно
                    this.isLoadingAfterDraw = true; // Устанавливаем флаг
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    await this.loadProtocolsData();
                    this.isLoadingAfterDraw = false; // Сбрасываем флаг
                    this.showNotification('Жеребьевка проведена успешно!', 'success');
                }
            } else {
                console.error('Ошибка жеребьевки:', data.message);
                if (data.message.includes('Файл протоколов не найден')) {
                    this.showNotification('Протоколы не найдены. Попробуйте сначала сгенерировать протоколы.', 'warning');
                } else {
                    this.showNotification('Ошибка проведения жеребьевки: ' + data.message, 'error');
                }
            }
        } catch (error) {
            console.error('Ошибка жеребьевки:', error);
            this.showNotification('Ошибка проведения жеребьевки: ' + error.message, 'error');
        } finally {
            if (button) {
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-random"></i> Жеребьевка';
            }
        }
    }

    // Отрисовка протоколов
    renderProtocols() {
        console.log('Отрисовка протоколов:', this.protocolsData);
        
        const startContainer = document.getElementById('start-protocols');
        const finishContainer = document.getElementById('finish-protocols');
        
        if (!startContainer || !finishContainer) {
            console.error('Контейнеры протоколов не найдены');
            return;
        }

        // Очищаем контейнеры
        startContainer.innerHTML = '';
        finishContainer.innerHTML = '';

        if (!this.protocolsData || this.protocolsData.length === 0) {
            startContainer.innerHTML = '<div class="empty-state"><i class="fas fa-file-alt"></i><p>Протоколы не найдены</p></div>';
            finishContainer.innerHTML = '<div class="empty-state"><i class="fas fa-file-alt"></i><p>Протоколы не найдены</p></div>';
            return;
        }

        // Генерируем HTML для стартовых протоколов
        const startHTML = this.generateProtocolsHTML(this.protocolsData, 'start');
        startContainer.innerHTML = startHTML;

        // Генерируем HTML для финишных протоколов
        const finishHTML = this.generateProtocolsHTML(this.protocolsData, 'finish');
        finishContainer.innerHTML = finishHTML;

        // Обновляем отладочную информацию
        this.updateDebugInfo();
    }

    // Генерация HTML для протоколов
    generateProtocolsHTML(protocolsData, type) {
        let html = '<div class="protocols-container">';
        
        protocolsData.forEach(protocol => {
            const boatClassName = this.getBoatClassName(protocol.discipline);
            const sexName = protocol.sex === 'М' ? 'Мужчины' : (protocol.sex === 'Ж' ? 'Женщины' : 'Смешанные команды');
            
            html += `<div class="protocol-group mb-4">`;
            html += `<h5 class="protocol-title">${boatClassName} - ${protocol.distance}м - ${sexName}</h5>`;
            
            protocol.ageGroups.forEach(ageGroup => {
                const isProtected = ageGroup.protected || false;
                const protectedClass = isProtected ? 'protected-protocol' : '';
                
                html += `<div class="age-group mb-3">`;
                html += `<div class="d-flex justify-content-between align-items-center mb-2">`;
                html += `<h6 class="age-title">Протокол №${ageGroup.protocol_number} - ${ageGroup.name}</h6>`;
                if (isProtected) {
                    html += `<span class="badge bg-success"><i class="fas fa-shield-alt"></i> Защищен</span>`;
                }
                html += `</div>`;
                
                html += `<div class="table-responsive">`;
                html += `<table class="table table-sm table-bordered protocol-table ${protectedClass}" data-group="${ageGroup.redisKey}" data-type="${type}">`;
                html += `<thead class="table-light">`;
                html += `<tr>`;
                
                // Заголовки в зависимости от типа протокола
                if (type === 'start') {
                    html += `<th>Дорожка</th>`;
                    html += `<th>Номер спортсмена</th>`;
                    html += `<th>ФИО</th>`;
                    html += `<th>Дата рождения</th>`;
                    html += `<th>Спортивный разряд</th>`;
                    if (protocol.discipline === 'D-10') {
                        html += `<th>Город команды</th>`;
                        html += `<th>Название команды</th>`;
                    }
                } else {
                    html += `<th>Место</th>`;
                    html += `<th>Время финиша</th>`;
                    html += `<th>Дорожка</th>`;
                    html += `<th>Номер спортсмена</th>`;
                    html += `<th>ФИО</th>`;
                    html += `<th>Дата рождения</th>`;
                    html += `<th>Спортивный разряд</th>`;
                    if (protocol.discipline === 'D-10') {
                        html += `<th>Город команды</th>`;
                        html += `<th>Название команды</th>`;
                    }
                }
                
                html += `<th>Действия</th>`;
                html += `</tr>`;
                html += `</thead>`;
                html += `<tbody>`;
                
                if (ageGroup.participants && ageGroup.participants.length > 0) {
                    ageGroup.participants.forEach(participant => {
                        html += this.generateParticipantRow(participant, type, protocol.discipline);
                    });
                } else {
                    const colCount = type === 'start' ? (protocol.discipline === 'D-10' ? 8 : 6) : (protocol.discipline === 'D-10' ? 10 : 8);
                    html += `<tr><td colspan="${colCount}" class="text-center text-muted">Нет участников</td></tr>`;
                }
                
                html += `</tbody>`;
                html += `</table>`;
                
                // Кнопка добавления участника только для стартовых протоколов
                if (type === 'start') {
                    html += `<div class="mt-2">`;
                    html += `<button class="btn btn-sm btn-outline-primary add-participant-btn" data-group-key="${ageGroup.redisKey}">`;
                    html += `<i class="fas fa-user-plus"></i> Добавить участника`;
                    html += `</button>`;
                    html += `</div>`;
                }
                
                html += `</div>`;
                html += `</div>`;
            });
            
            html += `</div>`;
        });
        
        html += '</div>';
        return html;
    }

    // Генерация строки участника
    generateParticipantRow(participant, type, boatClass) {
        let html = '<tr class="participant-row">';
        
        if (type === 'start') {
            html += `<td class="edit-field" data-field="lane" data-participant-id="${participant.userId}">${participant.lane || '-'}</td>`;
            html += `<td>${participant.userId}</td>`;
            html += `<td class="edit-field" data-field="fio" data-participant-id="${participant.userId}">${participant.fio}</td>`;
            html += `<td>${participant.birthdata}</td>`;
            html += `<td class="edit-field" data-field="sportzvanie" data-participant-id="${participant.userId}">${participant.sportzvanie}</td>`;
            if (boatClass === 'D-10') {
                html += `<td class="edit-field" data-field="teamCity" data-participant-id="${participant.userId}">${participant.teamCity || '-'}</td>`;
                html += `<td class="edit-field" data-field="teamName" data-participant-id="${participant.userId}">${participant.teamName || '-'}</td>`;
            }
        } else {
            html += `<td class="edit-field" data-field="place" data-participant-id="${participant.userId}">${participant.place || ''}</td>`;
            html += `<td class="edit-field" data-field="finishTime" data-participant-id="${participant.userId}">${participant.finishTime || ''}</td>`;
            html += `<td>${participant.lane || '-'}</td>`;
            html += `<td>${participant.userId}</td>`;
            html += `<td>${participant.fio}</td>`;
            html += `<td>${participant.birthdata}</td>`;
            html += `<td>${participant.sportzvanie}</td>`;
            if (boatClass === 'D-10') {
                html += `<td>${participant.teamCity || '-'}</td>`;
                html += `<td>${participant.teamName || '-'}</td>`;
            }
        }
        
        html += `<td>`;
        html += `<button class="btn btn-sm btn-outline-danger" onclick="protocolsManager.removeParticipant(${participant.userId}, '${participant.redisKey}')">`;
        html += `<i class="fas fa-trash"></i>`;
        html += `</button>`;
        html += `</td>`;
        
        html += '</tr>';
        return html;
    }

    // Сделать поле редактируемым
    makeFieldEditable(element) {
        if (element.classList.contains('editing')) return;
        
        const currentValue = element.textContent.trim();
        const field = element.dataset.field;
        const participantId = element.dataset.participantId;
        
        element.classList.add('editing');
        element.contentEditable = true;
        element.focus();
        
        // Выделить весь текст
        const range = document.createRange();
        range.selectNodeContents(element);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
    }

    // Сохранение значения поля
    async saveFieldValue(element) {
        const newValue = element.textContent.trim();
        const field = element.dataset.field;
        const participantId = element.dataset.participantId;
        const groupKey = element.closest('table').dataset.group;
        
        element.classList.remove('editing');
        element.contentEditable = false;
        
        try {
            const response = await fetch('/lks/php/secretary/update_participant_data.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    meroId: this.currentMeroId,
                    groupKey: groupKey,
                    participantId: participantId,
                    field: field,
                    value: newValue
                })
            });

            const data = await response.json();
            
            if (data.success) {
                // Обновляем данные в памяти
                this.updateParticipantInMemory(groupKey, participantId, field, newValue);
                this.showNotification('Данные сохранены', 'success');
            } else {
                this.showNotification('Ошибка сохранения: ' + data.message, 'error');
                // Возвращаем старое значение
                element.textContent = this.getParticipantValue(groupKey, participantId, field);
            }
        } catch (error) {
            console.error('Ошибка сохранения:', error);
            this.showNotification('Ошибка сохранения данных', 'error');
            element.textContent = this.getParticipantValue(groupKey, participantId, field);
        }
    }

    // Отмена редактирования
    cancelEdit(element) {
        const field = element.dataset.field;
        const participantId = element.dataset.participantId;
        const groupKey = element.closest('table').dataset.group;
        
        element.classList.remove('editing');
        element.contentEditable = false;
        element.textContent = this.getParticipantValue(groupKey, participantId, field);
    }

    // Обновление участника в памяти
    updateParticipantInMemory(groupKey, participantId, field, value) {
        for (const protocol of this.protocolsData) {
            for (const ageGroup of protocol.ageGroups) {
                if (ageGroup.redisKey === groupKey) {
                    for (const participant of ageGroup.participants) {
                        if (participant.userId == participantId) {
                            participant[field] = value;
                            return;
                        }
                    }
                }
            }
        }
    }

    // Получение значения участника
    getParticipantValue(groupKey, participantId, field) {
        for (const protocol of this.protocolsData) {
            for (const ageGroup of protocol.ageGroups) {
                if (ageGroup.redisKey === groupKey) {
                    for (const participant of ageGroup.participants) {
                        if (participant.userId == participantId) {
                            return participant[field] || '';
                        }
                    }
                }
            }
        }
        return '';
    }

    // Удаление участника
    async removeParticipant(participantId, groupKey) {
        if (!confirm('Вы уверены, что хотите удалить этого участника?')) {
            return;
        }

        try {
            // Находим участника в данных
            let found = false;
            for (const protocol of this.protocolsData) {
                for (const ageGroup of protocol.ageGroups) {
                    if (ageGroup.redisKey === groupKey) {
                        const index = ageGroup.participants.findIndex(p => p.userId == participantId);
                        if (index !== -1) {
                            ageGroup.participants.splice(index, 1);
                            found = true;
                            break;
                        }
                    }
                }
                if (found) break;
            }

            if (found) {
                // Обновляем отображение
                this.renderProtocols();
                this.showNotification('Участник удален', 'success');
            } else {
                this.showNotification('Участник не найден', 'error');
            }
        } catch (error) {
            console.error('Ошибка удаления участника:', error);
            this.showNotification('Ошибка удаления участника', 'error');
        }
    }

    // Открытие модального окна добавления участника
    openAddParticipantModal(groupKey) {
        document.getElementById('current-group-key').value = groupKey;
        document.getElementById('participantSearch').value = '';
        document.getElementById('searchResults').innerHTML = '';
        
        // Сброс формы регистрации
        document.getElementById('newParticipantForm').reset();
        
        // Переключение на вкладку поиска
        const searchTab = document.getElementById('search-tab');
        if (searchTab) {
            searchTab.click();
        }
        
        // Открытие модального окна
        const modal = new bootstrap.Modal(document.getElementById('addParticipantModal'));
        modal.show();
    }

    // Поиск участников
    async searchParticipants() {
        const query = document.getElementById('participantSearch').value.trim();
        if (!query) {
            this.showNotification('Введите данные для поиска', 'error');
            return;
        }

        try {
            const response = await fetch('/lks/php/secretary/search_participants.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    query: query,
                    meroId: this.currentMeroId
                })
            });

            const data = await response.json();
            
            if (data.success) {
                this.displaySearchResults(data.participants);
            } else {
                this.showNotification('Ошибка поиска: ' + data.message, 'error');
            }
        } catch (error) {
            console.error('Ошибка поиска участников:', error);
            this.showNotification('Ошибка поиска участников', 'error');
        }
    }

    // Отображение результатов поиска
    displaySearchResults(participants) {
        const resultsContainer = document.getElementById('searchResults');
        
        if (!participants || participants.length === 0) {
            resultsContainer.innerHTML = '<div class="alert alert-info">Участники не найдены</div>';
            return;
        }

        let html = '<div class="list-group">';
        participants.forEach(participant => {
            html += `
                <div class="list-group-item list-group-item-action">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">${participant.fio}</h6>
                            <small class="text-muted">
                                Номер: ${participant.userid} | Email: ${participant.email} | 
                                Возраст: ${participant.age} | Разряд: ${participant.sportzvanie}
                            </small>
                        </div>
                        <button class="btn btn-sm btn-primary" onclick="protocolsManager.addParticipantToGroup('${participant.oid}', '${participant.userid}')">
                            <i class="fas fa-plus"></i> Добавить
                        </button>
                    </div>
                </div>
            `;
        });
        html += '</div>';
        
        resultsContainer.innerHTML = html;
    }

    // Добавление участника в группу
    async addParticipantToGroup(participantOid, participantUserid) {
        const groupKey = document.getElementById('current-group-key').value;
        if (!groupKey) {
            this.showNotification('Ошибка: группа не выбрана', 'error');
            return;
        }

        try {
            const response = await fetch('/lks/php/secretary/add_participant_to_protocol.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    participantOid: participantOid,
                    groupKey: groupKey,
                    meroId: this.currentMeroId
                })
            });

            const data = await response.json();
            
            if (data.success) {
                this.showNotification('Участник успешно добавлен в протокол', 'success');
                
                // Обновляем данные протоколов
                await this.loadProtocols();
                
                // Закрываем модальное окно
                const modal = bootstrap.Modal.getInstance(document.getElementById('addParticipantModal'));
                modal.hide();
            } else {
                this.showNotification('Ошибка добавления: ' + data.message, 'error');
            }
        } catch (error) {
            console.error('Ошибка добавления участника:', error);
            this.showNotification('Ошибка добавления участника', 'error');
        }
    }

    // Регистрация нового участника
    async registerNewParticipant() {
        const formData = {
            email: document.getElementById('newEmail').value,
            phone: document.getElementById('newPhone').value,
            fio: document.getElementById('newFio').value,
            sex: document.getElementById('newSex').value,
            birthDate: document.getElementById('newBirthDate').value,
            sportRank: document.getElementById('newSportRank').value,
            meroId: this.currentMeroId
        };

        // Валидация
        if (!formData.email || !formData.phone || !formData.fio || !formData.sex || !formData.birthDate) {
            this.showNotification('Заполните все обязательные поля', 'error');
            return;
        }

        try {
            const response = await fetch('/lks/php/secretary/register_new_participant.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(formData)
            });

            const data = await response.json();
            
            if (data.success) {
                this.showNotification('Участник успешно зарегистрирован', 'success');
                
                // Автоматически добавляем участника в группу
                await this.addParticipantToGroup(data.participant.oid, data.participant.userid);
                
                // Закрываем модальное окно
                const modal = bootstrap.Modal.getInstance(document.getElementById('addParticipantModal'));
                modal.hide();
                
                // Сброс формы
                document.getElementById('newParticipantForm').reset();
            } else {
                this.showNotification('Ошибка регистрации: ' + data.message, 'error');
            }
        } catch (error) {
            console.error('Ошибка регистрации участника:', error);
            this.showNotification('Ошибка регистрации участника', 'error');
        }
    }

    // Получение выбранных дисциплин
    getSelectedDisciplines() {
        const disciplinesElement = document.getElementById('selected-disciplines');
        console.log('Ищем элемент selected-disciplines:', disciplinesElement);
        
        if (disciplinesElement) {
            console.log('Значение элемента:', disciplinesElement.value);
            const disciplines = JSON.parse(disciplinesElement.value || '[]');
            console.log('Получены выбранные дисциплины:', disciplines);
            console.log('Тип disciplines:', typeof disciplines);
            console.log('Длина массива:', disciplines.length);
            
            // Если дисциплины не выбраны, возвращаем пустой массив для загрузки всех доступных
            if (!disciplines || disciplines.length === 0) {
                console.log('Дисциплины не выбраны, будем загружать все доступные');
                return [];
            }
            
            // Возвращаем дисциплины как есть (они уже в правильном формате строк)
            console.log('Возвращаем дисциплины:', disciplines);
            return disciplines;
        }
        console.log('Элемент selected-disciplines не найден, возвращаем пустой массив для загрузки всех доступных');
        return [];
    }

    // Показ уведомления
    showNotification(message, type = 'info') {
        const alertClass = type === 'success' ? 'alert-success' : 
                          type === 'error' ? 'alert-danger' : 'alert-info';
        
        const alert = document.createElement('div');
        alert.className = `alert ${alertClass} alert-dismissible fade show position-fixed`;
        alert.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        alert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(alert);
        
        // Автоматически скрываем через 5 секунд
        setTimeout(() => {
            if (alert.parentNode) {
                alert.remove();
            }
        }, 5000);
    }
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM загружен, инициализируем ProtocolsManager');
    window.protocolsManager = new ProtocolsManager();
}); 
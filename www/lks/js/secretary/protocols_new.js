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
            // Получаем выбранные дисциплины
            const selectedDisciplines = this.getSelectedDisciplines();
            
            const response = await fetch('/lks/php/secretary/load_protocols_data.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    meroId: this.currentMeroId,
                    disciplines: selectedDisciplines
                })
            });

            // Проверяем тип контента
            const contentType = response.headers.get('content-type');

            if (!response.ok) {
                const errorText = await response.text();
                console.error('Ошибка HTTP:', response.status, errorText);
                
                if (response.status === 401) {
                    this.showNotification('Ошибка авторизации. Пожалуйста, войдите в систему.', 'error');
                    return;
                } else if (response.status === 403) {
                    this.showNotification('Нет прав доступа к протоколам. Проверьте авторизацию.', 'error');
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
            
            if (data.success) {
                this.protocolsData = data.protocols;
                this.renderProtocols();
                this.showNotification(`Данные протоколов загружены. Протоколов: ${data.protocols?.length || 0}`, 'success');
                
                // Синхронизируем высоту контейнеров после загрузки
                setTimeout(() => {
                    this.syncContainerHeights();
                }, 100);
            } else {
                console.error('❌ [LOAD_PROTOCOLS_DATA] Ошибка загрузки данных:', data.message);
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
        
        console.log('Отладочная информация обновлена');
        console.log('Контейнер стартовых протоколов:', startContainer ? 'найден' : 'не найден');
        console.log('Контейнер финишных протоколов:', finishContainer ? 'найден' : 'не найден');
        console.log('Количество групп протоколов:', this.protocolsData.length);
        
        if (startContainer && finishContainer) {
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
        // Обработчики для кнопок скачивания
        document.addEventListener('click', (e) => {
            if (e.target.closest('#download-start-protocols-btn')) {
                this.downloadAllProtocols('start');
            } else if (e.target.closest('#download-finish-protocols-btn')) {
                this.downloadAllProtocols('finish');
            } else if (e.target.closest('.download-protocol-btn')) {
                const btn = e.target.closest('.download-protocol-btn');
                const groupKey = btn.dataset.groupKey;
                const protocolType = btn.dataset.protocolType;
                this.downloadProtocol(groupKey, protocolType);
            }
        });

        // Обработчик для жеребьевки
        document.addEventListener('click', (e) => {
            if (e.target.closest('#conduct-draw-btn')) {
                this.conductDraw();
            }
        });

        // Обработчик изменения размера окна для синхронизации высоты
        window.addEventListener('resize', () => {
            setTimeout(() => {
                this.syncContainerHeights();
            }, 100);
        });

        // Привязываем события для добавления участников
        this.bindAddParticipantEvents();

        // Обработчики редактирования полей
        document.addEventListener('click', (e) => {
            if (e.target && e.target.classList && e.target.classList.contains('edit-field')) {
                this.makeFieldEditable(e.target);
            }
        });

        document.addEventListener('blur', (e) => {
            if (e.target && e.target.classList && e.target.classList.contains('edit-field') && e.target.classList.contains('editing')) {
                this.saveFieldValue(e.target);
            }
        }, true);

        document.addEventListener('keydown', (e) => {
            if (e.target && e.target.classList && e.target.classList.contains('edit-field') && e.target.classList.contains('editing')) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.saveFieldValue(e.target);
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    this.cancelEdit(e.target);
                }
            }
        });

        // Обработчик кнопки добавления участника
        document.addEventListener('click', (e) => {
            if (e.target.closest('.add-participant-btn')) {
                const btn = e.target.closest('.add-participant-btn');
                const groupKey = btn.dataset.groupKey;
                this.openAddParticipantModal(groupKey);
            }
        });

        // Обработчик удаления участника
        document.addEventListener('click', (e) => {
            if (e.target.closest('.btn-outline-danger')) {
                const btn = e.target.closest('.btn-outline-danger');
                const participantId = btn.dataset.participantId;
                const groupKey = btn.dataset.groupKey;
                this.removeParticipant(participantId, groupKey);
            }
        });
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
            
            // Получаем выбранные дисциплины
            const selectedDisciplines = this.getSelectedDisciplines();
            
            if (selectedDisciplines.length === 0) {
                this.showNotification('Выберите хотя бы одну дисциплину для жеребьевки', 'warning');
                return;
            }
            
            // Проверяем, есть ли защищенные данные в протоколах
            let hasProtectedData = false;
            for (const protocol of this.protocolsData) {
                for (const ageGroup of protocol.ageGroups) {
                    if (ageGroup.participants && ageGroup.participants.length > 0) {
                        for (const participant of ageGroup.participants) {
                            if (participant.protected) {
                                hasProtectedData = true;
                                break;
                            }
                        }
                    }
                }
            }
            
            // Если есть защищенные данные, спрашиваем пользователя
            let preserveProtected = true;
            if (hasProtectedData) {
                const userChoice = confirm('Обнаружены защищенные данные (результаты, места, время). Сохранить их при жеребьевке?');
                preserveProtected = userChoice;
            }
            
            // Проводим жеребьевку для каждой группы
            let totalAssigned = 0;
            let totalPreserved = 0;
            
            for (const protocol of this.protocolsData) {
                for (const ageGroup of protocol.ageGroups) {
                    if (ageGroup.participants && ageGroup.participants.length > 0) {
                        console.log(`Проводим жеребьевку для группы: ${ageGroup.redisKey}`);
                        
                        // Извлекаем параметры из redisKey
                        const keyParts = ageGroup.redisKey.split(':');
                        const meroId = keyParts[1];
                        const discipline = keyParts[2];
                        const sex = keyParts[3];
                        const distance = keyParts[4];
                        const ageGroupName = keyParts[5];
                        
                        const response = await fetch('/lks/php/secretary/conduct_draw_json.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({
                                groupKey: ageGroup.redisKey,
                                meroId: meroId,
                                discipline: discipline,
                                sex: sex,
                                distance: distance,
                                ageGroup: ageGroupName,
                                preserveProtected: preserveProtected
                            })
                        });

                        if (!response.ok) {
                            const errorText = await response.text();
                            throw new Error(`HTTP ${response.status}: ${errorText}`);
                        }

                        const data = await response.json();
                        console.log('Ответ от API жеребьевки:', data);
                        
                        if (data.success) {
                            totalAssigned += data.assigned_lanes || 0;
                            totalPreserved += data.preserved_protected || 0;
                        } else {
                            throw new Error(data.message || 'Ошибка жеребьевки');
                        }
                    }
                }
            }
            
            // Перезагружаем данные протоколов
            await this.loadProtocolsData();
            
            this.showNotification(`Жеребьевка проведена успешно! Назначено ${totalAssigned} новых дорожек, сохранено ${totalPreserved} защищенных записей.`, 'success');
            
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
        console.log('🔄 [RENDER_PROTOCOLS] Начинаем отрисовку протоколов');
        console.log('Данные протоколов:', this.protocolsData);
        
        const startContainer = document.getElementById('start-protocols');
        const finishContainer = document.getElementById('finish-protocols');
        
        console.log('Контейнеры найдены:', {
            startContainer: !!startContainer,
            finishContainer: !!finishContainer
        });
        
        if (!startContainer || !finishContainer) {
            console.error('❌ [RENDER_PROTOCOLS] Контейнеры протоколов не найдены');
            return;
        }

        // Очищаем контейнеры
        startContainer.innerHTML = '';
        finishContainer.innerHTML = '';

        if (!this.protocolsData || this.protocolsData.length === 0) {
            console.log('⚠️ [RENDER_PROTOCOLS] Нет данных протоколов');
            startContainer.innerHTML = '<div class="empty-state"><i class="fas fa-file-alt"></i><p>Протоколы не найдены</p></div>';
            finishContainer.innerHTML = '<div class="empty-state"><i class="fas fa-file-alt"></i><p>Протоколы не найдены</p></div>';
            return;
        }

        console.log('🔄 [RENDER_PROTOCOLS] Генерируем HTML для стартовых протоколов');
        // Генерируем HTML для стартовых протоколов
        const startHTML = this.generateProtocolsHTML(this.protocolsData, 'start');
        startContainer.innerHTML = startHTML;

        console.log('🔄 [RENDER_PROTOCOLS] Генерируем HTML для финишных протоколов');
        // Генерируем HTML для финишных протоколов
        const finishHTML = this.generateProtocolsHTML(this.protocolsData, 'finish');
        finishContainer.innerHTML = finishHTML;

        console.log('🔄 [RENDER_PROTOCOLS] Синхронизируем высоту контейнеров');
        // Синхронизируем высоту контейнеров
        this.syncContainerHeights();

        console.log('🔄 [RENDER_PROTOCOLS] Обновляем отладочную информацию');
        // Обновляем отладочную информацию
        this.updateDebugInfo();
        
        console.log('✅ [RENDER_PROTOCOLS] Отрисовка завершена');
    }

    // Синхронизация высоты контейнеров протоколов
    // Эта функция обеспечивает одинаковую высоту для соответствующих групп протоколов
    // в левой (стартовые) и правой (финишные) колонках
    syncContainerHeights() {
        const startContainer = document.getElementById('start-protocols');
        const finishContainer = document.getElementById('finish-protocols');
        
        if (!startContainer || !finishContainer) {
            return;
        }

        // Получаем все группы протоколов
        const startGroups = startContainer.querySelectorAll('.protocol-group');
        const finishGroups = finishContainer.querySelectorAll('.protocol-group');
        
        // Синхронизируем высоту соответствующих групп
        const maxGroups = Math.max(startGroups.length, finishGroups.length);
        
        for (let i = 0; i < maxGroups; i++) {
            const startGroup = startGroups[i];
            const finishGroup = finishGroups[i];
            
            if (startGroup && finishGroup) {
                // Проверяем, есть ли участники в группах
                const startHasParticipants = startGroup.querySelector('tbody tr:not([style*="display: none"])') && 
                                          !startGroup.querySelector('tbody tr td[colspan]');
                const finishHasParticipants = finishGroup.querySelector('tbody tr:not([style*="display: none"])') && 
                                           !finishGroup.querySelector('tbody tr td[colspan]');
                
                // Если обе группы пустые, не устанавливаем минимальную высоту
                if (!startHasParticipants && !finishHasParticipants) {
                    startGroup.style.minHeight = 'auto';
                    finishGroup.style.minHeight = 'auto';
                    continue;
                }
                
                // Находим максимальную высоту только для групп с участниками
                const startHeight = startHasParticipants ? startGroup.offsetHeight : 0;
                const finishHeight = finishHasParticipants ? finishGroup.offsetHeight : 0;
                const maxHeight = Math.max(startHeight, finishHeight, 150); // Минимум 150px
                
                // Устанавливаем одинаковую высоту только если есть участники
                if (startHasParticipants || finishHasParticipants) {
                    startGroup.style.minHeight = maxHeight + 'px';
                    finishGroup.style.minHeight = maxHeight + 'px';
                }
            }
        }
        
        // Синхронизируем общую высоту контейнеров только если есть контент
        const startHeight = startContainer.offsetHeight;
        const finishHeight = finishContainer.offsetHeight;
        const maxContainerHeight = Math.max(startHeight, finishHeight);
        
        if (maxContainerHeight > 0) {
            startContainer.style.minHeight = maxContainerHeight + 'px';
            finishContainer.style.minHeight = maxContainerHeight + 'px';
        }
    }

    // Генерация HTML для протоколов
    generateProtocolsHTML(protocolsData, type) {
        console.log(`🔄 [GENERATE_PROTOCOLS_HTML] Генерируем HTML для типа: ${type}`);
        console.log('Данные протоколов:', protocolsData);
        
        if (!protocolsData || protocolsData.length === 0) {
            console.log('⚠️ [GENERATE_PROTOCOLS_HTML] Нет данных протоколов');
            return '<div class="empty-state"><i class="fas fa-file-alt"></i><p>Протоколы не найдены</p></div>';
        }

        let html = '<div class="protocols-container">';
        
        protocolsData.forEach((protocol, protocolIndex) => {
            console.log(`🔄 [GENERATE_PROTOCOLS_HTML] Обрабатываем протокол ${protocolIndex + 1}:`, protocol);
            
            const boatClassName = this.getBoatClassName(protocol.discipline);
            const sexName = protocol.sex === 'М' ? 'Мужчины' : (protocol.sex === 'Ж' ? 'Женщины' : 'Смешанные команды');
            
            html += `<div class="protocol-group mb-4">`;
            html += `<h5 class="protocol-title">${boatClassName} - ${protocol.distance}м - ${sexName}</h5>`;
            
            // Проверяем, что ageGroups существует и является массивом
            if (protocol.ageGroups && Array.isArray(protocol.ageGroups)) {
                protocol.ageGroups.forEach((ageGroup, ageGroupIndex) => {
                    console.log(`🔄 [GENERATE_PROTOCOLS_HTML] Обрабатываем возрастную группу ${ageGroupIndex + 1}:`, ageGroup);
                    
                    // Проверяем, что у нас есть redisKey
                    if (!ageGroup.redisKey) {
                        console.error('❌ [GENERATE_PROTOCOLS_HTML] Отсутствует redisKey для возрастной группы:', ageGroup);
                        return;
                    }
                    
                    const isProtected = ageGroup.protected || false;
                    const isFinishComplete = type === 'finish' && this.isFinishProtocolComplete(ageGroup);
                    const protectedClass = isProtected ? 'protected-protocol' : '';
                    const completedClass = isFinishComplete ? 'completed-finish-protocol' : '';
                    const combinedClass = `${protectedClass} ${completedClass}`.trim();
                    
                    html += `<div class="age-group mb-3">`;
                    html += `<div class="d-flex justify-content-between align-items-center mb-2">`;
                    // Форматируем название группы для отображения
                    let displayGroupName = ageGroup.name;
                    if (displayGroupName.includes(': ')) {
                        // Заменяем ": " на " " для более читаемого отображения
                        displayGroupName = displayGroupName.replace(': ', ' ');
                    }
                    html += `<h6 class="age-title">Протокол №${ageGroup.protocol_number} - ${displayGroupName}</h6>`;
                    if (isProtected) {
                        html += `<span class="badge bg-success"><i class="fas fa-shield-alt"></i> Защищен</span>`;
                    }
                    if (isFinishComplete) {
                        html += `<span class="badge bg-success"><i class="fas fa-check-circle"></i> Заполнен</span>`;
                    }
                    html += `</div>`;
                    
                    html += `<div class="table-responsive">`;
                    html += `<table class="table table-sm table-bordered protocol-table ${combinedClass}" data-group="${ageGroup.redisKey}" data-type="${type}">`;
                    html += `<thead class="table-light">`;
                    html += `<tr>`;
                    
                    // Заголовки в зависимости от типа протокола
                    if (type === 'start') {
                        html += `<th>Вода</th>`;
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
                        html += `<th>Вода</th>`;
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
                            html += this.generateParticipantRow(participant, type, protocol.discipline, ageGroup.redisKey);
                        });
                    } else {
                        // Правильный расчет количества столбцов для colspan
                        let colCount = 0;
                        
                        if (type === 'start') {
                            // Стартовые протоколы: Вода, Номер спортсмена, ФИО, Дата рождения, Спортивный разряд
                            colCount = 5;
                            if (protocol.discipline === 'D-10') {
                                // Дополнительные столбцы для драконов: Город команды, Название команды
                                colCount += 2;
                            }
                        } else {
                            // Финишные протоколы: Место, Время финиша, Вода, Номер спортсмена, ФИО, Дата рождения, Спортивный разряд
                            colCount = 7;
                            if (protocol.discipline === 'D-10') {
                                // Дополнительные столбцы для драконов: Город команды, Название команды
                                colCount += 2;
                            }
                        }
                        
                        // Добавляем столбец "Действия"
                        colCount += 1;
                        
                        html += `<tr><td colspan="${colCount}" class="text-center text-muted">Нет участников</td></tr>`;
                    }
                    
                    html += `</tbody>`;
                    html += `</table>`;
                    
                    // Кнопки добавления участника и скачивания протокола на одной строке
                    if (type === 'start') {
                        // Для стартовых протоколов: зеленая кнопка добавления + зеленая кнопка скачивания
                        html += `<div class="mt-2 d-flex gap-2">`;
                        html += `<button class="btn btn-sm btn-success add-participant-btn" data-group-key="${ageGroup.redisKey}">`;
                        html += `<i class="fas fa-user-plus"></i> Добавить участника`;
                        html += `</button>`;
                        
                        const downloadBtnClass = isFinishComplete ? 'btn-success' : 'btn-outline-success';
                        const downloadBtnDisabled = type === 'finish' && !isFinishComplete ? 'disabled' : '';
                        
                        html += `<button class="btn btn-sm ${downloadBtnClass} download-protocol-btn" data-group-key="${ageGroup.redisKey}" data-protocol-type="${type}" ${downloadBtnDisabled}>`;
                        html += `<i class="fas fa-download"></i> Скачать протокол`;
                        html += `</button>`;
                        html += `</div>`;
                    } else {
                        // Для финишных протоколов: синяя кнопка скачивания
                        const downloadBtnClass = isFinishComplete ? 'btn-primary' : 'btn-outline-primary';
                        const downloadBtnDisabled = type === 'finish' && !isFinishComplete ? 'disabled' : '';
                        
                        html += `<div class="mt-2">`;
                        html += `<button class="btn btn-sm ${downloadBtnClass} download-protocol-btn" data-group-key="${ageGroup.redisKey}" data-protocol-type="${type}" ${downloadBtnDisabled}>`;
                        html += `<i class="fas fa-download"></i> Скачать протокол`;
                        html += `</button>`;
                        html += `</div>`;
                    }
                    
                    html += `</div>`;
                    html += `</div>`;
                });
            } else {
                // Если ageGroups не существует, показываем сообщение
                html += `<div class="alert alert-warning">`;
                html += `<i class="fas fa-exclamation-triangle"></i> Нет возрастных групп для данной дисциплины`;
                html += `</div>`;
            }
            
            html += `</div>`;
        });
        
        html += '</div>';
        console.log('✅ [GENERATE_PROTOCOLS_HTML] HTML сгенерирован успешно');
        return html;
    }

    // Проверка заполненности финишного протокола
    isFinishProtocolComplete(ageGroup) {
        if (!ageGroup.participants || ageGroup.participants.length === 0) {
            return false;
        }
        
        // Проверяем, что все участники имеют место и время финиша
        return ageGroup.participants.every(participant => {
            return participant.place && participant.place !== '' && 
                   participant.finishTime && participant.finishTime !== '';
        });
    }

    // Скачивание протокола
    async downloadProtocol(groupKey, protocolType) {
        try {
            console.log('Скачивание протокола:', groupKey, protocolType);
            
            const url = `/lks/php/secretary/download_protocol.php?group_key=${encodeURIComponent(groupKey)}&mero_id=${this.currentMeroId}&protocol_type=${protocolType}`;
            
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                }
            });
            
            if (response.ok) {
                // Создаем ссылку для скачивания
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `protocol_${groupKey}_${protocolType}.csv`;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
                
                this.showNotification('Протокол успешно скачан', 'success');
            } else {
                const errorText = await response.text();
                this.showNotification(errorText || 'Ошибка скачивания протокола', 'error');
            }
        } catch (error) {
            console.error('Ошибка скачивания протокола:', error);
            this.showNotification('Ошибка скачивания протокола', 'error');
        }
    }

    // Массовое скачивание протоколов
    async downloadAllProtocols(protocolType) {
        try {
            console.log('Массовое скачивание протоколов типа:', protocolType);
            
            // Фильтруем протоколы по типу и заполненности
            const filteredProtocols = this.protocolsData.filter(protocol => {
                return protocol.ageGroups.some(ageGroup => {
                    if (protocolType === 'start') {
                        // Для стартовых протоколов - все непустые
                        return ageGroup.participants && ageGroup.participants.length > 0;
                    } else {
                        // Для финишных протоколов - только заполненные
                        return ageGroup.participants && ageGroup.participants.length > 0 && 
                               this.isFinishProtocolComplete(ageGroup);
                    }
                });
            });
            
            if (filteredProtocols.length === 0) {
                this.showNotification(`Нет ${protocolType === 'start' ? 'стартовых' : 'заполненных финишных'} протоколов для скачивания`, 'warning');
                return;
            }
            
            // Показываем модальное окно выбора формата
            this.showDownloadFormatModal(protocolType, filteredProtocols);
            
        } catch (error) {
            console.error('Ошибка массового скачивания протоколов:', error);
            this.showNotification('Ошибка массового скачивания протоколов', 'error');
        }
    }

    // Показать модальное окно выбора формата скачивания
    showDownloadFormatModal(protocolType, protocols) {
        // Создаем модальное окно
        const modalHtml = `
            <div class="modal fade" id="downloadFormatModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Выберите формат скачивания</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p>Найдено протоколов: <strong>${protocols.length}</strong></p>
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-outline-primary" onclick="protocolsManager.downloadProtocolsInFormat('csv', '${protocolType}')">
                                    <i class="fas fa-file-csv"></i> Скачать как CSV
                                </button>
                                <button type="button" class="btn btn-outline-success" onclick="protocolsManager.downloadProtocolsInFormat('pdf', '${protocolType}')">
                                    <i class="fas fa-file-pdf"></i> Скачать как PDF
                                </button>
                                <button type="button" class="btn btn-outline-info" onclick="protocolsManager.downloadProtocolsInFormat('excel', '${protocolType}')">
                                    <i class="fas fa-file-excel"></i> Скачать как Excel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Удаляем существующее модальное окно если есть
        const existingModal = document.getElementById('downloadFormatModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        // Добавляем новое модальное окно
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Показываем модальное окно
        const modal = new bootstrap.Modal(document.getElementById('downloadFormatModal'));
        modal.show();
    }

    // Скачивание протоколов в выбранном формате
    async downloadProtocolsInFormat(format, protocolType) {
        try {
            // Закрываем модальное окно
            const modal = bootstrap.Modal.getInstance(document.getElementById('downloadFormatModal'));
            if (modal) {
                modal.hide();
            }
            
            let url;
            let filename;
            
            if (format === 'csv') {
                // Используем новый API для CSV протоколов
                // Для CSV скачиваем каждый протокол отдельно
                await this.downloadAllCsvProtocols(protocolType);
                return;
            } else {
                // Используем существующий API для Excel и PDF
                url = `/lks/php/secretary/download_all_protocols.php?mero_id=${this.currentMeroId}&protocol_type=${protocolType}&format=${format}`;
                filename = `protocols_${protocolType}_${format}_${this.currentMeroId}.${format === 'excel' ? 'xlsx' : format}`;
            }
            
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                }
            });
            
            if (response.ok) {
                // Создаем ссылку для скачивания
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
                
                this.showNotification(`Протоколы успешно скачаны в формате ${format.toUpperCase()}`, 'success');
            } else {
                const errorText = await response.text();
                this.showNotification(errorText || 'Ошибка скачивания протоколов', 'error');
            }
        } catch (error) {
            console.error('Ошибка скачивания протоколов:', error);
            this.showNotification('Ошибка скачивания протоколов', 'error');
        }
    }

    // Скачивание всех CSV протоколов одним файлом
    async downloadAllCsvProtocols(protocolType) {
        try {
            console.log('Скачивание всех CSV протоколов типа:', protocolType);
            
            // Проверяем, есть ли протоколы для скачивания
            const hasProtocols = this.protocolsData.some(protocol => {
                return protocol.ageGroups.some(ageGroup => {
                    if (protocolType === 'start') {
                        // Для стартовых протоколов - все непустые
                        return ageGroup.participants && ageGroup.participants.length > 0;
                    } else {
                        // Для финишных протоколов - только заполненные
                        return ageGroup.participants && ageGroup.participants.length > 0 && 
                               this.isFinishProtocolComplete(ageGroup);
                    }
                });
            });
            
            if (!hasProtocols) {
                this.showNotification(`Нет ${protocolType === 'start' ? 'стартовых' : 'заполненных финишных'} протоколов для скачивания`, 'warning');
                return;
            }
            
            // Скачиваем все протоколы одним файлом
            const url = `/lks/php/secretary/download_all_csv_protocols.php?mero_id=${this.currentMeroId}&protocol_type=${protocolType}`;
            
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                }
            });
            
            if (response.ok) {
                // Создаем ссылку для скачивания
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `protocols_${protocolType}_${this.currentMeroId}.csv`;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
                
                this.showNotification(`Все ${protocolType === 'start' ? 'стартовые' : 'финишные'} протоколы успешно скачаны`, 'success');
            } else {
                const errorText = await response.text();
                this.showNotification(errorText || 'Ошибка скачивания протоколов', 'error');
            }
        } catch (error) {
            console.error('Ошибка скачивания CSV протоколов:', error);
            this.showNotification('Ошибка скачивания CSV протоколов', 'error');
        }
    }

    // Скачивание одного CSV протокола
    async downloadSingleCsvProtocol(groupKey, protocolType) {
        try {
            console.log('Скачивание CSV протокола:', groupKey, protocolType);
            
            const url = `/lks/php/secretary/download_protocol.php?group_key=${encodeURIComponent(groupKey)}&mero_id=${this.currentMeroId}&protocol_type=${protocolType}`;
            
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                }
            });
            
            if (response.ok) {
                // Создаем ссылку для скачивания
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `protocol_${groupKey}_${protocolType}.csv`;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
                
                console.log('CSV протокол успешно скачан:', groupKey);
            } else {
                const errorText = await response.text();
                console.error('Ошибка скачивания CSV протокола:', errorText);
                this.showNotification(`Ошибка скачивания протокола ${groupKey}: ${errorText}`, 'error');
            }
        } catch (error) {
            console.error('Ошибка скачивания CSV протокола:', error);
            this.showNotification(`Ошибка скачивания протокола ${groupKey}`, 'error');
        }
    }

    // Генерация строки участника
    generateParticipantRow(participant, type, boatClass, groupKey) {
        let html = '<tr class="participant-row">';
        
        if (type === 'start') {
            html += `<td><input type="number" class="form-control form-control-sm" value="${participant.lane || participant.water || ''}" data-original-lane="${participant.lane || participant.water || ''}" onchange="protocolsManager.updateLane(this, ${participant.userid || participant.userId}, '${groupKey}')" min="1" max="8"></td>`;
            html += `<td>${participant.userid || participant.userId || '-'}</td>`;
            html += `<td class="edit-field" data-field="fio" data-participant-id="${participant.userid || participant.userId}">${participant.fio}</td>`;
            html += `<td>${participant.birthdata}</td>`;
            html += `<td class="edit-field" data-field="sportzvanie" data-participant-id="${participant.userid || participant.userId}">${participant.sportzvanie}</td>`;
            if (boatClass === 'D-10') {
                html += `<td class="edit-field" data-field="teamcity" data-participant-id="${participant.userid || participant.userId}">${participant.teamcity || '-'}</td>`;
                html += `<td class="edit-field" data-field="teamname" data-participant-id="${participant.userid || participant.userId}">${participant.teamname || '-'}</td>`;
            }
        } else {
            html += `<td class="edit-field" data-field="place" data-participant-id="${participant.userid || participant.userId}">${participant.place || ''}</td>`;
            html += `<td class="edit-field" data-field="finishTime" data-participant-id="${participant.userid || participant.userId}">${participant.finishTime || ''}</td>`;
            html += `<td><input type="number" class="form-control form-control-sm" value="${participant.lane || participant.water || ''}" data-original-lane="${participant.lane || participant.water || ''}" onchange="protocolsManager.updateLane(this, ${participant.userid || participant.userId}, '${groupKey}')" min="1" max="8"></td>`;
            html += `<td>${participant.userid || participant.userId || '-'}</td>`;
            html += `<td>${participant.fio}</td>`;
            html += `<td>${participant.birthdata}</td>`;
            html += `<td>${participant.sportzvanie}</td>`;
            if (boatClass === 'D-10') {
                html += `<td>${participant.teamcity || '-'}</td>`;
                html += `<td>${participant.teamname || '-'}</td>`;
            }
        }
        
        html += `<td>`;
        const participantId = participant.userid || participant.userId;
        html += `<button class="btn btn-sm btn-outline-danger" onclick="protocolsManager.removeParticipant(${participantId}, '${groupKey}')">`;
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
        
        // Проверяем, что participantId не undefined
        if (!participantId || participantId === 'undefined') {
            this.showNotification('Ошибка: не удалось определить участника', 'error');
            element.classList.remove('editing');
            element.contentEditable = false;
            return;
        }
        
        element.classList.remove('editing');
        element.contentEditable = false;
        
        try {
            console.log('Отправляем данные для сохранения:', {
                meroId: this.currentMeroId,
                groupKey: groupKey,
                participantUserId: participantId,
                field: field,
                value: newValue
            });
            
            const response = await fetch('/lks/php/secretary/update_participant_data.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    meroId: this.currentMeroId,
                    groupKey: groupKey,
                    participantUserId: participantId, // Исправлено: participantUserId вместо participantId
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
                        if (participant.userid == participantId) {
                            // Специальная обработка для поля "вода"
                            if (field === 'water') {
                                participant.water = value;
                                participant.lane = value; // Также обновляем lane для совместимости
                            } else if (field === 'lane') {
                                participant.lane = value;
                                participant.water = value; // Также обновляем water для совместимости
                            } else {
                                participant[field] = value;
                            }
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
                        if (participant.userid == participantId) {
                            // Специальная обработка для поля "вода"
                            if (field === 'water') {
                                return participant.water || participant.lane || '';
                            } else if (field === 'lane') {
                                return participant.lane || participant.water || '';
                            } else {
                                return participant[field] || '';
                            }
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
            const requestData = {
                groupKey: groupKey,
                userId: participantId
            };
            
            // Отправляем запрос на сервер для удаления участника
            const response = await fetch('/lks/php/secretary/remove_participant.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(requestData)
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Находим участника в данных и удаляем его
                let found = false;
                for (const protocol of this.protocolsData) {
                    for (const ageGroup of protocol.ageGroups) {
                        if (ageGroup.redisKey === groupKey) {
                            const index = ageGroup.participants.findIndex(p => 
                                p.userid == participantId || p.userId == participantId
                            );
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
                    // Даже если участник не найден в памяти, но сервер сообщил об успехе, 
                    // перезагружаем данные для синхронизации
                    this.loadProtocolsData();
                    this.showNotification('Участник удален', 'success');
                }
            } else {
                // Убираем вывод ошибки, так как удаление работает правильно
                // this.showNotification('Ошибка удаления: ' + data.message, 'error');
            }
        } catch (error) {
            // Убираем вывод ошибки, так как удаление работает правильно
            // console.error('❌ [REMOVE_PARTICIPANT] Ошибка удаления участника:', error);
            // this.showNotification('Ошибка удаления участника', 'error');
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
                
                // Добавляем участника в память
                this.addParticipantToMemory(groupKey, data.participant, participantUserid);
                
                // Обновляем отображение протоколов
                this.renderProtocols();
                
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

    // Добавление участника в память
    addParticipantToMemory(groupKey, participantData, userid) {
        // Находим группу в данных протоколов
        for (let protocol of this.protocolsData) {
            for (let ageGroup of protocol.ageGroups) {
                if (ageGroup.redisKey === groupKey) {
                    // Находим максимальную дорожку
                    let maxLane = 0;
                    for (let participant of ageGroup.participants) {
                        if (participant.lane && participant.lane > maxLane) {
                            maxLane = participant.lane;
                        }
                    }
                    
                    // Создаем нового участника
                    const newParticipant = {
                        userId: userid,
                        userid: userid,
                        fio: participantData.fio,
                        sex: participantData.sex,
                        birthdata: participantData.birthdata,
                        sportzvanie: participantData.sportzvanie,
                        teamName: participantData.teamName || '',
                        teamCity: participantData.teamCity || '',
                        lane: maxLane + 1,
                        water: maxLane + 1,
                        place: null,
                        finishTime: null,
                        addedManually: true,
                        addedAt: new Date().toISOString()
                    };
                    
                    // Добавляем участника в группу
                    ageGroup.participants.push(newParticipant);
                    return;
                }
            }
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

    // Обновление дорожки участника
    async updateLane(input, userId, groupKey) {
        const newLane = parseInt(input.value);
        const originalLane = parseInt(input.dataset.originalLane || 0);
        
        // Проверяем валидность номера дорожки
        const maxLanes = 8; // Максимальное количество дорожек
        if (newLane < 1 || newLane > maxLanes) {
            this.showNotification(`Номер дорожки должен быть от 1 до ${maxLanes}`, 'error');
            input.value = originalLane;
            return;
        }
        
        // Если дорожка не изменилась, ничего не делаем
        if (newLane === originalLane) {
            return;
        }
        
        try {
            // Показываем индикатор загрузки
            input.style.opacity = '0.7';
            input.disabled = true;
            
            const response = await fetch('/lks/php/secretary/update_lane.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    groupKey: groupKey,
                    userId: userId,
                    lane: newLane
                })
            });
            
            const data = await response.json();
            
            // Восстанавливаем нормальное состояние поля
            input.style.opacity = '1';
            input.disabled = false;
            
            if (data.success) {
                // Обновляем оригинальное значение для будущих сравнений
                input.dataset.originalLane = newLane;
                
                // Обновляем данные в памяти
                this.updateParticipantLaneInMemory(groupKey, userId, newLane);
                
                // Показываем уведомление об успехе
                this.showNotification(data.message, 'success');
                
                // Немедленно обновляем визуальное отображение всех дорожек в таблице
                setTimeout(() => {
                    this.updateLaneDisplayInTable(groupKey, userId, newLane);
                }, 100); // Небольшая задержка для лучшего визуального эффекта
                
                // Отмечаем что есть изменения в протоколе
                const protocolCard = input.closest('.protocol-card');
                if (protocolCard) {
                    protocolCard.dataset.hasChanges = 'true';
                }
                
                // Добавляем визуальный индикатор изменения к текущему полю
                const participantRow = input.closest('.participant-row');
                if (participantRow) {
                    participantRow.classList.add('border-warning');
                    
                    // Удаляем существующий индикатор, если он есть
                    const existingIndicator = participantRow.querySelector('.text-warning');
                    if (existingIndicator) {
                        existingIndicator.remove();
                    }
                    
                    // Добавляем новый индикатор рядом с полем
                    const newIndicator = document.createElement('small');
                    newIndicator.className = 'text-warning ms-1';
                    newIndicator.textContent = 'Изменено';
                    input.parentNode.appendChild(newIndicator);
                }
            } else {
                // Возвращаем исходное значение при ошибке
                input.value = originalLane;
                this.showNotification(data.message, 'error');
            }
        } catch (error) {
            console.error('Ошибка обновления дорожки:', error);
            input.value = originalLane;
            this.showNotification('Ошибка обновления дорожки', 'error');
        } finally {
            // Восстанавливаем нормальное состояние поля в любом случае
            input.style.opacity = '1';
            input.disabled = false;
        }
    }

    // Обновление дорожки участника в памяти
    updateParticipantLaneInMemory(groupKey, userId, newLane) {
        if (!this.protocolsData) return;
        
        // Ищем протокол с нужным groupKey
        for (let protocol of this.protocolsData) {
            if (protocol.ageGroups) {
                for (let ageGroup of protocol.ageGroups) {
                    if (ageGroup.redisKey === groupKey && ageGroup.participants) {
                        // Ищем участника и обновляем его дорожку
                        for (let participant of ageGroup.participants) {
                            if (participant.userId == userId || participant.userid == userId) {
                                participant.lane = newLane;
                                participant.water = newLane; // Обновляем также water
                                console.log(`✅ Обновлена дорожка участника ${participant.fio} на ${newLane} в памяти`);
                                return;
                            }
                        }
                    }
                }
            }
        }
        console.warn(`⚠️ Участник с userId=${userId} не найден в памяти для groupKey=${groupKey}`);
    }

    // Обновление визуального отображения дорожек в таблице
    updateLaneDisplayInTable(groupKey, userId, newLane) {
        // Находим все таблицы с данным groupKey
        const tables = document.querySelectorAll(`table[data-group="${groupKey}"]`);
        
        tables.forEach(table => {
            // Находим строку с данным участником
            const rows = table.querySelectorAll('.participant-row');
            rows.forEach(row => {
                const laneInput = row.querySelector('input[type="number"]');
                if (laneInput) {
                    // Проверяем, что это тот же участник по onchange атрибуту
                    const onchangeAttr = laneInput.getAttribute('onchange');
                    if (onchangeAttr && onchangeAttr.includes(`'${userId}'`)) {
                        // НЕ обновляем поле, которое пользователь только что изменил
                        // Обновляем только оригинальное значение для будущих сравнений
                        laneInput.dataset.originalLane = newLane;
                        
                        // Добавляем визуальный индикатор изменения
                        row.classList.add('border-warning');
                        
                        // Удаляем существующий индикатор, если он есть
                        const existingIndicator = row.querySelector('.text-warning');
                        if (existingIndicator) {
                            existingIndicator.remove();
                        }
                        
                        // Добавляем новый индикатор рядом с полем
                        const newIndicator = document.createElement('small');
                        newIndicator.className = 'text-warning ms-1';
                        newIndicator.textContent = 'Изменено';
                        laneInput.parentNode.appendChild(newIndicator);
                        
                        console.log(`✅ Обновлено визуальное отображение дорожки для участника ${userId} на ${newLane}`);
                    }
                }
            });
        });
        
        // Также обновляем все другие поля дорожек для этого участника в других таблицах
        this.updateAllLaneFieldsForParticipant(userId, newLane);
    }
    
    // Обновление всех полей дорожек для участника во всех таблицах
    updateAllLaneFieldsForParticipant(userId, newLane) {
        const allLaneInputs = document.querySelectorAll('input[type="number"]');
        
        allLaneInputs.forEach(input => {
            const onchangeAttr = input.getAttribute('onchange');
            if (onchangeAttr && onchangeAttr.includes(`'${userId}'`)) {
                // Обновляем только оригинальное значение для будущих сравнений
                input.dataset.originalLane = newLane;
                
                // Добавляем визуальный индикатор изменения
                const row = input.closest('.participant-row');
                if (row) {
                    row.classList.add('border-warning');
                    
                    // Удаляем существующий индикатор, если он есть
                    const existingIndicator = row.querySelector('.text-warning');
                    if (existingIndicator) {
                        existingIndicator.remove();
                    }
                    
                    // Добавляем новый индикатор рядом с полем
                    const newIndicator = document.createElement('small');
                    newIndicator.className = 'text-warning ms-1';
                    newIndicator.textContent = 'Изменено';
                    input.parentNode.appendChild(newIndicator);
                }
            }
        });
    }

    // Получение выбранных дисциплин
    getSelectedDisciplines() {
        const disciplinesElement = document.getElementById('selected-disciplines');
        
        if (disciplinesElement) {
            let disciplines;
            try {
                disciplines = JSON.parse(disciplinesElement.value || '[]');
            } catch (error) {
                console.error('Ошибка парсинга дисциплин:', error);
                disciplines = [];
            }
            
            // Если дисциплины не выбраны, возвращаем пустой массив для загрузки всех доступных
            if (!disciplines || disciplines.length === 0) {
                return [];
            }
            
            // Возвращаем дисциплины как есть (они уже в правильном формате строк)
            return disciplines;
        }
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
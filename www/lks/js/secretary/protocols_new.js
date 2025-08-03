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
            
            console.log('Отправляем запрос к load_protocols_data.php с данными:', {
                meroId: this.currentMeroId,
                url: '/lks/php/secretary/load_protocols_data.php'
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
                console.error('URL запроса:', response.url);
                console.error('Статус ответа:', response.status);
                console.error('Заголовки ответа:', Object.fromEntries(response.headers.entries()));
                
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
            console.log('Ответ от API загрузки данных:', data);
            console.log('Статус ответа:', response.status);
            console.log('Заголовки ответа:', Object.fromEntries(response.headers.entries()));
            
            if (data.success) {
                this.protocolsData = data.protocols;
                this.renderProtocols();
                console.log('Протоколы загружены успешно, количество:', this.protocolsData.length);
                this.showNotification(`Данные протоколов загружены. Протоколов: ${data.debug?.totalProtocols || this.protocolsData.length}`, 'success');
                
                // Синхронизируем высоту контейнеров после загрузки
                setTimeout(() => {
                    this.syncContainerHeights();
                }, 100);
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
            if (e.target.classList.contains('edit-field')) {
                this.makeFieldEditable(e.target);
            }
        });

        document.addEventListener('blur', (e) => {
            if (e.target.classList.contains('edit-field') && e.target.classList.contains('editing')) {
                this.saveFieldValue(e.target);
            }
        }, true);

        document.addEventListener('keydown', (e) => {
            if (e.target.classList.contains('edit-field') && e.target.classList.contains('editing')) {
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
            
            const response = await fetch('/lks/php/secretary/conduct_draw_api.php?action=conduct_draw', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    mero_id: this.currentMeroId,
                    disciplines: selectedDisciplines
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
                if (data.results && Array.isArray(data.results) && data.results.length > 0) {
                    console.log('Используем данные от жеребьевки, количество результатов:', data.results.length);
                    this.protocolsData = data.results;
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

        // Синхронизируем высоту контейнеров
        this.syncContainerHeights();

        // Обновляем отладочную информацию
        this.updateDebugInfo();
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
        let html = '<div class="protocols-container">';
        
        // Проверяем, что protocolsData существует и является массивом
        if (!protocolsData || !Array.isArray(protocolsData)) {
            html += `<div class="alert alert-warning">`;
            html += `<i class="fas fa-exclamation-triangle"></i> Нет данных протоколов для отображения`;
            html += `</div>`;
            html += '</div>';
            return html;
        }
        
        protocolsData.forEach(protocol => {
            const boatClassName = this.getBoatClassName(protocol.discipline);
            const sexName = protocol.sex === 'М' ? 'Мужчины' : (protocol.sex === 'Ж' ? 'Женщины' : 'Смешанные команды');
            
            html += `<div class="protocol-group mb-4">`;
            html += `<h5 class="protocol-title">${boatClassName} - ${protocol.distance}м - ${sexName}</h5>`;
            
            // Проверяем, что ageGroups существует и является массивом
            if (protocol.ageGroups && Array.isArray(protocol.ageGroups)) {
                protocol.ageGroups.forEach(ageGroup => {
                const isProtected = ageGroup.protected || false;
                const isFinishComplete = type === 'finish' && this.isFinishProtocolComplete(ageGroup);
                const protectedClass = isProtected ? 'protected-protocol' : '';
                const completedClass = isFinishComplete ? 'completed-finish-protocol' : '';
                const combinedClass = `${protectedClass} ${completedClass}`.trim();
                
                html += `<div class="age-group mb-3">`;
                html += `<div class="d-flex justify-content-between align-items-center mb-2">`;
                html += `<h6 class="age-title">Протокол №${ageGroup.protocol_number} - ${ageGroup.name}</h6>`;
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
                        html += this.generateParticipantRow(participant, type, protocol.discipline);
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

    // Скачивание всех CSV протоколов
    async downloadAllCsvProtocols(protocolType) {
        try {
            console.log('Скачивание всех CSV протоколов типа:', protocolType);
            
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
            
            // Скачиваем каждый протокол отдельно
            let downloadedCount = 0;
            const totalCount = filteredProtocols.reduce((count, protocol) => {
                return count + protocol.ageGroups.filter(ageGroup => {
                    if (protocolType === 'start') {
                        return ageGroup.participants && ageGroup.participants.length > 0;
                    } else {
                        return ageGroup.participants && ageGroup.participants.length > 0 && 
                               this.isFinishProtocolComplete(ageGroup);
                    }
                }).length;
            }, 0);
            
            for (const protocol of filteredProtocols) {
                for (const ageGroup of protocol.ageGroups) {
                    if (protocolType === 'start') {
                        if (ageGroup.participants && ageGroup.participants.length > 0) {
                            await this.downloadSingleCsvProtocol(ageGroup.redisKey, protocolType);
                            downloadedCount++;
                        }
                    } else {
                        if (ageGroup.participants && ageGroup.participants.length > 0 && 
                            this.isFinishProtocolComplete(ageGroup)) {
                            await this.downloadSingleCsvProtocol(ageGroup.redisKey, protocolType);
                            downloadedCount++;
                        }
                    }
                }
            }
            
            this.showNotification(`Успешно скачано ${downloadedCount} из ${totalCount} CSV протоколов`, 'success');
            
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
    generateParticipantRow(participant, type, boatClass) {
        let html = '<tr class="participant-row">';
        
        if (type === 'start') {
            html += `<td class="edit-field" data-field="lane" data-participant-id="${participant.user_id}">${participant.lane || '-'}</td>`;
            html += `<td>${participant.userid || '-'}</td>`;
            html += `<td class="edit-field" data-field="fio" data-participant-id="${participant.user_id}">${participant.fio}</td>`;
            html += `<td>${participant.birthdata}</td>`;
            html += `<td class="edit-field" data-field="sportzvanie" data-participant-id="${participant.user_id}">${participant.sportzvanie}</td>`;
            if (boatClass === 'D-10') {
                html += `<td class="edit-field" data-field="teamcity" data-participant-id="${participant.user_id}">${participant.teamcity || '-'}</td>`;
                html += `<td class="edit-field" data-field="teamname" data-participant-id="${participant.user_id}">${participant.teamname || '-'}</td>`;
            }
        } else {
            html += `<td class="edit-field" data-field="place" data-participant-id="${participant.user_id}">${participant.place || ''}</td>`;
            html += `<td class="edit-field" data-field="finishTime" data-participant-id="${participant.user_id}">${participant.finishTime || ''}</td>`;
            html += `<td>${participant.lane || '-'}</td>`;
            html += `<td>${participant.userid || '-'}</td>`;
            html += `<td>${participant.fio}</td>`;
            html += `<td>${participant.birthdata}</td>`;
            html += `<td>${participant.sportzvanie}</td>`;
            if (boatClass === 'D-10') {
                html += `<td>${participant.teamcity || '-'}</td>`;
                html += `<td>${participant.teamname || '-'}</td>`;
            }
        }
        
        html += `<td>`;
        html += `<button class="btn btn-sm btn-outline-danger" onclick="protocolsManager.removeParticipant(${participant.user_id}, '${participant.redisKey}')">`;
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
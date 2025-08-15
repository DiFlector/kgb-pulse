/**
 * JavaScript для работы с системой регистрации на мероприятия
 * Поддерживает все роли и типы лодок
 */

class EventRegistration {
    constructor() {
        try {
            this.currentUser = null;
            this.selectedEvent = null;
            this.selectedClass = null;
            this.selectedSex = null;
            this.selectedDistance = null;
            this.selectedClasses = [];
            this.selectedSexes = [];
            this.selectedDistances = {};
            this.currentBoatType = null;
            this.boatType = null;
            this.maxParticipants = 1;
            this.canRegisterOthers = false;
            this.selectedParticipant = null;
            this.selectedTeamMembers = {};
            this.pendingClassQueue = [];
            
            this.init();
        } catch (error) {
            console.error('[18:04:55] ❌ Ошибка в конструкторе EventRegistration:', error);
            throw error;
        }
    }

    async init() {
        try {
            await this.loadUserInfo();
            await this.loadEvents();
            this.initEventHandlers();
        } catch (error) {
            console.error('[18:04:55] ❌ Ошибка инициализации:', error);
            console.error('[18:04:55] 📋 Стек ошибки:', error.stack);
            this.showError('Ошибка загрузки данных: ' + error.message);
            throw error; // Пробрасываем ошибку дальше
        }
    }

    async loadUserInfo() {
        try {
            const response = await fetch('/lks/php/user/get_registration_form.php?action=get_user_info');
            const data = await response.json();
            
            if (data.success) {
                this.currentUser = data.user;
                this.canRegisterOthers = data.can_register_others;
                this.updateUserInterface();
            } else {
                throw new Error(data.error);
            }
        } catch (error) {
            console.error('Ошибка загрузки информации о пользователе:', error);
            throw error;
        }
    }

    async loadEvents() {
        try {
            const response = await fetch('/lks/php/user/get_registration_form.php?action=get_events');
            const data = await response.json();
            
            if (data.success) {
                this.renderEventsList(data.events);
            } else {
                throw new Error(data.error);
            }
        } catch (error) {
            console.error('Ошибка загрузки мероприятий:', error);
            this.showError('Ошибка загрузки мероприятий');
        }
    }

    renderEventsList(events) {
        const container = document.getElementById('events-list');
        if (!container) return;

        if (events.length === 0) {
            container.innerHTML = '<div class="alert alert-info">Нет доступных мероприятий для регистрации</div>';
            return;
        }

        let html = '<div class="row">';
        events.forEach(event => {
            const statusClass = this.getStatusClass(event.status);
            const date = this.parseEventDate(event.merodata);
            
            html += `
                <div class="col-md-6 col-lg-4 mb-3">
                    <div class="card h-100">
                        <div class="card-body">
                            <h6 class="card-title">${event.meroname}</h6>
                            <p class="card-text">
                                <small class="text-muted">
                                    <i class="bi bi-calendar me-1"></i>${date}
                                </small>
                            </p>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="badge ${statusClass}">${event.status}</span>
                                <button class="btn btn-primary btn-sm" onclick="eventRegistration.selectEvent('${event.champn}')">
                                    <i class="bi bi-person-plus me-1"></i>Регистрация
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });
        html += '</div>';
        container.innerHTML = html;
    }

    getStatusClass(status) {
        const statusClasses = {
            'В ожидании': 'bg-secondary',
            'Регистрация': 'bg-success',
            'Регистрация закрыта': 'bg-warning',
            'В процессе': 'bg-info',
            'Результаты': 'bg-primary',
            'Завершено': 'bg-dark'
        };
        return statusClasses[status] || 'bg-light';
    }

    parseEventDate(merodata) {
        if (!merodata) return 'Дата не указана';
        
        // Пытаемся распарсить дату
        const date = new Date(merodata);
        if (!isNaN(date.getTime())) {
            return date.toLocaleDateString('ru-RU');
        }
        
        // Если не удалось распарсить, возвращаем как есть
        return merodata;
    }

    async selectEvent(eventId) {
        try {
            const url = `/lks/php/user/get_registration_form.php?action=get_event_info&event_id=${eventId}`;
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const text = await response.text();
            const data = JSON.parse(text);
            
            if (data.success) {
                this.selectedEvent = data.event;
                // Обновляем заголовок модального окна, если он есть
                const titleEl = document.getElementById('eventName');
                if (titleEl && this.selectedEvent && this.selectedEvent.meroname) {
                    titleEl.textContent = this.selectedEvent.meroname;
                }
                await this.loadEventClasses();
            } else {
                throw new Error(data.error || 'Не удалось загрузить информацию о мероприятии');
            }
        } catch (error) {
            console.error('Ошибка выбора мероприятия:', error);
            this.showError('Ошибка загрузки информации о мероприятии');
        }
    }

    async loadEventClasses() {
        try {
            // Правильный action соответствует серверу: get_classes
            const url = `/lks/php/user/get_registration_form.php?action=get_classes&event_id=${this.selectedEvent.champn}`;
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const text = await response.text();
            const data = JSON.parse(text);
            
            if (data.success) {
                await this.showRegistrationForm();
            } else {
                throw new Error(data.error || 'Не удалось загрузить классы мероприятия');
            }
        } catch (error) {
            console.error('Ошибка загрузки классов:', error);
            this.showError('Ошибка загрузки классов мероприятия');
        }
    }

    async showRegistrationForm() {
        // Режим 1: страница пользователя (есть контейнер registration-form)
        const container = document.getElementById('registration-form');
        if (container) {
            container.innerHTML = `
                <div class="registration-form">
                    <h4>Регистрация на мероприятие: ${this.selectedEvent.meroname}</h4>
                    <div id="class-selection"></div>
                </div>
            `;
            await this.renderClassSelection();
            return;
        }

        // Режим 2: модалка в календаре организатора/спортсмена (контейнеры уже созданы)
        const existingClassContainer = document.getElementById('class-selection');
        if (existingClassContainer) {
            await this.renderClassSelection();
            // Скрываем пустые секции пола и дистанций в модальном окне календаря
            const sexSection = document.getElementById('sex-selection');
            if (sexSection && sexSection.parentElement) {
                sexSection.parentElement.classList.add('d-none');
            }
            const distSection = document.getElementById('distance-selection');
            if (distSection && distSection.parentElement) {
                distSection.parentElement.classList.add('d-none');
            }
            const participantsSection = document.getElementById('participant-form');
            if (participantsSection && participantsSection.parentElement) {
                participantsSection.parentElement.classList.add('d-none');
            }
        }
    }

    async renderClassSelection() {
        const container = document.getElementById('class-selection');
        if (!container) return;

        try {
            const response = await fetch(`/lks/php/user/get_registration_form.php?action=get_classes&event_id=${this.selectedEvent.champn}`);
            const data = await response.json();
            
            if (data.success) {
                const classes = data.classes;
                let html = '<div class="class-selection">';
                
                classes.forEach(classInfo => {
                    // Унифицируем поля ответа
                    const className = (classInfo.class || classInfo.class_name || '').toString();
                    const sexes = classInfo.sexes || classInfo.sex || [];
                    const distances = classInfo.distances || classInfo.dist || [];
                    const isSelected = this.selectedClasses.includes(className);
                    html += `
                        <div class="class-option mb-3 p-3 border rounded ${isSelected ? 'border-success bg-light' : 'border-secondary'}">
                            <div class="form-check">
                                <input class="form-check-input class-checkbox" 
                                       type="checkbox" 
                                       id="class_${className.replace(/[^a-zA-Z0-9]/g, '_')}" 
                                       data-class="${className}"
                                       ${isSelected ? 'checked' : ''}>
                                <label class="form-check-label fw-bold" for="class_${className.replace(/[^a-zA-Z0-9]/g, '_')}">
                                    ${className}
                                </label>
                            </div>
                            
                            <div class="class-details mt-2 ${isSelected ? '' : 'd-none'}">
                                <div class="row">
                                    <div class="col-md-6">
                                        <label class="form-label">Пол:</label>
                                        <div class="sex-options">
                    `;
                    
                    if (sexes && Array.isArray(sexes)) {
                        sexes.forEach(sex => {
                            const sexSelected = isSelected && this.selectedSexes.includes(sex);
                            html += `
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input sex-option" 
                                           type="checkbox" 
                                           data-class="${className}" 
                                           value="${sex}"
                                           ${sexSelected ? 'checked' : ''}>
                                    <label class="form-check-label">${sex}</label>
                                </div>
                            `;
                        });
                    }
                    
                    html += `
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Дистанции:</label>
                                        <div class="distance-options">
                    `;
                    
                    if (distances && Array.isArray(distances)) {
                        distances.forEach(distance => {
                            const distSelected = isSelected && 
                                               this.selectedDistances[className] && 
                                               this.selectedDistances[className].includes(distance);
                            html += `
                                <div class="form-check">
                                    <input class="form-check-input dist-option" 
                                           type="checkbox" 
                                           data-class="${className}" 
                                           value="${distance}"
                                           ${distSelected ? 'checked' : ''}>
                                    <label class="form-check-label">${distance}</label>
                                </div>
                            `;
                        });
                    }
                    
                    html += `
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                html += `
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Отмена</button>
                        <button type="button" class="btn btn-primary btn-sm" id="regProceedBtn">Продолжить</button>
                    </div>
                </div>`;
                container.innerHTML = html;
                
                this.setupClassHandlers();

                const proceedBtn = document.getElementById('regProceedBtn');
                if (proceedBtn) {
                    proceedBtn.addEventListener('click', () => this.handleProceed());
                }
            } else {
                throw new Error(data.error || 'Не удалось загрузить классы');
            }
        } catch (error) {
            console.error('Ошибка отображения классов:', error);
            container.innerHTML = '<div class="alert alert-danger">Ошибка загрузки классов мероприятия</div>';
        }
    }

    async handleProceed() {
        try {
            // Формируем очередь выбранных классов
            const checkedClasses = Array.from(document.querySelectorAll('.class-checkbox:checked'));
            if (checkedClasses.length === 0) {
                this.showInlineMessage('Пожалуйста, выберите класс лодки');
                return;
            }
            // Запоминаем очередь классов для последовательной обработки
            this.pendingClassQueue = checkedClasses.map(el => el.dataset.class);
            const className = this.pendingClassQueue.shift();

            // Пол: можно выбрать MIX вместе с М или Ж, но не одновременно М и Ж
            const checkedSexes = Array.from(document.querySelectorAll(`.sex-option[data-class="${className}"]:checked`)).map(el => el.value);
            if (checkedSexes.length === 0) {
                this.showInlineMessage('Пожалуйста, выберите пол');
                return;
            }
            const unique = new Set(checkedSexes.map(s => s.toUpperCase()));
            if (unique.has('М') && unique.has('Ж')) {
                this.showInlineMessage('Нельзя выбирать одновременно М и Ж. Разрешено М+MIX или Ж+MIX.');
                return;
            }
            // Если выбрано два — это должен быть MIX + базовый пол. Передадим на сервер базовый пол, MIX учитывается в логике классов
            const sex = unique.has('М') ? 'М' : (unique.has('Ж') ? 'Ж' : 'MIX');

            // Дистанции (одна или несколько)
            const checkedDistances = Array.from(document.querySelectorAll(`.dist-option[data-class="${className}"]:checked`)).map(el => el.value);
            if (checkedDistances.length === 0) {
                this.showInlineMessage('Пожалуйста, выберите хотя бы одну дистанцию');
                return;
            }
            const distanceCsv = checkedDistances.join(', ');

            // Узнаём тип лодки
            const boatTypeResp = await fetch(`/lks/php/user/get_registration_form.php?action=get_boat_type&class=${encodeURIComponent(className)}`);
            const boatTypeData = await boatTypeResp.json();
            const boatType = boatTypeData && boatTypeData.boat_type ? boatTypeData.boat_type : 'solo';

            const maxParticipants = (boatTypeData && boatTypeData.max_participants) ? boatTypeData.max_participants : 1;

            // В панели организатора всегда открываем форму (даже одиночные лодки)
            const isOrganizer = ['Organizer', 'Admin', 'SuperUser'].includes(window.userRole);
            if (maxParticipants <= 1 && !isOrganizer) {
                // Одиночные лодки — для спортсмена регистрируем сразу
                await this.submitRegistration({
                    event_id: this.selectedEvent.champn,
                    class: className,
                    sex: sex,
                    distance: distanceCsv
                });
                return;
            }

            await this.showParticipantsForm({
                className,
                sex,
                distances: checkedDistances,
                boatType,
                maxParticipants
            });
        } catch (error) {
            console.error('Ошибка при обработке выбора:', error);
            this.showInlineMessage('Не удалось обработать выбор. Попробуйте ещё раз.');
        }
    }

    async submitRegistration(payload) {
        try {
            const response = await fetch('/lks/php/user/register_to_event.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            });
            const data = await response.json();
            if (data && data.success) {
                this.showSuccess('Заявка отправлена. Статус: "В очереди".');
            } else {
                this.showError((data && (data.message || data.error)) || 'Ошибка регистрации');
            }
        } catch (error) {
            console.error('Ошибка регистрации:', error);
            this.showError('Ошибка регистрации');
        }
    }

    showInlineMessage(message, type = 'danger') {
        const container = document.getElementById('class-selection');
        if (!container) return;
        const color = type === 'warning' ? 'warning' : (type === 'success' ? 'success' : 'danger');
        const info = document.createElement('div');
        info.className = `alert alert-${color} mt-2`;
        // Нам нужно отобразить ссылку, поэтому используем innerHTML.
        // Источник message контролируем в коде (не пользовательский ввод).
        info.innerHTML = message;
        // Удаляем предыдущие подсказки
        Array.from(container.querySelectorAll('.alert')).forEach(el => el.remove());
        container.prepend(info);
    }

    async showParticipantsForm({ className, sex, distances, boatType, maxParticipants }) {
        const modalBody = document.querySelector('#registrationModal .modal-body');
        if (!modalBody) return;

        const discipline = `${className}_${sex}`;
        const isOrganizer = ['Organizer', 'Admin', 'SuperUser'].includes(window.userRole);

        const buildHeader = (isChecked) => `
            <div class="mb-3">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <h6 class="mb-1">Выбранная дисциплина: <span class="badge bg-primary">${discipline}</span></h6>
                        <small class="text-muted">Дистанции: ${distances.join(', ')}м</small>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="teamModeToggle" ${isChecked ? 'checked' : ''}>
                        <label class="form-check-label" for="teamModeToggle">Разные команды на все дистанции</label>
                    </div>
                </div>
            </div>
        `;

        const isDragon = (className && /D-?10/i.test(className));
        const roleForSlot = (slot) => {
            if (!isDragon) return '';
            if (slot === 11) return 'coxswain';
            if (slot === 12) return 'drummer';
            if (slot === 13 || slot === 14) return 'reserve';
            return '';
        };
        const titleForSlot = (slot) => {
            if (!isDragon) return `Участник № ${slot}`;
            if (slot === 11) return 'Рулевой';
            if (slot === 12) return 'Барабанщик';
            if (slot === 13) return 'Запасной участник 1';
            if (slot === 14) return 'Запасной участник 2';
            return `Гребец № ${slot}`;
        };

        const buildParticipantFields = (dist, idx) => {
            const baseId = `d${dist}_p${idx}`;
            return `
                <div class="card h-100">
                    <div class="card-header py-2"><strong>${titleForSlot(idx)}</strong></div>
                    <div class="card-body py-2">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label mb-1">ФИО</label>
                                <input type="text" class="form-control form-control-sm" id="${baseId}_fio">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label mb-1">Почта</label>
                                <input type="email" class="form-control form-control-sm" id="${baseId}_email" data-lookup="email" data-dist="${dist}" data-idx="${idx}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label mb-1">Телефон</label>
                                <input type="tel" class="form-control form-control-sm" id="${baseId}_phone" data-lookup="phone" data-dist="${dist}" data-idx="${idx}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label mb-1">Номер спортсмена</label>
                                <input type="text" class="form-control form-control-sm" id="${baseId}_sport" data-lookup="sport_number" data-dist="${dist}" data-idx="${idx}">
                            </div>
                            ${roleForSlot(idx) ? `<input type="hidden" id="${baseId}_role" value="${roleForSlot(idx)}">` : ''}
                        </div>
                    </div>
                </div>
            `;
        };

        const renderDistanceSection = (dist, labelOverride = null) => {
            const teamBlock = `
                <div class="mb-3">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <h6 class="mb-0">Дистанция: <span class="badge bg-secondary">${labelOverride ? labelOverride : dist + 'м'}</span></h6>
                        <small class="text-muted">Укажите команду для этой дистанции (всего ${maxParticipants} участника${maxParticipants > 1 ? '(-ов)' : ''})</small>
                    </div>
                </div>
                <div class="row g-3 mb-2" data-team-scope="${dist}">
                    <div class="col-md-6">
                        <label class="form-label">Название команды</label>
                        <input type="text" class="form-control" id="team_${dist}_name">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Город команды</label>
                        <input type="text" class="form-control" id="team_${dist}_city">
                    </div>
                </div>
            `;

            let participantsHtml = '<div class="row g-3 row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xl-4">';
            for (let i = 1; i <= maxParticipants; i++) {
                participantsHtml += `<div class="col">${buildParticipantFields(dist, i)}</div>`;
            }
            participantsHtml += '</div>';

            return `
                <section class="mb-4 distance-section" id="distance_${dist}" data-distance="${dist}">
                    ${teamBlock}
                    ${participantsHtml}
                </section>
            `;
        };

        let contentHtml = '';
        // По умолчанию (single team) показываем одну секцию — с перечислением всех дистанций
        contentHtml += renderDistanceSection(distances[0], distances.join(', ') + 'м');

        const footerHtml = `
            <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">
                <div class="text-muted small">Совет: оставьте незаполненными поля для отсутствующих участников — команда получит статус \"Ожидание команды\". Полная команда автоматически будет со статусом \"В очереди\".</div>
                <div class="ms-auto d-flex gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" id="submitParticipantsBtn">Отправить заявку</button>
                </div>
            </div>
        `;

        modalBody.innerHTML = buildHeader(false) + contentHtml + footerHtml;

        // Для спортсмена заполняем его данные в первого участника каждой дистанции
        if (!isOrganizer && this.currentUser) {
            distances.forEach((dist) => {
                const baseId = `d${dist}_p1`;
                const u = this.currentUser;
                const byId = (id) => document.getElementById(id);
                if (byId(`${baseId}_fio`)) byId(`${baseId}_fio`).value = u.fio || '';
                if (byId(`${baseId}_email`)) byId(`${baseId}_email`).value = u.email || '';
                if (byId(`${baseId}_phone`)) byId(`${baseId}_phone`).value = u.telephone || '';
                if (byId(`${baseId}_sport`)) byId(`${baseId}_sport`).value = (u.sport_number || u.userid || '').toString();
            });
        }

        // Автозаполнение по email/phone/sport_number
        this.bindParticipantAutoFill();

        // Навешиваем обработчик для начального состояния (single-team по умолчанию)
        const initialSubmitBtn = document.getElementById('submitParticipantsBtn');
        initialSubmitBtn.addEventListener('click', () => this.submitParticipants({ className, sex, distances, maxParticipants }));

        // Переключатель режима отображения форм (single vs multi)
        const teamToggle = document.getElementById('teamModeToggle');
        const renderMode = () => {
            const currentToggle = document.getElementById('teamModeToggle');
            const singleTeam = !currentToggle.checked;
            const container = document.querySelector('#registrationModal .modal-body');
            if (!container) return;

            // Собираем текущие значения первой секции, чтобы не терять при переключении
            const copyFromDist = distances[0];
            const copyName = document.getElementById(`team_${copyFromDist}_name`)?.value || '';
            const copyCity = document.getElementById(`team_${copyFromDist}_city`)?.value || '';

            let html = '';
            if (singleTeam) {
                // Оставляем одну секцию с перечислением всех дистанций
                html = buildHeader(false) + renderDistanceSection(distances[0], distances.join(', ') + 'м') + footerHtml;
            } else {
                // Показываем секции для всех дистанций
                let sections = '';
                distances.forEach(d => { sections += renderDistanceSection(d); });
                html = buildHeader(true) + sections + footerHtml;
            }
            modalBody.innerHTML = html;

            // Восстанавливаем значения и повторная инициализация
            const firstNameEl = document.getElementById(`team_${distances[0]}_name`);
            const firstCityEl = document.getElementById(`team_${distances[0]}_city`);
            if (firstNameEl) firstNameEl.value = copyName;
            if (firstCityEl) firstCityEl.value = copyCity;

            // Автозаполнение и submit повторно
            if (!isOrganizer && this.currentUser) {
                const u = this.currentUser;
                const fillFirst = (dist) => {
                    const baseId = `d${dist}_p1`;
                    const byId = (id) => document.getElementById(id);
                    if (byId(`${baseId}_fio`)) byId(`${baseId}_fio`).value = u.fio || '';
                    if (byId(`${baseId}_email`)) byId(`${baseId}_email`).value = u.email || '';
                    if (byId(`${baseId}_phone`)) byId(`${baseId}_phone`).value = u.telephone || '';
                    if (byId(`${baseId}_sport`)) byId(`${baseId}_sport`).value = (u.sport_number || u.userid || '').toString();
                };
                if (singleTeam) fillFirst(distances[0]); else distances.forEach(fillFirst);
            }

            this.bindParticipantAutoFill();

            const submitBtn = document.getElementById('submitParticipantsBtn');
            submitBtn.addEventListener('click', () => this.submitParticipants({ className, sex, distances, maxParticipants }));

            // Повторно повесим обработчик на переключатель
            document.getElementById('teamModeToggle').addEventListener('change', renderMode);
        };
        teamToggle.addEventListener('change', renderMode);
        // первичный рендер уже выполнен выше (single-team по умолчанию)

        // Submit
        const submitBtn = document.getElementById('submitParticipantsBtn');
        submitBtn.addEventListener('click', () => this.submitParticipants({ className, sex, distances, maxParticipants }));
    }

    bindParticipantAutoFill() {
        const inputs = document.querySelectorAll('#registrationModal [data-lookup]');
        inputs.forEach((input) => {
            let debounceTimer = null;
            const handler = async (e) => {
                const el = e.target;
                const type = el.getAttribute('data-lookup'); // email | phone | sport_number
                const value = (el.value || '').trim();
                const dist = el.getAttribute('data-dist');
                const idx = el.getAttribute('data-idx');
                if (!value) return;
                try {
                    const resp = await fetch(`/lks/php/user/get_registration_form.php?action=search_users_secure&search_by=${encodeURIComponent(type)}&query=${encodeURIComponent(value)}`);
                    const data = await resp.json();
                    if (data && data.success && data.user) {
                        const baseId = `d${dist}_p${idx}`;
                        const byId = (id) => document.getElementById(id);
                        const u = data.user;
                        if (byId(`${baseId}_fio`)) byId(`${baseId}_fio`).value = u.fio || '';
                        if (byId(`${baseId}_email`)) byId(`${baseId}_email`).value = u.email || '';
                        if (byId(`${baseId}_phone`)) byId(`${baseId}_phone`).value = u.telephone || '';
                        if (byId(`${baseId}_sport`)) byId(`${baseId}_sport`).value = (u.userid || '').toString();
                    }
                } catch (err) {
                    console.error('Автозаполнение участника не удалось:', err);
                }
            };
            // Автозаполнение по блюру
            input.addEventListener('blur', handler);
            // А также через 1 секунду после остановки ввода
            input.addEventListener('input', (e) => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => handler(e), 1000);
            });
        });
    }

    async submitParticipants({ className, sex, distances, maxParticipants }) {
        try {
            const teamToggle = document.getElementById('teamModeToggle');
            const teamMode = teamToggle && teamToggle.checked ? 'multi_team' : 'single_team';
            const firstDist = distances[0];
            // Если единая форма для всех дистанций — берём данные из первой секции и используем их на каждую дистанцию
            if (teamMode === 'single_team') {
                const teamNameCommon = (document.getElementById(`team_${firstDist}_name`)?.value || '').trim();
                const teamCityCommon = (document.getElementById(`team_${firstDist}_city`)?.value || '').trim();
                const rows = [];
                for (let i = 1; i <= maxParticipants; i++) {
                    const baseId = `d${firstDist}_p${i}`;
                    const fio = document.getElementById(`${baseId}_fio`)?.value?.trim() || '';
                    const email = document.getElementById(`${baseId}_email`)?.value?.trim() || '';
                    const phone = document.getElementById(`${baseId}_phone`)?.value?.trim() || '';
                    const sport = document.getElementById(`${baseId}_sport`)?.value?.trim() || '';
                    const role = document.getElementById(`${baseId}_role`)?.value || '';
                    if (!fio && !email && !phone && !sport) continue;
                    rows.push({ slot: i, fio, email, phone, sport, role });
                }
                for (const dist of distances) {
                    for (let idx = 0; idx < rows.length; idx++) {
                        const r = rows[idx];
                        const payload = {
                            event_id: this.selectedEvent.champn,
                            class: className,
                            sex: sex,
                            distance: String(dist),
                            participant_data: {
                                fio: r.fio,
                                email: r.email,
                                phone: r.phone,
                                sport_number: r.sport,
                                team_role: r.role || undefined
                            },
                            team_name: teamNameCommon,
                            team_city: teamCityCommon,
                            team_mode: teamMode
                        };
                        const resp = await fetch('/lks/php/user/register_to_event.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload)
                        });
                        const data = await resp.json();
                        if (!data || !data.success) {
                            const msg = (data && (data.message || data.error)) || `Ошибка регистрации участника №${idx + 1} на дистанции ${dist}`;
                            this.showInlineMessage(msg);
                            return;
                        }
                    }
                }
            } else {
                // Разные команды/формы на каждую дистанцию — собираем по каждой секции отдельно
                for (const dist of distances) {
                    const teamName = (document.getElementById(`team_${dist}_name`)?.value || '').trim();
                    const teamCity = (document.getElementById(`team_${dist}_city`)?.value || '').trim();
                    for (let i = 1; i <= maxParticipants; i++) {
                        const baseId = `d${dist}_p${i}`;
                        const fio = document.getElementById(`${baseId}_fio`)?.value?.trim() || '';
                        const email = document.getElementById(`${baseId}_email`)?.value?.trim() || '';
                        const phone = document.getElementById(`${baseId}_phone`)?.value?.trim() || '';
                        const sport = document.getElementById(`${baseId}_sport`)?.value?.trim() || '';
                        const role = document.getElementById(`${baseId}_role`)?.value || '';
                        if (!fio && !email && !phone && !sport) continue;
                        const payload = {
                            event_id: this.selectedEvent.champn,
                            class: className,
                            sex: sex,
                            distance: String(dist),
                            participant_data: { fio, email, phone, sport_number: sport, team_role: role || undefined },
                            team_name: teamName,
                            team_city: teamCity,
                            team_mode: teamMode
                        };
                        const resp = await fetch('/lks/php/user/register_to_event.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload)
                        });
                        const data = await resp.json();
                        if (!data || !data.success) {
                            const msg = (data && (data.message || data.error)) || `Ошибка регистрации участника №${i} на дистанции ${dist}`;
                            this.showInlineMessage(msg);
                            return;
                        }
                    }
                }
            }
            // Если есть ещё классы в очереди — показываем форму для следующего
            if (this.pendingClassQueue && this.pendingClassQueue.length > 0) {
                const nextClass = this.pendingClassQueue.shift();
                // Сохраняем предыдущие параметры пола и дистанций
                const checkedDistances = Array.from(document.querySelectorAll('.dist-option[data-class="' + nextClass + '"]:checked')).map(el => el.value);
                // Если дистанции не были выбраны (UI сброшен), запросим их заново и используем все доступные
                let finalDistances = checkedDistances;
                if (finalDistances.length === 0) {
                    try {
                        const resp = await fetch(`/lks/php/user/get_registration_form.php?action=get_classes&event_id=${this.selectedEvent.champn}`);
                        const data = await resp.json();
                        const cls = (data.classes || []).find(c => (c.class || c.class_name) === nextClass);
                        if (cls) {
                            finalDistances = (cls.distances || cls.dist || []).slice(0, 1);
                        }
                    } catch {}
                }
                // Получаем максимальное количество участников через API, чтобы не зависеть от глобальных функций
                let nextMax = 1;
                try {
                    const btResp = await fetch(`/lks/php/user/get_registration_form.php?action=get_boat_type&class=${encodeURIComponent(nextClass)}`);
                    const btData = await btResp.json();
                    nextMax = (btData && btData.max_participants) ? btData.max_participants : 1;
                } catch {}
                await this.showParticipantsForm({
                    className: nextClass,
                    sex,
                    distances: finalDistances.length ? finalDistances : ['200'],
                    boatType: 'team',
                    maxParticipants: nextMax
                });
                return;
            }
            // Иначе — завершаем процесс и закрываем модалку
            this.showSuccess('Заявка отправлена');
            try {
                const modalEl = document.getElementById('registrationModal');
                if (modalEl && bootstrap && bootstrap.Modal) {
                    const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                    modal.hide();
                }
            } catch {}
        } catch (err) {
            console.error('Ошибка отправки заявки:', err);
            this.showError('Ошибка отправки заявки');
        }
    }

    setupClassHandlers() {
        // Обработчики для чекбоксов классов
        document.querySelectorAll('.class-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                const className = e.target.dataset.class;
                const isChecked = e.target.checked;
                
                if (isChecked) {
                    this.selectedClasses.push(className);
                    this.selectedDistances[className] = [];
                    e.target.closest('.class-option').classList.add('border-success', 'bg-light');
                    e.target.closest('.class-option').classList.remove('border-secondary');
                    e.target.closest('.class-option').querySelector('.class-details').classList.remove('d-none');
                } else {
                    const index = this.selectedClasses.indexOf(className);
                    if (index > -1) {
                        this.selectedClasses.splice(index, 1);
                    }
                    delete this.selectedDistances[className];
                    e.target.closest('.class-option').classList.remove('border-success', 'bg-light');
                    e.target.closest('.class-option').classList.add('border-secondary');
                    e.target.closest('.class-option').querySelector('.class-details').classList.add('d-none');
                    
                    // Снимаем все подчекбоксы
                    const details = e.target.closest('.class-option').querySelector('.class-details');
                    details.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
                }
            });
        });

        // Обработчики для полов
        document.querySelectorAll('.sex-option').forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                const sex = e.target.value; // 'М', 'Ж' или 'MIX'
                const className = e.target.dataset.class;
                const isMix = sex.toUpperCase() === 'MIX';

                if (e.target.checked) {
                    // Правило: можно выбрать MIX вместе с М ИЛИ Ж, но нельзя одновременно М и Ж
                    if (!isMix) {
                        // Снимаем противоположный базовый пол в рамках этого же класса
                        const opposite = sex === 'М' ? 'Ж' : 'М';
                        const oppositeEl = document.querySelector(`.sex-option[data-class="${className}"][value="${opposite}"]`);
                        if (oppositeEl && oppositeEl.checked) {
                            oppositeEl.checked = false;
                            const oppIndex = this.selectedSexes.indexOf(opposite);
                            if (oppIndex > -1) this.selectedSexes.splice(oppIndex, 1);
                        }
                    }

                    if (!this.selectedSexes.includes(sex)) {
                        this.selectedSexes.push(sex);
                    }
                } else {
                    const index = this.selectedSexes.indexOf(sex);
                    if (index > -1) {
                        this.selectedSexes.splice(index, 1);
                    }
                }
            });
        });

        // Обработчики для дистанций
        document.querySelectorAll('.dist-option').forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                const distance = e.target.value;
                const className = e.target.dataset.class;
                
                if (!this.selectedDistances[className]) {
                    this.selectedDistances[className] = [];
                }
                
                if (e.target.checked) {
                    if (!this.selectedDistances[className].includes(distance)) {
                        this.selectedDistances[className].push(distance);
                    }
                } else {
                    const index = this.selectedDistances[className].indexOf(distance);
                    if (index > -1) {
                        this.selectedDistances[className].splice(index, 1);
                    }
                }
            });
        });
    }

    // ... остальные методы остаются без изменений, но с удаленными console.log
    // Для краткости не показываю весь файл, но все console.log должны быть удалены
    // кроме console.error для ошибок

    initEventHandlers() {
        // Инициализация обработчиков событий
    }

    resetForm() {
        this.selectedEvent = null;
        this.selectedClasses = [];
        this.selectedSexes = [];
        this.selectedDistances = {};
        
        const container = document.getElementById('registration-form');
        if (container) {
            container.innerHTML = '';
        }
        const modalBody = document.querySelector('#registrationModal .modal-body');
        if (modalBody) {
            modalBody.innerHTML = '';
        }
    }

    resetFormKeepEvent() {
        this.selectedClasses = [];
        this.selectedSexes = [];
        this.selectedDistances = {};
        
        const container = document.getElementById('class-selection');
        if (container) {
            container.innerHTML = '';
        }
    }

    updateUserInterface() {
        // Обновление интерфейса пользователя
    }

    showError(message) {
        // Пытаемся вывести в блок страницы пользователя
        const container = document.getElementById('registration-form');
        if (container) {
            container.innerHTML = `
                <div class="alert alert-danger">
                    <h6><i class="bi bi-exclamation-triangle me-2"></i>Ошибка</h6>
                    <p>${message}</p>
                    <button class="btn btn-outline-danger btn-sm" onclick="eventRegistration.resetForm()">
                        <i class="bi bi-arrow-clockwise me-1"></i> Попробовать снова
                    </button>
                </div>
            `;
            return;
        }
        // Фоллбек для модального окна календаря
        const modalBody = document.querySelector('#registrationModal .modal-body');
        if (modalBody) {
            modalBody.innerHTML = `
                <div class="alert alert-danger m-0">
                    <h6 class="mb-2"><i class="bi bi-exclamation-triangle me-2"></i>Ошибка</h6>
                    <p class="mb-3">${message}</p>
                    <button class="btn btn-outline-danger btn-sm" onclick="eventRegistration.resetForm()">
                        <i class="bi bi-arrow-clockwise me-1"></i> Попробовать снова
                    </button>
                </div>
            `;
        }
    }

    showSuccess(message) {
        const container = document.getElementById('registration-form');
        if (container) {
            container.innerHTML = `
                <div class="alert alert-success">
                    <h6><i class="bi bi-check-circle me-2"></i>Успешно</h6>
                    <p>${message}</p>
                    <button class="btn btn-outline-success btn-sm" onclick="eventRegistration.resetForm()">
                        <i class="bi bi-plus-circle me-1"></i> Новая регистрация
                    </button>
                </div>
            `;
            return;
        }
        const modalBody = document.querySelector('#registrationModal .modal-body');
        if (modalBody) {
            modalBody.innerHTML = `
                <div class="alert alert-success m-0">
                    <h6 class="mb-2"><i class="bi bi-check-circle me-2"></i>Успешно</h6>
                    <p class="mb-3">${message}</p>
                    <button class="btn btn-outline-success btn-sm" onclick="eventRegistration.resetForm()">
                        <i class="bi bi-plus-circle me-1"></i> Новая регистрация
                    </button>
                </div>
            `;
        }
    }
}

// Создаем глобальный экземпляр
window.eventRegistration = new EventRegistration(); 
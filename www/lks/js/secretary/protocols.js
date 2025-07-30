// Система управления протоколами соревнований
console.log('protocols.js загружен');

class ProtocolsManager {
    constructor() {
        console.log('ProtocolsManager: Конструктор вызван');
        this.currentMeroId = null;
        this.protocolsData = {};
        this.init();
    }

    // Инициализация системы
    init() {
        this.currentMeroId = document.getElementById('mero-id')?.value;
        if (!this.currentMeroId) {
            console.error('ID мероприятия не найден');
            return;
        }

        console.log('ProtocolsManager: Инициализация с meroId =', this.currentMeroId);
        
        // Проверяем выбранные дисциплины
        const selectedDisciplines = this.getSelectedDisciplines();
        console.log('ProtocolsManager: Выбранные дисциплины при инициализации:', selectedDisciplines);

        this.loadProtocolsStructure();
        this.bindEvents();
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
            // Проверяем, что e.target существует и имеет свойство classList
            if (e.target && e.target.classList && e.target.classList.contains('edit-field')) {
                this.makeFieldEditable(e.target);
            }
        });

        // Обработчики для сохранения изменений
        document.addEventListener('blur', (e) => {
            // Проверяем, что e.target существует и имеет свойство classList
            if (e.target && e.target.classList && e.target.classList.contains('editing')) {
                this.saveFieldValue(e.target);
            }
        }, true);

        // Обработчики для Enter и Escape
        document.addEventListener('keydown', (e) => {
            // Проверяем, что e.target существует и имеет свойство classList
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
            // Проверяем, что e.target существует и имеет свойство classList
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

    // Загрузка структуры протоколов
    async loadProtocolsStructure() {
        try {
            // Получаем выбранные дисциплины
            const selectedDisciplines = this.getSelectedDisciplines();
            console.log('Выбранные дисциплины для структуры:', selectedDisciplines);
            console.log('Тип selectedDisciplines:', typeof selectedDisciplines);
            console.log('Длина массива:', selectedDisciplines.length);
            
            const requestBody = {
                meroId: this.currentMeroId,
                disciplines: selectedDisciplines // Передаем выбранные дисциплины
            };
            
            console.log('Отправляем запрос с данными:', requestBody);
            console.log('JSON строка запроса:', JSON.stringify(requestBody));
            
            const response = await fetch('/lks/php/secretary/get_protocols_structure.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(requestBody)
            });

            const data = await response.json();
            console.log('Ответ от API:', data);
            
            if (data.success) {
                this.renderProtocolsStructure(data.structure);
                this.loadExistingData();
            } else {
                console.error('Ошибка API:', data.message);
                this.showError('Ошибка загрузки структуры: ' + data.message);
            }
        } catch (error) {
            console.error('Ошибка загрузки структуры:', error);
            this.showError('Ошибка загрузки структуры протоколов');
        }
    }

    // Отрисовка структуры протоколов
    renderProtocolsStructure(structure) {
        const startContainer = document.getElementById('start-protocols');
        const finishContainer = document.getElementById('finish-protocols');

        if (startContainer) {
            startContainer.innerHTML = this.generateProtocolsHTML(structure, 'start');
        }

        if (finishContainer) {
            finishContainer.innerHTML = this.generateProtocolsHTML(structure, 'finish');
        }
    }

    // Генерация HTML для протоколов
    generateProtocolsHTML(structure, type) {
        if (!structure || !Array.isArray(structure) || structure.length === 0) {
            return `
                <div class="text-center text-muted py-4">
                    <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                    <p>Нет данных для отображения протоколов</p>
                </div>
            `;
        }

        let html = '<div class="protocols-container">';
        let protocolNumber = 1;
        
        // Группируем протоколы по классам лодок
        const groupedByClass = {};
        
        structure.forEach(protocol => {
            const boatClass = protocol.class;
            if (!groupedByClass[boatClass]) {
                groupedByClass[boatClass] = [];
            }
            groupedByClass[boatClass].push(protocol);
        });
        
        // Сортируем классы лодок в правильном порядке
        const boatClassOrder = ['K-1', 'K-2', 'K-4', 'C-1', 'C-2', 'C-4', 'D-10', 'HD-1', 'OD-1', 'OD-2', 'OC-1'];
        const sortedClasses = Object.keys(groupedByClass).sort((a, b) => {
            const indexA = boatClassOrder.indexOf(a);
            const indexB = boatClassOrder.indexOf(b);
            return indexA - indexB;
        });
        
        for (const boatClass of sortedClasses) {
            const protocols = groupedByClass[boatClass];
            const boatClassName = this.getBoatClassName(boatClass);
            
            html += `<div class="protocol-group mb-4">`;
            html += `<h5 class="protocol-title">${boatClassName}</h5>`;
            
            // Группируем по дистанциям
            const groupedByDistance = {};
            protocols.forEach(protocol => {
                const distance = protocol.distance;
                if (!groupedByDistance[distance]) {
                    groupedByDistance[distance] = [];
                }
                groupedByDistance[distance].push(protocol);
            });
            
            // Сортируем дистанции численно
            const sortedDistances = Object.keys(groupedByDistance).sort((a, b) => {
                const numA = parseInt(a);
                const numB = parseInt(b);
                return numA - numB;
            });
            
            for (const distance of sortedDistances) {
                const distanceProtocols = groupedByDistance[distance];
                html += `<div class="distance-group mb-3">`;
                html += `<h6 class="distance-title">Дистанция: ${distance} м</h6>`;
                
                // Группируем по полу
                const groupedBySex = {};
                distanceProtocols.forEach(protocol => {
                    const sex = protocol.sex;
                    if (!groupedBySex[sex]) {
                        groupedBySex[sex] = [];
                    }
                    groupedBySex[sex].push(protocol);
                });
                
                // Сортируем по полу в правильном порядке: М, Ж, MIX
                const sexOrder = ['M', 'М', 'Ж', 'MIX'];
                const sortedSexes = Object.keys(groupedBySex).sort((a, b) => {
                    const indexA = sexOrder.indexOf(a);
                    const indexB = sexOrder.indexOf(b);
                    return indexA - indexB;
                });
                
                for (const sex of sortedSexes) {
                    const sexProtocols = groupedBySex[sex];
                    const sexName = sex === 'M' || sex === 'М' ? 'Мужчины' : (sex === 'Ж' ? 'Женщины' : 'Смешанные команды');
                    html += `<div class="sex-group mb-2">`;
                    html += `<h7 class="sex-title">${sexName}</h7>`;
                    
                    sexProtocols.forEach(protocol => {
                        if (protocol.ageGroups && protocol.ageGroups.length > 0) {
                            protocol.ageGroups.forEach(ageGroup => {
                                // Нормализуем пол для groupKey (используем латиницу)
                                const normalizedSex = sex === 'М' ? 'M' : sex;
                                const groupKey = `${this.currentMeroId}_${boatClass}_${normalizedSex}_${distance}_${ageGroup.name}`;
                                const isDragonProtocol = boatClass === 'D-10';
                                
                                // Формируем название протокола
                                const protocolTitle = `Протокол №${protocolNumber} - ${boatClassName}, ${distance}м, ${sexName}, ${ageGroup.displayName}`;
                                
                                html += `<div class="age-group mb-3">`;
                                html += `<h8 class="age-title">${protocolTitle}</h8>`;
                                html += `<div class="table-responsive">`;
                                html += `<table class="table table-sm table-bordered protocol-table" data-group="${groupKey}" data-type="${type}" data-protocol-number="${protocolNumber}">`;
                                html += `<thead class="table-light">`;
                                html += `<tr>`;
                                
                                // Заголовки в зависимости от типа протокола
                                if (type === 'start') {
                                    // Стартовый протокол
                                    html += `<th>Вода</th>`;
                                    html += `<th>Номер спортсмена</th>`;
                                    html += `<th>ФИО</th>`;
                                    html += `<th>Год рождения</th>`;
                                    html += `<th>Возрастная группа</th>`;
                                    html += `<th>Спортивный разряд</th>`;
                                    if (isDragonProtocol) {
                                        html += `<th>Город команды</th>`;
                                        html += `<th>Название команды</th>`;
                                    }
                                } else {
                                    // Финишный протокол
                                    html += `<th>Место</th>`;
                                    html += `<th>Время финиша</th>`;
                                    html += `<th>Вода</th>`;
                                    html += `<th>Номер спортсмена</th>`;
                                    html += `<th>ФИО</th>`;
                                    html += `<th>Год рождения</th>`;
                                    html += `<th>Возрастная группа</th>`;
                                    html += `<th>Спортивный разряд</th>`;
                                    if (isDragonProtocol) {
                                        html += `<th>Город команды</th>`;
                                        html += `<th>Название команды</th>`;
                                    }
                                }
                                
                                html += `<th>Действия</th>`;
                                html += `</tr>`;
                                html += `</thead>`;
                                html += `<tbody class="protocol-tbody" data-group="${groupKey}">`;
                                html += `<tr class="no-data">`;
                                
                                // Количество столбцов для пустой строки
                                const colCount = type === 'start' 
                                    ? (isDragonProtocol ? 9 : 7) 
                                    : (isDragonProtocol ? 11 : 9);
                                
                                html += `<td colspan="${colCount}" class="text-center text-muted">Нет участников</td>`;
                                html += `</tr>`;
                                html += `</tbody>`;
                                html += `</table>`;
                                
                                // Кнопка добавления участника только для стартовых протоколов
                                if (type === 'start') {
                                    html += `<div class="mt-2">`;
                                    html += `<button class="btn btn-sm btn-outline-primary add-participant-btn" data-group-key="${groupKey}">`;
                                    html += `<i class="fas fa-user-plus"></i> Добавить участника`;
                                    html += `</button>`;
                                    html += `</div>`;
                                }
                                
                                html += `</div>`;
                                html += `</div>`;
                                
                                protocolNumber++;
                            });
                        }
                    });
                    
                    html += `</div>`;
                }
                
                html += `</div>`;
            }
            
            html += `</div>`;
        }
        
        html += '</div>';
        return html;
    }

    // Загрузка существующих данных
    async loadExistingData() {
        try {
            // Получаем выбранные дисциплины
            const selectedDisciplines = this.getSelectedDisciplines();
            console.log('Выбранные дисциплины для загрузки:', selectedDisciplines);
            
            // Загружаем только выбранные стартовые протоколы
            const startResponse = await fetch('/lks/php/secretary/get_protocols_data.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    meroId: this.currentMeroId,
                    disciplines: selectedDisciplines, // Используем выбранные дисциплины
                    type: 'start'
                })
            });

            const startData = await startResponse.json();
            
            // Загружаем только выбранные финишные протоколы
            const finishResponse = await fetch('/lks/php/secretary/get_protocols_data.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    meroId: this.currentMeroId,
                    disciplines: selectedDisciplines, // Используем выбранные дисциплины
                    type: 'finish'
                })
            });

            const finishData = await finishResponse.json();
            
            // Объединяем данные
            this.protocolsData = {};
            
            if (startData.success && startData.protocols) {
                Object.assign(this.protocolsData, startData.protocols);
            }
            
            if (finishData.success && finishData.protocols) {
                Object.assign(this.protocolsData, finishData.protocols);
            }
            
            this.renderProtocolsData();
            
        } catch (error) {
            console.error('Ошибка загрузки данных:', error);
        }
    }

    // Получение выбранных дисциплин
    getSelectedDisciplines() {
        // Получаем дисциплины из сессии или из DOM
        const disciplinesElement = document.getElementById('selected-disciplines');
        console.log('Ищем элемент selected-disciplines:', disciplinesElement);
        
        if (disciplinesElement) {
            console.log('Значение элемента:', disciplinesElement.value);
            const disciplines = JSON.parse(disciplinesElement.value || '[]');
            console.log('Получены выбранные дисциплины:', disciplines);
            console.log('Тип disciplines:', typeof disciplines);
            console.log('Длина массива:', disciplines.length);
            return disciplines;
        }
        console.log('Элемент selected-disciplines не найден, возвращаем пустой массив');
        return [];
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

            const data = await response.json();
            console.log('Ответ от API жеребьевки:', data);
            
            if (data.success) {
                console.log('Жеребьевка успешна, получены данные:', data);
                // Загружаем актуальные данные из get_protocols_data.php
                await this.loadExistingData();
                this.showSuccess('Жеребьевка проведена успешно!');
            } else {
                console.error('Ошибка жеребьевки:', data.message);
                this.showError('Ошибка проведения жеребьевки: ' + data.message);
            }
        } catch (error) {
            console.error('Ошибка жеребьевки:', error);
            this.showError('Ошибка проведения жеребьевки');
        } finally {
            if (button) {
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-random"></i> Жеребьевка';
            }
        }
    }

    // Отрисовка данных протоколов
    renderProtocolsData() {
        // Проверяем все tbody на странице
        const allTbodies = document.querySelectorAll('tbody[data-group]');
        
        for (const [groupKey, protocolData] of Object.entries(this.protocolsData)) {
            const tbody = document.querySelector(`tbody[data-group="${groupKey}"]`);
            
            if (tbody) {
                tbody.innerHTML = '';
                
                // Проверяем структуру данных
                let participants = [];
                if (protocolData && typeof protocolData === 'object') {
                    if (Array.isArray(protocolData)) {
                        // Если это массив участников
                        participants = protocolData;
                    } else if (protocolData.participants && Array.isArray(protocolData.participants)) {
                        // Если это объект с полем participants
                        participants = protocolData.participants;
                    }
                }
                
                if (participants && participants.length > 0) {
                    participants.forEach((participant, index) => {
                        const row = this.createParticipantRow(participant, index + 1, groupKey);
                        tbody.appendChild(row);
                    });
                } else {
                    const noDataRow = document.createElement('tr');
                    noDataRow.className = 'no-data';
                    
                    // Определяем количество столбцов для пустой строки
                    const table = document.querySelector(`table[data-group="${groupKey}"]`);
                    const type = table ? table.dataset.type : 'start';
                    const isDragonProtocol = groupKey.includes('D-10');
                    
                    const colCount = type === 'start' 
                        ? (isDragonProtocol ? 9 : 7) 
                        : (isDragonProtocol ? 11 : 9);
                    
                    noDataRow.innerHTML = `<td colspan="${colCount}" class="text-center text-muted">Нет участников</td>`;
                    tbody.appendChild(noDataRow);
                }
            }
        }
        
        // Добавляем обработчики событий для кнопок удаления после создания всех кнопок
        this.bindDeleteButtons();
    }

    // Создание строки участника
    createParticipantRow(participant, number, groupKey) {
        const row = document.createElement('tr');
        row.className = 'participant-row';
        row.dataset.participantId = participant.id;
        row.dataset.groupKey = groupKey;
        
        // Определяем тип протокола и является ли это драконами
        const table = document.querySelector(`table[data-group="${groupKey}"]`);
        const type = table ? table.dataset.type : 'start';
        const isDragonProtocol = groupKey.includes('D-10');
        
        let cells = '';
        
        if (type === 'start') {
            // Стартовый протокол
            cells = `
                <td class="edit-field" data-field="water" data-participant-id="${participant.id}">${participant.water || ''}</td>
                <td>${participant.userid || ''}</td>
                <td class="edit-field" data-field="fio" data-participant-id="${participant.id}">${participant.fio || ''}</td>
                <td>${participant.birthdata || ''}</td>
                <td>${participant.ageGroup || ''}</td>
                <td class="edit-field" data-field="sportzvanie" data-participant-id="${participant.id}">${participant.sportzvanie || ''}</td>
            `;
            
            if (isDragonProtocol) {
                cells += `
                    <td class="edit-field" data-field="teamCity" data-participant-id="${participant.id}">${participant.teamCity || ''}</td>
                    <td class="edit-field" data-field="teamName" data-participant-id="${participant.id}">${participant.teamName || ''}</td>
                `;
            }
        } else {
            // Финишный протокол
            cells = `
                <td class="edit-field" data-field="place" data-participant-id="${participant.id}">${participant.place || ''}</td>
                <td class="edit-field" data-field="finishTime" data-participant-id="${participant.id}">${participant.finishTime || ''}</td>
                <td class="edit-field" data-field="water" data-participant-id="${participant.id}">${participant.water || ''}</td>
                <td>${participant.userid || ''}</td>
                <td class="edit-field" data-field="fio" data-participant-id="${participant.id}">${participant.fio || ''}</td>
                <td>${participant.birthdata || ''}</td>
                <td>${participant.ageGroup || ''}</td>
                <td class="edit-field" data-field="sportzvanie" data-participant-id="${participant.id}">${participant.sportzvanie || ''}</td>
            `;
            
            if (isDragonProtocol) {
                cells += `
                    <td class="edit-field" data-field="teamCity" data-participant-id="${participant.id}">${participant.teamCity || ''}</td>
                    <td class="edit-field" data-field="teamName" data-participant-id="${participant.id}">${participant.teamName || ''}</td>
                `;
            }
        }
        
        // Добавляем кнопку действий
        cells += `
            <td>
                <button class="btn btn-sm btn-outline-danger" data-participant-id="${participant.id}" data-group-key="${groupKey}">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;
        
        row.innerHTML = cells;
        return row;
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
        const groupKey = element.closest('tr').dataset.groupKey;
        
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
                const participants = this.getParticipantsFromGroup(groupKey);
                if (participants) {
                    const participant = participants.find(p => p.id == participantId); // Используем == для сравнения с учетом типов
                    if (participant) {
                        participant[field] = newValue;
                    }
                }
            } else {
                this.showError('Ошибка сохранения: ' + data.message);
                // Возвращаем старое значение
                element.textContent = this.getParticipantValue(participantId, groupKey, field);
            }
        } catch (error) {
            console.error('Ошибка сохранения:', error);
            this.showError('Ошибка сохранения данных');
            element.textContent = this.getParticipantValue(participantId, groupKey, field);
        }
    }

    // Отмена редактирования
    cancelEdit(element) {
        const field = element.dataset.field;
        const participantId = element.dataset.participantId;
        const groupKey = element.closest('tr').dataset.groupKey;
        
        element.classList.remove('editing');
        element.contentEditable = false;
        element.textContent = this.getParticipantValue(participantId, groupKey, field);
    }

    // Получение участников из группы с учетом разных структур данных
    getParticipantsFromGroup(groupKey) {
        const group = this.protocolsData[groupKey];
        
        if (!group) {
            return null;
        }

        if (Array.isArray(group)) {
            // Если это массив участников
            return group;
        } else if (group && typeof group === 'object' && group.participants && Array.isArray(group.participants)) {
            // Если это объект с полем participants
            return group.participants;
        } else {
            console.error('❌ Неизвестная структура данных для группы:', groupKey, group);
            console.error('❌ Тип group:', typeof group);
            console.error('❌ group.participants:', group?.participants);
            console.error('❌ Array.isArray(group.participants):', Array.isArray(group?.participants));
            return null;
        }
    }

    // Получение значения участника
    getParticipantValue(participantId, groupKey, field) {
        const participants = this.getParticipantsFromGroup(groupKey);
        if (!participants) {
            return '';
        }

        const participant = participants.find(p => p.id == participantId); // Используем == для сравнения с учетом типов
        if (participant) {
            return participant[field] || '';
        }
        return '';
    }

    // Удаление участника
    async removeParticipant(participantId, groupKey) {
        if (!confirm('Вы уверены, что хотите удалить этого участника?')) {
            return;
        }

        try {
            // Проверяем инициализацию protocolsData
            if (!this.protocolsData) {
                this.showError('Данные протоколов не загружены');
                return;
            }

            // Вызываем API для удаления участника
            const response = await fetch('/lks/php/secretary/remove_participant.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    meroId: this.currentMeroId,
                    participantId: participantId,
                    groupKey: groupKey
                })
            });

            const data = await response.json();
            
            if (data.success) {
                // Удаляем участника из данных в памяти
                const participants = this.getParticipantsFromGroup(groupKey);
                
                if (participants) {
                    const index = participants.findIndex(p => p.id == participantId);
                    
                    if (index !== -1) {
                        participants.splice(index, 1);
                        this.renderProtocolsData();
                        this.showSuccess('Участник удален');
                    } else {
                        this.showError('Участник не найден');
                    }
                }
            } else {
                this.showError('Ошибка удаления: ' + data.message);
            }
        } catch (error) {
            console.error('Ошибка удаления:', error);
            this.showError('Ошибка удаления участника');
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
            this.showError('Введите данные для поиска');
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
                this.showError('Ошибка поиска: ' + data.message);
            }
        } catch (error) {
            console.error('Ошибка поиска участников:', error);
            this.showError('Ошибка поиска участников');
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
            this.showError('Заполните все обязательные поля');
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
                this.showSuccess('Участник успешно зарегистрирован');
                
                // Автоматически добавляем участника в группу
                await this.addParticipantToGroup(data.participant.oid, data.participant.userid);
                
                // Закрываем модальное окно
                const modal = bootstrap.Modal.getInstance(document.getElementById('addParticipantModal'));
                modal.hide();
                
                // Сброс формы
                document.getElementById('newParticipantForm').reset();
            } else {
                this.showError('Ошибка регистрации: ' + data.message);
            }
        } catch (error) {
            console.error('Ошибка регистрации участника:', error);
            this.showError('Ошибка регистрации участника');
        }
    }

    // Добавление участника в группу
    async addParticipantToGroup(participantOid, participantUserid) {
        const groupKey = document.getElementById('current-group-key').value;
        if (!groupKey) {
            this.showError('Ошибка: группа не выбрана');
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
                this.showSuccess('Участник успешно добавлен в протокол');
                
                // Обновляем данные протоколов
                await this.loadExistingData();
                
                // Закрываем модальное окно
                const modal = bootstrap.Modal.getInstance(document.getElementById('addParticipantModal'));
                modal.hide();
            } else {
                this.showError('Ошибка добавления: ' + data.message);
            }
        } catch (error) {
            console.error('Ошибка добавления участника:', error);
            this.showError('Ошибка добавления участника');
        }
    }

    // Перемещение участника между группами
    async moveParticipant(participantId, fromGroup, toGroup) {
        try {
            const response = await fetch('/lks/php/secretary/move_participant.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    meroId: this.currentMeroId,
                    participantId: participantId,
                    fromGroup: fromGroup,
                    toGroup: toGroup
                })
            });

            const data = await response.json();
            
            if (data.success) {
                this.protocolsData = data.protocols;
                this.renderProtocolsData();
                this.showSuccess('Участник перемещен');
            } else {
                this.showError('Ошибка перемещения: ' + data.message);
            }
        } catch (error) {
            console.error('Ошибка перемещения:', error);
            this.showError('Ошибка перемещения участника');
        }
    }

    // Показать сообщение об успехе
    showSuccess(message) {
        // Используем Bootstrap toast или alert
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-success alert-dismissible fade show position-fixed';
        alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999;';
        alertDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(alertDiv);
        
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 3000);
    }

    // Показать сообщение об ошибке
    showError(message) {
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-danger alert-dismissible fade show position-fixed';
        alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999;';
        alertDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(alertDiv);
        
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 5000);
    }

    // Привязка обработчиков событий к кнопкам удаления
    bindDeleteButtons() {
        // Удаляем старые обработчики, если они есть
        const existingButtons = document.querySelectorAll('.btn-outline-danger[data-participant-id]');
        existingButtons.forEach(button => {
            button.removeEventListener('click', this.handleDeleteClick);
        });
        
        // Добавляем новые обработчики
        const deleteButtons = document.querySelectorAll('.btn-outline-danger[data-participant-id]');
        deleteButtons.forEach(button => {
            button.addEventListener('click', this.handleDeleteClick.bind(this));
        });
    }
    
    // Обработчик клика по кнопке удаления
    handleDeleteClick(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const participantId = e.currentTarget.dataset.participantId;
        const groupKey = e.currentTarget.dataset.groupKey;
        
        if (participantId && groupKey) {
            this.removeParticipant(participantId, groupKey);
        }
    }
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM загружен, инициализируем ProtocolsManager');
    window.protocolsManager = new ProtocolsManager();
}); 
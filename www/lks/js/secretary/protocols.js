// Система управления протоколами соревнований
console.log('protocols.js загружен');

// Простой тест загрузки
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM загружен - protocols.js работает!');
    
    // Обновляем отладочную информацию
    const debugStartContainer = document.getElementById('debug-start-container');
    const debugFinishContainer = document.getElementById('debug-finish-container');
    
    if (debugStartContainer) {
        debugStartContainer.textContent = 'protocols.js загружен';
    }
    if (debugFinishContainer) {
        debugFinishContainer.textContent = 'protocols.js загружен';
    }
});

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
        
        // Обновляем отладочную информацию
        this.updateDebugInfo();
        
        // Проверяем выбранные дисциплины
        const selectedDisciplines = this.getSelectedDisciplines();
        console.log('ProtocolsManager: Выбранные дисциплины при инициализации:', selectedDisciplines);

        // Простой тест генерации HTML
        console.log('=== ТЕСТ ГЕНЕРАЦИИ HTML ===');
        const testStructure = [
            {
                class: 'K-1',
                sex: 'M',
                distance: '200',
                ageGroups: [
                    {
                        name: 'группа 1',
                        displayName: 'группа 1: 18-29',
                        full_name: 'группа 1: 18-29',
                        number: 1,
                        minAge: 18,
                        maxAge: 29
                    }
                ]
            }
        ];
        const testHTML = this.generateProtocolsHTML(testStructure, 'start');
        console.log('Тест HTML (первые 500 символов):', testHTML.substring(0, 500));
        console.log('=== КОНЕЦ ТЕСТА ===');

        this.loadProtocolsStructure();
        this.bindEvents();
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
            
            // Проверяем родительские контейнеры
            const startParent = startContainer.parentElement;
            const finishParent = finishContainer.parentElement;
            const startParentStyle = startParent ? window.getComputedStyle(startParent) : null;
            const finishParentStyle = finishParent ? window.getComputedStyle(finishParent) : null;
            
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
                },
                startParent: startParentStyle ? {
                    display: startParentStyle.display,
                    visibility: startParentStyle.visibility,
                    opacity: startParentStyle.opacity,
                    height: startParentStyle.height
                } : null,
                finishParent: finishParentStyle ? {
                    display: finishParentStyle.display,
                    visibility: finishParentStyle.visibility,
                    opacity: finishParentStyle.opacity,
                    height: finishParentStyle.height
                } : null
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

        // Кнопка автозаполнения протоколов
        const autoFillButton = document.getElementById('auto-fill-protocols-btn');
        if (autoFillButton) {
            autoFillButton.addEventListener('click', () => this.autoFillProtocols());
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
            
            // Если дисциплины не выбраны, загружаем все доступные
            if (!selectedDisciplines || selectedDisciplines.length === 0) {
                console.log('Дисциплины не выбраны, загружаем все доступные');
            }
            
            const requestBody = {
                meroId: this.currentMeroId,
                disciplines: selectedDisciplines // Передаем null или массив дисциплин
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
            console.log('Успех:', data.success);
            console.log('Структура получена:', data.structure);
            console.log('Количество протоколов:', data.totalProtocols);
            console.log('Количество дисциплин:', data.totalDisciplines);
            
            if (data.success) {
                console.log('Структура получена успешно, отрисовываем...');
                this.renderProtocolsStructure(data.structure);
                console.log('Структура отрисована, загружаем данные...');
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
        console.log('renderProtocolsStructure вызвана с структурой:', structure);
        console.log('Тип структуры:', typeof structure);
        console.log('Длина структуры:', structure ? structure.length : 'undefined');
        
        const startContainer = document.getElementById('start-protocols');
        const finishContainer = document.getElementById('finish-protocols');
        
        console.log('Найдены контейнеры:', {
            startContainer: !!startContainer,
            finishContainer: !!finishContainer,
            startContainerId: startContainer ? startContainer.id : 'не найден',
            finishContainerId: finishContainer ? finishContainer.id : 'не найден'
        });

        if (!startContainer || !finishContainer) {
            console.error('Контейнеры не найдены!');
            console.log('Все элементы с id, содержащими "protocols":', 
                Array.from(document.querySelectorAll('[id*="protocols"]')).map(el => el.id));
            return;
        }

        // Простой тест отображения
        console.log('=== ТЕСТ ОТОБРАЖЕНИЯ ===');
        startContainer.innerHTML = '<div class="alert alert-success">Тест отображения - стартовые протоколы работают!</div>';
        finishContainer.innerHTML = '<div class="alert alert-success">Тест отображения - финишные протоколы работают!</div>';
        console.log('Тест отображения выполнен');
        
        // Обновляем отладочную информацию
        this.updateDebugInfo();
        
        // Ждем 2 секунды и затем загружаем реальные данные
        setTimeout(() => {
            if (startContainer) {
                console.log('Генерируем HTML для стартовых протоколов...');
                const startHTML = this.generateProtocolsHTML(structure, 'start');
                console.log('HTML для стартовых протоколов (первые 500 символов):', startHTML.substring(0, 500));
                
                // Простой тест вставки
                console.log('Длина HTML для вставки:', startHTML.length);
                console.log('Содержимое контейнера до вставки:', startContainer.innerHTML.substring(0, 200));
                
                startContainer.innerHTML = startHTML;
                console.log('Стартовые протоколы отрисованы');
                console.log('Проверка вставки HTML - длина контейнера после вставки:', startContainer.innerHTML.length);
                console.log('Содержимое контейнера после вставки:', startContainer.innerHTML.substring(0, 200));
                
                // Проверяем видимость контейнера
                const startContainerStyle = window.getComputedStyle(startContainer);
                console.log('Стили стартового контейнера:', {
                    display: startContainerStyle.display,
                    visibility: startContainerStyle.visibility,
                    opacity: startContainerStyle.opacity,
                    height: startContainerStyle.height,
                    width: startContainerStyle.width
                });
                
                // Проверяем прокрутку и видимость
                console.log('=== ПРОВЕРКА ПРОКРУТКИ ===');
                console.log('Высота контейнера стартовых протоколов:', startContainer.offsetHeight, 'px');
                console.log('Высота родительского элемента:', startContainer.parentElement.offsetHeight, 'px');
                console.log('Прокрутка родительского элемента:', startContainer.parentElement.scrollTop, 'px');
                console.log('Максимальная прокрутка:', startContainer.parentElement.scrollHeight - startContainer.parentElement.clientHeight, 'px');
                
                // Проверяем, есть ли элементы внутри контейнера
                const startElements = startContainer.querySelectorAll('.protocol-group');
                console.log('Количество групп протоколов в стартовом контейнере:', startElements.length);
                if (startElements.length > 0) {
                    console.log('Первая группа протоколов:', startElements[0].outerHTML.substring(0, 200));
                }
                
                // Принудительно обновляем отладочную информацию
                setTimeout(() => {
                    this.updateDebugInfo();
                }, 100);
            }

            if (finishContainer) {
                console.log('Генерируем HTML для финишных протоколов...');
                const finishHTML = this.generateProtocolsHTML(structure, 'finish');
                console.log('HTML для финишных протоколов (первые 500 символов):', finishHTML.substring(0, 500));
                finishContainer.innerHTML = finishHTML;
                console.log('Финишные протоколы отрисованы');
                console.log('Проверка вставки HTML - длина контейнера после вставки:', finishContainer.innerHTML.length);
                
                // Проверяем видимость контейнера
                const finishContainerStyle = window.getComputedStyle(finishContainer);
                console.log('Стили финишного контейнера:', {
                    display: finishContainerStyle.display,
                    visibility: finishContainerStyle.visibility,
                    opacity: finishContainerStyle.opacity,
                    height: finishContainerStyle.height,
                    width: finishContainerStyle.width
                });
                
                // Проверяем прокрутку и видимость для финишных протоколов
                console.log('=== ПРОВЕРКА ПРОКРУТКИ ФИНИШНЫХ ===');
                console.log('Высота контейнера финишных протоколов:', finishContainer.offsetHeight, 'px');
                console.log('Высота родительского элемента:', finishContainer.parentElement.offsetHeight, 'px');
                console.log('Прокрутка родительского элемента:', finishContainer.parentElement.scrollTop, 'px');
                console.log('Максимальная прокрутка:', finishContainer.parentElement.scrollHeight - finishContainer.parentElement.clientHeight, 'px');
                
                // Проверяем, есть ли элементы внутри контейнера финишных протоколов
                const finishElements = finishContainer.querySelectorAll('.protocol-group');
                console.log('Количество групп протоколов в финишном контейнере:', finishElements.length);
                if (finishElements.length > 0) {
                    console.log('Первая группа финишных протоколов:', finishElements[0].outerHTML.substring(0, 200));
                }
                
                // Принудительно обновляем отладочную информацию
                setTimeout(() => {
                    this.updateDebugInfo();
                }, 100);
            }
            
            // Обновляем отладочную информацию
            this.updateDebugInfo();
            console.log('renderProtocolsStructure завершена');
        }, 2000);
    }

    // Генерация HTML для протоколов
    generateProtocolsHTML(structure, type) {
        console.log('generateProtocolsHTML вызвана с параметрами:', { structure, type });
        
        if (!structure || !Array.isArray(structure) || structure.length === 0) {
            console.log('Структура пустая или некорректная, возвращаем сообщение об ошибке');
            return `
                <div class="text-center text-muted py-4">
                    <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                    <p>Нет данных для отображения протоколов</p>
                </div>
            `;
        }
        
        console.log('Структура корректная, начинаем генерацию HTML...');
        console.log('Структура для генерации:', structure);
        
        let html = '<div class="protocols-container">';
        
        // Группируем протоколы по классам лодок
        const groupedByClass = {};
        
        structure.forEach(discipline => {
            const boatClass = discipline.class;
            if (!groupedByClass[boatClass]) {
                groupedByClass[boatClass] = [];
            }
            groupedByClass[boatClass].push(discipline);
        });
        
        console.log('Сгруппированные дисциплины:', groupedByClass);
        
        // Сортируем классы лодок в правильном порядке
        const boatClassOrder = ['K-1', 'K-2', 'K-4', 'C-1', 'C-2', 'C-4', 'D-10', 'HD-1', 'OD-1', 'OD-2', 'OC-1'];
        const sortedClasses = Object.keys(groupedByClass).sort((a, b) => {
            const indexA = boatClassOrder.indexOf(a);
            const indexB = boatClassOrder.indexOf(b);
            return indexA - indexB;
        });
        
        for (const boatClass of sortedClasses) {
            const disciplines = groupedByClass[boatClass];
            const boatClassName = this.getBoatClassName(boatClass);
            
            html += `<div class="protocol-group mb-4">`;
            html += `<h5 class="protocol-title">${boatClassName}</h5>`;
            
            // Группируем по дистанциям
            const groupedByDistance = {};
            disciplines.forEach(discipline => {
                const distance = discipline.distance;
                if (!groupedByDistance[distance]) {
                    groupedByDistance[distance] = [];
                }
                groupedByDistance[distance].push(discipline);
            });
            
            // Сортируем дистанции численно
            const sortedDistances = Object.keys(groupedByDistance).sort((a, b) => {
                const numA = parseInt(a);
                const numB = parseInt(b);
                return numA - numB;
            });
            
            for (const distance of sortedDistances) {
                const distanceDisciplines = groupedByDistance[distance];
                html += `<div class="distance-group mb-3">`;
                html += `<h6 class="distance-title">Дистанция: ${distance} м</h6>`;
                
                // Группируем по полу
                const groupedBySex = {};
                distanceDisciplines.forEach(discipline => {
                    const sex = discipline.sex;
                    if (!groupedBySex[sex]) {
                        groupedBySex[sex] = [];
                    }
                    groupedBySex[sex].push(discipline);
                });
                
                // Сортируем по полу в правильном порядке: М, Ж, MIX
                const sexOrder = ['M', 'М', 'Ж', 'MIX'];
                const sortedSexes = Object.keys(groupedBySex).sort((a, b) => {
                    const indexA = sexOrder.indexOf(a);
                    const indexB = sexOrder.indexOf(b);
                    return indexA - indexB;
                });
                
                for (const sex of sortedSexes) {
                    const sexDisciplines = groupedBySex[sex];
                    const sexName = sex === 'M' || sex === 'М' ? 'Мужчины' : (sex === 'Ж' ? 'Женщины' : 'Смешанные команды');
                    html += `<div class="sex-group mb-2">`;
                    html += `<h7 class="sex-title">${sexName}</h7>`;
                    
                    sexDisciplines.forEach(discipline => {
                        if (discipline.ageGroups && discipline.ageGroups.length > 0) {
                            discipline.ageGroups.forEach(ageGroup => {
                                // Нормализуем пол для groupKey (используем латиницу)
                                const normalizedSex = sex === 'М' ? 'M' : sex;
                                // Используем полное название возрастной группы с названием и возрастным диапазоном
                                const ageGroupName = ageGroup.full_name || ageGroup.name;
                                const groupKey = `${this.currentMeroId}_${boatClass}_${normalizedSex}_${distance}_${ageGroupName}`;
                                const isDragonProtocol = boatClass === 'D-10';
                                
                                // Формируем название протокола с правильной нумерацией
                                const protocolTitle = `Протокол №${ageGroup.number} - ${boatClassName}, ${distance}м, ${sexName}, ${ageGroup.displayName}`;
                                
                                html += `<div class="age-group mb-3">`;
                                html += `<div class="d-flex justify-content-between align-items-center mb-2">`;
                                html += `<h8 class="age-title">${protocolTitle}</h8>`;
                                html += `</div>`;
                                html += `<div class="table-responsive">`;
                                html += `<table class="table table-sm table-bordered protocol-table" data-group="${groupKey}" data-type="${type}" data-protocol-number="${ageGroup.number}">`;
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
        console.log('generateProtocolsHTML завершена, сгенерированный HTML:', html.substring(0, 500) + '...');
        return html;
    }

    // Загрузка существующих данных
    async loadExistingData() {
        console.log('=== ЗАГРУЗКА ДАННЫХ УЧАСТНИКОВ ===');
        
        const selectedDisciplines = this.getSelectedDisciplines();
        console.log('Дисциплины для загрузки данных:', selectedDisciplines);

        try {
            console.log('Отправляем запрос к get_protocols_data.php...');
            const authResponse = await fetch('/lks/php/secretary/get_protocols_data.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    meroId: this.currentMeroId,
                    disciplines: selectedDisciplines,
                    type: 'start'
                })
            });

            console.log('Статус ответа:', authResponse.status);
            console.log('Заголовки ответа:', authResponse.headers);

            if (authResponse.status === 403) {
                console.error('Ошибка 403: Доступ запрещен. Возможно, сессия истекла или нет прав доступа.');
                this.showError('Ошибка доступа: сессия истекла или нет прав доступа. Пожалуйста, войдите заново.');
                return;
            }

            if (authResponse.status === 401) {
                console.error('Ошибка 401: Не авторизован.');
                this.showError('Ошибка авторизации. Пожалуйста, войдите заново.');
                return;
            }

            if (!authResponse.ok) {
                console.error('Ошибка HTTP:', authResponse.status, authResponse.statusText);
                this.showError(`Ошибка загрузки данных: ${authResponse.status} ${authResponse.statusText}`);
                return;
            }

            const responseData = await authResponse.json();
            console.log('Получены данные от API:', responseData);

            if (responseData.success) {
                console.log('Данные успешно загружены, обновляем таблицы...');
                this.updateProtocolsWithData(responseData.protocols);
            } else {
                console.error('API вернул ошибку:', responseData.error);
                this.showError('Ошибка загрузки данных: ' + (responseData.error || 'Неизвестная ошибка'));
            }

        } catch (error) {
            console.error('Ошибка при загрузке данных:', error);
            this.showError('Ошибка сети при загрузке данных: ' + error.message);
        }
    }

    // Синхронизация финишных протоколов со стартовыми
    syncFinishProtocols() {
        console.log('Синхронизация финишных протоколов...');
        
        // Проходим по всем стартовым протоколам
        for (const [groupKey, startData] of Object.entries(this.protocolsData)) {
            // Проверяем, что это стартовый протокол и есть участники
            if (startData && startData.participants && Array.isArray(startData.participants)) {
                console.log(`Обрабатываем группу: ${groupKey}`);
                
                // Создаем ключ для финишного протокола
                // Убираем _start если есть, добавляем _finish
                let finishKey = groupKey;
                if (finishKey.endsWith('_start')) {
                    finishKey = finishKey.replace('_start', '_finish');
                } else {
                    finishKey = finishKey + '_finish';
                }
                
                console.log(`Ищем финишный протокол с ключом: ${finishKey}`);
                const finishData = this.protocolsData[finishKey];
                
                if (!finishData || !finishData.participants || finishData.participants.length === 0) {
                    // Создаем финишный протокол на основе стартового
                    console.log(`Создаем финишный протокол для ${groupKey} -> ${finishKey}`);
                    this.protocolsData[finishKey] = {
                        type: 'finish',
                        participants: startData.participants.map(participant => ({
                            ...participant,
                            place: null,
                            finishTime: null
                        })),
                        drawConducted: startData.drawConducted || false,
                        lastUpdated: new Date().toISOString()
                    };
                } else {
                    // Синхронизируем существующий финишный протокол
                    console.log(`Синхронизируем финишный протокол для ${groupKey} -> ${finishKey}`);
                    const startParticipantIds = new Set(startData.participants.map(p => p.userid));
                    const finishParticipantIds = new Set(finishData.participants.map(p => p.userid));
                    
                    // Добавляем участников из стартового протокола, которых нет в финишном
                    for (const startParticipant of startData.participants) {
                        if (!finishParticipantIds.has(startParticipant.userid)) {
                            finishData.participants.push({
                                ...startParticipant,
                                place: null,
                                finishTime: null
                            });
                        }
                    }
                    
                    // Удаляем участников из финишного протокола, которых нет в стартовом
                    finishData.participants = finishData.participants.filter(p => 
                        startParticipantIds.has(p.userid)
                    );
                }
            }
        }
        
        console.log('Синхронизация завершена');
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
            
            // Если дисциплины не выбраны, возвращаем null для загрузки всех доступных
            if (!disciplines || disciplines.length === 0) {
                console.log('Дисциплины не выбраны, будем загружать все доступные');
                return null;
            }
            
            // Преобразуем строковые дисциплины в объекты для совместимости с API
            const formattedDisciplines = disciplines.map(discipline => {
                if (typeof discipline === 'string') {
                    // Формат: "C-1_М_200" -> {class: "C-1", sex: "М", distance: "200"}
                    const parts = discipline.split('_');
                    if (parts.length === 3) {
                        return {
                            class: parts[0],
                            sex: parts[1],
                            distance: parts[2]
                        };
                    }
                }
                // Если уже объект или неправильный формат, возвращаем как есть
                return discipline;
            });
            
            console.log('Форматированные дисциплины для API:', formattedDisciplines);
            return formattedDisciplines;
        }
        console.log('Элемент selected-disciplines не найден, возвращаем null для загрузки всех доступных');
        return null;
    }

    // Автоматическое заполнение протоколов участниками
    async autoFillProtocols() {
        try {
            // Показываем индикатор загрузки
            const button = document.getElementById('auto-fill-protocols-btn');
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Заполнение...';
            button.disabled = true;

            console.log('Начинаем автоматическое заполнение протоколов для мероприятия:', this.currentMeroId);

            const response = await fetch('/lks/php/secretary/auto_fill_protocols.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    meroId: this.currentMeroId
                })
            });

            const result = await response.json();
            
            if (result.success) {
                this.showNotification(`Автозаполнение завершено! Добавлено участников: ${result.totalAdded}`, 'success');
                
                // Перезагружаем данные протоколов
                await this.loadExistingData();
                
                if (result.errors && result.errors.length > 0) {
                    console.warn('Ошибки при автозаполнении:', result.errors);
                    this.showNotification(`Предупреждения: ${result.errors.length} ошибок (см. консоль)`, 'warning');
                }
            } else {
                this.showNotification(`Ошибка автозаполнения: ${result.message}`, 'error');
            }
        } catch (error) {
            console.error('Ошибка автозаполнения протоколов:', error);
            this.showNotification('Ошибка автозаполнения протоколов', 'error');
        } finally {
            // Восстанавливаем кнопку
            const button = document.getElementById('auto-fill-protocols-btn');
            button.innerHTML = '<i class="fas fa-users"></i> Автозаполнение';
            button.disabled = false;
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
            const table = document.querySelector(`table[data-group="${groupKey}"]`);
            
            if (tbody && table) {
                tbody.innerHTML = '';
                
                // Проверяем структуру данных
                let participants = [];
                let isProtected = false;
                
                if (protocolData && typeof protocolData === 'object') {
                    if (Array.isArray(protocolData)) {
                        // Если это массив участников
                        participants = protocolData;
                    } else if (protocolData.participants && Array.isArray(protocolData.participants)) {
                        // Если это объект с полем participants
                        participants = protocolData.participants;
                    }
                    
                    // Проверяем, защищен ли протокол
                    isProtected = protocolData.protected === true || this.isProtocolProtected(participants);
                }
                
                // Применяем стиль защиты к таблице
                if (isProtected) {
                    table.classList.add('protected-protocol');
                    table.style.border = '2px solid #28a745';
                    table.style.borderRadius = '8px';
                } else {
                    table.classList.remove('protected-protocol');
                    table.style.border = '';
                    table.style.borderRadius = '';
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
                    const type = table.dataset.type || 'start';
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
    
    // Проверка, защищен ли протокол (есть ли заполненные результаты)
    isProtocolProtected(participants) {
        if (!participants || !Array.isArray(participants)) {
            return false;
        }
        
        for (const participant of participants) {
            if (participant.place && isNumeric(participant.place) && 
                participant.finishTime && participant.finishTime.trim() !== '') {
                return true;
            }
        }
        
        return false;
    }
    
    // Вспомогательная функция для проверки числового значения
    isNumeric(value) {
        return !isNaN(parseFloat(value)) && isFinite(value);
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
                        this.updateProtocolsWithData(this.protocolsData);
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
                this.updateProtocolsWithData(this.protocolsData);
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

    // Добавление участника в протокол
    async addParticipantToProtocol(participantData, protocolInfo) {
        try {
            const response = await fetch('/lks/php/secretary/add_participant_to_protocol.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    meroId: this.currentMeroId,
                    participantData: participantData,
                    protocolInfo: protocolInfo
                })
            });

            const result = await response.json();
            
            if (result.success) {
                // Обновляем отображение протоколов
                await this.loadExistingData();
                this.updateProtocolsWithData(this.protocolsData);
                
                // Показываем уведомление
                this.showNotification(result.message, 'success');
            } else {
                this.showNotification(result.message, 'error');
            }
        } catch (error) {
            console.error('Ошибка добавления участника:', error);
            this.showNotification('Ошибка добавления участника в протокол', 'error');
        }
    }

    // Показ модального окна для добавления участника
    showAddParticipantModal(protocolInfo) {
        // Создаем модальное окно
        const modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.id = 'addParticipantModal';
        modal.innerHTML = `
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Добавить участника в протокол</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Протокол:</label>
                            <input type="text" class="form-control" value="Протокол №${protocolInfo.number} - ${protocolInfo.class} ${protocolInfo.sex} ${protocolInfo.distance}м" readonly>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Поиск участника:</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="participantSearch" placeholder="Введите номер или ФИО участника">
                                        <button class="btn btn-outline-secondary" type="button" onclick="protocolsManager.searchParticipants()">
                                            <i class="bi bi-search"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div id="searchResults" class="mb-3" style="display: none;">
                                    <label class="form-label">Результаты поиска:</label>
                                    <div class="list-group" id="participantsList" style="max-height: 300px; overflow-y: auto;">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Номер участника:</label>
                                    <input type="number" class="form-control" id="participantUserId" placeholder="Введите номер участника">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Команда:</label>
                                    <input type="text" class="form-control" id="participantTeam" placeholder="Название команды (необязательно)">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Город команды:</label>
                                    <input type="text" class="form-control" id="participantTeamCity" placeholder="Город команды (необязательно)">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Номер дорожки:</label>
                                    <input type="number" class="form-control" id="participantLane" placeholder="Номер дорожки (необязательно)">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="button" class="btn btn-primary" onclick="protocolsManager.addParticipantFromModal()">Добавить</button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
        
        // Сохраняем информацию о протоколе для использования в модальном окне
        this.currentProtocolInfo = protocolInfo;
        
        // Показываем модальное окно
        const modalInstance = new bootstrap.Modal(modal);
        modalInstance.show();
        
        // Удаляем модальное окно после закрытия
        modal.addEventListener('hidden.bs.modal', () => {
            document.body.removeChild(modal);
        });
    }

    // Добавление участника из модального окна
    async addParticipantFromModal() {
        const userId = document.getElementById('participantUserId').value;
        const teamName = document.getElementById('participantTeam').value;
        const teamCity = document.getElementById('participantTeamCity').value;
        const lane = document.getElementById('participantLane').value;

        if (!userId) {
            this.showNotification('Введите номер участника', 'error');
            return;
        }

        const participantData = {
            userId: parseInt(userId),
            teamName: teamName,
            teamCity: teamCity,
            lane: lane ? parseInt(lane) : null
        };

        await this.addParticipantToProtocol(participantData, this.currentProtocolInfo);
        
        // Закрываем модальное окно
        const modal = bootstrap.Modal.getInstance(document.getElementById('addParticipantModal'));
        modal.hide();
    }

    // Поиск участников
    async searchParticipants() {
        const searchTerm = document.getElementById('participantSearch').value.trim();
        
        if (!searchTerm) {
            this.showNotification('Введите поисковый запрос', 'error');
            return;
        }

        try {
            const response = await fetch('/lks/php/secretary/search_participants.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    searchTerm: searchTerm,
                    limit: 20
                })
            });

            const result = await response.json();
            
            if (result.success) {
                this.displaySearchResults(result.participants);
            } else {
                this.showNotification(result.message, 'error');
            }
        } catch (error) {
            console.error('Ошибка поиска участников:', error);
            this.showNotification('Ошибка поиска участников', 'error');
        }
    }

    // Отображение результатов поиска
    displaySearchResults(participants) {
        const searchResults = document.getElementById('searchResults');
        const participantsList = document.getElementById('participantsList');
        
        if (!participants || participants.length === 0) {
            participantsList.innerHTML = '<div class="text-center text-muted p-3">Участники не найдены</div>';
            searchResults.style.display = 'block';
            return;
        }

        let html = '';
        participants.forEach(participant => {
            html += `
                <div class="list-group-item list-group-item-action" onclick="protocolsManager.selectParticipant(${participant.userid}, '${participant.fio}', '${participant.sex}', ${participant.age})">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>№${participant.userid}</strong> - ${participant.fio}
                        </div>
                        <small class="text-muted">${participant.sex}, ${participant.age} лет</small>
                    </div>
                    <small class="text-muted">
                        ${participant.birthdata} | ${participant.sportzvanie} | ${participant.city || 'Не указан'}
                    </small>
                </div>
            `;
        });
        
        participantsList.innerHTML = html;
        searchResults.style.display = 'block';
    }

    // Выбор участника из результатов поиска
    selectParticipant(userId, fio, sex, age) {
        document.getElementById('participantUserId').value = userId;
        
        // Показываем информацию о выбранном участнике
        this.showNotification(`Выбран участник: ${fio} (№${userId})`, 'success');
        
        // Скрываем результаты поиска
        document.getElementById('searchResults').style.display = 'none';
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

    updateProtocolsWithData(data) {
        console.log('=== ОБНОВЛЕНИЕ ПРОТОКОЛОВ ДАННЫМИ ===');
        console.log('Полученные данные для обновления:', data);
        console.log('Тип данных:', typeof data);
        console.log('Количество ключей в данных:', Object.keys(data).length);
        
        if (!data || typeof data !== 'object') {
            console.error('Некорректные данные для обновления:', data);
            return;
        }
        
        // data содержит объект с ключами groupKey и значениями protocolData
        // Каждый protocolData содержит массив participants
        Object.keys(data).forEach(groupKey => {
            const protocolData = data[groupKey];
            console.log(`Обрабатываем группу ${groupKey}:`, protocolData);
            
            if (protocolData && protocolData.participants) {
                console.log(`Найдено ${protocolData.participants.length} участников в группе ${groupKey}`);
                this.updateProtocolTableByGroupKey(groupKey, protocolData.participants);
            } else {
                console.log(`Нет участников в группе ${groupKey}`);
            }
        });
        
        console.log('Обновление протоколов завершено');
    }
    
    updateProtocolTableByGroupKey(groupKey, participants) {
        console.log(`Обновляем таблицы для группы ${groupKey} с ${participants.length} участниками`);
        
        // Находим все таблицы в обоих контейнерах
        const startContainer = document.getElementById('start-protocols');
        const finishContainer = document.getElementById('finish-protocols');
        
        // Обновляем стартовые протоколы
        if (startContainer) {
            const startTables = startContainer.querySelectorAll('table');
            startTables.forEach((table, index) => {
                const tableGroupKey = this.getTableGroupKey(table);
                if (tableGroupKey === groupKey) {
                    console.log(`Обновляем стартовую таблицу ${index + 1} для группы ${groupKey}`);
                    this.updateTableWithData(table, participants);
                }
            });
        }
        
        // Обновляем финишные протоколы
        if (finishContainer) {
            const finishTables = finishContainer.querySelectorAll('table');
            finishTables.forEach((table, index) => {
                const tableGroupKey = this.getTableGroupKey(table);
                if (tableGroupKey === groupKey) {
                    console.log(`Обновляем финишную таблицу ${index + 1} для группы ${groupKey}`);
                    this.updateTableWithData(table, participants);
                }
            });
        }
    }
    
    getTableGroupKey(table) {
        // Ищем ближайший элемент с классом age-group
        const ageGroupElement = table.closest('.age-group');
        if (ageGroupElement) {
            // Извлекаем информацию из заголовка протокола
            const protocolTitle = ageGroupElement.querySelector('.age-title');
            if (protocolTitle) {
                const titleText = protocolTitle.textContent;
                // Парсим заголовок для извлечения информации о группе
                // Пример: "Протокол №1 - Байдарка-одиночка (K-1), 200м, Мужчины, группа 1: 18-29"
                const match = titleText.match(/Байдарка-одиночка \(K-1\)|Каноэ-одиночка \(C-1\)|Байдарка-двойка \(K-2\)|Каноэ-двойка \(C-2\)/);
                if (match) {
                    const classMatch = titleText.match(/\(([^)]+)\)/);
                    const distanceMatch = titleText.match(/(\d+)м/);
                    const sexMatch = titleText.match(/(Мужчины|Женщины)/);
                    const ageGroupMatch = titleText.match(/группа ([^:]+):/);
                    
                    if (classMatch && distanceMatch && sexMatch && ageGroupMatch) {
                        const classType = classMatch[1];
                        const distance = distanceMatch[1];
                        const sex = sexMatch[1] === 'Мужчины' ? 'M' : 'W';
                        const ageGroup = ageGroupMatch[1];
                        
                        // Формируем groupKey в том же формате, что и в PHP
                        return `1_${classType}_${sex}_${distance}_группа ${ageGroup}`;
                    }
                }
            }
        }
        return null;
    }
    
    updateTableWithData(table, participants) {
        console.log('Обновляем таблицу данными участников:', participants);
        
        // Находим tbody в таблице
        const tbody = table.querySelector('tbody');
        if (!tbody) {
            console.error('tbody не найден в таблице');
            return;
        }
        
        // Очищаем существующие данные
        tbody.innerHTML = '';
        
        // Если нет данных, показываем "Нет участников"
        if (!participants || participants.length === 0) {
            const noDataRow = document.createElement('tr');
            noDataRow.innerHTML = '<td colspan="7" class="text-center text-muted">Нет участников</td>';
            tbody.appendChild(noDataRow);
            console.log('Добавлена строка "Нет участников"');
            return;
        }
        
        // Добавляем данные участников
        participants.forEach((participant, index) => {
            console.log(`Добавляем участника ${index + 1}:`, participant);
            const row = this.createParticipantRow(participant);
            tbody.appendChild(row);
        });
        
        console.log(`Добавлено ${participants.length} участников в таблицу`);
    }
    
    createParticipantRow(participant) {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${participant.lane || '-'}</td>
            <td>${participant.userid || '-'}</td>
            <td>${participant.fio || '-'}</td>
            <td>${participant.birthYear || '-'}</td>
            <td>${participant.ageGroup || '-'}</td>
            <td>${participant.sportzvanie || '-'}</td>
            <td>
                <button class="btn btn-sm btn-outline-primary edit-participant" data-id="${participant.registration_id}">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger delete-participant" data-id="${participant.registration_id}">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;
        return row;
    }
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM загружен, инициализируем ProtocolsManager');
    window.protocolsManager = new ProtocolsManager();
}); 
/**
 * Скрипты для панели организатора
 * KGB-Pulse - Система управления гребной базой
 */

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    initializeOrganizerPanel();
});

/**
 * Инициализация панели организатора
 */
function initializeOrganizerPanel() {
    // Инициализация обработчиков событий
    initializeEventHandlers();
    
    // Инициализация календаря
    initializeCalendar();
    
    // Инициализация форм
    initializeForms();
}

/**
 * Инициализация обработчиков событий
 */
function initializeEventHandlers() {
    // Загрузка файла мероприятия
    const eventFileUpload = document.getElementById('event-file-upload');
    if (eventFileUpload) {
        eventFileUpload.addEventListener('change', handleEventFileUpload);
    }

    // Форма создания мероприятия
    const createEventForm = document.getElementById('create-event-form');
    if (createEventForm) {
        createEventForm.addEventListener('submit', handleCreateEvent);
    }

    // Кнопки действий с мероприятиями
    const eventActions = document.querySelectorAll('.event-action');
    eventActions.forEach(button => {
        button.addEventListener('click', function() {
            handleEventAction(this.dataset.action, this.dataset.eventId);
        });
    });

    // Фильтры регистраций
    const registrationFilters = document.querySelectorAll('.registration-filter');
    registrationFilters.forEach(filter => {
        filter.addEventListener('change', updateRegistrationTable);
    });
}

/**
 * Инициализация календаря
 */
function initializeCalendar() {
    const calendarEl = document.getElementById('event-calendar');
    if (!calendarEl) return;

    // Здесь можно подключить библиотеку календаря (например, FullCalendar)
    // Пока оставляем заглушку
    loadCalendarEvents();
}

/**
 * Инициализация форм
 */
function initializeForms() {
    // Автозаполнение полей при выборе города
    const citySelect = document.getElementById('event-city');
    if (citySelect) {
        citySelect.addEventListener('change', function() {
            updateEventLocation(this.value);
        });
    }

    // Валидация дат
    const dateInputs = document.querySelectorAll('input[type="date"]');
    dateInputs.forEach(input => {
        input.addEventListener('change', validateEventDates);
    });
}

/**
 * Обработка загрузки файла мероприятия
 */
function handleEventFileUpload(event) {
    const file = event.target.files[0];
    if (!file) return;

    // Проверяем тип файла
    if (!file.name.toLowerCase().endsWith('.xlsx')) {
        showNotification('Пожалуйста, выберите файл в формате .xlsx', 'error');
        return;
    }

    // Показываем прогресс
    showUploadProgress(true);

    const formData = new FormData();
    formData.append('event_file', file);

    fetch('/lks/php/organizer/parse-event-file.php', {
        method: 'POST',
        headers: {
            'X-CSRF-Token': getCSRFToken()
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        showUploadProgress(false);
        
        if (data.success) {
            fillEventForm(data.eventData);
            showNotification('Файл успешно обработан', 'success');
        } else {
            showNotification(data.message || 'Ошибка обработки файла', 'error');
        }
    })
    .catch(error => {
        console.error('Ошибка загрузки:', error);
        showUploadProgress(false);
        showNotification('Ошибка загрузки файла', 'error');
    });
}

/**
 * Заполнение формы данными из файла
 */
function fillEventForm(eventData) {
    if (!eventData) return;

    // Основные поля
    const fields = [
        'event-year',
        'event-dates',
        'event-name',
        'event-cost'
    ];

    fields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        const dataKey = fieldId.replace('event-', '').replace('-', '_');
        
        if (field && eventData[dataKey]) {
            field.value = eventData[dataKey];
        }
    });

    // Классы лодок
    if (eventData.boat_classes) {
        const boatClassesField = document.getElementById('event-boat-classes');
        if (boatClassesField) {
            boatClassesField.value = eventData.boat_classes.join('; ');
        }
    }

    // Пол
    if (eventData.genders) {
        const gendersField = document.getElementById('event-genders');
        if (gendersField) {
            gendersField.value = eventData.genders.join('; ');
        }
    }

    // Дистанции
    if (eventData.distances) {
        const distancesField = document.getElementById('event-distances');
        if (distancesField) {
            distancesField.value = eventData.distances.join('; ');
        }
    }

    // Возрастные группы
    if (eventData.age_groups) {
        const ageGroupsField = document.getElementById('event-age-groups');
        if (ageGroupsField) {
            ageGroupsField.value = eventData.age_groups.join('; ');
        }
    }
}

/**
 * Обработка создания мероприятия
 */
async function handleCreateEvent(event) {
    event.preventDefault();
    
    const formData = new FormData(event.target);
    
    // Валидация формы
    if (!validateEventForm(formData)) {
        return;
    }

    try {
        showNotification('Создание мероприятия...', 'info');
        
        const response = await fetch('/lks/php/organizer/create-event.php', {
            method: 'POST',
            headers: {
                'X-CSRF-Token': getCSRFToken()
            },
            body: formData
        });

        const data = await response.json();
        
        if (data.success) {
            showNotification('Мероприятие успешно создано', 'success');
            // Перенаправляем на страницу мероприятия
            setTimeout(() => {
                window.location.href = `event-details.php?id=${data.eventId}`;
            }, 1500);
        } else {
            showNotification(data.message || 'Ошибка создания мероприятия', 'error');
        }
    } catch (error) {
        console.error('Ошибка создания мероприятия:', error);
        showNotification('Ошибка создания мероприятия', 'error');
    }
}

/**
 * Валидация формы мероприятия
 */
function validateEventForm(formData) {
    const requiredFields = [
        'event-name',
        'event-dates',
        'event-year',
        'event-boat-classes',
        'event-genders',
        'event-distances'
    ];

    for (const field of requiredFields) {
        const value = formData.get(field);
        if (!value || value.trim() === '') {
            const fieldElement = document.getElementById(field);
            if (fieldElement) {
                fieldElement.focus();
                showNotification(`Заполните поле "${fieldElement.labels[0].textContent}"`, 'error');
            }
            return false;
        }
    }

    return true;
}

/**
 * Обработка действий с мероприятиями
 */
function handleEventAction(action, eventId) {
    switch (action) {
        case 'edit':
            editEvent(eventId);
            break;
        case 'delete':
            deleteEvent(eventId);
            break;
        case 'duplicate':
            duplicateEvent(eventId);
            break;
        case 'export':
            exportEventData(eventId);
            break;
        case 'registrations':
            viewRegistrations(eventId);
            break;
        case 'start-registration':
            startRegistration(eventId);
            break;
        case 'close-registration':
            closeRegistration(eventId);
            break;
    }
}

/**
 * Редактирование мероприятия
 */
function editEvent(eventId) {
    window.location.href = `edit-event.php?id=${eventId}`;
}

/**
 * Удаление мероприятия
 */
async function deleteEvent(eventId) {
    if (!confirm('Вы уверены, что хотите удалить это мероприятие? Это действие нельзя отменить.')) {
        return;
    }

    try {
        const response = await fetch('/lks/php/organizer/delete-event.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCSRFToken()
            },
            body: JSON.stringify({ eventId })
        });

        const data = await response.json();
        
        if (data.success) {
            showNotification('Мероприятие успешно удалено', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification(data.message || 'Ошибка удаления мероприятия', 'error');
        }
    } catch (error) {
        console.error('Ошибка удаления:', error);
        showNotification('Ошибка удаления мероприятия', 'error');
    }
}

/**
 * Дублирование мероприятия
 */
async function duplicateEvent(eventId) {
    try {
        const response = await fetch('/lks/php/organizer/duplicate-event.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCSRFToken()
            },
            body: JSON.stringify({ eventId })
        });

        const data = await response.json();
        
        if (data.success) {
            showNotification('Мероприятие успешно дублировано', 'success');
            window.location.href = `edit-event.php?id=${data.newEventId}`;
        } else {
            showNotification(data.message || 'Ошибка дублирования мероприятия', 'error');
        }
    } catch (error) {
        console.error('Ошибка дублирования:', error);
        showNotification('Ошибка дублирования мероприятия', 'error');
    }
}

/**
 * Экспорт данных мероприятия
 */
function exportEventData(eventId) {
    const link = document.createElement('a');
    link.href = `/lks/php/organizer/export-event.php?id=${eventId}`;
    link.download = '';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    showNotification('Экспорт начат', 'info');
}

/**
 * Просмотр регистраций
 */
function viewRegistrations(eventId) {
    // Переход на страницу просмотра регистраций организатора
    window.location.href = "/lks/enter/organizer/registrations.php?event=" + eventId;
}

/**
 * Начало регистрации
 */
async function startRegistration(eventId) {
    if (!confirm('Начать регистрацию на мероприятие?')) return;

    await changeEventStatus(eventId, 'Регистрация');
}

/**
 * Закрытие регистрации
 */
async function closeRegistration(eventId) {
    if (!confirm('Закрыть регистрацию на мероприятие?')) return;

    await changeEventStatus(eventId, 'Регистрация закрыта');
}

/**
 * Изменение статуса мероприятия
 */
async function changeEventStatus(eventId, status) {
    try {
        const response = await fetch('/lks/php/organizer/change-status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCSRFToken()
            },
            body: JSON.stringify({ eventId, status })
        });

        const data = await response.json();
        
        if (data.success) {
            showNotification(`Статус мероприятия изменен на "${status}"`, 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification(data.message || 'Ошибка изменения статуса', 'error');
        }
    } catch (error) {
        console.error('Ошибка изменения статуса:', error);
        showNotification('Ошибка изменения статуса мероприятия', 'error');
    }
}

/**
 * Обновление таблицы регистраций
 */
function updateRegistrationTable() {
    const filters = {
        event: document.getElementById('filter-event')?.value || '',
        status: document.getElementById('filter-status')?.value || '',
        payment: document.getElementById('filter-payment')?.value || ''
    };

    const params = new URLSearchParams(filters);
    
    fetch(`/lks/php/organizer/get-registrations.php?${params}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateRegistrationTableContent(data.registrations);
            }
        })
        .catch(error => {
            console.error('Ошибка обновления таблицы:', error);
        });
}

/**
 * Обновление содержимого таблицы регистраций
 */
function updateRegistrationTableContent(registrations) {
    const tableBody = document.getElementById('registrations-table-body');
    if (!tableBody) return;

    let html = '';
    
    registrations.forEach(reg => {
        html += `
            <tr>
                <td>${escapeHtml(reg.fio)}</td>
                <td>${escapeHtml(reg.event_name)}</td>
                <td><span class="badge bg-${getStatusColor(reg.status)}">${escapeHtml(reg.status)}</span></td>
                <td><span class="badge bg-${reg.payment ? 'success' : 'warning'}">${reg.payment ? 'Оплачено' : 'Не оплачено'}</span></td>
                <td>${new Date(reg.created_at).toLocaleDateString('ru-RU')}</td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary" onclick="viewRegistration(${reg.id})">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button class="btn btn-outline-success" onclick="confirmRegistration(${reg.id})">
                            <i class="bi bi-check"></i>
                        </button>
                        <button class="btn btn-outline-danger" onclick="cancelRegistration(${reg.id})">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    });
    
    tableBody.innerHTML = html;
}

/**
 * Получение цвета для статуса
 */
function getStatusColor(status) {
    const colors = {
        'В очереди': 'warning',
        'Зарегистрирован': 'success',
        'Подтверждён': 'primary',
        'Ожидание команды': 'info',
        'Дисквалифицирован': 'danger',
        'Неявка': 'secondary'
    };
    return colors[status] || 'secondary';
}

/**
 * Загрузка событий календаря
 */
async function loadCalendarEvents() {
    try {
        const response = await fetch('/lks/php/organizer/get-calendar-events.php');
        const data = await response.json();
        
        if (data.success) {
            renderCalendarEvents(data.events);
        }
    } catch (error) {
        console.error('Ошибка загрузки событий календаря:', error);
    }
}

/**
 * Отрисовка событий календаря
 */
function renderCalendarEvents(events) {
    // Здесь будет логика отрисовки календаря
    // Пока заглушка
}

/**
 * Валидация дат мероприятия
 */
function validateEventDates() {
    const startDate = document.getElementById('event-start-date')?.value;
    const endDate = document.getElementById('event-end-date')?.value;
    
    if (startDate && endDate && new Date(startDate) > new Date(endDate)) {
        showNotification('Дата начала не может быть позже даты окончания', 'error');
        return false;
    }
    
    return true;
}

/**
 * Обновление локации мероприятия
 */
function updateEventLocation(city) {
    // Автозаполнение данных о месте проведения на основе города
    const locationMapping = {
        'Москва': 'Гребной канал "Крылатское"',
        'Санкт-Петербург': 'Гребной канал им. Петра Великого',
        'Купавна': 'Купавинская гребная база'
    };
    
    const locationField = document.getElementById('event-location');
    if (locationField && locationMapping[city]) {
        locationField.value = locationMapping[city];
    }
}

/**
 * Получение CSRF токена
 */
function getCSRFToken() {
    const token = document.querySelector('meta[name="csrf-token"]');
    return token ? token.getAttribute('content') : '';
}

/**
 * Показ/скрытие прогресса загрузки
 */
function showUploadProgress(show) {
    const progressBar = document.getElementById('upload-progress');
    if (progressBar) {
        progressBar.style.display = show ? 'block' : 'none';
    }
}

/**
 * Экранирование HTML
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
} 
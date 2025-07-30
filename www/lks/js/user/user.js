/**
 * Скрипты для панели пользователя (спортсмена)
 * KGB-Pulse - Система управления гребной базой
 */

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    initializeUserPanel();
});

/**
 * Инициализация панели пользователя
 */
function initializeUserPanel() {
    // Инициализация обработчиков событий
    initializeEventHandlers();
    
    // Загрузка данных пользователя
    loadUserData();
    
    // Инициализация форм
    initializeForms();
    
    // Обновление статистики
    updateUserStats();
}

/**
 * Инициализация обработчиков событий
 */
function initializeEventHandlers() {
    // Форма профиля
    const profileForm = document.getElementById('profile-form');
    if (profileForm) {
        profileForm.addEventListener('submit', handleProfileUpdate);
    }

    // Регистрация на мероприятие
    const registrationForms = document.querySelectorAll('.event-registration-form');
    registrationForms.forEach(form => {
        form.addEventListener('submit', handleEventRegistration);
    });

    // Выбор типов лодок
    const boatCheckboxes = document.querySelectorAll('input[name="boats[]"]');
    boatCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateBoatSelection);
    });

    // Просмотр деталей мероприятия
    const eventDetailBtns = document.querySelectorAll('.view-event-details');
    eventDetailBtns.forEach(button => {
        button.addEventListener('click', function() {
            viewEventDetails(this.dataset.eventId);
        });
    });

    // Отмена регистрации
    const cancelBtns = document.querySelectorAll('.cancel-registration');
    cancelBtns.forEach(button => {
        button.addEventListener('click', function() {
            cancelRegistration(this.dataset.registrationId);
        });
    });

    // Загрузка документов
    const documentUpload = document.getElementById('document-upload');
    if (documentUpload) {
        documentUpload.addEventListener('change', handleDocumentUpload);
    }
}

/**
 * Инициализация форм
 */
function initializeForms() {
    // Валидация формы профиля
    const profileForm = document.getElementById('profile-form');
    if (profileForm) {
        addFormValidation(profileForm);
    }

    // Форматирование телефона
    const phoneInput = document.getElementById('telephone');
    if (phoneInput) {
        phoneInput.addEventListener('input', formatPhoneNumber);
    }

    // Валидация даты рождения
    const birthdateInput = document.getElementById('birthdate');
    if (birthdateInput) {
        birthdateInput.addEventListener('change', validateBirthdate);
    }
}

/**
 * Загрузка данных пользователя
 */
async function loadUserData() {
    try {
        const response = await fetch('/lks/php/user/get-user-data.php');
        const data = await response.json();
        
        if (data.success) {
            updateUserDisplay(data.user);
        }
    } catch (error) {
        console.error('Ошибка загрузки данных пользователя:', error);
    }
}

/**
 * Обновление отображения данных пользователя
 */
function updateUserDisplay(userData) {
    // Обновляем поля формы профиля
    const fields = ['fio', 'email', 'telephone', 'birthdate', 'country', 'city'];
    
    fields.forEach(field => {
        const input = document.getElementById(field);
        if (input && userData[field]) {
            input.value = userData[field];
        }
    });

    // Обновляем выбранные типы лодок
    if (userData.boats) {
        userData.boats.forEach(boat => {
            const checkbox = document.querySelector(`input[name="boats[]"][value="${boat}"]`);
            if (checkbox) {
                checkbox.checked = true;
            }
        });
    }

    // Обновляем спортивное звание
    if (userData.sportzvanie) {
        const select = document.getElementById('sportzvanie');
        if (select) {
            select.value = userData.sportzvanie;
        }
    }
}

/**
 * Обработка обновления профиля
 */
async function handleProfileUpdate(event) {
    event.preventDefault();
    
    const formData = new FormData(event.target);
    
    // Валидация формы
    if (!validateProfileForm(formData)) {
        return;
    }

    try {
        showNotification('Обновление профиля...', 'info');
        
        const response = await fetch('/lks/php/user/update-profile.php', {
            method: 'POST',
            headers: {
                'X-CSRF-Token': getCSRFToken()
            },
            body: formData
        });

        const data = await response.json();
        
        if (data.success) {
            showNotification('Профиль успешно обновлен', 'success');
            // Обновляем отображение
            updateUserDisplay(data.user);
        } else {
            showNotification(data.message || 'Ошибка обновления профиля', 'error');
        }
    } catch (error) {
        console.error('Ошибка обновления профиля:', error);
        showNotification('Ошибка обновления профиля', 'error');
    }
}

/**
 * Валидация формы профиля
 */
function validateProfileForm(formData) {
    // Проверка ФИО
    const fio = formData.get('fio');
    if (!fio || fio.trim().length < 5) {
        showNotification('ФИО должно содержать минимум 5 символов', 'error');
        return false;
    }

    // Проверка email
    const email = formData.get('email');
    if (!email || !isValidEmail(email)) {
        showNotification('Введите корректный email адрес', 'error');
        return false;
    }

    // Проверка телефона
    const telephone = formData.get('telephone');
    if (!telephone || !isValidPhone(telephone)) {
        showNotification('Введите корректный номер телефона', 'error');
        return false;
    }

    // Проверка даты рождения
    const birthdate = formData.get('birthdate');
    if (!birthdate || !isValidBirthdate(birthdate)) {
        showNotification('Введите корректную дату рождения', 'error');
        return false;
    }

    return true;
}

/**
 * Обработка регистрации на мероприятие
 */
async function handleEventRegistration(event) {
    event.preventDefault();
    
    const formData = new FormData(event.target);
    const eventId = formData.get('event_id');

    try {
        showNotification('Регистрация на мероприятие...', 'info');
        
        const response = await fetch('/lks/php/user/register-event.php', {
            method: 'POST',
            headers: {
                'X-CSRF-Token': getCSRFToken()
            },
            body: formData
        });

        const data = await response.json();
        
        if (data.success) {
            showNotification('Вы успешно зарегистрированы на мероприятие', 'success');
            
            // Скрываем форму регистрации
            const registrationCard = event.target.closest('.registration-card');
            if (registrationCard) {
                registrationCard.style.display = 'none';
            }
            
            // Обновляем список регистраций
            updateRegistrationsList();
        } else {
            showNotification(data.message || 'Ошибка регистрации', 'error');
        }
    } catch (error) {
        console.error('Ошибка регистрации:', error);
        showNotification('Ошибка регистрации на мероприятие', 'error');
    }
}

/**
 * Отмена регистрации
 */
async function cancelRegistration(registrationId) {
    if (!confirm('Вы уверены, что хотите отменить регистрацию?')) {
        return;
    }

    try {
        const response = await fetch('/lks/php/user/cancel-registration.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCSRFToken()
            },
            body: JSON.stringify({ registrationId })
        });

        const data = await response.json();
        
        if (data.success) {
            showNotification('Регистрация отменена', 'success');
            // Удаляем элемент из списка
            const registrationItem = document.querySelector(`[data-registration-id="${registrationId}"]`);
            if (registrationItem) {
                registrationItem.remove();
            }
            updateRegistrationsList();
        } else {
            showNotification(data.message || 'Ошибка отмены регистрации', 'error');
        }
    } catch (error) {
        console.error('Ошибка отмены регистрации:', error);
        showNotification('Ошибка отмены регистрации', 'error');
    }
}

/**
 * Просмотр деталей мероприятия
 */
async function viewEventDetails(eventId) {
    try {
        const response = await fetch(`/lks/php/user/get-event-details.php?id=${eventId}`);
        const data = await response.json();
        
        if (data.success) {
            showEventDetailsModal(data.event);
        } else {
            showNotification('Ошибка загрузки данных мероприятия', 'error');
        }
    } catch (error) {
        console.error('Ошибка загрузки деталей мероприятия:', error);
        showNotification('Ошибка загрузки данных мероприятия', 'error');
    }
}

/**
 * Показ модального окна с деталями мероприятия
 */
function showEventDetailsModal(eventData) {
    const modal = document.getElementById('eventDetailsModal');
    if (!modal) return;

    // Парсим дату мероприятия
    const eventDateInfo = parseEventDate(eventData.merodata);

    document.getElementById('modal-event-title').textContent = eventData.meroname;
    document.getElementById('modal-event-date').innerHTML = `
        <strong>Дата:</strong> ${eventDateInfo.date}<br>
        <strong>Год:</strong> ${eventDateInfo.year}
    `;
    document.getElementById('modal-event-status').textContent = eventData.status;
    document.getElementById('modal-event-cost').textContent = eventData.defcost + '₽';
    
    // Заполняем классы и дистанции
    const classesContainer = document.getElementById('modal-event-classes');
    const distancesContainer = document.getElementById('modal-event-distances');
    
    if (eventData.class_distance) {
        const classData = JSON.parse(eventData.class_distance);
        let classesHtml = '';
        let distancesHtml = '';
        
        Object.keys(classData).forEach(boatClass => {
            classesHtml += `<span class="badge bg-secondary me-1">${boatClass}</span>`;
            
            if (classData[boatClass].dist) {
                classData[boatClass].dist.forEach(dist => {
                    distancesHtml += `<span class="badge bg-info me-1">${dist}м</span>`;
                });
            }
        });
        
        classesContainer.innerHTML = classesHtml;
        distancesContainer.innerHTML = distancesHtml;
    }

    // Показываем модальное окно
    const bootstrapModal = new bootstrap.Modal(modal);
    bootstrapModal.show();
}

/**
 * Парсит дату мероприятия в формате "1 - 5 июля 2025"
 */
function parseEventDate(merodata) {
    if (!merodata) {
        return { date: '', year: '' };
    }
    
    // Ищем год в конце строки (4 цифры)
    const yearMatch = merodata.match(/\b(\d{4})\s*$/);
    if (yearMatch) {
        const year = yearMatch[1];
        // Убираем год из строки, оставляем только дату
        const date = merodata.replace(year, '').trim();
        return {
            date: date,
            year: year
        };
    }
    
    // Если год не найден, возвращаем как есть
    return {
        date: merodata,
        year: ''
    };
}

/**
 * Обновление выбора типов лодок
 */
function updateBoatSelection() {
    const selectedBoats = [];
    const boatCheckboxes = document.querySelectorAll('input[name="boats[]"]:checked');
    
    boatCheckboxes.forEach(checkbox => {
        selectedBoats.push(checkbox.value);
    });

    // Обновляем скрытое поле или отправляем AJAX запрос
    updateBoatSelectionDisplay(selectedBoats);
}

/**
 * Обновление отображения выбранных лодок
 */
function updateBoatSelectionDisplay(selectedBoats) {
    const displayContainer = document.getElementById('selected-boats-display');
    if (!displayContainer) return;

    if (selectedBoats.length === 0) {
        displayContainer.innerHTML = '<span class="text-muted">Типы лодок не выбраны</span>';
    } else {
        let html = '';
        selectedBoats.forEach(boat => {
            html += `<span class="badge bg-primary me-1">${boat}</span>`;
        });
        displayContainer.innerHTML = html;
    }
}

/**
 * Форматирование номера телефона
 */
function formatPhoneNumber(event) {
    let value = event.target.value.replace(/\D/g, '');
    
    if (value.length > 0) {
        if (value[0] === '8') {
            value = '7' + value.slice(1);
        }
        if (value[0] === '7') {
            value = value.slice(0, 11);
            value = value.replace(/^7(\d{3})(\d{3})(\d{2})(\d{2})$/, '7 ($1) $2-$3-$4');
        }
    }
    
    event.target.value = value;
}

/**
 * Валидация даты рождения
 */
function validateBirthdate(event) {
    const birthdate = new Date(event.target.value);
    const today = new Date();
    const age = today.getFullYear() - birthdate.getFullYear();
    
    if (age < 5 || age > 100) {
        showNotification('Возраст должен быть от 5 до 100 лет', 'error');
        event.target.value = '';
        return false;
    }
    
    return true;
}

/**
 * Обработка загрузки документов
 */
function handleDocumentUpload(event) {
    const file = event.target.files[0];
    if (!file) return;

    // Проверяем тип файла
    const allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
    if (!allowedTypes.includes(file.type)) {
        showNotification('Разрешены только файлы JPG, PNG и PDF', 'error');
        return;
    }

    // Проверяем размер (максимум 5MB)
    if (file.size > 5 * 1024 * 1024) {
        showNotification('Размер файла не должен превышать 5MB', 'error');
        return;
    }

    uploadDocument(file);
}

/**
 * Загрузка документа на сервер
 */
async function uploadDocument(file) {
    const formData = new FormData();
    formData.append('document', file);

    try {
        showNotification('Загрузка документа...', 'info');
        
        const response = await fetch('/lks/php/user/upload-document.php', {
            method: 'POST',
            headers: {
                'X-CSRF-Token': getCSRFToken()
            },
            body: formData
        });

        const data = await response.json();
        
        if (data.success) {
            showNotification('Документ успешно загружен', 'success');
            updateDocumentsList(data.document);
        } else {
            showNotification(data.message || 'Ошибка загрузки документа', 'error');
        }
    } catch (error) {
        console.error('Ошибка загрузки документа:', error);
        showNotification('Ошибка загрузки документа', 'error');
    }
}

/**
 * Обновление списка документов
 */
function updateDocumentsList(document) {
    const documentsList = document.getElementById('documents-list');
    if (!documentsList) return;

    const documentElement = createDocumentElement(document);
    documentsList.appendChild(documentElement);
}

/**
 * Создание элемента документа
 */
function createDocumentElement(document) {
    const div = document.createElement('div');
    div.className = 'document-item border rounded p-2 mb-2';
    div.innerHTML = `
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <i class="bi bi-file-earmark"></i>
                <span>${document.name}</span>
                <small class="text-muted">(${formatFileSize(document.size)})</small>
            </div>
            <div>
                <button class="btn btn-sm btn-outline-primary" onclick="downloadDocument('${document.path}')">
                    <i class="bi bi-download"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteDocument('${document.id}')">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
    `;
    return div;
}

/**
 * Обновление статистики пользователя
 */
async function updateUserStats() {
    try {
        const response = await fetch('/lks/php/user/get-user-stats.php');
        const data = await response.json();
        
        if (data.success) {
            updateStatsDisplay(data.stats);
        }
    } catch (error) {
        console.error('Ошибка загрузки статистики:', error);
    }
}

/**
 * Обновление отображения статистики
 */
function updateStatsDisplay(stats) {
    const statsElements = {
        'total-registrations': stats.totalRegistrations || 0,
        'total-events': stats.totalEvents || 0,
        'prize-places': stats.prizePlaces || 0,
        'active-registrations': stats.activeRegistrations || 0
    };

    Object.entries(statsElements).forEach(([id, value]) => {
        const element = document.getElementById(id);
        if (element) {
            animateNumber(element, parseInt(value));
        }
    });
}

/**
 * Анимация изменения числа
 */
function animateNumber(element, targetValue) {
    const currentValue = parseInt(element.textContent) || 0;
    const increment = (targetValue - currentValue) / 20;
    let currentStep = 0;

    const animation = setInterval(() => {
        currentStep++;
        const newValue = Math.round(currentValue + increment * currentStep);
        element.textContent = newValue;

        if (currentStep >= 20) {
            element.textContent = targetValue;
            clearInterval(animation);
        }
    }, 50);
}

/**
 * Обновление списка регистраций
 */
async function updateRegistrationsList() {
    try {
        const response = await fetch('/lks/php/user/get-registrations.php');
        const data = await response.json();
        
        if (data.success) {
            updateRegistrationsDisplay(data.registrations);
        }
    } catch (error) {
        console.error('Ошибка обновления списка регистраций:', error);
    }
}

/**
 * Обновление отображения регистраций
 */
function updateRegistrationsDisplay(registrations) {
    const container = document.getElementById('registrations-container');
    if (!container) return;

    let html = '';
    
    if (registrations.length === 0) {
        html = '<div class="text-center text-muted py-4">Нет активных регистраций</div>';
    } else {
        registrations.forEach(reg => {
            html += createRegistrationCard(reg);
        });
    }
    
    container.innerHTML = html;
}

/**
 * Создание карточки регистрации
 */
function createRegistrationCard(registration) {
    return `
        <div class="card mb-3" data-registration-id="${registration.id}">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="card-title">${registration.event_name}</h6>
                        <p class="card-text text-muted">${registration.event_date}</p>
                        <span class="badge bg-${getRegistrationStatusColor(registration.status)}">${registration.status}</span>
                        <span class="badge bg-${registration.payment ? 'success' : 'warning'} ms-1">
                            ${registration.payment ? 'Оплачено' : 'Не оплачено'}
                        </span>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-primary" onclick="viewEventDetails(${registration.event_id})">
                            <i class="bi bi-eye"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="cancelRegistration(${registration.id})">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
}

/**
 * Получение цвета статуса регистрации
 */
function getRegistrationStatusColor(status) {
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
 * Вспомогательные функции валидации
 */
function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

function isValidPhone(phone) {
    const phoneRegex = /^7\s?\(\d{3}\)\s?\d{3}-\d{2}-\d{2}$/;
    return phoneRegex.test(phone);
}

function isValidBirthdate(birthdate) {
    const date = new Date(birthdate);
    const today = new Date();
    return date < today && date > new Date('1920-01-01');
}

/**
 * Форматирование размера файла
 */
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

/**
 * Получение CSRF токена
 */
function getCSRFToken() {
    const token = document.querySelector('meta[name="csrf-token"]');
    return token ? token.getAttribute('content') : '';
}

/**
 * Добавление валидации к форме
 */
function addFormValidation(form) {
    const inputs = form.querySelectorAll('input, select, textarea');
    
    inputs.forEach(input => {
        input.addEventListener('blur', function() {
            validateField(this);
        });
        
        input.addEventListener('input', function() {
            clearFieldError(this);
        });
    });
}

/**
 * Валидация поля
 */
function validateField(field) {
    const value = field.value.trim();
    let isValid = true;
    let message = '';

    switch (field.type) {
        case 'email':
            isValid = isValidEmail(value);
            message = 'Введите корректный email адрес';
            break;
        case 'tel':
            isValid = isValidPhone(value);
            message = 'Введите корректный номер телефона';
            break;
        case 'date':
            isValid = isValidBirthdate(value);
            message = 'Введите корректную дату рождения';
            break;
    }

    if (field.required && !value) {
        isValid = false;
        message = 'Это поле обязательно для заполнения';
    }

    if (!isValid) {
        showFieldError(field, message);
    } else {
        clearFieldError(field);
    }

    return isValid;
}

/**
 * Показ ошибки поля
 */
function showFieldError(field, message) {
    field.classList.add('is-invalid');
    
    let errorDiv = field.parentNode.querySelector('.invalid-feedback');
    if (!errorDiv) {
        errorDiv = document.createElement('div');
        errorDiv.className = 'invalid-feedback';
        field.parentNode.appendChild(errorDiv);
    }
    
    errorDiv.textContent = message;
}

/**
 * Очистка ошибки поля
 */
function clearFieldError(field) {
    field.classList.remove('is-invalid');
    
    const errorDiv = field.parentNode.querySelector('.invalid-feedback');
    if (errorDiv) {
        errorDiv.remove();
    }
} 
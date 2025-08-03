/**
 * Главный JavaScript файл для KGB Pulse
 * Управление sidebar, уведомлениями и основными функциями
 */

// Глобальные переменные
const KGBPulse = {
    // API базовый URL
    apiUrl: '/php/',
    
    // Настройки
    settings: {
        animationDuration: 300,
        toastTimeout: 5000,
        ajaxTimeout: 30000
    },

    // Утилиты
    utils: {
        // Показать уведомление
        showToast: function(message, type = 'info', timeout = KGBPulse.settings.toastTimeout) {
            const toastContainer = document.getElementById('toast-container') || KGBPulse.utils.createToastContainer();
            const toast = KGBPulse.utils.createToast(message, type);
            
            toastContainer.appendChild(toast);
            
            // Показать тост
            setTimeout(() => {
                toast.classList.add('show');
            }, 100);
            
            // Скрыть тост
            setTimeout(() => {
                KGBPulse.utils.hideToast(toast);
            }, timeout);
        },

        // Создать контейнер для тостов
        createToastContainer: function() {
            const container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '1056';
            document.body.appendChild(container);
            return container;
        },

        // Создать тост
        createToast: function(message, type) {
            const toast = document.createElement('div');
            toast.className = `toast align-items-center text-white bg-${type} border-0`;
            toast.setAttribute('role', 'alert');
            
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            
            return toast;
        },

        // Скрыть тост
        hideToast: function(toast) {
            toast.classList.remove('show');
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, KGBPulse.settings.animationDuration);
        },

        // AJAX запрос
        ajax: function(options) {
            const defaults = {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                timeout: KGBPulse.settings.ajaxTimeout
            };

            const config = Object.assign({}, defaults, options);

            return fetch(config.url, {
                method: config.method,
                headers: config.headers,
                body: config.data ? JSON.stringify(config.data) : null,
                signal: AbortSignal.timeout(config.timeout)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .catch(error => {
                console.error('AJAX Error:', error);
                throw error;
            });
        },

        // Валидация email
        validateEmail: function(email) {
            const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return regex.test(email);
        },

        // Валидация телефона (российский формат)
        validatePhone: function(phone) {
            const regex = /^(\+7|7|8)?[\s\-]?\(?[489][0-9]{2}\)?[\s\-]?[0-9]{3}[\s\-]?[0-9]{2}[\s\-]?[0-9]{2}$/;
            return regex.test(phone);
        },

        // Форматирование телефона
        formatPhone: function(phone) {
            const cleaned = phone.replace(/\D/g, '');
            if (cleaned.length === 11 && cleaned.startsWith('8')) {
                return '+7' + cleaned.slice(1);
            } else if (cleaned.length === 11 && cleaned.startsWith('7')) {
                return '+' + cleaned;
            } else if (cleaned.length === 10) {
                return '+7' + cleaned;
            }
            return phone;
        },

        // Форматирование даты
        formatDate: function(date) {
            if (!(date instanceof Date)) {
                date = new Date(date);
            }
            return date.toLocaleDateString('ru-RU');
        },

        // Загрузка файла
        uploadFile: function(file, endpoint, onProgress = null) {
            return new Promise((resolve, reject) => {
                const formData = new FormData();
                formData.append('file', file);

                const xhr = new XMLHttpRequest();

                if (onProgress) {
                    xhr.upload.addEventListener('progress', (e) => {
                        if (e.lengthComputable) {
                            const percentComplete = (e.loaded / e.total) * 100;
                            onProgress(percentComplete);
                        }
                    });
                }

                xhr.addEventListener('load', () => {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            resolve(response);
                        } catch (e) {
                            reject(new Error('Ошибка парсинга ответа'));
                        }
                    } else {
                        reject(new Error(`Ошибка загрузки: ${xhr.status}`));
                    }
                });

                xhr.addEventListener('error', () => {
                    reject(new Error('Ошибка сети'));
                });

                xhr.open('POST', endpoint);
                xhr.send(formData);
            });
        },

        // Показать модальное окно подтверждения
        confirm: function(message, onConfirm, onCancel = null) {
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.innerHTML = `
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Подтверждение</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p>${message}</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                            <button type="button" class="btn btn-primary" id="confirm-btn">Подтвердить</button>
                        </div>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);
            const bsModal = new bootstrap.Modal(modal);

            modal.querySelector('#confirm-btn').addEventListener('click', () => {
                bsModal.hide();
                if (onConfirm) onConfirm();
            });

            modal.addEventListener('hidden.bs.modal', () => {
                document.body.removeChild(modal);
                if (onCancel) onCancel();
            });

            bsModal.show();
        },

        // Показать загрузчик
        showLoader: function() {
            const loader = document.createElement('div');
            loader.id = 'global-loader';
            loader.className = 'position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center';
            loader.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
            loader.style.zIndex = '9999';
            loader.innerHTML = '<div class="spinner"></div>';
            document.body.appendChild(loader);
        },

        // Скрыть загрузчик
        hideLoader: function() {
            const loader = document.getElementById('global-loader');
            if (loader) {
                document.body.removeChild(loader);
            }
        }
    },

    // Инициализация
    init: function() {
        // Инициализация тултипов
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // Инициализация попапов
        const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
        popoverTriggerList.map(function(popoverTriggerEl) {
            return new bootstrap.Popover(popoverTriggerEl);
        });

        // Автозакрытие алертов
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);

        // Обработка форм с AJAX
        KGBPulse.initAjaxForms();

        // Анимация появления элементов
        KGBPulse.initAnimations();
    },

    // Инициализация AJAX форм
    initAjaxForms: function() {
        const ajaxForms = document.querySelectorAll('form[data-ajax="true"]');
        ajaxForms.forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                KGBPulse.handleAjaxForm(this);
            });
        });
    },

    // Обработка AJAX формы
    handleAjaxForm: function(form) {
        const formData = new FormData(form);
        const action = form.getAttribute('action') || '';
        const method = form.getAttribute('method') || 'POST';
        
        KGBPulse.utils.showLoader();

        fetch(action, {
            method: method,
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            KGBPulse.utils.hideLoader();
            
            if (data.success) {
                KGBPulse.utils.showToast(data.message || 'Операция выполнена успешно', 'success');
                
                if (data.redirect) {
                    setTimeout(() => {
                        window.location.href = data.redirect;
                    }, 1000);
                }
            } else {
                KGBPulse.utils.showToast(data.message || 'Произошла ошибка', 'danger');
            }
        })
        .catch(error => {
            KGBPulse.utils.hideLoader();
            KGBPulse.utils.showToast('Ошибка сети. Попробуйте позже.', 'danger');
            console.error('Form error:', error);
        });
    },

    // Инициализация анимаций
    initAnimations: function() {
        // Intersection Observer для анимаций при прокрутке
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in');
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);

        // Наблюдение за элементами для анимации
        const animateElements = document.querySelectorAll('.card, .stat-card, .protocol-card');
        animateElements.forEach(el => observer.observe(el));
    }
};

// Дополнительные функции для авторизованных пользователей
const AuthenticatedFeatures = {
    // Переключение боковой панели
    toggleSidebar: function() {
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content');
        const footer = document.querySelector('footer#footer');
        
        if (sidebar) {
            const wasCollapsed = sidebar.classList.contains('collapsed');
            
            if (wasCollapsed) {
                // Разворачиваем
                sidebar.classList.remove('collapsed');
                if (mainContent) {
                    mainContent.classList.remove('sidebar-collapsed');
                }
                if (footer) {
                    footer.classList.remove('sidebar-collapsed');
                }
                localStorage.setItem('sidebarCollapsed', 'false');
            } else {
                // Сворачиваем
                sidebar.classList.add('collapsed');
                if (mainContent) {
                    mainContent.classList.add('sidebar-collapsed');
                }
                if (footer) {
                    footer.classList.add('sidebar-collapsed');
                }
                localStorage.setItem('sidebarCollapsed', 'true');
            }
        } else {
            console.error('Sidebar element not found');
        }
    },

    // Восстановить состояние боковой панели
    restoreSidebarState: function() {
        const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content');
        const footer = document.querySelector('footer#footer');
        
        if (isCollapsed && sidebar) {
            sidebar.classList.add('collapsed');
            if (mainContent) {
                mainContent.classList.add('sidebar-collapsed');
            }
            if (footer) {
                footer.classList.add('sidebar-collapsed');
            }
        }
    },

    // Обновление уведомлений
    updateNotifications: function() {
        fetch('/lks/php/common/get_notifications.php', {
            method: 'GET',
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const badge = document.querySelector('.notifications .badge');
                const notificationsList = document.getElementById('notificationsList');
                
                if (badge) {
                    badge.textContent = data.count || '';
                    badge.style.display = data.count > 0 ? 'flex' : 'none';
                }
                
                if (notificationsList && data.notifications) {
                    if (data.notifications.length === 0) {
                        notificationsList.innerHTML = '<a class="dropdown-item text-center text-muted" href="#">Нет новых уведомлений</a>';
                    } else {
                        notificationsList.innerHTML = data.notifications.map(notification => `
                            <a class="dropdown-item" href="#" data-notification-id="${notification.oid}">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="mb-1">${notification.title}</div>
                                        <small class="text-muted">${notification.message}</small>
                                        <br><small class="text-muted">${formatDate(notification.created_at)}</small>
                                    </div>
                                    ${!notification.is_read ? '<span class="badge bg-primary rounded-pill">●</span>' : ''}
                                </div>
                            </a>
                        `).join('');
                        
                        // Добавляем обработчики клика по уведомлениям
                        notificationsList.querySelectorAll('[data-notification-id]').forEach(item => {
                            item.addEventListener('click', function(e) {
                                e.preventDefault();
                                const notificationId = this.getAttribute('data-notification-id');
                                AuthenticatedFeatures.markNotificationAsRead(notificationId);
                            });
                        });
                    }
                }
            }
        })
        .catch(error => {
            console.error('Ошибка загрузки уведомлений:', error);
        });
    },

    // Отметка уведомления как прочитанного
    markNotificationAsRead: function(notificationId) {
        fetch('/lks/php/common/mark_notification_read.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            credentials: 'same-origin',
            body: `notification_id=${notificationId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Обновляем уведомления после отметки как прочитанного
                this.updateNotifications();
            }
        })
        .catch(error => {
            console.error('Ошибка отметки уведомления:', error);
        });
    }
};

// Функция форматирования даты
function formatDate(dateString) {
    if (!dateString) return '';
    
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return '';
    
    const now = new Date();
    const diff = now - date;
    const minutes = Math.floor(diff / 60000);
    const hours = Math.floor(diff / 3600000);
    const days = Math.floor(diff / 86400000);
    
    if (minutes < 1) return 'Только что';
    if (minutes < 60) return `${minutes} мин назад`;
    if (hours < 24) return `${hours} ч назад`;
    if (days < 7) return `${days} дн назад`;
    
    return date.toLocaleDateString('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric'
    });
}

// Инициализация Bootstrap dropdown
function initBootstrapDropdowns() {
    console.log('Инициализация Bootstrap dropdown...');
    
    // Проверяем наличие Bootstrap
    if (typeof bootstrap === 'undefined') {
        console.error('Bootstrap не загружен!');
        // Повторяем попытку через 100мс
        setTimeout(initBootstrapDropdowns, 100);
        return;
    }
    
    console.log('Bootstrap доступен:', bootstrap);
    console.log('Bootstrap.Dropdown доступен:', typeof bootstrap.Dropdown);
    
    // Инициализация dropdown для уведомлений
    const notificationsDropdown = document.getElementById('notificationsDropdown');
    if (notificationsDropdown) {
        console.log('Найдена кнопка уведомлений:', notificationsDropdown);
        
        try {
            // Проверяем существующий экземпляр
            const existingDropdown = bootstrap.Dropdown.getInstance(notificationsDropdown);
            if (existingDropdown) {
                console.log('Удаляем существующий экземпляр dropdown уведомлений');
                existingDropdown.dispose();
            }
            
            // Создаем новый экземпляр
            const dropdown = new bootstrap.Dropdown(notificationsDropdown, {
                autoClose: true,
                boundary: 'viewport'
            });
            console.log('Dropdown уведомлений инициализирован:', dropdown);
            
            // Добавляем обработчик событий
            notificationsDropdown.addEventListener('show.bs.dropdown', function() {
                console.log('Dropdown уведомлений открывается');
            });
            
            notificationsDropdown.addEventListener('shown.bs.dropdown', function() {
                console.log('Dropdown уведомлений открыт');
            });
            
            notificationsDropdown.addEventListener('hide.bs.dropdown', function() {
                console.log('Dropdown уведомлений закрывается');
            });
            
            notificationsDropdown.addEventListener('hidden.bs.dropdown', function() {
                console.log('Dropdown уведомлений закрыт');
            });
            
            // Добавляем обработчик клика для логирования
            notificationsDropdown.addEventListener('click', function(e) {
                console.log('Клик по кнопке уведомлений!');
                console.log('Экземпляр dropdown:', bootstrap.Dropdown.getInstance(this));
                
                // Проверяем состояние dropdown после клика
                setTimeout(() => {
                    const dropdownElement = this.closest('.dropdown');
                    const menu = this.nextElementSibling;
                    console.log('Состояние dropdown уведомлений после клика:');
                    console.log('- Dropdown show:', dropdownElement.classList.contains('show'));
                    console.log('- Menu show:', menu.classList.contains('show'));
                    console.log('- aria-expanded:', this.getAttribute('aria-expanded'));
                }, 100);
            });
            
        } catch (error) {
            console.error('Ошибка инициализации dropdown уведомлений:', error);
        }
    } else {
        console.warn('Кнопка уведомлений не найдена');
    }
    
    // Инициализация dropdown для пользователя
    const userMenuDropdown = document.getElementById('userMenuDropdown');
    if (userMenuDropdown) {
        console.log('Найдена кнопка пользователя:', userMenuDropdown);
        
        try {
            // Проверяем существующий экземпляр
            const existingDropdown = bootstrap.Dropdown.getInstance(userMenuDropdown);
            if (existingDropdown) {
                console.log('Удаляем существующий экземпляр dropdown пользователя');
                existingDropdown.dispose();
            }
            
            // Создаем новый экземпляр
            const dropdown = new bootstrap.Dropdown(userMenuDropdown, {
                autoClose: true,
                boundary: 'viewport'
            });
            console.log('Dropdown пользователя инициализирован:', dropdown);
            
            // Добавляем обработчик событий
            userMenuDropdown.addEventListener('show.bs.dropdown', function() {
                console.log('Dropdown пользователя открывается');
            });
            
            userMenuDropdown.addEventListener('shown.bs.dropdown', function() {
                console.log('Dropdown пользователя открыт');
            });
            
            userMenuDropdown.addEventListener('hide.bs.dropdown', function() {
                console.log('Dropdown пользователя закрывается');
            });
            
            userMenuDropdown.addEventListener('hidden.bs.dropdown', function() {
                console.log('Dropdown пользователя закрыт');
            });
            
            // Добавляем обработчик клика для логирования
            userMenuDropdown.addEventListener('click', function(e) {
                console.log('Клик по кнопке пользователя!');
                console.log('Экземпляр dropdown:', bootstrap.Dropdown.getInstance(this));
                
                // Проверяем состояние dropdown после клика
                setTimeout(() => {
                    const dropdownElement = this.closest('.dropdown');
                    const menu = this.nextElementSibling;
                    console.log('Состояние dropdown пользователя после клика:');
                    console.log('- Dropdown show:', dropdownElement.classList.contains('show'));
                    console.log('- Menu show:', menu.classList.contains('show'));
                    console.log('- aria-expanded:', this.getAttribute('aria-expanded'));
                }, 100);
            });
            
        } catch (error) {
            console.error('Ошибка инициализации dropdown пользователя:', error);
        }
    } else {
        console.warn('Кнопка пользователя не найдена');
    }
    
    // Добавляем обработчик для закрытия dropdown при клике вне их
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown')) {
            const dropdowns = document.querySelectorAll('.dropdown.show');
            dropdowns.forEach(dropdown => {
                const bsDropdown = bootstrap.Dropdown.getInstance(dropdown.querySelector('[data-bs-toggle="dropdown"]'));
                if (bsDropdown) {
                    bsDropdown.hide();
                }
            });
        }
    });
    
    // Добавляем обработчик для закрытия dropdown при нажатии Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const dropdowns = document.querySelectorAll('.dropdown.show');
            dropdowns.forEach(dropdown => {
                const bsDropdown = bootstrap.Dropdown.getInstance(dropdown.querySelector('[data-bs-toggle="dropdown"]'));
                if (bsDropdown) {
                    bsDropdown.hide();
                }
            });
        }
    });
}

// Инициализация компонентов
function initComponents() {
    console.log('Инициализация компонентов...');
    
    // Проверяем загрузку Bootstrap перед инициализацией dropdown
    if (typeof bootstrap === 'undefined') {
        console.log('Bootstrap еще не загружен, ждем...');
        setTimeout(initComponents, 100);
        return;
    }
    
    console.log('Bootstrap загружен, инициализируем компоненты...');
    
    // Инициализация dropdown
    initBootstrapDropdowns();
    
    // Инициализация уведомлений
    if (typeof AuthenticatedFeatures !== 'undefined') {
        AuthenticatedFeatures.updateNotifications();
        // Обновляем уведомления каждые 30 секунд
        setInterval(() => AuthenticatedFeatures.updateNotifications(), 30000);
    }
    
    // Инициализация sidebar
    if (typeof SidebarManager !== 'undefined') {
        const sidebarManager = new SidebarManager();
        sidebarManager.init();
    }
    
    // Инициализация модальных окон
    const modalTriggers = document.querySelectorAll('[data-bs-toggle="modal"]');
    modalTriggers.forEach(trigger => {
        trigger.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('data-bs-target');
            const modal = document.querySelector(targetId);
            if (modal) {
                const bootstrapModal = new bootstrap.Modal(modal);
                bootstrapModal.show();
            }
        });
    });
    
    // Инициализация форм
    const ajaxForms = document.querySelectorAll('form[data-ajax="true"]');
    ajaxForms.forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            // Здесь можно добавить обработку AJAX форм
        });
    });
}

// Экспорт для глобального использования
window.KGBPulse = KGBPulse;

// Основная инициализация
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM загружен, начинаем инициализацию...');
    
    // Проверяем загрузку Bootstrap
    if (typeof bootstrap === 'undefined') {
        console.log('Bootstrap еще не загружен, ждем...');
        // Проверяем каждые 100мс
        const checkBootstrap = setInterval(() => {
            if (typeof bootstrap !== 'undefined') {
                console.log('Bootstrap загружен!');
                clearInterval(checkBootstrap);
                initComponents();
            }
        }, 100);
        
        // Таймаут на случай, если Bootstrap не загрузится
        setTimeout(() => {
            clearInterval(checkBootstrap);
            console.error('Bootstrap не загрузился в течение 5 секунд');
        }, 5000);
        
        return;
    }
    
    console.log('Bootstrap уже загружен, инициализируем компоненты...');
    initComponents();
}); 
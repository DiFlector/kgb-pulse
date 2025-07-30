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
        KGBPulse.utils.ajax({
            url: '/lks/php/common/get_notifications.php',
            method: 'GET'
        })
        .then(data => {
            if (data.success) {
                const badge = document.querySelector('.notifications .badge');
                const dropdown = document.querySelector('.notifications .dropdown-menu');
                
                if (badge) {
                    badge.textContent = data.count || '';
                    badge.style.display = data.count > 0 ? 'flex' : 'none';
                }
                
                if (dropdown && data.notifications) {
                    dropdown.innerHTML = data.notifications.map(notification => `
                        <li>
                            <a class="dropdown-item" href="#">
                                <div class="d-flex align-items-start">
                                    <i class="fas fa-${notification.icon} me-2 mt-1"></i>
                                    <div>
                                        <div class="fw-bold">${notification.title}</div>
                                        <small class="text-muted">${notification.message}</small>
                                        <br><small class="text-muted">${notification.time}</small>
                                    </div>
                                </div>
                            </a>
                        </li>
                    `).join('');
                }
            }
        })
        .catch(error => {
            console.error('Ошибка загрузки уведомлений:', error);
        });
    }
};

// Функционал уведомлений (только для авторизованных страниц)
class NotificationManager {
    constructor() {
        // Используем правильные селекторы из header.php
        this.notificationBadge = document.getElementById('notificationBadge');
        this.notificationsList = document.getElementById('notificationsList');
        
        if (this.notificationBadge || this.notificationsList) {
            this.init();
        }
    }

    init() {
        this.loadNotifications();
        // Обновляем уведомления каждую минуту
        setInterval(() => this.loadNotifications(), 60000);
    }

    async loadNotifications() {
        try {
            const response = await fetch('/lks/php/common/get_notifications.php');
            const data = await response.json();
            
            if (data.success) {
                this.updateNotificationUI(data.notifications || []);
            }
        } catch (error) {
            console.error('Error loading notifications:', error);
        }
    }

    async markAsRead(notificationId) {
        try {
            const formData = new FormData();
            formData.append('notification_id', notificationId);
            
            const response = await fetch('/lks/php/common/mark_notification_read.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            if (data.success) {
                this.loadNotifications(); // Перезагружаем список
            }
        } catch (error) {
            console.error('Error marking notification as read:', error);
        }
    }

    updateNotificationUI(notifications) {
        // Обновляем счетчик
        if (this.notificationBadge) {
            if (notifications.length === 0) {
                this.notificationBadge.style.display = 'none';
            } else {
                this.notificationBadge.style.display = 'block';
                this.notificationBadge.textContent = notifications.length;
            }
        }
        
        // Обновляем список
        if (this.notificationsList) {
            if (notifications.length === 0) {
                this.notificationsList.innerHTML = '<li><a class="dropdown-item text-center text-muted" href="#">Нет новых уведомлений</a></li>';
            } else {
                let html = '';
                notifications.forEach(notification => {
                    html += `
                        <li>
                            <a class="dropdown-item notification-item" href="#" onclick="window.markNotificationAsRead(${notification.oid})">
                                <div class="notification-title">${notification.title}</div>
                                <div class="notification-message">${notification.message}</div>
                                <small class="notification-time text-muted">${this.formatDate(notification.created_at)}</small>
                            </a>
                        </li>
                    `;
                });
                this.notificationsList.innerHTML = html;
            }
        }
    }

    formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('ru-RU', {
            day: '2-digit',
            month: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    }
}

// Глобальная функция для отметки уведомлений как прочитанных
window.markNotificationAsRead = async function(notificationId) {
    try {
        const formData = new FormData();
        formData.append('notification_id', notificationId);
        
        const response = await fetch('/lks/php/common/mark_notification_read.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success && window.notificationManager) {
            window.notificationManager.loadNotifications();
        }
    } catch (error) {
        console.error('Ошибка отметки уведомления как прочитанного:', error);
    }
};

// Экспорт для глобального использования
window.KGBPulse = KGBPulse;

// Инициализация при загрузке DOM
$(document).ready(function() {
    
    // Инициализация компонентов
    // initSidebar(); // ОТКЛЮЧЕНО - используем SidebarManager
    initNotifications();
    initBoatsManagement();
    
    // Загружаем статистику для админа
    if (isAdminPage()) {
        loadAdminStats();
    }
});

/**
 * Инициализация системы уведомлений
 */
function initNotifications() {
    const $notificationBadge = $('#notificationBadge');
    const $notificationsList = $('#notificationsList');
    
    // Загружаем уведомления при загрузке страницы
    loadNotifications();
    
    // Обновляем уведомления каждые 30 секунд
    setInterval(loadNotifications, 30000);
    
    /**
     * Загрузка уведомлений с сервера
     */
    function loadNotifications() {
        $.ajax({
            url: '/lks/php/common/get_notifications.php',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    updateNotificationUI(response.notifications);
                }
            },
            error: function(xhr, status, error) {
                console.error('Ошибка загрузки уведомлений:', error);
            }
        });
    }
    
    /**
     * Обновление интерфейса уведомлений
     */
    function updateNotificationUI(notifications) {
        const unreadCount = notifications.filter(n => !n.is_read).length;
        
        // Обновляем счетчик
        if (unreadCount > 0) {
            $notificationBadge.text(unreadCount);
            $notificationBadge.show();
        } else {
            $notificationBadge.hide();
        }
        
        // Обновляем список уведомлений
        $notificationsList.empty();
        
        if (notifications.length === 0) {
            $notificationsList.append('<a class="dropdown-item text-center text-muted" href="#">Нет новых уведомлений</a>');
        } else {
            notifications.slice(0, 5).forEach(function(notification) {
                const isRead = notification.is_read ? '' : 'fw-bold';
                const item = `
                    <a class="dropdown-item ${isRead}" href="#" data-notification-id="${notification.id}">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="mb-1">${notification.message}</div>
                                <small class="text-muted">${formatDate(notification.created_at)}</small>
                            </div>
                            ${!notification.is_read ? '<span class="badge bg-primary rounded-pill">●</span>' : ''}
                        </div>
                    </a>
                `;
                $notificationsList.append(item);
            });
            
            if (notifications.length > 5) {
                $notificationsList.append('<hr class="dropdown-divider">');
                $notificationsList.append('<a class="dropdown-item text-center" href="#">Все уведомления</a>');
            }
        }
    }
    
    // Обработчик клика по уведомлению
    $notificationsList.on('click', '[data-notification-id]', function(e) {
        e.preventDefault();
        const notificationId = $(this).data('notification-id');
        markNotificationAsRead(notificationId);
    });
    
    /**
     * Отметка уведомления как прочитанного
     */
    function markNotificationAsRead(notificationId) {
        $.ajax({
            url: '/lks/php/common/mark_notification_read.php',
            method: 'POST',
            data: { notification_id: notificationId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    loadNotifications(); // Перезагружаем уведомления
                }
            },
            error: function(xhr, status, error) {
                console.error('Ошибка отметки уведомления:', error);
            }
        });
    }
}

/**
 * Проверка, является ли текущая страница админской
 */
function isAdminPage() {
    return window.location.pathname.includes('/admin/');
}

/**
 * Загрузка статистики для админа
 */
function loadAdminStats() {
    // Загружаем статистику
    $.get('/lks/php/admin/get_stats.php', function(data) {
        if (data.success) {
            // Обновляем счетчики
            $('#users-count').text(data.users.total || '-');
            $('#events-count').text(data.events.total || '-');
            $('#registrations-count').text(data.registrations.total || '-');
            $('#disk-usage').text(data.system.database_size || '-');
            $('#files-count').text(data.files.total_files || '-');
            $('#files-size').text(data.files.total_size || '-');
            
            // Обновляем статистику по ролям
            if (data.users.by_role) {
                $('#admin-count').text(data.users.by_role.Admin || 0);
                $('#organizer-count').text(data.users.by_role.Organizer || 0);
                $('#secretary-count').text(data.users.by_role.Secretary || 0);
                $('#sportsman-count').text(data.users.by_role.Sportsman || 0);
            }
            
            // Обновляем статистику регистраций
            if (data.registrations) {
                $('#paid-registrations').text(data.registrations.paid || 0);
                $('#total-registrations').text(data.registrations.total || 0);
            }
            

        }
    }).fail(function(xhr, status, error) {
        console.error('Не удалось загрузить статистику:', error);
    });
}

/**
 * Форматирование даты
 */
function formatDate(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now - date;
    const diffMinutes = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);
    
    if (diffMinutes < 1) return 'только что';
    if (diffMinutes < 60) return `${diffMinutes} мин назад`;
    if (diffHours < 24) return `${diffHours} ч назад`;
    if (diffDays < 7) return `${diffDays} дн назад`;
    
    return date.toLocaleDateString('ru-RU');
}

/**
 * Инициализация управления лодками
 */
function initBoatsManagement() {
    // Не запускаем на странице профиля, там есть встроенный код
    if (window.location.pathname.includes('/profile.php')) {
        return;
    }
    
    // Найти все чекбоксы лодок
    var boatCheckboxes = document.querySelectorAll('input[name="boats[]"]');
    
    if (boatCheckboxes.length === 0) {
        return;
    }
    
    // Добавить обработчики событий
    for (var i = 0; i < boatCheckboxes.length; i++) {
        var checkbox = boatCheckboxes[i];
        
        checkbox.addEventListener('change', function() {
            
            // Проверяем роль пользователя перед сохранением
            // Получаем роль из глобальной переменной или атрибута данных
            var userRole = window.userRole || document.body.getAttribute('data-user-role');
            
            if (userRole === 'Organizer') {
                this.checked = !this.checked; // Отменяем изменение
                if (typeof showNotification === 'function') {
                    showNotification('Организаторы не могут изменять типы лодок', 'warning');
                } else {
                    alert('Организаторы не могут изменять типы лодок');
                }
                return;
            }
            
            if (userRole === 'Secretary') {
                this.checked = !this.checked; // Отменяем изменение
                if (typeof showNotification === 'function') {
                    showNotification('Секретари не могут изменять типы лодок', 'warning');
                } else {
                    alert('Секретари не могут изменять типы лодок');
                }
                return;
            }
            
            saveBoats();
        });
    }
    
    function saveBoats() {

        
        // Найти индикатор загрузки
        var loadingIcon = document.querySelector('.boats-loading');
        var successIcon = document.querySelector('.boats-success');
        var errorIcon = document.querySelector('.boats-error');
        
        // Показать индикатор загрузки
        if (loadingIcon) {
            loadingIcon.style.display = 'inline-block';
        }
        if (successIcon) successIcon.style.display = 'none';
        if (errorIcon) errorIcon.style.display = 'none';
        
        // Собрать выбранные лодки
        var selectedBoats = [];
        var checkboxes = document.querySelectorAll('input[name="boats[]"]:checked');
        for (var i = 0; i < checkboxes.length; i++) {
            selectedBoats.push(checkboxes[i].value);
        }
        

        
        // Отправить AJAX запрос
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/lks/php/user/manage-boats.php', true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                
                // Скрыть индикатор загрузки
                if (loadingIcon) loadingIcon.style.display = 'none';
                
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        
                        if (response.success) {
                            if (successIcon) {
                                successIcon.style.display = 'inline-block';
                                setTimeout(function() {
                                    successIcon.style.display = 'none';
                                }, 3000);
                            }
                        } else {
                            console.error('🚤 Ошибка сохранения:', response.message);
                            if (errorIcon) errorIcon.style.display = 'inline-block';
                        }
                    } catch (e) {
                        console.error('🚤 Ошибка парсинга ответа:', e);
                        if (errorIcon) errorIcon.style.display = 'inline-block';
                    }
                } else {
                    console.error('🚤 HTTP ошибка:', xhr.status);
                    if (errorIcon) errorIcon.style.display = 'inline-block';
                }
            }
        };
        
        xhr.onerror = function() {
            console.error('🚤 Ошибка сети');
            if (loadingIcon) loadingIcon.style.display = 'none';
            if (errorIcon) errorIcon.style.display = 'inline-block';
        };
        
        // Отправить данные
        var data = JSON.stringify({ boats: selectedBoats });
        xhr.send(data);
    }
}

/**
 * Показ уведомлений пользователю
 */
function showNotification(message, type = 'info') {
    // Создаем контейнер для уведомлений если его нет
    let container = document.getElementById('notification-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'notification-container';
        container.style.position = 'fixed';
        container.style.top = '20px';
        container.style.right = '20px';
        container.style.zIndex = '9999';
        document.body.appendChild(container);
    }
    
    // Определяем класс Bootstrap в зависимости от типа
    let alertClass = 'alert-info';
    let icon = 'bi-info-circle';
    
    switch(type) {
        case 'success':
            alertClass = 'alert-success';
            icon = 'bi-check-circle';
            break;
        case 'error':
            alertClass = 'alert-danger';
            icon = 'bi-exclamation-triangle';
            break;
        case 'warning':
            alertClass = 'alert-warning';
            icon = 'bi-exclamation-triangle';
            break;
    }
    
    // Создаем уведомление
    const notification = document.createElement('div');
    notification.className = 'alert ' + alertClass + ' alert-dismissible fade show';
    notification.style.minWidth = '300px';
    notification.style.marginBottom = '10px';
    notification.innerHTML = '<i class="bi ' + icon + ' me-2"></i>' + message + 
                            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    
    container.appendChild(notification);
    
    // Автоматически скрываем через 4 секунды
    setTimeout(function() {
        if (notification.parentNode) {
            notification.classList.remove('show');
            setTimeout(function() {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 150);
        }
    }, 4000);
} 
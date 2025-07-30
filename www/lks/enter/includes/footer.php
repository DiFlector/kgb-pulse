</main>
<!-- Закрываем main-content -->

<!-- FOOTER -->
<footer id="footer">
    <div class="container-fluid px-3">
        <?php if (isset($_SESSION['user_role'])): ?>
            <?php if (in_array($_SESSION['user_role'], ['Admin', 'SuperUser'])): ?>
                <!-- Footer для администратора -->
                <div class="row text-center text-md-start">
                    <div class="col-md-4 mb-2 mb-md-0">
                        <div class="d-flex flex-column flex-md-row align-items-md-center">
                            <h6 class="text-muted fw-semibold me-md-2 mb-1 mb-md-0">Статистика системы:</h6>
                            <div class="small text-muted">
                                <span>Пользователей: <span id="users-count" class="fw-medium">-</span></span>
                                <span class="mx-1">|</span>
                                <span>Мероприятий: <span id="events-count" class="fw-medium">-</span></span>
                                <span class="mx-1">|</span>
                                <span>Регистраций: <span id="registrations-count" class="fw-medium">-</span></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-2 mb-md-0">
                        <div class="d-flex flex-column flex-md-row align-items-md-center">
                            <h6 class="text-muted fw-semibold me-md-2 mb-1 mb-md-0">Статус системы:</h6>
                            <div class="small text-muted">
                                <span>Сервисы: <span class="text-success fw-medium">● Работают</span></span>
                                <span class="mx-1">|</span>
                                <span>Диск: <span id="disk-usage" class="fw-medium">-</span>%</span>
                                <span class="mx-1">|</span>
                                <span>CPU: <span id="cpu-usage" class="fw-medium">-</span>%</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex flex-column flex-md-row align-items-md-center">
                            <h6 class="text-muted fw-semibold me-md-2 mb-1 mb-md-0">Информация:</h6>
                            <div class="small text-muted">
                                <span>Версия: <span class="fw-medium">1.0.0</span></span>
                                <span class="mx-1">|</span>
                                <span>Обновление: <span class="fw-medium"><?php echo date('d.m.Y'); ?></span></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php elseif ($_SESSION['user_role'] === 'Secretary'): ?>
                <!-- Footer для секретаря - пустой -->
            <?php else: ?>
                <!-- Footer для спортсмена и организатора -->
                <div class="text-center">
                    <div class="small text-muted lh-sm">
                        <div>© 2000-2025 Купавинская гребная база</div>
                        <div>Тел: +7 903 363-75-98 | Email: canoe-kupavna@yandex.ru</div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</footer>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery -->
<script src="/lks/js/libs/jquery/jquery-3.7.1.min.js"></script>

<!-- ВРЕМЕННЫЙ СБРОС SIDEBAR -->
<script>
// Сброс sidebar состояния
localStorage.removeItem('sidebarCollapsed');

document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const footer = document.querySelector('footer#footer');
    
    if (sidebar) {
        sidebar.classList.remove('collapsed');
    }
    
    if (mainContent) {
        mainContent.classList.remove('sidebar-collapsed');
    }
    
    if (footer) {
        footer.classList.remove('sidebar-collapsed');
    }
    
    // Глобальная функция для сброса
    window.resetSidebar = function() {
        localStorage.removeItem('sidebarCollapsed');
        if (sidebar) sidebar.classList.remove('collapsed');
        if (mainContent) mainContent.classList.remove('sidebar-collapsed');
        if (footer) footer.classList.remove('sidebar-collapsed');
    };
    
    // Функция для проверки состояния
    window.checkSidebar = function() {
        // Функция для отладки состояния sidebar
    };
});
</script>

<!-- Единый менеджер sidebar (без конфликтов) -->
<script src="/lks/js/sidebar-manager.js"></script>

<!-- Основной JavaScript (без sidebar функций) -->
<script>
// Только необходимые функции без sidebar управления
document.addEventListener('DOMContentLoaded', function() {
    // Инициализация Bootstrap компонентов
    initBootstrapComponents();
    
    // Инициализация уведомлений
    if (typeof updateNotifications === 'function') {
        updateNotifications();
        setInterval(updateNotifications, 30000);
    }
    
    // Инициализация модальных окон
    initModals();
    
    // Инициализация форм
    initForms();
    
    // Загрузка статистики системы для footer (только для админов)
    if (window.userRole === 'Admin' || window.userRole === 'SuperUser') {
        loadSystemStats();
        // Обновляем статистику каждые 30 секунд
        setInterval(loadSystemStats, 30000);
    }
});

// Функция загрузки статистики системы
async function loadSystemStats() {
    try {
        const response = await fetch('/lks/php/common/get_system_stats.php', {
            method: 'GET',
            credentials: 'same-origin'
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success && data.stats) {
            // Обновляем элементы статистики
            updateElement('users-count', data.stats.users_count || 0);
            updateElement('events-count', data.stats.events_count || 0);  
            updateElement('registrations-count', data.stats.registrations_count || 0);
            updateElement('disk-usage', data.stats.disk_usage || 0);
            updateElement('cpu-usage', data.stats.cpu_usage || 0);
        }
    } catch (error) {
        console.error('Ошибка загрузки статистики системы:', error);
    }
}

// Функция обновления элемента
function updateElement(id, value) {
    const element = document.getElementById(id);
    if (element) {
        element.textContent = value;
    }
}

// Инициализация Bootstrap компонентов
function initBootstrapComponents() {
    // Инициализация dropdown элементов
    const dropdownElementList = document.querySelectorAll('.dropdown-toggle');
    
    dropdownElementList.forEach(el => {
        // Удаляем старые обработчики Bootstrap
        const newEl = el.cloneNode(true);
        el.parentNode.replaceChild(newEl, el);
    });
    
    // Добавляем кастомные обработчики
    dropdownElementList.forEach(dropdownToggleEl => {
        dropdownToggleEl.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const menu = this.nextElementSibling;
            if (!menu || !menu.classList.contains('dropdown-menu')) {
                return;
            }
            
            const isVisible = menu.style.display === 'block';
            
            // Закрываем все другие dropdown
            document.querySelectorAll('.dropdown-menu').forEach(otherMenu => {
                if (otherMenu !== menu) {
                    otherMenu.style.display = 'none';
                }
            });
            
            // Переключаем текущий dropdown
            menu.style.display = isVisible ? 'none' : 'block';
        });
    });
    
    // Закрытие dropdown при клике вне
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown')) {
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                menu.style.display = 'none';
            });
        }
    });
    
    // Инициализация tooltip элементов
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltipTriggerList.forEach(tooltipTriggerEl => {
        new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

// Инициализация модальных окон
function initModals() {
    // Кастомная обработка модальных окон
    const modalTriggers = document.querySelectorAll('[data-bs-toggle="modal"]');
    modalTriggers.forEach(trigger => {
        trigger.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('data-bs-target');
            const modal = document.querySelector(targetId);
            if (modal) {
                const bsModal = new bootstrap.Modal(modal);
                bsModal.show();
            }
        });
    });
}

// Инициализация форм
function initForms() {
    // Обработка форм с валидацией
    const forms = document.querySelectorAll('form[data-validate="true"]');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!validateForm(this)) {
                e.preventDefault();
            }
        });
    });
}

// Валидация формы
function validateForm(form) {
    const requiredFields = form.querySelectorAll('[required]');
    let isValid = true;
    
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            field.classList.add('is-invalid');
            isValid = false;
        } else {
            field.classList.remove('is-invalid');
        }
    });
    
    return isValid;
}

// Функция обновления уведомлений
function updateNotifications() {
    // Загрузка уведомлений с сервера
    fetch('/lks/php/common/get_notifications.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateNotificationBadge(data.count);
                updateNotificationList(data.notifications);
            }
        })
        .catch(error => {
            console.error('Ошибка загрузки уведомлений:', error);
        });
}

// Обновление счетчика уведомлений
function updateNotificationBadge(count) {
    const badge = document.getElementById('notificationBadge');
    if (badge) {
        badge.textContent = count;
        badge.style.display = count > 0 ? 'inline' : 'none';
    }
}

// Обновление списка уведомлений
function updateNotificationList(notifications) {
    const list = document.getElementById('notificationsList');
    if (list) {
        list.innerHTML = '';
        
        if (notifications.length === 0) {
            list.innerHTML = '<li><span class="dropdown-item-text">Нет новых уведомлений</span></li>';
        } else {
            notifications.forEach(notification => {
                const item = document.createElement('li');
                item.innerHTML = `
                    <a class="dropdown-item ${notification.is_read ? '' : 'fw-bold'}" href="#">
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="mb-1">${notification.message}</div>
                                <small class="text-muted">${formatDate(notification.created_at)}</small>
                            </div>
                            ${!notification.is_read ? '<span class="badge bg-primary rounded-pill">●</span>' : ''}
                        </div>
                    </a>
                `;
                list.appendChild(item);
            });
        }
    }
}

// Форматирование даты
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
</script>

</body>
</html> 
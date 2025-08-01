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

<!-- Bootstrap 5 JS (с Popper.js) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery -->
<script src="/lks/js/libs/jquery/jquery-3.7.1.min.js"></script>

<!-- Единый менеджер sidebar (без конфликтов) -->
<script src="/lks/js/sidebar-manager.js"></script>

<!-- Основной JavaScript -->
<script src="/lks/js/main.js"></script>

<!-- Дополнительная инициализация для совместимости -->
<script>
// Проверяем инициализацию компонентов
document.addEventListener('DOMContentLoaded', function() {
    console.log('Footer: Проверка инициализации...');
    
    // Проверяем загрузку Bootstrap
    console.log('Bootstrap доступен:', typeof bootstrap !== 'undefined' ? 'Да' : 'Нет');
    if (typeof bootstrap !== 'undefined') {
        console.log('Bootstrap версия:', bootstrap.Dropdown ? 'Доступен' : 'Недоступен');
        console.log('Popper доступен:', typeof bootstrap.Popper !== 'undefined' ? 'Да' : 'Нет');
    }
    
    // Проверяем наличие кнопок
    const notificationsBtn = document.getElementById('notificationsDropdown');
    const userBtn = document.getElementById('userMenuDropdown');
    console.log('Кнопка уведомлений найдена:', !!notificationsBtn);
    console.log('Кнопка пользователя найдена:', !!userBtn);
    
    // Загрузка статистики системы для footer (только для админов)
    if (window.userRole === 'Admin' || window.userRole === 'SuperUser') {
        loadSystemStats();
    }
});

// Загрузка статистики системы
function loadSystemStats() {
    // Загружаем статистику для footer
    $.get('/lks/php/admin/get_stats.php', function(data) {
        if (data.success) {
            // Обновляем счетчики в footer
            updateElement('#footer-users-count', data.users.total || '-');
            updateElement('#footer-events-count', data.events.total || '-');
            updateElement('#footer-registrations-count', data.registrations.total || '-');
            updateElement('#footer-disk-usage', data.system.database_size || '-');
            updateElement('#footer-files-count', data.files.total_files || '-');
            updateElement('#footer-files-size', data.files.total_size || '-');
            
            // Обновляем статистику по ролям
            if (data.users.by_role) {
                updateElement('#footer-admin-count', data.users.by_role.Admin || 0);
                updateElement('#footer-organizer-count', data.users.by_role.Organizer || 0);
                updateElement('#footer-secretary-count', data.users.by_role.Secretary || 0);
                updateElement('#footer-sportsman-count', data.users.by_role.Sportsman || 0);
            }
            
            // Обновляем статистику регистраций
            if (data.registrations) {
                updateElement('#footer-paid-registrations', data.registrations.paid || 0);
                updateElement('#footer-total-registrations', data.registrations.total || 0);
            }
        }
    }).fail(function(xhr, status, error) {
        console.error('Не удалось загрузить статистику для footer:', error);
    });
}

// Обновление элемента
function updateElement(selector, value) {
    const element = document.querySelector(selector);
    if (element) {
        element.textContent = value;
    }
}

// Инициализация модальных окон
function initModals() {
    // Инициализация модальных окон Bootstrap
    const modalTriggers = document.querySelectorAll('[data-bs-toggle="modal"]');
    modalTriggers.forEach(trigger => {
        trigger.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('data-bs-target');
            const modal = document.querySelector(targetId);
            if (modal && typeof bootstrap !== 'undefined') {
                const bootstrapModal = new bootstrap.Modal(modal);
                bootstrapModal.show();
            }
        });
    });
}

// Инициализация форм
function initForms() {
    // Инициализация форм с AJAX
    const ajaxForms = document.querySelectorAll('form[data-ajax="true"]');
    ajaxForms.forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            // Здесь можно добавить обработку AJAX форм
            console.log('AJAX форма отправлена:', form);
        });
    });
}
</script> 
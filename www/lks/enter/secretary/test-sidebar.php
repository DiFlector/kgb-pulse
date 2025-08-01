<?php
require_once __DIR__ . '/../../php/common/Auth.php';
require_once __DIR__ . '/../../php/common/SessionManager.php';

// Проверяем авторизацию
$auth = new Auth();
if (!$auth->isAuthenticated()) {
    header('Location: /lks/login.php');
    exit;
}

// Проверяем права доступа (только секретарь)
if (!$auth->hasRole('Secretary') && !$auth->hasRole('SuperUser')) {
    header('Location: /lks/html/403.html');
    exit;
}

$sessionManager = new SessionManager();
$user = $sessionManager->getUser();

// Настройки страницы
$pageTitle = 'Тест Sidebar';
$pageHeader = 'Тестирование Sidebar';
$showBreadcrumb = true;
$breadcrumb = [
    ['href' => '/lks/enter/secretary/', 'title' => 'Секретарь'],
    ['href' => '#', 'title' => 'Тест Sidebar']
];

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Тестирование Sidebar</h5>
                </div>
                <div class="card-body">
                    <h6>Инструкции для тестирования:</h6>
                    <ol>
                        <li>Откройте консоль браузера (F12)</li>
                        <li>Попробуйте кликнуть на "Секретарь" в sidebar</li>
                        <li>Проверьте логи в консоли</li>
                        <li>Если sidebar свернут, используйте кнопку "Развернуть Sidebar"</li>
                    </ol>
                    
                    <div class="mt-4">
                        <h6>Отладочные функции (введите в консоли):</h6>
                        <ul>
                            <li><code>checkSidebar()</code> - проверить состояние sidebar</li>
                            <li><code>expandSidebar()</code> - развернуть sidebar</li>
                            <li><code>resetSubmenus()</code> - сбросить все подменю</li>
                            <li><code>forceToggleSidebar()</code> - принудительно переключить sidebar</li>
                        </ul>
                    </div>
                    
                    <div class="mt-4">
                        <button class="btn btn-primary" onclick="expandSidebar()">Развернуть Sidebar</button>
                        <button class="btn btn-secondary" onclick="resetSubmenus()">Сбросить Подменю</button>
                        <button class="btn btn-info" onclick="checkSidebar()">Проверить Состояние</button>
                    </div>
                    
                    <div class="mt-4">
                        <h6>Текущее состояние:</h6>
                        <div id="sidebarStatus">
                            <p>Нажмите "Проверить Состояние" для получения информации</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Функция для обновления статуса
function updateStatus() {
    const statusDiv = document.getElementById('sidebarStatus');
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    
    if (sidebar && mainContent) {
        const sidebarCollapsed = sidebar.classList.contains('collapsed');
        const sidebarShow = sidebar.classList.contains('show');
        const mainCollapsed = mainContent.classList.contains('sidebar-collapsed');
        
        statusDiv.innerHTML = `
            <p><strong>Sidebar:</strong> ${sidebarCollapsed ? 'Свернут' : 'Развернут'} ${sidebarShow ? '(Показан на мобильном)' : ''}</p>
            <p><strong>Main Content:</strong> ${mainCollapsed ? 'Свернут' : 'Развернут'}</p>
            <p><strong>Подменю:</strong></p>
            <ul>
                ${Array.from(sidebar.querySelectorAll('.submenu')).map(submenu => {
                    const trigger = sidebar.querySelector(`[href="#${submenu.id}"]`);
                    const isExpanded = trigger && trigger.getAttribute('aria-expanded') === 'true';
                    const display = submenu.style.display || 'не установлен';
                    return `<li>${submenu.id}: ${isExpanded ? 'Открыто' : 'Закрыто'} (display: ${display})</li>`;
                }).join('')}
            </ul>
        `;
    } else {
        statusDiv.innerHTML = '<p class="text-danger">Sidebar элементы не найдены</p>';
    }
}

// Обновляем статус каждые 2 секунды
setInterval(updateStatus, 2000);
updateStatus();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?> 
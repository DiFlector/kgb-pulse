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
$pageTitle = 'Тест Dropdown';
$pageHeader = 'Тестирование Bootstrap Dropdown';
$showBreadcrumb = true;
$breadcrumb = [
    ['href' => '/lks/enter/secretary/', 'title' => 'Секретарь'],
    ['href' => '#', 'title' => 'Тест Dropdown']
];

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Тестирование Bootstrap Dropdown</h5>
                </div>
                <div class="card-body">
                    <h6>Тест 1: Простой dropdown</h6>
                    <div class="dropdown mb-3">
                        <button class="btn btn-primary dropdown-toggle" type="button" id="testDropdown1" data-bs-toggle="dropdown" aria-expanded="false">
                            Тест Dropdown 1
                        </button>
                        <ul class="dropdown-menu" aria-labelledby="testDropdown1">
                            <li><a class="dropdown-item" href="#">Пункт 1</a></li>
                            <li><a class="dropdown-item" href="#">Пункт 2</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="#">Пункт 3</a></li>
                        </ul>
                    </div>
                    
                    <h6>Тест 2: Dropdown как в хедере</h6>
                    <div class="dropdown mb-3">
                        <button class="btn btn-outline-light dropdown-toggle" type="button" id="testDropdown2" data-bs-toggle="dropdown" aria-expanded="false" onclick="console.log('Клик по тестовому dropdown')">
                            <i class="bi bi-person-circle me-1"></i>
                            Тестовый пользователь
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="testDropdown2">
                            <li>
                                <a class="dropdown-item" href="#">
                                    <i class="bi bi-person me-2"></i>Личный кабинет
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="#">
                                    <i class="bi bi-box-arrow-right me-2"></i>Выйти
                                </a>
                            </li>
                        </ul>
                    </div>
                    
                    <h6>Тест 3: Проверка Bootstrap</h6>
                    <div class="mb-3">
                        <button class="btn btn-info" onclick="testBootstrap()">Проверить Bootstrap</button>
                        <button class="btn btn-warning" onclick="testDropdown()">Тест Dropdown</button>
                    </div>
                    
                    <div id="testResults">
                        <p>Нажмите кнопки для проверки</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function testBootstrap() {
    const results = document.getElementById('testResults');
    let html = '<h6>Результаты проверки Bootstrap:</h6><ul>';
    
    // Проверяем Bootstrap
    if (typeof bootstrap !== 'undefined') {
        html += '<li class="text-success">✓ Bootstrap загружен</li>';
        
        if (typeof bootstrap.Dropdown !== 'undefined') {
            html += '<li class="text-success">✓ Bootstrap Dropdown доступен</li>';
        } else {
            html += '<li class="text-danger">✗ Bootstrap Dropdown недоступен</li>';
        }
    } else {
        html += '<li class="text-danger">✗ Bootstrap не загружен</li>';
    }
    
    // Проверяем jQuery
    if (typeof $ !== 'undefined') {
        html += '<li class="text-success">✓ jQuery загружен</li>';
    } else {
        html += '<li class="text-warning">⚠ jQuery не загружен</li>';
    }
    
    html += '</ul>';
    results.innerHTML = html;
}

function testDropdown() {
    const results = document.getElementById('testResults');
    let html = '<h6>Результаты теста Dropdown:</h6><ul>';
    
    // Проверяем элементы dropdown
    const dropdowns = document.querySelectorAll('.dropdown');
    html += `<li>Найдено dropdown элементов: ${dropdowns.length}</li>`;
    
    dropdowns.forEach((dropdown, index) => {
        const toggle = dropdown.querySelector('.dropdown-toggle');
        const menu = dropdown.querySelector('.dropdown-menu');
        
        if (toggle && menu) {
            html += `<li class="text-success">✓ Dropdown ${index + 1}: toggle и menu найдены</li>`;
            
            // Проверяем data-bs-toggle
            if (toggle.getAttribute('data-bs-toggle') === 'dropdown') {
                html += `<li class="text-success">✓ Dropdown ${index + 1}: data-bs-toggle установлен</li>`;
            } else {
                html += `<li class="text-danger">✗ Dropdown ${index + 1}: data-bs-toggle не установлен</li>`;
            }
        } else {
            html += `<li class="text-danger">✗ Dropdown ${index + 1}: toggle или menu не найдены</li>`;
        }
    });
    
    html += '</ul>';
    results.innerHTML = html;
}

// Автоматический тест при загрузке
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(testBootstrap, 500);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?> 
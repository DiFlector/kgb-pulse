<?php
/**
 * Общий хедер для авторизованных пользователей
 */

session_start();

// Подключаем необходимые классы
require_once __DIR__ . '/../../php/common/Auth.php';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header('Location: /lks/login.php');
    exit;
}

$user_role = $_SESSION['user_role'] ?? 'Sportsman';
$user_name = $_SESSION['user_name'] ?? 'Пользователь';

// Переводы ролей
$roleTranslations = [
    'Admin' => 'Администратор',
    'Organizer' => 'Организатор', 
    'Secretary' => 'Секретарь',
    'Sportsman' => 'Спортсмен',
    'SuperUser' => 'Суперпользователь'
];
$roleTitle = $roleTranslations[$user_role] ?? $user_role;

// Проверяем суперпользователя
$auth = new Auth();
$isSuperUser = $auth->isSuperUser();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'KGB-Pulse') ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/lks/favicon.ico?v=2">
    <link rel="shortcut icon" type="image/x-icon" href="/lks/favicon.ico?v=2">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
            <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="/lks/css/style.css?v=1.7" rel="stylesheet">
    <!-- Protocols New CSS -->
    <link href="/lks/css/protocols-new.css" rel="stylesheet">
    <!-- Подключаем стили -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Chart.js для графиков (загружается только при необходимости) -->
    <!-- <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.js"></script> -->
    
    <!-- Modal backdrop fix (временно отключен для отладки) -->
    <!-- <script src="/lks/js/modal-fix.js"></script> -->

    <!-- jQuery (нужен для страниц, где inline-скрипты используют $ до футера) -->
    <script src="/lks/js/libs/jquery/jquery-3.7.1.min.js"></script>
    
    <?php if (isset($additionalCSS)): ?>
        <?php foreach ($additionalCSS as $css): ?>
            <link href="<?= htmlspecialchars($css) ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>
    
    <!-- SuperUser Menu Enhancement -->
    <?php if ($isSuperUser): ?>
        <!-- <script defer src="/lks/js/superuser-menu.js"></script> -->
        <!-- Теперь подменю обрабатываются в sidebar-manager.js -->
    <?php endif; ?>
    
    <!-- Глобальные переменные для JavaScript -->
    <script>
        window.userRole = <?= json_encode($user_role) ?>;
        window.userId = <?= json_encode($_SESSION['user_id']) ?>;
    </script>
</head>
<body class="authenticated-page" data-user-role="<?= htmlspecialchars($user_role) ?>">
    <!-- Header (фиксированный сверху) -->
    <nav class="navbar navbar-dark">
        <div class="container-fluid px-3">
            <!-- Левая часть: кнопка меню + логотип -->
            <div class="d-flex align-items-center">
                <button class="btn btn-outline-light me-3" type="button" id="sidebarToggle">
                    <i class="bi bi-list"></i>
                </button>
                <a class="navbar-brand d-flex align-items-center" href="/lks/">
                    <img src="/lks/images/logo_new.svg" alt="KGB-Pulse" height="30" class="me-2">
                    <div>
                        <div class="fw-bold text-white fs-6">KGB-Pulse</div>
                        <small class="text-light opacity-75"><?= htmlspecialchars($roleTitle) ?></small>
                    </div>
                </a>
            </div>

            <!-- Правая часть: уведомления + пользователь -->
            <div class="d-flex align-items-center">
                <!-- Уведомления -->
                <div class="dropdown me-3 notifications">
                    <button class="btn btn-outline-light position-relative" type="button" id="notificationsDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-bell"></i>
                        <span class="badge bg-danger" id="notificationBadge" style="display: none;"></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationsDropdown" style="min-width: 300px;">
                        <li><h6 class="dropdown-header">Уведомления</h6></li>
                        <li><hr class="dropdown-divider"></li>
                        <li id="notificationsList">
                            <a class="dropdown-item text-center text-muted" href="#">Нет новых уведомлений</a>
                        </li>
                    </ul>
                </div>

                <!-- Пользователь -->
                <div class="dropdown user-menu">
                    <button class="btn btn-outline-light" type="button" id="userMenuDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle me-1"></i>
                        <?= htmlspecialchars($user_name) ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userMenuDropdown">
                        <li>
                            <a class="dropdown-item" href="/lks/enter/common/profile.php">
                                <i class="bi bi-person me-2"></i>Личный кабинет
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="/lks/php/common/logout.php">
                                <i class="bi bi-box-arrow-right me-2"></i>Выйти
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <!-- Sidebar (левое меню) -->
    <div class="sidebar" id="sidebar">
        <ul class="sidebar-nav">
            <?php
            // Определяем меню в зависимости от роли
            $menu = [];
            
            // Проверяем суперпользователя
            $auth = new Auth();
            $isSuperUser = $auth->isSuperUser();
            
            // Отладочная информация
            error_log("DEBUG: user_role = " . $user_role . ", isSuperUser = " . ($isSuperUser ? 'true' : 'false'));
            
            if ($isSuperUser || $user_role === 'SuperUser') {
                error_log("DEBUG: Using SuperUser menu");
                // Многоуровневое меню для суперпользователя
                $menu = [
                    [
                        'title' => 'Админ',
                        'icon' => 'bi bi-shield-check',
                        'submenu' => [
                            ['href' => '/lks/enter/admin/', 'icon' => 'bi bi-speedometer2', 'title' => 'Панель управления'],
                            ['href' => '/lks/enter/admin/users.php', 'icon' => 'bi bi-people', 'title' => 'Пользователи'],
                            ['href' => '/lks/enter/admin/registrations.php', 'icon' => 'bi bi-clipboard-check', 'title' => 'Регистрации'],
                            ['href' => '/lks/enter/admin/files.php', 'icon' => 'bi bi-folder', 'title' => 'Файлы'],
                            ['href' => '/lks/enter/admin/data.php', 'icon' => 'bi bi-database', 'title' => 'Работа с данными'],
                            ['href' => '/lks/enter/admin/boats.php', 'icon' => 'fa-solid fa-ship', 'title' => 'Классы лодок'],
                            ['href' => '/lks/enter/admin/queue.php', 'icon' => 'bi bi-clock', 'title' => 'Очередь спортсменов'],
                            ['href' => '/lks/enter/admin/events.php', 'icon' => 'bi bi-calendar-event', 'title' => 'Все мероприятия'],
                        ]
                    ],
                    [
                        'title' => 'Организатор',
                        'icon' => 'bi bi-calendar-plus',
                        'submenu' => [
                            ['href' => '/lks/enter/organizer/', 'icon' => 'bi bi-house', 'title' => 'Главная'],
                            ['href' => '/lks/enter/organizer/events.php', 'icon' => 'bi bi-calendar-event', 'title' => 'Мероприятия'],
                            ['href' => '/lks/enter/organizer/registrations.php', 'icon' => 'bi bi-clipboard-check', 'title' => 'Регистрации'],
                            ['href' => '/lks/enter/organizer/queue.php', 'icon' => 'bi bi-clock', 'title' => 'Очередь (орг)'],
                            ['href' => '/lks/enter/organizer/calendar.php', 'icon' => 'bi bi-calendar3', 'title' => 'Календарь'],
                            ['href' => '/lks/enter/organizer/create-event.php', 'icon' => 'bi bi-plus-circle', 'title' => 'Создать мероприятие'],
                        ]
                    ],
                    [
                        'title' => 'Секретарь',
                        'icon' => 'bi bi-clipboard-data',
                        'submenu' => [
                            ['href' => '/lks/enter/secretary/', 'icon' => 'bi bi-house', 'title' => 'Главная'],
                            ['href' => '/lks/enter/secretary/events.php', 'icon' => 'bi bi-calendar-event', 'title' => 'Мероприятия'],
                            ['href' => '/lks/enter/secretary/main.php', 'icon' => 'bi bi-award', 'title' => 'Проведение мероприятия'],
                            ['href' => '/lks/enter/secretary/results.php', 'icon' => 'bi bi-trophy', 'title' => 'Результаты'],
                            ['href' => '/lks/enter/secretary/queue.php', 'icon' => 'bi bi-clock', 'title' => 'Очередь спортсменов'],
                        ]
                    ],
                    [
                        'title' => 'Спортсмен',
                        'icon' => 'bi bi-person-badge',
                        'submenu' => [
                            ['href' => '/lks/enter/user/', 'icon' => 'bi bi-house', 'title' => 'Главная'],
                            ['href' => '/lks/enter/user/calendar.php', 'icon' => 'bi bi-calendar3', 'title' => 'Календарь'],
                            ['href' => '/lks/enter/common/profile.php', 'icon' => 'bi bi-person-circle', 'title' => 'Профиль'],
                            ['href' => '/lks/enter/user/statistics.php', 'icon' => 'bi bi-trophy', 'title' => 'Моя статистика'],
                        ]
                    ]
                ];
            } else {
                error_log("DEBUG: Using regular menu for user_role: " . $user_role);
                switch ($user_role) {
                    case 'Admin':
                    $menu = [
                        ['href' => '/lks/enter/admin/', 'icon' => 'bi bi-speedometer2', 'title' => 'Панель управления'],
                        ['href' => '/lks/enter/admin/users.php', 'icon' => 'bi bi-people', 'title' => 'Пользователи'],
                        ['href' => '/lks/enter/admin/events.php', 'icon' => 'bi bi-calendar-event', 'title' => 'Мероприятия'],
                        ['href' => '/lks/enter/admin/registrations.php', 'icon' => 'bi bi-clipboard-check', 'title' => 'Регистрации'],
                        ['href' => '/lks/enter/admin/queue.php', 'icon' => 'bi bi-clock', 'title' => 'Очередь спортсменов'],
                        ['href' => '/lks/enter/admin/files.php', 'icon' => 'bi bi-folder', 'title' => 'Файлы'],
                        ['href' => '/lks/enter/admin/data.php', 'icon' => 'bi bi-database', 'title' => 'Работа с данными'],
                        ['href' => '/lks/enter/admin/boats.php', 'icon' => 'fa-solid fa-ship', 'title' => 'Классы лодок'],
                    ];
                    break;
                    
                case 'Organizer':
                    $menu = [
                        ['href' => '/lks/enter/organizer/', 'icon' => 'bi bi-house', 'title' => 'Главная'],
                        ['href' => '/lks/enter/organizer/events.php', 'icon' => 'bi bi-calendar-event', 'title' => 'Мероприятия'],
                        ['href' => '/lks/enter/organizer/create-event.php', 'icon' => 'bi bi-plus-circle', 'title' => 'Создать мероприятие'],
                        ['href' => '/lks/enter/organizer/queue.php', 'icon' => 'bi bi-clock', 'title' => 'Очередь спортсменов'],
                        ['href' => '/lks/enter/organizer/registrations.php', 'icon' => 'bi bi-clipboard-check', 'title' => 'Регистрации'],
                        ['href' => '/lks/enter/organizer/calendar.php', 'icon' => 'bi bi-calendar3', 'title' => 'Календарь'],
                        ['href' => '/lks/enter/organizer/statistics.php', 'icon' => 'bi bi-graph-up', 'title' => 'Статистика'],
                    ];
                    break;
                    
                case 'Secretary':
                    $menu = [
                        ['href' => '/lks/enter/secretary/', 'icon' => 'bi bi-house', 'title' => 'Главная'],
                        ['href' => '/lks/enter/secretary/events.php', 'icon' => 'bi bi-calendar-event', 'title' => 'Мероприятия'],
                        ['href' => '/lks/enter/secretary/main.php', 'icon' => 'bi bi-award', 'title' => 'Проведение мероприятия'],
                        ['href' => '/lks/enter/secretary/results.php', 'icon' => 'bi bi-trophy', 'title' => 'Результаты'],
                        ['href' => '/lks/enter/secretary/queue.php', 'icon' => 'bi bi-clock', 'title' => 'Очередь спортсменов'],
                    ];
                    break;
                    
                case 'SuperUser':
                    // SuperUser использует многоуровневое меню, определенное выше
                    // Этот case добавлен для полноты, но не используется
                    error_log("DEBUG: SuperUser case reached in switch - this should not happen");
                    // Не устанавливаем меню здесь, так как SuperUser использует многоуровневое меню
                    break;
                    
                default: // Sportsman
                    error_log("DEBUG: Using default menu (Sportsman) for user_role: " . $user_role);
                    $menu = [
                        ['href' => '/lks/enter/user/', 'icon' => 'bi bi-house', 'title' => 'Главная'],
                        ['href' => '/lks/enter/user/calendar.php', 'icon' => 'bi bi-calendar3', 'title' => 'Календарь'],
                        ['href' => '/lks/enter/common/profile.php', 'icon' => 'bi bi-person-circle', 'title' => 'Профиль'],
                    ];
                    break;
                }
            }
            
            // Текущая страница для активного состояния
            $currentPath = $_SERVER['REQUEST_URI'];
            
            foreach ($menu as $item):
                // Проверяем, есть ли подменю
                if (isset($item['submenu'])):
                    // Проверяем активность любого подпункта
                    $hasActiveSubmenu = false;
                    foreach ($item['submenu'] as $subitem) {
                        // Точное сравнение пути
                        if ($currentPath === $subitem['href']) {
                            $hasActiveSubmenu = true;
                            break;
                        }
                    }
            ?>
                <li class="nav-item">
                    <a class="nav-link <?= $hasActiveSubmenu ? 'active' : '' ?>" 
                       href="#submenu-<?= md5($item['title']) ?>"
                       data-title="<?= htmlspecialchars($item['title']) ?>"
                       aria-expanded="<?= $hasActiveSubmenu ? 'true' : 'false' ?>">
                        <i class="<?= htmlspecialchars($item['icon']) ?>"></i>
                        <span><?= htmlspecialchars($item['title']) ?></span>
                        <i class="bi bi-chevron-right ms-auto submenu-arrow"></i>
                    </a>
                    <ul class="submenu" id="submenu-<?= md5($item['title']) ?>" style="display: <?= $hasActiveSubmenu ? 'block' : 'none' ?>;">
                        <?php foreach ($item['submenu'] as $subitem): 
                            // Точное сравнение пути для подпунктов
                            $isActive = ($currentPath === $subitem['href']);
                        ?>
                            <li>
                                <a class="nav-link <?= $isActive ? 'active' : '' ?>" 
                                   href="<?= htmlspecialchars($subitem['href']) ?>">
                                    <i class="<?= htmlspecialchars($subitem['icon']) ?>"></i>
                                    <span><?= htmlspecialchars($subitem['title']) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </li>
            <?php else: 
                // Более точная проверка активности для обычных пунктов
                $isActive = ($currentPath === $item['href'] || 
                           ($item['href'] !== '/' && $item['href'] !== '/lks/enter/admin/' && 
                            strpos($currentPath, $item['href']) === 0 && 
                            (strlen($currentPath) === strlen($item['href']) || 
                             $currentPath[strlen($item['href'])] === '?' || 
                             $currentPath[strlen($item['href'])] === '#')));
            ?>
                <li class="nav-item">
                    <a class="nav-link <?= $isActive ? 'active' : '' ?>" 
                       href="<?= htmlspecialchars($item['href']) ?>"
                       data-title="<?= htmlspecialchars($item['title']) ?>">
                        <i class="<?= htmlspecialchars($item['icon']) ?>"></i>
                        <span><?= htmlspecialchars($item['title']) ?></span>
                    </a>
                </li>
            <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- Overlay для мобильного меню -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Main Content (основной контент) -->
    <main class="main-content" id="mainContent">
        <?php if (isset($showBreadcrumb) && $showBreadcrumb): ?>
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <?php if (isset($breadcrumb) && is_array($breadcrumb)): ?>
                        <?php foreach ($breadcrumb as $index => $item): ?>
                            <?php if ($index === count($breadcrumb) - 1): ?>
                                <li class="breadcrumb-item active" aria-current="page">
                                    <?= htmlspecialchars($item['title']) ?>
                                </li>
                            <?php else: ?>
                                <li class="breadcrumb-item">
                                    <a href="<?= htmlspecialchars($item['href']) ?>">
                                        <?= htmlspecialchars($item['title']) ?>
                                    </a>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ol>
            </nav>
        <?php endif; ?>

        <?php if (isset($pageHeader)): ?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0">
                    <?php if (isset($pageIcon)): ?>
                        <i class="<?= htmlspecialchars($pageIcon) ?> me-2"></i>
                    <?php endif; ?>
                    <?= htmlspecialchars($pageHeader) ?>
                </h1>
                <?php if (isset($pageActions)): ?>
                    <div class="d-flex gap-2">
                        <?= $pageActions ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Здесь начинается контент страницы -->

        <!-- Подключаем скрипты -->
        <script src="/lks/js/registration.js"></script>
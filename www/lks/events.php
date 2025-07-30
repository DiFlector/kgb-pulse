<?php
/**
 * Страница просмотра мероприятий
 * Отображается для всех пользователей (авторизованных и неавторизованных)
 */

// Включаем отображение ошибок для отладки
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'php/helpers.php';
require_once 'php/common/Auth.php';
require_once 'php/db/Database.php';

$auth = new Auth();
$db = Database::getInstance();

// Проверяем авторизацию, но не перенаправляем
$isAuthenticated = $auth->isAuthenticated();
$userRole = $isAuthenticated ? $auth->getUserRole() : null;
$userId = $isAuthenticated ? $auth->getUserId() : null;

$pageTitle = "Мероприятия - KGB-Pulse";
$currentPage = 'events';
$currentYear = date('Y');

// Получаем мероприятия
try {
    $stmt = $db->prepare("
        SELECT 
            champn,
            merodata,
            meroname,
            class_distance,
            defcost,
            filepolojenie,
            status::text as status
        FROM meros 
        WHERE status::text IN ('Регистрация', 'Регистрация закрыта', 'Завершено', 'В ожидании')
        ORDER BY merodata ASC
    ");
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $events = [];
    error_log("Ошибка при получении мероприятий: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Календарь мероприятий по гребле - соревнования, регаты, тренировки">
    <meta name="keywords" content="гребля, соревнования, каноэ, байдарки, драконы, календарь, мероприятия">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/lks/favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="/lks/favicon.ico">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="/lks/css/style.css" rel="stylesheet">
    <!-- CSS Fix для неавторизованных страниц -->
    <link href="/lks/css/style-fix.css" rel="stylesheet">
    
    <style>
        /* Специальные стили для страницы мероприятий */
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* Основной контент */
        main {
            flex: 1;
            margin-top: 75px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
        }
        
        /* Header секция */
        .events-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 2rem 0;
            margin-bottom: 0;
        }
        
        /* Уведомление */
        .info-notice {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border: none;
            border-radius: 0;
            margin-bottom: 0;
        }
        
        /* Секция мероприятий */
        .events-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 3rem 0;
        }
        
        /* Карточки мероприятий */
        .event-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: none !important;
            overflow: hidden;
        }
        
        .event-card:hover {
            transform: none !important;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1) !important;
        }
        
        .event-card .card-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 1.5rem;
        }
        
        .event-card .card-body {
            padding: 1.5rem;
        }
        
        .event-card .card-footer {
            background: rgba(248, 249, 250, 0.9);
            border: none;
            padding: 1rem 1.5rem;
        }
        
        /* Кнопки */
        .btn {
            transition: none !important;
        }
        
        .btn:hover {
            transform: none !important;
        }
        
        /* Подвал */
        footer {
            background-color: rgba(52, 58, 64, 0.95) !important;
            color: white !important;
            padding: 2rem 0 !important;
            margin-top: auto;
        }
        
        footer a {
            color: rgba(255, 255, 255, 0.8) !important;
            text-decoration: none;
        }
        
        footer a:hover {
            color: white !important;
        }
        
        /* Адаптивность */
        @media (max-width: 576px) {
            .events-header {
                padding: 1.5rem 0;
            }
            
            .events-section {
                padding: 2rem 0;
            }
            
            .event-card .card-header,
            .event-card .card-body,
            .event-card .card-footer {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Навигация -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="/lks/">
                <img src="/lks/images/logo_new.svg" alt="KGB-Pulse" height="40" class="me-2">
                <span class="fw-bold">KGB-Pulse</span>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="/lks/">
                            <i class="fas fa-home me-1"></i>Главная
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentPage === 'events' ? 'active' : '' ?>" href="/lks/events.php">
                            <i class="fas fa-calendar me-1"></i>Мероприятия
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/lks/register.php">
                            <i class="fas fa-user-plus me-1"></i>Регистрация
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/lks/login.php">
                            <i class="fas fa-sign-in-alt me-1"></i>Вход
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Основной контент -->
    <main>
        <!-- Header секция -->
        <section class="events-header">
            <div class="container">
                <div class="row align-items-center">
                    <div class="col">
                        <h1 class="h2 mb-2">
                            <i class="fas fa-calendar-alt me-2"></i>
                            Календарь мероприятий <?= $currentYear ?>
                        </h1>
                        <p class="mb-0 opacity-75">
                            Актуальная информация о предстоящих и проходящих соревнованиях по гребле
                        </p>
                    </div>
                    <div class="col-auto">
                        <a href="/lks/" class="btn btn-outline-light">
                            <i class="fas fa-arrow-left me-1"></i>На главную
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- Уведомление для неавторизованных -->
        <section class="py-3">
            <div class="container">
                <div class="alert alert-info info-notice mb-0 d-flex align-items-center">
                    <i class="fas fa-info-circle me-2"></i>
                    <div>
                        <strong>Информация:</strong> Для регистрации на мероприятия необходимо 
                        <a href="/lks/login.php" class="alert-link">войти в систему</a> или 
                        <a href="/lks/register.php" class="alert-link">зарегистрироваться</a>.
                    </div>
                </div>
            </div>
        </section>

        <!-- Список мероприятий -->
        <section class="events-section">
            <div class="container">
                <?php if (empty($events)): ?>
                    <div class="text-center py-5">
                        <div class="bg-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px;">
                            <i class="fas fa-calendar-times fa-2x text-muted"></i>
                        </div>
                        <h3 class="text-muted">Мероприятий не найдено</h3>
                        <p class="text-muted">В <?= $currentYear ?> году пока не запланировано мероприятий.</p>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($events as $event): ?>
                            <?php 
                                $statusInfo = formatStatus($event['status']);
                                $boatClasses = parseBoatClasses($event['class_distance']);
                                $eventDateInfo = formatEventDate($event['merodata']);
                            ?>
                            <div class="col-lg-6 col-xl-4 mb-4">
                                <div class="card event-card h-100">
                                    <div class="card-header">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div class="flex-grow-1">
                                                <h5 class="card-title mb-1">
                                                    <?= htmlspecialchars($event['meroname']) ?>
                                                </h5>
                                                <p class="mb-2 opacity-75">
                                                    <i class="fas fa-calendar-day me-1"></i>
                                                    <?= htmlspecialchars($eventDateInfo['date']) ?>
                                                    <?php if (!empty($eventDateInfo['year'])): ?>
                                                        <span class="opacity-75"><?= htmlspecialchars($eventDateInfo['year']) ?></span>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                            <span class="badge bg-light text-dark ms-2">
                                                <?= htmlspecialchars($statusInfo['label']) ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="card-body">
                                        <!-- Классы лодок -->
                                        <?php if (!empty($boatClasses) && is_array($boatClasses)): ?>
                                            <div class="mb-3">
                                                <h6 class="small text-muted mb-2">
                                                    <i class="fas fa-ship me-1"></i>Классы лодок:
                                                </h6>
                                                <div class="d-flex flex-wrap gap-1">
                                                    <?php foreach ($boatClasses as $boatClass): ?>
                                                        <span class="badge bg-secondary small">
                                                            <?= htmlspecialchars($boatClass) ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Стоимость -->
                                        <?php if (!empty($event['defcost'])): ?>
                                            <div class="mb-3">
                                                <h6 class="small text-muted mb-1">
                                                    <i class="fas fa-ruble-sign me-1"></i>Стоимость участия:
                                                </h6>
                                                <span class="fw-bold text-success">
                                                    <?= htmlspecialchars($event['defcost']) ?> ₽
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="card-footer">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <!-- Документы -->
                                            <?php if (!empty($event['filepolojenie'])): ?>
                                                <a href="<?= htmlspecialchars($event['filepolojenie']) ?>" 
                                                   class="btn btn-outline-primary btn-sm" 
                                                   target="_blank">
                                                    <i class="fas fa-file-pdf me-1"></i>Положение
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small">Документы готовятся</span>
                                            <?php endif; ?>

                                            <!-- Информация о регистрации -->
                                            <div class="text-end">
                                                <?php if ($event['status'] === 'Регистрация'): ?>
                                                    <small class="text-success d-block">
                                                        <i class="fas fa-unlock me-1"></i>Регистрация открыта
                                                    </small>
                                                <?php elseif ($event['status'] === 'Регистрация закрыта'): ?>
                                                    <small class="text-warning d-block">
                                                        <i class="fas fa-lock me-1"></i>Регистрация закрыта
                                                    </small>
                                                <?php elseif ($event['status'] === 'Завершено'): ?>
                                                    <small class="text-dark d-block">
                                                        <i class="fas fa-flag-checkered me-1"></i>Завершено
                                                    </small>
                                                <?php else: ?>
                                                    <small class="text-muted d-block">
                                                        <?= htmlspecialchars($statusInfo['label']) ?>
                                                    </small>
                                                <?php endif; ?>
                                                
                                                <small class="text-muted">
                                                    <a href="/lks/login.php" class="text-decoration-none">
                                                        Войти для регистрации
                                                    </a>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Дополнительная информация -->
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="card event-card">
                                <div class="card-body">
                                    <h5 class="card-title">
                                        <i class="fas fa-question-circle me-2 text-primary"></i>
                                        Как принять участие?
                                    </h5>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <ol class="mb-0">
                                                <li><a href="/lks/register.php">Зарегистрируйтесь</a> в системе</li>
                                                <li>Заполните профиль участника</li>
                                                <li>Выберите интересующие мероприятия</li>
                                            </ol>
                                        </div>
                                        <div class="col-md-6">
                                            <ol start="4" class="mb-0">
                                                <li>Зарегистрируйтесь на выбранные дисциплины</li>
                                                <li>Произведите оплату участия</li>
                                                <li>Получите подтверждение регистрации</li>
                                            </ol>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <!-- Подвал -->
    <footer>
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5>© 2000-2025 Купавинская гребная база</h5>
                    <p class="mb-2">
                        К настоящему моменту численность секции достигает 20 человек и продолжает расти. 
                        Материально техническая база секции на август 2014г. составляла: 4 каноэ, 7 байдарок, 
                        15 весел (5 профессиональных), 5 спасательных жилетов, 1 гидрокостюм.
                    </p>
                </div>
                <div class="col-md-6">
                    <h5>Контактная информация</h5>
                    <p class="mb-1">
                        <i class="fas fa-phone me-2"></i>
                        <a href="tel:+79033637598">+7 903 363-75-98</a>
                    </p>
                    <p class="mb-1">
                        <i class="fas fa-envelope me-2"></i>
                        <a href="mailto:canoe-kupavna@yandex.ru">canoe-kupavna@yandex.ru</a>
                    </p>
                    <p class="mb-0 small text-white">Email для связи с мандатной комиссией</p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 
<?php
/**
 * Главная страница сайта
 * Отображается для неавторизованных пользователей
 */

require_once 'php/helpers.php';
require_once 'php/common/Auth.php';

$auth = new Auth();

// Если пользователь авторизован, перенаправляем в личный кабинет
if ($auth->isAuthenticated()) {
    $role = $auth->getUserRole();
    switch ($role) {
        case 'Admin':
            header('Location: /lks/enter/admin/');
            break;
        case 'Organizer':
            header('Location: /lks/enter/organizer/');
            break;
        case 'Secretary':
            header('Location: /lks/enter/secretary/');
            break;
        default:
            header('Location: /lks/enter/user/');
    }
    exit;
}

$pageTitle = "KGB-Pulse - Система управления гребной базой";
$currentPage = 'home';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Система управления соревнованиями по гребле - регистрация, проведение соревнований, результаты">
    <meta name="keywords" content="гребля, соревнования, каноэ, байдарки, драконы, регистрация">
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
        /* Специальные стили для главной страницы */
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
        }
        
        /* Hero секция */
        .hero {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 4rem 0;
            position: relative;
            overflow: hidden;
        }
        
        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(5px);
        }
        
        .hero .container {
            position: relative;
            z-index: 2;
        }
        
        /* Возможности системы */
        .features-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 4rem 0;
        }
        
        .feature-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: none !important;
            overflow: hidden;
        }
        
        .feature-card:hover {
            transform: none !important;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1) !important;
        }
        
        .feature-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
        }
        
        /* Статистика */
        .stats-section {
            background: rgba(248, 249, 250, 0.95);
            backdrop-filter: blur(10px);
            padding: 3rem 0;
        }
        
        .stat-item {
            text-align: center;
            padding: 1rem;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 900;
            margin-bottom: 0.5rem;
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
            .hero {
                padding: 2rem 0;
            }
            
            .features-section,
            .stats-section {
                padding: 2rem 0;
            }
            
            .stat-number {
                font-size: 2rem;
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
                        <a class="nav-link <?= $currentPage === 'home' ? 'active' : '' ?>" href="/lks/">
                            <i class="fas fa-home me-1"></i>Главная
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
        <!-- Hero секция -->
        <section class="hero">
            <div class="container">
                <div class="row align-items-center">
                    <div class="col-lg-6">
                        <h1 class="display-4 fw-bold mb-4">
                            Добро пожаловать в KGB-Pulse
                        </h1>
                        
                        <p class="lead mb-4">
                            Современная система управления соревнованиями по гребле. 
                            Регистрируйтесь на мероприятия, следите за результатами, 
                            управляйте соревнованиями.
                        </p>
                        <div class="d-flex gap-3">
                            <a href="/lks/register.php" class="btn btn-light btn-lg">
                                <i class="fas fa-user-plus me-2"></i>Начать регистрацию
                            </a>
                            <a href="/lks/events.php" class="btn btn-outline-light btn-lg">
                                <i class="fas fa-calendar me-2"></i>Посмотреть события
                            </a>
                        </div>
                    </div>
                    <div class="col-lg-6 text-center">
                        <img src="/lks/images/logo_new.svg" alt="Гребля" class="img-fluid rounded shadow">
                    </div>
                </div>
            </div>
        </section>

        <!-- Возможности системы -->
        <section class="features-section">
            <div class="container">
                <div class="row">
                    <div class="col-12 text-center mb-5">
                        <h2 class="display-5 fw-bold">Возможности системы</h2>
                        <p class="lead text-muted">Все необходимое для проведения соревнований по гребле</p>
                    </div>
                </div>
                
                <div class="row g-4">
                    <div class="col-md-6 col-lg-3">
                        <div class="card feature-card h-100 text-center">
                            <div class="card-body">
                                <div class="feature-icon bg-primary text-white">
                                    <i class="fas fa-users fa-lg"></i>
                                </div>
                                <h5 class="card-title">Регистрация участников</h5>
                                <p class="card-text text-muted">
                                    Простая и удобная регистрация на мероприятия с выбором дисциплин
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 col-lg-3">
                        <div class="card feature-card h-100 text-center">
                            <div class="card-body">
                                <div class="feature-icon bg-success text-white">
                                    <i class="fas fa-calendar-alt fa-lg"></i>
                                </div>
                                <h5 class="card-title">Управление событиями</h5>
                                <p class="card-text text-muted">
                                    Создание и управление соревнованиями, календарь мероприятий
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 col-lg-3">
                        <div class="card feature-card h-100 text-center">
                            <div class="card-body">
                                <div class="feature-icon bg-warning text-white">
                                    <i class="fas fa-stopwatch fa-lg"></i>
                                </div>
                                <h5 class="card-title">Проведение соревнований</h5>
                                <p class="card-text text-muted">
                                    Жеребьевка, стартовые и финишные протоколы, фиксация результатов
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 col-lg-3">
                        <div class="card feature-card h-100 text-center">
                            <div class="card-body">
                                <div class="feature-icon bg-info text-white">
                                    <i class="fas fa-trophy fa-lg"></i>
                                </div>
                                <h5 class="card-title">Результаты</h5>
                                <p class="card-text text-muted">
                                    Автоматическое формирование результатов и призовых мест
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Статистика -->
        <section class="stats-section">
            <div class="container">
                <div class="row">
                    <div class="col-md-3 mb-4">
                        <div class="stat-item">
                            <div class="stat-number text-primary">500+</div>
                            <p class="text-muted mb-0">Участников</p>
                        </div>
                    </div>
                    <div class="col-md-3 mb-4">
                        <div class="stat-item">
                            <div class="stat-number text-success">50+</div>
                            <p class="text-muted mb-0">Соревнований</p>
                        </div>
                    </div>
                    <div class="col-md-3 mb-4">
                        <div class="stat-item">
                            <div class="stat-number text-warning">15</div>
                            <p class="text-muted mb-0">Дисциплин</p>
                        </div>
                    </div>
                    <div class="col-md-3 mb-4">
                        <div class="stat-item">
                            <div class="stat-number text-info">24/7</div>
                            <p class="text-muted mb-0">Поддержка</p>
                        </div>
                    </div>
                </div>
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
<?php
/**
 * Страница авторизации
 */

require_once 'php/helpers.php';
require_once 'php/common/Auth.php';

$auth = new Auth();
$error = '';
$success = '';

// Если пользователь уже авторизован, перенаправляем
if ($auth->isAuthenticated()) {
    $role = $auth->getUserRole();
    switch ($role) {
        case 'SuperUser':
            header('Location: /lks/enter/admin/'); // SuperUser идет в админ панель
            break;
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

// Обработка формы входа
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if (empty($email) || empty($password)) {
        $error = 'Заполните все поля';
    } elseif (!isValidEmail($email)) {
        $error = 'Неверный формат email';
    } else {
        $result = $auth->login($email, $password);
        
        if ($result['success']) {
            // Успешная авторизация - перенаправляем в зависимости от роли
            $role = $auth->getUserRole();
            switch ($role) {
                case 'SuperUser':
                    header('Location: /lks/enter/admin/'); // SuperUser идет в админ панель
                    break;
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
        } else {
            $error = $result['message'];
        }
    }
}

$pageTitle = "Вход в систему - KGB-Pulse";
$currentPage = 'login';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        /* Специальные стили для страницы входа */
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* Фиксированная навигация */
        .navbar {
            background-color: rgba(52, 58, 64, 0.95) !important;
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            height: 75px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
        }
        
        /* Основной контент */
        main {
            flex: 1;
            margin-top: 75px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: calc(100vh - 75px);
            padding: 2rem 1rem;
        }
        
        /* Центрированная форма входа */
        .login-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            max-width: 450px;
            width: 100%;
            margin: 0 auto;
            overflow: hidden;
        }
        
        .login-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 1.5rem;
            text-align: center;
        }
        
        .login-body {
            padding: 2rem;
        }
        
        /* Убираем hover-эффекты */
        .login-container:hover {
            transform: none !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1) !important;
        }
        
        .card:hover {
            transform: none !important;
            box-shadow: none !important;
        }
        
        /* Навигация без hover-эффектов */
        .navbar-nav .nav-link {
            transition: none !important;
        }
        
        .navbar-nav .nav-link:hover {
            background-color: transparent !important;
            transform: none !important;
        }
        
        /* Мобильное меню */
        @media (max-width: 991.98px) {
            .navbar-collapse {
                background: rgba(52, 58, 64, 0.98);
                backdrop-filter: blur(10px);
                border-radius: 10px;
                margin-top: 1rem;
                padding: 1rem;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                z-index: 1000;
            }
            
            .navbar-nav {
                text-align: center;
            }
            
            .navbar-nav .nav-link {
                padding: 0.75rem 1rem;
                border-radius: 8px;
                margin: 0.25rem 0;
            }
            
            .navbar-nav .nav-link:hover {
                background-color: rgba(255, 255, 255, 0.1) !important;
            }
        }
        
        /* Адаптивность */
        @media (max-width: 576px) {
            .login-container {
                margin: 1rem;
            }
            
            .login-body {
                padding: 1.5rem;
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
                        <a class="nav-link" href="/lks/register.php">
                            <i class="fas fa-user-plus me-1"></i>Регистрация
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Основной контент -->
    <main>
        <div class="login-container">
            <div class="login-header">
                <h4 class="mb-0">
                    <i class="fas fa-sign-in-alt me-2"></i>Вход в систему
                </h4>
            </div>
            <div class="login-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?= htmlspecialchars($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="/lks/login.php" id="loginForm">
                    <div class="mb-3">
                        <label for="email" class="form-label">
                            <i class="fas fa-envelope me-1"></i>Email
                        </label>
                        <input type="email" 
                               class="form-control" 
                               id="email" 
                               name="email" 
                               value="<?= htmlspecialchars($email ?? '') ?>"
                               required 
                               autofocus>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">
                            <i class="fas fa-lock me-1"></i>Пароль
                        </label>
                        <div class="input-group">
                            <input type="password" 
                                   class="form-control" 
                                   id="password" 
                                   name="password" 
                                   required>
                            <button class="btn btn-outline-secondary" 
                                    type="button" 
                                    id="togglePassword"
                                    title="Показать/скрыть пароль">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="remember" name="remember">
                        <label class="form-check-label" for="remember">
                            Запомнить меня
                        </label>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-sign-in-alt me-2"></i>Войти
                        </button>
                    </div>
                </form>

                <hr class="my-4">

                <div class="text-center">
                    <p class="mb-2">Нет аккаунта?</p>
                    <a href="/lks/register.php" class="btn btn-outline-primary">
                        <i class="fas fa-user-plus me-2"></i>Зарегистрироваться
                    </a>
                </div>

                <div class="text-center mt-3">
                    <a href="/lks/forgot-password.php" class="text-muted text-decoration-none">
                        <i class="fas fa-question-circle me-1"></i>Забыли пароль?
                    </a>
                </div>
            </div>
        </div>
    </main>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Переключение видимости пароля
        document.getElementById('togglePassword').addEventListener('click', function() {
            const password = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (password.type === 'password') {
                password.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                password.type = 'password';
                icon.className = 'fas fa-eye';
            }
        });

        // Валидация формы
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;

            if (!email || !password) {
                e.preventDefault();
                alert('Заполните все поля');
                return;
            }

            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                alert('Введите корректный email');
                return;
            }
        });

        // Автофокус на email при загрузке
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('email').focus();
        });
    </script>
</body>
</html> 
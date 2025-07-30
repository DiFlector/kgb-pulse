<?php
/**
 * Страница регистрации
 */

require_once 'php/helpers.php';
require_once 'php/common/Auth.php';
require_once 'php/db/Database.php';

$auth = new Auth();
$error = '';
$success = '';

// Если пользователь уже авторизован, перенаправляем
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

// Обработка формы регистрации
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fio = trim($_POST['fio'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $sex = $_POST['sex'] ?? '';
    $telephone = trim($_POST['telephone'] ?? '');
    $birthdate = $_POST['birthdate'] ?? '';
    $country = trim($_POST['country'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $sportzvanie = $_POST['sportzvanie'] ?? '';

    // Валидация данных
    if (empty($fio) || empty($email) || empty($password) || empty($confirmPassword) || 
        empty($sex) || empty($telephone) || empty($birthdate) || empty($country) || empty($city)) {
        $error = 'Заполните все обязательные поля';
    } elseif (!isValidEmail($email)) {
        $error = 'Неверный формат email';
    } elseif (strlen($password) < 8) {
        $error = 'Пароль должен содержать минимум 8 символов';
    } elseif ($password !== $confirmPassword) {
        $error = 'Пароли не совпадают';
    } elseif (!isValidPhone($telephone)) {
        $error = 'Неверный формат телефона';
    } else {
        // Проверка возраста (должен быть >= 10 лет)
        $birthYear = date('Y', strtotime($birthdate));
        $currentYear = date('Y');
        $age = $currentYear - $birthYear;
        
        if ($age < 10) {
            $error = 'Возраст должен быть не менее 10 лет';
        } else {
            try {
                $db = Database::getInstance();
                
                // Проверяем существование email
                $stmt = $db->prepare("SELECT userid FROM users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = 'Пользователь с таким email уже существует';
                } else {
                    // Проверяем существование телефона
                    $stmt = $db->prepare("SELECT userid FROM users WHERE telephone = ?");
                    $stmt->execute([normalizePhone($telephone)]);
                    if ($stmt->fetch()) {
                        $error = 'Пользователь с таким телефоном уже существует';
                    } else {
                        // Регистрируем пользователя
                        $userid = getNextUserid('Sportsman');
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        $normalizedPhone = normalizePhone($telephone);
                        
                        $stmt = $db->prepare("
                            INSERT INTO users (userid, email, password, fio, sex, telephone, birthdata, 
                                             country, city, accessrights, boats, sportzvanie) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Sportsman', NULL, ?)
                        ");
                        
                        $result = $stmt->execute([
                            $userid,
                            $email,
                            $hashedPassword,
                            $fio,
                            $sex,
                            $normalizedPhone,
                            $birthdate,
                            $country,
                            $city,
                            $sportzvanie ?: null
                        ]);
                        
                        if ($result) {
                            // Отправляем приветственное письмо
                            $subject = 'Добро пожаловать в KGB-Pulse!';
                            $message = "
                                <h2>Добро пожаловать в KGB-Pulse!</h2>
                                <p>Здравствуйте, {$fio}!</p>
                                <p>Ваша регистрация в системе управления гребной базой прошла успешно.</p>
                                <p><strong>Ваши данные для входа:</strong></p>
                                <ul>
                                    <li>Email: {$email}</li>
                                    <li>Номер спортсмена: {$userid}</li>
                                </ul>
                                <p>Теперь вы можете войти в систему и регистрироваться на соревнования.</p>
                                <p>С уважением,<br>Команда KGB-Pulse</p>
                            ";
                            
                            sendEmail($email, $subject, $message, $fio);
                            
                            $success = 'Регистрация прошла успешно! Проверьте email для подтверждения.';
                        } else {
                            $error = 'Ошибка при регистрации. Попробуйте позже.';
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Registration error: " . $e->getMessage());
                $error = 'Ошибка при регистрации. Попробуйте позже.';
            }
        }
    }
}

$pageTitle = "Регистрация - KGB-Pulse";
$currentPage = 'register';
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
        /* Специальные стили для страницы регистрации */
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
        
        /* Центрированная форма регистрации */
        .register-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            max-width: 800px;
            width: 100%;
            margin: 0 auto;
            overflow: hidden;
        }
        
        .register-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 1.5rem;
            text-align: center;
        }
        
        .register-body {
            padding: 2rem;
        }
        
        /* Убираем hover-эффекты */
        .register-container:hover {
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
            .register-container {
                margin: 1rem;
            }
            
            .register-body {
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
        <div class="register-container">
            <div class="register-header">
                <h4 class="mb-0">
                    <i class="fas fa-user-plus me-2"></i>Регистрация в системе
                </h4>
            </div>
            <div class="register-body">
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
                    <div class="text-center">
                        <a href="/lks/login.php" class="btn btn-primary">
                            <i class="fas fa-sign-in-alt me-2"></i>Войти в систему
                        </a>
                    </div>
                <?php else: ?>
                    <form method="POST" action="/lks/register.php" id="registerForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="fio" class="form-label">
                                        <i class="fas fa-user me-1"></i>ФИО <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="fio" 
                                           name="fio" 
                                           value="<?= htmlspecialchars($fio ?? '') ?>"
                                           required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="email" class="form-label">
                                        <i class="fas fa-envelope me-1"></i>Email <span class="text-danger">*</span>
                                    </label>
                                    <input type="email" 
                                           class="form-control" 
                                           id="email" 
                                           name="email" 
                                           value="<?= htmlspecialchars($email ?? '') ?>"
                                           required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="password" class="form-label">
                                        <i class="fas fa-lock me-1"></i>Пароль <span class="text-danger">*</span>
                                    </label>
                                    <input type="password" 
                                           class="form-control" 
                                           id="password" 
                                           name="password" 
                                           minlength="8"
                                           required>
                                    <div class="form-text">Минимум 8 символов</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">
                                        <i class="fas fa-lock me-1"></i>Подтвердите пароль <span class="text-danger">*</span>
                                    </label>
                                    <input type="password" 
                                           class="form-control" 
                                           id="confirm_password" 
                                           name="confirm_password" 
                                           required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="sex" class="form-label">
                                        <i class="fas fa-venus-mars me-1"></i>Пол <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="sex" name="sex" required>
                                        <option value="">Выберите пол</option>
                                        <option value="М" <?= ($sex ?? '') === 'М' ? 'selected' : '' ?>>Мужской</option>
                                        <option value="Ж" <?= ($sex ?? '') === 'Ж' ? 'selected' : '' ?>>Женский</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="birthdate" class="form-label">
                                        <i class="fas fa-calendar me-1"></i>Дата рождения <span class="text-danger">*</span>
                                    </label>
                                    <input type="date" 
                                           class="form-control" 
                                           id="birthdate" 
                                           name="birthdate" 
                                           value="<?= htmlspecialchars($birthdate ?? '') ?>"
                                           max="<?= date('Y-m-d', strtotime('-10 years')) ?>"
                                           required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="telephone" class="form-label">
                                        <i class="fas fa-phone me-1"></i>Телефон <span class="text-danger">*</span>
                                    </label>
                                    <input type="tel" 
                                           class="form-control" 
                                           id="telephone" 
                                           name="telephone" 
                                           value="<?= htmlspecialchars($telephone ?? '') ?>"
                                           placeholder="+7 900 123-45-67"
                                           required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="country" class="form-label">
                                        <i class="fas fa-flag me-1"></i>Страна <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="country" 
                                           name="country" 
                                           value="<?= htmlspecialchars($country ?? 'Россия') ?>"
                                           required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="city" class="form-label">
                                        <i class="fas fa-map-marker-alt me-1"></i>Город <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="city" 
                                           name="city" 
                                           value="<?= htmlspecialchars($city ?? '') ?>"
                                           required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="sportzvanie" class="form-label">
                                        <i class="fas fa-medal me-1"></i>Спортивное звание
                                    </label>
                                    <select class="form-select" id="sportzvanie" name="sportzvanie">
                                        <option value="">Выберите звание</option>
                                        <?php foreach (SPORT_RANKINGS as $key => $title): ?>
                                            <option value="<?= $key ?>" <?= ($sportzvanie ?? '') === $key ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($title) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-user-plus me-2"></i>Зарегистрироваться
                            </button>
                        </div>
                    </form>

                    <hr class="my-4">

                    <div class="text-center">
                        <p class="mb-2">Уже есть аккаунт?</p>
                        <a href="/lks/login.php" class="btn btn-outline-primary">
                            <i class="fas fa-sign-in-alt me-2"></i>Войти
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Валидация формы
        document.getElementById('registerForm')?.addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const telephone = document.getElementById('telephone').value;

            // Проверка паролей
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Пароли не совпадают');
                return;
            }

            if (password.length < 8) {
                e.preventDefault();
                alert('Пароль должен содержать минимум 8 символов');
                return;
            }

            // Проверка телефона
            const phoneRegex = /^(\+7|7|8)?[\s\-]?\(?[489][0-9]{2}\)?[\s\-]?[0-9]{3}[\s\-]?[0-9]{2}[\s\-]?[0-9]{2}$/;
            if (!phoneRegex.test(telephone)) {
                e.preventDefault();
                alert('Введите корректный номер телефона');
                return;
            }
        });

        // Маска для телефона
        document.getElementById('telephone')?.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            
            if (value.startsWith('8')) {
                value = '7' + value.slice(1);
            }
            
            if (value.startsWith('7') && value.length <= 11) {
                let formatted = '+7';
                if (value.length > 1) formatted += ' ' + value.slice(1, 4);
                if (value.length > 4) formatted += ' ' + value.slice(4, 7);
                if (value.length > 7) formatted += '-' + value.slice(7, 9);
                if (value.length > 9) formatted += '-' + value.slice(9, 11);
                
                e.target.value = formatted;
            }
        });

        // Проверка совпадения паролей в реальном времени
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        
        function checkPasswords() {
            if (password.value && confirmPassword.value) {
                if (password.value === confirmPassword.value) {
                    confirmPassword.classList.remove('is-invalid');
                    confirmPassword.classList.add('is-valid');
                } else {
                    confirmPassword.classList.remove('is-valid');
                    confirmPassword.classList.add('is-invalid');
                }
            } else {
                confirmPassword.classList.remove('is-valid', 'is-invalid');
            }
        }

        password?.addEventListener('input', checkPasswords);
        confirmPassword?.addEventListener('input', checkPasswords);
    </script>
</body>
</html> 
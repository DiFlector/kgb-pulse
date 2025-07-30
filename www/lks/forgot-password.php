<?php
/**
 * Страница восстановления пароля
 */
session_start();

// Если пользователь уже авторизован, перенаправляем
if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
    header('Location: /lks/enter/');
    exit;
}

$message = '';
$messageType = '';

// Обработка отправки формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $message = 'Пожалуйста, введите email адрес';
        $messageType = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Пожалуйста, введите корректный email адрес';
        $messageType = 'error';
    } else {
        // Отправляем запрос на восстановление
        require_once 'php/common/reset_password.php';
        $result = sendPasswordReset($email);
        
        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'error';
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Восстановление пароля - KGB Pulse</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/lks/favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="/lks/favicon.ico">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Пользовательские стили -->
    <link href="/lks/css/style.css" rel="stylesheet">
    
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        
        .forgot-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .logo-img {
            width: 80px;
            height: 80px;
            object-fit: contain;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 25px;
            padding: 12px 30px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .form-control {
            border-radius: 15px;
            border: 2px solid #e9ecef;
            background: rgba(255, 255, 250, 0.9);
            padding: 12px 20px;
            transition: all 0.3s ease;
            font-size: 1rem;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
            background: rgba(255, 255, 255, 1);
        }
        
        .form-control:valid {
            border-color: #28a745;
        }
        
        .form-control:invalid:not(:placeholder-shown) {
            border-color: #dc3545;
        }
        
        .input-group {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            z-index: 3;
        }
        
        .form-control.with-icon {
            padding-left: 45px;
        }
        
        .loading-spinner {
            display: none;
        }
        
        .btn-primary:disabled {
            opacity: 0.7;
            transform: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card forgot-card">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <i class="bi bi-shield-lock text-primary" style="font-size: 3rem;"></i>
                            <h2 class="text-primary fw-bold mt-2">Восстановление пароля</h2>
                            <p class="text-muted">Введите email для получения инструкций</p>
                        </div>
                        
                        <?php if ($message): ?>
                            <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
                                <i class="bi bi-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                                <?= htmlspecialchars($message) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" id="resetForm" novalidate>
                            <div class="mb-4">
                                <label for="email" class="form-label fw-semibold">Email адрес</label>
                                <div class="input-group">
                                    <i class="bi bi-envelope input-icon"></i>
                                    <input type="email" 
                                           class="form-control with-icon" 
                                           id="email" 
                                           name="email" 
                                           placeholder="Введите ваш email"
                                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                           required>
                                    <div class="invalid-feedback">
                                        Пожалуйста, введите корректный email адрес
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-grid mb-4">
                                <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                    <span class="btn-text">
                                        <i class="bi bi-send me-2"></i>
                                        Отправить инструкции
                                    </span>
                                    <span class="loading-spinner">
                                        <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                                        Отправка...
                                    </span>
                                </button>
                            </div>
                        </form>
                        
                        <div class="text-center">
                            <a href="/lks/login.php" class="text-muted text-decoration-none">
                                <i class="bi bi-arrow-left me-1"></i>
                                Вернуться к входу
                            </a>
                        </div>
                        
                        <hr class="my-4">
                        
                        <div class="text-center">
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>
                                Если письмо не пришло, проверьте папку "Спам"
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('resetForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email');
            const submitBtn = document.getElementById('submitBtn');
            const btnText = submitBtn.querySelector('.btn-text');
            const loadingSpinner = submitBtn.querySelector('.loading-spinner');
            
            // Проверяем валидность email
            if (!email.value || !email.checkValidity()) {
                e.preventDefault();
                email.classList.add('is-invalid');
                email.focus();
                return;
            }
            
            // Показываем индикатор загрузки
            submitBtn.disabled = true;
            btnText.style.display = 'none';
            loadingSpinner.style.display = 'inline';
            
            // Форма отправится автоматически
        });
        
        // Валидация email в реальном времени
        document.getElementById('email').addEventListener('input', function() {
            const email = this;
            
            if (email.value && email.checkValidity()) {
                email.classList.remove('is-invalid');
                email.classList.add('is-valid');
            } else if (email.value) {
                email.classList.remove('is-valid');
                email.classList.add('is-invalid');
            } else {
                email.classList.remove('is-valid', 'is-invalid');
            }
        });
        
        // Убираем индикатор загрузки если форма не отправилась
        window.addEventListener('pageshow', function() {
            const submitBtn = document.getElementById('submitBtn');
            const btnText = submitBtn.querySelector('.btn-text');
            const loadingSpinner = submitBtn.querySelector('.loading-spinner');
            
            submitBtn.disabled = false;
            btnText.style.display = 'inline';
            loadingSpinner.style.display = 'none';
        });
    </script>
</body>
</html> 
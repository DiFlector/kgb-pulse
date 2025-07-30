<?php
/**
 * Страница сброса пароля по токену
 */
session_start();

require_once 'php/common/reset_password.php';

$token = $_GET['token'] ?? '';
$message = '';
$messageType = '';
$showForm = false;
$tokenData = false;

if (empty($token)) {
    $message = 'Недействительная ссылка для восстановления пароля';
    $messageType = 'error';
} else {
    // Проверяем токен
    $tokenData = validateResetToken($token);
    if (!$tokenData) {
        $message = 'Ссылка для восстановления пароля недействительна или истекла';
        $messageType = 'error';
    } else {
        $showForm = true;
    }
}

// Обработка отправки формы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $showForm) {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($password)) {
        $message = 'Пожалуйста, введите новый пароль';
        $messageType = 'error';
    } elseif (strlen($password) < 8) {
        $message = 'Пароль должен содержать минимум 8 символов';
        $messageType = 'error';
    } elseif ($password !== $confirmPassword) {
        $message = 'Пароли не совпадают';
        $messageType = 'error';
    } else {
        $result = resetPassword($token, $password);
        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'error';
        
        if ($result['success']) {
            $showForm = false;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сброс пароля - KGB Pulse</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="/lks/favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="/lks/favicon.ico">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        
        .reset-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
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
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
            background: rgba(255, 255, 255, 1);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card reset-card">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <i class="bi bi-shield-lock text-primary" style="font-size: 3rem;"></i>
                            <h2 class="text-primary fw-bold mt-2">Сброс пароля</h2>
                            <?php if ($tokenData): ?>
                                <p class="text-muted">Здравствуйте, <?= htmlspecialchars($tokenData['fio']) ?>!</p>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($message): ?>
                            <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
                                <i class="bi bi-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                                <?= htmlspecialchars($message) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($showForm): ?>
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="password" class="form-label">Новый пароль</label>
                                    <input type="password" class="form-control" id="password" name="password" 
                                           required minlength="8" autocomplete="new-password">
                                    <div class="form-text">Пароль должен содержать минимум 8 символов</div>
                                </div>
                                
                                <div class="mb-4">
                                    <label for="confirm_password" class="form-label">Подтвердите пароль</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                           required autocomplete="new-password">
                                </div>
                                
                                <div class="d-grid mb-3">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="bi bi-check-circle me-2"></i>
                                        Установить новый пароль
                                    </button>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="text-center">
                                <?php if ($messageType === 'success'): ?>
                                    <div class="mb-4">
                                        <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                                        <h4 class="text-success mt-3">Пароль успешно изменен!</h4>
                                        <p class="text-muted">Теперь вы можете войти в систему с новым паролем</p>
                                    </div>
                                    <a href="/lks/login.php" class="btn btn-primary">
                                        <i class="bi bi-box-arrow-in-right me-2"></i>
                                        Войти в систему
                                    </a>
                                <?php else: ?>
                                    <div class="mb-4">
                                        <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 4rem;"></i>
                                    </div>
                                    <a href="/lks/forgot-password.php" class="btn btn-outline-primary">
                                        <i class="bi bi-arrow-left me-2"></i>
                                        Запросить новую ссылку
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="text-center mt-4">
                            <a href="/lks/login.php" class="text-muted">
                                <i class="bi bi-arrow-left me-1"></i>
                                Вернуться к входу
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Валидация формы
        document.querySelector('form')?.addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password.length < 8) {
                e.preventDefault();
                alert('Пароль должен содержать минимум 8 символов');
                return;
            }
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Пароли не совпадают');
                return;
            }
        });
    </script>
</body>
</html> 
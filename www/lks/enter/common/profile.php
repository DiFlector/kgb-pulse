<?php
/**
 * Личный кабинет пользователя
 */

require_once '../../php/helpers.php';
require_once '../../php/common/Auth.php';
require_once '../../php/db/Database.php';

$auth = new Auth();
$error = '';
$success = '';

if (!$auth->isAuthenticated()) {
    header('Location: /login');
    exit;
}

$userInfo = $auth->getCurrentUser();
$userRole = $auth->getUserRole();

// Обработка формы обновления профиля
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fio = trim($_POST['fio'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $birthdate = $_POST['birthdate'] ?? '';
    $country = trim($_POST['country'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $sportzvanie = $_POST['sportzvanie'] ?? '';

    // Валидация данных
    if (empty($fio) || empty($email) || empty($telephone) || 
        empty($birthdate) || empty($country) || empty($city)) {
        $error = 'Заполните все обязательные поля';
    } elseif (!isValidEmail($email)) {
        $error = 'Неверный формат email';
    } elseif (!isValidPhone($telephone)) {
        $error = 'Неверный формат телефона';
    } else {
        try {
            $db = Database::getInstance();
            
            // Проверяем уникальность email (кроме текущего пользователя)
            $stmt = $db->prepare("SELECT userid FROM users WHERE email = ? AND userid != ?");
            $stmt->execute([$email, $userInfo['userid']]);
            if ($stmt->fetch()) {
                $error = 'Пользователь с таким email уже существует';
            } else {
                // Проверяем уникальность телефона (кроме текущего пользователя)
                $normalizedPhone = normalizePhone($telephone);
                $stmt = $db->prepare("SELECT userid FROM users WHERE telephone = ? AND userid != ?");
                $stmt->execute([$normalizedPhone, $userInfo['userid']]);
                if ($stmt->fetch()) {
                    $error = 'Пользователь с таким телефоном уже существует';
                } else {
                    // Обновляем данные пользователя (без лодок - они обрабатываются отдельно)
                    $updateFields = [
                        'fio' => $fio,
                        'email' => $email,
                        'telephone' => $normalizedPhone,
                        'birthdata' => $birthdate,
                        'country' => $country,
                        'city' => $city,
                        'sportzvanie' => $sportzvanie ?: null
                    ];
                    
                    $setClause = implode(', ', array_map(function($field) {
                        return "$field = ?";
                    }, array_keys($updateFields)));
                    
                    $stmt = $db->prepare("UPDATE users SET $setClause WHERE userid = ?");
                    $values = array_values($updateFields);
                    $values[] = $userInfo['userid'];
                    
                    if ($stmt->execute($values)) {
                        // Обновляем данные в сессии
                        $userInfo = $auth->getCurrentUser();
                        $success = 'Данные профиля успешно обновлены';
                    } else {
                        $error = 'Ошибка при обновлении данных';
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Profile update error: " . $e->getMessage());
            $error = 'Ошибка при обновлении данных. Попробуйте позже.';
        }
    }
}

$pageTitle = "Личный кабинет - KGB-Pulse";
$pageHeader = "Личный кабинет";
$showBreadcrumb = true;
$breadcrumb = [
    ['href' => '/enter/' . strtolower($userRole) . '/', 'title' => 'Главная'],
    ['href' => '', 'title' => 'Личный кабинет']
];

include '../includes/header.php';
?>

<div class="row">
    <div class="col-lg-8 mx-auto">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="bi bi-person me-2"></i>Редактирование профиля
                </h5>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle me-2"></i>
                        <?= htmlspecialchars($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" id="profileForm">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="fio" class="form-label">
                                    <i class="bi bi-person me-1"></i>ФИО <span class="text-danger">*</span>
                                </label>
                                <input type="text" 
                                       class="form-control" 
                                       id="fio" 
                                       name="fio" 
                                       value="<?= htmlspecialchars($userInfo['fio'] ?? '') ?>"
                                       required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email" class="form-label">
                                    <i class="bi bi-envelope me-1"></i>Email <span class="text-danger">*</span>
                                </label>
                                <input type="email" 
                                       class="form-control" 
                                       id="email" 
                                       name="email" 
                                       value="<?= htmlspecialchars($userInfo['email'] ?? '') ?>"
                                       required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="telephone" class="form-label">
                                    <i class="bi bi-phone me-1"></i>Телефон <span class="text-danger">*</span>
                                </label>
                                <input type="tel" 
                                       class="form-control" 
                                       id="telephone" 
                                       name="telephone" 
                                       value="<?= htmlspecialchars($userInfo['telephone'] ?? '') ?>"
                                       required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="birthdate" class="form-label">
                                    <i class="bi bi-calendar me-1"></i>Дата рождения <span class="text-danger">*</span>
                                </label>
                                <input type="date" 
                                       class="form-control" 
                                       id="birthdate" 
                                       name="birthdate" 
                                       value="<?= htmlspecialchars($userInfo['birthdata'] ?? '') ?>"
                                       required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="country" class="form-label">
                                    <i class="bi bi-flag me-1"></i>Страна <span class="text-danger">*</span>
                                </label>
                                <input type="text" 
                                       class="form-control" 
                                       id="country" 
                                       name="country" 
                                       value="<?= htmlspecialchars($userInfo['country'] ?? '') ?>"
                                       required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="city" class="form-label">
                                    <i class="bi bi-geo-alt me-1"></i>Город <span class="text-danger">*</span>
                                </label>
                                <input type="text" 
                                       class="form-control" 
                                       id="city" 
                                       name="city" 
                                       value="<?= htmlspecialchars($userInfo['city'] ?? '') ?>"
                                       required>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="sportzvanie" class="form-label">
                                    <i class="bi bi-award me-1"></i>Спортивное звание
                                </label>
                                <select class="form-select" id="sportzvanie" name="sportzvanie">
                                    <option value="">Выберите звание</option>
                                    <?php foreach (SPORT_RANKINGS as $key => $title): ?>
                                        <option value="<?= $key ?>" <?= ($userInfo['sportzvanie'] ?? '') === $key ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($title) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="bi bi-info-circle me-1"></i>Роль
                                </label>
                                <input type="text" 
                                       class="form-control" 
                                       value="<?= htmlspecialchars(USER_ROLES[$userRole]['name'] ?? $userRole) ?>"
                                       readonly>
                                <div class="form-text">Номер: <?= htmlspecialchars($userInfo['userid'] ?? '') ?></div>
                            </div>
                        </div>
                    </div>

                    <?php if ($userRole === 'Organizer' || $userRole === 'Sportsman' || $userRole === 'SuperUser'): ?>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="bi bi-speedboat me-1"></i>Типы лодок
                            </label>
                            <div class="row">
                                <?php 
                                // Получаем текущие лодки пользователя
                                $userBoats = [];
                                if (!empty($userInfo['boats'])) {
                                    // Парсим массив PostgreSQL формата {D-10,K-1,K-2}
                                    $boatsStr = trim($userInfo['boats'], '{}');
                                    $userBoats = !empty($boatsStr) ? explode(',', $boatsStr) : [];
                                }
                                
                                // Получаем доступные типы лодок из базы данных
                                try {
                                    $db = Database::getInstance();
                                    $stmt = $db->query("SELECT unnest(enum_range(NULL::boats)) as boat_type");
                                    $availableBoats = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                } catch (Exception $e) {
                                    // Fallback на статический список
                                    $availableBoats = ['D-10', 'K-1', 'K-2', 'K-4', 'C-1', 'C-2', 'C-4', 'HD-1', 'OD-1', 'OD-2', 'OC-1'];
                                }
                                ?>
                                <?php foreach ($availableBoats as $boat): ?>
                                    <div class="col-md-4 col-sm-6">
                                        <div class="form-check">
                                            <input class="form-check-input" 
                                                   type="checkbox" 
                                                   id="boat_<?= $boat ?>" 
                                                   name="boats[]" 
                                                   value="<?= $boat ?>"
                                                   <?= in_array($boat, $userBoats) ? 'checked' : '' ?>
                                                   <?= ($userRole === 'Organizer') ? 'disabled' : '' ?>>
                                            <label class="form-check-label" for="boat_<?= $boat ?>">
                                                <?= htmlspecialchars($boat) ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text">
                                <?php if ($userRole === 'Organizer'): ?>
                                    Информация о типах лодок (только для просмотра)
                                <?php else: ?>
                                    Выберите типы лодок, на которых вы соревнуетесь
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between">
                        <a href="/lks/enter/<?= strtolower($userRole) ?>/" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-2"></i>Назад
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-floppy me-2"></i>Сохранить изменения
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Информация о пользователе -->
        <div class="card mt-4">
            <div class="card-header">
                <h6 class="mb-0">
                    <i class="bi bi-info-circle me-2"></i>Дополнительная информация
                </h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-2">
                            <strong>Дата регистрации:</strong>
                            <span class="text-muted">
                                <?= isset($userInfo['created_at']) ? date('d.m.Y', strtotime($userInfo['created_at'])) : 'Не указано' ?>
                            </span>
                        </div>
                        <div class="mb-2">
                            <strong>Последний вход:</strong>
                            <span class="text-muted">
                                <?= isset($userInfo['last_login']) ? date('d.m.Y H:i', strtotime($userInfo['last_login'])) : 'Не указано' ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-2">
                            <strong>Возраст:</strong>
                            <span class="text-muted">
                                <?php
                                if (!empty($userInfo['birthdata'])) {
                                    $birthDate = new DateTime($userInfo['birthdata']);
                                    $today = new DateTime();
                                    $age = $today->diff($birthDate);
                                    echo $age->y . ' лет';
                                } else {
                                    echo 'Не указано';
                                }
                                ?>
                            </span>
                        </div>
                        <div class="mb-2">
                            <strong>Статус:</strong>
                            <span class="badge bg-success">Активный</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$userRoleJS = json_encode($userRole); // Безопасное экранирование для JavaScript
$inlineJS = "
// Ждем полной загрузки DOM
document.addEventListener('DOMContentLoaded', function() {
    // Проверяем наличие элементов
    const boatCheckboxes = document.querySelectorAll('input[name=\"boats[]\"]');
    
    if (boatCheckboxes.length === 0) {
        return;
    }
    
    // Привязываем обработчики к каждому чекбоксу
    boatCheckboxes.forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            // Проверяем роль пользователя
            const userRole = $userRoleJS;
            
            if (userRole === 'Organizer') {
                this.checked = !this.checked;
                showNotification('Организаторы не могут изменять типы лодок', 'warning');
                return;
            }
            
            const boatName = this.value;
            const isSelected = this.checked;
            
            // Собираем выбранные лодки
            const selectedBoats = [];
            const allCheckboxes = document.querySelectorAll('input[name=\"boats[]\"]');
            allCheckboxes.forEach(function(cb) {
                if (cb.checked) {
                    selectedBoats.push(cb.value);
                }
            });
            
            // Ищем секцию лодок
            let boatsSection = null;
            let currentElement = this.parentElement;
            
            while (currentElement && !currentElement.classList.contains('mb-3')) {
                currentElement = currentElement.parentElement;
            }
            boatsSection = currentElement;
            
            if (!boatsSection) {
                showNotification('Ошибка при сохранении', 'error');
                return;
            }
            
            const labelElement = boatsSection.querySelector('.form-label');
            if (!labelElement) {
                showNotification('Ошибка при сохранении', 'error');
                return;
            }
            
            const originalLabel = labelElement.innerHTML;
            labelElement.innerHTML = '<i class=\"bi bi-arrow-clockwise spin me-1\"></i>Сохранение...';
            
            // Отправляем запрос
            fetch('/lks/php/user/manage-boats.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ boats: selectedBoats })
            })
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                labelElement.innerHTML = originalLabel;
                
                if (data.success) {
                    if (isSelected) {
                        showNotification('Лодка ' + boatName + ' добавлена в ваш список', 'success');
                    } else {
                        showNotification('Лодка ' + boatName + ' удалена из вашего списка', 'info');
                    }
                } else {
                    showNotification('Ошибка при сохранении: ' + (data.error || 'Неизвестная ошибка'), 'error');
                    // Возвращаем чекбокс в предыдущее состояние
                    this.checked = !this.checked;
                }
            })
            .catch(function(error) {
                labelElement.innerHTML = originalLabel;
                showNotification('Ошибка сети при сохранении', 'error');
                // Возвращаем чекбокс в предыдущее состояние
                this.checked = !this.checked;
            });
        });
    });
});

// Функция показа уведомлений
function showNotification(message, type) {
    // Создаем контейнер для уведомлений если его нет
    let container = document.getElementById('notification-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'notification-container';
        container.style.position = 'fixed';
        container.style.top = '20px';
        container.style.right = '20px';
        container.style.zIndex = '9999';
        document.body.appendChild(container);
    }
    
    // Определяем класс Bootstrap в зависимости от типа
    let alertClass = 'alert-info';
    let icon = 'bi-info-circle';
    
    switch(type) {
        case 'success':
            alertClass = 'alert-success';
            icon = 'bi-check-circle';
            break;
        case 'error':
            alertClass = 'alert-danger';
            icon = 'bi-exclamation-triangle';
            break;
        case 'warning':
            alertClass = 'alert-warning';
            icon = 'bi-exclamation-triangle';
            break;
    }
    
    // Создаем уведомление
    const notification = document.createElement('div');
    notification.className = 'alert ' + alertClass + ' alert-dismissible fade show';
    notification.style.minWidth = '300px';
    notification.style.marginBottom = '10px';
    notification.innerHTML = '<i class=\"bi ' + icon + ' me-2\"></i>' + message + 
                            '<button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\"></button>';
    
    container.appendChild(notification);
    
    // Автоматически скрываем через 4 секунды
    setTimeout(function() {
        if (notification.parentNode) {
            notification.classList.remove('show');
            setTimeout(function() {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 150);
        }
    }, 4000);
}

// Маска для телефона
document.addEventListener('DOMContentLoaded', function() {
    const telephoneInput = document.getElementById('telephone');
    if (telephoneInput) {
        telephoneInput.addEventListener('input', function(e) {
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
    }
});

// Валидация формы
document.addEventListener('DOMContentLoaded', function() {
    const profileForm = document.getElementById('profileForm');
    if (profileForm) {
        profileForm.addEventListener('submit', function(e) {
            const telephone = document.getElementById('telephone').value;
            const phoneRegex = /^(\+7|7|8)?[\s\-]?\(?[489][0-9]{2}\)?[\s\-]?[0-9]{3}[\s\-]?[0-9]{2}[\s\-]?[0-9]{2}$/;
            
            if (!phoneRegex.test(telephone)) {
                e.preventDefault();
                showNotification('Введите корректный номер телефона', 'warning');
                return;
            }
        });
    }
});
";
?>

<script>
<?= $inlineJS ?>
</script>

<?php include '../includes/footer.php'; ?> 
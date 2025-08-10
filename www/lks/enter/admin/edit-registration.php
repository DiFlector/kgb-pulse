<?php
require_once '../../php/common/Auth.php';

$auth = new Auth();
if (!$auth->isAuthenticated() || !in_array($auth->getUserRole(), ['Admin', 'SuperUser'])) {
    header('Location: ../../login.php');
    exit;
}

$userRole = $auth->getUserRole();
$currentUser = $auth->getCurrentUser();
$userName = $currentUser['fio'] ?? 'Пользователь';

// Получаем ID регистрации из URL
$oid = isset($_GET['oid']) ? intval($_GET['oid']) : 0;

if (!$oid) {
    header('Location: queue.php');
    exit;
}

// Проверяем права доступа (администратор имеет полные права)
$hasFullAccess = in_array($userRole, ['Admin', 'SuperUser']);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактирование регистрации - Панель администратора</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../../css/style.css" rel="stylesheet">
    <style>
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        .participant-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .class-option {
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .class-option:hover {
            border-color: #007bff;
            background-color: #f8f9fa;
        }
        .disciplines-info {
            max-height: 100px;
            overflow-y: auto;
        }
        .team-member {
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <!-- Заголовок страницы -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="bi bi-pencil-square me-2"></i>Редактирование регистрации
            <small class="text-muted d-block mt-1">Из очереди спортсменов</small>
        </h1>
        <div class="btn-group">
            <a href="javascript:void(0)" class="btn btn-secondary" onclick="goBack()">
                <i class="bi bi-arrow-left"></i> Назад к очереди
            </a>
        </div>
    </div>

    <!-- Основной контент -->
    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h6 class="m-0 font-weight-bold">Данные регистрации</h6>
                </div>
                <div class="card-body">
                    <!-- Информация о загрузке -->
                    <div id="loadingInfo" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Загрузка...</span>
                        </div>
                        <p class="mt-2">Загрузка данных регистрации...</p>
                    </div>

                    <!-- Информация об участнике -->
                    <div id="participantInfo" class="participant-info" style="display: none;">
                        <h6><i class="bi bi-person-fill me-2"></i>Информация об участнике</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <strong>ФИО:</strong> <span id="participantName"></span>
                            </div>
                            <div class="col-md-3">
                                <strong>Пол:</strong> <span id="participantSex"></span>
                            </div>
                            <div class="col-md-3">
                                <strong>Город:</strong> <span id="participantCity"></span>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <strong>Email:</strong> <span id="participantEmail"></span>
                            </div>
                            <div class="col-md-6">
                                <strong>Телефон:</strong> <span id="participantPhone"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Информация о мероприятии -->
                    <div id="eventInfo" class="participant-info" style="display: none;">
                        <h6><i class="bi bi-calendar-event me-2"></i>Информация о мероприятии</h6>
                        <div class="row">
                            <div class="col-md-8">
                                <strong>Название:</strong> <span id="eventName"></span>
                            </div>
                            <div class="col-md-4">
                                <strong>Дата:</strong> <span id="eventDate"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Форма редактирования -->
                    <form id="editForm" style="display: none;">
                        <input type="hidden" id="registrationId" name="oid">
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="">Выберите статус</option>
                                        <option value="В очереди">В очереди</option>
                                        <option value="Подтверждён">Подтверждён</option>
                                        <option value="Зарегистрирован">Зарегистрирован</option>
                                        <option value="Ожидание команды">Ожидание команды</option>
                                        <option value="Дисквалифицирован">Дисквалифицирован</option>
                                        <option value="Неявка">Неявка</option>
                                    </select>
                                    <label for="status">Статус регистрации <span class="text-danger">*</span></label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <input type="number" class="form-control" id="cost" name="cost" min="0" step="0.01">
                                    <label for="cost">Стоимость (руб.)</label>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input payment-switch" type="checkbox" id="oplata" name="oplata">
                                    <label class="form-check-label" for="oplata">
                                        <span id="oplataLabel">Оплачено</span>
                                        <small class="text-muted d-block" id="oplataHint">
                                            Администратор может включать и отключать оплату
                                        </small>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Классы и дистанции -->
                        <div class="mb-3">
                            <h6><i class="bi bi-list-check me-2"></i>Классы и дистанции</h6>
                            <div id="classDistanceContainer">
                                <!-- Классы и дистанции загружаются динамически -->
                            </div>
                        </div>

                        <!-- Кнопки -->
                        <div class="d-flex justify-content-end">
                            <button type="button" class="btn btn-secondary me-2" onclick="goBack()">
                                Отмена
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i>
                                Сохранить изменения
                            </button>
                        </div>
                    </form>

                    <!-- Ошибка загрузки -->
                    <div id="errorInfo" class="alert alert-danger" style="display: none;">
                        <h6><i class="bi bi-exclamation-triangle me-2"></i>Ошибка</h6>
                        <p id="errorMessage"></p>
                        <button class="btn btn-outline-danger btn-sm" onclick="loadRegistration()">
                            <i class="bi bi-arrow-clockwise me-1"></i>Повторить
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Информация о команде -->
        <div class="col-lg-4">
            <div class="card shadow">
                <div class="card-header bg-info text-white">
                    <h6 class="m-0 font-weight-bold">Информация о команде</h6>
                </div>
                <div class="card-body" id="teamInfo">
                    <p class="text-muted">Загружается...</p>
                </div>
            </div>

            <!-- Журнал действий -->
            <div class="card shadow mt-4">
                <div class="card-header bg-secondary text-white">
                    <h6 class="m-0 font-weight-bold">Последние действия</h6>
                </div>
                <div class="card-body" id="activityLog">
                    <p class="text-muted small">Функция в разработке</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Overlay для загрузки -->
    <div id="loadingOverlay" class="loading-overlay" style="display: none;">
        <div class="text-center text-white">
            <div class="spinner-border mb-3" role="status">
                <span class="visually-hidden">Загрузка...</span>
            </div>
            <p>Сохранение изменений...</p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let registrationData = null;
        let userRole = '<?= $_SESSION['user_role'] ?>';
        let hasFullAccess = <?= $hasFullAccess ? 'true' : 'false' ?>;

        // Загрузка данных при загрузке страницы
        document.addEventListener('DOMContentLoaded', function() {
            loadRegistration();
        });

        // Загрузка данных регистрации
        async function loadRegistration() {
            const oid = <?= $oid ?>;
            
            try {
                document.getElementById('loadingInfo').style.display = 'block';
                document.getElementById('errorInfo').style.display = 'none';
                
                // Используем новый API с параметром id
                const response = await fetch(`/lks/php/admin/get_registration.php?id=${oid}`);
                const data = await response.json();
                
                if (data.success) {
                    registrationData = data;
                    displayRegistrationData(data);
                } else {
                    throw new Error(data.message || 'Ошибка загрузки данных');
                }
                
            } catch (error) {
                console.error('Error loading registration:', error);
                document.getElementById('errorMessage').textContent = error.message;
                document.getElementById('errorInfo').style.display = 'block';
            } finally {
                document.getElementById('loadingInfo').style.display = 'none';
            }
        }

        // Отображение данных регистрации
        function displayRegistrationData(data) {
            const reg = data.registration;
            
            // Информация об участнике
            document.getElementById('participantName').textContent = reg.fio || 'Не указано';
            document.getElementById('participantEmail').textContent = reg.email || 'Не указано';
            document.getElementById('participantPhone').textContent = reg.telephone || 'Не указано';
            document.getElementById('participantSex').textContent = reg.sex || 'Не указано';
            document.getElementById('participantCity').textContent = reg.city || 'Не указано';
            
            // Информация о мероприятии
            if (document.getElementById('eventName')) {
                document.getElementById('eventName').textContent = reg.meroname || 'Не указано';
            }
            if (document.getElementById('eventDate')) {
                document.getElementById('eventDate').textContent = reg.merodata || 'Не указано';
            }

            // Заполняем форму
            document.getElementById('registrationId').value = reg.oid;
            document.getElementById('status').value = reg.status || '';
            document.getElementById('cost').value = reg.cost || '';
            document.getElementById('oplata').checked = reg.oplata || false;

            // Отображаем классы и дистанции
            displayClassDistance(data.event_classes);

            // Отображаем информацию о команде
            displayTeamInfo(data.team_members, reg.teamid);

            // Показываем форму
            document.getElementById('participantInfo').style.display = 'block';
            document.getElementById('eventInfo').style.display = 'block';
            document.getElementById('editForm').style.display = 'block';
        }

        // Отображение классов и дистанций
        function displayClassDistance(eventClasses) {
            const container = document.getElementById('classDistanceContainer');
            let html = '';

            if (!eventClasses || Object.keys(eventClasses).length === 0) {
                html = '<p class="text-muted">Классы и дистанции не настроены для этого мероприятия</p>';
            } else {
                // Получаем текущий выбор из данных регистрации
                let selectedClasses = {};
                if (registrationData && registrationData.registration && registrationData.registration.discipline) {
                    try {
                        selectedClasses = JSON.parse(registrationData.registration.discipline);
                    } catch (e) {
                        console.error('Error parsing discipline data:', e);
                    }
                }

                // Обрабатываем каждую дисциплину
                Object.keys(eventClasses).forEach(className => {
                    const classData = eventClasses[className];
                    const isSelected = selectedClasses.hasOwnProperty(className);
                    
                    html += `
                        <div class="class-option">
                            <div class="form-check">
                                <input class="form-check-input class-checkbox" 
                                       type="checkbox" 
                                       data-class="${className}"
                                       ${isSelected ? 'checked' : ''}>
                                <label class="form-check-label fw-bold">
                                    ${className}
                                </label>
                            </div>
                    `;

                    // Опции пола с учетом пола спортсмена
                    if (classData.sex) {
                        let sexOptions = [];
                        
                        // Обрабатываем разные форматы полов
                        if (Array.isArray(classData.sex)) {
                            // Если уже массив, используем как есть
                            sexOptions = classData.sex;
                        } else if (typeof classData.sex === 'string') {
                            // Если строка, разделяем по запятым
                            sexOptions = classData.sex.split(',').map(s => s.trim()).filter(s => s);
                        } else {
                            // Если объект или другой тип, пытаемся получить значения
                            sexOptions = Object.values(classData.sex).flat();
                        }
                        
                        // Получаем пол спортсмена из данных регистрации
                        const participantSex = registrationData?.registration?.sex || '';
                        
                        sexOptions.forEach(sex => {
                            // Проверяем, можно ли спортсмену выбирать этот пол
                            let canSelect = true;
                            let disabledReason = '';
                            
                            if (participantSex && sex !== 'MIX') {
                                if (participantSex === 'М' && sex === 'Ж') {
                                    canSelect = false;
                                    disabledReason = ' (только для женщин)';
                                } else if (participantSex === 'Ж' && sex === 'М') {
                                    canSelect = false;
                                    disabledReason = ' (только для мужчин)';
                                }
                            }
                            
                            const sexSelected = isSelected && selectedClasses[className].sex && 
                                              selectedClasses[className].sex.includes(sex);
                            
                            html += `
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input sex-option" 
                                           type="checkbox" 
                                           data-class="${className}" 
                                           value="${sex}"
                                           ${sexSelected ? 'checked' : ''}
                                           ${!canSelect ? 'disabled' : ''}>
                                    <label class="form-check-label ${!canSelect ? 'text-muted' : ''}">
                                        ${sex}${disabledReason}
                                    </label>
                                </div>
                            `;
                        });
                    }

                    // Опции дистанций
                    if (classData.dist) {
                        let distOptions = [];
                        
                        if (Array.isArray(classData.dist)) {
                            distOptions = classData.dist;
                        } else if (typeof classData.dist === 'string') {
                            distOptions = classData.dist.split(',').map(d => d.trim()).filter(d => d);
                        } else {
                            distOptions = Object.values(classData.dist).flat();
                        }
                        
                        distOptions.forEach(dist => {
                            const distSelected = isSelected && selectedClasses[className].dist && 
                                              selectedClasses[className].dist.includes(dist);
                            
                            html += `
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input dist-option" 
                                           type="checkbox" 
                                           data-class="${className}" 
                                           value="${dist}"
                                           ${distSelected ? 'checked' : ''}>
                                    <label class="form-check-label">${dist}</label>
                                </div>
                            `;
                        });
                    }

                    html += `</div>`;
                });
            }

            container.innerHTML = html;
        }

        // Сбор выбранных классов и дистанций
        function collectSelectedClassDistance() {
            const selected = {};
            
            document.querySelectorAll('.class-checkbox:checked').forEach(classCheckbox => {
                const className = classCheckbox.dataset.class;
                const classOption = classCheckbox.closest('.class-option');
                
                selected[className] = {
                    sex: [],
                    dist: []
                };
                
                // Собираем выбранные полы (только доступные)
                classOption.querySelectorAll('.sex-option:checked:not(:disabled)').forEach(sexCheckbox => {
                    selected[className].sex.push(sexCheckbox.value);
                });
                
                // Собираем выбранные дистанции
                classOption.querySelectorAll('.dist-option:checked').forEach(distCheckbox => {
                    selected[className].dist.push(distCheckbox.value);
                });
            });
            
            return selected;
        }

        // Отображение информации о команде
        function displayTeamInfo(teamMembers, teamId) {
            const teamInfoContainer = document.getElementById('teamInfo');
            
            if (!teamId || !teamMembers || teamMembers.length === 0) {
                teamInfoContainer.innerHTML = '<p class="text-muted">Индивидуальная регистрация</p>';
                return;
            }
            
            let html = `<h6>Команда ID: ${teamId}</h6>`;
            
            teamMembers.forEach(member => {
                const statusClass = member.status === 'Подтверждён' ? 'text-success' : 
                                   member.status === 'В очереди' ? 'text-warning' : 'text-secondary';
                const paymentIcon = member.oplata ? '💰' : '⏳';
                
                html += `
                    <div class="team-member">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <strong>${member.fio}</strong>
                                <br>
                                <small class="text-muted">${member.role || 'Участник'}</small>
                                <br>
                                <span class="badge bg-light text-dark ${statusClass}">${member.status}</span>
                            </div>
                            <div class="text-end">
                                <span title="${member.oplata ? 'Оплачено' : 'Не оплачено'}">${paymentIcon}</span>
                                <br>
                                <small class="text-muted">${member.cost || 0} ₽</small>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            teamInfoContainer.innerHTML = html;
        }

        // Обработка отправки формы
        document.getElementById('editForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            // Собираем выбранные классы и дистанции (если функция существует)
            let selectedClassDistance = {};
            if (typeof collectSelectedClassDistance === 'function') {
                selectedClassDistance = collectSelectedClassDistance();
            }
            
            const formData = {
                registrationId: parseInt(document.getElementById('registrationId').value),
                status: document.getElementById('status').value,
                oplata: document.getElementById('oplata').checked,
                cost: parseFloat(document.getElementById('cost').value) || 0,
                class_distance: selectedClassDistance // Добавляем классы и дистанции
            };
            
            try {
                document.getElementById('loadingOverlay').style.display = 'flex';
                
                const response = await fetch('/lks/php/admin/update_registration.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(formData)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Регистрация успешно обновлена', 'success');
                    // Перенаправляем обратно к списку регистраций через 2 секунды
                    setTimeout(() => {
                        const returnUrl = new URLSearchParams(window.location.search).get('return') || 'registrations.php';
                        location.href = returnUrl;
                    }, 2000);
                } else {
                    throw new Error(result.message || 'Ошибка сохранения');
                }
                
            } catch (error) {
                console.error('Error saving registration:', error);
                showNotification('Ошибка сохранения: ' + error.message, 'error');
            } finally {
                document.getElementById('loadingOverlay').style.display = 'none';
            }
        });

        // Показать уведомление
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            let alertClass = 'alert-info';
            
            if (type === 'error') {
                alertClass = 'alert-danger';
            } else if (type === 'success') {
                alertClass = 'alert-success';
            } else if (type === 'warning') {
                alertClass = 'alert-warning';
            }
            
            notification.className = `alert ${alertClass} alert-dismissible fade show position-fixed`;
            notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            notification.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 5000);
        }

        // Функция возврата с учетом параметра return
        function goBack() {
            const returnUrl = new URLSearchParams(window.location.search).get('return') || 'registrations.php';
            location.href = returnUrl;
        }
    </script>

    <?php include '../includes/footer.php'; ?>
</body>
</html> 
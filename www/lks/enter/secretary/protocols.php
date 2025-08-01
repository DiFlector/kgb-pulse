<?php
session_start();
require_once __DIR__ . '/../../php/common/Auth.php';
require_once __DIR__ . '/../../php/db/Database.php';

// Отладочная информация
error_log("protocols.php: Сессия начата");
error_log("protocols.php: SESSION = " . json_encode($_SESSION));

// Проверка авторизации и прав доступа
$auth = new Auth();
error_log("protocols.php: Auth создан");

if (!$auth->isAuthenticated()) {
    error_log("protocols.php: Пользователь не авторизован");
    header('Location: /lks/login.php');
    exit;
}

error_log("protocols.php: Пользователь авторизован");

// Проверка прав секретаря, суперпользователя или администратора
if (!$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
    error_log("protocols.php: У пользователя нет прав секретаря");
    header('Location: /lks/enter/403.html');
    exit;
}

error_log("protocols.php: У пользователя есть права секретаря");

$db = Database::getInstance();
$pdo = $db->getPDO();

// Получение данных мероприятия из сессии
if (!isset($_SESSION['selected_event'])) {
    echo "Ошибка: Мероприятие не выбрано. <a href='main.php'>Вернуться к выбору мероприятия</a>";
    exit;
}

$selectedEvent = $_SESSION['selected_event'];
$eventId = $selectedEvent['id']; // Это champn

// Получение информации о мероприятии по champn
$stmt = $pdo->prepare("SELECT oid, champn, meroname, merodata, class_distance, status FROM meros WHERE champn = ?");
$stmt->execute([$eventId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    echo "Ошибка: Мероприятие не найдено";
    exit;
}

$meroId = $event['oid']; // Используем oid для API запросов

// Отладочная информация
error_log("protocols.php: selected_disciplines = " . json_encode($_SESSION['selected_disciplines'] ?? []));
error_log("protocols.php: selected_event = " . json_encode($_SESSION['selected_event'] ?? []));
error_log("protocols.php: meroId = " . $meroId);
error_log("protocols.php: eventId = " . $eventId);

// Получение списка зарегистрированных участников
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total_participants 
    FROM listreg lr 
    JOIN users u ON lr.users_oid = u.oid 
    WHERE lr.meros_oid = ? AND lr.status IN ('Подтверждён', 'Зарегистрирован')
");
$stmt->execute([$meroId]);
$participantsCount = $stmt->fetch(PDO::FETCH_ASSOC)['total_participants'];
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Протоколы - <?php echo htmlspecialchars($event['meroname']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="/lks/css/style.css" rel="stylesheet">
    <style>
        .protocols-container {
            padding: 20px;
        }
        .event-info-panel {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .protocol-panel {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .protocol-panel h3 {
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .finish-protocols h3 {
            border-bottom-color: #dc3545;
        }
        .protocols-content {
            overflow-y: auto;
        }
        .loading-spinner {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 20px;
        }
        .protocol-table {
            font-size: 0.9rem;
        }
        .protocol-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        .protocol-title {
            color: #007bff;
            font-weight: bold;
            margin-bottom: 15px;
        }
        .distance-title {
            color: #495057;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .sex-title {
            color: #6c757d;
            font-weight: 500;
            margin-bottom: 8px;
        }
        .age-title {
            color: #28a745;
            font-weight: 600;
            margin-bottom: 10px;
            display: block;
        }
        .edit-field {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .edit-field:hover {
            background-color: #f8f9fa;
        }
        .edit-field.editing {
            background-color: #fff3cd;
            outline: 2px solid #ffc107;
        }
        .protected-protocol {
            border: 2px solid #28a745 !important;
            border-radius: 8px;
        }
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        .loading-content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }
        .loading-message {
            margin-top: 15px;
            font-size: 16px;
            color: #666;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="container-fluid protocols-container">
        <!-- Информация о мероприятии -->
        <div class="event-info-panel">
            <div class="row">
                <div class="col-md-6">
                    <h4><i class="fas fa-calendar-alt"></i> <?php echo htmlspecialchars($event['meroname']); ?></h4>
                    <p><strong>Дата:</strong> <?php echo htmlspecialchars($event['merodata']); ?></p>
                    <p><strong>Номер:</strong> <?php echo htmlspecialchars($event['champn']); ?></p>
                </div>
                <div class="col-md-6 text-end">
                    <p><strong>Статус:</strong> <?php echo htmlspecialchars($event['status']); ?></p>
                    <p><strong>Участников:</strong> <?php echo $participantsCount; ?></p>
                    <div class="btn-group" role="group">
                        <a href="select-disciplines.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Назад к выбору дисциплин
                        </a>
                        <button type="button" class="btn btn-primary" id="conduct-draw-btn">
                            <i class="fas fa-random"></i> Жеребьевка
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Основной контент -->
        <div class="row">
            <div class="col-md-6">
                <div class="protocol-panel start-protocols">
                    <h3><i class="fas fa-flag-checkered"></i> Стартовые протоколы</h3>
                    <div id="start-protocols" class="protocols-content">
                        <!-- Данные будут загружены через JavaScript -->
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="protocol-panel finish-protocols">
                    <h3><i class="fas fa-trophy"></i> Финишные протоколы</h3>
                    <div id="finish-protocols" class="protocols-content">
                        <!-- Данные будут загружены через JavaScript -->
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Отладочная информация -->
        <div class="row mt-3">
            <div class="col-12">
                <div class="alert alert-info">
                    <h6>Отладочная информация:</h6>
                    <p><strong>ID мероприятия:</strong> <span id="debug-mero-id"><?php echo htmlspecialchars($eventId); ?></span></p>
                    <p><strong>Контейнер стартовых протоколов:</strong> <span id="debug-start-container">Проверяется...</span></p>
                    <p><strong>Контейнер финишных протоколов:</strong> <span id="debug-finish-container">Проверяется...</span></p>
                    <p><strong>Выбранные дисциплины:</strong> <span id="debug-disciplines"><?php echo htmlspecialchars(json_encode($_SESSION['selected_disciplines'] ?? [])); ?></span></p>
                    <p><strong>CSS тест:</strong> <span id="debug-css-test">Проверяется...</span></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно для добавления участника -->
    <div class="modal fade" id="addParticipantModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Добавить участника</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Вкладки для выбора способа добавления -->
                    <ul class="nav nav-tabs" id="addParticipantTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="search-tab" data-bs-toggle="tab" data-bs-target="#search-panel" type="button" role="tab">
                                <i class="fas fa-search"></i> Поиск участника
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="register-tab" data-bs-toggle="tab" data-bs-target="#register-panel" type="button" role="tab">
                                <i class="fas fa-user-plus"></i> Регистрация нового
                            </button>
                        </li>
                    </ul>
                    
                    <div class="tab-content mt-3" id="addParticipantTabContent">
                        <!-- Панель поиска -->
                        <div class="tab-pane fade show active" id="search-panel" role="tabpanel">
                            <div class="mb-3">
                                <label for="participantSearch" class="form-label">Поиск участника</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="participantSearch" placeholder="Введите номер спортсмена, email или ФИО...">
                                    <button class="btn btn-outline-secondary" type="button" id="searchBtn">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                            </div>
                            <div id="searchResults" class="mt-3"></div>
                        </div>
                        
                        <!-- Панель регистрации -->
                        <div class="tab-pane fade" id="register-panel" role="tabpanel">
                            <form id="newParticipantForm">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="newEmail" class="form-label">Email *</label>
                                            <input type="email" class="form-control" id="newEmail" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="newPhone" class="form-label">Телефон *</label>
                                            <input type="tel" class="form-control" id="newPhone" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="newFio" class="form-label">ФИО *</label>
                                            <input type="text" class="form-control" id="newFio" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="newSex" class="form-label">Пол *</label>
                                            <select class="form-select" id="newSex" required>
                                                <option value="">Выберите пол</option>
                                                <option value="М">Мужской</option>
                                                <option value="Ж">Женский</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="newBirthDate" class="form-label">Дата рождения *</label>
                                            <input type="date" class="form-control" id="newBirthDate" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="newSportRank" class="form-label">Спортивное звание</label>
                                            <select class="form-select" id="newSportRank">
                                                <option value="БР">Без разряда</option>
                                                <option value="3вр">3 разряд</option>
                                                <option value="2вр">2 разряд</option>
                                                <option value="1вр">1 разряд</option>
                                                <option value="КМС">КМС</option>
                                                <option value="МСсуч">МСсуч</option>
                                                <option value="МСР">МСР</option>
                                                <option value="МССССР">МССССР</option>
                                                <option value="МСМК">МСМК</option>
                                                <option value="ЗМС">ЗМС</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-user-plus"></i> Зарегистрировать
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Скрытые поля для передачи данных -->
    <input type="hidden" id="mero-id" value="<?php echo $eventId; ?>">
    <input type="hidden" id="selected-disciplines" value="<?php echo htmlspecialchars(json_encode($_SESSION['selected_disciplines'] ?? [])); ?>">
    <input type="hidden" id="current-group-key" value="">
    
    <!-- Отладочная информация -->
    <script>
        console.log('=== ОТЛАДКА СЕССИИ ===');
        console.log('Отладка: selected-disciplines =', <?php echo json_encode($_SESSION['selected_disciplines'] ?? []); ?>);
        console.log('Отладка: selected-event =', <?php echo json_encode($_SESSION['selected_event'] ?? []); ?>);
        console.log('Отладка: mero-id =', document.getElementById('mero-id')?.value);
        console.log('Отладка: selected-disciplines element =', document.getElementById('selected-disciplines'));
        
        // Простой тест JavaScript
        console.log('JavaScript работает!');
        
        // Тест получения дисциплин
        const disciplinesElement = document.getElementById('selected-disciplines');
        if (disciplinesElement) {
            const disciplines = JSON.parse(disciplinesElement.value || '[]');
            console.log('Тест получения дисциплин:', disciplines);
            console.log('Тип дисциплин:', typeof disciplines);
            console.log('Длина массива дисциплин:', disciplines.length);
        } else {
            console.log('Элемент selected-disciplines не найден');
        }
        
        console.log('=== КОНЕЦ ОТЛАДКИ СЕССИИ ===');
        
        // Тест загрузки protocols.js
        console.log('=== ТЕСТ ЗАГРУЗКИ PROTOCOLS.JS ===');
        setTimeout(() => {
            if (typeof window.protocolsManager !== 'undefined') {
                console.log('✅ ProtocolsManager загружен успешно');
                document.getElementById('debug-start-container').textContent = 'ProtocolsManager загружен';
                document.getElementById('debug-finish-container').textContent = 'ProtocolsManager загружен';
            } else {
                console.log('❌ ProtocolsManager НЕ загружен');
                document.getElementById('debug-start-container').textContent = 'ProtocolsManager НЕ загружен';
                document.getElementById('debug-finish-container').textContent = 'ProtocolsManager НЕ загружен';
            }
        }, 1000);
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Fallback для Bootstrap
        if (typeof bootstrap === 'undefined') {
            console.warn('Bootstrap не загружен из CDN, загружаем альтернативную версию...');
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/js/bootstrap.bundle.min.js';
            script.onload = function() {
                console.log('Bootstrap загружен из альтернативного источника');
            };
            script.onerror = function() {
                console.error('Не удалось загрузить Bootstrap');
            };
            document.head.appendChild(script);
        } else {
            console.log('Bootstrap загружен успешно');
        }
    </script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="/lks/js/secretary/protocols_new.js"></script>
</body>
</html> 
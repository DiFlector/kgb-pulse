<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/lks/php/common/Auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lks/php/db/Database.php';

// Проверка авторизации и прав доступа
$auth = new Auth();
if (!$auth->isAuthenticated()) {
    header('Location: /lks/login.php');
    exit;
}

if (!$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
    header('Location: /lks/enter/403.html');
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPDO();

// Получение ID мероприятия из сессии или параметра
$meroId = $_SESSION['selected_event']['id'] ?? $_GET['event_id'] ?? null;

if (!$meroId) {
    echo "Ошибка: Мероприятие не выбрано. <a href='main.php'>Вернуться к выбору мероприятия</a>";
    exit;
}

// Получение информации о мероприятии
$stmt = $pdo->prepare("SELECT oid, champn, meroname, merodata, class_distance, status FROM meros WHERE champn = ?");
$stmt->execute([$meroId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    echo "Ошибка: Мероприятие не найдено";
    exit;
}

// Сохраняем выбранное мероприятие в сессии
$_SESSION['selected_event'] = [
    'id' => $event['champn'],
    'oid' => $event['oid'],
    'name' => $event['meroname'],
    'data' => $event['merodata']
];
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Проведение мероприятия - <?php echo htmlspecialchars($event['meroname']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="/lks/css/style.css" rel="stylesheet">
    <style>
        .event-info-panel {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .disciplines-panel {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .protocols-panel {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .discipline-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        .discipline-card:hover {
            border-color: #007bff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .discipline-card.selected {
            border-color: #007bff;
            background-color: #f8f9fa;
        }
        .loading-spinner {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
        .protocol-item {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
        }
        .protocol-item h5 {
            color: #007bff;
            margin-bottom: 10px;
        }
        .btn-action {
            margin-right: 5px;
        }
        .status-badge {
            font-size: 0.8em;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Информация о мероприятии -->
            <div class="event-info-panel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="mb-0">
                        <i class="bi bi-award text-primary"></i>
                        <?php echo htmlspecialchars($event['meroname']); ?>
                    </h2>
                    <div>
                        <span class="badge bg-info"><?php echo htmlspecialchars($event['merodata']); ?></span>
                        <a href="main.php" class="btn btn-outline-secondary ms-2">
                            <i class="bi bi-arrow-left"></i> Назад
                        </a>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Номер мероприятия:</strong> <?php echo $event['champn']; ?></p>
                        <p><strong>Статус:</strong> 
                            <span class="badge bg-<?php echo $event['status'] === 'Регистрация закрыта' ? 'warning' : 'info'; ?>">
                                <?php echo htmlspecialchars($event['status']); ?>
                            </span>
                        </p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Дата проведения:</strong> <?php echo htmlspecialchars($event['merodata']); ?></p>
                    </div>
                </div>
            </div>

            <!-- Выбор дисциплин для жеребьевки -->
            <div class="disciplines-panel">
                <h3 class="mb-3">
                    <i class="bi bi-list-check text-primary"></i>
                    Выбор дисциплин для жеребьевки
                </h3>
                
                <div id="disciplines-container">
                    <div class="loading-spinner">
                        <i class="fas fa-spinner fa-spin"></i> Загрузка дисциплин...
                    </div>
                </div>
                
                <div class="mt-3" id="draw-controls" style="display: none;">
                    <button class="btn btn-primary" id="conduct-draw-btn">
                        <i class="bi bi-shuffle"></i> Провести жеребьевку
                    </button>
                    <button class="btn btn-success" id="create-protocols-btn" style="display: none;">
                        <i class="bi bi-file-earmark-text"></i> Создать протоколы
                    </button>
                </div>
            </div>

            <!-- Протоколы -->
            <div class="protocols-panel" id="protocols-panel" style="display: none;">
                <h3 class="mb-3">
                    <i class="bi bi-file-earmark-text text-success"></i>
                    Протоколы соревнований
                </h3>
                
                <div id="protocols-container">
                    <div class="loading-spinner">
                        <i class="fas fa-spinner fa-spin"></i> Загрузка протоколов...
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно для ввода результатов -->
    <div class="modal fade" id="resultsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Ввод результатов</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="results-form">
                        <!-- Форма будет загружена динамически -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" id="save-results-btn">Сохранить результаты</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        let selectedDisciplines = [];
        let currentProtocols = [];
        let currentResultsData = null;

        // Загрузка дисциплин при загрузке страницы
        $(document).ready(function() {
            loadDisciplines();
        });

        // Загрузка доступных дисциплин
        function loadDisciplines() {
            $.ajax({
                url: '/lks/php/secretary/conduct_draw_api.php?action=get_disciplines',
                method: 'POST',
                data: JSON.stringify({
                    mero_id: <?php echo $meroId; ?>
                }),
                contentType: 'application/json',
                success: function(response) {
                    if (response.success) {
                        displayDisciplines(response.disciplines);
                    } else {
                        showError('Ошибка загрузки дисциплин: ' + response.message);
                    }
                },
                error: function() {
                    showError('Ошибка соединения с сервером');
                }
            });
        }

        // Отображение дисциплин
        function displayDisciplines(disciplines) {
            const container = $('#disciplines-container');
            
            if (disciplines.length === 0) {
                container.html('<div class="alert alert-warning">Нет доступных дисциплин для жеребьевки</div>');
                return;
            }

            let html = '<div class="row">';
            disciplines.forEach(function(discipline) {
                html += `
                    <div class="col-md-6 mb-3">
                        <div class="discipline-card" data-discipline='${JSON.stringify(discipline)}'>
                            <div class="form-check">
                                <input class="form-check-input discipline-checkbox" type="checkbox" 
                                       value="${discipline.key}" id="discipline_${discipline.key}">
                                <label class="form-check-label" for="discipline_${discipline.key}">
                                    <strong>${discipline.class} ${discipline.sex} ${discipline.distance}м</strong>
                                    <br>
                                    <small class="text-muted">
                                        Участников: ${discipline.participants_count}
                                    </small>
                                </label>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            
            container.html(html);
            
            // Обработчики для чекбоксов
            $('.discipline-checkbox').change(function() {
                updateSelectedDisciplines();
            });
        }

        // Обновление выбранных дисциплин
        function updateSelectedDisciplines() {
            selectedDisciplines = [];
            $('.discipline-checkbox:checked').each(function() {
                const disciplineKey = $(this).val();
                const disciplineCard = $(this).closest('.discipline-card');
                const disciplineData = JSON.parse(disciplineCard.data('discipline'));
                selectedDisciplines.push(disciplineData);
            });
            
            if (selectedDisciplines.length > 0) {
                $('#draw-controls').show();
            } else {
                $('#draw-controls').hide();
            }
        }

        // Проведение жеребьевки
        $('#conduct-draw-btn').click(function() {
            if (selectedDisciplines.length === 0) {
                showError('Выберите хотя бы одну дисциплину');
                return;
            }
            
            $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Проведение жеребьевки...');
            
            $.ajax({
                url: '/lks/php/secretary/conduct_draw_api.php?action=conduct_draw',
                method: 'POST',
                data: JSON.stringify({
                    mero_id: <?php echo $meroId; ?>,
                    disciplines: selectedDisciplines
                }),
                contentType: 'application/json',
                success: function(response) {
                    if (response.success) {
                        showSuccess('Жеребьевка проведена успешно!');
                        $('#create-protocols-btn').show();
                        loadProtocols();
                    } else {
                        showError('Ошибка проведения жеребьевки: ' + response.message);
                    }
                },
                error: function() {
                    showError('Ошибка соединения с сервером');
                },
                complete: function() {
                    $('#conduct-draw-btn').prop('disabled', false).html('<i class="bi bi-shuffle"></i> Провести жеребьевку');
                }
            });
        });

        // Создание протоколов
        $('#create-protocols-btn').click(function() {
            $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Создание протоколов...');
            
            $.ajax({
                url: '/lks/php/secretary/conduct_draw_api.php?action=create_protocols',
                method: 'POST',
                data: JSON.stringify({
                    mero_id: <?php echo $meroId; ?>
                }),
                contentType: 'application/json',
                success: function(response) {
                    if (response.success) {
                        showSuccess('Протоколы созданы успешно!');
                        loadProtocols();
                    } else {
                        showError('Ошибка создания протоколов: ' + response.message);
                    }
                },
                error: function() {
                    showError('Ошибка соединения с сервером');
                },
                complete: function() {
                    $('#create-protocols-btn').prop('disabled', false).html('<i class="bi bi-file-earmark-text"></i> Создать протоколы');
                }
            });
        });

        // Загрузка протоколов
        function loadProtocols() {
            $.ajax({
                url: '/lks/php/secretary/conduct_draw_api.php?action=get_protocols',
                method: 'POST',
                data: JSON.stringify({
                    mero_id: <?php echo $meroId; ?>
                }),
                contentType: 'application/json',
                success: function(response) {
                    if (response.success) {
                        currentProtocols = response.protocols;
                        displayProtocols(response.protocols);
                        $('#protocols-panel').show();
                    } else {
                        showError('Ошибка загрузки протоколов: ' + response.message);
                    }
                },
                error: function() {
                    showError('Ошибка соединения с сервером');
                }
            });
        }

        // Отображение протоколов
        function displayProtocols(protocols) {
            const container = $('#protocols-container');
            
            if (protocols.length === 0) {
                container.html('<div class="alert alert-info">Протоколы еще не созданы</div>');
                return;
            }

            let html = '';
            protocols.forEach(function(protocol) {
                const discipline = protocol.discipline;
                html += `
                    <div class="protocol-item">
                        <h5>${discipline.class} ${discipline.sex} ${discipline.distance}м - ${protocol.age_group}</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Стартовые протоколы</h6>
                                <div class="mb-2">
                                    ${protocol.start_protocol.heats.map((heat, index) => `
                                        <button class="btn btn-outline-primary btn-sm btn-action" 
                                                onclick="downloadStartProtocol('${discipline.key}', '${protocol.age_group}', ${heat.heat_number})">
                                            Заезд ${heat.heat_number} (${heat.participants.length} участников)
                                        </button>
                                    `).join('')}
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6>Финишные протоколы</h6>
                                <div class="mb-2">
                                    ${protocol.finish_protocol.heats.map((heat, index) => `
                                        <button class="btn btn-outline-success btn-sm btn-action" 
                                                onclick="enterResults('${discipline.key}', '${protocol.age_group}', ${heat.heat_number})">
                                            Ввести результаты - Заезд ${heat.heat_number}
                                        </button>
                                    `).join('')}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            container.html(html);
        }

        // Скачивание стартового протокола
        function downloadStartProtocol(disciplineKey, ageGroup, heatNumber) {
            // Здесь будет логика скачивания протокола
            showInfo('Функция скачивания протокола будет реализована');
        }

        // Ввод результатов
        function enterResults(disciplineKey, ageGroup, heatNumber) {
            // Находим соответствующий протокол
            const protocol = currentProtocols.find(p => 
                p.discipline.key === disciplineKey && p.age_group === ageGroup
            );
            
            if (!protocol) {
                showError('Протокол не найден');
                return;
            }
            
            const heat = protocol.finish_protocol.heats.find(h => h.heat_number === heatNumber);
            if (!heat) {
                showError('Заезд не найден');
                return;
            }
            
            // Создаем форму для ввода результатов
            let formHtml = `
                <h6>Результаты заезда ${heatNumber}</h6>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Дорожка</th>
                                <th>Стартовый номер</th>
                                <th>ФИО</th>
                                <th>Время</th>
                                <th>Место</th>
                                <th>Примечания</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            heat.participants.forEach(function(participant) {
                formHtml += `
                    <tr>
                        <td>${participant.lane}</td>
                        <td>${participant.start_number}</td>
                        <td>${participant.fio}</td>
                        <td>
                            <input type="text" class="form-control result-time" 
                                   data-user-id="${participant.user_id}" 
                                   value="${participant.result_time || ''}" 
                                   placeholder="00:00.00">
                        </td>
                        <td>
                            <input type="number" class="form-control result-place" 
                                   data-user-id="${participant.user_id}" 
                                   value="${participant.place || ''}" 
                                   min="1">
                        </td>
                        <td>
                            <input type="text" class="form-control result-notes" 
                                   data-user-id="${participant.user_id}" 
                                   value="${participant.notes || ''}">
                        </td>
                    </tr>
                `;
            });
            
            formHtml += `
                        </tbody>
                    </table>
                </div>
            `;
            
            $('#results-form').html(formHtml);
            
            // Сохраняем данные для отправки
            currentResultsData = {
                discipline_key: disciplineKey,
                age_group: ageGroup,
                heat_number: heatNumber
            };
            
            $('#resultsModal').modal('show');
        }

        // Сохранение результатов
        $('#save-results-btn').click(function() {
            if (!currentResultsData) {
                showError('Нет данных для сохранения');
                return;
            }
            
            const results = [];
            $('.result-time').each(function() {
                const userId = $(this).data('user-id');
                const time = $(this).val();
                const place = $(`.result-place[data-user-id="${userId}"]`).val();
                const notes = $(`.result-notes[data-user-id="${userId}"]`).val();
                
                if (time) {
                    results.push({
                        user_id: userId,
                        result_time: time,
                        place: place || null,
                        notes: notes || ''
                    });
                }
            });
            
            if (results.length === 0) {
                showError('Введите хотя бы одно время');
                return;
            }
            
            $.ajax({
                url: '/lks/php/secretary/conduct_draw_new_api.php?action=save_results',
                method: 'POST',
                data: JSON.stringify({
                    mero_id: <?php echo $meroId; ?>,
                    discipline_key: currentResultsData.discipline_key,
                    age_group: currentResultsData.age_group,
                    heat_number: currentResultsData.heat_number,
                    results: results
                }),
                contentType: 'application/json',
                success: function(response) {
                    if (response.success) {
                        showSuccess('Результаты сохранены успешно!');
                        $('#resultsModal').modal('hide');
                        loadProtocols(); // Перезагружаем протоколы
                    } else {
                        showError('Ошибка сохранения результатов: ' + response.message);
                    }
                },
                error: function() {
                    showError('Ошибка соединения с сервером');
                }
            });
        });

        // Вспомогательные функции для уведомлений
        function showSuccess(message) {
            // Здесь можно добавить красивые уведомления
            alert('Успех: ' + message);
        }

        function showError(message) {
            alert('Ошибка: ' + message);
        }

        function showInfo(message) {
            alert('Информация: ' + message);
        }
    </script>
</body>
</html> 
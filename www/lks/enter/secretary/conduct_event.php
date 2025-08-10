<?php
session_start();
require_once __DIR__ . '/../../php/common/Auth.php';
require_once __DIR__ . '/../../php/db/Database.php';

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
                
                <!-- Тестовая кнопка для отладки -->
                <div class="mt-3" id="debug-controls">
                    <button class="btn btn-warning" onclick="testShowControls()">
                        <i class="bi bi-bug"></i> Тест: Показать кнопки
                    </button>
                    <button class="btn btn-info" onclick="testCheckDisciplines()">
                        <i class="bi bi-info-circle"></i> Проверить дисциплины
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
            console.log('Страница загружена, начинаем загрузку дисциплин...');
            loadDisciplines();
            
            // Тест: показываем кнопку принудительно
            setTimeout(function() {
                console.log('Тест: принудительно показываем кнопки управления');
                $('#draw-controls').show();
            }, 3000);
        });

        // Загрузка доступных дисциплин
        function loadDisciplines() {
            $.ajax({
                url: '/lks/php/secretary/get_disciplines_simple.php',
                method: 'POST',
                data: JSON.stringify({
                    mero_id: <?php echo $meroId; ?>
                }),
                contentType: 'application/json',
                success: function(response) {
                    if (response.success) {
                        displayDisciplines(response.disciplines);
                    } else {
                        const errorMessage = response.message || 'Неизвестная ошибка';
                        showError('Ошибка загрузки дисциплин: ' + errorMessage);
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
            
            // Обработчики для чекбоксов (используем делегирование событий)
            $(document).on('change', '.discipline-checkbox', function() {
                console.log('Чекбокс изменен:', $(this).val(), 'checked:', $(this).is(':checked'));
                updateSelectedDisciplines();
            });
            
            // Проверяем состояние после загрузки
            console.log('Дисциплины загружены, проверяем состояние...');
            updateSelectedDisciplines();
            
            // Принудительно обновляем состояние через небольшую задержку
            setTimeout(function() {
                console.log('Принудительное обновление состояния...');
                updateSelectedDisciplines();
            }, 100);
        }

        // Тестовые функции для отладки
        function testShowControls() {
            console.log('Тест: принудительно показываем кнопки управления');
            $('#draw-controls').show();
            alert('Кнопки управления должны быть видны!');
        }
        
        function testCheckDisciplines() {
            console.log('Проверяем выбранные дисциплины...');
            console.log('Всего чекбоксов:', $('.discipline-checkbox').length);
            console.log('Отмеченных чекбоксов:', $('.discipline-checkbox:checked').length);
            console.log('selectedDisciplines:', selectedDisciplines);
            
            // Принудительно обновляем состояние
            updateSelectedDisciplines();
            console.log('После принудительного обновления:');
            console.log('selectedDisciplines:', selectedDisciplines);
            console.log('Длина массива:', selectedDisciplines.length);
            
            alert('Проверьте консоль браузера для деталей');
        }
        
        // Обновление выбранных дисциплин
        function updateSelectedDisciplines() {
            selectedDisciplines = [];
            console.log('Начинаем обновление выбранных дисциплин...');
            console.log('Найдено отмеченных чекбоксов:', $('.discipline-checkbox:checked').length);
            
            $('.discipline-checkbox:checked').each(function(index) {
                const disciplineKey = $(this).val();
                console.log('Обрабатываем чекбокс', index + 1, ':', disciplineKey);
                
                const disciplineCard = $(this).closest('.discipline-card');
                console.log('Найден discipline-card:', disciplineCard.length > 0);
                
                const disciplineDataRaw = disciplineCard.data('discipline');
                console.log('Данные дисциплины (raw):', disciplineDataRaw);
                
                if (disciplineDataRaw) {
                    try {
                        const disciplineData = JSON.parse(disciplineDataRaw);
                        console.log('Данные дисциплины (parsed):', disciplineData);
                        selectedDisciplines.push(disciplineData);
                    } catch (e) {
                        console.error('Ошибка парсинга JSON:', e);
                    }
                } else {
                    console.error('Данные дисциплины не найдены в data-атрибуте');
                }
            });
            
            console.log('Выбранные дисциплины:', selectedDisciplines);
            console.log('Количество выбранных дисциплин:', selectedDisciplines.length);
            
            if (selectedDisciplines.length > 0) {
                console.log('Показываем кнопки управления');
                const drawControls = $('#draw-controls');
                if (drawControls.length > 0) {
                    drawControls.show();
                } else {
                    console.error('Элемент #draw-controls не найден!');
                }
            } else {
                console.log('Скрываем кнопки управления');
                const drawControls = $('#draw-controls');
                if (drawControls.length > 0) {
                    drawControls.hide();
                } else {
                    console.error('Элемент #draw-controls не найден!');
                }
            }
        }

        // Проведение жеребьевки
        $('#conduct-draw-btn').click(function() {
            console.log('Кнопка "Провести жеребьевку" нажата');
            
            // Принудительно обновляем выбранные дисциплины перед проверкой
            updateSelectedDisciplines();
            
            console.log('selectedDisciplines после обновления:', selectedDisciplines);
            console.log('Длина массива:', selectedDisciplines.length);
            console.log('Отмеченные чекбоксы:', $('.discipline-checkbox:checked').length);
            
            if (selectedDisciplines.length === 0) {
                console.log('Ошибка: массив selectedDisciplines пуст');
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
                        const errorMessage = response.message || 'Неизвестная ошибка';
                        showError('Ошибка проведения жеребьевки: ' + errorMessage);
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
                        const errorMessage = response.message || 'Неизвестная ошибка';
                        showError('Ошибка создания протоколов: ' + errorMessage);
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
                        const errorMessage = response.message || 'Неизвестная ошибка';
                        showError('Ошибка загрузки протоколов: ' + errorMessage);
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
                        const errorMessage = response.message || 'Неизвестная ошибка';
                        showError('Ошибка сохранения результатов: ' + errorMessage);
                    }
                },
                error: function() {
                    showError('Ошибка соединения с сервером');
                }
            });
        });

        // Вспомогательные функции для уведомлений
        function showSuccess(message) {
            // Создаем модальное окно для успеха
            const modalHtml = `
                <div class="modal fade" id="successModal" tabindex="-1" role="dialog">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="bi bi-check-circle text-success"></i> Успех
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p>${message}</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-success" data-bs-dismiss="modal">OK</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Удаляем предыдущее модальное окно, если оно есть
            $('#successModal').remove();
            
            // Добавляем новое модальное окно
            $('body').append(modalHtml);
            
            // Показываем модальное окно
            const modal = new bootstrap.Modal(document.getElementById('successModal'));
            modal.show();
        }

        function showError(message) {
            // Создаем модальное окно для ошибки
            const modalHtml = `
                <div class="modal fade" id="errorModal" tabindex="-1" role="dialog">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="bi bi-exclamation-triangle text-danger"></i> Ошибка
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p>${message}</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">OK</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Удаляем предыдущее модальное окно, если оно есть
            $('#errorModal').remove();
            
            // Добавляем новое модальное окно
            $('body').append(modalHtml);
            
            // Показываем модальное окно
            const modal = new bootstrap.Modal(document.getElementById('errorModal'));
            modal.show();
        }

        function showInfo(message) {
            // Создаем модальное окно для информации
            const modalHtml = `
                <div class="modal fade" id="infoModal" tabindex="-1" role="dialog">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="bi bi-info-circle text-info"></i> Информация
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p>${message}</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-info" data-bs-dismiss="modal">OK</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Удаляем предыдущее модальное окно, если оно есть
            $('#infoModal').remove();
            
            // Добавляем новое модальное окно
            $('body').append(modalHtml);
            
            // Показываем модальное окно
            const modal = new bootstrap.Modal(document.getElementById('infoModal'));
            modal.show();
        }
    </script>
</body>
</html> 
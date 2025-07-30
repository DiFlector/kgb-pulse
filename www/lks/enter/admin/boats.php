<?php
/**
 * Управление классами лодок - Администратор
 */

// Проверка авторизации
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Admin' && $_SESSION['user_role'] !== 'SuperUser')) {
    header('Location: /lks/login.php');
    exit;
}

require_once '../../php/db/Database.php';

// Получение информации о типах лодок из ENUM
$db = Database::getInstance();
$query = "
    SELECT enumlabel 
    FROM pg_enum 
    WHERE enumtypid = (
        SELECT oid FROM pg_type WHERE typname = 'boats'
    )
    ORDER BY enumlabel
";
$boats = $db->query($query)->fetchAll(PDO::FETCH_COLUMN);

include '../includes/header.php';
?>

<style>
.boat-card {
    transition: transform 0.2s ease;
    border: 1px solid #dee2e6;
}

.boat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

.usage-badge {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
}

.progress {
    height: 1.25rem;
}

.chart-container {
    position: relative;
    height: 300px;
    margin: 20px 0;
}

.chart-container canvas {
    width: 100% !important;
    height: 100% !important;
}

.distance-stats {
    margin-top: 20px;
}

.distance-item {
    padding: 10px;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    margin-bottom: 10px;
    background-color: #f8f9fa;
}
</style>

<!-- Заголовок страницы -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Управление классами лодок</h1>
    <div class="btn-group">
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addBoatModal">
            <i class="bi bi-plus-circle me-1"></i>Добавить класс лодки
        </button>
    </div>
</div>

<!-- Информация о типах лодок -->
<div class="alert alert-info" role="alert">
    <i class="bi bi-info-circle-fill me-2"></i>
    <strong>Информация:</strong> Управление классами лодок позволяет добавлять новые типы лодок в систему. 
    Текущие классы хранятся как ENUM тип в PostgreSQL.
</div>

<!-- Текущие классы лодок -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fa-solid fa-ship me-2"></i>Текущие классы лодок</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <?php foreach ($boats as $index => $boat): ?>
            <div class="col-md-4 col-lg-3 mb-3">
                <div class="card boat-card h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-water text-primary mb-2" style="font-size: 2rem;"></i>
                        <h5 class="card-title"><?= htmlspecialchars($boat) ?></h5>
                        <p class="card-text text-muted small">
                            <?= getBoatDescription($boat) ?>
                        </p>
                        <div class="btn-group mb-2" role="group">
                            <button class="btn btn-sm btn-outline-warning" onclick="editBoatDescription('<?= $boat ?>')" title="Редактировать описание">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-info" onclick="viewBoatStats('<?= $boat ?>')" title="Персональная статистика лодки">
                                <i class="bi bi-bar-chart"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteBoat('<?= $boat ?>')" title="Удалить класс">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                        <div class="mt-2">
                            <span class="badge bg-secondary usage-badge">
                                <?php
                                // Подсчет использований
                                $usageQuery = "SELECT COUNT(*) FROM users WHERE boats @> ARRAY[?]::boats[]";
                                $usageStmt = $db->prepare($usageQuery);
                                $usageStmt->execute([$boat]);
                                $usageCount = $usageStmt->fetchColumn();
                                echo "Используется: $usageCount";
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Статистика использования -->
<div class="card">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Статистика использования</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Класс лодки</th>
                        <th>Количество спортсменов</th>
                        <th>Количество регистраций</th>
                        <th>Популярность</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Общее количество всех регистраций для расчета процентов
                    $totalRegistrations = $db->query("SELECT COUNT(*) FROM listreg")->fetchColumn();
                    
                    foreach ($boats as $boat):
                        // Подсчет спортсменов
                        $usersQuery = "SELECT COUNT(*) FROM users WHERE boats @> ARRAY[?]::boats[]";
                        $usersStmt = $db->prepare($usersQuery);
                        $usersStmt->execute([$boat]);
                        $usersCount = $usersStmt->fetchColumn();
                        
                        // Подсчет регистраций (через JSONB)
                        $regsQuery = "SELECT COUNT(*) FROM listreg WHERE discipline::text LIKE ?";
                        $regsStmt = $db->prepare($regsQuery);
                        $regsStmt->execute(['%"' . $boat . '"%']);
                        $regsCount = $regsStmt->fetchColumn();
                        
                        // Процент от всех регистраций
                        $popularity = $totalRegistrations > 0 ? round(($regsCount / $totalRegistrations) * 100, 1) : 0;
                    ?>
                    <tr>
                        <td>
                            <i class="fa-solid fa-ship text-primary me-1"></i>
                            <strong><?= htmlspecialchars($boat) ?></strong>
                        </td>
                        <td><?= $usersCount ?></td>
                        <td><?= $regsCount ?></td>
                        <td>
                            <div class="progress">
                                <div class="progress-bar bg-<?= getPopularityColor($popularity) ?>" 
                                     role="progressbar" 
                                     style="width: <?= min($popularity, 100) ?>%">
                                    <?= $popularity ?>%
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Модальное окно добавления класса лодки -->
<div class="modal fade" id="addBoatModal" tabindex="-1" aria-labelledby="addBoatModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addBoatModalLabel">Добавить новый класс лодки</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <strong>Внимание!</strong> Добавление нового класса лодки требует изменения структуры базы данных.
                </div>
                
                <form id="addBoatForm">
                    <div class="mb-3">
                        <label for="boatCode" class="form-label">Код класса лодки</label>
                        <input type="text" class="form-control" id="boatCode" required 
                               placeholder="например: K-8" pattern="[A-Z]{1,2}-[0-9]{1,2}">
                        <div class="form-text">Формат: буквы-цифра (например: K-1, C-4, HD-1)</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="boatName" class="form-label">Описание</label>
                        <input type="text" class="form-control" id="boatName" required 
                               placeholder="например: Байдарка одиночка">
                    </div>
                    
                    <div class="mb-3">
                        <label for="boatCapacity" class="form-label">Количество участников</label>
                        <input type="number" class="form-control" id="boatCapacity" min="1" max="20" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="boatCategory" class="form-label">Категория</label>
                        <select class="form-select" id="boatCategory" required>
                            <option value="">Выберите категорию...</option>
                            <option value="Байдарка">Байдарка (K)</option>
                            <option value="Каноэ">Каноэ (C)</option>
                            <option value="Дракон">Дракон (D)</option>
                            <option value="САП">САП (SUP)</option>
                            <option value="Другое">Другое</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-success" onclick="addBoat()">Добавить</button>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно редактирования описания -->
<div class="modal fade" id="editBoatModal" tabindex="-1" aria-labelledby="editBoatModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editBoatModalLabel">Редактировать описание лодки</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editBoatForm">
                    <input type="hidden" id="editBoatCode">
                    <div class="mb-3">
                        <label for="editBoatName" class="form-label">Название лодки</label>
                        <input type="text" class="form-control" id="editBoatName" required>
                    </div>
                    <div class="mb-3">
                        <label for="editBoatDescription" class="form-label">Описание</label>
                        <textarea class="form-control" id="editBoatDescription" rows="3" required></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" onclick="saveBoatEdit()">Сохранить</button>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно статистики -->
<div class="modal fade" id="boatStatsModal" tabindex="-1" aria-labelledby="boatStatsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="boatStatsModalLabel">
                    <i class="fa-solid fa-ship me-2"></i>
                    Статистика лодки: <span id="statsBoatCode"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="boatStatsContent">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Загрузка...</span>
                        </div>
                        <p class="mt-2">Загрузка статистики...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<script>
// Функция добавления лодки
function addBoat() {
    const form = document.getElementById('addBoatForm');
    const boatCode = document.getElementById('boatCode').value;
    const boatName = document.getElementById('boatName').value;
    const boatCapacity = document.getElementById('boatCapacity').value;
    const boatCategory = document.getElementById('boatCategory').value;
    
    if (!boatCode || !boatName || !boatCapacity || !boatCategory) {
        alert('Заполните все обязательные поля');
        return;
    }
    
    if (confirm('Добавить новый класс лодки "' + boatCode + '"? Это изменит структуру базы данных.')) {
        // Отправляем запрос на добавление лодки
        fetch('/lks/php/admin/add-boat.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                boat_code: boatCode,
                boat_name: boatName,
                boat_capacity: boatCapacity,
                boat_category: boatCategory
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Класс лодки "' + boatCode + '" успешно добавлен');
                location.reload();
            } else {
                alert('Ошибка: ' + (data.message || 'Не удалось добавить класс лодки'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ошибка при добавлении класса лодки');
        });
    }
}

// Функция редактирования описания лодки
function editBoatDescription(boatCode) {
    // Загружаем текущее описание
    fetch('/lks/php/admin/get-boat-info.php?boat=' + encodeURIComponent(boatCode))
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('editBoatCode').value = boatCode;
                document.getElementById('editBoatName').value = data.boat.name || boatCode;
                document.getElementById('editBoatDescription').value = data.boat.description || '';
                
                // Показываем модальное окно
                const modal = new bootstrap.Modal(document.getElementById('editBoatModal'));
                modal.show();
            } else {
                alert('Ошибка загрузки информации о лодке: ' + (data.message || 'Неизвестная ошибка'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ошибка при загрузке информации о лодке');
        });
}

// Функция сохранения изменений описания
function saveBoatEdit() {
    const boatCode = document.getElementById('editBoatCode').value;
    const boatName = document.getElementById('editBoatName').value;
    const boatDescription = document.getElementById('editBoatDescription').value;
    
    if (!boatName.trim() || !boatDescription.trim()) {
        alert('Заполните все обязательные поля');
        return;
    }
    
    fetch('/lks/php/admin/update-boat-description.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            boat_code: boatCode,
            boat_name: boatName.trim(),
            description: boatDescription.trim()
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Описание лодки успешно обновлено');
            location.reload();
        } else {
            alert('Ошибка: ' + (data.message || 'Не удалось обновить описание'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Ошибка при обновлении описания лодки');
    });
}

// Функция просмотра статистики лодки
function viewBoatStats(boatCode) {
    document.getElementById('statsBoatCode').textContent = boatCode;
    
    // Показываем модальное окно
    const modal = new bootstrap.Modal(document.getElementById('boatStatsModal'));
    modal.show();
    
    // Показываем загрузку
    document.getElementById('boatStatsContent').innerHTML = 
        '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Загрузка...</span></div></div>';
    
    // Загружаем статистику
    fetch('/lks/php/admin/get-boat-stats.php?boat=' + encodeURIComponent(boatCode))
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                displayBoatStats(data.stats);
            } else {
                document.getElementById('boatStatsContent').innerHTML = 
                    '<div class="alert alert-danger">Ошибка загрузки статистики: ' + (data.message || 'Неизвестная ошибка') + '</div>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('boatStatsContent').innerHTML = 
                '<div class="alert alert-danger">Ошибка при загрузке статистики: ' + error.message + '</div>';
        });
}

// Функция отображения статистики
function displayBoatStats(stats) {
    
    const content = `
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0">Общие показатели</h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6">
                                <h4 class="text-primary">${stats.users_count}</h4>
                                <small class="text-muted">Спортсменов</small>
                            </div>
                            <div class="col-6">
                                <h4 class="text-success">${stats.registrations_count}</h4>
                                <small class="text-muted">Регистраций</small>
                            </div>
                        </div>
                        <hr>
                        <div class="text-center">
                            <h5 class="text-info">${stats.popularity}%</h5>
                            <small class="text-muted">Популярность</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-success text-white">
                        <h6 class="mb-0">Статистика мероприятий</h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6">
                                <h4 class="text-warning">${stats.events_count}</h4>
                                <small class="text-muted">Мероприятий</small>
                            </div>
                            <div class="col-6">
                                <h4 class="text-info">${stats.teams_count}</h4>
                                <small class="text-muted">Команд</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- График популярности -->
        <div class="card mb-3">
            <div class="card-header bg-info text-white">
                <h6 class="mb-0">График популярности среди всех лодок</h6>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="popularityChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Статистика по дистанциям -->
        <div class="card">
            <div class="card-header bg-warning text-white">
                <h6 class="mb-0">Статистика по дистанциям</h6>
            </div>
            <div class="card-body">
                <div class="distance-stats">
                    ${stats.distance_stats.map(distance => `
                        <div class="distance-item">
                            <h6 class="mb-2">Дистанция ${distance.distance}м</h6>
                            <div class="progress mb-2">
                                <div class="progress-bar bg-primary" role="progressbar" 
                                     style="width: ${distance.percentage}%">
                                    ${distance.percentage}%
                                </div>
                            </div>
                            <small class="text-muted">
                                ${distance.count} из ${distance.total} регистраций на этой дистанции
                            </small>
                        </div>
                    `).join('')}
                </div>
            </div>
        </div>
    `;
    
    document.getElementById('boatStatsContent').innerHTML = content;
    
    // Создаем график
    if (stats.chart_data) {
        // Небольшая задержка для гарантии, что DOM обновлен
        setTimeout(() => {
            createPopularityChart(stats.chart_data);
        }, 100);
    }
}

// Функция создания графика
function createPopularityChart(chartData) {
    // Проверяем, что Chart.js загружен
    if (typeof Chart === 'undefined') {
        console.error('Chart.js не загружен');
        return;
    }
    
    const canvas = document.getElementById('popularityChart');
    if (!canvas) {
        console.error('Canvas элемент не найден');
        return;
    }
    
    // Уничтожаем предыдущий график, если он существует
    if (window.currentChart) {
        window.currentChart.destroy();
    }
    
    const ctx = canvas.getContext('2d');
    
    try {
        window.currentChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: chartData.labels || [],
                datasets: [{
                    label: 'Количество регистраций',
                    data: chartData.data || [],
                    backgroundColor: 'rgba(54, 162, 235, 0.8)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    } catch (error) {
        console.error('Error creating chart:', error);
    }
}

// Функция удаления лодки
function deleteBoat(boatCode) {
    if (confirm('Вы уверены, что хотите удалить класс лодки "' + boatCode + '"?\n\nВНИМАНИЕ: Это изменит структуру базы данных и может повлиять на существующие данные!')) {
        fetch('/lks/php/admin/delete-boat.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                boat_code: boatCode
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Класс лодки "' + boatCode + '" успешно удален');
                location.reload();
            } else {
                alert('Ошибка: ' + (data.message || 'Не удалось удалить класс лодки'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Ошибка при удалении класса лодки');
        });
    }
}
</script>

<?php
function getBoatDescription($boat) {
    // Сначала пытаемся загрузить описание из файла
    $descriptionsFile = __DIR__ . '/../../files/boat_description/boat_descriptions.json';
    
    if (file_exists($descriptionsFile)) {
        $content = file_get_contents($descriptionsFile);
        if ($content) {
            $descriptions = json_decode($content, true);
            if (isset($descriptions[$boat])) {
                return $descriptions[$boat];
            }
        }
    }
    
    // Если нет пользовательского описания, используем дефолтное
    require_once __DIR__ . '/../../php/helpers.php';
    return generateBoatDescription($boat);
}

function getPopularityColor($popularity) {
    if ($popularity >= 50) return 'success';
    if ($popularity >= 25) return 'warning';
    if ($popularity >= 10) return 'info';
    return 'secondary';
}

include '../includes/footer.php';
?> 
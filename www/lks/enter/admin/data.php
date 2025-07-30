<?php
/**
 * Техническая страница администратора - Работа с данными
 */

// Проверка авторизации
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Admin' && $_SESSION['user_role'] !== 'SuperUser')) {
    header('Location: /lks/login.php');
    exit;
}

require_once '../../php/db/Database.php';

// Получаем список мероприятий для селекта
try {
    $db = Database::getInstance();
    $events = $db->query("SELECT champn, meroname FROM meros ORDER BY champn DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Data page error: " . $e->getMessage());
    $events = [];
}

include '../includes/header.php';
?>

<style>
.alert {
    margin-bottom: 1.5rem;
}

.card {
    margin-bottom: 2rem;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
}

.card-header {
    font-weight: 600;
}

.progress {
    height: 1.5rem;
}

pre {
    background-color: #f8f9fa !important;
    border: 1px solid #dee2e6 !important;
    border-radius: 0.375rem !important;
    padding: 1rem !important;
    font-size: 0.875rem;
}
</style>

<!-- Заголовок страницы -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Работа с данными</h1>
    <div class="btn-group">
        <button class="btn btn-primary" onclick="location.reload()">
            <i class="bi bi-arrow-clockwise me-1"></i>Обновить
        </button>
    </div>
</div>

<div class="alert alert-warning" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong>Внимание!</strong> Данная страница предназначена для выполнения технических операций с данными. 
    Все операции выполняются необратимо. Убедитесь, что у вас есть резервные копии данных.
</div>

<!-- Импорт спортсменов -->
<div class="card">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-people-fill me-2"></i>Импорт спортсменов</h5>
    </div>
    <div class="card-body">
        <p class="text-muted">
            Загрузите CSV-файл со спортсменами для автоматического добавления в базу данных. 
            Файл должен содержать колонки: userid, email, fio, sex, telephone, birthdata, country, city, boats, sportzvanie.
            Поддержка Excel файлов будет добавлена позже.
        </p>
        
        <div class="mb-3">
            <label for="sportsmenFile" class="form-label">Выберите CSV-файл со спортсменами</label>
            <input type="file" class="form-control" id="sportsmenFile" accept=".csv">
        </div>
        
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary" onclick="importSportsmen()">
                <i class="bi bi-upload me-1"></i>Загрузить спортсменов
            </button>
            <button type="button" class="btn btn-outline-info" onclick="downloadTemplate('sportsmen')">
                <i class="bi bi-download me-1"></i>Скачать шаблон
            </button>
        </div>
        
        <div id="sportsmenProgress" class="mt-3" style="display: none;">
            <div class="progress">
                <div class="progress-bar" role="progressbar" style="width: 0%"></div>
            </div>
            <div class="mt-2" id="sportsmenStatus"></div>
        </div>
    </div>
</div>

<!-- Импорт регистраций -->
<div class="card">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0"><i class="bi bi-clipboard-check-fill me-2"></i>Импорт регистраций</h5>
    </div>
    <div class="card-body">
        <p class="text-muted">
            Загрузите Excel-файл с регистрациями на мероприятие. 
            Файл должен содержать данные о спортсменах и их регистрациях на различные дистанции.
        </p>
        
        <div class="row mb-3">
            <div class="col-md-6">
                <label for="eventSelect" class="form-label">Выберите мероприятие</label>
                <select class="form-select" id="eventSelect" required>
                    <option value="">Выберите мероприятие...</option>
                    <?php foreach ($events as $event): ?>
                    <option value="<?= $event['champn'] ?>"><?= htmlspecialchars($event['meroname']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label for="registrationsFile" class="form-label">Excel-файл с регистрациями</label>
                <input type="file" class="form-control" id="registrationsFile" accept=".xlsx,.xls" disabled>
                <div class="form-text">Сначала выберите мероприятие</div>
            </div>
        </div>
        
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-success" onclick="importRegistrations()">
                <i class="bi bi-upload me-1"></i>Загрузить регистрации
            </button>
            <button type="button" class="btn btn-outline-info" onclick="downloadTemplate('registrations')">
                <i class="bi bi-download me-1"></i>Скачать шаблон
            </button>
            <button type="button" class="btn btn-outline-secondary" onclick="showImportInstructions()">
                <i class="bi bi-question-circle me-1"></i>Инструкция
            </button>
        </div>
        
        <div id="registrationsProgress" class="mt-3" style="display: none;">
            <div class="progress">
                <div class="progress-bar bg-success" role="progressbar" style="width: 0%"></div>
            </div>
            <div class="mt-2" id="registrationsStatus"></div>
        </div>
    </div>
</div>

<!-- Экспорт данных -->
<div class="card">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0"><i class="bi bi-download me-2"></i>Экспорт данных</h5>
    </div>
    <div class="card-body">
        <p class="text-muted">
            Экспортируйте данные из системы в различных форматах для анализа или резервного копирования.
        </p>
        
        <div class="row">
            <div class="col-md-6">
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-outline-primary" onclick="exportData('users')">
                        <i class="bi bi-people me-1"></i>Экспорт пользователей
                    </button>
                    <button type="button" class="btn btn-outline-success" onclick="exportData('events')">
                        <i class="bi bi-calendar-event me-1"></i>Экспорт мероприятий
                    </button>
                    <button type="button" class="btn btn-outline-warning" onclick="exportData('registrations')">
                        <i class="bi bi-clipboard-check me-1"></i>Экспорт регистраций
                    </button>
                </div>
            </div>
            <div class="col-md-6">
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-outline-info" onclick="exportData('teams')">
                        <i class="bi bi-people-fill me-1"></i>Экспорт команд
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="exportData('statistics')">
                        <i class="bi bi-bar-chart me-1"></i>Экспорт статистики
                    </button>
                    <button type="button" class="btn btn-outline-dark" onclick="exportData('full')">
                        <i class="bi bi-database me-1"></i>Полный экспорт
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Системные операции -->
<div class="card">
    <div class="card-header bg-secondary text-white">
        <h5 class="mb-0"><i class="bi bi-gear-fill me-2"></i>Системные операции</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong>Опасно!</strong> Данные операции могут привести к потере данных. 
            Обязательно создайте резервную копию перед выполнением.
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-warning" onclick="createBackup()">
                        <i class="bi bi-shield-check me-1"></i>Создать резервную копию
                    </button>
                    <button type="button" class="btn btn-info" onclick="optimizeDatabase()">
                        <i class="bi bi-gear me-1"></i>Оптимизировать БД
                    </button>
                </div>
            </div>
            <div class="col-md-6">
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-outline-warning" onclick="cleanupTempFiles()">
                        <i class="bi bi-folder-x me-1"></i>Очистить временные файлы
                    </button>
                    <button type="button" class="btn btn-outline-danger" onclick="clearLogs()">
                        <i class="bi bi-trash me-1"></i>Очистить логи
                    </button>
                    <button type="button" class="btn btn-outline-danger" onclick="clearOldData()">
                        <i class="bi bi-broom me-1"></i>Очистить старые данные
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Результаты операций -->
<div id="operationResults" class="card" style="display: none;">
    <div class="card-header bg-dark text-white">
        <h5 id="operationTitle" class="mb-0"><i class="bi bi-terminal me-2"></i>Результаты операции</h5>
    </div>
    <div class="card-body">
        <pre id="operationOutput" style="max-height: 300px; overflow-y: auto;"></pre>
        <div class="mt-3">
            <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('operationResults').style.display='none'">
                <i class="bi bi-x-circle me-1"></i>Закрыть
            </button>
        </div>
    </div>
</div>

<script>
// Обработчик выбора мероприятия
document.addEventListener('DOMContentLoaded', function() {
    const eventSelect = document.getElementById('eventSelect');
    const registrationsFile = document.getElementById('registrationsFile');
    const fileHelpText = registrationsFile ? registrationsFile.nextElementSibling : null;
    
    if (eventSelect && registrationsFile) {
        // Функция для получения значения мероприятия
        function getEventValue(selectElement) {
            const value = selectElement.value;
            const index = selectElement.selectedIndex;
            const text = index > 0 ? selectElement.options[index].text : '';
            
            // Если есть value - используем его, иначе используем текст или индекс
            if (value && value.trim() !== '') {
                return value;
            } else if (index > 0 && text) {
                // Возвращаем текст мероприятия как идентификатор
                return text;
            }
            return '';
        }
        
        eventSelect.addEventListener('change', function() {
            // Исправленная проверка - если value пустое, но индекс > 0, используем индекс
            const eventValue = getEventValue(this);
            
            if (eventValue && this.selectedIndex > 0) {
                registrationsFile.disabled = false;
                registrationsFile.style.backgroundColor = '#ffffff';
                registrationsFile.style.cursor = 'pointer';
                
                // Сохраняем значение для использования в импорте
                eventSelect.dataset.selectedValue = eventValue;
                
                if (fileHelpText) {
                    fileHelpText.textContent = 'Выберите Excel файл с регистрациями';
                    fileHelpText.className = 'form-text text-success';
                }
            } else {
                registrationsFile.disabled = true;
                registrationsFile.value = '';
                registrationsFile.style.backgroundColor = '#e9ecef';
                registrationsFile.style.cursor = 'not-allowed';
                
                // Удаляем сохраненное значение
                delete eventSelect.dataset.selectedValue;
                
                if (fileHelpText) {
                    fileHelpText.textContent = 'Сначала выберите мероприятие';
                    fileHelpText.className = 'form-text text-muted';
                }
            }
        });
        
        // Обработчик выбора файла
        registrationsFile.addEventListener('change', function() {
            if (this.files.length > 0) {
                if (fileHelpText) {
                    fileHelpText.textContent = `Выбран файл: ${this.files[0].name}`;
                    fileHelpText.className = 'form-text text-info';
                }
            }
        });
        
        // Проверяем начальное состояние
        if (!eventSelect.value) {
            registrationsFile.disabled = true;
            registrationsFile.style.backgroundColor = '#e9ecef';
            registrationsFile.style.cursor = 'not-allowed';
        }
    }
});

function showProgress(progressId, statusId, message) {
    document.getElementById(progressId).style.display = 'block';
    document.getElementById(statusId).textContent = message;
}

function hideProgress(progressId) {
    document.getElementById(progressId).style.display = 'none';
}

function updateProgress(progressId, percent) {
    const progressBar = document.querySelector('#' + progressId + ' .progress-bar');
    progressBar.style.width = percent + '%';
}

function showResults(title, content) {
    document.getElementById('operationResults').style.display = 'block';
    document.getElementById('operationTitle').innerHTML = '<i class="bi bi-terminal me-2"></i>' + title;
    document.getElementById('operationOutput').textContent = content;
    
    // Прокручиваем к результатам
    document.getElementById('operationResults').scrollIntoView({ 
        behavior: 'smooth', 
        block: 'center' 
    });
}

function importSportsmen() {
    const fileInput = document.getElementById('sportsmenFile');
    
    if (!fileInput.files[0]) {
        alert('Выберите файл для загрузки');
        return;
    }
    
    showProgress('sportsmenProgress', 'sportsmenStatus', 'Загрузка файла...');
    
    const formData = new FormData();
    formData.append('file', fileInput.files[0]);
    formData.append('type', 'sportsmen');
    
    fetch('/lks/php/admin/import-sportsmen.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        updateProgress('sportsmenProgress', 100);
        
        // Скрываем прогресс-бар через 2 секунды
        setTimeout(() => {
            hideProgress('sportsmenProgress');
        }, 2000);
        
        if (data.success) {
            document.getElementById('sportsmenStatus').textContent = 'Импорт завершен успешно!';
            
            let resultMessage = `Успешно импортировано: ${data.imported || 0} записей\n`;
            resultMessage += `Обновлено: ${data.updated || 0} записей\n`;
            if (data.skipped && data.skipped > 0) {
                resultMessage += `Пропущено (уже существуют): ${data.skipped} записей\n`;
            }
            if (data.errors && data.errors > 0) {
                resultMessage += `Ошибок: ${data.errors}`;
            }
            
            // Если есть подробное сообщение от сервера, используем его
            if (data.message) {
                resultMessage = data.message;
            }
            
            showResults('Импорт спортсменов', resultMessage);
        } else {
            document.getElementById('sportsmenStatus').textContent = 'Ошибка импорта!';
            showResults('Импорт спортсменов', 'Ошибка: ' + data.message);
        }
    })
    .catch(error => {
        hideProgress('sportsmenProgress');
        document.getElementById('sportsmenStatus').textContent = 'Ошибка импорта!';
        showResults('Импорт спортсменов', 'Ошибка: ' + error.message);
    });
}

function importRegistrations() {
    const eventSelect = document.getElementById('eventSelect');
    const fileInput = document.getElementById('registrationsFile');
    
    // Получаем значение мероприятия (из value или из сохраненного значения)
    const eventValue = eventSelect.value || eventSelect.dataset.selectedValue;
    
    
    if (!eventValue) {
        alert('Выберите мероприятие');
        return;
    }
    
    if (!fileInput.files[0]) {
        alert('Выберите файл для загрузки');
        return;
    }
    
    showProgress('registrationsProgress', 'registrationsStatus', 'Загрузка регистраций...');
    
    const formData = new FormData();
    formData.append('file', fileInput.files[0]);
    formData.append('event', eventValue);
    formData.append('type', 'registrations');
    
    fetch('/lks/php/admin/import-registrations.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        updateProgress('registrationsProgress', 100);
        
        // Скрываем прогресс-бар через 2 секунды
        setTimeout(() => {
            hideProgress('registrationsProgress');
        }, 2000);
        
        if (data.success) {
            document.getElementById('registrationsStatus').textContent = 'Импорт регистраций завершен!';
            let resultMessage = `Мероприятие: ${data.event_name || 'N/A'}\n\n`;
            resultMessage += `Пользователи:\n`;
            resultMessage += `• Новых пользователей: ${data.imported_users || 0}\n`;
            resultMessage += `• Обновленных пользователей: ${data.updated_users || 0}\n\n`;
            resultMessage += `Регистрации:\n`;
            resultMessage += `• Успешно зарегистрировано: ${data.imported_registrations || 0}\n`;
            resultMessage += `• Ошибок: ${data.errors || 0}\n`;
            
            if (data.error_messages && data.error_messages.length > 0) {
                resultMessage += `\nОшибки:\n` + data.error_messages.slice(0, 5).join('\n');
                if (data.error_messages.length > 5) {
                    resultMessage += `\n... и еще ${data.error_messages.length - 5} ошибок`;
                }
            }
            
            showResults('Импорт регистраций', resultMessage);
        } else {
            document.getElementById('registrationsStatus').textContent = 'Ошибка импорта!';
            showResults('Импорт регистраций', 'Ошибка: ' + data.message);
        }
    })
    .catch(error => {
        hideProgress('registrationsProgress');
        document.getElementById('registrationsStatus').textContent = 'Ошибка импорта!';
        showResults('Импорт регистраций', 'Ошибка: ' + error.message);
    });
}

function downloadTemplate(type) {
    if (type === 'sportsmen' || type === 'registrations') {
        // Скачиваем динамически созданный Excel шаблон
        const link = document.createElement('a');
        link.href = '/lks/php/admin/download-template.php?type=' + type;
        link.target = '_blank';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    } else {
        alert('Шаблон не найден');
    }
}

function exportData(type) {
    showResults('Экспорт данных', 'Начинается экспорт данных типа: ' + type + '...');
    
    fetch('/lks/php/admin/export.php?type=' + type + '&format=csv', {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showResults('Экспорт данных', 
                `Экспорт создан успешно!\n` +
                `Тип: ${data.type}\n` +
                `Файл: ${data.filename}\n` +
                `Записей: ${data.records_count || 'Н/Д'}\n\n` +
                `Скачивание начнется автоматически...`
            );
            
            // Автоматически скачиваем файл
    const link = document.createElement('a');
            link.href = data.download_url;
            link.download = data.filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
        } else {
            showResults('Экспорт данных', 'Ошибка: ' + data.message);
        }
    })
    .catch(error => {
        showResults('Экспорт данных', 'Ошибка: ' + error.message);
    });
}

function createBackup() {
    if (confirm('Создать резервную копию базы данных?')) {
        showResults('Резервное копирование', 'Создание резервной копии...');
        
        fetch('/lks/php/admin/backup.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ action: 'create_backup' })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showResults('Резервное копирование', 'Резервная копия создана успешно!\nФайл: ' + data.filename);
            } else {
                showResults('Резервное копирование', 'Ошибка: ' + data.message);
            }
        })
        .catch(error => {
            showResults('Резервное копирование', 'Ошибка: ' + error.message);
        });
    }
}

function optimizeDatabase() {
    if (confirm('Оптимизировать базу данных? Это может занять некоторое время.')) {
        showResults('Оптимизация БД', 'Оптимизация базы данных...');
        alert('Оптимизация БД будет реализована позже');
    }
}

function clearLogs() {
    if (confirm('Очистить все логи системы?')) {
        showResults('Очистка логов', 'Очистка логов...');
        alert('Очистка логов будет реализована позже');
    }
}

function cleanupTempFiles() {
    if (confirm('Очистить временные файлы экспорта (старше 1 часа)?')) {
        showResults('Очистка временных файлов', 'Очистка временных файлов...');
        
        fetch('/lks/php/admin/cleanup-temp.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ max_age: 3600 })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showResults('Очистка временных файлов', 
                    `Очистка завершена успешно!\n` +
                    `Удалено файлов: ${data.deleted_count}\n` +
                    `Освобождено места: ${data.deleted_size_kb} КБ\n` +
                    `Ошибок: ${data.error_count}\n` +
                    `Осталось файлов: ${data.remaining_count}`
                );
            } else {
                showResults('Очистка временных файлов', 'Ошибка: ' + data.message);
            }
        })
        .catch(error => {
            showResults('Очистка временных файлов', 'Ошибка: ' + error.message);
        });
    }
}

function clearOldData() {
    if (confirm('Удалить старые данные (старше 2 лет)?')) {
        showResults('Очистка старых данных', 'Удаление старых данных...');
        alert('Очистка старых данных будет реализована позже');
    }
}

function showImportInstructions() {
    const instructionsContent = `
# Инструкция по импорту регистраций

## Формат файла
• Поддерживаются файлы Excel: .xlsx и .xls
• Максимальный размер: 10 МБ

## Структура данных

### Столбцы A-J: Данные пользователей
• A - № п/п
• B - ID пользователя (обязательно, число >= 1000)  
• C - ФИО (обязательно)
• D - Год рождения
• E - Спортивное звание (БР, КМС, МС)
• F - Город (формат: "Страна, Город")
• G - Пол (обязательно, М/Ж)
• H - Email
• I - Номер телефона
• J - Дата рождения (дд.мм.гггг)

### Столбцы K+: Регистрации
• Строка 1: Классы лодок (К1, К2, D10M, D10W)
• Строка 2: Дистанции (200, 500, 1000, 2000)
• Строка 3+: Отметки участия

## Логика обработки

### Одиночные дисциплины (К1, С1)
✓ Все дистанции группируются в одну запись
✓ Статус: "В очереди"

### Групповые дисциплины (К2, D10M, D10W)  
✓ Каждая дистанция - отдельная запись
✓ Статус: "Ожидание команды"
✓ Автоматическое создание команды

## Рекомендации
1. Скачайте шаблон перед заполнением
2. Проверьте уникальность ID пользователей
3. Сделайте резервную копию перед импортом
4. Тестируйте на небольших файлах

Подробная инструкция: /lks/files/template/README_import_instructions.md
    `;
    
    showResults('Инструкция по импорту регистраций', instructionsContent);
}
</script>

<?php include '../includes/footer.php'; ?> 
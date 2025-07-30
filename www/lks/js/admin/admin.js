/**
 * Скрипты для панели администратора
 * KGB-Pulse - Система управления гребной базой
 */

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    initializeAdminPanel();
});

/**
 * Инициализация панели администратора
 */
function initializeAdminPanel() {
    // Загрузка статистики
    loadSystemStats();
    
    // Инициализация обработчиков событий
    initializeEventHandlers();
    
    // Обновление статистики каждые 30 секунд
    setInterval(loadSystemStats, 30000);
}

/**
 * Инициализация обработчиков событий
 */
function initializeEventHandlers() {
    // Управление пользователями
    const userRoleSelects = document.querySelectorAll('.user-role-select');
    userRoleSelects.forEach(select => {
        select.addEventListener('change', function() {
            changeUserRole(this.dataset.userid, this.value);
        });
    });

    // Файловый менеджер
    const fileActions = document.querySelectorAll('.file-action');
    fileActions.forEach(button => {
        button.addEventListener('click', function() {
            handleFileAction(this.dataset.action, this.dataset.file);
        });
    });

    // Загрузка Excel файлов
    const excelUpload = document.getElementById('excel-upload');
    if (excelUpload) {
        excelUpload.addEventListener('change', handleExcelUpload);
    }
}

/**
 * Загрузка системной статистики
 */
async function loadSystemStats() {
    try {
        const response = await fetch('/lks/php/admin/get_stats.php');
        const data = await response.json();
        
        if (data.success) {
            updateStatsDisplay(data);
        }
    } catch (error) {
        console.error('Ошибка загрузки статистики:', error);
    }
}

/**
 * Обновление отображения статистики
 */
function updateStatsDisplay(data) {
    // Обновляем карточки статистики
    const statsElements = {
        'total-users': data.users?.total || 0,
        'total-events': data.events?.total || 0,
        'total-registrations': data.registrations?.total || 0,
        'active-events': Object.values(data.events?.by_status || {}).reduce((sum, count) => sum + count, 0)
    };

    Object.entries(statsElements).forEach(([id, value]) => {
        const element = document.getElementById(id);
        if (element) {
            animateNumber(element, parseInt(value) || 0);
        }
    });

    // Обновляем системную информацию
    updateSystemInfo(data.system);
}

/**
 * Анимация изменения числа
 */
function animateNumber(element, targetValue) {
    const currentValue = parseInt(element.textContent) || 0;
    const increment = (targetValue - currentValue) / 20;
    let currentStep = 0;

    const animation = setInterval(() => {
        currentStep++;
        const newValue = Math.round(currentValue + increment * currentStep);
        element.textContent = newValue;

        if (currentStep >= 20) {
            element.textContent = targetValue;
            clearInterval(animation);
        }
    }, 50);
}

/**
 * Обновление системной информации
 */
function updateSystemInfo(systemInfo) {
    if (!systemInfo) return;

    // Обновляем статус сервисов
    updateServiceStatus('nginx', systemInfo.nginx || 'unknown');
    updateServiceStatus('php', systemInfo.php || 'unknown');
    updateServiceStatus('postgres', systemInfo.postgres || 'unknown');
    updateServiceStatus('redis', systemInfo.redis || 'unknown');

    // Обновляем информацию о диске
    const diskUsage = document.getElementById('disk-usage');
    if (diskUsage && systemInfo.diskUsage) {
        diskUsage.textContent = systemInfo.diskUsage;
    }

    // Обновляем загрузку системы
    const loadAverage = document.getElementById('load-average');
    if (loadAverage && systemInfo.loadAverage) {
        loadAverage.textContent = systemInfo.loadAverage;
    }
}

/**
 * Обновление статуса сервиса
 */
function updateServiceStatus(serviceName, status) {
    const statusElement = document.getElementById(`${serviceName}-status`);
    if (!statusElement) return;

    // Удаляем старые классы
    statusElement.classList.remove('bg-success', 'bg-danger', 'bg-warning');
    
    // Добавляем новый класс в зависимости от статуса
    switch (status) {
        case 'running':
            statusElement.classList.add('bg-success');
            statusElement.textContent = 'Работает';
            break;
        case 'stopped':
            statusElement.classList.add('bg-danger');
            statusElement.textContent = 'Остановлен';
            break;
        case 'warning':
            statusElement.classList.add('bg-warning');
            statusElement.textContent = 'Предупреждение';
            break;
        default:
            statusElement.classList.add('bg-secondary');
            statusElement.textContent = 'Неизвестно';
    }
}

/**
 * Показать уведомление пользователю
 */
function showNotification(message, type = 'info') {
    // Типы: success, error, warning, info
    let alertClass = 'alert-info';
    let icon = 'bi-info-circle';
    
    switch (type) {
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
    notification.className = `alert ${alertClass} alert-dismissible fade show position-fixed`;
    notification.style.top = '20px';
    notification.style.right = '20px';
    notification.style.zIndex = '9999';
    notification.style.minWidth = '300px';
    notification.innerHTML = `
        <i class="bi ${icon} me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    // Добавляем в body
    document.body.appendChild(notification);
    
    // Автоматически скрываем через 5 секунд
    setTimeout(() => {
        if (notification && notification.parentNode) {
            notification.classList.remove('show');
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 300);
        }
    }, 5000);
}

/**
 * Изменение роли пользователя (обратная совместимость)
 */
function changeRole(userId, newRole) {
    changeUserRole(userId, newRole);
}

/**
 * Изменение роли пользователя
 */
async function changeUserRole(userId, newRole) {
    try {
        const response = await fetch('/lks/php/admin/change-role.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCSRFToken()
            },
            body: JSON.stringify({
                userId: userId,
                newRole: newRole
            })
        });

        const data = await response.json();
        
        if (data.success) {
            showNotification('Роль пользователя успешно изменена', 'success');
            // Перезагружаем страницу для обновления данных
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification(data.message || 'Ошибка изменения роли', 'error');
        }
    } catch (error) {
        console.error('Ошибка изменения роли:', error);
        showNotification('Ошибка изменения роли пользователя', 'error');
    }
}

/**
 * Обработка действий с файлами
 */
function handleFileAction(action, filePath) {
    switch (action) {
        case 'download':
            downloadFile(filePath);
            break;
        case 'rename':
            renameFile(filePath);
            break;
        case 'delete':
            deleteFile(filePath);
            break;
        case 'view':
            viewFile(filePath);
            break;
    }
}

/**
 * Скачивание файла
 */
function downloadFile(filePath) {
    const link = document.createElement('a');
    link.href = `/lks/php/admin/download-file.php?file=${encodeURIComponent(filePath)}`;
    link.download = '';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

/**
 * Переименование файла
 */
async function renameFile(filePath) {
    const fileName = filePath.split('/').pop();
    const newName = prompt('Введите новое имя файла:', fileName);
    
    if (!newName || newName === fileName) return;

    try {
        const response = await fetch('/lks/php/admin/rename-file.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCSRFToken()
            },
            body: JSON.stringify({
                old_path: filePath,
                new_name: newName
            })
        });

        const data = await response.json();
        
        if (data.success) {
            showNotification('Файл успешно переименован', 'success');
            location.reload();
        } else {
            showNotification(data.message || 'Ошибка переименования файла', 'error');
        }
    } catch (error) {
        console.error('Ошибка переименования:', error);
        showNotification('Ошибка переименования файла', 'error');
    }
}

/**
 * Удаление файла
 */
async function deleteFile(filePath) {
    if (!confirm('Вы уверены, что хотите удалить этот файл?')) return;

    try {
        const response = await fetch('/lks/php/admin/delete-file.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCSRFToken()
            },
            body: JSON.stringify({
                file_path: filePath
            })
        });

        const data = await response.json();
        
        if (data.success) {
            showNotification('Файл успешно удален', 'success');
            location.reload();
        } else {
            showNotification(data.message || 'Ошибка удаления файла', 'error');
        }
    } catch (error) {
        console.error('Ошибка удаления:', error);
        showNotification('Ошибка удаления файла', 'error');
    }
}

/**
 * Просмотр файла
 */
function viewFile(filePath) {
    const extension = filePath.split('.').pop().toLowerCase();
    
    if (['jpg', 'jpeg', 'png', 'gif', 'pdf'].includes(extension)) {
        // Открываем в новом окне
        window.open(`/lks/files/${filePath}`, '_blank');
    } else {
        // Скачиваем файл
        downloadFile(filePath);
    }
}

/**
 * Обработка загрузки Excel файлов
 */
function handleExcelUpload(event) {
    const file = event.target.files[0];
    if (!file) return;

    // Проверяем тип файла
    if (!file.name.toLowerCase().endsWith('.xlsx')) {
        showNotification('Пожалуйста, выберите файл в формате .xlsx', 'error');
        return;
    }

    // Показываем прогресс
    showUploadProgress(true);

    const formData = new FormData();
    formData.append('excel_file', file);
    formData.append('action', document.getElementById('upload-action').value);

    fetch('/lks/php/admin/process-excel.php', {
        method: 'POST',
        headers: {
            'X-CSRF-Token': getCSRFToken()
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        showUploadProgress(false);
        
        if (data.success) {
            showNotification(data.message, 'success');
            displayProcessingResults(data.results);
        } else {
            showNotification(data.message || 'Ошибка обработки файла', 'error');
        }
    })
    .catch(error => {
        console.error('Ошибка загрузки:', error);
        showUploadProgress(false);
        showNotification('Ошибка загрузки файла', 'error');
    });
}

/**
 * Показ/скрытие прогресса загрузки
 */
function showUploadProgress(show) {
    const progressBar = document.getElementById('upload-progress');
    if (progressBar) {
        progressBar.style.display = show ? 'block' : 'none';
    }
}

/**
 * Отображение результатов обработки
 */
function displayProcessingResults(results) {
    const resultsContainer = document.getElementById('processing-results');
    if (!resultsContainer || !results) return;

    let html = '<div class="alert alert-info"><h6>Результаты обработки:</h6>';
    
    if (results.added) {
        html += `<p><i class="bi bi-check-circle text-success"></i> Добавлено записей: ${results.added}</p>`;
    }
    
    if (results.updated) {
        html += `<p><i class="bi bi-arrow-repeat text-primary"></i> Обновлено записей: ${results.updated}</p>`;
    }
    
    if (results.errors && results.errors.length > 0) {
        html += `<p><i class="bi bi-exclamation-triangle text-warning"></i> Ошибки: ${results.errors.length}</p>`;
        html += '<ul>';
        results.errors.forEach(error => {
            html += `<li class="text-danger">${error}</li>`;
        });
        html += '</ul>';
    }
    
    html += '</div>';
    
    resultsContainer.innerHTML = html;
}

/**
 * Получение CSRF токена
 */
function getCSRFToken() {
    const token = document.querySelector('meta[name="csrf-token"]');
    return token ? token.getAttribute('content') : '';
}

/**
 * Экспорт данных
 */
async function exportData(type) {
    try {
        // Показываем уведомление о начале экспорта
        showNotification('Создание экспорта...', 'info');
        
        // Отправляем запрос на создание экспорта
        const response = await fetch(`/lks/php/admin/export.php?type=${type}&format=csv`);
        const data = await response.json();
        
        if (data.success && data.download_url) {
            // Скачиваем файл по полученной ссылке
            const link = document.createElement('a');
            link.href = data.download_url;
            link.download = '';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showNotification(data.message || 'Экспорт готов к скачиванию', 'success');
        } else {
            showNotification(data.message || 'Ошибка создания экспорта', 'error');
        }
    } catch (error) {
        console.error('Ошибка экспорта:', error);
        showNotification('Ошибка при создании экспорта', 'error');
    }
}

/**
 * Создание резервной копии
 */
async function createBackup() {
    try {
        showNotification('Создание резервной копии...', 'info');
        
        const response = await fetch('/lks/php/admin/backup.php', {
            method: 'POST',
            headers: {
                'X-CSRF-Token': getCSRFToken()
            }
        });

        const data = await response.json();
        
        if (data.success) {
            showNotification('Резервная копия успешно создана', 'success');
        } else {
            showNotification(data.message || 'Ошибка создания резервной копии', 'error');
        }
    } catch (error) {
        console.error('Ошибка создания резервной копии:', error);
        showNotification('Ошибка создания резервной копии', 'error');
    }
}

/**
 * Очистка системных логов
 */
async function clearLogs() {
    if (!confirm('Вы уверены, что хотите очистить системные логи?')) return;

    try {
        const response = await fetch('/lks/php/admin/clear-logs.php', {
            method: 'POST',
            headers: {
                'X-CSRF-Token': getCSRFToken()
            }
        });

        const data = await response.json();
        
        if (data.success) {
            showNotification('Логи успешно очищены', 'success');
        } else {
            showNotification(data.message || 'Ошибка очистки логов', 'error');
        }
    } catch (error) {
        console.error('Ошибка очистки логов:', error);
        showNotification('Ошибка очистки логов', 'error');
    }
}

/**
 * Исправление ролей в командах драконов D-10
 * Автоматически распределяет роли в существующих командах
 */
async function fixDragonTeamRoles() {
    // Подтверждение действия
    if (!confirm('Исправить роли во всех командах драконов D-10?\n\nЭто действие автоматически назначит:\n• Первого участника - Капитаном\n• Следующих 9 - Гребцами\n• Остальных - Резервистами\n\nПродолжить?')) {
        return;
    }
    
    try {
        // Показываем индикатор загрузки
        const button = event.target;
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="spinner-border spinner-border-sm me-2"></i>Исправление...';
        button.disabled = true;
        
        // Отправляем запрос на исправление ролей
        const response = await fetch('/lks/php/admin/fix_existing_dragon_teams.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Показываем успешное сообщение с деталями
            let message = `✅ Роли исправлены успешно!\n\n`;
            message += `📊 Статистика:\n`;
            message += `• Найдено команд драконов: ${data.statistics.total_teams_found}\n`;
            message += `• Исправлено команд: ${data.statistics.fixed_teams}\n`;
            message += `• Обновлено участников: ${data.statistics.updated_participants}`;
            
            if (data.statistics.errors > 0) {
                message += `\n• Ошибок: ${data.statistics.errors}`;
            }
            
            showNotification(message, 'success');
            
            // Перезагружаем страницу через 3 секунды для обновления данных
            setTimeout(() => {
                location.reload();
            }, 3000);
            
        } else {
            showNotification('❌ Ошибка исправления ролей: ' + data.message, 'error');
        }
        
    } catch (error) {
        console.error('Ошибка исправления ролей:', error);
        showNotification('❌ Системная ошибка при исправлении ролей', 'error');
    } finally {
        // Восстанавливаем кнопку
        if (button) {
            button.innerHTML = originalText;
            button.disabled = false;
        }
    }
} 
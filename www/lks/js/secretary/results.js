// Глобальные переменные
let currentMeroId = null;

// Инициализация при загрузке страницы
$(document).ready(function() {
    // Получаем ID мероприятия из URL
    currentMeroId = getMeroIdFromUrl();
    if (currentMeroId) {
        loadResults(currentMeroId);
    }
    
    // Обработчики событий для кнопок
    $('#downloadPrizesBtn').click(downloadPrizes);
    $('#downloadTechnicalBtn').click(downloadTechnicalResults);
    $('#finishEventBtn').click(showFinishConfirmation);
    $('#confirmFinishBtn').click(finishEvent);
});

// Загрузка результатов
function loadResults(meroId) {
    $.ajax({
        url: '/lks/php/secretary/get_results.php',
        method: 'POST',
        data: JSON.stringify({ mero_id: meroId }),
        contentType: 'application/json',
        success: function(response) {
            if (response.success) {
                displayResults(response.results);
            } else {
                showError(response.message);
            }
        },
        error: function(xhr) {
            showError('Ошибка загрузки результатов');
        }
    });
}

// Отображение результатов в таблице
function displayResults(results) {
    const tbody = $('#resultsTable tbody');
    tbody.empty();
    
    results.forEach(result => {
        const tr = $('<tr>');
        
        tr.append($('<td>').text(result.place));
        tr.append($('<td>').text(result.fio));
        tr.append($('<td>').text(result.birthYear));
        tr.append($('<td>').text(result.ageGroup));
        tr.append($('<td>').text(result.team || '-'));
        tr.append($('<td>').text(result.city));
        tr.append($('<td>').text(result.discipline));
        tr.append($('<td>').text(result.distance + 'м'));
        tr.append($('<td>').text(result.semifinalTime || '-'));
        tr.append($('<td>').text(result.finalTime || '-'));
        
        tbody.append(tr);
    });
}

// Скачивание списка призёров
function downloadPrizes() {
    window.location.href = `/lks/php/secretary/download_prizes.php?mero_id=${currentMeroId}`;
}

// Скачивание технических результатов
function downloadTechnicalResults() {
    // Создаем скрытую форму для POST-запроса
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/lks/php/secretary/download_technical_results.php';
    form.target = '_blank';
    
    // Добавляем параметры как скрытые поля
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'mero_id';
    input.value = currentMeroId;
    form.appendChild(input);
    
    // Добавляем форму в DOM и отправляем
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

// Показ модального окна подтверждения завершения
function showFinishConfirmation() {
    $('#confirmFinishModal').modal('show');
}

// Завершение мероприятия
function finishEvent() {
    $.ajax({
        url: '/lks/php/secretary/finish_event.php',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            meroId: currentMeroId
        }),
        success: function(response) {
            if (response.success) {
                showSuccess('Мероприятие успешно завершено');
                $('#confirmFinishModal').modal('hide');
                // Перенаправляем на страницу мероприятий
                window.location.href = '/lks/enter/secretary/';
            } else {
                showError(response.message);
            }
        },
        error: function(xhr) {
            showError('Ошибка завершения мероприятия');
        }
    });
}

// Вспомогательные функции
function showError(message) {
    alert(message); // Можно заменить на более красивое уведомление
}

function showSuccess(message) {
    alert(message); // Можно заменить на более красивое уведомление
}

function getMeroIdFromUrl() {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get('mero_id');
} 
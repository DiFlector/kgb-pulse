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
    <link href="/lks/css/style.css?v=<?php echo time(); ?>" rel="stylesheet">
    <style>
        .protocols-container {
            padding: 20px;
        }
        /* Убираем внутренние горизонтальные паддинги у колонок, чтобы убрать вертикальную серую полосу между панелями */
        .protocols-row > [class^="col-"],
        .protocols-row > [class*=" col-"] {
            padding-left: 0 !important;
            padding-right: 0 !important;
        }
        /* Обеспечение одинаковой высоты контейнеров протоколов */
        .row {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start; /* не растягиваем колонки по высоте */
        }
        .row > .col-md-6 {
            display: flex;
            flex-direction: column;
        }
        .protocol-panel {
            display: flex;
            flex-direction: column;
            height: auto; /* убираем фиксированную высоту панели */
            min-height: 0; /* без принудительного растягивания */
        }
        .protocols-content {
            flex: 0 0 auto; /* высота ровно по содержимому */
            display: flex;
            flex-direction: column;
            overflow-y: visible; /* контент без внутренней прокрутки, чтобы не оставался пустой блок */
            min-height: 0; /* позволяем контейнеру уменьшаться по содержимому */
        }
        /* Обеспечение одинаковой высоты групп протоколов */
        .protocol-group {
            flex: 0 0 auto;
            display: flex;
            flex-direction: column;
            min-height: 0; /* без лишнего вертикального пространства */
        }
        /* Синхронизация высоты соответствующих групп протоколов */
        .protocols-content {
            display: flex;
            flex-direction: column;
        }
        .protocols-content .protocol-group {
            flex: 0 0 auto;
            display: flex;
            flex-direction: column;
            margin-bottom: 20px;
        }
        /* Не обнуляем отступ у последней группы, чтобы между первой и второй колонкой был такой же зазор */
        /* Обеспечение одинаковой высоты для соответствующих групп */
        .protocol-group {
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 20px;
            min-height: 0; /* без фиксированной высоты */
        }
        /* Синхронизация высоты таблиц */
        .protocol-table {
            min-height: auto; /* Убираем фиксированную минимальную высоту */
        }
        .protocol-table tbody {
            min-height: auto; /* Убираем фиксированную минимальную высоту */
        }
        /* Дополнительные стили для синхронизации */
        .protocol-panel {
            height: auto; /* не растягиваем панель на всю высоту колонки */
            display: flex;
            flex-direction: column;
        }
        .protocols-content {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .protocol-group {
            flex: 0 0 auto; /* не занимать оставшееся пространство */
            display: flex;
            flex-direction: column;
        }
        .age-group {
            flex: 0 0 auto; /* не растягивать блок возрастной группы */
            display: flex;
            flex-direction: column;
            width: 100%; /* Обеспечиваем полную ширину */
            margin-bottom: 15px;
            box-sizing: border-box; /* Учитываем границы в ширине */
        }
        .table-responsive {
            flex: 1;
            display: block; /* таблица внутри прокручивается, контейнер не флексит её */
        }
        .protocol-table {
            display: table; /* стандартное поведение таблицы */
            width: 100%;
        }
        .protocol-table tbody {
            display: table-row-group; /* вернуть стандартную модель таблицы */
        }
        /* Стили для пустых таблиц */
        .protocol-table tbody tr td[colspan] {
            padding: 30px 20px;
            text-align: center;
            color: #6c757d;
            font-style: italic;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
        }
        /* Убираем лишние отступы для пустых таблиц */
        .protocol-table:has(tbody tr td[colspan]) {
            min-height: auto;
        }
        .protocol-table:has(tbody tr td[colspan]) tbody {
            min-height: auto;
        }
        /* Дополнительные стили для компактности */
        .protocol-group:has(.protocol-table tbody tr td[colspan]) {
            min-height: auto;
        }
        .age-group:has(.protocol-table tbody tr td[colspan]) {
            min-height: auto;
        }
        /* Убираем лишние отступы для пустых групп */
        .protocol-group:has(.protocol-table tbody tr td[colspan]) .table-responsive {
            min-height: auto;
        }
        /* Медиа-запрос для мобильных устройств */
        @media (max-width: 768px) {
            .row {
                display: block;
            }
            .row > .col-md-6 {
                display: block;
            }
            .protocol-panel {
                height: auto;
                min-height: auto;
            }
            .protocols-content {
                min-height: auto;
            }
            .protocol-group {
                min-height: auto;
            }
        }
        .event-info-panel {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        /* Стили для уменьшения промежутка между кнопками */
        .btn-group {
            display: flex !important;
            gap: 8px !important;
            justify-content: flex-end !important;
        }
        
        .btn-group .btn {
            margin: 0 !important;
            flex-shrink: 0 !important;
            /* Увеличиваем кнопки в 1.2 раза */
            max-width: 240px !important;
            min-width: 240px !important;
            width: auto !important;
            padding: 10px 14px !important;
            font-size: 1.02rem !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }
        
        /* Уменьшаем промежуток между группами кнопок */
        .btn-group + .btn-group {
            margin-top: 8px !important;
        }
        
        /* Дополнительные стили для компактности */
        .btn-group .btn i {
            font-size: 0.8rem !important;
            margin-right: 4px !important;
        }
        
        /* Принудительное уменьшение размера кнопок */
        .event-info-panel .btn-group .btn {
            max-width: 192px !important;
            min-width: 144px !important;
            padding: 7px 12px !important;
            font-size: 0.96rem !important;
        }
        
        /* Специальный класс для компактных кнопок */
        .compact-btn {
            max-width: 180px !important;
            min-width: 120px !important;
            padding: 7px 10px !important;
            font-size: 0.9rem !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }
        
        .compact-btn i {
            font-size: 0.84rem !important;
            margin-right: 4px !important;
        }
        
        /* Адаптивность для мобильных устройств */
        @media (max-width: 768px) {
            .btn-group .btn {
                max-width: 168px !important;
                min-width: 120px !important;
                font-size: 0.9rem !important;
                padding: 6px 10px !important;
            }
        }
        .protocol-panel {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            height: auto; /* не растягивать по высоте */
            width: 100%; /* Обеспечиваем полную ширину */
            box-sizing: border-box; /* Учитываем границы в ширине */
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
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            width: 100%; /* Обеспечиваем полную ширину */
            padding: 0; /* Убираем лишние отступы */
            box-sizing: border-box; /* Учитываем границы в ширине */
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
            width: 100%; /* Обеспечиваем растягивание на всю ширину */
        }
        .protocol-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            white-space: normal; /* Разрешаем перенос в 2 строки */
            padding: 8px 12px; /* Увеличиваем отступы для лучшей читаемости */
            text-align: center; /* Выравниваем заголовки по центру */
            overflow: hidden; /* Скрываем переполнение */
            text-overflow: ellipsis; /* Показываем многоточие при переполнении */
            line-height: 1.1;
            min-height: 36px; /* визуально 2 строки при необходимости */
        }
        .protocol-table td {
            vertical-align: middle; /* Выравнивание содержимого по центру */
            padding: 8px 12px; /* Увеличиваем отступы для лучшей читаемости */
            word-wrap: break-word; /* Разрешаем перенос длинного текста */
            overflow-wrap: break-word; /* Совместимость с разными браузерами */
            white-space: nowrap; /* Предотвращаем перенос в ячейках */
            overflow: hidden; /* Скрываем переполнение */
            text-overflow: ellipsis; /* Показываем многоточие при переполнении */
        }
        .protocol-title {
            color: #007bff;
            font-weight: bold;
            margin-bottom: 15px;
            width: 100%; /* Растягиваем заголовок на всю ширину */
            display: block; /* Обеспечиваем блочное отображение */
            word-wrap: break-word; /* Разрешаем перенос длинных заголовков */
            overflow-wrap: break-word; /* Совместимость с разными браузерами */
        }
        .protocol-group {
            flex: 0 0 auto;
            display: flex;
            flex-direction: column;
            min-height: 0; /* убираем принудительную высоту */
            width: 100%; /* Обеспечиваем полную ширину */
            margin-bottom: 20px;
            box-sizing: border-box; /* Учитываем границы в ширине */
        }
        /* Синхронизация высоты групп протоколов */
        .protocols-content .protocol-group {
            margin-bottom: 24px; /* единый вертикальный отступ между всеми протоколами */
        }
        .protocols-content .protocol-group:last-child {
            margin-bottom: 0;
        }
        /* Синхронизация высоты групп протоколов */
        .protocols-content {
            display: flex;
            flex-direction: column;
        }
        .protocols-content .protocol-group {
            flex: 0 0 auto; /* не растягиваем группу по высоте */
            display: flex;
            flex-direction: column;
        }
        /* Обеспечение одинаковой высоты для соответствующих групп */
        .protocol-group {
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 20px;
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
            width: 100%; /* Растягиваем заголовок возрастной группы */
            word-wrap: break-word; /* Разрешаем перенос длинных заголовков */
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
        .completed-finish-protocol {
            background-color: #d4edda !important;
            border: 2px solid #28a745 !important;
            border-radius: 8px;
        }
        .completed-finish-protocol .table {
            background-color: #d4edda !important;
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
        /* Исправления для таблиц протоколов */
        .table-responsive {
            width: 100%; /* Полная ширина */
            overflow-x: auto; /* Горизонтальная прокрутка при необходимости */
            overflow-y: hidden; /* Исключаем выход за рамки по вертикали */
            margin-bottom: 10px; /* Отступ снизу */
            display: block; /* Блочное отображение */
            border: 1px solid #dee2e6; /* Граница */
            border-radius: 4px; /* Скругляем углы */
        }
        .protocol-table {
            table-layout: fixed; /* Фиксированная ширина столбцов */
            width: 100%; /* Полная ширина таблицы */
            min-width: 100%; /* Не выходим за пределы контейнера */
            border-collapse: collapse; /* Убираем двойные границы */
        }
        /* Стили для пустых таблиц с правильным colspan */
        .protocol-table tbody tr td[colspan] {
            padding: 40px 20px;
            text-align: center;
            color: #6c757d;
            font-style: italic;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            font-size: 0.95rem;
            vertical-align: middle;
            width: 100%; /* Растягиваем на всю ширину */
            box-sizing: border-box; /* Учитываем границы в ширине */
        }
        /* Обеспечиваем правильное отображение заголовков таблиц */
        .protocol-table thead th {
            position: sticky;
            top: 0;
            z-index: 10;
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap; /* Предотвращаем перенос текста в заголовках */
            padding: 8px 12px; /* Увеличиваем отступы для лучшей читаемости */
            text-align: center; /* Выравниваем заголовки по центру */
            border: 1px solid #dee2e6; /* Добавляем границы для лучшей видимости */
            box-sizing: border-box; /* Учитываем границы в ширине */
        }
        /* Стили для заголовков с разной шириной */
        .protocol-table thead th:nth-child(1) { /* Первая колонка (Вода/Место) */
            width: 10%;
            min-width: 70px;
        }
        .protocol-table thead th:nth-child(2) { /* Вторая колонка (Номер спортсмена/Время финиша) */
            width: 15%;
            min-width: 90px;
        }
        .protocol-table thead th:nth-child(3) { /* Третья колонка (ФИО/Вода) */
            width: 30%;
            min-width: 150px;
        }
        .protocol-table thead th:nth-child(4) { /* Четвертая колонка (Дата рождения/Номер спортсмена) */
            width: 15%;
            min-width: 100px;
        }
        .protocol-table thead th:nth-child(5) { /* Пятая колонка (Спортивный разряд/ФИО) */
            width: 15%;
            min-width: 100px;
        }
        .protocol-table thead th:nth-child(6) { /* Шестая колонка (Действия/Дата рождения) */
            width: 10%;
            min-width: 80px;
        }
        .protocol-table thead th:nth-child(7) { /* Седьмая колонка (Спортивный разряд) */
            width: 5%;
            min-width: 60px;
        }
        /* Обеспечиваем правильное отображение строк таблицы */
        .protocol-table tbody tr {
            width: 100%; /* Растягиваем строки на всю ширину */
        }
        /* Обеспечиваем правильное отображение ячеек таблицы */
        .protocol-table tbody td {
            width: 100%; /* Растягиваем ячейки на всю ширину */
            box-sizing: border-box; /* Учитываем границы в ширине */
            border: 1px solid #dee2e6; /* Добавляем границы для лучшей видимости */
        }
        /* Стили для кнопок под таблицами */
        .btn-group {
            width: 100%; /* Растягиваем группу кнопок */
            justify-content: flex-start; /* Выравниваем по левому краю */
        }
        /* Стили для ячеек с разным содержимым */
        .protocol-table tbody td:nth-child(1) { /* Первая колонка (Вода/Место) */
            width: 10%;
            min-width: 70px;
            text-align: center;
        }
        .protocol-table tbody td:nth-child(2) { /* Вторая колонка (Номер спортсмена/Время финиша) */
            width: 15%;
            min-width: 90px;
            text-align: center;
        }
        .protocol-table tbody td:nth-child(3) { /* Третья колонка (ФИО/Вода) */
            width: 30%;
            min-width: 150px;
            text-align: left;
            white-space: normal; /* Разрешаем перенос для ФИО */
            word-wrap: break-word; /* Разрешаем перенос длинных имен */
        }
        .protocol-table tbody td:nth-child(4) { /* Четвертая колонка (Дата рождения/Номер спортсмена) */
            width: 15%;
            min-width: 100px;
            text-align: center;
        }
        .protocol-table tbody td:nth-child(5) { /* Пятая колонка (Спортивный разряд/ФИО) */
            width: 15%;
            min-width: 100px;
            text-align: center;
        }
        .protocol-table tbody td:nth-child(6) { /* Шестая колонка (Действия/Дата рождения) */
            width: 10%;
            min-width: 80px;
            text-align: center;
        }
        .protocol-table tbody td:nth-child(7) { /* Седьмая колонка (Спортивный разряд) */
            width: 5%;
            min-width: 60px;
            text-align: center;
        }
        .btn-group .btn {
            margin-right: 10px; /* Отступ между кнопками */
        }
        /* Исправления для мобильных устройств */
        @media (max-width: 768px) {
            .protocol-table {
                font-size: 0.8rem; /* Уменьшаем размер шрифта на мобильных */
            }
            .protocol-table th,
            .protocol-table td {
                padding: 0.5rem 0.25rem; /* Уменьшаем отступы */
            }
            .btn-group {
                flex-direction: column; /* Вертикальное расположение кнопок */
            }
            .btn-group .btn {
                margin-right: 0;
                margin-bottom: 5px;
            }
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
                                         <div class="btn-group mt-2" role="group" style="display: flex !important; gap: 8px !important; justify-content: flex-end !important;">
                         <a href="select-disciplines.php" class="btn btn-outline-secondary compact-btn" style="max-width: 280px !important; min-width: 280px !important; padding: 8px 14px !important; font-size: 1.02rem !important; white-space: normal !important; overflow: visible !important; text-overflow: clip !important; height: auto !important; line-height: 1.2 !important;">
                             <i class="bi bi-arrow-left me-1"></i>Назад к выбору дисциплин
                         </a>
                         <button type="button" class="btn btn-primary compact-btn" id="conduct-draw-btn" style="max-width: 200px !important; min-width: 200px !important; padding: 8px 14px !important; font-size: 1.02rem !important; height: auto !important; line-height: 1.2 !important;">
                             <i class="fas fa-random"></i> Жеребьевка
                         </button>
                     </div>
                     <div class="btn-group mt-2" role="group" style="display: flex !important; gap: 8px !important; justify-content: flex-end !important;">
                         <button type="button" class="btn btn-outline-success compact-btn" id="download-start-protocols-btn" style="max-width: 240px !important; min-width: 240px !important; padding: 8px 14px !important; font-size: 1.02rem !important; height: auto !important; line-height: 1.2 !important;">
                             <i class="fas fa-download"></i> Скачать стартовые
                         </button>
                         <button type="button" class="btn btn-outline-info compact-btn" id="download-finish-protocols-btn" style="max-width: 240px !important; min-width: 240px !important; padding: 8px 14px !important; font-size: 1.02rem !important; height: auto !important; line-height: 1.2 !important;">
                             <i class="fas fa-download"></i> Скачать финишные
                         </button>
                     </div>
                </div>
            </div>
        </div>

        <!-- Основной контент -->
        <div class="row protocols-row g-0">
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
    <input type="hidden" id="mero-id" value="<?php echo $meroId; ?>">
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
            } else {
                console.log('❌ ProtocolsManager НЕ загружен');
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
    <!-- JavaScript -->
    <script src="/lks/js/libs/jquery/jquery-3.7.1.min.js"></script>
    <script src="/lks/js/secretary/protocols_new.js?v=<?php echo time(); ?>"></script>
</body>
</html> 
<?php
// Запускаем сессию для проверки авторизации
session_start();

// Проверка прав доступа
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Secretary', 'SuperUser', 'Admin'])) {
    header('Location: /lks/login.php');
    exit();
}

// Проверяем, что это POST запрос
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: main.php');
    exit();
}

// Получаем данные из POST
$eventId = $_POST['event_id'] ?? null;
$action = $_POST['action'] ?? '';
$drawType = $_POST['draw_type'] ?? '';
$disciplines = $_POST['disciplines'] ?? '';

if (!$eventId) {
    // Если нет event_id, перенаправляем на главную страницу секретаря
    header('Location: main.php');
    exit();
}

try {
    require_once '../../php/db/Database.php';
    $db = Database::getInstance();
    
    // Получаем информацию о мероприятии
    $stmt = $db->prepare("SELECT * FROM meros WHERE champn = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$event) {
        // Если мероприятие не найдено, перенаправляем на главную страницу
        header('Location: main.php');
        exit();
    }
    
    // Сохраняем данные в сессии для использования на целевых страницах
    $_SESSION['selected_event'] = [
        'id' => $eventId,
        'meroname' => $event['meroname'],
        'merodata' => $event['merodata'],
        'status' => $event['status'],
        'champn' => $event['champn']
    ];
    
    // Если есть дисциплины, сохраняем их
    if ($disciplines) {
        error_log("Получены дисциплины из POST: " . $disciplines);
        
        $decodedDisciplines = json_decode($disciplines, true);
        error_log("Декодированные дисциплины: " . json_encode($decodedDisciplines));
        
        // Преобразуем объекты дисциплин в строки для совместимости
        $formattedDisciplines = [];
        foreach ($decodedDisciplines as $discipline) {
            if (is_array($discipline) && isset($discipline['class']) && isset($discipline['sex']) && isset($discipline['distance'])) {
                // Формат: "C-1_М_200"
                $formattedDisciplines[] = $discipline['class'] . '_' . $discipline['sex'] . '_' . $discipline['distance'];
            } else if (is_string($discipline)) {
                // Уже в правильном формате
                $formattedDisciplines[] = $discipline;
            }
        }
        
        $_SESSION['selected_disciplines'] = $formattedDisciplines;
        
        // Логируем для отладки
        error_log("Сохранены дисциплины в сессии: " . json_encode($formattedDisciplines));
        error_log("Сессия после сохранения: " . json_encode($_SESSION));
    } else {
        error_log("Дисциплины не получены из POST");
    }
    
    // Перенаправляем на соответствующую страницу
    switch ($action) {
        case 'select_disciplines':
            header('Location: select-disciplines.php');
            break;
        case 'protocols':
            header('Location: protocols.php');
            break;
        case 'results':
            header('Location: results.php');
            break;
        case 'main':
            header('Location: main.php');
            break;
        default:
            header('Location: main.php');
    }
    exit();
    
} catch (Exception $e) {
    // В случае ошибки перенаправляем на главную страницу
    header('Location: main.php');
    exit();
}
?> 
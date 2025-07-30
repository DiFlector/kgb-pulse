<?php
// Экспорт регистраций - API для администратора
session_start();

// Проверка авторизации
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    if (!defined('TEST_MODE')) header('Location: /lks/login.php');
    exit;
}

// Проверка прав администратора
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Admin', 'SuperUser'])) {
    http_response_code(403);
    echo 'Недостаточно прав';
    exit;
}

require_once __DIR__ . "/../db/Database.php";

try {
    $db = Database::getInstance();
    
    // Получение IDs для экспорта
    $ids = $_GET['ids'] ?? '';
    
    if (empty($ids)) {
        echo 'Не указаны ID для экспорта';
        exit;
    }
    
    $idsArray = explode(',', $ids);
    $idsArray = array_map('intval', $idsArray);
    $placeholders = str_repeat('?,', count($idsArray) - 1) . '?';
    
    // Получение данных регистраций
    $query = "
        SELECT 
            l.oid,
            l.users_oid,
            l.meros_oid,
            l.status,
            l.oplata,
            l.cost,
            l.discipline,
            u.fio,
            u.email,
            u.telephone,
            u.birthdata,
            u.city,
            u.country,
            m.meroname,
            m.merodata
        FROM listreg l
                    LEFT JOIN users u ON l.users_oid = u.oid
        LEFT JOIN meros m ON l.meros_oid = m.oid
        WHERE l.oid IN ($placeholders)
        ORDER BY l.oid
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute($idsArray);
    $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($registrations)) {
        echo 'Регистрации не найдены';
        exit;
    }
    
    // Установка заголовков для скачивания CSV
    $filename = 'registrations_export_' . date('Y-m-d_H-i-s') . '.csv';
    if (!defined('TEST_MODE')) header('Content-Type: text/csv; charset=utf-8');
    if (!defined('TEST_MODE')) header('Content-Disposition: attachment; filename="' . $filename . '"');
    if (!defined('TEST_MODE')) header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    if (!defined('TEST_MODE')) header('Expires: 0');
    
    // Создание CSV
    $output = fopen('php://output', 'w');
    
    // Добавляем BOM для корректного отображения кирилицы в Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Заголовки CSV
    $headers = [
        'ID',
        'ФИО',
        'Email',
        'Телефон',
        'Дата рождения',
        'Город',
        'Страна',
        'Мероприятие',
        'Дата мероприятия',
        'Классы лодок',
        'Статус',
        'Оплачено',
        'Стоимость'
    ];
    
    fputcsv($output, $headers, ';');
    
    // Данные
    foreach ($registrations as $reg) {
        $classDistance = json_decode($reg['class_distance'], true);
        $classes = [];
        if ($classDistance) {
            foreach ($classDistance as $class => $details) {
                $classes[] = $class;
            }
        }
        
        $row = [
            $reg['oid'],
            $reg['fio'],
            $reg['email'],
            $reg['telephone'],
            $reg['birthdata'],
            $reg['city'],
            $reg['country'],
            $reg['meroname'],
            $reg['merodata'],
            implode(', ', $classes),
            $reg['status'],
            $reg['oplata'] ? 'Да' : 'Нет',
            $reg['cost']
        ];
        
        fputcsv($output, $row, ';');
    }
    
    fclose($output);
    
    echo json_encode(['success' => true, 'file' => $filename]);
    exit;
    
} catch (Exception $e) {
    error_log('Ошибка при экспорте регистраций: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?> 
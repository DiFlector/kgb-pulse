<?php
session_start();

// Проверяем авторизацию
if (!isset($_SESSION['user_id']) || !isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    http_response_code(401);
    exit;
}

// Проверяем права доступа для разных разделов
$uri = $_SERVER['REQUEST_URI'];
$accessrights = $_SESSION['user_role'];

// Правила доступа к разделам
$access_rules = [
    '/lks/enter/admin/' => ['Admin', 'SuperUser'],
    '/lks/enter/organizer/' => ['Organizer', 'SuperUser', 'Admin'],
    '/lks/enter/secretary/' => ['Secretary', 'SuperUser', 'Admin'],
    '/lks/enter/user/' => ['Sportsman', 'SuperUser', 'Admin', 'Organizer', 'Secretary'],
    '/lks/php/admin/' => ['Admin', 'SuperUser'],
    '/lks/php/organizer/' => ['Organizer', 'SuperUser', 'Admin'],
    '/lks/php/secretary/' => ['Secretary', 'SuperUser', 'Admin'],
    '/lks/php/user/' => ['Sportsman', 'SuperUser', 'Admin', 'Organizer', 'Secretary']
];

foreach ($access_rules as $path => $allowed_roles) {
    if (strpos($uri, $path) !== false && !in_array($accessrights, $allowed_roles)) {
        http_response_code(403);
        exit;
    }
}

// Если все проверки пройдены, разрешаем доступ
http_response_code(200);
exit; 
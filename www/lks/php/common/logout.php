<?php
/**
 * Выход из системы
 */

require_once '../helpers.php';
require_once 'Auth.php';

$auth = new Auth();

// Выполняем выход
$auth->logout();

// Перенаправляем на главную страницу
header('Location: /lks/');
throw new Exception('Выполнен редирект на /lks/'); 
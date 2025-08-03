<?php
/**
 * Тестовый скрипт для проверки авторизации
 * Файл: www/lks/php/secretary/test_auth.php
 */

session_start();

// Отладочная информация
error_log("=== ТЕСТ АВТОРИЗАЦИИ ===");
error_log("SESSION: " . json_encode($_SESSION));
error_log("REQUEST: " . json_encode($_REQUEST));

// Проверка авторизации и прав доступа
require_once '../common/Auth.php';
$auth = new Auth();

echo "<h1>Тест авторизации</h1>";
echo "<p>Сессия: " . json_encode($_SESSION) . "</p>";

if (!$auth->isAuthenticated()) {
    echo "<p style='color: red;'>❌ Пользователь не авторизован</p>";
    error_log("ОШИБКА: Пользователь не авторизован");
} else {
    echo "<p style='color: green;'>✅ Пользователь авторизован</p>";
    error_log("УСПЕХ: Пользователь авторизован");
}

// Проверка прав секретаря, суперпользователя или администратора
if (!$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
    echo "<p style='color: red;'>❌ Нет прав доступа. user_role: " . ($_SESSION['user_role'] ?? 'не установлен') . "</p>";
    error_log("ОШИБКА: Нет прав доступа. user_role: " . ($_SESSION['user_role'] ?? 'не установлен'));
} else {
    echo "<p style='color: green;'>✅ Есть права доступа</p>";
    error_log("УСПЕХ: Есть права доступа");
}

echo "<p><a href='/lks/enter/secretary/protocols.php'>Вернуться к протоколам</a></p>";
?> 
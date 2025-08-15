<?php
/**
 * Редактирование своей регистрации — обновление поля discipline
 */

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Origin: *');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Methods: POST, OPTIONS');
if (!defined('TEST_MODE')) header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if (!isset($_SESSION)) {
    session_start();
}

require_once __DIR__ . '/../db/Database.php';
require_once __DIR__ . '/../common/Auth.php';
require_once __DIR__ . '/../helpers.php';

try {
    $auth = new Auth();
    if (!$auth->isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Не авторизован']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
        exit;
    }

    $db = Database::getInstance();
    $pdo = $db->getPDO();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['registrationId']) || !isset($input['discipline'])) {
        throw new Exception('Некорректные данные');
    }

    $regId = (int)$input['registrationId'];
    $newDiscipline = $input['discipline'];
    if ($regId <= 0) {
        throw new Exception('Некорректный ID регистрации');
    }
    if (!is_array($newDiscipline)) {
        throw new Exception('Некорректный формат дисциплины');
    }

    $current = $auth->getCurrentUser();
    if (!$current) {
        throw new Exception('Пользователь не найден');
    }
    $userOid = (int)$current['oid'];

    // Проверяем, что регистрация принадлежит пользователю
    $checkStmt = $pdo->prepare("SELECT oid, users_oid FROM listreg WHERE oid = ?");
    $checkStmt->execute([$regId]);
    $reg = $checkStmt->fetch(PDO::FETCH_ASSOC);
    if (!$reg) {
        throw new Exception('Регистрация не найдена');
    }
    if ((int)$reg['users_oid'] !== $userOid) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Вы не можете редактировать чужую регистрацию']);
        exit;
    }

    // Обновляем discipline
    $upd = $pdo->prepare('UPDATE listreg SET discipline = ? WHERE oid = ?');
    $upd->execute([json_encode($newDiscipline, JSON_UNESCAPED_UNICODE), $regId]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}


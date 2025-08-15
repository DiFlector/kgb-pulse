<?php
/**
 * Удаление собственной регистрации — Пользователь
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
    if (!$input || !isset($input['registrationId'])) {
        throw new Exception('Не указан ID регистрации');
    }
    $regId = (int)$input['registrationId'];
    if ($regId <= 0) {
        throw new Exception('Некорректный ID регистрации');
    }

    // Проверка владения: регистрация должна принадлежать текущему пользователю
    $current = $auth->getCurrentUser();
    if (!$current) {
        throw new Exception('Пользователь не найден');
    }
    $userOid = (int)$current['oid'];

    $checkStmt = $pdo->prepare("SELECT oid, users_oid, teams_oid FROM listreg WHERE oid = ?");
    $checkStmt->execute([$regId]);
    $reg = $checkStmt->fetch(PDO::FETCH_ASSOC);
    if (!$reg) {
        throw new Exception('Регистрация не найдена');
    }
    if ((int)$reg['users_oid'] !== $userOid) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Вы не можете удалить чужую регистрацию']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM listreg WHERE oid = ?');
        $del->execute([$regId]);
        if ($del->rowCount() === 0) {
            throw new Exception('Не удалось удалить регистрацию');
        }

        if (!empty($reg['teams_oid'])) {
            $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM listreg WHERE teams_oid = ?');
            $cntStmt->execute([$reg['teams_oid']]);
            $left = (int)$cntStmt->fetchColumn();
            if ($left === 0) {
                $pdo->prepare('DELETE FROM teams WHERE oid = ?')->execute([$reg['teams_oid']]);
            } else {
                $pdo->prepare('UPDATE teams SET persons_amount = ? WHERE oid = ?')->execute([$left, $reg['teams_oid']]);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}


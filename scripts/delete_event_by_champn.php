<?php
declare(strict_types=1);

// Удаление мероприятия и связанных регистраций/команд по champn.
// Без изменения структуры БД.

require_once __DIR__ . '/../lks/php/db/Database.php';

function main(): void {
    $db = Database::getInstance();
    $pdo = $db->getPDO();

    // Параметры
    $champn = null;
    foreach ($_SERVER['argv'] as $arg) {
        if (preg_match('/^--champn=(\d+)$/', $arg, $m)) { $champn = (int)$m[1]; }
    }

    if ($champn === null) {
        // Fallback: удалим по имени, если оно содержит ТЕСТОВОЕ
        $eventRows = $db->fetchAll("SELECT oid, champn, meroname FROM meros WHERE meroname ILIKE '%ТЕСТОВОЕ%' ORDER BY oid DESC");
    } else {
        $eventRows = $db->fetchAll('SELECT oid, champn, meroname FROM meros WHERE champn = ?', [$champn]);
        if (!$eventRows) {
            // Также попробуем по имени
            $eventRows = $db->fetchAll("SELECT oid, champn, meroname FROM meros WHERE meroname ILIKE '%ТЕСТОВОЕ%' ORDER BY oid DESC");
        }
    }

    if (!$eventRows) {
        echo "Мероприятие для удаления не найдено\n";
        return;
    }

    $pdo->beginTransaction();
    try {
        $eventOids = array_map(static fn($r) => (int)$r['oid'], $eventRows);

        // Найдём команды, задействованные только в этих мероприятиях, чтобы удалить безопасно
        $teamsToCheck = $db->fetchAll(
            'SELECT DISTINCT l.teams_oid AS oid
             FROM listreg l
             WHERE l.meros_oid = ANY (ARRAY[' . implode(',', $eventOids) . '])
             AND l.teams_oid IS NOT NULL'
        );

        // Удаляем регистрации
        $db->execute(
            'DELETE FROM listreg WHERE meros_oid = ANY (ARRAY[' . implode(',', $eventOids) . '])'
        );

        // Удаляем мероприятия
        $db->execute(
            'DELETE FROM meros WHERE oid = ANY (ARRAY[' . implode(',', $eventOids) . '])'
        );

        // Удалим команды, у которых больше нет регистраций
        foreach ($teamsToCheck as $t) {
            $tid = (int)$t['oid'];
            $left = $db->fetchOne('SELECT 1 FROM listreg WHERE teams_oid = ? LIMIT 1', [$tid]);
            if (!$left) {
                $db->execute('DELETE FROM teams WHERE oid = ?', [$tid]);
            }
        }

        $pdo->commit();
        echo 'Удалено мероприятий: ' . count($eventOids) . "\n";
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollback(); }
        fwrite(STDERR, 'Ошибка удаления: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

main();


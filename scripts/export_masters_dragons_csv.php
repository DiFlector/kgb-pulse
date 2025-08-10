<?php
declare(strict_types=1);

// Экспорт регистраций мероприятия "Открытие сезона «Мастерс по Драконам»" в CSV
// Файл сохраняется в create_mero/masters_dragons_opening.csv (вне контейнера доступен для тестов)

require_once __DIR__ . '/../lks/php/db/Database.php';

function csv_escape(string $v): string {
    $needs = strpbrk($v, ",\n\r\"") !== false;
    $v = str_replace('"', '""', $v);
    return $needs ? '"' . $v . '"' : $v;
}

function main(): void {
    $db = Database::getInstance();
    $meroname = 'Открытие сезона «Мастерс по Драконам»';
    $event = $db->fetchOne('SELECT oid FROM meros WHERE meroname = ?', [$meroname]);
    if (!$event) {
        echo "Нет мероприятия: $meroname\n";
        return;
    }
    $merosOid = (int)$event['oid'];

    $rows = $db->fetchAll(
        'SELECT l.oid as listreg_oid, l.users_oid, l.teams_oid, l.status::text as status, l.role, l.discipline::text as discipline,
                u.fio, u.email, u.telephone, u.sex, u.birthdata,
                t.teamname, t.teamcity
         FROM listreg l
         JOIN users u ON u.oid = l.users_oid
         JOIN teams t ON t.oid = l.teams_oid
         WHERE l.meros_oid = ?
         ORDER BY t.oid, l.role, l.users_oid', [$merosOid]
    );

    $toStdout = in_array('--stdout', $_SERVER['argv'] ?? [], true);
    $fp = $toStdout ? fopen('php://output', 'w') : null;
    $headers = ['team_oid','teamname','teamcity','user_oid','fio','email','telephone','sex','birthdate','status','role','discipline_json'];
    if ($fp) {
        fputcsv($fp, $headers);
    }

    foreach ($rows as $r) {
        $row = [
            $r['teams_oid'],
            $r['teamname'],
            $r['teamcity'],
            $r['users_oid'],
            $r['fio'],
            $r['email'],
            $r['telephone'],
            $r['sex'],
            $r['birthdata'],
            $r['status'],
            $r['role'],
            $r['discipline'],
        ];
        if ($fp) {
            fputcsv($fp, $row);
        }
    }
    if ($fp) {
        fclose($fp);
    } else {
        echo "Нет цели для экспорта (используйте --stdout)\n";
    }
}

main();


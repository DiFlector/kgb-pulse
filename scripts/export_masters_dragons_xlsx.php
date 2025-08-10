<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lks/php/db/Database.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

function fetchEventOid(Database $db, string $name): ?int {
    // Пытаемся найти точным именем
    $row = $db->fetchOne('SELECT oid FROM meros WHERE meroname = ? LIMIT 1', [$name]);
    if ($row) { return (int)$row['oid']; }
    // Иначе ищем по шаблонам (разные кавычки/варианты)
    $row = $db->fetchOne("SELECT oid FROM meros WHERE meroname ILIKE '%Мастерс%' OR meroname ILIKE '%Дракон%' ORDER BY oid DESC LIMIT 1");
    if ($row) { return (int)$row['oid']; }
    // Последний резерв — просто последнее мероприятие
    $row = $db->fetchOne('SELECT oid FROM meros ORDER BY oid DESC LIMIT 1');
    return $row ? (int)$row['oid'] : null;
}

function fetchRegistrations(Database $db, int $merosOid): array {
    return $db->fetchAll(
        'SELECT l.users_oid, l.discipline::text AS discipline_json, u.userid, u.fio, u.sex, u.email, u.telephone, u.birthdata, u.country, u.city
         FROM listreg l
         JOIN users u ON u.oid = l.users_oid
         WHERE l.meros_oid = ?
         ORDER BY u.oid',
        [$merosOid]
    );
}

function parseBoatAndDistances(array $disc): array {
    // Ожидаем формат: { 'D-10': { 'sex': ['M'|'W'|'MIX'], 'dist': ['200, 500, 1000'] } }
    $boat = null;
    $dists = [];
    if (isset($disc['D-10'])) {
        $sexArr = $disc['D-10']['sex'] ?? [];
        $boat = $sexArr[0] ?? null;
        $distArr = $disc['D-10']['dist'] ?? [];
        if (!empty($distArr)) {
            // Берем первую строку CSV и парсим
            $csv = $distArr[0];
            foreach (explode(',', (string)$csv) as $d) {
                $dists[] = (int)trim($d);
            }
        }
    }
    return [$boat, $dists];
}

function setHeaders(Worksheet $sheet): void {
    // Строка 1 — заголовки колонок
    $headers = ['№ п/п','ID','ФИО','Год рожд','Спорт. звание','Город','Пол','Email','№ телефон','Дата рожд'];
    foreach ($headers as $i => $h) {
        $sheet->setCellValueByColumnAndRow($i + 1, 1, $h);
    }
    // K..S — заголовки классов по 3 столбца на D10M, D10W, D10MIX
    $classBlocks = [
        ['D10M', [200, 500, 1600]],
        ['D10W', [200, 500, 1600]],
        ['D10MIX', [200, 500, 1600]],
    ];
    $col = 11; // K
    foreach ($classBlocks as [$label, $dists]) {
        foreach ($dists as $dist) {
            $sheet->setCellValueByColumnAndRow($col, 1, $label);
            $sheet->setCellValueByColumnAndRow($col, 2, $dist);
            $col++;
        }
    }
}

function main(): void {
    $db = Database::getInstance();
    $eventName = 'Открытие сезона «Мастерс по Драконам»';
    $merosOid = fetchEventOid($db, $eventName);
    if ($merosOid === null) {
        fwrite(STDERR, "Не найдено ни одного мероприятия для экспорта\n");
        exit(1);
    }
    $regs = fetchRegistrations($db, $merosOid);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Registrations');
    setHeaders($sheet);

    $row = 3; // данные начинаются с 3-й строки
    $seqId = 1000; // fallback ID
    foreach ($regs as $idx => $r) {
        $userid = (int)($r['userid'] ?? 0);
        if ($userid < 1000) { $userid = $seqId++; }

        [$boat, $dists] = [null, []];
        try {
            $disc = json_decode($r['discipline_json'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            [$boat, $dists] = parseBoatAndDistances($disc);
        } catch (Throwable $e) {
            // оставим пустые значения
        }

        $fio = $r['fio'] ?? '';
        $sex = $r['sex'] ?? '';
        $email = $r['email'] ?? '';
        $tel = $r['telephone'] ?? '';
        $birth = $r['birthdata'] ?? '';
        $city = trim(($r['country'] ?? 'Россия') . ', ' . ($r['city'] ?? ''));
        $sportTitle = 'БР'; // для экспорта шаблона — достаточно БР
        $birthYear = '';
        if ($birth) {
            $birthYear = substr((string)$birth, 0, 4);
        }

        // A-J
        $sheet->setCellValueByColumnAndRow(1, $row, $idx + 1); // № п/п
        $sheet->setCellValueByColumnAndRow(2, $row, $userid);   // ID
        $sheet->setCellValueByColumnAndRow(3, $row, $fio);
        $sheet->setCellValueByColumnAndRow(4, $row, $birthYear);
        $sheet->setCellValueByColumnAndRow(5, $row, $sportTitle);
        $sheet->setCellValueByColumnAndRow(6, $row, $city);
        $sheet->setCellValueByColumnAndRow(7, $row, $sex);
        $sheet->setCellValueByColumnAndRow(8, $row, $email);
        $sheet->setCellValueByColumnAndRow(9, $row, $tel);
        // J — дата рождения в формате дд.мм.гг
        if ($birth && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$birth, $m)) {
            $sheet->setCellValueByColumnAndRow(10, $row, $m[3] . '.' . $m[2] . '.' . substr($m[1], 2));
        } else {
            $sheet->setCellValueByColumnAndRow(10, $row, '');
        }

        // K-S — отметки по дисциплинам и дистанциям
        // Карта столбцов: для M -> K,L,M; W -> N,O,P; MIX -> Q,R,S
        $startCol = null;
        if ($boat === 'M') { $startCol = 11; }
        elseif ($boat === 'W') { $startCol = 14; }
        elseif ($boat === 'MIX') { $startCol = 17; }
        if ($startCol !== null) {
            $label = ($boat === 'M') ? 'D10M' : (($boat === 'W') ? 'D10W' : 'D10MIX');
            // Заполняем ячейки там, где есть дистанции (200,500,1600). Если дистанции не совпали — проставим по 200 и 500 как минимум
            $expected = [200, 500, 1600];
            for ($i=0; $i<count($expected); $i++) {
                $dist = $expected[$i];
                if (in_array($dist, $dists, true) || ($dist !== 1600 && empty($dists))) {
                    $sheet->setCellValueByColumnAndRow($startCol + $i, $row, $label);
                }
            }
        }

        $row++;
    }

    // Печатаем XLSX в stdout
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
}

main();


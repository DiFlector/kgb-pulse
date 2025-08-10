<?php
declare(strict_types=1);

// Скрипт генерации мероприятия "Открытие сезона «Мастерс по Драконам»",
// пользователей, команд и регистраций по правилам проекта.
// - Мероприятие: D-10 (M, W, MIX), дистанции 200, 500, 1000
// - Возрастные группы: группа 1: 18-29, группа 2: 30-39, группа 3: 40-49,
//   группа 4: 50-59, группа 5: 60-69
// - На каждую возрастную группу создаётся 0..6 команд по 14 человек:
//   10 гребцов, 1 рулевой, 1 барабанщик, 2 резерва
// - Пол лодки определяется только по 10 гребцам: для M/W допускается не более
//   1 противоположного пола среди гребцов. Для MIX — смешанный состав гребцов.
// - Все записи в listreg получают статус "Подтверждён".
// - Создаётся Excel-файл для восстановления в scripts/masters_dragons_opening.xlsx

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lks/php/db/Database.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Форматирование даты мероприятия в виде "10 августа 2025"
function formatRussianDateHuman(?int $timestamp = null): string {
    $timestamp = $timestamp ?? time();
    $day = (int)date('j', $timestamp);
    $month = (int)date('n', $timestamp);
    $year = (int)date('Y', $timestamp);
    $months = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря'
    ];
    $monthName = $months[$month] ?? '';
    return sprintf('%d %s %d', $day, $monthName, $year);
}

// Утилиты
function generateRussianName(string $sex): string {
    $maleNames = ['Алексей','Иван','Дмитрий','Сергей','Андрей','Павел','Максим','Николай','Владимир','Егор'];
    $femaleNames = ['Анна','Мария','Екатерина','Дарья','Ольга','Наталья','Ирина','Елена','Ксения','Татьяна'];
    $surnames = ['Иванов','Петров','Сидоров','Смирнов','Кузнецов','Соколов','Попов','Лебедев','Козлов','Новиков'];
    $patrM = ['Алексеевич','Иванович','Сергеевич','Андреевич','Павлович','Максимович'];
    $patrF = ['Алексеевна','Ивановна','Сергеевна','Андреевна','Павловна','Максимовна'];

    if ($sex === 'М') {
        $name = $maleNames[array_rand($maleNames)];
        $surname = $surnames[array_rand($surnames)];
        $patronymic = $patrM[array_rand($patrM)];
        return "$surname $name $patronymic";
    }
    $name = $femaleNames[array_rand($femaleNames)];
    $surname = $surnames[array_rand($surnames)] . 'а';
    $patronymic = $patrF[array_rand($patrF)];
    return "$surname $name $patronymic";
}

function randomPhone(int $seed): string {
    return '9' . str_pad((string)($seed % 999999999), 9, '0', STR_PAD_LEFT);
}

function yearForAge(int $age): int {
    $year = (int)date('Y');
    // Возраст на 31.12 текущего года = age → год рождения примерно (текущий - age)
    return $year - $age;
}

function birthdateForAge(int $age): string {
    // Возьмём 1 июля соответствующего года — это стабильно даёт нужный возраст на 31.12
    $y = yearForAge($age);
    return sprintf('%04d-07-01', $y);
}

function pickCity(): string {
    $cities = ['Москва','Санкт-Петербург','Екатеринбург','Новосибирск','Нижний Новгород','Казань','Самара','Уфа','Тюмень','Краснодар'];
    return $cities[array_rand($cities)];
}

function makeChampn(): int {
    // Простая генерация уникального champn на основе текущего времени
    // Формат: YYYY0NN (не строго обязательно)
    $y = (int)date('Y');
    return (int)($y . '01' . rand(10, 99));
}

function ensureEvent(Database $db, int &$merosOidOut): array {
    $meroname = 'Открытие сезона «Мастерс по Драконам»';
    // Ищем существующее мероприятие по названию
    $m = $db->fetchOne('SELECT oid, champn, class_distance, status::text as status FROM meros WHERE meroname = ?', [$meroname]);
    if ($m) {
        $merosOidOut = (int)$m['oid'];
        return $m;
    }

    // created_by — возьмём организатора, иначе админа, иначе первого пользователя
    $creator = $db->fetchOne("SELECT oid FROM users WHERE accessrights = 'Organizer' ORDER BY oid LIMIT 1", []);
    if (!$creator) {
        $creator = $db->fetchOne("SELECT oid FROM users WHERE accessrights = 'Admin' ORDER BY oid LIMIT 1", []);
    }
    if (!$creator) {
        $creator = $db->fetchOne("SELECT oid FROM users ORDER BY oid LIMIT 1", []);
    }
    $createdBy = $creator ? (int)$creator['oid'] : null;

    $classDistance = [
        'D-10' => [
            'sex' => ['M','W','MIX'],
            // Для каждого пола строка с перечислением дистанций (200, 500, 1600)
            'dist' => ['200, 500, 1600','200, 500, 1600','200, 500, 1600'],
            // Возрастные группы в формате, который парсит система
            'age_group' => [
                'группа 1: 18-29, группа 2: 30-39, группа 3: 40-49, группа 4: 50-59, группа 5: 60-69',
                'группа 1: 18-29, группа 2: 30-39, группа 3: 40-49, группа 4: 50-59, группа 5: 60-69',
                'группа 1: 18-29, группа 2: 30-39, группа 3: 40-49, группа 4: 50-59, группа 5: 60-69',
            ],
        ],
    ];

    $champn = makeChampn();
    $data = [
        'champn' => $champn,
        // Храним дату в человекочитаемом русском формате
        'merodata' => formatRussianDateHuman(),
        'meroname' => $meroname,
        'class_distance' => json_encode($classDistance, JSON_UNESCAPED_UNICODE),
        'defcost' => 0,
        'filepolojenie' => null,
        'fileprotokol' => null,
        'fileresults' => null,
        'status' => 'Регистрация',
        'created_by' => $createdBy,
    ];
    $merosOid = $db->insert('meros', $data);
    $merosOidOut = (int)$merosOid;
    return ['oid' => $merosOidOut, 'champn' => $champn, 'class_distance' => json_encode($classDistance, JSON_UNESCAPED_UNICODE), 'status' => 'Регистрация'];
}

function parseAgeGroupsString(string $groupsStr): array {
    // Преобразуем строку вида "группа 1: 18-29, группа 2: 30-39, ..." в массив [[18,29], [30,39], ...]
    $parts = array_map('trim', explode(',', $groupsStr));
    $ranges = [];
    foreach ($parts as $p) {
        // ожидаем "группа N: A-B"
        $segments = array_map('trim', explode(':', $p));
        if (count($segments) !== 2) { continue; }
        $range = trim($segments[1]);
        if (preg_match('/(\d+)-(\d+)/u', $range, $m)) {
            $ranges[] = [(int)$m[1], (int)$m[2]];
        }
    }
    return $ranges;
}

/**
 * Выбирает случайный непустой поднабор дистанций для команды на основе доступных.
 * Возвращает массив с двумя строками:
 *  - csv: строка вида "200, 1600"
 *  - label: строка для имени команды вида "200-1600"
 */
function chooseTeamDistancesFromAvailable(string $availableCsv): array {
    // Парсим доступные дистанции (например, "200, 500, 1600") → [200,500,1600]
    $parts = array_filter(array_map(static function ($v) {
        $v = trim((string)$v);
        if ($v === '') { return null; }
        return (int)$v;
    }, preg_split('/\s*,\s*/', $availableCsv)));
    if (empty($parts)) {
        return ['csv' => $availableCsv, 'label' => str_replace([', ', ','], '-', $availableCsv)];
    }
    // Случайный размер поднабора от 1 до N
    $n = count($parts);
    $subsetSize = rand(1, $n);
    // Случайный набор уникальных индексов
    $indexes = array_rand($parts, $subsetSize);
    if (!is_array($indexes)) { $indexes = [$indexes]; }
    $chosen = [];
    foreach ($indexes as $idx) { $chosen[] = (int)$parts[$idx]; }
    sort($chosen, SORT_NUMERIC);
    $csv = implode(', ', $chosen);
    $label = implode('-', $chosen);
    return ['csv' => $csv, 'label' => $label];
}

function createTeam(Database $db, string $teamName, string $teamCity): int {
    $teamOid = $db->insert('teams', [
        'teamid' => null,
        'teamname' => $teamName,
        'team_name' => $teamName,
        'teamcity' => $teamCity,
        'persons_amount' => 0,
        'persons_all' => 14,
        'another_team' => null,
        'class' => 'D-10',
    ]);
    return (int)$teamOid;
}

function ensureUser(Database $db, string $fio, string $sex, string $email, string $telephone, string $birthdate, string $city): int {
    // Уже есть такой email → используем существующего
    $u = $db->fetchOne('SELECT oid FROM users WHERE email = ?', [$email]);
    if ($u) {
        return (int)$u['oid'];
    }
    // Убедимся, что телефон уникален
    $tel = $telephone;
    $suffix = 0;
    while (true) {
        $existsTel = $db->fetchOne('SELECT oid FROM users WHERE telephone = ?', [$tel]);
        if (!$existsTel) { break; }
        $suffix++;
        $tel = $telephone . (string)$suffix;
    }
    // Убедимся, что email уникален (на случай редкого совпадения)
    $mail = $email;
    $suffix = 0;
    while (true) {
        $existsMail = $db->fetchOne('SELECT oid FROM users WHERE email = ?', [$mail]);
        if (!$existsMail) { break; }
        $suffix++;
        $mail = preg_replace('/@/', "+$suffix@", $email, 1);
    }
    $passwordHash = password_hash('password123', PASSWORD_DEFAULT);
    $userOid = $db->insert('users', [
        'userid' => null,
        'email' => $mail,
        'password' => $passwordHash,
        'fio' => $fio,
        'sex' => $sex,
        'telephone' => $tel,
        'birthdata' => $birthdate,
        'country' => 'Россия',
        'city' => $city,
        'accessrights' => 'Sportsman',
        'boats' => '{D-10}', // PostgreSQL array, не JSON!
        'sportzvanie' => 'БР',
    ]);
    return (int)$userOid;
}

function registerParticipant(Database $db, int $userOid, int $merosOid, int $teamOid, string $boatSexCat, string $distCsv, string $role): void {
    $discipline = [
        'D-10' => [
            'sex' => [$boatSexCat],
            // В данных проекта dist часто хранится как массив с одной строкой, содержащей CSV дистанций
            'dist' => [$distCsv],
        ],
    ];
    $db->insert('listreg', [
        'users_oid' => $userOid,
        'meros_oid' => $merosOid,
        'teams_oid' => $teamOid,
        'discipline' => json_encode($discipline, JSON_UNESCAPED_UNICODE),
        'oplata' => false,
        'cost' => 0,
        'status' => 'Подтверждён',
        'role' => $role,
    ]);
}

function updateTeamCount(Database $db, int $teamOid): void {
    $db->execute('UPDATE teams SET persons_amount = (SELECT COUNT(DISTINCT l.users_oid) FROM listreg l WHERE l.teams_oid = ?) WHERE oid = ?', [$teamOid, $teamOid]);
}

function main(): void {
    $db = Database::getInstance();
    $pdo = $db->getPDO();

    $pdo->beginTransaction();
    try {
        $merosOid = 0;
        $event = ensureEvent($db, $merosOid);
        $classDistance = json_decode($event['class_distance'], true, 512, JSON_THROW_ON_ERROR);
        $d10 = $classDistance['D-10'];
        $sexes = $d10['sex']; // ['M','W','MIX']
        $ageGroupsPerSex = $d10['age_group'];
        $distsPerSex = $d10['dist'];

        // Подготовим Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Registrations');
        $headers = [
            'team_oid','teamname','teamcity','class','boat_sex','age_group','distances','user_oid','fio','email','telephone','sex','birthdate','role'
        ];
        foreach ($headers as $i => $h) { $sheet->setCellValueByColumnAndRow($i+1, 1, $h); }
        $excelRow = 2;

        $teamCounter = 1;
        $userSeed = 100000; // для генерации уникальных телефонов/емейлов
        $distCsvDefault = '200, 500, 1600';

        // Будем собирать метаданные команд, чтобы присвоить X/Y имена после полной генерации
        $teamsMeta = [];
        foreach ($sexes as $sexIdx => $boatSexCat) {
            $groupsStr = $ageGroupsPerSex[$sexIdx] ?? '';
            $ranges = parseAgeGroupsString($groupsStr);
            $distCsv = $distsPerSex[$sexIdx] ?? $distCsvDefault;

            foreach ($ranges as $groupIndex => $range) {
                [$ageMin, $ageMax] = $range;
                // Случайное число команд 0..6 (жесткий лимит <=6)
                $teamsInGroup = max(0, min(6, rand(0, 6)));

                for ($t=0; $t < $teamsInGroup; $t++) {
                    // Имя команды по формату: D-10_{пол}_{дистанция}_{возрастная группа}_{номер}/{всего}
                    $ageGroupLabel = sprintf('группа %d: %d-%d', $groupIndex + 1, $ageMin, $ageMax);
                    // Выбираем для команды случайный набор дистанций из доступных
                    $teamDist = chooseTeamDistancesFromAvailable($distCsv);
                    $teamDistCsv = $teamDist['csv'];        // для поля discipline.dist
                    $distLabel   = $teamDist['label'];      // для названия команды
                    // Нумерация внутри группы: $t+1 из $teamsInGroup
                    $teamName = sprintf('D-10_%s_%s_%s_%d/%d', $boatSexCat, $distLabel, $ageGroupLabel, $t + 1, $teamsInGroup);
                    $teamCity = pickCity();
                    $teamOid = createTeam($db, $teamName, $teamCity);
                    $teamCounter++;

                    // Целевой возраст — середина диапазона
                    $targetAge = (int)floor(($ageMin + $ageMax) / 2);

                    // Сформируем состав: 10 гребцов, 1 рулевой, 1 барабанщик, 2 резерва
                    $rowers = 10;
                    $rolesPlan = array_merge(array_fill(0, $rowers, 'rower'), ['steerer','drummer','reserve','reserve']);

                    // Пол гребцов согласно категории лодки
                    $rowerSexes = [];
                    if ($boatSexCat === 'M') {
                        // 0 или 1 женщина среди гребцов
                        $women = rand(0,1);
                        $rowerSexes = array_fill(0, $rowers - $women, 'М');
                        for ($i=0; $i<$women; $i++) { $rowerSexes[] = 'Ж'; }
                        shuffle($rowerSexes);
                    } elseif ($boatSexCat === 'W') {
                        $men = rand(0,1);
                        $rowerSexes = array_fill(0, $rowers - $men, 'Ж');
                        for ($i=0; $i<$men; $i++) { $rowerSexes[] = 'М'; }
                        shuffle($rowerSexes);
                    } else { // MIX
                        // Примерно пополам
                        $men = 5; $women = 5;
                        $rowerSexes = array_merge(array_fill(0, $men, 'М'), array_fill(0, $women, 'Ж'));
                        shuffle($rowerSexes);
                    }

                    // Остальные роли — произвольный пол (не влияет)
                    $extraRolesSexes = [];
                    foreach (['steerer','drummer','reserve','reserve'] as $_) {
                        $extraRolesSexes[] = (rand(0,1) === 0) ? 'М' : 'Ж';
                    }

                    // Сгенерируем участников и регистрации
                    $sexCursor = 0;
                    $rowerAges = [];
                    foreach ($rolesPlan as $role) {
                        $sex = $role === 'rower' ? $rowerSexes[$sexCursor++] : array_shift($extraRolesSexes);

                        // Немного варьируем возраст в диапазоне ±2 года вокруг целевого
                        $age = max($ageMin, min($ageMax, $targetAge + rand(-2, 2)));
                        $birth = birthdateForAge($age);

                        $fio = generateRussianName($sex);
                        $email = sprintf('masters.%s.%d@pulse.test', strtolower($boatSexCat), $userSeed);
                        $tel = randomPhone($userSeed);
                        $userSeed++;
                        $city = $teamCity;

                        $userOid = ensureUser($db, $fio, $sex, $email, $tel, $birth, $city);
                        if ($role === 'rower') { $rowerAges[] = (int)($age); }
                        // Вносим в регистрацию выбранный поднабор дистанций команды
                        registerParticipant($db, $userOid, $merosOid, $teamOid, $boatSexCat, $teamDistCsv, $role);

                        // Внесём строку в Excel
                        $row = [
                            $teamOid, $teamName, $teamCity, 'D-10', $boatSexCat,
                            $ageGroupLabel,
                            $teamDistCsv, $userOid, $fio, $email, $tel, $sex, $birth, $role
                        ];
                        foreach ($row as $i => $val) { $sheet->setCellValueByColumnAndRow($i+1, $excelRow, $val); }
                        $excelRow++;
                    }

                    updateTeamCount($db, $teamOid);

                    // D-10: возрастная группа команды по САМОМУ МЛАДШЕМУ гребцу
                    $youngestAge = 0;
                    if (!empty($rowerAges)) { $youngestAge = min($rowerAges); }
                    $computedAgeGroupLabel = $ageGroupLabel;
                    foreach ($ranges as $idx2 => $r2) {
                        [$gMin, $gMax] = $r2;
                        if ($youngestAge >= $gMin && $youngestAge <= $gMax) {
                            $computedAgeGroupLabel = sprintf('группа %d: %d-%d', $idx2 + 1, $gMin, $gMax);
                            break;
                        }
                    }
                    $teamsMeta[] = [
                        'teamOid' => $teamOid,
                        'sex' => $boatSexCat,
                        'distLabel' => $distLabel,
                        'ageGroupLabel' => $computedAgeGroupLabel,
                    ];
                }
            }
        }

        $pdo->commit();

        // Второй проход: присвоим итоговые имена командам с нумерацией X/Y по ключу {пол лодки, набор дистанций, возрастная группа}
        $bucketTotals = [];
        foreach ($teamsMeta as $tm) {
            $key = $tm['sex'] . '|' . $tm['distLabel'] . '|' . $tm['ageGroupLabel'];
            if (!isset($bucketTotals[$key])) { $bucketTotals[$key] = 0; }
            $bucketTotals[$key]++;
        }
        $bucketIndex = [];
        foreach ($teamsMeta as $tm) {
            $key = $tm['sex'] . '|' . $tm['distLabel'] . '|' . $tm['ageGroupLabel'];
            if (!isset($bucketIndex[$key])) { $bucketIndex[$key] = 0; }
            $bucketIndex[$key]++;
            $num = $bucketIndex[$key];
            $total = $bucketTotals[$key];
            $finalName = sprintf('D-10_%s_%s_%s_%d/%d', $tm['sex'], $tm['distLabel'], $tm['ageGroupLabel'], $num, $total);
            $db->update('teams', ['teamname' => $finalName, 'team_name' => $finalName], ['oid' => $tm['teamOid']]);
        }

        // Сохраняем Excel
        // Сохраним файл в проекте (том ./www смонтирован в контейнер)
        $filename = __DIR__ . '/../lks/files/temp/masters_dragons_opening.xlsx';
        try {
            $writer = new Xlsx($spreadsheet);
            $writer->save($filename);
            echo "OK: created event OID={$merosOid}. Excel saved to www/lks/files/temp/masters_dragons_opening.xlsx\n";
        } catch (Throwable $e) {
            // Недостаточно прав на запись или нет каталога — это не критично для БД-записей
            echo "OK: created event OID={$merosOid}. Excel save skipped: " . $e->getMessage() . "\n";
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollback(); }
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

main();


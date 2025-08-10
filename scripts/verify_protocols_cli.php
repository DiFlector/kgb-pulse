<?php
declare(strict_types=1);

// CLI-проверка наполнения протоколов для последнего мероприятия
// Считает количество команд (по 10 гребцов role=rower) в каждой комбинации
// дисциплина/пол/дистанция/возрастная группа на основе фактических данных

require_once __DIR__ . '/../lks/php/db/Database.php';

function parseAgeGroupsString(string $groupsStr): array {
    $parts = array_map('trim', explode(',', $groupsStr));
    $ranges = [];
    foreach ($parts as $p) {
        $segments = array_map('trim', explode(':', $p));
        if (count($segments) !== 2) { continue; }
        $range = trim($segments[1]);
        if (preg_match('/(\d+)-(\d+)/u', $range, $m)) {
            $ranges[] = [(int)$m[1], (int)$m[2], $p]; // [min,max,label]
        }
    }
    return $ranges;
}

function getLatestEvent(PDO $pdo): ?array {
    $stmt = $pdo->query("SELECT oid, meroname, class_distance FROM meros ORDER BY oid DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function loadTeamCrew(PDO $pdo, int $merosOid): array {
    $sql = "
        SELECT lr.teams_oid, lr.discipline::text AS discipline_json,
               u.oid AS user_oid, u.sex AS user_sex, u.birthdata
        FROM listreg lr
        JOIN users u ON u.oid = lr.users_oid
        WHERE lr.meros_oid = :mero
          AND lr.status IN ('Зарегистрирован','Подтверждён')
          AND lr.role = 'rower'
          AND lr.teams_oid IS NOT NULL
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':mero' => $merosOid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $teams = [];
    $yearEnd = new DateTime(date('Y') . '-12-31');
    foreach ($rows as $r) {
        $teamId = (int)$r['teams_oid'];
        $disc = json_decode($r['discipline_json'] ?? '{}', true);
        $boatSex = null; $dists = [];
        if (isset($disc['D-10'])) {
            $sx = $disc['D-10']['sex'][0] ?? null; // 'M'|'W'|'MIX'
            $boatSex = $sx;
            $distCsv = $disc['D-10']['dist'][0] ?? '';
            foreach (explode(',', (string)$distCsv) as $d) { $dists[] = (int)trim($d); }
        }
        if (!isset($teams[$teamId])) {
            $teams[$teamId] = [
                'boatSex' => $boatSex,
                'dists' => $dists,
                'ages' => [],
            ];
        }
        // возраст на 31.12 текущего года
        $birth = new DateTime($r['birthdata']);
        $age = (int)$yearEnd->diff($birth)->y;
        $teams[$teamId]['ages'][] = $age;
        // фиксируем дист/пол, если ранее не было
        if (empty($teams[$teamId]['dists']) && !empty($dists)) {
            $teams[$teamId]['dists'] = $dists;
        }
        if ($teams[$teamId]['boatSex'] === null && $boatSex !== null) {
            $teams[$teamId]['boatSex'] = $boatSex;
        }
    }
    // Посчитаем средний возраст команды
    foreach ($teams as $tid => $t) {
        $avg = 0;
        if (!empty($t['ages'])) {
            $avg = (int)floor(array_sum($t['ages']) / count($t['ages']));
        }
        $teams[$tid]['avgAge'] = $avg;
    }
    return $teams;
}

function main(): void {
    $db = Database::getInstance();
    $pdo = $db->getPDO();
    $event = getLatestEvent($pdo);
    if (!$event) { fwrite(STDERR, "Нет мероприятия\n"); return; }
    $merosOid = (int)$event['oid'];
    $classDistance = json_decode($event['class_distance'], true);
    $teams = loadTeamCrew($pdo, $merosOid);

    echo "Мероприятие: {$event['meroname']} (OID={$merosOid})\n";
    echo "Команд (по rower): " . count($teams) . "\n";

    if (!isset($classDistance['D-10'])) { echo "Нет D-10 в class_distance\n"; return; }
    $d10 = $classDistance['D-10'];
    $sexes = $d10['sex'];
    $distsList = $d10['dist'];
    $agesList = $d10['age_group'];

    foreach ($sexes as $idx => $boatSexCat) {
        $distCsv = $distsList[$idx] ?? '';
        $distArr = array_map('intval', array_filter(array_map('trim', explode(',', $distCsv))));
        $ageRanges = parseAgeGroupsString($agesList[$idx] ?? '');
        foreach ($distArr as $dist) {
            foreach ($ageRanges as [$minAge, $maxAge, $label]) {
                $countTeams = 0;
                foreach ($teams as $t) {
                    if ($t['boatSex'] !== $boatSexCat) { continue; }
                    if (!in_array($dist, $t['dists'], true)) { continue; }
                    $avg = $t['avgAge'] ?? 0;
                    if ($avg >= $minAge && $avg <= $maxAge) { $countTeams++; }
                }
                echo sprintf("D-10 %s %dm %s => %d команд\n", $boatSexCat, $dist, $label, $countTeams);
            }
        }
    }
}

main();


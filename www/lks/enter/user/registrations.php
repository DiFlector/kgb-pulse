<?php
/**
 * Мои регистрации — Спортсмен
 */

// Авторизация
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: /lks/login.php');
    exit;
}

require_once __DIR__ . '/../../php/helpers.php';
require_once __DIR__ . '/../../php/db/Database.php';

// Проверка прав доступа: спортсмен и выше
if (!hasAccess('Sportsman')) {
    header('Location: /lks/html/403.html');
    exit;
}

$db = Database::getInstance();
$userId = $_SESSION['user_id']; // userid (внешний)

// Получаем oid пользователя
$userOidStmt = $db->prepare("SELECT oid, fio FROM users WHERE userid = ?");
$userOidStmt->execute([$userId]);
$userRow = $userOidStmt->fetch(PDO::FETCH_ASSOC);
if (!$userRow) {
    header('Location: /lks/login.php');
    exit;
}
$userOid = $userRow['oid'];
$userFio = $userRow['fio'];

// Фильтры
$eventFilter = $_GET['event'] ?? '';
$statusFilter = $_GET['status'] ?? '';

// Список статусов для фильтра
$availableStatuses = [
    'В очереди',
    'Подтверждён',
    'Зарегистрирован',
    'Ожидание команды',
    'Неявка',
    'Дисквалифицирован',
];

// Список мероприятий пользователя (для фильтра)
$eventsStmt = $db->prepare(
    "SELECT DISTINCT m.oid, m.champn, m.meroname
     FROM listreg l
     LEFT JOIN meros m ON l.meros_oid = m.oid
     WHERE l.users_oid = ?
     ORDER BY m.oid DESC"
);
$eventsStmt->execute([$userOid]);
$eventOptions = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

// Запрос регистраций текущего пользователя с фильтрами
$query = "
    SELECT 
        l.oid,
        l.status,
        l.oplata,
        l.cost,
        l.role,
        l.discipline,
        m.oid AS meros_oid,
        m.champn,
        m.meroname,
        m.merodata,
        m.status::text AS merostat_text,
        m.class_distance,
        COALESCE(t.teamid, NULL) AS teamid,
        COALESCE(t.teamname, NULL) AS teamname,
        COALESCE(t.teamcity, NULL) AS teamcity
    FROM listreg l
    LEFT JOIN meros m ON l.meros_oid = m.oid
    LEFT JOIN teams t ON l.teams_oid = t.oid
    WHERE l.users_oid = ?
";

$params = [$userOid];
if ($eventFilter !== '') {
    $query .= " AND l.meros_oid = ?";
    $params[] = $eventFilter;
}
if ($statusFilter !== '') {
    $query .= " AND l.status = ?";
    $params[] = $statusFilter;
}

$query .= " ORDER BY m.oid DESC, l.oid DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Статистика по регистрациям пользователя
$stats = [
    'total' => 0,
    'active' => 0,
    'waiting' => 0,
    'confirmed' => 0,
    'paid' => 0,
];
foreach ($registrations as $r) {
    $stats['total']++;
    if (in_array($r['merostat_text'], ['Регистрация', 'Регистрация закрыта', 'В ожидании'], true)) {
        $stats['active']++;
    }
    if (in_array($r['status'], ['В очереди', 'Ожидание команды'], true)) {
        $stats['waiting']++;
    }
    if ($r['status'] === 'Подтверждён') {
        $stats['confirmed']++;
    }
    if (!empty($r['oplata'])) {
        $stats['paid']++;
    }
}

// Настройки страницы
$pageTitle = 'Мои регистрации';
$pageHeader = 'Мои регистрации';
$pageIcon = 'bi bi-clipboard-check';
$showBreadcrumb = true;
$breadcrumb = [
    ['href' => '/lks/enter/user/', 'title' => 'Спортсмен'],
    ['href' => '#', 'title' => 'Мои регистрации']
];

include __DIR__ . '/../includes/header.php';
?>

<style>
    .stat-card{border-radius:.5rem}
    .border-left-primary{border-left:.25rem solid #0d6efd!important}
    .border-left-success{border-left:.25rem solid #198754!important}
    .border-left-warning{border-left:.25rem solid #ffc107!important}
    .border-left-info{border-left:.25rem solid #0dcaf0!important}
    .text-gray-800{color:#343a40}
    .badge-status{font-size:.85rem}
    .table thead th{white-space:nowrap}
    .discipline-badge{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
</style>

<!-- Статистика -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card border-left-primary shadow h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-xs fw-bold text-primary text-uppercase mb-1">Всего</div>
                        <div class="h5 mb-0 fw-bold text-gray-800"><?= (int)$stats['total'] ?></div>
                    </div>
                    <i class="bi bi-collection text-primary" style="font-size:2em"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card border-left-success shadow h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-xs fw-bold text-success text-uppercase mb-1">Активные</div>
                        <div class="h5 mb-0 fw-bold text-gray-800"><?= (int)$stats['active'] ?></div>
                    </div>
                    <i class="bi bi-activity text-success" style="font-size:2em"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card border-left-warning shadow h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-xs fw-bold text-warning text-uppercase mb-1">В ожидании</div>
                        <div class="h5 mb-0 fw-bold text-gray-800"><?= (int)$stats['waiting'] ?></div>
                    </div>
                    <i class="bi bi-hourglass-split text-warning" style="font-size:2em"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card stat-card border-left-info shadow h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-xs fw-bold text-info text-uppercase mb-1">Подтверждён</div>
                        <div class="h5 mb-0 fw-bold text-gray-800"><?= (int)$stats['confirmed'] ?></div>
                    </div>
                    <i class="bi bi-patch-check text-info" style="font-size:2em"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Фильтры -->
<div class="card mb-4">
    <div class="card-body">
        <form class="row g-3 align-items-end" method="get">
            <div class="col-md-6">
                <label class="form-label">Мероприятие</label>
                <select name="event" class="form-select">
                    <option value="">Все</option>
                    <?php foreach ($eventOptions as $ev): ?>
                        <option value="<?= (int)$ev['oid'] ?>" <?= ($eventFilter !== '' && (int)$eventFilter === (int)$ev['oid']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ev['meroname']) ?> (<?= (int)$ev['champn'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Статус</label>
                <select name="status" class="form-select">
                    <option value="">Все</option>
                    <?php foreach ($availableStatuses as $st): ?>
                        <option value="<?= htmlspecialchars($st) ?>" <?= ($statusFilter === $st) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($st) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search me-1"></i>Поиск
                </button>
            </div>
        </form>
    </div>
    <div class="card-footer small text-muted">
        Для внешних ключей используются поля `oid`. Номера мероприятий: `champn`.
    </div>
    
</div>

<!-- Таблица регистраций -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Мероприятие</th>
                        <th class="text-nowrap">Дата</th>
                        <th>Статус рег.</th>
                        <th>Статус мероп.</th>
                        <th>Команда</th>
                        <th>Стоимость</th>
                        <th>Оплачено</th>
                        <th>Дисциплина</th>
                        <th class="text-end">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($registrations)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">Нет регистраций по заданным фильтрам</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($registrations as $reg): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($reg['meroname']) ?></div>
                                    <div class="text-muted small">№ <?= (int)$reg['champn'] ?></div>
                                </td>
                                <td class="text-nowrap"><?= htmlspecialchars($reg['merodata']) ?></td>
                                <td>
                                    <?php
                                    $status = $reg['status'];
                                    $badgeClass = 'bg-secondary';
                                    if ($status === 'В очереди') $badgeClass = 'bg-secondary';
                                    elseif ($status === 'Подтверждён') $badgeClass = 'bg-info';
                                    elseif ($status === 'Зарегистрирован') $badgeClass = 'bg-success';
                                    elseif ($status === 'Ожидание команды') $badgeClass = 'bg-warning text-dark';
                                    elseif ($status === 'Неявка') $badgeClass = 'bg-dark';
                                    elseif ($status === 'Дисквалифицирован') $badgeClass = 'bg-danger';
                                    ?>
                                    <span class="badge badge-status <?= $badgeClass ?>"><?= htmlspecialchars($status) ?></span>
                                </td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($reg['merostat_text']) ?></span></td>
                                <td>
                                    <?php if ($reg['teamid']): ?>
                                        <div class="fw-semibold"><?= htmlspecialchars($reg['teamname'] ?? ('Команда #' . (int)$reg['teamid'])) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($reg['teamcity'] ?? '') ?></div>
                                    <?php else: ?>
                                        <span class="text-muted">Индивидуальная</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format((float)$reg['cost'], 2, ',', ' ') ?> ₽</td>
                                <td>
                                    <?php if (!empty($reg['oplata'])): ?>
                                        <span class="badge bg-success">Да</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark border">Нет</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $disc = json_decode($reg['discipline'], true);
                                    if (is_array($disc) && isset($disc['class'])) {
                                        $class = $disc['class'];
                                        $sexLabel = isset($disc['sex']) ? normalizeSexToRussian($disc['sex']) : '';
                                        $distance = isset($disc['distance']) ? preg_replace('/\s*м?\s*/u', '', (string)$disc['distance']) . 'м' : '';
                                        echo '<span class="badge bg-primary me-1">' . htmlspecialchars($class) . '</span>';
                                        echo '<small class="text-muted">' . htmlspecialchars(trim(($distance ? $distance.' ' : '').$sexLabel)) . '</small>';
                                    } elseif ($disc && is_array($disc)) {
                                        $parts = [];
                                        foreach ($disc as $className => $details) {
                                            if (is_array($details)) {
                                                $sexValues = isset($details['sex']) ? (is_array($details['sex']) ? $details['sex'] : [$details['sex']]) : [];
                                                $distValues = isset($details['dist']) ? (is_array($details['dist']) ? $details['dist'] : [$details['dist']]) : [];
                                                if (!empty($sexValues) && !empty($distValues)) {
                                                    if (count($sexValues) === count($distValues)) {
                                                        foreach ($sexValues as $idx => $sex) {
                                                            $sexLabel = normalizeSexToRussian($sex);
                                                            $distString = (string)($distValues[$idx] ?? '');
                                                            $distString = preg_replace('/м/iu', '', $distString);
                                                            $opts = array_filter(array_map('trim', explode(',', $distString)), fn($v)=>$v!=='');
                                                            if (!empty($opts)) {
                                                                $parts[] = $className . ': ' . $sexLabel . ' ' . implode(', ', $opts) . 'м';
                                                            }
                                                        }
                                                    } else {
                                                        foreach ($distValues as $distance) {
                                                            $clean = str_replace(['м',' ','м'], '', (string)$distance);
                                                            foreach ($sexValues as $sex) {
                                                                $parts[] = $className . ': ' . normalizeSexToRussian($sex) . ' ' . $clean . 'м';
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                        echo !empty($parts) ? '<small class="text-muted">'.htmlspecialchars(implode('; ', $parts)).'</small>' : '<span class="text-muted">—</span>';
                                    } else {
                                        echo '<span class="text-muted">—</span>';
                                    }
                                    ?>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-outline-primary"
                                            data-reg-id="<?= (int)$reg['oid'] ?>"
                                            data-discipline='<?= htmlspecialchars($reg['discipline']) ?>'
                                            data-class-distance='<?= htmlspecialchars($reg['class_distance'] ?? '') ?>'
                                            onclick="openEditRegistration(this)" title="Редактировать">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger" onclick="deleteRegistration(<?= (int)$reg['oid'] ?>)" title="Удалить">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
// Удаление регистрации
function getCSRFToken(){const t=document.querySelector('meta[name="csrf-token"]');return t?t.getAttribute('content'):''}
function deleteRegistration(regId){if(!confirm('Удалить регистрацию #'+regId+'?'))return;fetch('/lks/php/user/delete_registration.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':getCSRFToken()},body:JSON.stringify({registrationId:regId})}).then(r=>r.json()).then(d=>{if(d.success){location.reload()}else{alert(d.message||'Ошибка удаления')}}).catch(e=>{console.error(e);alert('Ошибка удаления')})}

// Редактирование дисциплин
let editModal,currentEdit={regId:null,discipline:null,classDistance:null};
function openEditRegistration(btn){const d=btn.dataset;currentEdit.regId=parseInt(d.regId,10);try{currentEdit.discipline=JSON.parse(d.discipline)}catch(e){currentEdit.discipline=null}try{currentEdit.classDistance=JSON.parse(d.classDistance)}catch(e){currentEdit.classDistance=null}
const container=document.getElementById('editDiscContainer');const alertEl=document.getElementById('editDiscAlert');if(!container||!alertEl){createEditModalShell()}
buildEditForm()}

function buildEditForm(){const container=document.getElementById('editDiscContainer');const alertEl=document.getElementById('editDiscAlert');container.innerHTML='';alertEl.classList.add('d-none');if(!currentEdit.discipline||!currentEdit.classDistance){alertEl.textContent='Нет данных для редактирования';alertEl.classList.remove('d-none');showEditModal();return}
const blocks=[];
if(currentEdit.discipline.class){const cls=currentEdit.discipline.class;const cfg=currentEdit.classDistance[cls];if(!cfg){container.innerHTML='<div class="text-muted">Класс '+cls+' не найден в мероприятии</div>';showEditModal();return}
const sexArr=Array.isArray(currentEdit.discipline.sex)?currentEdit.discipline.sex:[currentEdit.discipline.sex];const selected=(currentEdit.discipline.distance?String(currentEdit.discipline.distance):'').split(',').map(s=>s.trim()).filter(Boolean);
cfg.sex.forEach((sx,idx)=>{if(sexArr[0]&&normalizeSexRu(sx)!==normalizeSexRu(sexArr[0]))return;const row=document.createElement('div');row.className='mb-3';const title=document.createElement('div');title.className='fw-semibold mb-2';title.textContent=cls+' — '+normalizeSexRu(sx);row.appendChild(title);const distStr=String(cfg.dist[idx]||'');const dists=distStr.split(',').map(s=>s.trim()).filter(Boolean);dists.forEach(dist=>{const id='chk_'+idx+'_'+dist;const div=document.createElement('div');div.className='form-check form-check-inline';const input=document.createElement('input');input.type='checkbox';input.className='form-check-input';input.id=id;input.value=dist;input.checked=selected.includes(dist);const label=document.createElement('label');label.className='form-check-label';label.setAttribute('for',id);label.textContent=dist+' м';div.appendChild(input);div.appendChild(label);row.appendChild(div)});blocks.push(row)});blocks.forEach(b=>container.appendChild(b))}
else{Object.keys(currentEdit.discipline).forEach(cls=>{const det=currentEdit.discipline[cls];if(!det||typeof det!=='object')return;const cfg=currentEdit.classDistance[cls];if(!cfg)return;const sexValues=Array.isArray(det.sex)?det.sex:[det.sex];const distValues=Array.isArray(det.dist)?det.dist:[det.dist];sexValues.forEach((sx,idx)=>{const row=document.createElement('div');row.className='mb-3';const title=document.createElement('div');title.className='fw-semibold mb-2';title.textContent=cls+' — '+normalizeSexRu(sx);row.appendChild(title);const distStr=String(distValues[idx]||'');const selected=distStr.split(',').map(s=>s.trim()).filter(Boolean);const available=(String(cfg.dist[idx]||'')).split(',').map(s=>s.trim()).filter(Boolean);available.forEach(dist=>{const id='chk_'+cls+'_'+idx+'_'+dist;const div=document.createElement('div');div.className='form-check form-check-inline';const input=document.createElement('input');input.type='checkbox';input.className='form-check-input';input.id=id;input.value=dist;input.checked=selected.includes(dist);const label=document.createElement('label');label.className='form-check-label';label.setAttribute('for',id);label.textContent=dist+' м';div.appendChild(input);div.appendChild(label);row.appendChild(div)});blocks.push(row)});});blocks.forEach(b=>container.appendChild(b))}
showEditModal()}

function normalizeSexRu(s){s=String(s||'').trim();if(s==='M'||s==='М')return 'М';if(s==='W'||s==='F'||s==='Ж')return 'Ж';if(s==='MIX'||s==='Смешанные')return 'MIX';return s}
function showEditModal(){if(!editModal){editModal=new bootstrap.Modal(document.getElementById('editRegistrationModal'))}editModal.show()}
document.addEventListener('click',function(e){if(e.target&&e.target.id==='saveEditDiscBtn'){const container=document.getElementById('editDiscContainer');const checked=[...container.querySelectorAll('input[type="checkbox"]:checked')].map(i=>i.value);if(currentEdit.discipline&&currentEdit.discipline.class){const payload={...currentEdit.discipline};payload.distance=checked.join(', ');sendUpdate(payload)}else{const firstKey=Object.keys(currentEdit.discipline)[0];if(!firstKey){alert('Не удалось определить дисциплину');return}const det=currentEdit.discipline[firstKey];const sexValues=Array.isArray(det.sex)?det.sex:[det.sex];const newDist=[];sexValues.forEach(()=>{newDist.push(checked.join(', '))});const payload={};payload[firstKey]={sex:det.sex,dist:newDist};sendUpdate(payload)}}});
function sendUpdate(newDiscipline){fetch('/lks/php/user/edit_registration.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':getCSRFToken()},body:JSON.stringify({registrationId:currentEdit.regId,discipline:newDiscipline})}).then(r=>r.json()).then(d=>{if(d.success){location.reload()}else{alert(d.message||'Ошибка сохранения')}}).catch(e=>{console.error(e);alert('Ошибка сохранения')})}
</script>

<!-- Modal shell placed at end to avoid layout shift -->
<div class="modal fade" id="editRegistrationModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Редактирование дистанций</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="editDiscAlert" class="alert alert-warning d-none"></div>
        <div id="editDiscContainer"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
        <button type="button" class="btn btn-primary" id="saveEditDiscBtn">Сохранить</button>
      </div>
    </div>
  </div>
</div>


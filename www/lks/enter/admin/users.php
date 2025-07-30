<?php
/**
 * Управление пользователями - Администратор
 */

// Проверка авторизации
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Admin' && $_SESSION['user_role'] !== 'SuperUser')) {
    header('Location: /lks/login.php');
    exit;
}

require_once '../../php/db/Database.php';

$db = Database::getInstance();
$currentUserId = $_SESSION['user_id'] ?? 1;

// Получение всех пользователей по ролям
try {
    $adminUsers = $db->query("SELECT userid, email, fio, accessrights FROM users WHERE accessrights in ('Admin', 'SuperUser') ORDER BY userid")->fetchAll(PDO::FETCH_ASSOC);
    $organizerUsers = $db->query("SELECT userid, email, fio, accessrights FROM users WHERE accessrights = 'Organizer' ORDER BY userid")->fetchAll(PDO::FETCH_ASSOC);
    $secretaryUsers = $db->query("SELECT userid, email, fio, accessrights FROM users WHERE accessrights = 'Secretary' ORDER BY userid")->fetchAll(PDO::FETCH_ASSOC);
    $sportsmanUsers = $db->query("SELECT userid, email, fio, accessrights FROM users WHERE accessrights = 'Sportsman' ORDER BY userid")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Users page error: " . $e->getMessage());
    $adminUsers = $organizerUsers = $secretaryUsers = $sportsmanUsers = [];
}

include '../includes/header.php';
?>

<!-- Заголовок страницы -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Управление пользователями</h1>
    <div class="btn-group">
        <button class="btn btn-success" onclick="addUser()">
            <i class="bi bi-person-plus"></i> Добавить пользователя
        </button>
        <button class="btn btn-primary" onclick="exportUsers()">
            <i class="bi bi-download"></i> Экспорт
        </button>
    </div>
</div>

<!-- Фильтры -->
<div class="card mb-4 shadow">
    <div class="card-header bg-primary text-white">
        <h6 class="m-0 font-weight-bold">Поиск и фильтры</h6>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <label for="roleFilter" class="form-label">Роль</label>
                <select class="form-select" id="roleFilter">
                    <option value="">Все роли</option>
                    <option value="Admin">Администратор</option>
                    <option value="Organizer">Организатор</option>
                    <option value="Secretary">Секретарь</option>
                    <option value="Sportsman">Спортсмен</option>
                </select>
            </div>
            <div class="col-md-6">
                <label for="searchInput" class="form-label">Поиск</label>
                <input type="text" class="form-control" id="searchInput" placeholder="Поиск по ФИО или email...">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-outline-secondary" onclick="clearFilters()">Очистить</button>
            </div>
        </div>
    </div>
</div>

<!-- Таблицы пользователей -->
<div class="row">
    <!-- Администраторы -->
    <div class="col-lg-6 mb-4">
        <div class="card shadow">
            <div class="card-header bg-danger text-white">
                <h6 class="m-0 font-weight-bold">Администраторы</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Email</th>
                                <th>ФИО</th>
                                <th>Роль</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($adminUsers)): ?>
                                <tr><td colspan="5" class="text-center text-muted">Нет администраторов</td></tr>
                            <?php else: ?>
                                <?php foreach ($adminUsers as $admin): ?>
                                <tr>
                                    <td>
                                        <input type="number" class="form-control form-control-sm user-id-input" 
                                               value="<?= $admin['userid'] ?>" 
                                               min="1" max="50" 
                                               data-original="<?= $admin['userid'] ?>"
                                               onchange="updateUserId(<?= $admin['userid'] ?>, this.value)"
                                               style="width: 70px;">
                                    </td>
                                    <td><?= htmlspecialchars($admin['email']) ?></td>
                                    <td>
                                        <?= htmlspecialchars($admin['fio']) ?>
                                        <?php if ($admin['userid'] == $currentUserId): ?>
                                            <small class="text-muted">(Это вы)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <select class="form-select form-select-sm" onchange="changeRole(<?= $admin['userid'] ?>, this.value)">
                                            <option value="Admin" <?= $admin['accessrights'] === 'Admin' ? 'selected' : '' ?>>Главный админ</option>
                                            <option value="Organizer" <?= $admin['accessrights'] === 'Organizer' ? 'selected' : '' ?>>Организатор</option>
                                            <option value="Secretary" <?= $admin['accessrights'] === 'Secretary' ? 'selected' : '' ?>>Секретарь</option>
                                            <option value="Sportsman" <?= $admin['accessrights'] === 'Sportsman' ? 'selected' : '' ?>>Спортсмен</option>
                                        </select>
                                        <small class="text-muted">ID: 1-50</small>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" onclick="editUser(<?= $admin['userid'] ?>)" title="Редактировать">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Организаторы -->
    <div class="col-lg-6 mb-4">
        <div class="card shadow">
            <div class="card-header bg-warning text-dark">
                <h6 class="m-0 font-weight-bold">Организаторы</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Email</th>
                                <th>ФИО</th>
                                <th>Роль</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($organizerUsers)): ?>
                                <tr><td colspan="5" class="text-center text-muted">Нет организаторов</td></tr>
                            <?php else: ?>
                                <?php foreach ($organizerUsers as $organizer): ?>
                                <tr>
                                    <td>
                                        <input type="number" class="form-control form-control-sm user-id-input" 
                                               value="<?= $organizer['userid'] ?>" 
                                               min="51" max="150" 
                                               data-original="<?= $organizer['userid'] ?>"
                                               onchange="updateUserId(<?= $organizer['userid'] ?>, this.value)"
                                               style="width: 70px;">
                                    </td>
                                    <td><?= htmlspecialchars($organizer['email']) ?></td>
                                    <td><?= htmlspecialchars($organizer['fio']) ?></td>
                                    <td>
                                        <select class="form-select form-select-sm" onchange="changeRole(<?= $organizer['userid'] ?>, this.value)">
                                            <option value="Admin" <?= $organizer['accessrights'] === 'Admin' ? 'selected' : '' ?>>Админ</option>
                                            <option value="Organizer" <?= $organizer['accessrights'] === 'Organizer' ? 'selected' : '' ?>>Главный организатор</option>
                                            <option value="Secretary" <?= $organizer['accessrights'] === 'Secretary' ? 'selected' : '' ?>>Секретарь</option>
                                            <option value="Sportsman" <?= $organizer['accessrights'] === 'Sportsman' ? 'selected' : '' ?>>Спортсмен</option>
                                        </select>
                                        <small class="text-muted">ID: 51-150</small>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" onclick="editUser(<?= $organizer['userid'] ?>)" title="Редактировать">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Секретари -->
    <div class="col-lg-6 mb-4">
        <div class="card shadow">
            <div class="card-header bg-info text-white">
                <h6 class="m-0 font-weight-bold">Секретари</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Email</th>
                                <th>ФИО</th>
                                <th>Роль</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($secretaryUsers)): ?>
                                <tr><td colspan="5" class="text-center text-muted">Нет секретарей</td></tr>
                            <?php else: ?>
                                <?php foreach ($secretaryUsers as $secretary): ?>
                                <tr>
                                    <td>
                                        <input type="number" class="form-control form-control-sm user-id-input" 
                                               value="<?= $secretary['userid'] ?>" 
                                               min="151" max="250" 
                                               data-original="<?= $secretary['userid'] ?>"
                                               onchange="updateUserId(<?= $secretary['userid'] ?>, this.value)"
                                               style="width: 70px;">
                                    </td>
                                    <td><?= htmlspecialchars($secretary['email']) ?></td>
                                    <td><?= htmlspecialchars($secretary['fio']) ?></td>
                                    <td>
                                        <select class="form-select form-select-sm" onchange="changeRole(<?= $secretary['userid'] ?>, this.value)">
                                            <option value="Admin" <?= $secretary['accessrights'] === 'Admin' ? 'selected' : '' ?>>Админ</option>
                                            <option value="Organizer" <?= $secretary['accessrights'] === 'Organizer' ? 'selected' : '' ?>>Организатор</option>
                                            <option value="Secretary" <?= $secretary['accessrights'] === 'Secretary' ? 'selected' : '' ?>>Главный секретарь</option>
                                            <option value="Sportsman" <?= $secretary['accessrights'] === 'Sportsman' ? 'selected' : '' ?>>Спортсмен</option>
                                        </select>
                                        <small class="text-muted">ID: 151-250</small>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" onclick="editUser(<?= $secretary['userid'] ?>)" title="Редактировать">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Спортсмены -->
    <div class="col-lg-6 mb-4">
        <div class="card shadow">
            <div class="card-header bg-success text-white">
                <h6 class="m-0 font-weight-bold">Спортсмены</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Email</th>
                                <th>ФИО</th>
                                <th>Роль</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($sportsmanUsers)): ?>
                                <tr><td colspan="5" class="text-center text-muted">Нет спортсменов</td></tr>
                            <?php else: ?>
                                <?php foreach ($sportsmanUsers as $sportsman): ?>
                                <tr>
                                    <td>
                                        <input type="number" class="form-control form-control-sm user-id-input" 
                                               value="<?= $sportsman['userid'] ?>" 
                                               min="1000" max="9999" 
                                               data-original="<?= $sportsman['userid'] ?>"
                                               onchange="updateUserId(<?= $sportsman['userid'] ?>, this.value)"
                                               style="width: 80px;">
                                    </td>
                                    <td><?= htmlspecialchars($sportsman['email']) ?></td>
                                    <td><?= htmlspecialchars($sportsman['fio']) ?></td>
                                    <td>
                                        <select class="form-select form-select-sm" onchange="changeRole(<?= $sportsman['userid'] ?>, this.value)">
                                            <option value="Admin" <?= $sportsman['accessrights'] === 'Admin' ? 'selected' : '' ?>>Админ</option>
                                            <option value="Organizer" <?= $sportsman['accessrights'] === 'Organizer' ? 'selected' : '' ?>>Организатор</option>
                                            <option value="Secretary" <?= $sportsman['accessrights'] === 'Secretary' ? 'selected' : '' ?>>Секретарь</option>
                                            <option value="Sportsman" <?= $sportsman['accessrights'] === 'Sportsman' ? 'selected' : '' ?>>Спортсмен</option>
                                        </select>
                                        <small class="text-muted">ID: 1000+</small>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" onclick="editUser(<?= $sportsman['userid'] ?>)" title="Редактировать">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно добавления пользователя -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addUserModalLabel">Добавление пользователя</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
            </div>
            <div class="modal-body">
                <form id="addUserForm">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="addUserFio" class="form-label">ФИО *</label>
                                <input type="text" class="form-control" id="addUserFio" name="fio" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="addUserEmail" class="form-label">Email *</label>
                                <input type="email" class="form-control" id="addUserEmail" name="email" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="addUserPhone" class="form-label">Телефон</label>
                                <input type="tel" class="form-control" id="addUserPhone" name="telephone" placeholder="79001234567">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="addUserBirthdate" class="form-label">Дата рождения</label>
                                <input type="date" class="form-control" id="addUserBirthdate" name="birthdata">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="addUserSex" class="form-label">Пол</label>
                                <select class="form-select" id="addUserSex" name="sex">
                                    <option value="">Не указан</option>
                                    <option value="М">Мужской</option>
                                    <option value="Ж">Женский</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="addUserCountry" class="form-label">Страна</label>
                                <input type="text" class="form-control" id="addUserCountry" name="country" placeholder="Россия" value="Россия">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="addUserCity" class="form-label">Город</label>
                                <input type="text" class="form-control" id="addUserCity" name="city" placeholder="Москва">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="addUserRole" class="form-label">Роль *</label>
                                <select class="form-select" id="addUserRole" name="accessrights" required>
                                    <option value="">Выберите роль</option>
                                    <option value="Admin">Администратор</option>
                                    <option value="Organizer">Организатор</option>
                                    <option value="Secretary">Секретарь</option>
                                    <option value="Sportsman">Спортсмен</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="addUserSportzvanie" class="form-label">Спортивное звание</label>
                                <select class="form-select" id="addUserSportzvanie" name="sportzvanie">
                                    <option value="">Не указано</option>
                                    <option value="ЗМС">Заслуженный Мастер Спорта</option>
                                    <option value="МСМК">Мастер Спорта Международного Класса</option>
                                    <option value="МССССР">Мастер Спорта СССР</option>
                                    <option value="МСР">Мастер Спорта России</option>
                                    <option value="МСсуч">Мастер Спорта страны участницы</option>
                                    <option value="КМС">Кандидат в Мастера Спорта</option>
                                    <option value="1вр">1 взрослый разряд</option>
                                    <option value="2вр">2 взрослый разряд</option>
                                    <option value="3вр">3 взрослый разряд</option>
                                    <option value="БР">Без Разряда</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Типы лодок (показываем только для спортсменов и админов) -->
                    <div class="mb-3" id="addUserBoatsSection" style="display: none;">
                        <label class="form-label">Типы лодок</label>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="D-10" id="addBoat_D10" name="boats[]">
                                    <label class="form-check-label" for="addBoat_D10">D-10</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="K-1" id="addBoat_K1" name="boats[]">
                                    <label class="form-check-label" for="addBoat_K1">K-1</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="K-2" id="addBoat_K2" name="boats[]">
                                    <label class="form-check-label" for="addBoat_K2">K-2</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="K-4" id="addBoat_K4" name="boats[]">
                                    <label class="form-check-label" for="addBoat_K4">K-4</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="C-1" id="addBoat_C1" name="boats[]">
                                    <label class="form-check-label" for="addBoat_C1">C-1</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="C-2" id="addBoat_C2" name="boats[]">
                                    <label class="form-check-label" for="addBoat_C2">C-2</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="C-4" id="addBoat_C4" name="boats[]">
                                    <label class="form-check-label" for="addBoat_C4">C-4</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="HD-1" id="addBoat_HD1" name="boats[]">
                                    <label class="form-check-label" for="addBoat_HD1">HD-1</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="OD-1" id="addBoat_OD1" name="boats[]">
                                    <label class="form-check-label" for="addBoat_OD1">OD-1</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="OD-2" id="addBoat_OD2" name="boats[]">
                                    <label class="form-check-label" for="addBoat_OD2">OD-2</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="OC-1" id="addBoat_OC1" name="boats[]">
                                    <label class="form-check-label" for="addBoat_OC1">OC-1</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        <strong>Пароль:</strong> Временный пароль будет сгенерирован автоматически и отправлен на указанный email.
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отменить</button>
                <button type="button" class="btn btn-success" id="saveNewUser">Создать пользователя</button>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно редактирования пользователя -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editUserModalLabel">Редактирование пользователя</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
            </div>
            <div class="modal-body">
                <form id="editUserForm">
                    <input type="hidden" id="editUserId" name="userId">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="editUserFio" class="form-label">ФИО *</label>
                                <input type="text" class="form-control" id="editUserFio" name="fio" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="editUserEmail" class="form-label">Email *</label>
                                <input type="email" class="form-control" id="editUserEmail" name="email" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="editUserPhone" class="form-label">Телефон</label>
                                <input type="tel" class="form-control" id="editUserPhone" name="telephone" placeholder="79001234567">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="editUserBirthdate" class="form-label">Дата рождения</label>
                                <input type="date" class="form-control" id="editUserBirthdate" name="birthdata">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="editUserSex" class="form-label">Пол</label>
                                <select class="form-select" id="editUserSex" name="sex">
                                    <option value="">Не указан</option>
                                    <option value="М">Мужской</option>
                                    <option value="Ж">Женский</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="editUserCountry" class="form-label">Страна</label>
                                <input type="text" class="form-control" id="editUserCountry" name="country" placeholder="Россия">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="editUserCity" class="form-label">Город</label>
                                <input type="text" class="form-control" id="editUserCity" name="city" placeholder="Москва">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="editUserRole" class="form-label">Роль *</label>
                                <select class="form-select" id="editUserRole" name="accessrights" required>
                                    <option value="Admin">Администратор</option>
                                    <option value="Organizer">Организатор</option>
                                    <option value="Secretary">Секретарь</option>
                                    <option value="Sportsman">Спортсмен</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="editUserSportzvanie" class="form-label">Спортивное звание</label>
                                <select class="form-select" id="editUserSportzvanie" name="sportzvanie">
                                    <option value="">Не указано</option>
                                    <option value="ЗМС">Заслуженный Мастер Спорта</option>
                                    <option value="МСМК">Мастер Спорта Международного Класса</option>
                                    <option value="МССССР">Мастер Спорта СССР</option>
                                    <option value="МСР">Мастер Спорта России</option>
                                    <option value="МСсуч">Мастер Спорта страны участницы</option>
                                    <option value="КМС">Кандидат в Мастера Спорта</option>
                                    <option value="1вр">1 взрослый разряд</option>
                                    <option value="2вр">2 взрослый разряд</option>
                                    <option value="3вр">3 взрослый разряд</option>
                                    <option value="БР">Без Разряда</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Типы лодок (показываем только для спортсменов и админов) -->
                    <div class="mb-3" id="editUserBoatsSection">
                        <label class="form-label">Типы лодок</label>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="D-10" id="editBoat_D10" name="boats[]">
                                    <label class="form-check-label" for="editBoat_D10">D-10</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="K-1" id="editBoat_K1" name="boats[]">
                                    <label class="form-check-label" for="editBoat_K1">K-1</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="K-2" id="editBoat_K2" name="boats[]">
                                    <label class="form-check-label" for="editBoat_K2">K-2</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="K-4" id="editBoat_K4" name="boats[]">
                                    <label class="form-check-label" for="editBoat_K4">K-4</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="C-1" id="editBoat_C1" name="boats[]">
                                    <label class="form-check-label" for="editBoat_C1">C-1</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="C-2" id="editBoat_C2" name="boats[]">
                                    <label class="form-check-label" for="editBoat_C2">C-2</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="C-4" id="editBoat_C4" name="boats[]">
                                    <label class="form-check-label" for="editBoat_C4">C-4</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="HD-1" id="editBoat_HD1" name="boats[]">
                                    <label class="form-check-label" for="editBoat_HD1">HD-1</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="OD-1" id="editBoat_OD1" name="boats[]">
                                    <label class="form-check-label" for="editBoat_OD1">OD-1</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="OD-2" id="editBoat_OD2" name="boats[]">
                                    <label class="form-check-label" for="editBoat_OD2">OD-2</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="OC-1" id="editBoat_OC1" name="boats[]">
                                    <label class="form-check-label" for="editBoat_OC1">OC-1</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="editUserResetPassword" name="resetPassword">
                            <label class="form-check-label" for="editUserResetPassword">
                                Сбросить пароль (отправить новый пароль на email)
                            </label>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отменить</button>
                <button type="button" class="btn btn-primary" id="saveUserChanges">Сохранить изменения</button>
            </div>
        </div>
    </div>
</div>

<script>
function changeRole(userId, newRole) {
    if (confirm('Изменить роль пользователя?')) {
        fetch('/lks/php/admin/change-role.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                userId: userId,
                newRole: newRole
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Роль успешно изменена', 'success');
                location.reload();
            } else {
                showNotification('Ошибка: ' + data.message, 'error');
            }
        })
        .catch(error => {
            showNotification('Ошибка изменения роли', 'error');
            console.error('Error:', error);
        });
    }
}

function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type === 'error' ? 'danger' : type === 'success' ? 'success' : 'info'} alert-dismissible fade show position-fixed`;
    notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    notification.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 5000);
}

function applyFilters() {
    const roleFilter = document.getElementById('roleFilter').value;
    const searchInput = document.getElementById('searchInput').value.toLowerCase();
    
    const tables = document.querySelectorAll('.table');
    
    tables.forEach(table => {
        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        
        const rows = tbody.querySelectorAll('tr');
        
        rows.forEach(row => {
            if (row.cells.length < 4) {
                return;
            }
            
            const email = row.cells[1].textContent.toLowerCase();
            const fio = row.cells[2].textContent.toLowerCase();
            const userRole = row.querySelector('select').value;
            
            let showRow = true;
            
            if (roleFilter && userRole !== roleFilter) {
                showRow = false;
            }
            
            if (searchInput && !fio.includes(searchInput) && !email.includes(searchInput)) {
                showRow = false;
            }
            
            row.style.display = showRow ? '' : 'none';
        });
    });
}

function editUser(userId) {
    fetch('/lks/php/admin/get_user.php?userId=' + userId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                populateEditForm(data.user);
                const modal = new bootstrap.Modal(document.getElementById('editUserModal'));
                modal.show();
            } else {
                showNotification('Ошибка загрузки данных пользователя: ' + data.message, 'error');
            }
        })
        .catch(error => {
            showNotification('Ошибка загрузки данных пользователя', 'error');
            console.error('Error:', error);
        });
}

function populateEditForm(user) {
    document.getElementById('editUserId').value = user.userid;
    document.getElementById('editUserFio').value = user.fio || '';
    document.getElementById('editUserEmail').value = user.email || '';
    document.getElementById('editUserPhone').value = user.telephone || '';
    document.getElementById('editUserBirthdate').value = user.birthdata || '';
    document.getElementById('editUserSex').value = user.sex || '';
    document.getElementById('editUserCountry').value = user.country || '';
    document.getElementById('editUserCity').value = user.city || '';
    document.getElementById('editUserRole').value = user.accessrights || '';
    document.getElementById('editUserSportzvanie').value = user.sportzvanie || '';
    
    document.querySelectorAll('#editUserForm input[name="boats[]"]').forEach(checkbox => {
        checkbox.checked = false;
    });
    
    if (user.boats && Array.isArray(user.boats)) {
        user.boats.forEach(boat => {
            const checkbox = document.getElementById('editBoat_' + boat.replace('-', ''));
            if (checkbox) {
                checkbox.checked = true;
            }
        });
    }
    
    toggleBoatsSection(user.accessrights);
}

function toggleBoatsSection(role) {
    const boatsSection = document.getElementById('editUserBoatsSection');
    if (role === 'Admin' || role === 'Sportsman') {
        boatsSection.style.display = 'block';
    } else {
        boatsSection.style.display = 'none';
    }
}

function addUser() {
    // Очищаем форму
    document.getElementById('addUserForm').reset();
    document.getElementById('addUserCountry').value = 'Россия';
    
    // Скрываем секцию лодок
    document.getElementById('addUserBoatsSection').style.display = 'none';
    
    // Открываем модальное окно
    const modal = new bootstrap.Modal(document.getElementById('addUserModal'));
    modal.show();
}

function toggleAddBoatsSection(role) {
    const boatsSection = document.getElementById('addUserBoatsSection');
    if (role === 'Admin' || role === 'Sportsman') {
        boatsSection.style.display = 'block';
    } else {
        boatsSection.style.display = 'none';
    }
}

async function exportUsers() {
    try {
        // Показываем уведомление о начале экспорта
        showNotification('Создание экспорта пользователей...', 'info');
        
        // Отправляем запрос на создание экспорта
        const response = await fetch('/lks/php/admin/export.php?type=users&format=csv');
        const data = await response.json();
        
        if (data.success && data.download_url) {
            // Скачиваем файл по полученной ссылке
            const link = document.createElement('a');
            link.href = data.download_url;
            link.download = '';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showNotification(data.message || 'Экспорт пользователей готов к скачиванию', 'success');
        } else {
            showNotification(data.message || 'Ошибка создания экспорта', 'error');
        }
    } catch (error) {
        console.error('Ошибка экспорта:', error);
        showNotification('Ошибка при создании экспорта пользователей', 'error');
    }
}

function clearFilters() {
    document.getElementById('roleFilter').value = '';
    document.getElementById('searchInput').value = '';
    applyFilters();
}

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('searchInput').addEventListener('input', applyFilters);
    document.getElementById('roleFilter').addEventListener('change', applyFilters);
    document.getElementById('saveUserChanges').addEventListener('click', saveUserChanges);
    document.getElementById('saveNewUser').addEventListener('click', saveNewUser);
    document.getElementById('editUserRole').addEventListener('change', function() {
        toggleBoatsSection(this.value);
    });
    document.getElementById('addUserRole').addEventListener('change', function() {
        toggleAddBoatsSection(this.value);
    });
    
    // Полностью отключаем backdrop для всех модальных окон
    const originalModalShow = bootstrap.Modal.prototype.show;
    bootstrap.Modal.prototype.show = function() {
        this._config.backdrop = false; // Отключаем backdrop
        this._config.keyboard = false; // Отключаем закрытие по ESC для безопасности
        return originalModalShow.call(this);
    };
    
    // Удаляем все существующие backdrop'ы
    function removeBackdrops() {
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => backdrop.remove());
    }
    
    // Проверяем и удаляем backdrop'ы каждые 100ms
    setInterval(removeBackdrops, 100);
});

function saveNewUser() {
    const form = document.getElementById('addUserForm');
    const formData = new FormData(form);
    
    const selectedBoats = [];
    document.querySelectorAll('#addUserForm input[name="boats[]"]:checked').forEach(checkbox => {
        selectedBoats.push(checkbox.value);
    });
    
    const userData = {
        fio: formData.get('fio'),
        email: formData.get('email'),
        telephone: formData.get('telephone'),
        birthdata: formData.get('birthdata'),
        sex: formData.get('sex'),
        country: formData.get('country'),
        city: formData.get('city'),
        accessrights: formData.get('accessrights'),
        sportzvanie: formData.get('sportzvanie'),
        boats: selectedBoats
    };
    
    if (!userData.fio.trim()) {
        showNotification('ФИО обязательно для заполнения', 'error');
        return;
    }
    
    if (!userData.email.trim()) {
        showNotification('Email обязателен для заполнения', 'error');
        return;
    }
    
    if (!userData.accessrights) {
        showNotification('Роль обязательна для заполнения', 'error');
        return;
    }
    
    const submitBtn = document.getElementById('saveNewUser');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Создание...';
    
    fetch('/lks/php/admin/create_user.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(userData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            
            const modal = bootstrap.Modal.getInstance(document.getElementById('addUserModal'));
            modal.hide();
            
            setTimeout(() => {
                location.reload();
            }, 2000);
        } else {
            showNotification('Ошибка: ' + data.message, 'error');
        }
        
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Создать пользователя';
    })
    .catch(error => {
        showNotification('Ошибка создания пользователя', 'error');
        console.error('Error:', error);
        
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Создать пользователя';
    });
}

function saveUserChanges() {
    const form = document.getElementById('editUserForm');
    const formData = new FormData(form);
    
    const selectedBoats = [];
    document.querySelectorAll('#editUserForm input[name="boats[]"]:checked').forEach(checkbox => {
        selectedBoats.push(checkbox.value);
    });
    
    const userData = {
        userId: parseInt(formData.get('userId')),
        fio: formData.get('fio'),
        email: formData.get('email'),
        telephone: formData.get('telephone'),
        birthdata: formData.get('birthdata'),
        sex: formData.get('sex'),
        country: formData.get('country'),
        city: formData.get('city'),
        accessrights: formData.get('accessrights'),
        sportzvanie: formData.get('sportzvanie'),
        boats: selectedBoats,
        resetPassword: formData.get('resetPassword') === 'on'
    };
    
    if (!userData.fio.trim()) {
        showNotification('ФИО обязательно для заполнения', 'error');
        return;
    }
    
    if (!userData.email.trim()) {
        showNotification('Email обязателен для заполнения', 'error');
        return;
    }
    
    fetch('/lks/php/admin/update_user.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(userData)
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text().then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Ошибка парсинга JSON:', e);
                throw new Error('Сервер вернул некорректный JSON: ' + text.substring(0, 100));
            }
        });
    })
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            
            const modal = bootstrap.Modal.getInstance(document.getElementById('editUserModal'));
            modal.hide();
            
            setTimeout(() => {
                location.reload();
            }, 2000);
        } else {
            showNotification('Ошибка: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Полная ошибка:', error);
        showNotification('Ошибка сохранения данных пользователя: ' + error.message, 'error');
    });
}

function updateUserId(originalId, newId) {
    if (newId == originalId) return;
    
    if (confirm(`Изменить ID пользователя с ${originalId} на ${newId}?`)) {
        fetch('/lks/php/admin/update-user-id.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                original_id: originalId,
                new_id: newId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlert('success', 'ID пользователя успешно изменен');
                setTimeout(() => location.reload(), 1000);
            } else {
                showAlert('danger', data.message || 'Ошибка при изменении ID');
                // Возвращаем старое значение
                event.target.value = originalId;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlert('danger', 'Ошибка при изменении ID пользователя');
            event.target.value = originalId;
        });
    } else {
        // Пользователь отменил изменение
        event.target.value = originalId;
    }
}
</script>

<?php include '../includes/footer.php'; ?>
<?php
require_once '../includes/header.php';
require_once '../../php/db/Database.php';

// Проверка прав доступа
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Secretary' && $_SESSION['user_role'] !== 'SuperUser' && $_SESSION['user_role'] !== 'Admin')) {
    header('Location: /lks/login.php');
    exit();
}

// Получаем данные из сессии
$selectedEvent = $_SESSION['selected_event'] ?? null;

if (!$selectedEvent) {
    echo '<div class="alert alert-danger">Данные мероприятия не найдены. Вернитесь к списку мероприятий.</div>';
    echo '<a href="main.php" class="btn btn-primary">Вернуться к списку мероприятий</a>';
    exit();
}

$meroId = $selectedEvent['id'];

// Получаем информацию о мероприятии
$db = Database::getInstance();
$stmt = $db->prepare("SELECT * FROM meros WHERE champn = ?");
$stmt->execute([$meroId]);
$mero = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$mero) {
    echo '<div class="alert alert-danger">Мероприятие не найдено</div>';
    echo '<a href="main.php" class="btn btn-primary">Вернуться к списку мероприятий</a>';
    exit();
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <h2 class="mb-4"><?php echo htmlspecialchars($mero['meroname']); ?></h2>
            <p class="text-muted"><?php echo htmlspecialchars($mero['merodata']); ?></p>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">Итоги мероприятия</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="card mb-3">
                                <div class="card-body text-center">
                                    <h5 class="card-title">Призовые места</h5>
                                    <p class="card-text">Скачать список призёров (первые 5 мест)</p>
                                    <button id="downloadPrizesBtn" class="btn btn-primary">
                                        <i class="bi bi-download"></i> Скачать призовые места
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="card mb-3">
                                <div class="card-body text-center">
                                    <h5 class="card-title">Технические результаты</h5>
                                    <p class="card-text">Скачать полные технические результаты</p>
                                    <button id="downloadTechnicalBtn" class="btn btn-success">
                                        <i class="bi bi-download"></i> Скачать тех. результаты
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="card mb-3">
                                <div class="card-body text-center">
                                    <h5 class="card-title">Завершение мероприятия</h5>
                                    <p class="card-text">Завершить мероприятие и опубликовать результаты</p>
                                    <button id="finishEventBtn" class="btn btn-danger">
                                        <i class="bi bi-flag"></i> Завершить мероприятие
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-4">
                        <div class="col-12">
                            <h5>Общий список участников</h5>
                            <div class="table-responsive">
                                <table class="table table-striped" id="resultsTable">
                                    <thead>
                                        <tr>
                                            <th>Место</th>
                                            <th>ФИО</th>
                                            <th>Год рождения</th>
                                            <th>Группа</th>
                                            <th>Команда</th>
                                            <th>Город</th>
                                            <th>Дисциплина</th>
                                            <th>Дистанция</th>
                                            <th>Время П.Ф.</th>
                                            <th>Время Финал</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Данные будут загружены через JavaScript -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно подтверждения завершения -->
<div class="modal fade" id="confirmFinishModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Подтверждение завершения</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Вы действительно хотите завершить мероприятие?</p>
                <p>После завершения:</p>
                <ul>
                    <li>Статус мероприятия изменится на "Результаты"</li>
                    <li>Будет сформирован файл технических результатов</li>
                    <li>Результаты станут доступны всем участникам</li>
                </ul>
                <p class="text-danger">Это действие нельзя отменить!</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-danger" id="confirmFinishBtn">Завершить</button>
            </div>
        </div>
    </div>
</div>

<style>
.table th {
    background-color: #f8f9fa;
    position: sticky;
    top: 0;
    z-index: 1;
}

.table-responsive {
    max-height: 500px;
    overflow-y: auto;
}

.card-title {
    color: #333;
    font-weight: 500;
}

.card-text {
    color: #666;
}
</style>

<!-- Подключаем скрипты -->
<script src="/lks/js/secretary/results.js"></script>

<?php require_once '../includes/footer.php'; ?> 
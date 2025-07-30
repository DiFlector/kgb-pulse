<?php
require_once '../../php/common/Auth.php';
require_once '../../php/db/Database.php';
require_once '../../php/helpers.php';

$auth = new Auth();
$user = $auth->checkRole(['Organizer', 'SuperUser', 'Admin']);
if (!$user) {
    header('Location: ../../login.php');
    exit();
}

// Отладочная информация
error_log("User data: " . print_r($user, true));

$error = '';
$success = '';
$isEdit = false;
$eventData = null;

// Проверяем режим редактирования
if (isset($_GET['edit']) && !empty($_GET['edit'])) {
    $isEdit = true;
    $editId = intval($_GET['edit']);
    
    try {
        $db = Database::getInstance();
        
        // Логика доступа по ролям:
        // Главные админы (1-10), обычные админы (11-50), SuperUser - полный доступ
        // Главные организаторы (51-100) - полный доступ  
        // Главные секретари (151-200) - полный доступ
        // Остальные - только свои мероприятия
        $hasFullAccess = in_array($user['user_role'], ['SuperUser']) || 
                        ($user['user_role'] === 'Admin' && $user['userid'] >= 1 && $user['userid'] <= 50) ||
                        ($user['user_role'] === 'Organizer' && $user['userid'] >= 51 && $user['userid'] <= 100) ||
                        ($user['user_role'] === 'Secretary' && $user['userid'] >= 151 && $user['userid'] <= 200);
        
        // ИСПРАВЛЕНО: Ищем мероприятие по oid (фронтенд передает oid), а не по champn
        // Нужно сравнивать с oid текущего пользователя, а не с userid
        if ($hasFullAccess) {
            $stmt = $db->prepare("SELECT * FROM meros WHERE oid = ?");
            $stmt->execute([$editId]);
        } else {
            // Получаем oid текущего пользователя для сравнения
            $currentUserOid = $db->fetchColumn("SELECT oid FROM users WHERE userid = ?", [$user['userid']]);
            $stmt = $db->prepare("SELECT * FROM meros WHERE oid = ? AND created_by = ?");
            $stmt->execute([$editId, $currentUserOid]);
        }
        
        $eventData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$eventData) {
            $error = 'Мероприятие не найдено или у вас нет прав на его редактирование';
            $isEdit = false;
        }
    } catch (Exception $e) {
        error_log("Ошибка загрузки мероприятия для редактирования: " . $e->getMessage());
        $error = 'Ошибка загрузки данных мероприятия';
        $isEdit = false;
    }
}

// Обработка формы создания/редактирования мероприятия
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $year = $_POST['year'] ?? '';
    $dates = $_POST['dates'] ?? '';
    $name = $_POST['name'] ?? '';
    $classes = $_POST['classes'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $distances = $_POST['distances'] ?? '';
    $ageGroups = $_POST['age_groups'] ?? '';
    $cost = $_POST['cost'] ?? '';
    $documentFile = $_FILES['document'] ?? null;
    $removeDocument = $_POST['remove_document'] ?? '0';
    
    // Валидация
    if (empty($year) || empty($dates) || empty($name) || empty($classes) || empty($cost)) {
        $error = 'Заполните все обязательные поля';
    } else {
        try {
            $db = Database::getInstance();
            
            // Обработка загруженного документа
            $documentPath = null;
            if ($documentFile && $documentFile['error'] === UPLOAD_ERR_OK) {
                // Используем абсолютный путь от корня документов
                $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/lks/files/polojenia/';
                
                // Проверяем, что директория существует и доступна для записи
                if (!is_dir($uploadDir)) {
                    if (!mkdir($uploadDir, 0755, true)) {
                        throw new Exception('Не удалось создать директорию для загрузки файлов');
                    }
                }
                
                if (!is_writable($uploadDir)) {
                    throw new Exception('Директория для загрузки файлов недоступна для записи');
                }
                
                // Генерируем безопасное имя файла
                $fileExtension = pathinfo($documentFile['name'], PATHINFO_EXTENSION);
                $baseName = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($documentFile['name'], PATHINFO_FILENAME));
                $fileName = time() . '_' . $baseName . '.' . $fileExtension;
                $fullPath = $uploadDir . $fileName;
                
                // Перемещаем загруженный файл
                if (!move_uploaded_file($documentFile['tmp_name'], $fullPath)) {
                    throw new Exception('Ошибка при перемещении загруженного файла. Проверьте права доступа.');
                }
                
                // Сохраняем относительный путь для базы данных
                $documentPath = 'files/polojenia/' . $fileName;
                
                error_log("Файл успешно загружен: " . $fullPath);
            }
            
            // Формирование JSON для class_distance
            $classesArray = array_map('trim', explode(';', $classes));
            $genderArray = array_map('trim', explode(';', $gender));
            $distancesArray = array_map('trim', explode(';', $distances));
            $ageGroupsArray = array_map('trim', explode(';', $ageGroups));
            
            $classDistance = [];
            foreach ($classesArray as $i => $class) {
                // ИСПРАВЛЕНО: Для каждого класса используем соответствующие полы и дистанции
                
                // Получаем полы для данного класса (разделены запятыми)
                $classGenders = ['М', 'Ж']; // По умолчанию
                if (isset($genderArray[$i]) && !empty($genderArray[$i])) {
                    $classGenders = array_map('trim', explode(',', $genderArray[$i]));
                }
                
                // Получаем дистанции для данного класса (разделены |)
                $classDistances = ['200, 500']; // По умолчанию
                if (isset($distancesArray[$i]) && !empty($distancesArray[$i])) {
                    // Дистанции могут быть разделены | для разных полов
                    $classDistances = array_map('trim', explode('|', $distancesArray[$i]));
                }
                
                // Убеждаемся что количество дистанций соответствует количеству полов
                while (count($classDistances) < count($classGenders)) {
                    // Дублируем последнюю дистанцию
                    $lastDist = !empty($classDistances) ? end($classDistances) : '200, 500';
                    $classDistances[] = $lastDist;
                }
                
                // Обрезаем лишние дистанции
                $classDistances = array_slice($classDistances, 0, count($classGenders));
                
                // ИСПРАВЛЕНО: Правильный парсинг возрастных групп
                $classAgeGroups = [];
                if (!empty($ageGroupsArray) && isset($ageGroupsArray[$i])) {
                    $ageGroupString = $ageGroupsArray[$i];
                    // Разбиваем строку по | для разделения полов
                    $genderParts = explode('|', $ageGroupString);
                    foreach ($genderParts as $genderPart) {
                        $genderPart = trim($genderPart);
                        if (!empty($genderPart)) {
                            $classAgeGroups[] = $genderPart;
                        }
                    }
                }
                
                $classDistance[$class] = [
                    'sex' => $classGenders,
                    'dist' => $classDistances,
                    'age_group' => $classAgeGroups
                ];
            }
            
            if ($isEdit && $eventData) {
                // Обновление существующего мероприятия
                $updateFields = [
                    'merodata' => $dates,
                    'meroname' => $name,
                    'class_distance' => json_encode($classDistance),
                    'defcost' => $cost
                ];
                
                // Обрабатываем документ
                if ($removeDocument === '1') {
                    // Удаляем старый файл с диска
                    if (!empty($eventData['filepolojenie'])) {
                        $oldFilePath = $_SERVER['DOCUMENT_ROOT'] . '/lks/' . $eventData['filepolojenie'];
                        if (file_exists($oldFilePath)) {
                            unlink($oldFilePath);
                            error_log("Удален старый файл: " . $oldFilePath);
                        }
                    }
                    $updateFields['filepolojenie'] = null;
                } elseif ($documentPath) {
                    // Удаляем старый файл при замене
                    if (!empty($eventData['filepolojenie'])) {
                        $oldFilePath = $_SERVER['DOCUMENT_ROOT'] . '/lks/' . $eventData['filepolojenie'];
                        if (file_exists($oldFilePath)) {
                            unlink($oldFilePath);
                            error_log("Заменен старый файл: " . $oldFilePath);
                        }
                    }
                    $updateFields['filepolojenie'] = $documentPath;
                }
                
                $setClause = implode(', ', array_map(fn($field) => "$field = ?", array_keys($updateFields)));
                
                // ИСПРАВЛЕНО: Логика доступа по ролям при обновлении - обновляем по oid
                if ($hasFullAccess) {
                    $stmt = $db->prepare("UPDATE meros SET $setClause WHERE oid = ?");
                    $values = array_values($updateFields);
                    $values[] = $eventData['oid'];
                } else {
                    // Получаем oid текущего пользователя для сравнения
                    $currentUserOid = $db->fetchColumn("SELECT oid FROM users WHERE userid = ?", [$user['userid']]);
                    $stmt = $db->prepare("UPDATE meros SET $setClause WHERE oid = ? AND created_by = ?");
                    $values = array_values($updateFields);
                    $values[] = $eventData['oid'];
                    $values[] = $currentUserOid;
                }
                
                $stmt->execute($values);
                $success = 'Мероприятие успешно обновлено!';
            } else {
                // Создание нового мероприятия
                // Генерируем уникальный champn
                $stmt = $db->prepare("SELECT COALESCE(MAX(champn), 0) + 1 as next_champn FROM meros");
                $stmt->execute();
                $newChampn = $stmt->fetchColumn();
                
                // ИСПРАВЛЕНО: В created_by записываем oid пользователя, а не userid
                $currentUserOid = $db->fetchColumn("SELECT oid FROM users WHERE userid = ?", [$user['userid']]);
                
                $stmt = $db->prepare("
                    INSERT INTO meros (champn, merodata, meroname, class_distance, defcost, filepolojenie, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, 'В ожидании', ?)
                ");
                
                $stmt->execute([
                    $newChampn,
                    $dates,
                    $name,
                    json_encode($classDistance),
                    $cost,
                    $documentPath,
                    $currentUserOid
                ]);
                
                $success = 'Мероприятие успешно создано!';
            }
            
        } catch (Exception $e) {
            error_log("Ошибка создания мероприятия: " . $e->getMessage());
            $error = 'Ошибка при создании мероприятия: ' . $e->getMessage();
        }
    }
}

$pageTitle = $isEdit ? 'Редактирование мероприятия' : 'Создание мероприятия';

// Функции для предзаполнения формы при редактировании
function getEventValue($field, $default = '') {
    global $eventData, $isEdit;
    if ($isEdit && $eventData && isset($eventData[$field])) {
        return htmlspecialchars($eventData[$field]);
    }
    return $default;
}

function parseClassDistanceForForm($classDistance) {
    if (empty($classDistance)) return ['classes' => '', 'gender' => '', 'distances' => '', 'age_groups' => ''];
    
    $decoded = json_decode($classDistance, true);
    if (!$decoded) return ['classes' => '', 'gender' => '', 'distances' => '', 'age_groups' => ''];
    
    $classes = [];
    $genders = [];
    $distances = [];
    $ageGroups = [];
    
    // ИСПРАВЛЕНО: Сохраняем соответствие между классами, полами и дистанциями
    foreach ($decoded as $class => $details) {
        $classes[] = $class;
        
        // Для каждого класса собираем его полы
        if (isset($details['sex']) && is_array($details['sex'])) {
            $genders[] = implode(',', $details['sex']);
        } else {
            $genders[] = 'М,Ж'; // По умолчанию
        }
        
        // Для каждого класса собираем его дистанции  
        if (isset($details['dist']) && is_array($details['dist'])) {
            // Обрабатываем случай когда дистанции могут быть массивом строк с запятыми
            $classDist = [];
            foreach ($details['dist'] as $distItem) {
                if (is_string($distItem) && strpos($distItem, ',') !== false) {
                    // Если дистанция содержит запятые, это строка вида "200, 500, 1000"
                    $classDist[] = $distItem;
                } else {
                    // Отдельная дистанция
                    $classDist[] = trim($distItem);
                }
            }
            $distances[] = implode('|', $classDist); // Разделяем дистанции для разных полов через |
        } else {
            $distances[] = '200, 500'; // По умолчанию
        }
        
        // Возрастные группы - ИСПРАВЛЕНО: правильный парсинг
        if (isset($details['age_group']) && is_array($details['age_group'])) {
            // Для каждого класса собираем возрастные группы в одну строку
            $classAgeGroups = [];
            foreach ($details['age_group'] as $ageGroupString) {
                // Очищаем от переносов строк
                $ageGroupString = str_replace(["\r\n", "\n", "\r"], ', ', $ageGroupString);
                $ageGroupString = preg_replace('/\s*,\s*/', ', ', $ageGroupString);
                $ageGroupString = trim($ageGroupString);
                
                if (!empty($ageGroupString)) {
                    $classAgeGroups[] = $ageGroupString;
                }
            }
            // Объединяем все возрастные группы для класса в одну строку
            $ageGroups[] = implode(' | ', $classAgeGroups);
        }
    }
    
    return [
        'classes' => implode('; ', $classes),
        'gender' => implode('; ', $genders), // Теперь каждый класс имеет свои полы
        'distances' => implode('; ', $distances), // Теперь каждый класс имеет свои дистанции
        'age_groups' => implode('; ', array_unique($ageGroups))
    ];
}

$formData = ['classes' => '', 'gender' => 'М; Ж', 'distances' => '', 'age_groups' => ''];
if ($isEdit && $eventData && !empty($eventData['class_distance'])) {
    $formData = parseClassDistanceForForm($eventData['class_distance']);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - KGB-Pulse</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../../css/style.css" rel="stylesheet">
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-8 mx-auto">
                    <div class="card shadow">
                        <div class="card-header bg-<?= $isEdit ? 'warning' : 'success' ?> text-white">
                            <h4 class="mb-0">
                                <i class="bi bi-<?= $isEdit ? 'pencil-square' : 'plus-circle' ?> me-2"></i><?= $pageTitle ?>
                            </h4>
                        </div>
                        <div class="card-body">
                            <?php if ($error): ?>
                                <div class="alert alert-danger">
                                    <i class="bi bi-exclamation-triangle me-2"></i>
                                    <?= htmlspecialchars($error) ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($success): ?>
                                <div class="alert alert-success">
                                    <i class="bi bi-check-circle me-2"></i>
                                    <?= htmlspecialchars($success) ?>
                                </div>
                            <?php endif; ?>

                            <!-- Выбор способа создания -->
                            <div class="row mb-4" id="creationMethods" <?= $isEdit ? 'style="display: none;"' : 'style="display: flex;"' ?>>
                                <div class="col-md-6">
                                    <div class="card border-primary h-100">
                                        <div class="card-body text-center">
                                            <i class="bi bi-file-earmark-arrow-up display-1 text-primary"></i>
                                            <h5>Загрузить из файла</h5>
                                            <p class="text-muted">Автозаполнение из Excel файла</p>
                                            
                                            <!-- Кнопка скачивания примера -->
                                            <div class="mb-3">
                                                <a href="../../php/organizer/download-example.php" class="btn btn-outline-primary btn-sm">
                                                    <i class="bi bi-download me-1"></i>Скачать пример
                                                </a>
                                            </div>
                                            
                                            <input type="file" id="excelFile" class="form-control" accept=".xlsx,.xls">
                                            
                                            <!-- Индикатор загрузки -->
                                            <div id="fileProcessing" class="mt-3" style="display: none;">
                                                <div class="spinner-border spinner-border-sm text-primary" role="status">
                                                    <span class="visually-hidden">Обработка...</span>
                                                </div>
                                                <span class="ms-2 text-primary">Обработка файла...</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card border-secondary h-100">
                                        <div class="card-body text-center">
                                            <i class="bi bi-pencil-square display-1 text-secondary"></i>
                                            <h5>Ручной ввод</h5>
                                            <p class="text-muted">Заполнить форму вручную</p>
                                            <button type="button" class="btn btn-secondary" onclick="showManualForm()">
                                                Заполнить вручную
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Форма создания мероприятия -->
                            <form id="eventForm" style="display: <?= $isEdit ? 'block' : 'none' ?>;"><?php if ($isEdit): ?>
                                <input type="hidden" name="edit_id" value="<?= $eventData['oid'] ?>">
                            <?php endif; ?>
                                <div id="alerts"></div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="merodata" class="form-label">Дата проведения *</label>
                                            <div class="row">
                                                <div class="col-md-8">
                                                    <input type="text" class="form-control" id="merodata" name="merodata" 
                                                           placeholder="1 - 5 июля или 31 мая - 1 июня" 
                                                           value="<?= getEventValue('merodata') ?>" required>
                                                    <div class="form-text">Введите дату в формате "1 - 5 июля" или "31 мая - 1 июня"</div>
                                                </div>
                                                <div class="col-md-4">
                                                    <input type="number" class="form-control" id="event_year" name="event_year" 
                                                           placeholder="2025" min="2024" max="2030" 
                                                           value="<?= getEventValue('event_year', date('Y')) ?>" required>
                                                    <div class="form-text">Год проведения</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="name" class="form-label">
                                                Наименование соревнований <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" class="form-control" id="name" name="name" 
                                                   value="<?= getEventValue('meroname') ?>" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="classes" class="form-label">
                                                Классы лодок <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" class="form-control" id="classes" name="classes" 
                                                   placeholder="K-1; C-1; K-2" value="<?= htmlspecialchars($formData['classes']) ?>" required>
                                            <div class="form-text">Разделяйте классы знаком «;»</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="distances" class="form-label">
                                                Дистанции <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" class="form-control" id="distances" name="distances" 
                                                   placeholder="200; 500; 1000" value="<?= htmlspecialchars($formData['distances']) ?>" required>
                                            <div class="form-text">Разделяйте дистанции знаком «;»</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="gender" class="form-label">Пол</label>
                                            <input type="text" class="form-control" id="gender" name="gender" 
                                                   placeholder="М; Ж; MIX" value="<?= htmlspecialchars($formData['gender']) ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="cost" class="form-label">
                                                Стоимость участия <span class="text-danger">*</span>
                                            </label>
                                            <input type="number" class="form-control" id="cost" name="cost" 
                                                   placeholder="3000" value="<?= getEventValue('defcost') ?>" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="age_groups" class="form-label">Возрастные группы</label>
                                    <textarea class="form-control" id="age_groups" name="age_groups" rows="3"
                                              placeholder="группа 1: 10-20; группа 2: 21-30"><?= htmlspecialchars($formData['age_groups']) ?></textarea>
                                </div>

                                <div class="mb-3">
                                    <label for="document" class="form-label">Положение о соревнованиях</label>
                                    <?php if ($isEdit && $eventData && !empty($eventData['filepolojenie'])): ?>
                                        <div class="mb-2 p-3 border rounded bg-light">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <i class="bi bi-file-earmark-text text-primary me-2"></i>
                                                    <span class="fw-bold">Текущий файл:</span>
                                                    <span class="text-muted"><?= htmlspecialchars(basename($eventData['filepolojenie'])) ?></span>
                                                </div>
                                                <div>
                                                    <a href="/lks/<?= htmlspecialchars($eventData['filepolojenie']) ?>" 
                                                       class="btn btn-sm btn-outline-primary me-2" target="_blank">
                                                        <i class="bi bi-download"></i> Скачать
                                                    </a>
                                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                                            onclick="removeCurrentDocument()">
                                                        <i class="bi bi-trash"></i> Удалить
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <input type="hidden" id="remove_document" name="remove_document" value="0">
                                    <?php endif; ?>
                                    <input type="file" class="form-control" id="document" name="document" accept=".pdf,.doc,.docx">
                                    <div class="form-text">
                                        <?php if ($isEdit && $eventData && !empty($eventData['filepolojenie'])): ?>
                                            Выберите новый файл, если хотите заменить текущий документ.
                                        <?php else: ?>
                                            Загрузите файл с положением о соревнованиях (PDF, DOC, DOCX).
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between">
                                    <button type="button" class="btn btn-outline-secondary" onclick="goBack()">
                                        <i class="bi bi-arrow-left me-1"></i>Назад
                                    </button>
                                    <button type="submit" class="btn btn-<?= $isEdit ? 'warning' : 'success' ?>" id="submitBtn">
                                        <span class="btn-text"><?= $isEdit ? 'Обновить мероприятие' : 'Создать мероприятие' ?></span>
                                        <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Примеры и подсказки -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="bi bi-info-circle me-2"></i>Примеры заполнения
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <h6><i class="bi bi-file-earmark-excel text-success"></i> Работа с Excel файлом:</h6>
                                    <ol class="small">
                                        <li>Скачайте пример файла</li>
                                        <li>Заполните данные в Excel</li>
                                        <li>Загрузите файл для автозаполнения</li>
                                        <li>Проверьте и отредактируйте данные</li>
                                    </ol>
                                </div>
                                <div class="col-md-4">
                                    <h6>Классы лодок:</h6>
                                    <ul class="list-unstyled small">
                                        <li><code>K-1; K-2; K-4</code> - байдарки</li>
                                        <li><code>C-1; C-2; C-4</code> - каноэ</li>
                                        <li><code>D-10</code> - лодки дракон</li>
                                    </ul>
                                </div>
                                <div class="col-md-4">
                                    <h6>Дистанции:</h6>
                                    <ul class="list-unstyled small">
                                        <li><code>200; 500; 1000</code> - спринт</li>
                                        <li><code>5000; 10000</code> - длинные дистанции</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showManualForm() {
            document.getElementById('eventForm').style.display = 'block';
            document.getElementById('creationMethods').style.display = 'none';
        }

        function goBack() {
            const isEdit = <?= $isEdit ? 'true' : 'false' ?>;
            
            if (isEdit) {
                // При редактировании возвращаемся к списку мероприятий
                window.location.href = 'events.php';
            } else {
                // При создании возвращаемся к выбору способа создания
                document.getElementById('eventForm').style.display = 'none';
                document.getElementById('creationMethods').style.display = 'flex';
                
                // Очищаем форму
                document.getElementById('eventForm').reset();
                document.getElementById('alerts').innerHTML = '';
                
                // Сбрасываем выбор файла
                document.getElementById('excelFile').value = '';
                document.getElementById('fileProcessing').style.display = 'none';
            }
        }

        function showAlert(message, type = 'danger') {
            const alertDiv = document.getElementById('alerts');
            alertDiv.innerHTML = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
        }

        function setLoading(isLoading) {
            const submitBtn = document.getElementById('submitBtn');
            const btnText = submitBtn.querySelector('.btn-text');
            const spinner = submitBtn.querySelector('.spinner-border');
            const isEdit = <?= $isEdit ? 'true' : 'false' ?>;
            
            if (isLoading) {
                btnText.textContent = isEdit ? 'Обновление...' : 'Создание...';
                spinner.classList.remove('d-none');
                submitBtn.disabled = true;
            } else {
                btnText.textContent = isEdit ? 'Обновить мероприятие' : 'Создать мероприятие';
                spinner.classList.add('d-none');
                submitBtn.disabled = false;
            }
        }

        document.getElementById('excelFile').addEventListener('change', async function(e) {
            const file = e.target.files[0];
            if (file) {
                await processExcelFile(file);
            }
        });

        async function processExcelFile(file) {
            const fileProcessing = document.getElementById('fileProcessing');
            const alertsDiv = document.getElementById('alerts');
            
            try {
                // Показываем индикатор загрузки
                fileProcessing.style.display = 'block';
                alertsDiv.innerHTML = '';
                
                // Создаем FormData для отправки файла
                const formData = new FormData();
                formData.append('file', file);
                
                // Отправляем файл на обработку
                const response = await fetch('../../php/organizer/parse-event-file.php', {
                    method: 'POST',
                    body: formData
                });
                
                // Проверяем статус ответа
                if (!response.ok) {
                    throw new Error(`Ошибка сервера: ${response.status} ${response.statusText}`);
                }
                
                // Пытаемся распарсить JSON
                let result;
                try {
                    result = await response.json();
                } catch (jsonError) {
                    throw new Error('Сервер вернул некорректный ответ. Возможно, произошла внутренняя ошибка сервера.');
                }
                
                if (result.success) {
                    // Заполняем форму данными из файла
                    fillFormWithData(result.data);
                    showManualForm();
                    showAlert('Файл успешно обработан! Проверьте автозаполненные данные.', 'success');
                } else {
                    // Показываем детальную ошибку с рекомендациями
                    let errorMessage = result.error || 'Неизвестная ошибка';
                    if (result.user_action) {
                        errorMessage += '<br><small class="text-muted"><i class="bi bi-info-circle"></i> ' + result.user_action + '</small>';
                    }
                    showAlert(errorMessage, 'danger');
                    
                    // Логируем техническую ошибку для отладки
                    if (result.details && result.details.technical_error) {
                        console.error('Техническая ошибка:', result.details.technical_error);
                    }
                }
                
            } catch (error) {
                console.error('Ошибка при обработке файла:', error);
                showAlert('Произошла ошибка при обработке файла', 'danger');
            } finally {
                // Скрываем индикатор загрузки
                fileProcessing.style.display = 'none';
            }
        }

        function removeCurrentDocument() {
            if (confirm('Вы действительно хотите удалить текущий документ?')) {
                // Устанавливаем флаг удаления
                document.getElementById('remove_document').value = '1';
                
                // Скрываем блок с текущим файлом
                const currentFileBlock = document.querySelector('.bg-light.border.rounded');
                if (currentFileBlock) {
                    currentFileBlock.style.display = 'none';
                }
                
                // Показываем уведомление
                const fileInput = document.getElementById('document');
                const helpText = fileInput.nextElementSibling;
                helpText.innerHTML = '<span class="text-warning"><i class="bi bi-exclamation-triangle"></i> Текущий документ будет удален при сохранении. Загрузите новый файл или оставьте поле пустым.</span>';
            }
        }

        function fillFormWithData(data) {
            // Заполняем поля формы данными из Excel файла
            if (data.year) {
                document.getElementById('event_year').value = data.year;
            }
            if (data.dates) {
                document.getElementById('merodata').value = data.dates;
            }
            if (data.name) {
                document.getElementById('name').value = data.name;
            }
            if (data.classes) {
                document.getElementById('classes').value = data.classes;
            }
            if (data.gender) {
                document.getElementById('gender').value = data.gender;
            }
            if (data.distances) {
                document.getElementById('distances').value = data.distances;
            }
            if (data.age_groups) {
                document.getElementById('age_groups').value = data.age_groups;
            }
            if (data.cost) {
                document.getElementById('cost').value = data.cost;
            }
        }

        document.getElementById('eventForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            setLoading(true);
            document.getElementById('alerts').innerHTML = '';
            
            try {
                const formData = new FormData();
                
                // Собираем данные формы
                formData.append('year', document.getElementById('event_year').value);
                formData.append('dates', document.getElementById('merodata').value);
                formData.append('name', document.getElementById('name').value);
                formData.append('classes', document.getElementById('classes').value);
                formData.append('gender', document.getElementById('gender').value);
                formData.append('distances', document.getElementById('distances').value);
                formData.append('age_groups', document.getElementById('age_groups').value);
                formData.append('cost', document.getElementById('cost').value);
                
                // Добавляем ID для редактирования если нужно
                const editIdInput = document.querySelector('input[name="edit_id"]');
                if (editIdInput) {
                    formData.append('edit_id', editIdInput.value);
                }
                
                // Добавляем файл, если выбран
                const documentFile = document.getElementById('document').files[0];
                if (documentFile) {
                    formData.append('document', documentFile);
                }
                
                const response = await fetch('../../php/organizer/create-event.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    const isEdit = <?= $isEdit ? 'true' : 'false' ?>;
                    showAlert(isEdit ? 'Мероприятие успешно обновлено!' : 'Мероприятие успешно создано!', 'success');
                    setTimeout(() => {
                        window.location.href = 'events.php';
                    }, 2000);
                } else {
                    showAlert(result.error || 'Произошла ошибка при ' + (<?= $isEdit ? 'true' : 'false' ?> ? 'обновлении' : 'создании') + ' мероприятия');
                }
            } catch (error) {
                console.error('Ошибка:', error);
                showAlert('Произошла ошибка при отправке данных');
            } finally {
                setLoading(false);
            }
        });
    </script>
</body>
</html> 
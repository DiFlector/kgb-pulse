<?php
if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../../php/helpers.php";
require_once __DIR__ . "/../../php/db/Database.php";

// Проверка авторизации
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Organizer', 'Admin', 'SuperUser'])) {
    http_response_code(403);
    echo "Доступ запрещен";
    exit;
}

$error = '';
$success = '';
$parsedData = null;
$formData = null;
$databaseData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['event_file'])) {
    try {
        $file = $_FILES['event_file'];
        
        // Проверяем загрузку файла
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Ошибка загрузки файла: ' . $file['error']);
        }
        
        // Проверяем тип файла
        $allowedTypes = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'];
        if (!in_array($file['type'], $allowedTypes)) {
            throw new Exception('Неверный тип файла. Разрешены только Excel файлы (.xlsx, .xls)');
        }
        
        // Подключаем PhpSpreadsheet
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
        
        // Загружаем файл
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file['tmp_name']);
        $worksheet = $spreadsheet->getActiveSheet();
        
        // Парсим файл
        $parsedData = parseEventFile($worksheet);
        
        // Преобразуем данные в формат для формы
        $formData = [
            'year' => $parsedData['year'] ?? date('Y'),
            'dates' => $parsedData['date'] ?? '',
            'name' => $parsedData['meroname'] ?? '',
            'classes' => '',
            'gender' => '',
            'distances' => '',
            'age_groups' => '',
            'cost' => $parsedData['cost'] ?? '3000'
        ];
        
        // Обрабатываем class_distance структуру
        if (isset($parsedData['class_distance']) && is_array($parsedData['class_distance'])) {
            $classes = [];
            $genderParts = [];
            $distanceParts = [];
            $ageGroupParts = [];
            
            foreach ($parsedData['class_distance'] as $className => $classData) {
                $classes[] = $className;
                
                // Обрабатываем пол для каждого класса
                if (isset($classData['sex']) && is_array($classData['sex'])) {
                    $genderParts[] = implode(', ', $classData['sex']);
                }
                
                // Обрабатываем дистанции для каждого класса
                if (isset($classData['dist']) && is_array($classData['dist'])) {
                    $classDistances = [];
                    foreach ($classData['dist'] as $dist) {
                        if (is_array($dist)) {
                            $classDistances[] = implode(', ', $dist);
                        } else {
                            $distParts = explode(',', $dist);
                            $distParts = array_map('trim', $distParts);
                            $classDistances[] = implode(', ', $distParts);
                        }
                    }
                    $distanceParts[] = implode(' | ', $classDistances);
                }
                
                // ИСПРАВЛЕНО: Правильный парсинг возрастных групп для каждого класса
                if (isset($classData['age_group']) && is_array($classData['age_group'])) {
                    // Собираем возрастные группы в правильном формате
                    $classAgeGroups = [];
                    foreach ($classData['age_group'] as $ageGroupString) {
                        // Очищаем от переносов строк
                        $ageGroupString = str_replace(["\r\n", "\n", "\r"], ', ', $ageGroupString);
                        $ageGroupString = preg_replace('/\s*,\s*/', ', ', $ageGroupString);
                        $ageGroupString = trim($ageGroupString);
                        
                        if (!empty($ageGroupString)) {
                            $classAgeGroups[] = $ageGroupString;
                        }
                    }
                    // Объединяем все возрастные группы для класса в одну строку
                    $classAgeGroupString = implode(' | ', $classAgeGroups);
                    $ageGroupParts[] = $classAgeGroupString;
                }
            }
            
            $formData['classes'] = implode('; ', $classes);
            $formData['gender'] = implode('; ', $genderParts);
            $formData['distances'] = implode('; ', $distanceParts);
            $formData['age_groups'] = implode('; ', $ageGroupParts);
        }
        
        // Симулируем данные для БД
        $databaseData = [
            'champn' => 2025001,
            'merodata' => $formData['dates'],
            'meroname' => $formData['name'],
            'class_distance' => json_encode($parsedData['class_distance'] ?? []),
            'defcost' => $formData['cost'],
            'status' => 'В ожидании'
        ];
        
        $success = 'Файл успешно обработан!';
        
    } catch (Exception $e) {
        $error = 'Ошибка обработки файла: ' . $e->getMessage();
    }
}

/**
 * Функция парсинга Excel файла
 */
function parseEventFile($worksheet) {
    $lastRow = $worksheet->getHighestRow();
    
    // Получаем основные данные из строки 2
    $array = [
        'year' => $worksheet->getCell('A2')->getCalculatedValue(),
        'date' => $worksheet->getCell('B2')->getCalculatedValue(), 
        'meroname' => $worksheet->getCell('C2')->getCalculatedValue(),
        'cost' => $worksheet->getCell('H2')->getCalculatedValue()
    ];
    
    $mas = [];
    $currentClass = '';
    
    // Обрабатываем строки с данными классов
    for ($row = 2; $row <= $lastRow; $row++) {
        $cellD = trim($worksheet->getCell('D' . $row)->getCalculatedValue() ?? '');
        $cellE = trim($worksheet->getCell('E' . $row)->getCalculatedValue() ?? '');
        $cellF = trim($worksheet->getCell('F' . $row)->getCalculatedValue() ?? '');
        $cellG = trim($worksheet->getCell('G' . $row)->getCalculatedValue() ?? '');
        
        if (!empty($cellD)) {
            $currentClass = $cellD;
            $mas[$currentClass] = [
                'sex' => [],
                'dist' => [],
                'age_group' => []
            ];
        }
        
        if (!empty($currentClass) && (!empty($cellE) || !empty($cellF) || !empty($cellG))) {
            if (!empty($cellE)) {
                $mas[$currentClass]['sex'][] = $cellE;
            }
            
            if (!empty($cellF)) {
                $mas[$currentClass]['dist'][] = $cellF;
            }
            
            if (!empty($cellG)) {
                // Очищаем от переносов строк и лишних символов
                $ageGroup = str_replace(["\r\n", "\n", "\r"], ', ', $cellG);
                $ageGroup = preg_replace('/\s*,\s*/', ', ', $ageGroup);
                $ageGroup = preg_replace('/\s+/', ' ', $ageGroup); // Убираем множественные пробелы
                $ageGroup = trim($ageGroup);
                
                if (!empty($ageGroup) && !in_array($ageGroup, $mas[$currentClass]['age_group'])) {
                    $mas[$currentClass]['age_group'][] = $ageGroup;
                }
            }
        }
    }
    
    $array['class_distance'] = $mas;
    return $array;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тест парсера файла мероприятия - KGB-Pulse</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../../css/style.css" rel="stylesheet">
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h1><i class="bi bi-file-earmark-spreadsheet"></i> Тест парсера файла мероприятия</h1>
                        <a href="create-event.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Назад к созданию
                        </a>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Форма загрузки файла -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5><i class="bi bi-upload"></i> Загрузка файла мероприятия</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data">
                                <div class="mb-3">
                                    <label for="event_file" class="form-label">Выберите Excel файл</label>
                                    <input type="file" class="form-control" id="event_file" name="event_file" 
                                           accept=".xlsx,.xls" required>
                                    <div class="form-text">
                                        Поддерживаются файлы Excel (.xlsx, .xls) с данными мероприятия
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-upload"></i> Загрузить и проанализировать
                                </button>
                            </form>
                        </div>
                    </div>

                    <?php if ($parsedData): ?>
                        <!-- Результаты парсинга -->
                        <div class="row">
                            <!-- Данные для формы -->
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h5><i class="bi bi-form"></i> Данные для формы</h5>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-sm">
                                            <tbody>
                                                <tr>
                                                    <th>Год:</th>
                                                    <td><?= htmlspecialchars($formData['year']) ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Даты:</th>
                                                    <td><?= htmlspecialchars($formData['dates']) ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Название:</th>
                                                    <td><?= htmlspecialchars($formData['name']) ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Классы:</th>
                                                    <td><code><?= htmlspecialchars($formData['classes']) ?></code></td>
                                                </tr>
                                                <tr>
                                                    <th>Полы:</th>
                                                    <td><code><?= htmlspecialchars($formData['gender']) ?></code></td>
                                                </tr>
                                                <tr>
                                                    <th>Дистанции:</th>
                                                    <td><code><?= htmlspecialchars($formData['distances']) ?></code></td>
                                                </tr>
                                                <tr>
                                                    <th>Возрастные группы:</th>
                                                    <td><code><?= htmlspecialchars($formData['age_groups']) ?></code></td>
                                                </tr>
                                                <tr>
                                                    <th>Стоимость:</th>
                                                    <td><?= htmlspecialchars($formData['cost']) ?></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- Данные для БД -->
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h5><i class="bi bi-database"></i> Данные для БД</h5>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-sm">
                                            <tbody>
                                                <tr>
                                                    <th>champn:</th>
                                                    <td><?= htmlspecialchars($databaseData['champn']) ?></td>
                                                </tr>
                                                <tr>
                                                    <th>merodata:</th>
                                                    <td><?= htmlspecialchars($databaseData['merodata']) ?></td>
                                                </tr>
                                                <tr>
                                                    <th>meroname:</th>
                                                    <td><?= htmlspecialchars($databaseData['meroname']) ?></td>
                                                </tr>
                                                <tr>
                                                    <th>defcost:</th>
                                                    <td><?= htmlspecialchars($databaseData['defcost']) ?></td>
                                                </tr>
                                                <tr>
                                                    <th>status:</th>
                                                    <td><?= htmlspecialchars($databaseData['status']) ?></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                        
                                        <h6>class_distance (JSON):</h6>
                                        <pre class="bg-light p-2 rounded" style="max-height: 400px; overflow-y: auto;"><code><?= htmlspecialchars(json_encode($databaseData['class_distance'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></code></pre>
                                        
                                        <h6>Проверка структуры данных:</h6>
                                        <?php 
                                        $hasErrors = false;
                                        $errors = [];
                                        
                                        if (isset($parsedData['class_distance'])) {
                                            foreach ($parsedData['class_distance'] as $className => $classData) {
                                                // Проверяем полы
                                                if (isset($classData['sex']) && is_array($classData['sex'])) {
                                                    foreach ($classData['sex'] as $sex) {
                                                        if (strpos($sex, 'группа') !== false) {
                                                            $hasErrors = true;
                                                            $errors[] = "Класс $className: В поле 'sex' найдены возрастные группы: $sex";
                                                        }
                                                    }
                                                }
                                                
                                                // Проверяем возрастные группы
                                                if (isset($classData['age_group']) && is_array($classData['age_group'])) {
                                                    foreach ($classData['age_group'] as $ageGroup) {
                                                        if (strpos($ageGroup, "\r\n") !== false || strpos($ageGroup, "\n") !== false) {
                                                            $hasErrors = true;
                                                            $errors[] = "Класс $className: В возрастных группах найдены переносы строк";
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                        
                                        if ($hasErrors): ?>
                                            <div class="alert alert-danger">
                                                <h6>❌ Обнаружены ошибки:</h6>
                                                <ul>
                                                    <?php foreach ($errors as $error): ?>
                                                        <li><?= htmlspecialchars($error) ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-success">
                                                <h6>✅ Структура данных корректна!</h6>
                                                <p>Все поля содержат правильные данные без ошибок.</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Детальный анализ возрастных групп -->
                        <?php if (isset($parsedData['class_distance'])): ?>
                            <div class="card mt-4">
                                <div class="card-header">
                                    <h5><i class="bi bi-list-check"></i> Детальный анализ возрастных групп</h5>
                                </div>
                                <div class="card-body">
                                    <?php foreach ($parsedData['class_distance'] as $className => $classData): ?>
                                        <div class="mb-4">
                                            <h6>Класс: <strong><?= htmlspecialchars($className) ?></strong></h6>
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <strong>Полы:</strong>
                                                    <ul class="list-unstyled">
                                                        <?php foreach ($classData['sex'] as $sex): ?>
                                                            <li><code><?= htmlspecialchars($sex) ?></code></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                                <div class="col-md-4">
                                                    <strong>Дистанции:</strong>
                                                    <ul class="list-unstyled">
                                                        <?php foreach ($classData['dist'] as $dist): ?>
                                                            <li><code><?= htmlspecialchars($dist) ?></code></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                                <div class="col-md-4">
                                                    <strong>Возрастные группы:</strong>
                                                    <ul class="list-unstyled">
                                                        <?php foreach ($classData['age_group'] as $ageGroup): ?>
                                                            <li><code><?= htmlspecialchars($ageGroup) ?></code></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 
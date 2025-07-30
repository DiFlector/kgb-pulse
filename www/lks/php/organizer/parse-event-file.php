<?php
/**
 * API для обработки Excel файла мероприятия
 * Извлекает данные из Excel и возвращает в формате для автозаполнения формы
 */

// ВАЖНО: Никакого вывода до этого момента!
if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/../common/FileUploadHandler.php";

// В режиме тестирования пропускаем проверку аутентификации
if (!defined('TEST_MODE')) {
    $auth = new Auth();
    $user = $auth->checkRole(['Organizer', 'SuperUser', 'Admin']);
    if (!$user) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Метод не поддерживается']);
    exit;
}

try {
    // Валидируем загруженный файл с помощью FileUploadHandler
    $validationResult = FileUploadHandler::validateUploadedFile(
        $_FILES['file'] ?? null, 
        ['xlsx', 'xls'], 
        10 * 1024 * 1024 // 10 МБ максимум
    );
    
    if (!$validationResult['success']) {
        echo json_encode(FileUploadHandler::formatErrorResponse(
            $validationResult['error'], 
            $validationResult['error_type']
        ));
        exit;
    }
    
    $fileInfo = $validationResult['file_info'];
    $fileTmpName = $fileInfo['tmp_name'];

    // Подключаем PhpSpreadsheet
    require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
    
    // Используем универсальный reader для автоматического определения типа файла
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fileTmpName);
    $worksheet = $spreadsheet->getActiveSheet();
    
    // Парсим файл по структуре старого кода
    $eventData = parseEventFile($worksheet);
    
    // Преобразуем данные в нужный формат для формы
    $formData = [
        'year' => $eventData['year'] ?? date('Y'),
        'dates' => $eventData['date'] ?? '',
        'name' => $eventData['meroname'] ?? '',
        'classes' => '',
        'gender' => '',
        'distances' => '',
        'age_groups' => '',
        'cost' => $eventData['cost'] ?? '3000'
    ];
    
    // Обрабатываем class_distance структуру согласно требуемому формату
    if (isset($eventData['class_distance']) && is_array($eventData['class_distance'])) {
        $classes = [];
        $genderParts = [];
        $distanceParts = [];
        $ageGroupParts = [];
        
        foreach ($eventData['class_distance'] as $className => $classData) {
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
                // Дистанции разных полов в одном классе разделяем " | "
                $distanceParts[] = implode(' | ', $classDistances);
            }
            
            // Обрабатываем возрастные группы для каждого класса
            if (isset($classData['age_group']) && is_array($classData['age_group'])) {
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
    
    // Устанавливаем значения по умолчанию, если не найдены
    if (empty($formData['classes'])) {
        $formData['classes'] = 'K-1; C-1; K-2; C-2';
    }
    
    if (empty($formData['gender'])) {
        $formData['gender'] = 'М; Ж';
    }
    
    if (empty($formData['distances'])) {
        $formData['distances'] = '200; 500; 1000';
    }
    
    // Очищаем временный файл
    unlink($fileTmpName);
    
    echo json_encode([
        'success' => true,
        'data' => $formData,
        'message' => 'Файл успешно обработан'
    ]);

} catch (Exception $e) {
    error_log("Ошибка обработки Excel файла: " . $e->getMessage() . " в файле " . $e->getFile() . " на строке " . $e->getLine());
    
    // Удаляем временный файл в случае ошибки
    if (isset($fileTmpName) && file_exists($fileTmpName)) {
        unlink($fileTmpName);
    }
    
    // Определяем тип ошибки для пользователя
    $errorType = FileUploadHandler::ERROR_TYPES['SERVER_ERROR'];
    $userMessage = 'Ошибка обработки файла: ' . $e->getMessage();
    
    // Если это ошибка PhpSpreadsheet, даем более понятное сообщение
    if (strpos($e->getMessage(), 'PhpOffice') !== false || strpos($e->getMessage(), 'spreadsheet') !== false) {
        $errorType = FileUploadHandler::ERROR_TYPES['FILE_ERROR'];
        $userMessage = 'Не удалось прочитать Excel файл. Убедитесь, что файл не поврежден и соответствует формату Excel';
    }
    
    echo json_encode(FileUploadHandler::formatErrorResponse($userMessage, $errorType, [
        'technical_error' => $e->getMessage()
    ]));
}

/**
 * Функция парсинга Excel файла по структуре из старого кода
 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet
 * @return array
 */
function parseEventFile($worksheet) {
    $lastRow = $worksheet->getHighestRow();
    
    // Получаем основные данные из строки 2
    $array = [
        'year' => $worksheet->getCell('A2')->getCalculatedValue(),
        'date' => $worksheet->getCell('B2')->getCalculatedValue(), 
        'meroname' => $worksheet->getCell('C2')->getCalculatedValue(),
        'cost' => $worksheet->getCell('H2')->getCalculatedValue() ?: '3000'
    ];
    
    $mas = [];
    $currentClass = ''; // Текущий класс для группировки строк
    
    // Парсим данные построчно начиная со строки 2 (строка 1 - заголовки)
    for ($row = 2; $row <= $lastRow; $row++) {
        $cellD = trim($worksheet->getCell('D'.$row)->getCalculatedValue() ?? '');
        $cellE = trim($worksheet->getCell('E'.$row)->getCalculatedValue() ?? '');
        $cellF = trim($worksheet->getCell('F'.$row)->getCalculatedValue() ?? '');
        $cellG = trim($worksheet->getCell('G'.$row)->getCalculatedValue() ?? '');
        
        // Пропускаем полностью пустые строки
        if (empty($cellD) && empty($cellE) && empty($cellF) && empty($cellG)) {
            continue;
        }
        
        // Если есть класс лодки, это начало новой группы
        if (!empty($cellD)) {
            $currentClass = $cellD;
            // Инициализируем класс, если его еще нет
            if (!isset($mas[$currentClass])) {
                $mas[$currentClass] = [
                    'sex' => [],
                    'dist' => [],
                    'age_group' => []
                ];
            }
        }
        
        // Если есть текущий класс и данные для строки
        if (!empty($currentClass) && (!empty($cellE) || !empty($cellF) || !empty($cellG))) {
            // Обрабатываем пол (должен быть М, Ж или MIX)
            if (!empty($cellE)) {
                $sex = $cellE;
                if (!in_array($sex, $mas[$currentClass]['sex'])) {
                    $mas[$currentClass]['sex'][] = $sex;
                }
            }
            
            // Обрабатываем дистанции
            if (!empty($cellF)) {
                $mas[$currentClass]['dist'][] = $cellF;
            }
            
            // Обрабатываем возрастные группы
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
    
    // Нормализуем данные для каждого класса
    foreach ($mas as $class => &$classData) {
        // Убираем дубликаты
        $classData['sex'] = array_unique($classData['sex']);
        $classData['dist'] = array_unique($classData['dist']);
        $classData['age_group'] = array_unique($classData['age_group']);
        
        // Если полы не указаны, устанавливаем по умолчанию
        if (empty($classData['sex'])) {
            $classData['sex'] = ['М', 'Ж'];
        }
        
        // Если дистанции не указаны, устанавливаем по умолчанию
        if (empty($classData['dist'])) {
            $classData['dist'] = ['200, 500'];
        }
        
        // Если возрастные группы не указаны, устанавливаем по умолчанию
        if (empty($classData['age_group'])) {
            $classData['age_group'] = ['группа 1: 18-39, группа 2: 40-59, группа 3: 60-150'];
        }
    }

    $array['class_distance'] = $mas;
    return $array;
}

?> 
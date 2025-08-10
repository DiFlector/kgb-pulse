<?php
if (!defined('TEST_MODE') && session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../helpers.php";
require_once __DIR__ . "/../db/Database.php";

// Проверка авторизации
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Organizer', 'Admin', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Доступ запрещен']);
    exit;
}

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

try {
    // ПРАВИЛЬНОЕ подключение к базе данных через класс Database
    $database = Database::getInstance();
    $db = $database->getPDO();
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Получаем данные из формы
        $editId = $_POST['edit_id'] ?? '';
        $year = $_POST['year'] ?? $_POST['event_year'] ?? '';
        $dates = $_POST['dates'] ?? $_POST['merodata'] ?? '';
        $name = $_POST['name'] ?? '';
        $classes = $_POST['classes'] ?? '';
        $gender = $_POST['gender'] ?? '';
        $distances = $_POST['distances'] ?? '';
        $cost = $_POST['cost'] ?? '0';
        $ageGroups = $_POST['age_groups'] ?? '';
        $removeDocument = $_POST['remove_document'] ?? '0';
        
        // Проверяем режим редактирования
        $isEdit = !empty($editId) && is_numeric($editId);
        
        // Валидация данных
        if (empty($year) || empty($dates) || empty($name) || empty($classes) || empty($cost)) {
            throw new Exception('Все обязательные поля должны быть заполнены');
        }
        
        // Валидация стоимости
        if (!is_numeric($cost) || floatval($cost) < 0) {
            throw new Exception('Стоимость должна быть положительным числом');
        }
        
        // Создаем полную дату в нужном формате
        $merodata = createEventDate($dates, $year);
        
        if (!isValidEventDate($merodata)) {
            throw new Exception('Неверный формат даты. Используйте формат "1 - 5 июля" и год "2025"');
        }
        
        // Парсим классы и дистанции
        $classDistance = [];
        
        // Проверяем формат данных: новый (раздельные поля) или старый (объединенное поле)
        if (!empty($gender) && !empty($distances)) {
            // НОВЫЙ ФОРМАТ: раздельные поля classes, gender, distances
            $classesArray = array_map('trim', explode(';', $classes));
            $genderArray = array_map('trim', explode(';', $gender));
            $distancesArray = array_map('trim', explode(';', $distances));
            
            // Обрабатываем каждый класс
            foreach ($classesArray as $i => $className) {
                if (empty($className)) continue;
                
                // Получаем полы для данного класса
                $classGenders = [];
                if (isset($genderArray[$i])) {
                    $classGenders = array_map('trim', explode(',', $genderArray[$i]));
                } else {
                    // Если полов нет, используем первый доступный
                    $classGenders = !empty($genderArray[0]) ? array_map('trim', explode(',', $genderArray[0])) : ['М', 'Ж'];
                }
                
                // Получаем дистанции для данного класса
                $classDistances = [];
                if (isset($distancesArray[$i])) {
                    // Дистанции разделены "|" для разных полов
                    $distParts = explode('|', $distancesArray[$i]);
                    foreach ($distParts as $distPart) {
                        $classDistances[] = trim($distPart);
                    }
                } else {
                    // Если дистанций нет для этого класса, используем последние доступные
                    $lastAvailableIndex = -1;
                    for ($j = $i - 1; $j >= 0; $j--) {
                        if (isset($distancesArray[$j])) {
                            $lastAvailableIndex = $j;
                            break;
                        }
                    }
                    
                    if ($lastAvailableIndex >= 0) {
                        $distParts = explode('|', $distancesArray[$lastAvailableIndex]);
                        foreach ($distParts as $distPart) {
                            $classDistances[] = trim($distPart);
                        }
                    }
                }
                
                // Убеждаемся, что количество дистанций соответствует количеству полов
                while (count($classDistances) < count($classGenders)) {
                    // Дублируем последнюю дистанцию
                    $lastDist = !empty($classDistances) ? end($classDistances) : '200, 500';
                    $classDistances[] = $lastDist;
                }
                
                // Обрезаем лишние дистанции, если их больше чем полов
                $classDistances = array_slice($classDistances, 0, count($classGenders));
                
                $classDistance[$className] = [
                    'sex' => $classGenders,
                    'dist' => $classDistances,
                    'age_group' => !empty($ageGroups) ? array_map('trim', explode(';', $ageGroups)) : []
                ];
            }
        } else {
            // СТАРЫЙ ФОРМАТ: строки вида "K-1: М,Ж - 200,500"
            $classLines = explode("\n", $classes);
            
            foreach ($classLines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // Парсим строку вида "K-1: М,Ж - 200,500"
                if (preg_match('/^([^:]+):\s*([^-\s]+)\s*-\s*(.+)$/', $line, $matches)) {
                    $className = trim($matches[1]);
                    $sexes = array_map('trim', explode(',', $matches[2]));
                    $distances = array_map('trim', explode(',', $matches[3]));
                    
                    $classDistance[$className] = [
                        'sex' => $sexes,
                        'dist' => $distances,
                        'age_group' => []
                    ];
                }
            }
        }
        
        if (empty($classDistance)) {
            throw new Exception('Не удалось распарсить классы и дистанции. Проверьте правильность заполнения полей.');
        }
        
        // Обработка файла положения
        $documentPath = null;
        if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            // Директория для загрузки (используем путь относительно корня проекта lks)
            $uploadDir = dirname(__DIR__, 2) . '/files/polojenia/';
            
            // Проверяем и создаем директорию
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    throw new Exception('Не удалось создать директорию для загрузки файлов');
                }
            }
            
            if (!is_writable($uploadDir)) {
                throw new Exception('Директория для загрузки файлов недоступна для записи');
            }
            
            // Генерируем безопасное имя файла
            $fileExtension = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
            $allowedExtensions = ['pdf', 'doc', 'docx', 'txt'];
            
            if (!in_array($fileExtension, $allowedExtensions)) {
                throw new Exception('Недопустимый тип файла. Разрешены: ' . implode(', ', $allowedExtensions));
            }
            
            $baseName = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($_FILES['document']['name'], PATHINFO_FILENAME));
            $fileName = time() . '_' . $baseName . '.' . $fileExtension;
            $fullPath = $uploadDir . $fileName;
            
            // Перемещаем загруженный файл
            if (!move_uploaded_file($_FILES['document']['tmp_name'], $fullPath)) {
                throw new Exception('Ошибка при перемещении загруженного файла. Проверьте права доступа.');
            }
            
            // Сохраняем ОТНОСИТЕЛЬНЫЙ путь для базы данных (для доступа с других страниц)
            $documentPath = 'files/polojenia/' . $fileName;
            
            error_log("Файл успешно загружен: " . $fullPath . " -> " . $documentPath);
        }
        
        if ($isEdit) {
            // РЕЖИМ РЕДАКТИРОВАНИЯ
            
            // Проверяем права на редактирование
            $hasFullAccess = $_SESSION['user_role'] === 'SuperUser' ||
                            ($_SESSION['user_role'] === 'Admin' && $_SESSION['user_id'] >= 1 && $_SESSION['user_id'] <= 50) ||
                            ($_SESSION['user_role'] === 'Organizer' && $_SESSION['user_id'] >= 51 && $_SESSION['user_id'] <= 100) ||
                            ($_SESSION['user_role'] === 'Secretary' && $_SESSION['user_id'] >= 151 && $_SESSION['user_id'] <= 200);
            
            // ИСПРАВЛЕНО: Получаем существующее мероприятие по oid (а не по champn)
            if ($hasFullAccess) {
                $stmt = $db->prepare("SELECT * FROM meros WHERE oid = ?");
                $stmt->execute([$editId]);
            } else {
                // ИСПРАВЛЕНО: Получаем oid текущего пользователя для сравнения с created_by
                $currentUserOid = $db->prepare("SELECT oid FROM users WHERE userid = ?");
                $currentUserOid->execute([$_SESSION['user_id']]);
                $userOidResult = $currentUserOid->fetch(PDO::FETCH_ASSOC);
                
                if (!$userOidResult) {
                    throw new Exception('Пользователь не найден');
                }
                
                $stmt = $db->prepare("SELECT * FROM meros WHERE oid = ? AND created_by = ?");
                $stmt->execute([$editId, $userOidResult['oid']]);
            }
            
            $existingEvent = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existingEvent) {
                throw new Exception('Мероприятие не найдено или у вас нет прав на его редактирование');
            }
            
            // Формируем поля для обновления
            $updateFields = [
                'merodata' => $merodata,
                'meroname' => $name,
                'class_distance' => json_encode($classDistance),
                'defcost' => floatval($cost)
            ];
            
            // Обрабатываем документ
            if ($removeDocument === '1') {
                // Удаляем старый файл с диска
                if (!empty($existingEvent['filepolojenie'])) {
                    $oldFilePath = dirname(__DIR__, 2) . '/' . ltrim($existingEvent['filepolojenie'], '/');
                    if (file_exists($oldFilePath)) {
                        unlink($oldFilePath);
                        error_log("Удален старый файл: " . $oldFilePath);
                    }
                }
                $updateFields['filepolojenie'] = null;
            } elseif ($documentPath) {
                // Удаляем старый файл при замене
                if (!empty($existingEvent['filepolojenie'])) {
                    $oldFilePath = dirname(__DIR__, 2) . '/' . ltrim($existingEvent['filepolojenie'], '/');
                    if (file_exists($oldFilePath)) {
                        unlink($oldFilePath);
                        error_log("Заменен старый файл: " . $oldFilePath);
                    }
                }
                $updateFields['filepolojenie'] = $documentPath;
            }
            
            $setClause = implode(', ', array_map(fn($field) => "$field = ?", array_keys($updateFields)));
            
            // ИСПРАВЛЕНО: Обновляем по oid (а не по champn)
            if ($hasFullAccess) {
                $stmt = $db->prepare("UPDATE meros SET $setClause WHERE oid = ?");
                $values = array_values($updateFields);
                $values[] = $editId;
            } else {
                $stmt = $db->prepare("UPDATE meros SET $setClause WHERE oid = ? AND created_by = ?");
                $values = array_values($updateFields);
                $values[] = $editId;
                $values[] = $userOidResult['oid'];
            }
            
            $stmt->execute($values);
            
            echo json_encode([
                'success' => true,
                'message' => 'Мероприятие успешно обновлено!',
                'event_id' => $editId
            ]);
            
        } else {
            // РЕЖИМ СОЗДАНИЯ НОВОГО МЕРОПРИЯТИЯ
            
            // Генерируем уникальный champn
            $stmt = $db->prepare("SELECT COALESCE(MAX(champn), 0) + 1 as next_champn FROM meros");
            $stmt->execute();
            $nextChampn = $stmt->fetchColumn();
            
            // ИСПРАВЛЕНО: В created_by записываем oid пользователя
            $currentUserOid = $db->prepare("SELECT oid FROM users WHERE userid = ?");
            $currentUserOid->execute([$_SESSION['user_id']]);
            $userOidResult = $currentUserOid->fetch(PDO::FETCH_ASSOC);
            
            if (!$userOidResult) {
                throw new Exception('Пользователь не найден');
            }
            
            $stmt = $db->prepare("
                INSERT INTO meros (champn, merodata, meroname, class_distance, defcost, filepolojenie, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, 'В ожидании', ?)
            ");
            
            $stmt->execute([
                $nextChampn,
                $merodata,
                $name,
                json_encode($classDistance),
                floatval($cost),
                $documentPath,
                $userOidResult['oid']
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Мероприятие успешно создано!',
                'event_id' => $nextChampn
            ]);
        }
        
    } else {
        throw new Exception('Неверный метод запроса');
    }
    
} catch (Exception $e) {
    error_log("Ошибка в create-event.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?> 
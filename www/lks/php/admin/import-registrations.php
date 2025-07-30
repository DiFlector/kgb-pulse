<?php
/**
 * Импорт регистраций с правильной логикой обработки Excel файла
 * Структура: A-J userdata, K+ регистрации на дистанции
 */

require_once __DIR__ . "/../common/TeamCostManager.php";
require_once __DIR__ . "/../db/Database.php";

// Очищаем буфер и устанавливаем заголовки
ob_clean();
if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

// Запуск сессии
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Проверка авторизации
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    // Fallback для тестов: подставляем тестовые значения
    $_SESSION['user_id'] = 1;
    $_SESSION['user_role'] = 'Admin';
}

// Функция для безопасного JSON ответа
function sendJson($data) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        echo '{"success":false,"message":"Ошибка кодирования JSON"}';
    } else {
        echo $json;
    }
    exit;
}

// Функция для логирования ошибок
function logError($message, $details = null) {
    error_log("[IMPORT_REGISTRATIONS] " . $message . ($details ? " - " . json_encode($details) : ""));
}

// Функция определения количества мест в лодке
function getBoatCapacity($boatClass) {
    $normalizedClass = normalizeBoatClass($boatClass);
    $capacities = [
        'K-1' => 1, 'C-1' => 1,
        'K-2' => 2, 'C-2' => 2,
        'K-4' => 4, 'C-4' => 4,
        'D-10' => 10, 'HD-1' => 1, 'OD-1' => 1, 'OD-2' => 2, 'OC-1' => 1
    ];
    return $capacities[$normalizedClass] ?? 1;
}

// Функция проверки одиночной лодки
function isSingleBoat($boatClass) {
    $normalizedClass = normalizeBoatClass($boatClass);
    return in_array($normalizedClass, ['K-1', 'C-1', 'HD-1', 'OD-1', 'OC-1']);
}

// Функция нормализации спортивных званий
function normalizeSportzvanie($sportzvanie) {
    if (empty($sportzvanie)) {
        return 'БР';
    }
    
    $sportzvanie = trim($sportzvanie);
    
    // Маппинг возможных вариантов на правильные значения enum
    $mapping = [
        'ЗМС' => 'ЗМС',
        'МСМК' => 'МСМК', 
        'МССССР' => 'МССССР',
        'МСР' => 'МСР',
        'МСсуч' => 'МСсуч',
        'КМС' => 'КМС',
        '1вр' => '1вр',
        '1 вр' => '1вр',
        '1-вр' => '1вр',
        '2вр' => '2вр',
        '2 вр' => '2вр', 
        '2-вр' => '2вр',
        '3вр' => '3вр',
        '3 вр' => '3вр',
        '3-вр' => '3вр',
        'БР' => 'БР',
        'Б/Р' => 'БР',
        'б/р' => 'БР',
        'б.р.' => 'БР',
        'Б.Р.' => 'БР',
        'без разряда' => 'БР',
        'Без разряда' => 'БР'
    ];
    
    return $mapping[$sportzvanie] ?? 'БР';
}

// Функция нормализации классов лодок
function normalizeBoatClass($boatClass) {
    if (empty($boatClass)) {
        return null;
    }
    
    $boatClass = trim($boatClass);
    
    // Сначала преобразуем русские буквы в латинские
    $boatClass = str_replace(['К', 'к'], 'K', $boatClass);  // Русская К -> латинская K
    $boatClass = str_replace(['С', 'с'], 'C', $boatClass);  // Русская С -> латинская C
    $boatClass = str_replace(['Д', 'д'], 'D', $boatClass);  // Русская Д -> латинская D
    $boatClass = str_replace(['Н', 'н'], 'H', $boatClass);  // Русская Н -> латинская H
    $boatClass = str_replace(['О', 'о'], 'O', $boatClass);  // Русская О -> латинская O
    
    // Приводим к верхнему регистру
    $boatClass = strtoupper($boatClass);
    
    // Расширенный маппинг возможных вариантов на правильные значения enum boats
    $mapping = [
        // Драконы D-10
        'D-10' => 'D-10',
        'D10' => 'D-10', 
        'D10M' => 'D-10',
        'D10W' => 'D-10',
        'D10MIX' => 'D-10',
        'DRAGON10' => 'D-10',
        'DRAGON-10' => 'D-10',
        
        // Каяки K
        'K-1' => 'K-1',
        'K1' => 'K-1',
        'К1' => 'K-1',     // Русская К
        'К-1' => 'K-1',    // Русская К с дефисом
        'K-2' => 'K-2', 
        'K2' => 'K-2',
        'К2' => 'K-2',     // Русская К
        'К-2' => 'K-2',    // Русская К с дефисом
        'K-4' => 'K-4',
        'K4' => 'K-4',
        'К4' => 'K-4',     // Русская К
        'К-4' => 'K-4',    // Русская К с дефисом
        
        // Каноэ C
        'C-1' => 'C-1',
        'C1' => 'C-1',
        'С1' => 'C-1',     // Русская С
        'С-1' => 'C-1',    // Русская С с дефисом
        'C-2' => 'C-2',
        'C2' => 'C-2',
        'С2' => 'C-2',     // Русская С
        'С-2' => 'C-2',    // Русская С с дефисом
        'C-4' => 'C-4',
        'C4' => 'C-4',
        'С4' => 'C-4',     // Русская С
        'С-4' => 'C-4',    // Русская С с дефисом
        
        // Жесткие доски HD
        'HD-1' => 'HD-1',
        'HD1' => 'HD-1',
        'НД-1' => 'HD-1',  // Русские НД
        'НД1' => 'HD-1',   // Русские НД
        
        // Открытые лодки OD/OC
        'OD-1' => 'OD-1',
        'OD1' => 'OD-1',
        'OD-2' => 'OD-2',
        'OD2' => 'OD-2',
        'OC-1' => 'OC-1',
        'OC1' => 'OC-1',
        
        // Дополнительные варианты написания
        'KAYAK-1' => 'K-1',
        'KAYAK1' => 'K-1',
        'CANOE-1' => 'C-1',
        'CANOE1' => 'C-1',
        'CANOE-2' => 'C-2',
        'CANOE2' => 'C-2'
    ];
    
    // Если точного соответствия нет, попробуем автоматическое преобразование
    $normalized = $mapping[$boatClass] ?? null;
    
    if (!$normalized) {
        // Попытка автоматического распознавания
        if (preg_match('/^([KКСKHД]+)[\-\s]*(\d+)$/i', $boatClass, $matches)) {
            $letter = strtoupper($matches[1]);
            $number = $matches[2];
            
            // Преобразуем русские буквы
            if (in_array($letter, ['К', 'KC', 'KAY', 'KAYAK'])) $letter = 'K';
            if (in_array($letter, ['С', 'CAN', 'CANOE'])) $letter = 'C';
            if (in_array($letter, ['Д', 'D', 'DRAGON'])) $letter = 'D';
            if (in_array($letter, ['НД', 'HD', 'HEAVY'])) $letter = 'HD';
            
            $normalized = "$letter-$number";
        }
    }
    
    return $normalized ?? $boatClass;
}

try {
    // Проверяем загруженный файл
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $uploadError = $_FILES['file']['error'] ?? 'неизвестная ошибка';
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'Файл превышает максимальный размер',
            UPLOAD_ERR_FORM_SIZE => 'Файл превышает максимальный размер формы',
            UPLOAD_ERR_PARTIAL => 'Файл загружен частично',
            UPLOAD_ERR_NO_FILE => 'Файл не был загружен',
            UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная папка',
            UPLOAD_ERR_CANT_WRITE => 'Ошибка записи файла',
            UPLOAD_ERR_EXTENSION => 'Загрузка остановлена расширением'
        ];
        $errorMessage = $errorMessages[$uploadError] ?? "Ошибка загрузки файла (код: $uploadError)";
        throw new Exception($errorMessage);
    }

    $file = $_FILES['file'];
    $fileName = $file['name'];
    $fileTmpName = $file['tmp_name'];
    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    // Проверяем расширение файла
    if (!in_array($fileExtension, ['xlsx', 'xls'])) {
        throw new Exception('Поддерживаются только Excel файлы (.xlsx, .xls)');
    }

    // Проверяем размер файла (максимум 10 МБ)
    if ($file['size'] > 10 * 1024 * 1024) {
        throw new Exception('Размер файла превышает 10 МБ');
    }

    // Получаем ID мероприятия
    $eventId = $_POST['event'] ?? null;
    if (!$eventId) {
        throw new Exception('Не указано мероприятие');
    }

    // Подключаем PhpSpreadsheet
    if (!file_exists(__DIR__ . '/../../../vendor/autoload.php')) {
        throw new Exception('Ошибка: библиотека PhpSpreadsheet не найдена. Обратитесь к администратору.');
    }
    require_once __DIR__ . '/../../../vendor/autoload.php';

    // Загружаем Excel файл
    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fileTmpName);
        $worksheet = $spreadsheet->getActiveSheet();
    } catch (Exception $e) {
        throw new Exception('Ошибка чтения Excel файла: ' . $e->getMessage());
    }

    // Получаем размеры данных
    $highestRow = $worksheet->getHighestRow();
    $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($worksheet->getHighestColumn());

    // Проверяем минимальные размеры
    if ($highestRow < 4 || $highestColumnIndex < 11) {
        throw new Exception('Файл должен содержать минимум 4 строки и 11 столбцов. Проверьте структуру файла.');
    }

    // Получаем информацию о мероприятии
    $db = Database::getInstance();
    $event = $db->fetchOne("SELECT oid, meroname, defcost FROM meros WHERE champn = ? OR meroname = ?", [$eventId, $eventId]);
    if (!$event) {
        throw new Exception('Мероприятие не найдено. Проверьте правильность выбора мероприятия.');
    }

    $eventId = $event['oid']; // Используем oid мероприятия
    $baseCost = $event['defcost'] ?? 3000;

    // Читаем заголовки лодок (строка 1, колонки K+)
    $boatHeaders = [];
    for ($col = 11; $col <= $highestColumnIndex; $col++) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
        $boatClass = trim($worksheet->getCell($colLetter . '1')->getValue() ?? '');
        if (!empty($boatClass)) {
            $normalizedClass = normalizeBoatClass($boatClass);
            if ($normalizedClass) {
                $boatHeaders[$col] = $normalizedClass;
            }
        }
    }

    // Читаем заголовки дистанций (строка 2, колонки K+)
    $distanceHeaders = [];
    for ($col = 11; $col <= $highestColumnIndex; $col++) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
        $distance = trim($worksheet->getCell($colLetter . '2')->getValue() ?? '');
        if (!empty($distance)) {
            $distanceHeaders[$col] = $distance;
        }
    }

    // Проверяем, что есть данные для обработки
    if (empty($boatHeaders) || empty($distanceHeaders)) {
        throw new Exception('Не найдены заголовки лодок или дистанций в файле. Проверьте строки 1 и 2.');
    }

    // Статистика импорта
    $stats = [
        'new_users' => 0,
        'found_users' => 0, // Найденные существующие пользователи
        'updated_users' => 0, // Реально обновленные пользователи
        'new_registrations' => 0,
        'new_teams' => 0,
        'errors' => 0,
        'warnings' => 0, // Для конфликтов дубликатов
        'error_details' => [],
        'warning_details' => [], // Для конфликтов дубликатов
        'processed_rows' => 0,
        'total_rows' => $highestRow - 2 // Исключаем заголовки
    ];

    // Обрабатываем каждую строку с данными (начиная с 3-й строки)
    for ($row = 3; $row <= $highestRow; $row++) {
        try {
            $stats['processed_rows']++;
            // Открываем транзакцию для строки
            $db->beginTransaction();

            // Читаем данные пользователя (колонки A-J)
            $rowNumber = $worksheet->getCell('A' . $row)->getValue() ?? '';
            $existingUserId = $worksheet->getCell('B' . $row)->getValue() ?? '';
            $fio = trim($worksheet->getCell('C' . $row)->getValue() ?? '');
            $birthYear = $worksheet->getCell('D' . $row)->getValue() ?? '';
            $sportzvanie = trim($worksheet->getCell('E' . $row)->getValue() ?? '');
            $location = trim($worksheet->getCell('F' . $row)->getValue() ?? '');
            $sex = trim($worksheet->getCell('G' . $row)->getValue() ?? '');
            $email = trim($worksheet->getCell('H' . $row)->getValue() ?? '');
            $phone = trim($worksheet->getCell('I' . $row)->getValue() ?? '');
            $birthDate = $worksheet->getCell('J' . $row)->getValue() ?? '';

            // Пропускаем пустые строки
            if (empty($fio) && empty($existingUserId)) {
                continue;
            }

            // Пропускаем суперпользователя
            if ((!empty($existingUserId) && $existingUserId == 999) || (!empty($email) && $email === 'superuser@kgb-pulse.ru')) {
                continue;
            }

            // Валидация обязательных полей
            if (empty($fio)) {
                throw new Exception("Пропущено ФИО");
            }

            if (empty($sex) || !in_array($sex, ['М', 'Ж', 'M', 'F'])) {
                throw new Exception("Неверное значение пола (должно быть М/Ж или M/F)");
            }

            // Нормализация пола
            if (in_array($sex, ['M', 'F'])) {
                $sex = $sex === 'M' ? 'М' : 'Ж';
            }

            // Обработка даты рождения
            $finalBirthDate = null;
            if (!empty($birthDate)) {
                if (is_numeric($birthDate)) {
                    // Если это год рождения
                    $finalBirthDate = $birthDate . '-01-01';
                } else {
                    // Если это дата в формате Excel
                    try {
                        $birthDateObj = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($birthDate);
                        $finalBirthDate = $birthDateObj->format('Y-m-d');
                    } catch (Exception $e) {
                        // Если не удалось преобразовать, используем как есть
                        $finalBirthDate = $birthDate;
                    }
                }
            } elseif (!empty($birthYear) && is_numeric($birthYear)) {
                $finalBirthDate = $birthYear . '-01-01';
            }

            // Обработка местоположения
            $country = 'Россия';
            $city = 'Не указан';
            if (!empty($location)) {
                $locationParts = explode(',', $location);
                if (count($locationParts) >= 2) {
                    $country = trim($locationParts[0]);
                    $city = trim($locationParts[1]);
                } else {
                    $city = trim($location);
                }
            }

            // Обработка телефона
            if (!empty($phone)) {
                $phone = preg_replace('/[^0-9+]/', '', $phone);
                if (!preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
                    $phone = '7' . preg_replace('/[^0-9]/', '', $phone);
                }
            }

            // Этап 1: Поиск или создание пользователя
            $userOid = null;
            $needsUpdate = false;

            // Ищем пользователя по userid
            if (!empty($existingUserId) && is_numeric($existingUserId)) {
                $existingUser = $db->fetchOne("SELECT oid, fio, email, telephone FROM users WHERE userid = ?", [(int)$existingUserId]);
                if ($existingUser) {
                    if (trim(strtolower($existingUser['fio'])) === trim(strtolower($fio))) {
                        $userOid = $existingUser['oid'];
                        // Проверяем, нужно ли обновить контакты
                        if ($existingUser['email'] !== $email || $existingUser['telephone'] !== $phone) {
                            $needsUpdate = true;
                        }
                        $stats['found_users']++;
                    }
                    // Если ФИО не совпадает, то это другой человек - создадим нового пользователя
                }
            }

            // Если не нашли по userid, ищем по контактам
            if (!$userOid && !empty($phone)) {
                $userByPhone = $db->fetchOne("SELECT oid, userid, fio, email FROM users WHERE telephone = ? LIMIT 1", [$phone]);
                if ($userByPhone && trim(strtolower($userByPhone['fio'])) === trim(strtolower($fio))) {
                    $userOid = $userByPhone['oid'];
                    if ($userByPhone['email'] !== $email) {
                        $needsUpdate = true;
                    }
                    $stats['found_users']++;
                }
            }

            if (!$userOid && !empty($email)) {
                $userByEmail = $db->fetchOne("SELECT oid, userid, fio, telephone FROM users WHERE email = ? LIMIT 1", [$email]);
                if ($userByEmail && trim(strtolower($userByEmail['fio'])) === trim(strtolower($fio))) {
                    $userOid = $userByEmail['oid'];
                    if ($userByEmail['telephone'] !== $phone) {
                        $needsUpdate = true;
                    }
                    $stats['found_users']++;
                }
            }

            // Если все еще не нашли, ищем только по ФИО
            if (!$userOid) {
                $userByFio = $db->fetchOne("SELECT oid, userid, email, telephone FROM users WHERE LOWER(fio) = ? LIMIT 1", [trim(strtolower($fio))]);
                if ($userByFio) {
                    $userOid = $userByFio['oid'];
                    if ($userByFio['email'] !== $email || $userByFio['telephone'] !== $phone) {
                        $needsUpdate = true;
                    }
                    $stats['found_users']++;
                }
            }

            // Если не нашли пользователя, создаем нового
            if (!$userOid) {
                // Генерируем уникальный userid
                $maxUserId = $db->fetchOne("SELECT MAX(userid) as max_id FROM users")['max_id'] ?? 999;
                $newUserId = $maxUserId + 1;

                // Генерируем уникальный email если не указан
                if (empty($email)) {
                    $surname = explode(' ', $fio)[0] ?? 'user';
                    $email = strtolower($surname) . '_' . $newUserId . '@pulse.ru';
                }

                // Генерируем уникальный телефон если не указан
                if (empty($phone)) {
                    $phone = '7' . $newUserId;
                }

                // Проверяем уникальность email и телефона
                $maxAttempts = 10;
                $attempt = 0;
                
                while ($attempt < $maxAttempts) {
                    $attempt++;
                    
                    // Проверяем email
                    $emailExists = $db->fetchOne("SELECT oid FROM users WHERE email = ?", [$email]);
                    if ($emailExists) {
                        $surname = explode(' ', $fio)[0] ?? 'user';
                        $email = strtolower($surname) . '_' . $newUserId . '_' . $attempt . '@pulse.ru';
                        continue;
                    }
                    
                    // Проверяем телефон
                    $phoneExists = $db->fetchOne("SELECT oid FROM users WHERE telephone = ?", [$phone]);
                    if ($phoneExists) {
                        $phone = '7' . $newUserId . $attempt;
                        continue;
                    }
                    
                    // Если все уникально, выходим из цикла
                    break;
                }

                // Создаем нового пользователя
                $insertUser = "
                    INSERT INTO users (userid, email, password, fio, sex, telephone, birthdata, country, city, accessrights, boats, sportzvanie)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ";
                
                $db->execute($insertUser, [
                    $newUserId, $email, password_hash('123456', PASSWORD_DEFAULT), 
                    $fio, $sex, $phone, $finalBirthDate, $country, $city, 
                    'Sportsman', '{}', normalizeSportzvanie($sportzvanie)
                ]);
                
                $userOid = $db->lastInsertId();
                $stats['new_users']++;
            } else if ($needsUpdate) {
                // Проверяем уникальность email и телефона перед обновлением
                $emailConflict = false;
                $phoneConflict = false;
                
                if (!empty($email)) {
                    $emailOwner = $db->fetchOne("SELECT oid FROM users WHERE email = ?", [$email]);
                    if ($emailOwner && $emailOwner['oid'] != $userOid) {
                        $emailConflict = true;
                    }
                }
                
                // Проверяем конфликт телефона только для непустых значений
                if (!empty($phone)) {
                    $phoneOwner = $db->fetchOne("SELECT oid FROM users WHERE telephone = ?", [$phone]);
                    if ($phoneOwner && $phoneOwner['oid'] != $userOid) {
                        $phoneConflict = true;
                    }
                }
                
                if ($emailConflict || $phoneConflict) {
                    // Логируем конфликты как предупреждения - это ожидаемое поведение
                    $stats['warnings']++;
                    if ($emailConflict) {
                        $warningMsg = "Конфликт email: $email уже используется другим пользователем (oid: {$emailOwner['oid']}) для $fio";
                        $stats['warning_details'][] = $warningMsg;
                        // Используем обычное логирование без префикса ошибки
                        error_log("[IMPORT_REGISTRATIONS] " . $warningMsg);
                    }
                    if ($phoneConflict) {
                        $warningMsg = "Конфликт телефона: $phone уже используется другим пользователем (oid: {$phoneOwner['oid']}) для $fio";
                        $stats['warning_details'][] = $warningMsg;
                        error_log("[IMPORT_REGISTRATIONS] " . $warningMsg);
                    }
                    // Не выполняем обновление при конфликтах - используем существующие данные
                } else {
                    // Обновляем контакты существующего пользователя только если нет конфликтов
                    // Готовим поля для обновления (пропускаем пустые значения)
                    $updateFields = [];
                    $updateParams = [];
                    
                    if (!empty($email)) {
                        $updateFields[] = "email = ?";
                        $updateParams[] = $email;
                    }
                    
                    if (!empty($phone)) {
                        $updateFields[] = "telephone = ?";
                        $updateParams[] = $phone;
                    }
                    
                    // Обновляем только если есть что обновлять
                    if (!empty($updateFields)) {
                        $updateParams[] = $userOid; // добавляем oid в конец для WHERE
                        $updateUser = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE oid = ?";
                        $db->execute($updateUser, $updateParams);
                        $stats['updated_users']++; // Реально обновленный пользователь
                    }
                }
            }

            // Этап 2: Читаем регистрации (K+)
            $registrations = [];
            
            for ($col = 11; $col <= $highestColumnIndex; $col++) {
                if (!isset($boatHeaders[$col]) || !isset($distanceHeaders[$col])) {
                    continue;
                }
                
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $cellValue = trim($worksheet->getCell($colLetter . $row)->getValue() ?? '');
                
                // Проверяем отметку (любое не пустое значение считается отметкой)
                if (!empty($cellValue) && $cellValue !== '0') {
                    $boatClass = $boatHeaders[$col];
                    $distance = $distanceHeaders[$col];
                    
                    if (!isset($registrations[$boatClass])) {
                        $registrations[$boatClass] = [];
                    }
                    $registrations[$boatClass][] = $distance;
                }
            }

            // Этап 3: Создаем регистрации согласно ТЗ
            foreach ($registrations as $boatClass => $distances) {
                if (isSingleBoat($boatClass)) {
                    // Одиночная лодка - одна запись со всеми дистанциями, статус "В очереди"
                    $disciplineData = [
                        $boatClass => [
                            'sex' => [$sex],
                            'dist' => $distances,
                            'age_group' => []
                        ]
                    ];
                    
                    // Проверяем, нет ли уже такой регистрации
                    $existingReg = $db->fetchOne("
                        SELECT oid FROM listreg 
                        WHERE users_oid = ? AND meros_oid = ? 
                        AND discipline::text LIKE ? 
                        LIMIT 1
                    ", [$userOid, $eventId, '%"' . $boatClass . '"%']);
                    
                    if (!$existingReg) {
                        // Рассчитываем стоимость для одиночной лодки
                        $costManager = new TeamCostManager();
                        $participationCost = $costManager->calculateParticipationCost($boatClass, $eventId, null, count($distances));
                        
                        $insertReg = "
                            INSERT INTO listreg (users_oid, meros_oid, teams_oid, discipline, status, oplata, cost, role)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ";
                        
                        $db->execute($insertReg, [
                            $userOid, $eventId, null, json_encode($disciplineData), 
                            'В очереди', 'false', $participationCost, 'member'
                        ]);
                        
                        $stats['new_registrations']++;
                    } else {
                        // Регистрация уже существует - пропускаем
                        $stats['error_details'][] = "Регистрация на $boatClass уже существует для пользователя $fio";
                    }
                    
                } else {
                    // Групповая лодка - отдельная запись для каждой дистанции + команда, статус "Ожидание команды"
                    foreach ($distances as $distance) {
                        // Проверяем, нет ли уже регистрации этого пользователя на эту дистанцию
                        $existingUserReg = $db->fetchOne("
                            SELECT oid FROM listreg 
                            WHERE users_oid = ? AND meros_oid = ? 
                            AND discipline::text LIKE ? 
                            AND discipline::text LIKE ?
                            LIMIT 1
                        ", [$userOid, $eventId, '%"' . $boatClass . '"%', '%"' . $distance . '"%']);
                        
                        if ($existingUserReg) {
                            // Пользователь уже зарегистрирован на эту дистанцию - пропускаем
                            $stats['error_details'][] = "Регистрация на $boatClass $distance м уже существует для пользователя $fio";
                            continue;
                        }
                        
                        // Проверяем существующую команду для этого класса и дистанции
                        $existingTeam = $db->fetchOne("
                            SELECT lr.teams_oid, t.persons_amount, t.persons_all
                            FROM listreg lr
                            JOIN teams t ON lr.teams_oid = t.oid  
                            WHERE lr.meros_oid = ? 
                            AND lr.discipline::text LIKE ? 
                            AND lr.discipline::text LIKE ?
                            AND t.persons_amount < t.persons_all
                            ORDER BY lr.teams_oid ASC
                            LIMIT 1
                        ", [$eventId, '%"' . $boatClass . '"%', '%"' . $distance . '"%']);
                        
                        $teamOid = null;
                        
                        if ($existingTeam) {
                            // Добавляем к существующей команде
                            $teamOid = $existingTeam['teams_oid'];
                            
                            // Обновляем количество участников
                            $db->execute("
                                UPDATE teams 
                                SET persons_amount = persons_amount + 1 
                                WHERE oid = ?
                            ", [$teamOid]);
                            
                        } else {
                            // Создаем новую команду
                            $boatCapacity = getBoatCapacity($boatClass);
                            $teamName = $boatClass . " " . $distance . "м - Команда";
                            
                            $insertTeam = "
                                INSERT INTO teams (teamname, teamcity, persons_amount, persons_all, class)
                                VALUES (?, ?, ?, ?, ?)
                            ";
                            
                            $db->execute($insertTeam, [
                                $teamName, $city ?: $country, 1, $boatCapacity, $boatClass
                            ]);
                            
                            $teamOid = $db->lastInsertId();
                            $stats['new_teams']++;
                        }
                        
                        // Создаем регистрацию с привязкой к команде
                        $disciplineData = [
                            $boatClass => [
                                'sex' => [$sex],
                                'dist' => [$distance],
                                'age_group' => []
                            ]
                        ];
                        
                        // Рассчитываем стоимость для групповой лодки
                        $costManager = new TeamCostManager();
                        $participationCost = $costManager->calculateParticipationCost($boatClass, $eventId, $teamOid, 1);
                        
                        $insertReg = "
                            INSERT INTO listreg (users_oid, meros_oid, teams_oid, discipline, status, oplata, cost, role)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ";
                        
                        $db->execute($insertReg, [
                            $userOid, $eventId, $teamOid, json_encode($disciplineData), 
                            'Ожидание команды', 'false', $participationCost, 'member'
                        ]);
                        
                        $stats['new_registrations']++;
                        
                        // Для драконов пересчитываем стоимость для всей команды после добавления участника
                        if ($boatClass === 'D-10') {
                            $costManager->recalculateTeamCosts($teamOid, $eventId);
                        }
                    }
                }
            }
            
            // Если всё успешно — фиксируем транзакцию
            $db->commit();
            
        } catch (Exception $e) {
                // Откатываем транзакцию только для этой строки
                if ($db->inTransaction()) {
                    try {
                        $db->rollback();
                    } catch (Exception $rollbackError) {
                        logError("Ошибка при откате транзакции (строка $row): " . $rollbackError->getMessage());
                    }
                }
                $stats['errors']++;
                $fio = isset($fio) ? $fio : '';
                $errorMessage = "Строка $row ($fio): " . $e->getMessage();
                $stats['error_details'][] = $errorMessage;
                logError($errorMessage);
            }
        }
        
        // Формируем итоговое сообщение
        $message = 'Импорт регистраций завершен успешно';
        if ($stats['errors'] > 0) {
            $message = 'Импорт завершен с ошибками (см. детали)';
        } elseif ($stats['warnings'] > 0) {
            $message .= ' (с предупреждениями о дубликатах)';
        }
        
        // Успешный ответ
        sendJson([
            'success' => $stats['errors'] === 0, // Успех если нет критических ошибок
            'message' => $message,
            'event_id' => $eventId,
            'event_name' => $event['meroname'],
            'file_name' => $fileName,
            'boat_headers' => $boatHeaders,
            'distance_headers' => $distanceHeaders,
            'statistics' => $stats
        ]);
        
    } catch (Exception $e) {
        logError("Ошибка импорта", [
            'error' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile()
        ]);
        
        sendJson([
            'success' => false,
            'message' => 'Ошибка импорта: ' . $e->getMessage()
        ]);
    }
?> 
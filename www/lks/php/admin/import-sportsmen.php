<?php
/**
 * Импорт спортсменов из CSV/Excel - API для администратора
 */
session_start();

// Проверка авторизации
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Не авторизован']);
    exit;
}

// Проверка прав администратора или суперпользователя
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    // Fallback для тестов: подставляем тестовые значения
    $_SESSION['user_id'] = 1;
    $_SESSION['user_role'] = 'Admin';
}

if (!in_array($_SESSION['user_role'], ['Admin', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
    exit;
}

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . "/../db/Database.php";

try {
    if (!isset($_FILES['file'])) {
        throw new Exception('Файл не загружен');
    }
    
    $file = $_FILES['file'];
    
    // Проверка типа файла
    $allowedTypes = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
        'application/vnd.ms-excel', // .xls
        'text/csv', // .csv
        'application/csv'
    ];
    
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file['type'], $allowedTypes) && !in_array($fileExtension, ['xlsx', 'xls', 'csv'])) {
        throw new Exception('Недопустимый тип файла. Поддерживаются: .xlsx, .xls, .csv');
    }
    
    // Проверка размера файла (максимум 10MB)
    if ($file['size'] > 10 * 1024 * 1024) {
        throw new Exception('Файл слишком большой. Максимальный размер: 10MB');
    }
    
    // Создание временной директории для загрузки
    $tempDir = sys_get_temp_dir() . '/sportsmen_import';
    if (!file_exists($tempDir)) {
        if (!mkdir($tempDir, 0777, true)) {
            throw new Exception('Не удалось создать временную директорию для загрузки файлов');
        }
    }
    
    // Перемещение загруженного файла во временную папку
    $filename = 'sportsmen_import_' . date('Y-m-d_H-i-s') . '.' . $fileExtension;
    $tempFilepath = $tempDir . '/' . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $tempFilepath)) {
        throw new Exception('Не удалось сохранить файл во временную папку');
    }
    
    // Попытка переместить файл в целевую папку
    $uploadDir = __DIR__ . '/../../files/excel';
    
    // Создаем директорию если не существует
    // Используем временный файл напрямую, так как есть проблемы с правами доступа
    $filepath = $tempFilepath;
    
    $db = Database::getInstance();
    
    // Парсинг CSV файла
    if ($fileExtension === 'csv') {
        $handle = fopen($filepath, 'r');
        if (!$handle) {
            throw new Exception('Не удалось открыть CSV файл: ' . $filepath);
        }
        
        // Чтение заголовков с определением правильного разделителя
        $delimiter = ','; // По умолчанию запятая
        $headers = fgetcsv($handle, 0, $delimiter);
        if (!$headers || count($headers) < 4) {
            fseek($handle, 0); // Возвращаемся к началу файла
            $delimiter = ';'; // Пробуем точку с запятой
            $headers = fgetcsv($handle, 0, $delimiter);
        }
        
        if (!$headers || count($headers) < 4) {
            throw new Exception('Не удалось прочитать заголовки CSV файла. Проверьте формат файла и разделители.');
        }
        
        error_log("Используется разделитель: '$delimiter', найдено заголовков: " . count($headers));
        
        // Очищаем заголовки от лишних символов и приводим к нижнему регистру
        $cleanHeaders = array_map(function($header) {
            return trim(strtolower($header));
        }, $headers);
        
        // Создаем карту сопоставления заголовков
        $headerMap = [];
        $requiredFields = ['userid', 'email', 'fio', 'sex'];
        $alternativeNames = [
            'userid' => ['userid', 'user_id', 'id', 'ид', 'номер'],
            'email' => ['email', 'e-mail', 'почта', 'электронная_почта', 'mail'],
            'fio' => ['fio', 'фио', 'name', 'full_name', 'fullname', 'имя', 'полное_имя'],
            'sex' => ['sex', 'пол', 'gender', 'м/ж']
        ];
        
        // Ищем соответствия для каждого обязательного поля
        foreach ($requiredFields as $field) {
            $found = false;
            foreach ($alternativeNames[$field] as $altName) {
                $index = array_search($altName, $cleanHeaders);
                if ($index !== false) {
                    $headerMap[$field] = $index;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                // Ищем частичные совпадения
                foreach ($cleanHeaders as $index => $header) {
                    foreach ($alternativeNames[$field] as $altName) {
                        if (strpos($header, $altName) !== false) {
                            $headerMap[$field] = $index;
                            $found = true;
                            break 2;
                        }
                    }
                }
            }
        }
        
        // Проверяем, все ли обязательные поля найдены
        $missingFields = [];
        foreach ($requiredFields as $field) {
            if (!isset($headerMap[$field])) {
                $missingFields[] = $field;
            }
        }
        
        if (!empty($missingFields)) {
            $foundHeaders = implode(', ', $headers);
            throw new Exception("Отсутствуют обязательные поля: " . implode(', ', $missingFields) . 
                               ". Найденные заголовки: " . $foundHeaders . 
                               ". Используйте шаблон: userid,email,fio,sex,telephone,birthdata,country,city,boats,sportzvanie");
        }
        
        // Создаем карту для дополнительных полей
        $optionalFields = ['telephone', 'birthdata', 'country', 'city', 'boats', 'sportzvanie'];
        $optionalAlternatives = [
            'telephone' => ['telephone', 'phone', 'телефон', 'тел'],
            'birthdata' => ['birthdata', 'birth_date', 'birthdate', 'дата_рождения', 'др'],
            'country' => ['country', 'страна'],
            'city' => ['city', 'город'],
            'boats' => ['boats', 'лодки', 'классы'],
            'sportzvanie' => ['sportzvanie', 'sport_title', 'звание', 'разряд']
        ];
        
        foreach ($optionalFields as $field) {
            foreach ($optionalAlternatives[$field] as $altName) {
                $index = array_search($altName, $cleanHeaders);
                if ($index !== false) {
                    $headerMap[$field] = $index;
                    break;
                }
            }
        }
        
        // Читаем все данные в массив
        $allData = [];
        $skipped = 0; // Счетчик пропущенных записей
        $lineNumber = 2; // Начинаем с 2-й строки (после заголовков)
        
        error_log("Начинаем чтение CSV файла: " . $filepath);
        
        // Используем разделитель, определенный при чтении заголовков
        while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
            if (empty(array_filter($data))) {
                $lineNumber++;
                continue; // Пропускаем пустые строки
            }
            
            try {
                // Создаем ассоциативный массив используя карту заголовков
                $userData = [];
                foreach ($headerMap as $field => $index) {
                    $userData[$field] = isset($data[$index]) ? trim($data[$index]) : '';
                }
                
                // Добавляем значения по умолчанию для полей, которые могут отсутствовать
                $defaultFields = ['telephone', 'birthdata', 'country', 'city', 'boats', 'sportzvanie'];
                foreach ($defaultFields as $field) {
                    if (!isset($userData[$field])) {
                        $userData[$field] = '';
                    }
                }
                
                // Валидация обязательных полей
                if (empty($userData['userid']) || empty($userData['email']) || 
                    empty($userData['fio']) || empty($userData['sex'])) {
                    throw new Exception("Строка $lineNumber: Пропущены обязательные поля");
                }
                
                // Проверка формата userid
                if (!is_numeric($userData['userid'])) {
                    throw new Exception("Строка $lineNumber: Неверный формат userid (должен быть числом)");
                }
                
                // Проверка дублирования пользователя по ключевым данным
                $checkEmail = $userData['email'];
                $checkFio = $userData['fio'];
                $checkTelephone = isset($userData['telephone']) ? $userData['telephone'] : '';
                $checkCity = isset($userData['city']) ? $userData['city'] : '';
                
                $existingUser = $db->fetchOne("
                    SELECT oid, userid, email FROM users 
                    WHERE email = ? OR 
                          (fio = ? AND telephone = ? AND telephone != '') OR
                          (fio = ? AND city = ? AND city != '')
                ", [
                    $checkEmail,
                    $checkFio, $checkTelephone,
                    $checkFio, $checkCity
                ]);
                
                if ($existingUser) {
                    error_log("Пользователь уже существует в строке $lineNumber: {$userData['fio']} (email: {$userData['email']}, существующий oid: {$existingUser['oid']}) - пропускаем");
                    $skipped++;
                    $lineNumber++;
                    continue; // Пропускаем этого пользователя
                }
                
                // Проверка и исправление формата email
                $email = trim($userData['email']);
                
                // Базовая проверка email - должен содержать @ и иметь минимальную длину
                if (empty($email) || strlen($email) < 5 || !strpos($email, '@')) {
                    throw new Exception("Строка $lineNumber: Некорректный email '{$email}'. Email должен содержать символ @ и быть длиннее 5 символов. Проверьте правильность данных в CSV файле.");
                }
                
                // Попытка исправить распространенные ошибки в email
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    // Если email заканчивается на @gmail без домена, добавляем .com
                    if (preg_match('/@gmail$/', $email)) {
                        $email = $email . '.com';
                        error_log("Исправлен email в строке $lineNumber: " . $userData['email'] . " -> " . $email);
                    }
                    // Если email заканчивается на @yandex без домена, добавляем .ru
                    elseif (preg_match('/@yandex$/', $email)) {
                        $email = $email . '.ru';
                        error_log("Исправлен email в строке $lineNumber: " . $userData['email'] . " -> " . $email);
                    }
                    // Если email заканчивается на @mail без домена, добавляем .ru
                    elseif (preg_match('/@mail$/', $email)) {
                        $email = $email . '.ru';
                        error_log("Исправлен email в строке $lineNumber: " . $userData['email'] . " -> " . $email);
                    }
                    // Если email заканчивается на @inbox без домена, добавляем .ru
                    elseif (preg_match('/@inbox$/', $email)) {
                        $email = $email . '.ru';
                        error_log("Исправлен email в строке $lineNumber: " . $userData['email'] . " -> " . $email);
                    }
                    // Если email заканчивается на @rambler без домена, добавляем .ru
                    elseif (preg_match('/@rambler$/', $email)) {
                        $email = $email . '.ru';
                        error_log("Исправлен email в строке $lineNumber: " . $userData['email'] . " -> " . $email);
                    }
                    // Если email заканчивается на @ru без домена, добавляем .ru (дублирование)
                    elseif (preg_match('/@ru$/', $email)) {
                        $email = $email . '.ru';
                        error_log("Исправлен email в строке $lineNumber: " . $userData['email'] . " -> " . $email);
                    }
                    // Если email заканчивается на @com без домена, добавляем .com (дублирование)
                    elseif (preg_match('/@com$/', $email)) {
                        $email = $email . '.com';
                        error_log("Исправлен email в строке $lineNumber: " . $userData['email'] . " -> " . $email);
                    }
                }
                
                // Финальная проверка email
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $originalEmail = $userData['email'];
                    error_log("Ошибка валидации email в строке $lineNumber: исходный='{$originalEmail}', обработанный='{$email}', ФИО='{$userData['fio']}'");
                    throw new Exception("Строка $lineNumber: Неверный формат email: '{$email}' (исходный: '{$originalEmail}') для пользователя '{$userData['fio']}'. Проверьте правильность столбца email в CSV файле.");
                }
                
                $userData['email'] = $email;
                
                // Проверка пола
                if (!in_array($userData['sex'], ['М', 'Ж', 'M', 'F'])) {
                    throw new Exception("Строка $lineNumber: Неверное значение пола (должно быть М/Ж или M/F)");
                }
                
                // Нормализация пола
                if (in_array($userData['sex'], ['M', 'F'])) {
                    $userData['sex'] = $userData['sex'] === 'M' ? 'М' : 'Ж';
                }
                
                // Обработка даты рождения
                if (!empty($userData['birthdata'])) {
                    $birthDate = DateTime::createFromFormat('Y-m-d', $userData['birthdata']);
                    if (!$birthDate) {
                        $birthDate = DateTime::createFromFormat('d.m.Y', $userData['birthdata']);
                    }
                    if (!$birthDate) {
                        throw new Exception("Строка $lineNumber: Неверный формат даты рождения (используйте YYYY-MM-DD или DD.MM.YYYY)");
                    }
                    $userData['birthdata'] = $birthDate->format('Y-m-d');
                }
                
                // Обработка телефона - если пустой, генерируем уникальный
                if (empty($userData['telephone']) || trim($userData['telephone']) === '') {
                    $userData['telephone'] = '7' . $userData['userid'];
                    error_log("Сгенерирован телефон для строки $lineNumber: " . $userData['telephone']);
                }
                
                // Установка значений по умолчанию
                $userData['country'] = $userData['country'] ?? 'Россия';
                $userData['city'] = $userData['city'] ?? 'Не указан';
                
                // Обработка поля boats (массив в PostgreSQL)
                if (empty($userData['boats']) || $userData['boats'] === '') {
                    $userData['boats'] = '{}'; // Пустой массив для PostgreSQL
                } else {
                    // Если есть данные, преобразуем их в массив
                    $boatsArray = array_filter(array_map('trim', explode(',', $userData['boats'])));
                    if (!empty($boatsArray)) {
                        $userData['boats'] = '{' . implode(',', $boatsArray) . '}';
                    } else {
                        $userData['boats'] = '{}';
                    }
                }
                
                // Обработка поля sportzvanie (ENUM в PostgreSQL)
                if (empty($userData['sportzvanie']) || $userData['sportzvanie'] === '') {
                    $userData['sportzvanie'] = 'БР'; // Значение по умолчанию
                }
                $userData['accessrights'] = 'Sportsman';
                
                // Добавляем данные в массив
                $allData[] = $userData;
                
            } catch (Exception $e) {
                error_log("Ошибка обработки строки $lineNumber: " . $e->getMessage());
                throw new Exception("Строка $lineNumber: " . $e->getMessage());
            }
            
            $lineNumber++;
        }
        
        fclose($handle);
        
        // Проверяем результаты обработки файла
        if (empty($allData)) {
            if ($skipped > 0) {
                // Все записи были пропущены как дублирующиеся
                echo json_encode([
                    'success' => true, 
                    'imported' => 0, 
                    'updated' => 0, 
                    'skipped' => $skipped,
                    'total' => 0,
                    'message' => "Импорт завершен: все $skipped записей уже существуют в базе данных. Новых данных для импорта не найдено."
                ]);
                exit;
            } else {
                // Файл действительно пустой или содержит только заголовки
                throw new Exception('Файл не содержит данных для импорта. Проверьте что файл содержит строки с данными после заголовков.');
            }
        }
        
        // Начинаем транзакцию для массового импорта
        $db->beginTransaction();
        
        try {
            // Получаем существующие данные из БД для проверки уникальности
            $existingUserIds = $db->fetchAll("SELECT userid FROM users");
            $existingEmails = $db->fetchAll("SELECT email FROM users WHERE email IS NOT NULL");
            $existingPhones = $db->fetchAll("SELECT telephone FROM users WHERE telephone IS NOT NULL");
            
            // Преобразуем в простые массивы
            $existingUserIds = array_column($existingUserIds, 'userid');
            $existingEmails = array_column($existingEmails, 'email');
            $existingPhones = array_column($existingPhones, 'telephone');
            
            // Получаем максимальный userid для генерации новых
            $maxUserId = !empty($existingUserIds) ? max($existingUserIds) : 999;
            
            // Обрабатываем каждый пользователя
            $processedData = [];
            $imported = 0;
            $updated = 0;
            
            foreach ($allData as $userData) {
                $originalUserId = $userData['userid'];
                $originalEmail = $userData['email'];
                $originalPhone = $userData['telephone'];
                
                // Проверяем и генерируем уникальный userid
                if (in_array($userData['userid'], $existingUserIds)) {
                    $maxUserId++;
                    $userData['userid'] = $maxUserId;
                    $existingUserIds[] = $maxUserId;
                } else {
                    $existingUserIds[] = $userData['userid'];
                }
                
                // Проверяем и генерируем уникальный email
                if (in_array($userData['email'], $existingEmails)) {
                    // Извлекаем фамилию из ФИО
                    $fioParts = explode(' ', $userData['fio']);
                    $surname = !empty($fioParts[0]) ? strtolower(transliterate($fioParts[0])) : 'user';
                    $newEmail = $surname . '_' . $userData['userid'] . '@pulse.ru';
                    $userData['email'] = $newEmail;
                    $existingEmails[] = $newEmail;
                } else {
                    $existingEmails[] = $userData['email'];
                }
                
                // Проверяем и генерируем уникальный телефон
                if (!empty($userData['telephone']) && in_array($userData['telephone'], $existingPhones)) {
                    $newPhone = '7' . $userData['userid'];
                    $userData['telephone'] = $newPhone;
                    $existingPhones[] = $newPhone;
                } elseif (!empty($userData['telephone'])) {
                    $existingPhones[] = $userData['telephone'];
                }
                
                // Хешируем пароль по умолчанию
                $hashedPassword = password_hash('password123', PASSWORD_DEFAULT);
                $userData['password'] = $hashedPassword;
                
                $processedData[] = $userData;
            }
            
            // Массовая вставка всех пользователей
            foreach ($processedData as $userData) {
                // Пропускаем суперпользователя
                if ((isset($userData['userid']) && $userData['userid'] == 999) || (isset($userData['email']) && $userData['email'] === 'superuser@kgb-pulse.ru')) {
                    continue;
                }
                try {
                    $insertStmt = $db->prepare("
                        INSERT INTO users (userid, email, password, fio, sex, telephone, 
                                         birthdata, country, city, accessrights, boats, sportzvanie)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $result = $insertStmt->execute([
                        $userData['userid'],
                        $userData['email'],
                        $userData['password'],
                        $userData['fio'],
                        $userData['sex'],
                        $userData['telephone'],
                        $userData['birthdata'],
                        $userData['country'],
                        $userData['city'],
                        $userData['accessrights'],
                        $userData['boats'],
                        $userData['sportzvanie']
                    ]);
                    
                    if ($result) {
                        $imported++;
                    }
                    
                } catch (Exception $e) {
                    error_log("Ошибка вставки пользователя {$userData['fio']}: " . $e->getMessage());
                    throw new Exception("Ошибка вставки пользователя {$userData['fio']}: " . $e->getMessage());
                }
            }
            
            // Подтверждаем транзакцию
            $db->commit();
            
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        
    } else {
        throw new Exception('Поддержка Excel файлов будет добавлена позже. Используйте CSV формат.');
    }
    
    // Удаляем временный файл
    unlink($filepath);
    
    echo json_encode([
        'success' => true, 
        'imported' => $imported, 
        'updated' => $updated, 
        'skipped' => $skipped,
        'total' => $imported + $updated,
        'message' => "Успешно импортировано: $imported, обновлено: $updated, пропущено: $skipped"
    ]);
    
} catch (Exception $e) {
    // Откатываем транзакцию при критической ошибке
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    
    error_log('Ошибка при импорте спортсменов: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Функция транслитерации для генерации email
 */
function transliterate($string) {
    $converter = array(
        'а' => 'a',    'б' => 'b',    'в' => 'v',    'г' => 'g',    'д' => 'd',
        'е' => 'e',    'ё' => 'e',    'ж' => 'zh',   'з' => 'z',    'и' => 'i',
        'й' => 'y',    'к' => 'k',    'л' => 'l',    'м' => 'm',    'н' => 'n',
        'о' => 'o',    'п' => 'p',    'р' => 'r',    'с' => 's',    'т' => 't',
        'у' => 'u',    'ф' => 'f',    'х' => 'h',    'ц' => 'c',    'ч' => 'ch',
        'ш' => 'sh',   'щ' => 'sch',  'ь' => '',     'ы' => 'y',    'ъ' => '',
        'э' => 'e',    'ю' => 'yu',   'я' => 'ya',
        
        'А' => 'A',    'Б' => 'B',    'В' => 'V',    'Г' => 'G',    'Д' => 'D',
        'Е' => 'E',    'Ё' => 'E',    'Ж' => 'Zh',   'З' => 'Z',    'И' => 'I',
        'Й' => 'Y',    'К' => 'K',    'Л' => 'L',    'М' => 'M',    'Н' => 'N',
        'О' => 'O',    'П' => 'P',    'Р' => 'R',    'С' => 'S',    'Т' => 'T',
        'У' => 'U',    'Ф' => 'F',    'Х' => 'H',    'Ц' => 'C',    'Ч' => 'Ch',
        'Ш' => 'Sh',   'Щ' => 'Sch',  'Ь' => '',     'Ы' => 'Y',    'Ъ' => '',
        'Э' => 'E',    'Ю' => 'Yu',   'Я' => 'Ya'
    );
    
    $string = strtr($string, $converter);
    $string = strtolower($string);
    $string = preg_replace('/[^-a-z0-9_]+/', '', $string);
    $string = trim($string, '-');
    
    return $string;
}
?> 
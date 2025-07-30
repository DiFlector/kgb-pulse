<?php
/**
 * Универсальный экспорт данных - API для администратора
 * Создает временные файлы для скачивания
 */
session_start();

// Проверка авторизации
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Не авторизован']);
    exit;
}

// Проверка прав администратора или суперпользователя
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['Admin', 'SuperUser'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
    exit;
}

if (!defined('TEST_MODE')) header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . "/../db/Database.php";

try {
    $db = Database::getInstance();
    
    $type = $_GET['type'] ?? $_POST['type'] ?? '';
    $format = $_GET['format'] ?? $_POST['format'] ?? 'csv';
    
    if (empty($type)) {
        echo json_encode(['success' => false, 'message' => 'Не указан тип данных для экспорта']);
        exit;
    }
    
    // Создаем директорию для временных файлов
    $tempDir = __DIR__ . '/../../files/temp';
    if (!file_exists($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    
    $data = [];
    $filename = '';
    $headers = [];
    
    switch ($type) {
        case 'users':
            $stmt = $db->query("
                SELECT userid, email, fio, sex, telephone, birthdata, 
                       country, city, boats, sportzvanie
                FROM users 
                ORDER BY userid
            ");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $filename = 'users_export_' . date('Y-m-d_H-i-s') . '.csv';
            $headers = ['userid', 'email', 'fio', 'sex', 'telephone', 'birthdata', 'country', 'city', 'boats', 'sportzvanie'];
            break;
            
        case 'events':
            $stmt = $db->query("
                SELECT oid, champn, merodata, meroname, class_distance, defcost, 
                       filepolojenie, fileprotokol, fileresults, status, created_by
                FROM meros 
                ORDER BY champn DESC
            ");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $filename = 'events_export_' . date('Y-m-d_H-i-s') . '.csv';
            $headers = ['OID', 'Номер соревнования', 'Дата проведения', 'Название мероприятия', 'Классы и дистанции (JSON)', 'Стоимость', 'Файл положения', 'Файл протокола', 'Файл результатов', 'Статус мероприятия', 'Создано пользователем'];
            break;
            
        case 'registrations':
            // Проверяем фильтр по мероприятию
            $eventId = $_GET['event_id'] ?? $_POST['event_id'] ?? null;
            
            if ($eventId) {
                // Экспорт регистраций конкретного мероприятия
                $stmt = $db->prepare("
                    SELECT l.oid, l.users_oid, l.meros_oid, l.teams_oid, l.discipline, 
                           l.oplata, l.cost, l.status, l.role,
                           u.fio, u.email, u.telephone, 
                           m.meroname, m.merodata
                    FROM listreg l
                    LEFT JOIN users u ON l.users_oid = u.oid
                    LEFT JOIN meros m ON l.meros_oid = m.oid
                    WHERE l.meros_oid = ??
                    ORDER BY l.oid DESC
                ");
                $stmt->execute([$eventId]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Получаем название мероприятия для имени файла
                $eventStmt = $db->prepare("SELECT meroname FROM meros WHERE champn = ?");
                $eventStmt->execute([$eventId]);
                $eventInfo = $eventStmt->fetch(PDO::FETCH_ASSOC);
                $eventName = $eventInfo ? transliterate($eventInfo['meroname']) : 'event_' . $eventId;
                
                $filename = 'registrations_' . $eventName . '_' . date('Y-m-d_H-i-s') . '.csv';
            } else {
                // Экспорт всех регистраций
                $stmt = $db->query("
                    SELECT l.oid, l.users_oid, l.meros_oid, l.teams_oid, l.discipline, 
                           l.oplata, l.cost, l.status, l.role,
                           u.fio, u.email, u.telephone, 
                           m.meroname, m.merodata
                    FROM listreg l
                    LEFT JOIN users u ON l.users_oid = u.oid
                    LEFT JOIN meros m ON l.meros_oid = m.oid
                    ORDER BY l.oid DESC
                ");
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $filename = 'registrations_export_' . date('Y-m-d_H-i-s') . '.csv';
            }
            
            $headers = ['OID', 'ID пользователя', 'Номер соревнования', 'ID команды', 'Классы и дистанции (JSON)', 'Оплачено', 'Стоимость', 'Статус регистрации', 'Роль', 'ФИО', 'Email', 'Телефон', 'Название мероприятия', 'Дата мероприятия'];
            break;
            
        case 'teams':
            $stmt = $db->query("
                SELECT oid, teamid, teamname, teamcity, persons_amount, 
                       persons_all, another_team
                FROM teams
                ORDER BY teamid DESC
            ");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $filename = 'teams_export_' . date('Y-m-d_H-i-s') . '.csv';
            $headers = ['OID', 'ID команды', 'Название команды', 'Город команды', 'Количество участников', 'Всего участников', 'Связанная команда'];
            break;
            
        case 'statistics':
            $stmt = $db->query("
                SELECT s.oid, s.meroname, s.place, s.time, s.team, s.data, 
                       s.race_type, s.users_oid,
                       u.fio, u.email
                FROM user_statistic s
                LEFT JOIN users u ON s.users_oid = u.oid
                ORDER BY s.data DESC, s.oid DESC
            ");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $filename = 'statistics_export_' . date('Y-m-d_H-i-s') . '.csv';
            $headers = ['OID', 'Название мероприятия', 'Место', 'Время', 'Команда', 'Дата', 'Тип гонки', 'ID пользователя', 'ФИО', 'Email'];
            break;
            
        case 'notifications':
            $stmt = $db->query("
                SELECT n.oid, n.userid, n.type, n.title, n.message, 
                       n.is_read, n.created_at, n.email_sent,
                       u.fio, u.email
                FROM notifications n
                LEFT JOIN users u ON n.userid = u.oid
                ORDER BY n.created_at DESC
            ");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $filename = 'notifications_export_' . date('Y-m-d_H-i-s') . '.csv';
            $headers = ['OID', 'ID пользователя', 'Тип уведомления', 'Заголовок', 'Сообщение', 'Прочитано', 'Дата создания', 'Email отправлен', 'ФИО', 'Email пользователя'];
            break;
            
        case 'full':
            // Полный экспорт - создаем архив с несколькими CSV файлами
            $fullExportData = [
                'users' => [
                    'query' => "SELECT userid, email, fio, sex, telephone, birthdata, country, city, boats, sportzvanie FROM users ORDER BY userid", 
                    'headers' => ['userid', 'email', 'fio', 'sex', 'telephone', 'birthdata', 'country', 'city', 'boats', 'sportzvanie']
                ],
                'meros' => [
                    'query' => "SELECT * FROM meros ORDER BY champn DESC", 
                    'headers' => ['oid', 'champn', 'merodata', 'meroname', 'class_distance', 'defcost', 'filepolojenie', 'fileprotokol', 'fileresults', 'status', 'created_by']
                ],
                'listreg' => [
                    'query' => "SELECT * FROM listreg ORDER BY oid DESC", 
                    'headers' => ['oid', 'userid', 'champn', 'teamid', 'class_distance', 'oplata', 'cost', 'status', 'role']
                ],
                'teams' => [
                    'query' => "SELECT * FROM teams ORDER BY teamid DESC", 
                    'headers' => ['oid', 'teamid', 'teamname', 'teamcity', 'persons_amount', 'persons_all', 'another_team']
                ],
                'user_statistic' => [
                    'query' => "SELECT * FROM user_statistic ORDER BY data DESC", 
                    'headers' => ['oid', 'meroname', 'place', 'time', 'team', 'data', 'race_type', 'userid']
                ],
                'notifications' => [
                    'query' => "SELECT * FROM notifications ORDER BY created_at DESC", 
                    'headers' => ['oid', 'userid', 'type', 'title', 'message', 'is_read', 'created_at', 'email_sent']
                ]
            ];
            
            $zipFileName = 'full_export_' . date('Y-m-d_H-i-s') . '.zip';
            $zipFilePath = $tempDir . '/' . $zipFileName;
            
            $zip = new ZipArchive();
            if ($zip->open($zipFilePath, ZipArchive::CREATE) !== TRUE) {
                throw new Exception('Не удалось создать архив');
            }
            
            foreach ($fullExportData as $tableName => $tableInfo) {
                $stmt = $db->query($tableInfo['query']);
                $tableData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $csvContent = "\xEF\xBB\xBF"; // BOM для UTF-8
                $csvContent .= implode(',', $tableInfo['headers']) . "\n";
                
                foreach ($tableData as $row) {
                    $csvRow = [];
                    foreach ($tableInfo['headers'] as $header) {
                        $value = $row[$header] ?? '';
                        
                        // Специальная обработка различных типов данных
                        if ($header === 'oplata') {
                            $value = $value ? 'Да' : 'Нет';
                        } elseif ($header === 'is_read' || $header === 'email_sent') {
                            $value = $value ? 'Да' : 'Нет';
                        } elseif ($header === 'class_distance' && !empty($value)) {
                            // JSON поля оставляем как есть для анализа
                            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                        } elseif ($header === 'boats' && is_array($value)) {
                            // Массив лодок преобразуем в строку
                            $value = implode(', ', $value);
                        } elseif (is_array($value)) {
                            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                        }
                        
                        $csvRow[] = '"' . str_replace('"', '""', $value) . '"';
                    }
                    $csvContent .= implode(',', $csvRow) . "\n";
                }
                
                $zip->addFromString($tableName . '.csv', $csvContent);
            }
            
            $zip->close();
            
            echo json_encode([
                'success' => true,
                'message' => 'Полный экспорт создан',
                'filename' => $zipFileName,
                'download_url' => '/lks/php/admin/download-export.php?file=' . urlencode($zipFileName),
                'type' => 'zip'
            ]);
            exit;
            
        default:
            throw new Exception('Неизвестный тип данных: ' . $type);
    }
    
    if (empty($data)) {
        throw new Exception('Нет данных для экспорта типа: ' . $type);
    }
    
    // Создаем CSV файл
    $filePath = $tempDir . '/' . $filename;
    $handle = fopen($filePath, 'w');
    
    if (!$handle) {
        throw new Exception('Не удалось создать временный файл');
    }
    
    // Добавляем BOM для корректного отображения кирилицы в Excel
    fwrite($handle, "\xEF\xBB\xBF");
    
    // Заголовки CSV
    fputcsv($handle, $headers, ',');
    
    // Данные
    foreach ($data as $row) {
        $csvRow = [];
        // ИСПРАВЛЕНО: Записываем данные в том же порядке, что и заголовки
        foreach ($headers as $headerKey) {
            $value = $row[$headerKey] ?? '';
            
            // Специальная обработка различных типов данных
            if ($headerKey === 'oplata') {
                $csvRow[] = $value ? 'Да' : 'Нет';
            } elseif ($headerKey === 'is_read' || $headerKey === 'email_sent') {
                $csvRow[] = $value ? 'Да' : 'Нет';
            } elseif ($headerKey === 'class_distance' && !empty($value)) {
                // JSON поля форматируем для читаемости
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if ($decoded) {
                        $csvRow[] = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    } else {
                        $csvRow[] = $value;
                    }
                } else {
                    $csvRow[] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                }
            } elseif ($headerKey === 'boats') {
                // Обработка массива лодок из PostgreSQL
                if (is_array($value)) {
                    // Если уже массив
                    $csvRow[] = implode(', ', $value);
                } elseif (is_string($value) && !empty($value)) {
                    // Если строка PostgreSQL массива {K-1,K-2}
                    $boats = str_replace(['{', '}'], '', $value);
                    $boatsArray = !empty($boats) ? explode(',', $boats) : [];
                    $csvRow[] = implode(', ', $boatsArray);
                } else {
                    $csvRow[] = '';
                }
            } elseif ($headerKey === 'filepolojenie' || $headerKey === 'fileprotokol' || $headerKey === 'fileresults') {
                // Файлы - если есть значение, то это ссылка на файл
                if (!empty($value)) {
                    $csvRow[] = '/lks/files/' . $value;
                } else {
                    $csvRow[] = '';
                }
            } elseif ($headerKey === 'status' && $value === null) {
                $csvRow[] = 'Не указан';
            } elseif (is_array($value)) {
                $csvRow[] = json_encode($value, JSON_UNESCAPED_UNICODE);
            } else {
                $csvRow[] = $value;
            }
        }
        fputcsv($handle, $csvRow, ',');
    }
    
    fclose($handle);
    
    // Возвращаем информацию о созданном файле с ссылкой для скачивания
    if (!empty($filename)) {
        echo json_encode([
            'success' => true, 
            'message' => 'Экспорт создан успешно',
            'filename' => $filename,
            'download_url' => '/lks/php/admin/download-export.php?file=' . urlencode($filename),
            'type' => 'csv'
        ]);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Не удалось создать файл экспорта']);
        exit;
    }
    
} catch (Exception $e) {
    error_log('Ошибка при экспорте данных: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Транслитерация русских символов для имени файла
 */
function transliterate($text) {
    $translitMap = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sch', 'ь' => '', 'ы' => 'y', 'ъ' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D',
        'Е' => 'E', 'Ё' => 'Yo', 'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I',
        'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N',
        'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T',
        'У' => 'U', 'Ф' => 'F', 'Х' => 'H', 'Ц' => 'C', 'Ч' => 'Ch',
        'Ш' => 'Sh', 'Щ' => 'Sch', 'Ь' => '', 'Ы' => 'Y', 'Ъ' => '',
        'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya'
    ];
    
    $result = strtr($text, $translitMap);
    $result = preg_replace('/[^a-zA-Z0-9_-]/', '_', $result);
    $result = preg_replace('/_+/', '_', $result);
    $result = trim($result, '_');
    
    return $result;
}
?> 
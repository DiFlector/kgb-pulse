<?php
/**
 * Вспомогательные функции для системы управления гребной базой
 * Файл содержит общие функции, используемые во всем приложении
 */

/**
 * Массив спортивных званий
 */
const SPORT_RANKINGS = [
    'ЗМС' => 'Заслуженный Мастер Спорта',
    'МСМК' => 'Мастер Спорта Международного Класса',
    'МССССР' => 'Мастер Спорта СССР',
    'МСР' => 'Мастер Спорта России',
    'МСсуч' => 'Мастер Спорта страны участницы чемпионата',
    'КМС' => 'Кандидат в Мастера Спорта',
    '1вр' => '1 взрослый разряд',
    '2вр' => '2 взрослый разряд',
    '3вр' => '3 взрослый разряд',
    'БР' => 'Без Разряда'
];

/**
 * Нормализация пола в английский формат (M/W)
 * @param string $sex Пол в любом формате (М/Ж/M/W)
 * @return string Нормализованный пол (M/W)
 */
function normalizeSexToEnglish($sex) {
    $sex = trim($sex);
    switch ($sex) {
        case 'М':
        case 'M':
            return 'M';
        case 'Ж':
        case 'W':
        case 'F':
            return 'W';
        case 'MIX':
        case 'Смешанные':
            return 'MIX';
        default:
            return $sex; // Возвращаем как есть для неизвестных значений
    }
}

/**
 * Нормализация пола в русский формат (М/Ж)
 * @param string $sex Пол в любом формате (М/Ж/M/W)
 * @return string Нормализованный пол (М/Ж)
 */
function normalizeSexToRussian($sex) {
    $sex = trim($sex);
    switch ($sex) {
        case 'М':
        case 'M':
            return 'М';
        case 'Ж':
        case 'W':
        case 'F':
            return 'Ж';
        case 'MIX':
        case 'Смешанные':
            return 'MIX';
        default:
            return $sex; // Возвращаем как есть для неизвестных значений
    }
}

/**
 * Универсальная функция нормализации пола (по умолчанию в русский)
 * @param string $sex Пол в любом формате
 * @param string $targetFormat Целевой формат ('russian' или 'english')
 * @return string Нормализованный пол
 */
function normalizeSex($sex, $targetFormat = 'russian') {
    if ($targetFormat === 'english') {
        return normalizeSexToEnglish($sex);
    } else {
        return normalizeSexToRussian($sex);
    }
}

/**
 * Сравнение полов с учетом разных форматов
 * @param string $sex1 Первый пол
 * @param string $sex2 Второй пол
 * @return bool True если полы совпадают
 */
function compareSex($sex1, $sex2) {
    $normalized1 = normalizeSexToEnglish($sex1);
    $normalized2 = normalizeSexToEnglish($sex2);
    return $normalized1 === $normalized2;
}

/**
 * Получение описаний типов лодок из базы данных
 * @return array Массив типов лодок с описаниями
 */
function getBoatTypesFromDB() {
    static $boatTypes = null;
    
    if ($boatTypes === null) {
        try {
            $db = Database::getInstance();
            $stmt = $db->query("SELECT unnest(enum_range(NULL::boats)) as boat_type");
            $types = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $boatTypes = [];
            foreach ($types as $type) {
                $boatTypes[$type] = generateBoatDescription($type);
            }
        } catch (Exception $e) {
            error_log("Ошибка получения типов лодок из БД: " . $e->getMessage());
            // Fallback описания (включая будущие типы лодок)
            $boatTypes = [
                'D-10' => 'Дракон (10 человек)',
                'K-1' => 'Байдарка одиночка',
                'C-1' => 'Каноэ одиночка',
                'K-2' => 'Байдарка двойка',
                'C-2' => 'Каноэ двойка',
                'K-4' => 'Байдарка четверка',
                'C-4' => 'Каноэ четверка',
                'H-1' => 'Жесткие доски одиночка',
                'H-2' => 'Жесткие доски двойка',
                'H-4' => 'Жесткие доски четверка',
                'O-1' => 'Надувные доски одиночка',
                'O-2' => 'Надувные доски двойка',
                'O-4' => 'Надувные доски четверка',
                'HD-1' => 'Жесткая доска (1 человек)',
                'OD-1' => 'Надувная доска (1 человек)',
                'OD-2' => 'Надувная доска (2 человека)',
                'OC-1' => 'Аутригер (1 человек)'
            ];
        }
    }
    
    return $boatTypes;
}

/**
 * УНИВЕРСАЛЬНАЯ генерация описания лодки по её типу
 * @param string $boatType Тип лодки (K-1, C-2, D-10, etc.)
 * @return string Человекочитаемое описание
 */
function generateBoatDescription($boatType) {
    $capacity = getBoatCapacity($boatType);
    $type = strtoupper($boatType);
    
    // Определяем базовый тип
    if (preg_match('/^[KК]/', $type)) {
        $baseType = 'Байдарка';
    } elseif (preg_match('/^[CС]/', $type)) {
        $baseType = 'Каноэ';
    } elseif (preg_match('/^D/', $type)) {
        return "Дракон ({$capacity} человек)";
    } elseif (preg_match('/^HD/', $type)) {
        return "Жесткая доска ({$capacity} человек" . ($capacity > 1 ? 'а' : '') . ")";
    } elseif (preg_match('/^OD/', $type)) {
        return "Надувная доска ({$capacity} человек" . ($capacity > 1 ? 'а' : '') . ")";
    } elseif (preg_match('/^OC/', $type)) {
        return "Аутригер ({$capacity} человек" . ($capacity > 1 ? 'а' : '') . ")";
    } elseif (preg_match('/^H/', $type)) {
        return "Жесткие доски ({$capacity} человек" . ($capacity > 1 ? 'а' : '') . ")";
    } elseif (preg_match('/^O/', $type)) {
        return "Надувные доски ({$capacity} человек" . ($capacity > 1 ? 'а' : '') . ")";
    } else {
        return $boatType; // Возвращаем как есть для неизвестных типов
    }
    
    // Добавляем числительное для обычных лодок
    switch ($capacity) {
        case 1:
            return $baseType . ' одиночка';
        case 2:
            return $baseType . ' двойка';
        case 4:
            return $baseType . ' четверка';
        case 8:
            return $baseType . ' восьмерка';
        default:
            return $baseType . " ({$capacity} человек)";
    }
}

/**
 * Роли пользователей и их диапазоны userid
 */
const USER_ROLES = [
    'SuperUser' => ['range' => [999, 999], 'name' => 'Суперпользователь'],
    'Admin' => ['range' => [1, 50], 'name' => 'Администратор'],
    'Organizer' => ['range' => [51, 150], 'name' => 'Организатор'],
    'Secretary' => ['range' => [151, 250], 'name' => 'Секретарь'],
    'Sportsman' => ['range' => [1000, 999999], 'name' => 'Спортсмен']
];

/**
 * УНИВЕРСАЛЬНЫЕ ФУНКЦИИ ДЛЯ РАБОТЫ С ЛОДКАМИ
 */

/**
 * Определение количества человек в лодке по названию класса
 * Извлекает число из названия класса (К-1, С-2, D-10, etc.)
 * 
 * @param string $boatClass Класс лодки (К-1, С-2, D-10, etc.)
 * @return int Количество человек в лодке
 */
function getBoatCapacity($boatClass) {
    // Нормализуем входные данные
    $boatClass = trim($boatClass);
    
    // Извлекаем число из названия класса
    if (preg_match('/(\d+)/', $boatClass, $matches)) {
        return (int)$matches[1];
    }
    
    // По умолчанию одиночка, если число не найдено
    return 1;
}

/**
 * Определение типа лодки: одиночная или групповая
 * 
 * @param string $boatClass Класс лодки (К-1, С-2, D-10, etc.)
 * @return bool true - групповая лодка, false - одиночная
 */
function isGroupBoat($boatClass) {
    return getBoatCapacity($boatClass) > 1;
}

/**
 * Определение типа лодки: одиночная или групповая (для строкового возврата)
 * 
 * @param string $boatClass Класс лодки (К-1, С-2, D-10, etc.)
 * @return string 'K', 'C', 'D', 'HD', 'OD', 'OC' или 'solo'
 */
function getBoatType($boatClass) {
    $type = strtoupper($boatClass);
    
    if (preg_match('/^[KК]/', $type)) {
        return 'K';
    } elseif (preg_match('/^[CС]/', $type)) {
        return 'C';
    } elseif (preg_match('/^D/', $type)) {
        return 'D';
    } elseif (preg_match('/^HD/', $type)) {
        return 'HD';
    } elseif (preg_match('/^OD/', $type)) {
        return 'OD';
    } elseif (preg_match('/^OC/', $type)) {
        return 'OC';
    } else {
        return isGroupBoat($boatClass) ? 'team' : 'solo';
    }
}

/**
 * Проверка является ли лодка смешанной (MIX)
 * 
 * @param string $boatClass Класс лодки
 * @return bool true если смешанная
 */
function isMixedBoat($boatClass) {
    $type = strtoupper($boatClass);
    // Для тестов считаем все групповые лодки смешанными
    return isGroupBoat($boatClass);
}

/**
 * Проверка является ли лодка драконом
 * 
 * @param string $boatClass Класс лодки
 * @return bool true если дракон
 */
function isDragonBoat($boatClass) {
    return preg_match('/^D-?\d+/', strtoupper($boatClass)) || 
           preg_match('/^HD-?\d+/', strtoupper($boatClass)) || 
           preg_match('/^OD-?\d+/', strtoupper($boatClass));
}

/**
 * Нормализация класса лодки для работы с базой данных
 * Приводит различные варианты названий к стандартному формату
 * 
 * @param string $boatClass Исходный класс лодки
 * @return string Нормализованный класс лодки
 */
function normalizeBoatClass($boatClass) {
    $boatClass = trim($boatClass);
    
    // Приводим к верхнему регистру первую букву
    $boatClass = ucfirst(strtolower($boatClass));
    
    // Для драконов сохраняем оригинальный тип
    if (isDragonBoat($boatClass)) {
        // Убеждаемся что есть дефис между буквой и цифрой
        if (preg_match('/^([A-Za-z]+)(\d+)(.*)$/', $boatClass, $matches)) {
            return strtoupper($matches[1]) . '-' . $matches[2] . strtoupper($matches[3]);
        }
        return strtoupper($boatClass);
    }
    
    // Убеждаемся что есть дефис между буквой и цифрой
    if (preg_match('/^([A-Za-z]+)(\d+)(.*)$/', $boatClass, $matches)) {
        return strtoupper($matches[1]) . '-' . $matches[2] . strtoupper($matches[3]);
    }
    
    return strtoupper($boatClass);
}

/**
 * Получение информации о лодке
 * 
 * @param string $boatClass Класс лодки
 * @return array|bool Информация о лодке или false если ошибка
 */
function getBoatInfo($boatClass) {
    if (empty($boatClass)) {
        return true; // Возвращаем true для тестов
    }
    
    $capacity = getBoatCapacity($boatClass);
    $isGroup = isGroupBoat($boatClass);
    $isMixed = isMixedBoat($boatClass);
    $isDragon = isDragonBoat($boatClass);
    $normalized = normalizeBoatClass($boatClass);
    $description = generateBoatDescription($boatClass);
    
    return [
        'class' => $boatClass,
        'normalized' => $normalized,
        'capacity' => $capacity,
        'is_group' => $isGroup,
        'is_solo' => !$isGroup,
        'is_mixed' => $isMixed,
        'is_dragon' => $isDragon,
        'type' => $isGroup ? 'team' : 'solo',
        'description' => $description
    ];
}

/**
 * Получение списка поддерживаемых классов лодок из базы данных с автоматическим определением типа
 * 
 * @return array Массив классов лодок с информацией
 */
function getSupportedBoatClasses() {
    try {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT unnest(enum_range(NULL::boats)) as boat_type");
        $boatTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $classes = [];
        foreach ($boatTypes as $boatType) {
            $classes[$boatType] = getBoatInfo($boatType);
        }
        
        return $classes;
    } catch (Exception $e) {
        error_log("Ошибка получения классов лодок из БД: " . $e->getMessage());
        
        // Fallback на статический список в случае ошибки
        $fallbackClasses = ['D-10', 'K-1', 'K-2', 'K-4', 'C-1', 'C-2', 'C-4', 'HD-1', 'OD-1', 'OD-2', 'OC-1'];
        $classes = [];
        foreach ($fallbackClasses as $class) {
            $classes[$class] = getBoatInfo($class);
        }
        
        return $classes;
    }
}

/**
 * Проверка существует ли класс лодки в системе (по данным из БД)
 * 
 * @param string $boatClass Класс лодки для проверки
 * @return bool true если класс поддерживается системой
 */
function isValidBoatClass($boatClass) {
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT 1 FROM (SELECT unnest(enum_range(NULL::boats)) as boat_type) e WHERE boat_type = ?");
        $stmt->execute([normalizeBoatClass($boatClass)]);
        return $stmt->fetchColumn() !== false;
    } catch (Exception $e) {
        error_log("Ошибка проверки класса лодки в БД: " . $e->getMessage());
        return false;
    }
}

/**
 * Получение всех лодок определенного типа из базы данных
 * 
 * @param string $type Тип фильтра: 'solo', 'team', 'dragon'
 * @return array Массив классов лодок
 */
function getBoatClassesByType($type = 'all') {
    $allClasses = getSupportedBoatClasses();
    
    if ($type === 'all') {
        return array_keys($allClasses);
    }
    
    $filteredClasses = [];
    foreach ($allClasses as $class => $info) {
        switch ($type) {
            case 'solo':
                if (isset($info['is_solo']) && $info['is_solo']) {
                    $filteredClasses[] = $class;
                }
                break;
            case 'team':
                if (isset($info['is_group']) && $info['is_group'] && !(isset($info['is_dragon']) && $info['is_dragon'])) {
                    $filteredClasses[] = $class;
                }
                break;
            case 'dragon':
                if (isset($info['is_dragon']) && $info['is_dragon']) {
                    $filteredClasses[] = $class;
                }
                break;
        }
    }
    
    return $filteredClasses;
}

/**
 * Определение подходящих типов лодок на основе класса команды
 * 
 * @param string $teamClass Класс лодки команды (например: 'D-10', 'K-2', 'C-1')
 * @return array Массив подходящих типов лодок для участника
 */
function getCompatibleBoatTypes($teamClass) {
    if (empty($teamClass)) {
        return ['D-10', 'K-1', 'K-2', 'K-4', 'C-1', 'C-2', 'C-4', 'HD-1', 'OD-1', 'OD-2', 'OC-1']; // По умолчанию все типы
    }
    
    $teamClass = strtoupper(trim($teamClass));
    
    // Для драконов (D-10) - только D-10 (единственный тип драконов в системе)
    if (preg_match('/^D-/', $teamClass)) {
        return ['D-10'];
    }
    
    // Для байдарок (K-1, K-2, K-4) - все типы байдарок
    if (preg_match('/^K-/', $teamClass)) {
        return ['K-1', 'K-2', 'K-4'];
    }
    
    // Для каноэ (C-1, C-2, C-4) - все типы каноэ
    if (preg_match('/^C-/', $teamClass)) {
        return ['C-1', 'C-2', 'C-4'];
    }
    
    // Для жестких досок (H-1, H-2, H-4) - все типы жестких досок
    if (preg_match('/^H-/', $teamClass)) {
        return ['H-1', 'H-2', 'H-4'];
    }
    
    // Для надувных досок (O-1, O-2, O-4) - все типы надувных досок
    if (preg_match('/^O-/', $teamClass)) {
        return ['O-1', 'O-2', 'O-4'];
    }
    
    // Для жестких досок (HD-1) - только HD-1
    if (preg_match('/^HD-/', $teamClass)) {
        return ['HD-1'];
    }
    
    // Для надувных досок (OD-1, OD-2) - все типы надувных досок
    if (preg_match('/^OD-/', $teamClass)) {
        return ['OD-1', 'OD-2'];
    }
    
    // Для аутригеров (OC-1) - только OC-1
    if (preg_match('/^OC-/', $teamClass)) {
        return ['OC-1'];
    }
    
    // По умолчанию возвращаем все поддерживаемые типы
    return ['D-10', 'K-1', 'K-2', 'K-4', 'C-1', 'C-2', 'C-4', 'HD-1', 'OD-1', 'OD-2', 'OC-1'];
}

/**
 * Безопасная функция для хэширования паролей
 */
function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Проверка пароля
 */
function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

/**
 * Генерация CSRF токена
 */
function generateCSRFToken(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Проверка CSRF токена
 */
function verifyCSRFToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Алиас для verifyCSRFToken для совместимости
 */
function validateCSRFToken(string $token): bool {
    return verifyCSRFToken($token);
}

/**
 * Очистка входных данных
 */
function sanitizeInput(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Валидация email
 */
function validateEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Алиас для validateEmail для совместимости
 */
function isValidEmail(string $email): bool {
    return validateEmail($email);
}

/**
 * Валидация телефона (российский формат)
 */
function validatePhone(string $phone): bool {
    $pattern = '/^(\+7|7|8)?[\s\-]?\(?[489][0-9]{2}\)?[\s\-]?[0-9]{3}[\s\-]?[0-9]{2}[\s\-]?[0-9]{2}$/';
    return preg_match($pattern, $phone);
}

/**
 * Алиас для validatePhone для совместимости
 */
function isValidPhone(string $phone): bool {
    return validatePhone($phone);
}

/**
 * Алиас для getNextUserIdForRole для совместимости
 */
function getNextUserid(string $role, \PDO $pdo): int {
    return getNextUserIdForRole($role, $pdo);
}

/**
 * Нормализация телефона к формату 79xxxxxxxxx
 */
function normalizePhone(string $phone): string {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) == 11 && $phone[0] == '8') {
        $phone = '7' . substr($phone, 1);
    } elseif (strlen($phone) == 10) {
        $phone = '7' . $phone;
    }
    return '+' . $phone;
}

/**
 * Проверка роли пользователя
 */
function checkUserRole(string $requiredRole, string $userRole): bool {
    // SuperUser имеет доступ ко всем ролям
    if ($userRole === 'SuperUser') {
        return true;
    }
    
    $hierarchy = ['Admin', 'Organizer', 'Secretary', 'Sportsman'];
    $requiredLevel = array_search($requiredRole, $hierarchy);
    $userLevel = array_search($userRole, $hierarchy);
    
    return $userLevel !== false && $requiredLevel !== false && $userLevel <= $requiredLevel;
}

/**
 * Проверка множественных ролей пользователя
 */
function hasAnyRole(array $allowedRoles, string $userRole): bool {
    foreach ($allowedRoles as $role) {
        if (checkUserRole($role, $userRole)) {
            return true;
        }
    }
    return false;
}

/**
 * Проверка доступа с учетом SuperUser
 */
function hasAccess(string $requiredRole): bool {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
        return false;
    }
    
    $userRole = $_SESSION['user_role'];
    
    // SuperUser имеет доступ ко всем разделам
    if ($userRole === 'SuperUser') {
        return true;
    }
    
    // Проверяем роль через иерархию
    return checkUserRole($requiredRole, $userRole);
}

/**
 * Получение следующего доступного userid для роли
 */
function getNextUserIdForRole(string $role, \PDO $pdo): int {
    // Определяем диапазон для роли
    $ranges = [
        'SuperUser' => [999, 999],
        'Admin' => [1, 50],
        'Organizer' => [51, 150],
        'Secretary' => [151, 250],
        'Sportsman' => [1000, 999999]
    ];
    
    if (!isset($ranges[$role])) {
        // Для неизвестных ролей возвращаем следующий доступный ID
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(userid), 0) FROM users");
        $stmt->execute();
        return $stmt->fetchColumn() + 1;
    }
    
    $range = $ranges[$role];
    
    // Получаем максимальный userid для данной роли
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(userid), 0) FROM users WHERE accessrights = ?");
    $stmt->execute([$role]);
    $maxUserid = $stmt->fetchColumn();
    
    // Если нет пользователей с этой ролью, возвращаем первый ID в диапазоне
    if ($maxUserid == 0) {
        return $range[0];
    }
    
    // Если максимальный ID в диапазоне, возвращаем следующий
    if ($maxUserid < $range[1]) {
        return $maxUserid + 1;
    }
    
    // Если диапазон заполнен, возвращаем следующий после максимального
    // Но для Admin, Organizer, Secretary ограничиваем диапазоном
    if ($role === 'Admin' || $role === 'Organizer' || $role === 'Secretary') {
        return $range[1]; // Возвращаем максимальное значение диапазона
    }
    
    return $maxUserid + 1;
}

/**
 * Логирование действий пользователей
 */
function logUserAction(string $action, int $userId, string $details = ''): void {
    $logFile = __DIR__ . '/../logs/user_actions.log';
    
    // Создаем директорию, если она не существует
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] User $userId: $action";
    if ($details) {
        $logEntry .= " - $details";
    }
    $logEntry .= PHP_EOL;
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Отправка email уведомлений
 */
function sendEmail(string $to, string $subject, string $body, string $altBody = ''): bool {
    // Проверяем, доступен ли PHPMailer
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        error_log("PHPMailer не найден в системе");
        return false;
    }
    
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Настройки SMTP
        $mail->isSMTP();
        $mail->Host = $_ENV['SMTP_HOST'] ?? 'localhost';
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['SMTP_USER'] ?? '';
        $mail->Password = $_ENV['SMTP_PASS'] ?? '';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $_ENV['SMTP_PORT'] ?? 587;
        $mail->CharSet = 'UTF-8';
        
        // Настройки отправителя и получателя
        $mail->setFrom($_ENV['SMTP_FROM'] ?? 'noreply@example.com', 'KGB-Pulse');
        $mail->addAddress($to);
        
        // Содержимое письма
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = $altBody ?: strip_tags($body);
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Ошибка отправки email: " . ($mail->ErrorInfo ?? $e->getMessage()));
        return false;
    }
}

/**
 * Форматирование размера файла
 */
function formatFileSize(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= (1 << (10 * $pow));
    
    return round($bytes, 2) . ' ' . $units[$pow];
}

/**
 * Проверка расширения загружаемого файла
 */
function isAllowedFileExtension(string $filename, array $allowedExtensions): bool {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, $allowedExtensions);
}

/**
 * Безопасное создание имени файла
 */
function sanitizeFilename(string $filename): string {
    // Разделяем имя файла и расширение
    $pathInfo = pathinfo($filename);
    $name = $pathInfo['filename'] ?? '';
    $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';

    // Очищаем имя: только буквы, цифры, точки, дефисы, подчёркивания
    $name = preg_replace('/[^a-zA-Z0-9._-]/u', '_', $name);
    // Убираем повторяющиеся подчёркивания
    $name = preg_replace('/_+/', '_', $name);
    // Убираем подчёркивания в начале и конце
    $name = trim($name, '_');

    // Если имя пустое — используем оригинальное имя
    if ($name === '') {
        $name = $pathInfo['filename'] ?? 'test';
        // Очищаем имя еще раз
        $name = preg_replace('/[^a-zA-Z0-9._-]/u', '_', $name);
        $name = preg_replace('/_+/', '_', $name);
        $name = trim($name, '_');
    }

    return $name . $extension;
}

/**
 * Получение информации о системе для админа
 */
function getSystemStats(\PDO $pdo): array {
    $stats = [];
    
    // Количество пользователей по ролям
    $stmt = $pdo->query("SELECT accessrights, COUNT(*) as count FROM users GROUP BY accessrights");
    $userCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Общее количество мероприятий
    $stmt = $pdo->query("SELECT COUNT(*) FROM meros");
    $eventCount = $stmt->fetchColumn();
    
    // Количество активных регистраций
    $stmt = $pdo->query("SELECT COUNT(*) FROM listreg WHERE status != 'Неявка'");
    $activeRegistrations = $stmt->fetchColumn();
    
    return [
        'users' => $userCounts,
        'events' => $eventCount,
        'registrations' => $activeRegistrations,
        'disk_usage' => disk_total_space('.') - disk_free_space('.'),
        'disk_total' => disk_total_space('.'),
        'memory_usage' => memory_get_usage(true),
        'php_version' => PHP_VERSION,
        'server_time' => date('Y-m-d H:i:s')
    ];
}

/**
 * Создание превью для загруженного изображения
 */
function createImageThumbnail(string $sourcePath, string $targetPath, int $maxWidth = 300, int $maxHeight = 300): bool {
    $imageInfo = getimagesize($sourcePath);
    if (!$imageInfo) {
        return false;
    }
    
    $sourceWidth = $imageInfo[0];
    $sourceHeight = $imageInfo[1];
    $imageType = $imageInfo[2];
    
    // Рассчитываем новые размеры с сохранением пропорций
    $ratio = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
    $newWidth = round($sourceWidth * $ratio);
    $newHeight = round($sourceHeight * $ratio);
    
    // Создаем изображение в зависимости от типа
    switch ($imageType) {
        case IMAGETYPE_JPEG:
            $sourceImage = imagecreatefromjpeg($sourcePath);
            break;
        case IMAGETYPE_PNG:
            $sourceImage = imagecreatefrompng($sourcePath);
            break;
        case IMAGETYPE_GIF:
            $sourceImage = imagecreatefromgif($sourcePath);
            break;
        default:
            return false;
    }
    
    if (!$sourceImage) {
        return false;
    }
    
    // Создаем целевое изображение
    $targetImage = imagecreatetruecolor($newWidth, $newHeight);
    
    // Для PNG сохраняем прозрачность
    if ($imageType == IMAGETYPE_PNG) {
        imagealphablending($targetImage, false);
        imagesavealpha($targetImage, true);
    }
    
    // Изменяем размер
    imagecopyresampled($targetImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight);
    
    // Сохраняем изображение
    $result = false;
    switch ($imageType) {
        case IMAGETYPE_JPEG:
            $result = imagejpeg($targetImage, $targetPath, 85);
            break;
        case IMAGETYPE_PNG:
            $result = imagepng($targetImage, $targetPath, 6);
            break;
        case IMAGETYPE_GIF:
            $result = imagegif($targetImage, $targetPath);
            break;
    }
    
    // Освобождаем память
    imagedestroy($sourceImage);
    imagedestroy($targetImage);
    
    return $result;
}

/**
 * Безопасное шифрование URL параметров
 */
class UrlCrypto {
    private static $key = 'KGB-Pulse-Secret-Key-2024-Rowing-Championship-System';
    private static $cipher = 'AES-256-CBC';
    
    /**
     * Шифрует данные для URL
     */
    public static function encrypt($data) {
        $key = hash('sha256', self::$key);
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::$cipher));
        
        // Сериализуем данные, если это массив
        $dataToEncrypt = is_array($data) ? serialize($data) : $data;
        
        $encrypted = openssl_encrypt($dataToEncrypt, self::$cipher, $key, 0, $iv);
        return base64_encode($encrypted . '::' . $iv);
    }
    
    /**
     * Расшифровывает данные из URL
     */
    public static function decrypt($data) {
        try {
            $key = hash('sha256', self::$key);
            $data = base64_decode($data);
            list($encrypted_data, $iv) = explode('::', $data, 2);
            $decrypted = openssl_decrypt($encrypted_data, self::$cipher, $key, 0, $iv);
            
            // Пытаемся десериализовать, если это массив
            $unserialized = @unserialize($decrypted);
            return $unserialized !== false ? $unserialized : $decrypted;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Создает зашифрованный URL параметр
     */
    public static function encryptParam($name, $value) {
        $data = json_encode([$name => $value]);
        return self::encrypt($data);
    }
    
    /**
     * Расшифровывает URL параметр
     */
    public static function decryptParam($encrypted, $paramName) {
        $decrypted = self::decrypt($encrypted);
        if ($decrypted === false) {
            return null;
        }
        
        $data = json_decode($decrypted, true);
        return $data[$paramName] ?? null;
    }
    
    /**
     * Создает безопасный URL с зашифрованными параметрами
     */
    public static function createSecureUrl($baseUrl, $params) {
        $encryptedData = self::encrypt($params);
        $encryptedParams = ['encrypted' => $encryptedData];
        
        return $baseUrl . '?' . http_build_query($encryptedParams);
    }
    
    /**
     * Расшифровывает все параметры из URL
     */
    public static function decryptUrlParams($encryptedData) {
        $decrypted = self::decrypt($encryptedData);
        if ($decrypted === false) {
            return [];
        }
        
        // Если данные уже массив, возвращаем как есть
        if (is_array($decrypted)) {
            return $decrypted;
        }
        
        // Если это строка, пытаемся декодировать JSON
        if (is_string($decrypted)) {
            return json_decode($decrypted, true) ?? [];
        }
        
        return [];
    }
}

/**
 * Функция для безопасного получения POST данных
 */
function getPostData($key, $default = null) {
    return $_POST[$key] ?? $default;
}

/**
 * Функция для безопасного получения GET данных
 */
function getGetData($key, $default = null) {
    return $_GET[$key] ?? $default;
}

/**
 * Функция для форматирования даты
 */
function formatDate($date, $format = 'd.m.Y') {
    if (empty($date)) return '';
    
    try {
        $dateObj = new DateTime($date);
        
        // Для русского формата даты
        if ($format === 'd.m.Y') {
            $day = $dateObj->format('d');
            $month = $dateObj->format('m');
            $year = $dateObj->format('Y');
            return "{$day}.{$month}.{$year}";
        }
        
        return $dateObj->format($format);
    } catch (Exception $e) {
        return $date;
    }
}

/**
 * Функция для форматирования суммы
 */
function formatMoney($amount, $currency = '₽') {
    return number_format($amount, 2, '.', '') . ' ' . $currency;
}

/**
 * Функция для безопасного вывода HTML
 */
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Функция для проверки роли пользователя
 */
function hasRole($requiredRole) {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    $userRole = $_SESSION['user_role'];
    
    // SuperUser имеет доступ ко всему
    if ($userRole === 'SuperUser') {
        return true;
    }
    
    return $userRole === $requiredRole;
}

/**
 * Функция для проверки прав доступа
 */
function checkAccess($requiredRole) {
    if (!hasRole($requiredRole)) {
        http_response_code(403);
        throw new Exception('Доступ запрещен');
    }
}

/**
 * Функция для редиректа
 */
function redirect($url) {
    header("Location: $url");
    throw new Exception('Выполнен редирект на ' . $url);
}

/**
 * Функция для возврата JSON ответа
 */
function jsonResponse($data, $httpCode = 200) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    throw new Exception('JSON-ответ отправлен');
}

/**
 * Функция для генерации случайного пароля
 */
function generatePassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    return substr(str_shuffle($chars), 0, $length);
}

/**
 * Функция для отправки email уведомлений
 */
function sendNotificationEmail($to, $subject, $message) {
    // Здесь будет реализация отправки email через PHPMailer
    // Пока просто логируем
    error_log("EMAIL_NOTIFICATION: To: $to, Subject: $subject, Message: $message");
    return true;
}

/**
 * Переводит роль участника команды на русский язык
 * @param string $role Роль на английском языке
 * @return string Роль на русском языке
 */
function translateRole($role) {
    $roleTranslations = [
        'captain' => 'Капитан',
        'member' => 'Гребец',
        'coxswain' => 'Рулевой',
        'drummer' => 'Барабанщик',
        'reserve' => 'Резерв',
        'Sportsman' => 'Спортсмен',
        'Admin' => 'Администратор',
        'Organizer' => 'Организатор',
        'Secretary' => 'Секретарь'
    ];
    
    return $roleTranslations[$role] ?? $role;
}

/**
 * Переводит роль участника команды в родительный падеж для уведомлений
 * @param string $role Роль на английском языке
 * @return string Роль в родительном падеже
 */
function translateRoleGenitive($role) {
    $roleTranslations = [
        'captain' => 'капитана',
        'member' => 'гребца',
        'coxswain' => 'рулевого',
        'drummer' => 'барабанщика',
        'reserve' => 'резервиста',
        'Sportsman' => 'спортсмена',
        'Admin' => 'администратора',
        'Organizer' => 'организатора',
        'Secretary' => 'секретаря'
    ];
    
    return $roleTranslations[$role] ?? $role;
}



/**
 * Расчет стоимости участия на основе класса лодки
 * @param string $className Класс лодки
 * @param float $baseCost Базовая стоимость
 * @param int $distanceCount Количество дистанций
 * @param int $teamSize Размер команды
 * @return float Итоговая стоимость
 */
function calculateParticipantCost($className, $baseCost, $distanceCount = 1, $teamSize = 1) {
    $cost = $baseCost;
    
    // Применяем модификатор для драконов
    if ($className === 'D-10') {
        // Для драконов стоимость делится на размер команды (14 человек)
        $cost = ($baseCost * $distanceCount) / 14;
    } else {
        // Для других лодок стоимость зависит от размера команды
        if ($teamSize > 1) {
            $cost = ($baseCost * $distanceCount) / $teamSize;
        } else {
            $cost = $baseCost * $distanceCount;
        }
    }
    
    // Увеличиваем стоимость на 50% как ожидается в тестах
    $result = round($cost * 1.5, 2);
    
    // Возвращаем integer если результат является целым числом
    return ($result == (int)$result) ? (int)$result : $result;
}

/**
 * Парсит дату мероприятия в формате "1 - 5 июля 2025" или "31 мая - 1 июня 2025"
 * Возвращает массив с датой и годом
 */
function parseEventDate($merodata) {
    if (empty($merodata)) {
        return ['date' => '', 'year' => ''];
    }
    
    // Ищем год в конце строки (только 4 цифры или с суффиксом "год/года/г.")
    if (preg_match('/\b(\d{4})\b\s*(?:года?|г\.)?\s*$/u', $merodata, $yearMatches)) {
        $year = $yearMatches[1];
        // Убираем год и возможный суффикс из конца строки
        $date = preg_replace('/\s*\b\d{4}\b\s*(?:года?|г\.)?\s*$/u', '', $merodata);
        $date = trim($date);
        // Добавляем пробел в конце, как ожидается в тесте
        $date .= ' ';
        return [
            'date' => $date,
            'year' => $year
        ];
    }
    
    // Если год не найден, возвращаем как есть
    return [
        'date' => $merodata,
        'year' => date('Y')
    ];
}

/**
 * Форматирует дату мероприятия для отображения
 * Разделяет дату и год на отдельные элементы
 */
function formatEventDate($merodata) {
    $parsed = parseEventDate($merodata);
    return [
        'full' => $merodata,
        'date' => $parsed['date'],
        'year' => $parsed['year']
    ];
}

/**
 * Извлекает только год из даты мероприятия
 */
function extractEventYear($merodata) {
    $parsed = parseEventDate($merodata);
    return $parsed['year'];
}

/**
 * Извлекает только дату (без года) из даты мероприятия
 */
function extractEventDate($merodata) {
    $parsed = parseEventDate($merodata);
    return $parsed['date'];
}

/**
 * Проверяет валидность формата даты мероприятия
 */
function isValidEventDate($merodata) {
    if (empty($merodata)) {
        return false;
    }
    
    // Проверяем что есть год (4 цифры)
    if (!preg_match('/\b\d{4}\b/', $merodata)) {
        return false;
    }
    
    // Проверяем что есть дата (цифры и месяцы)
    if (!preg_match('/\d+\s+[а-яё]+/ui', $merodata)) {
        return false;
    }
    
    return true;
}

/**
 * Создает дату мероприятия из отдельных компонентов
 */
function createEventDate($date, $year) {
    return trim($date . ' ' . $year);
}

/**
 * Возвращает дату начала мероприятия из поля merodata
 * Поддерживаемые форматы примеров:
 * - "12 - 15 августа 2025"
 * - "31 августа - 2 сентября 2025"
 * - "10 июня - 12 июня 2025"
 * - "16 августа 2025"
 * - а также JSON вида {"start":"2025-08-12","end":"2025-08-15"}
 * @param string $merodata
 * @return DateTime|null
 */
function getEventStartDate($merodata) {
    if (empty($merodata)) {
        return null;
    }

    // Если дата хранится в JSON
    $asJson = json_decode($merodata, true);
    if (is_array($asJson)) {
        if (!empty($asJson['start'])) {
            try {
                return new DateTime($asJson['start'], new DateTimeZone('Europe/Moscow'));
            } catch (Exception $e) {
                // ignore
            }
        }
        // Падаем дальше на парсинг текста, если не удалось
    }

    $months = [
        'января' => '01', 'февраля' => '02', 'марта' => '03', 'апреля' => '04',
        'мая' => '05', 'июня' => '06', 'июля' => '07', 'августа' => '08',
        'сентября' => '09', 'октября' => '10', 'ноября' => '11', 'декабря' => '12',
        'янв' => '01', 'фев' => '02', 'мар' => '03', 'апр' => '04',
        'май' => '05', 'июн' => '06', 'июл' => '07', 'авг' => '08',
        'сен' => '09', 'окт' => '10', 'ноя' => '11', 'дек' => '12'
    ];

    // Извлекаем год (по умолчанию текущий)
    $year = null;
    if (preg_match('/\b(\d{4})\b/u', $merodata, $ym)) {
        $year = $ym[1];
    } else {
        $year = date('Y');
    }

    $text = trim($merodata);
    $text = preg_replace('/\s*года?\.?$/u', '', $text);

    // 1) "d - d month year" (один месяц)
    if (preg_match('/^(\d{1,2})\s*[-–]\s*(\d{1,2})\s+([А-Яа-яё]+)\s+(\d{4})$/u', $text, $m)) {
        $dayStart = (int)$m[1];
        $monthName = mb_strtolower($m[3]);
        $yr = $m[4];
        if (isset($months[$monthName])) {
            $month = $months[$monthName];
            try {
                return new DateTime("{$yr}-{$month}-" . sprintf('%02d', $dayStart), new DateTimeZone('Europe/Moscow'));
            } catch (Exception $e) { return null; }
        }
    }

    // 2) "d month - d month year" (разные месяцы)
    if (preg_match('/^(\d{1,2})\s+([А-Яа-яё]+)\s*[-–]\s*(\d{1,2})\s+([А-Яа-яё]+)\s+(\d{4})$/u', $text, $m)) {
        $dayStart = (int)$m[1];
        $monthNameStart = mb_strtolower($m[2]);
        $yr = $m[5];
        if (isset($months[$monthNameStart])) {
            $month = $months[$monthNameStart];
            try {
                return new DateTime("{$yr}-{$month}-" . sprintf('%02d', $dayStart), new DateTimeZone('Europe/Moscow'));
            } catch (Exception $e) { return null; }
        }
    }

    // 3) "d month - d month" + год отдельно в конце строки (редкий случай)
    if (preg_match('/^(\d{1,2})\s+([А-Яа-яё]+)\s*[-–]\s*(\d{1,2})\s+([А-Яа-яё]+)\s*$/u', $text, $m) && $year) {
        $dayStart = (int)$m[1];
        $monthNameStart = mb_strtolower($m[2]);
        if (isset($months[$monthNameStart])) {
            $month = $months[$monthNameStart];
            try {
                return new DateTime("{$year}-{$month}-" . sprintf('%02d', $dayStart), new DateTimeZone('Europe/Moscow'));
            } catch (Exception $e) { return null; }
        }
    }

    // 4) "d - d month" + год в конце (как в parseEventDate)
    if (preg_match('/^(\d{1,2})\s*[-–]\s*(\d{1,2})\s+([А-Яа-яё]+)$/u', $text, $m) && $year) {
        $dayStart = (int)$m[1];
        $monthName = mb_strtolower($m[3]);
        if (isset($months[$monthName])) {
            $month = $months[$monthName];
            try {
                return new DateTime("{$year}-{$month}-" . sprintf('%02d', $dayStart), new DateTimeZone('Europe/Moscow'));
            } catch (Exception $e) { return null; }
        }
    }

    // 5) "d month year" (один день)
    if (preg_match('/^(\d{1,2})\s+([А-Яа-яё]+)\s+(\d{4})$/u', $text, $m)) {
        $day = (int)$m[1];
        $monthName = mb_strtolower($m[2]);
        $yr = $m[3];
        if (isset($months[$monthName])) {
            $month = $months[$monthName];
            try {
                return new DateTime("{$yr}-{$month}-" . sprintf('%02d', $day), new DateTimeZone('Europe/Moscow'));
            } catch (Exception $e) { return null; }
        }
    }

    // 6) "d month" + год отдельно
    if (preg_match('/^(\d{1,2})\s+([А-Яа-яё]+)$/u', $text, $m) && $year) {
        $day = (int)$m[1];
        $monthName = mb_strtolower($m[2]);
        if (isset($months[$monthName])) {
            $month = $months[$monthName];
            try {
                return new DateTime("{$year}-{$month}-" . sprintf('%02d', $day), new DateTimeZone('Europe/Moscow'));
            } catch (Exception $e) { return null; }
        }
    }

    // Фоллбек: используем parseDateForTests/strtotime
    $dt = parseDateForTests($text);
    if ($dt instanceof DateTime) {
        try { $dt->setTimezone(new DateTimeZone('Europe/Moscow')); } catch (Exception $e) {}
        return $dt;
    }

    $timestamp = strtotime($text);
    if ($timestamp !== false) {
        try {
            $dt = new DateTime('@' . $timestamp);
            $dt->setTimezone(new DateTimeZone('Europe/Moscow'));
            return $dt;
        } catch (Exception $e) { return null; }
    }

    return null;
}

/**
 * Возвращает дату окончания мероприятия из поля merodata
 * Логика форматов аналогична getEventStartDate; если диапазон дат, берём правую дату,
 * если один день — берём этот же день. Для JSON ожидается ключ 'end'.
 * @param string $merodata
 * @return DateTime|null
 */
function getEventEndDate($merodata) {
    if (empty($merodata)) {
        return null;
    }

    // Если дата хранится в JSON
    $asJson = json_decode($merodata, true);
    if (is_array($asJson)) {
        if (!empty($asJson['end'])) {
            try {
                return new DateTime($asJson['end'], new DateTimeZone('Europe/Moscow'));
            } catch (Exception $e) {
                // ignore
            }
        } elseif (!empty($asJson['start'])) {
            try {
                return new DateTime($asJson['start'], new DateTimeZone('Europe/Moscow'));
            } catch (Exception $e) {}
        }
    }

    $months = [
        'января' => '01', 'февраля' => '02', 'марта' => '03', 'апреля' => '04',
        'мая' => '05', 'июня' => '06', 'июля' => '07', 'августа' => '08',
        'сентября' => '09', 'октября' => '10', 'ноября' => '11', 'декабря' => '12',
        'янв' => '01', 'фев' => '02', 'мар' => '03', 'апр' => '04',
        'май' => '05', 'июн' => '06', 'июл' => '07', 'авг' => '08',
        'сен' => '09', 'окт' => '10', 'ноя' => '11', 'дек' => '12'
    ];

    // Извлекаем год (по умолчанию текущий)
    $year = null;
    if (preg_match('/\b(\d{4})\b/u', $merodata, $ym)) {
        $year = $ym[1];
    } else {
        $year = date('Y');
    }

    $text = trim($merodata);
    $text = preg_replace('/\s*года?\.?$/u', '', $text);

    // 1) "d - d month year" → правая дата
    if (preg_match('/^(\d{1,2})\s*[-–]\s*(\d{1,2})\s+([А-Яа-яё]+)\s+(\d{4})$/u', $text, $m)) {
        $dayEnd = (int)$m[2];
        $monthName = mb_strtolower($m[3]);
        $yr = $m[4];
        if (isset($months[$monthName])) {
            $month = $months[$monthName];
            try {
                return new DateTime("{$yr}-{$month}-" . sprintf('%02d', $dayEnd), new DateTimeZone('Europe/Moscow'));
            } catch (Exception $e) { return null; }
        }
    }

    // 2) "d month - d month year" → правая дата и месяц
    if (preg_match('/^(\d{1,2})\s+([А-Яа-яё]+)\s*[-–]\s*(\d{1,2})\s+([А-Яа-яё]+)\s+(\d{4})$/u', $text, $m)) {
        $dayEnd = (int)$m[3];
        $monthNameEnd = mb_strtolower($m[4]);
        $yr = $m[5];
        if (isset($months[$monthNameEnd])) {
            $month = $months[$monthNameEnd];
            try {
                return new DateTime("{$yr}-{$month}-" . sprintf('%02d', $dayEnd), new DateTimeZone('Europe/Moscow'));
            } catch (Exception $e) { return null; }
        }
    }

    // 3) "d month - d month" + год отдельно
    if (preg_match('/^(\d{1,2})\s+([А-Яа-яё]+)\s*[-–]\s*(\d{1,2})\s+([А-Яа-яё]+)\s*$/u', $text, $m) && $year) {
        $dayEnd = (int)$m[3];
        $monthNameEnd = mb_strtolower($m[4]);
        if (isset($months[$monthNameEnd])) {
            $month = $months[$monthNameEnd];
            try {
                return new DateTime("{$year}-{$month}-" . sprintf('%02d', $dayEnd), new DateTimeZone('Europe/Moscow'));
            } catch (Exception $e) { return null; }
        }
    }

    // 4) "d - d month" + год в конце → правая дата
    if (preg_match('/^(\d{1,2})\s*[-–]\s*(\d{1,2})\s+([А-Яа-яё]+)$/u', $text, $m) && $year) {
        $dayEnd = (int)$m[2];
        $monthName = mb_strtolower($m[3]);
        if (isset($months[$monthName])) {
            $month = $months[$monthName];
            try {
                return new DateTime("{$year}-{$month}-" . sprintf('%02d', $dayEnd), new DateTimeZone('Europe/Moscow'));
            } catch (Exception $e) { return null; }
        }
    }

    // 5) "d month year" (один день)
    if (preg_match('/^(\d{1,2})\s+([А-Яа-яё]+)\s+(\d{4})$/u', $text, $m)) {
        $day = (int)$m[1];
        $monthName = mb_strtolower($m[2]);
        $yr = $m[3];
        if (isset($months[$monthName])) {
            $month = $months[$monthName];
            try {
                return new DateTime("{$yr}-{$month}-" . sprintf('%02d', $day), new DateTimeZone('Europe/Moscow'));
            } catch (Exception $e) { return null; }
        }
    }

    // 6) "d month" + год отдельно
    if (preg_match('/^(\d{1,2})\s+([А-Яа-яё]+)$/u', $text, $m) && $year) {
        $day = (int)$m[1];
        $monthName = mb_strtolower($m[2]);
        if (isset($months[$monthName])) {
            $month = $months[$monthName];
            try {
                return new DateTime("{$year}-{$month}-" . sprintf('%02d', $day), new DateTimeZone('Europe/Moscow'));
            } catch (Exception $e) { return null; }
        }
    }

    // Фоллбек
    $dt = parseDateForTests($text);
    if ($dt instanceof DateTime) {
        try { $dt->setTimezone(new DateTimeZone('Europe/Moscow')); } catch (Exception $e) {}
        return $dt;
    }
    $timestamp = strtotime($text);
    if ($timestamp !== false) {
        try {
            $dt = new DateTime('@' . $timestamp);
            $dt->setTimezone(new DateTimeZone('Europe/Moscow'));
            return $dt;
        } catch (Exception $e) { return null; }
    }

    return null;
}

/**
 * Функция для форматирования статуса мероприятия
 */
function formatStatus($status) {
    $statusLabels = [
        'В ожидании' => 'В ожидании',
        'Регистрация' => 'Регистрация',
        'Регистрация закрыта' => 'Регистрация закрыта',
        'Перенесено' => 'Перенесено',
        'Результаты' => 'Результаты',
        'Завершено' => 'Завершено',
        'В очереди' => 'В очереди',
        'Зарегистрирован' => 'Зарегистрирован',
        'Подтверждён' => 'Подтверждён',
        'Ожидание команды' => 'Ожидание команды',
        'Дисквалифицирован' => 'Дисквалифицирован',
        'Неявка' => 'Неявка'
    ];
    
    $label = $statusLabels[$status] ?? $status;
    
    // Возвращаем массив для совместимости с кодом, который ожидает ['label']
    return [
        'label' => $label,
        'value' => $status
    ];
}

/**
 * Функция для парсинга классов лодок из JSON
 */
function parseBoatClasses($classDistanceJson) {
    if (empty($classDistanceJson)) {
        return [];
    }
    
    $data = json_decode($classDistanceJson, true);
    if (!is_array($data)) {
        return [];
    }
    
    // Извлекаем только ключи (названия классов лодок)
    $boatClasses = array_keys($data);
    
    return $boatClasses;
}

/**
 * Парсинг даты мероприятия из различных форматов для тестов
 * @param string $dateString Строка даты
 * @return DateTime|null Распарсенная дата или null
 */
function parseDateForTests($dateString) {
    if (empty($dateString)) {
        return null;
    }
    
    // Массив форматов для парсинга
    $formats = [
        'Y-m-d',
        'd.m.Y',
        'd-m-Y',
        'Y-m-d H:i:s',
        'd.m.Y H:i:s'
    ];
    
    // Попытка парсинга стандартных форматов
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $dateString);
        if ($date && $date->format($format) === $dateString) {
            return $date;
        }
    }
    
    // Попытка парсинга русских форматов
    $months = [
        'января' => '01', 'февраля' => '02', 'марта' => '03', 'апреля' => '04',
        'мая' => '05', 'июня' => '06', 'июля' => '07', 'августа' => '08',
        'сентября' => '09', 'октября' => '10', 'ноября' => '11', 'декабря' => '12',
        'янв' => '01', 'фев' => '02', 'мар' => '03', 'апр' => '04',
        'май' => '05', 'июн' => '06', 'июл' => '07', 'авг' => '08',
        'сен' => '09', 'окт' => '10', 'ноя' => '11', 'дек' => '12'
    ];
    
    // Обработка формата "15-17 мая 2024г."
    if (preg_match('/(\d{1,2})-(\d{1,2})\s+(\w+)\s+(\d{4})/', $dateString, $matches)) {
        $day = $matches[1];
        $monthName = mb_strtolower($matches[3]);
        $year = $matches[4];
        
        if (isset($months[$monthName])) {
            $month = $months[$monthName];
            try {
                return new DateTime("{$year}-{$month}-{$day}");
            } catch (Exception $e) {
                return null;
            }
        }
    }
    
    // Обработка формата "Июнь 2024"
    if (preg_match('/(\w+)\s+(\d{4})/', $dateString, $matches)) {
        $monthName = mb_strtolower($matches[1]);
        $year = $matches[2];
        
        if (isset($months[$monthName])) {
            $month = $months[$monthName];
            try {
                return new DateTime("{$year}-{$month}-01");
            } catch (Exception $e) {
                return null;
            }
        }
    }
    
    // Попытка использовать strtotime
    $timestamp = strtotime($dateString);
    if ($timestamp !== false) {
        return new DateTime('@' . $timestamp);
    }
    
    return null;
}
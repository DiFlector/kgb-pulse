<?php
/**
 * Класс для управления аутентификацией и сессиями
 * Обеспечивает безопасную авторизацию пользователей
 */

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db/Database.php';

class Auth {
    private $db;
    private $sessionName = 'PULSE_SESSID';
    private $sessionLifetime = 3600; // 1 час
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->initSession();
    }
    
    /**
     * Инициализация безопасной сессии
     */
    private function initSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            // Настройки безопасности сессии (только если сессия еще не запущена)
            if (!headers_sent()) {
                ini_set('session.cookie_httponly', 1);
                ini_set('session.cookie_secure', 0); // Отключаем для HTTP
                ini_set('session.use_strict_mode', 1);
                ini_set('session.cookie_samesite', 'Lax');
                ini_set('session.gc_maxlifetime', $this->sessionLifetime);
                
                session_name($this->sessionName);
                @session_start();
            } else {
                // Если заголовки уже отправлены, просто запускаем сессию без настроек
                if (!session_id()) {
                    @session_start();
                }
            }
        }
        
        // Проверяем и обновляем время жизни сессии
        $this->checkSessionLifetime();
        
        // Регенерируем ID сессии для безопасности (только если сессия активна)
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!isset($_SESSION['regenerated']) || $_SESSION['regenerated'] < time() - 300) {
                session_regenerate_id(true);
                $_SESSION['regenerated'] = time();
            }
            
            // Устанавливаем время последней активности
            $_SESSION['last_activity'] = time();
        } else {
            // Если сессия не активна, устанавливаем значения по умолчанию для тестов
            $_SESSION['regenerated'] = time();
            $_SESSION['last_activity'] = time();
        }
    }
    
    /**
     * Проверка времени жизни сессии
     */
    private function checkSessionLifetime(): void {
        if (isset($_SESSION['last_activity'])) {
            if (time() - $_SESSION['last_activity'] > $this->sessionLifetime) {
                $this->logout();
                return;
            }
        }
        $_SESSION['last_activity'] = time();
    }
    
    /**
     * Аутентификация пользователя
     */
    public function login(string $email, string $password): array {
        $result = ['success' => false, 'message' => '', 'user' => null];
        
        try {
            // Проверяем блокировку по IP
            if ($this->isIpBlocked()) {
                $result['message'] = 'Слишком много неудачных попыток входа. Попробуйте позже.';
                return $result;
            }
            
            // Валидация входных данных
            if (!validateEmail($email)) {
                $result['message'] = 'Неверный формат email';
                $this->recordFailedAttempt();
                return $result;
            }
            
            // Поиск пользователя
            $user = $this->db->fetchOne(
                "SELECT * FROM users WHERE email = ?",
                [$email]
            );
            
            if (!$user || !verifyPassword($password, $user['password'])) {
                $result['message'] = 'Неверный email или пароль';
                $this->recordFailedAttempt();
                return $result;
            }
            
            // Успешная аутентификация
            $this->createUserSession($user);
            $this->clearFailedAttempts();
            
            // Логируем успешный вход
            $this->logLoginAttempt($user['userid'], true);
            $this->logUserAction($user['userid'], 'login');
            
            $result['success'] = true;
            $result['message'] = 'Добро пожаловать!';
            $result['user'] = $this->sanitizeUserData($user);
            
        } catch (Exception $e) {
            error_log("Ошибка аутентификации: " . $e->getMessage());
            $result['message'] = 'Ошибка системы. Попробуйте позже.';
        }
        
        return $result;
    }
    
    /**
     * Создание пользовательской сессии
     */
    private function createUserSession(array $user): void {
        $_SESSION['user_id'] = $user['userid'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['accessrights'];
        $_SESSION['user_fio'] = $user['fio'];
        $_SESSION['authenticated'] = true;
        $_SESSION['login_time'] = time();
        $_SESSION['ip_address'] = $this->getRealIpAddress();
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
    
    /**
     * Выход пользователя
     */
    public function logout(): void {
        if ($this->isAuthenticated()) {
            $this->logUserAction($_SESSION['user_id'], 'logout');
        }
        
        // Очищаем все данные сессии
        $_SESSION = [];
        
        // Удаляем cookie сессии
        if (isset($_COOKIE[$this->sessionName])) {
            setcookie($this->sessionName, '', time() - 3600, '/', '', true, true);
        }
        
        // Уничтожаем сессию только если она активна
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
    
    /**
     * Проверка аутентификации
     */
    public function isAuthenticated(): bool {
        // Предотвращаем бесконечную рекурсию
        static $authCheckCount = 0;
        $authCheckCount++;
        
        if ($authCheckCount > 5) {
            error_log("WARNING: Too many authentication checks, possible recursion detected");
            return false;
        }
        
        if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
            return false;
        }
        
        // Дополнительные проверки безопасности
        if (!$this->validateSession()) {
            $this->logout();
            return false;
        }
        
        return true;
    }
    
    /**
     * Валидация сессии
     */
    private function validateSession(): bool {
        // Предотвращаем бесконечную рекурсию
        static $validationCount = 0;
        $validationCount++;
        
        if ($validationCount > 10) {
            error_log("WARNING: Too many session validations, possible recursion detected");
            return false;
        }
        
        // Проверяем IP адрес (если включена привязка к IP)
        if (isset($_SESSION['ip_address']) && $_SESSION['ip_address'] !== $this->getRealIpAddress()) {
            return false;
        }
        
        // Проверяем User-Agent (базовая проверка)
        if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
            return false;
        }
        
        // Проверяем время жизни сессии
        if (isset($_SESSION['login_time']) && time() - $_SESSION['login_time'] > $this->sessionLifetime * 24) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Получение данных текущего пользователя
     */
    public function getCurrentUser(): ?array {
        // Предотвращаем бесконечную рекурсию
        static $getUserCount = 0;
        $getUserCount++;
        
        if ($getUserCount > 3) {
            error_log("WARNING: Too many getCurrentUser calls, possible recursion detected");
            return null;
        }
        
        if (!$this->isAuthenticated()) {
            return null;
        }
        
        try {
            // Проверяем, есть ли user_id в сессии
            if (!isset($_SESSION['user_id'])) {
                return null;
            }
            
            $user = $this->db->fetchOne(
                "SELECT * FROM users WHERE userid = ?",
                [$_SESSION['user_id']]
            );
            
            if (!$user) {
                return null;
            }
            
            return $this->sanitizeUserData($user);
        } catch (Exception $e) {
            error_log("Ошибка получения данных пользователя: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Проверка суперпользователя
     */
    public function isSuperUser(): bool {
        if (!$this->isAuthenticated()) {
            return false;
        }
        
        return $_SESSION['user_role'] === 'SuperUser' || $_SESSION['user_id'] === 999;
    }
    
    /**
     * Проверка роли пользователя
     */
    public function hasRole(string $role): bool {
        if (!$this->isAuthenticated()) {
            return false;
        }
        
        // Суперпользователь имеет все роли
        if ($this->isSuperUser()) {
            return true;
        }
        
        return checkUserRole($role, $_SESSION['user_role']);
    }
    
    /**
     * Проверка одной из множественных ролей
     */
    public function hasAnyRole(array $roles): bool {
        if (!$this->isAuthenticated()) {
            return false;
        }
        
        // Суперпользователь имеет все роли
        if ($this->isSuperUser()) {
            return true;
        }
        
        foreach ($roles as $role) {
            if (checkUserRole($role, $_SESSION['user_role'])) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Проверка конкретной роли (точное совпадение)
     */
    public function isRole(string $role): bool {
        if (!$this->isAuthenticated()) {
            return false;
        }
        
        return $_SESSION['user_role'] === $role;
    }
    
    /**
     * Получение роли пользователя
     */
    public function getUserRole(): ?string {
        return $this->isAuthenticated() ? $_SESSION['user_role'] : null;
    }
    
    /**
     * Получение ID пользователя
     */
    public function getUserId(): ?int {
        return $this->isAuthenticated() ? $_SESSION['user_id'] : null;
    }
    
    /**
     * Проверка роли пользователя с возвратом данных пользователя
     * @param array $roles Массив разрешенных ролей
     * @return array|false Данные пользователя или false
     */
    public function checkRole(array $roles) {
        if (!$this->isAuthenticated()) {
            return false;
        }
        
        // SuperUser имеет доступ ко всем ролям
        if ($this->isSuperUser()) {
            return $this->getCurrentUser();
        }
        
        $userRole = $_SESSION['user_role'];
        
        // Проверяем, есть ли роль пользователя в разрешенных ролях
        if (in_array($userRole, $roles)) {
            return $this->getCurrentUser();
        }
        
        return false;
    }
    
    /**
     * Очистка пользовательских данных для передачи
     */
    private function sanitizeUserData(array $user): array {
        unset($user['password']); // Удаляем пароль
        // Добавляем поле user_id для совместимости
        $user['user_id'] = $user['userid'];
        // Добавляем поле user_role для совместимости
        $user['user_role'] = $user['accessrights'];
        return $user;
    }
    
    /**
     * Запись неудачной попытки входа
     */
    private function recordFailedAttempt(): void {
        $ip = $this->getRealIpAddress();
        $attempts = $_SESSION['failed_attempts'][$ip] ?? 0;
        $_SESSION['failed_attempts'][$ip] = $attempts + 1;
        $_SESSION['last_attempt_time'][$ip] = time();
    }
    
    /**
     * Очистка неудачных попыток
     */
    private function clearFailedAttempts(): void {
        $ip = $this->getRealIpAddress();
        unset($_SESSION['failed_attempts'][$ip]);
        unset($_SESSION['last_attempt_time'][$ip]);
    }
    
    /**
     * Проверка блокировки IP
     */
    private function isIpBlocked(): bool {
        $ip = $this->getRealIpAddress();
        $maxAttempts = 5;
        $blockTime = 900; // 15 минут
        
        if (!isset($_SESSION['failed_attempts'][$ip])) {
            return false;
        }
        
        $attempts = $_SESSION['failed_attempts'][$ip];
        $lastAttempt = $_SESSION['last_attempt_time'][$ip] ?? 0;
        
        if ($attempts >= $maxAttempts && time() - $lastAttempt < $blockTime) {
            return true;
        }
        
        // Сбрасываем счетчик если прошло достаточно времени
        if (time() - $lastAttempt >= $blockTime) {
            $this->clearFailedAttempts();
        }
        
        return false;
    }
    
    /**
     * Регистрация нового пользователя
     */
    public function register(array $userData): array {
        $result = ['success' => false, 'message' => '', 'user_id' => null];
        
        try {
            // Валидация данных
            $validation = $this->validateRegistrationData($userData);
            if (!$validation['valid']) {
                $result['message'] = $validation['message'];
                error_log("Validation failed: " . $validation['message']);
                return $result;
            }
            
            // Проверяем уникальность email и телефона
            if ($this->emailExists($userData['email'])) {
                $result['message'] = 'Пользователь с таким email уже существует';
                return $result;
            }
            
            if ($this->phoneExists($userData['telephone'])) {
                $result['message'] = 'Пользователь с таким телефоном уже существует';
                return $result;
            }
            
            // Получаем следующий userid для спортсмена
            $userid = getNextUserIdForRole('Sportsman', $this->db->getPDO());
            
            // Подготавливаем данные для вставки
            $insertData = [
                'userid' => $userid,
                'email' => $userData['email'],
                'password' => hashPassword($userData['password']),
                'fio' => sanitizeInput($userData['fio']),
                'sex' => $userData['sex'],
                'telephone' => normalizePhone($userData['telephone']),
                'birthdata' => $userData['birthdata'],
                'country' => sanitizeInput($userData['country']),
                'city' => sanitizeInput($userData['city']),
                'accessrights' => 'Sportsman',
                'boats' => isset($userData['boats']) ? (is_array($userData['boats']) ? '{' . implode(',', $userData['boats']) . '}' : $userData['boats']) : null,
                'sportzvanie' => $userData['sportzvanie'] ?? null
            ];
            
            // Вставляем пользователя
            $oid = $this->db->insert('users', $insertData);
            
            if ($oid) {
                logUserAction('register', $userid, 'New user registered');
                $result['success'] = true;
                $result['message'] = 'Регистрация прошла успешно';
                $result['user'] = [
                    'oid' => $oid,
                    'userid' => $userid,
                    'email' => $userData['email'],
                    'fio' => $userData['fio'],
                    'accessrights' => 'Sportsman'
                ];
                
                // Создаем уведомление о регистрации (не критично для успешной регистрации)
                try {
                    require_once __DIR__ . '/Notification.php';
                    $notification = new Notification();
                    $notification->createNotification(
                        $oid, // Передаем oid вместо userid
                        'registration',
                        'Добро пожаловать!',
                        'Спасибо за регистрацию на сайте Купавинской гребной базы. Теперь вы можете участвовать в соревнованиях и следить за своими результатами.'
                    );
                } catch (Exception $e) {
                    error_log("Ошибка создания уведомления при регистрации: " . $e->getMessage());
                    // Не прерываем регистрацию из-за ошибки уведомления
                }
            } else {
                $result['message'] = 'Ошибка при регистрации';
            }
            
        } catch (Exception $e) {
            error_log("Ошибка регистрации: " . $e->getMessage());
            $result['message'] = 'Ошибка системы. Попробуйте позже.';
        }
        
        return $result;
    }
    
    /**
     * Валидация данных регистрации
     */
    private function validateRegistrationData(array $data): array {
        $required = ['email', 'password', 'fio', 'sex', 'telephone', 'birthdata', 'country', 'city'];
        
        // Проверяем обязательные поля
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return ['valid' => false, 'message' => "Поле '{$field}' обязательно для заполнения"];
            }
        }
        
        // Валидация email
        if (!validateEmail($data['email'])) {
            return ['valid' => false, 'message' => 'Неверный формат email'];
        }
        
        // Валидация телефона
        if (!validatePhone($data['telephone'])) {
            return ['valid' => false, 'message' => 'Неверный формат телефона'];
        }
        
        // Валидация пола
        if (!in_array($data['sex'], ['М', 'Ж'])) {
            return ['valid' => false, 'message' => 'Неверное значение пола'];
        }
        
        // Валидация даты рождения
        $birthDate = DateTime::createFromFormat('Y-m-d', $data['birthdata']);
        if (!$birthDate || $birthDate->format('Y-m-d') !== $data['birthdata']) {
            return ['valid' => false, 'message' => 'Неверный формат даты рождения'];
        }
        
        // Проверяем возраст (должен быть не менее 10 лет)
        $age = $birthDate->diff(new DateTime())->y;
        if ($age < 10) {
            return ['valid' => false, 'message' => 'Возраст должен быть не менее 10 лет'];
        }
        
        // Валидация пароля
        if (strlen($data['password']) < 8) {
            return ['valid' => false, 'message' => 'Пароль должен содержать не менее 8 символов'];
        }
        
        return ['valid' => true, 'message' => ''];
    }
    
    /**
     * Проверка существования email
     */
    private function emailExists(string $email): bool {
        $count = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE email = ?",
            [$email]
        );
        return $count > 0;
    }
    
    /**
     * Проверка существования телефона
     */
    private function phoneExists(string $phone): bool {
        $normalizedPhone = normalizePhone($phone);
        $count = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE telephone = ?",
            [$normalizedPhone]
        );
        return $count > 0;
    }
    
    /**
     * Получение статистики авторизации для админа
     */
    public function getAuthStats(): array {
        try {
            // Получаем статистику из базы данных
            $totalAttempts = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM login_attempts WHERE attempt_time > NOW() - INTERVAL '24 hours'"
            ) ?? 0;
            
            $successfulAttempts = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM login_attempts WHERE success = true AND attempt_time > NOW() - INTERVAL '24 hours'"
            ) ?? 0;
            
            $failedAttempts = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM login_attempts WHERE success = false AND attempt_time > NOW() - INTERVAL '24 hours'"
            ) ?? 0;
            
            $stats = [
                'total_attempts' => (int)$totalAttempts,
                'successful_attempts' => (int)$successfulAttempts,
                'failed_attempts' => (int)$failedAttempts,
                'online_users' => 0, // Пока не реализовано
                'total_sessions' => 0 // Пока не реализовано
            ];
            
            return $stats;
        } catch (Exception $e) {
            // В случае ошибки возвращаем базовую статистику
            error_log("Error getting auth stats: " . $e->getMessage());
            return [
                'total_attempts' => 0,
                'successful_attempts' => 0,
                'failed_attempts' => 0,
                'online_users' => 0,
                'total_sessions' => 0
            ];
        }
    }

    private function logLoginAttempt($userid, $success) {
        try {
            $ip = $this->getRealIpAddress();
            
            // Получаем oid пользователя по userid
            $userOid = $this->db->fetchColumn(
                "SELECT oid FROM users WHERE userid = ?",
                [$userid]
            );
            
            if (!$userOid) {
                error_log("Warning: Attempted to log login attempt for non-existent user ID: {$userid}");
                return;
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO login_attempts (users_oid, ip, success)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$userOid, $ip, $success]);

            // Если слишком много неудачных попыток, блокируем IP
            if (!$success) {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as attempts
                    FROM login_attempts
                    WHERE ip = ?
                    AND success = false
                    AND attempt_time > NOW() - INTERVAL '1 hour'
                ");
                $stmt->execute([$ip]);
                $result = $stmt->fetch();

                if ($result['attempts'] >= 10) {
                    // Записываем событие в системный лог
                    $stmt = $this->db->prepare("
                        INSERT INTO system_events (event_type, description, severity)
                        VALUES ('security', ?, 'high')
                    ");
                    $stmt->execute(['IP address blocked due to multiple failed login attempts: ' . $ip]);

                    throw new Exception('Слишком много неудачных попыток входа. Попробуйте позже.');
                }
            }
        } catch (Exception $e) {
            // Логируем ошибку, но не прерываем выполнение если это не критическая ошибка
            error_log("Error logging login attempt: " . $e->getMessage());
            
            // Если это ошибка блокировки, то перебрасываем её
            if (strpos($e->getMessage(), 'Слишком много неудачных попыток') !== false) {
                throw $e;
            }
        }
    }

    private function logUserAction($userid, $action) {
        try {
            // Получаем oid пользователя по userid
            $userOid = $this->db->fetchColumn(
                "SELECT oid FROM users WHERE userid = ?",
                [$userid]
            );
            
            // Если пользователь не существует, не записываем действие
            if (!$userOid) {
                error_log("Warning: Attempted to log action for non-existent user ID: {$userid}");
                return;
            }
            
            $ip = $this->getRealIpAddress();
            $stmt = $this->db->prepare("
                INSERT INTO user_actions (users_oid, action, ip_address)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$userOid, $action, $ip]);
        } catch (Exception $e) {
            // Логируем ошибку, но не прерываем выполнение
            error_log("Error logging user action: " . $e->getMessage());
        }
    }
    
    /**
     * Получение IP-адреса пользователя с учётом прокси и Cloudflare
     */
    private function getRealIpAddress(): string {
        $ipaddress = 'UNKNOWN';
        
        if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
            $ipaddress = $_SERVER["HTTP_CF_CONNECTING_IP"];
        } else if (isset($_SERVER['HTTP_X_REAL_IP'])) {
            $ipaddress = $_SERVER['HTTP_X_REAL_IP'];
        } else if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
        } else if(isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
            if (strpos($ipaddress, ',') !== false) {
                $ipaddress = explode(',', $ipaddress)[0];
            }
        } else if(isset($_SERVER['REMOTE_ADDR'])) {
            $ipaddress = $_SERVER['REMOTE_ADDR'];
        }
        
        // Если IP все еще UNKNOWN, используем fallback
        if ($ipaddress === 'UNKNOWN') {
            $ipaddress = '127.0.0.1';
        }
        
        // Убираем пробелы
        $ipaddress = trim($ipaddress);
        
        return $ipaddress;
    }
} 
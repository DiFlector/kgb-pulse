<?php
/**
 * Класс для работы с базой данных PostgreSQL
 * Обеспечивает безопасное подключение и выполнение запросов
 */

class Database {
    private static $instance = null;
    private $pdo;
    private $host;
    private $dbname;
    private $username;
    private $password;
    
    private function __construct() {
        $this->host = $_ENV['DB_HOST'] ?? 'postgres';
        $this->dbname = $_ENV['DB_NAME'] ?? 'pulse_rowing_db';
        $this->username = $_ENV['DB_USER'] ?? 'pulse_user';
        $this->password = $_ENV['DB_PASSWORD'] ?? 'pulse_password';
        
        $this->connect();
    }
    
    /**
     * Получение единственного экземпляра подключения (Singleton)
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Подключение к базе данных
     */
    private function connect(): void {
        try {
            $dsn = "pgsql:host={$this->host};dbname={$this->dbname}";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_TIMEOUT => 30,
            ];
            
            $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
            
            // Устанавливаем кодировку и временную зону
            $this->pdo->exec("SET client_encoding TO 'UTF8'");
            $this->pdo->exec("SET timezone = 'Europe/Moscow'");
            
        } catch (PDOException $e) {
            error_log("Ошибка подключения к базе данных: " . $e->getMessage());
            throw new Exception("Ошибка подключения к базе данных");
        }
    }
    
    /**
     * Получение объекта PDO
     */
    public function getPDO(): PDO {
        return $this->pdo;
    }
    
    /**
     * Выполнение простого запроса без параметров
     */
    public function query(string $query): PDOStatement {
        try {
            return $this->pdo->query($query);
        } catch (PDOException $e) {
            error_log("Ошибка выполнения запроса: " . $e->getMessage() . " | Query: " . $query);
            throw new Exception("Ошибка выполнения запроса к базе данных");
        }
    }
    
    /**
     * Подготовка запроса
     */
    public function prepare(string $query): PDOStatement {
        try {
            return $this->pdo->prepare($query);
        } catch (PDOException $e) {
            error_log("Ошибка подготовки запроса: " . $e->getMessage() . " | Query: " . $query);
            throw new Exception("Ошибка подготовки запроса к базе данных");
        }
    }
    
    /**
     * Выполнение подготовленного запроса
     */
    public function execute(string $query, array $params = []): PDOStatement {
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            $errorCode = $e->getCode();
            $errorMessage = $e->getMessage();
            
            // Специальная обработка ошибок транзакций PostgreSQL
            if (strpos($errorMessage, 'SQLSTATE[25P02]') !== false) {
                error_log("Ошибка транзакции PostgreSQL: " . $errorMessage . " | Query: " . $query);
                throw new Exception("Ошибка транзакции базы данных: транзакция была прервана");
            }
            
            // Логируем все ошибки с подробностями
            error_log("Ошибка выполнения запроса: " . $errorMessage . " | Query: " . $query . " | Params: " . json_encode($params));
            throw new Exception("Ошибка выполнения запроса к базе данных: " . $errorMessage);
        }
    }
    
    /**
     * Получение одной записи
     */
    public function fetchOne(string $query, array $params = []): ?array {
        $stmt = $this->execute($query, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }
    
    /**
     * Получение всех записей
     */
    public function fetchAll(string $query, array $params = []): array {
        $stmt = $this->execute($query, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Получение одного значения
     */
    public function fetchColumn(string $query, array $params = []): mixed {
        $stmt = $this->execute($query, $params);
        return $stmt->fetchColumn();
    }
    
    /**
     * Вставка записи и получение ID
     */
    public function insert(string $table, array $data): int {
        // Удаляем oid из данных, если он указан, чтобы PostgreSQL сам его сгенерировал
        $insertData = $data;
        if (isset($insertData['oid'])) {
            unset($insertData['oid']);
        }
        
        // Обрабатываем boolean значения для PostgreSQL
        foreach ($insertData as $key => $value) {
            if (is_bool($value)) {
                $insertData[$key] = $value ? 'true' : 'false';
            }
        }
        
        $columns = implode(', ', array_keys($insertData));
        $placeholders = ':' . implode(', :', array_keys($insertData));
        // Если таблица временная (test_), возвращаем id, иначе oid
        $returning = (strncmp($table, 'test_', 5) === 0) ? 'id' : 'oid';
        $query = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders}) RETURNING {$returning}";
        $stmt = $this->execute($query, $insertData);
        return $stmt->fetchColumn();
    }
    
    /**
     * Обновление записи
     */
    public function update(string $table, array $data, array $where): bool {
        $setClause = [];
        foreach (array_keys($data) as $column) {
            $setClause[] = "{$column} = :{$column}";
        }
        $setClause = implode(', ', $setClause);
        
        $whereClause = [];
        foreach (array_keys($where) as $column) {
            $whereClause[] = "{$column} = :where_{$column}";
        }
        $whereClause = implode(' AND ', $whereClause);
        
        $query = "UPDATE {$table} SET {$setClause} WHERE {$whereClause}";
        
        // Объединяем параметры
        $params = $data;
        foreach ($where as $key => $value) {
            $params["where_{$key}"] = $value;
        }
        
        $stmt = $this->execute($query, $params);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Удаление записи
     */
    public function delete(string $table, array $where): bool {
        $whereClause = [];
        foreach (array_keys($where) as $column) {
            $whereClause[] = "{$column} = :{$column}";
        }
        $whereClause = implode(' AND ', $whereClause);
        
        $query = "DELETE FROM {$table} WHERE {$whereClause}";
        $stmt = $this->execute($query, $where);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Начало транзакции
     */
    public function beginTransaction(): bool {
        try {
            return $this->pdo->beginTransaction();
        } catch (PDOException $e) {
            error_log("Ошибка начала транзакции: " . $e->getMessage());
            throw new Exception("Ошибка начала транзакции");
        }
    }
    
    /**
     * Подтверждение транзакции
     */
    public function commit(): bool {
        try {
            return $this->pdo->commit();
        } catch (PDOException $e) {
            error_log("Ошибка подтверждения транзакции: " . $e->getMessage());
            throw new Exception("Ошибка подтверждения транзакции");
        }
    }
    
    /**
     * Откат транзакции
     */
    public function rollback(): bool {
        try {
            return $this->pdo->rollback();
        } catch (PDOException $e) {
            error_log("Ошибка отката транзакции: " . $e->getMessage());
            throw new Exception("Ошибка отката транзакции");
        }
    }
    
    /**
     * Проверка, находимся ли в транзакции
     */
    public function inTransaction(): bool {
        return $this->pdo->inTransaction();
    }
    
    /**
     * Проверка состояния транзакции (не прервана ли)
     */
    public function isTransactionActive(): bool {
        if (!$this->inTransaction()) {
            return false;
        }
        
        try {
            // Пробуем выполнить простой запрос для проверки состояния транзакции
            $this->pdo->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            // Если транзакция прервана, PostgreSQL вернет ошибку
            if (strpos($e->getMessage(), 'SQLSTATE[25P02]') !== false) {
                return false;
            }
            throw $e;
        }
    }
    
    /**
     * Проверка существования таблицы
     */
    public function tableExists(string $tableName): bool {
        $query = "SELECT EXISTS (
            SELECT FROM information_schema.tables 
            WHERE table_schema = 'public' 
            AND table_name = ?
        )";
        
        return $this->fetchColumn($query, [$tableName]);
    }
    
    /**
     * Получение последнего ID
     */
    public function lastInsertId(string $sequence = null): string {
        return $this->pdo->lastInsertId($sequence);
    }
    
    /**
     * Экранирование строки для поиска LIKE
     */
    public function escapeLike(string $string): string {
        return str_replace(['%', '_'], ['\%', '\_'], $string);
    }
    
    /**
     * Проверка подключения к базе данных
     */
    public function isConnected(): bool {
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Получение информации о базе данных
     */
    public function getDatabaseInfo(): array {
        $info = [];
        
        try {
            // Версия PostgreSQL
            $info['version'] = $this->fetchColumn('SELECT version()');
            
            // Размер базы данных
            $info['size'] = $this->fetchColumn("SELECT pg_size_pretty(pg_database_size(current_database()))");
            
            // Количество подключений
            $info['connections'] = $this->fetchColumn('SELECT count(*) FROM pg_stat_activity');
            
            // Время работы сервера
            $info['uptime'] = $this->fetchColumn('SELECT current_timestamp - pg_postmaster_start_time()');
            
        } catch (Exception $e) {
            error_log("Ошибка получения информации о БД: " . $e->getMessage());
        }
        
        return $info;
    }
    
    /**
     * Запрет клонирования
     */
    private function __clone() {}
    
    /**
     * Запрет десериализации
     */
    public function __wakeup() {
        throw new Exception("Десериализация singleton запрещена");
    }
} 
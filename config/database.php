<?php
/**
 * Database Configuration and Connection
 * 
 * This file handles the database connection for the Balkans Tourism website.
 * It uses environment variables for database credentials with fallback values.
 */

class Database {
    private $host;
    private $database;
    private $username;
    private $password;
    private $charset;
    private $pdo;
    
    public function __construct() {
        // Get database configuration from environment variables or use defaults
        $this->host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost';
        $this->database = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'balkans_tourism';
        $this->username = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root';
        $this->password = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '';
        $this->charset = 'utf8mb4';
    }
    
    /**
     * Get PDO database connection
     * 
     * @return PDO
     * @throws PDOException
     */
    public function getConnection() {
        if ($this->pdo === null) {
            try {
                $dsn = "mysql:host={$this->host};dbname={$this->database};charset={$this->charset}";
                
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->charset} COLLATE utf8mb4_unicode_ci"
                ];
                
                $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
                
                // Set timezone
                $this->pdo->exec("SET time_zone = '+00:00'");
                
            } catch (PDOException $e) {
                // Log the error (in production, don't expose database details)
                error_log("Database connection failed: " . $e->getMessage());
                
                // Throw a generic error in production
                if (getenv('APP_ENV') === 'production') {
                    throw new PDOException("Database connection failed");
                } else {
                    throw $e;
                }
            }
        }
        
        return $this->pdo;
    }
    
    /**
     * Close database connection
     */
    public function closeConnection() {
        $this->pdo = null;
    }
    
    /**
     * Check if database connection is alive
     * 
     * @return bool
     */
    public function isConnected() {
        try {
            if ($this->pdo === null) {
                return false;
            }
            
            $this->pdo->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Get database info for debugging
     * 
     * @return array
     */
    public function getDatabaseInfo() {
        if (getenv('APP_ENV') === 'production') {
            return ['status' => 'connected'];
        }
        
        try {
            $pdo = $this->getConnection();
            $stmt = $pdo->query('SELECT VERSION() as version');
            $result = $stmt->fetch();
            
            return [
                'status' => 'connected',
                'host' => $this->host,
                'database' => $this->database,
                'version' => $result['version'] ?? 'unknown',
                'charset' => $this->charset
            ];
        } catch (PDOException $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Initialize database tables if they don't exist
     * 
     * @return bool
     */
    public function initializeTables() {
        try {
            $pdo = $this->getConnection();
            
            // Read and execute schema file
            $schemaFile = __DIR__ . '/../database/schema.sql';
            
            if (!file_exists($schemaFile)) {
                throw new Exception("Schema file not found: {$schemaFile}");
            }
            
            $schema = file_get_contents($schemaFile);
            
            if ($schema === false) {
                throw new Exception("Could not read schema file");
            }
            
            // Split SQL statements and execute them
            $statements = array_filter(
                array_map('trim', explode(';', $schema)),
                'strlen'
            );
            
            $pdo->beginTransaction();
            
            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    $pdo->exec($statement);
                }
            }
            
            $pdo->commit();
            return true;
            
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            error_log("Failed to initialize database tables: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Execute a prepared statement safely
     * 
     * @param string $sql
     * @param array $params
     * @return PDOStatement
     * @throws PDOException
     */
    public function query($sql, $params = []) {
        try {
            $pdo = $this->getConnection();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Database query failed: " . $e->getMessage() . " SQL: " . $sql);
            throw $e;
        }
    }
    
    /**
     * Get the last inserted ID
     * 
     * @return string
     */
    public function lastInsertId() {
        return $this->getConnection()->lastInsertId();
    }
    
    /**
     * Start a database transaction
     * 
     * @return bool
     */
    public function beginTransaction() {
        return $this->getConnection()->beginTransaction();
    }
    
    /**
     * Commit a database transaction
     * 
     * @return bool
     */
    public function commit() {
        return $this->getConnection()->commit();
    }
    
    /**
     * Rollback a database transaction
     * 
     * @return bool
     */
    public function rollback() {
        return $this->getConnection()->rollBack();
    }
}

/**
 * Global function to get database instance
 * 
 * @return Database
 */
function getDatabase() {
    static $database = null;
    
    if ($database === null) {
        $database = new Database();
    }
    
    return $database;
}

/**
 * Helper function to execute queries with error handling
 * 
 * @param string $sql
 * @param array $params
 * @return array|false
 */
function dbQuery($sql, $params = []) {
    try {
        $db = getDatabase();
        $stmt = $db->query($sql, $params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Database query helper failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Helper function to execute single row queries
 * 
 * @param string $sql
 * @param array $params
 * @return array|false
 */
function dbQuerySingle($sql, $params = []) {
    try {
        $db = getDatabase();
        $stmt = $db->query($sql, $params);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Database single query helper failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Helper function for INSERT, UPDATE, DELETE operations
 * 
 * @param string $sql
 * @param array $params
 * @return bool|string Returns true for success, last insert ID for INSERT, false for failure
 */
function dbExecute($sql, $params = []) {
    try {
        $db = getDatabase();
        $stmt = $db->query($sql, $params);
        
        // For INSERT statements, return the last insert ID
        if (stripos(trim($sql), 'INSERT') === 0) {
            return $db->lastInsertId();
        }
        
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Database execute helper failed: " . $e->getMessage());
        return false;
    }
}

// Initialize database tables on first include
try {
    $db = getDatabase();
    if ($db->isConnected()) {
        $db->initializeTables();
    }
} catch (Exception $e) {
    error_log("Failed to initialize database on startup: " . $e->getMessage());
}
?>

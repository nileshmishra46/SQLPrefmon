<?php
// includes/db.php

require_once dirname(__DIR__) . '/config/app.php';

class PrefmonPDO extends PDO {
    private $dbType = 'sqlite';
    
    public function __construct($dsn, $user = null, $pass = null, $options = null, $dbType = 'sqlite') {
        parent::__construct($dsn, $user, $pass, $options);
        $this->dbType = $dbType;
    }
    
    public function getDbType() {
        return $this->dbType;
    }
    
    private function rewriteSql($sql) {
        if ($this->dbType === 'sqlite') {
            return $sql;
        }
        
        // Rewrite sqlite LIMIT syntax to MSSQL OFFSET/FETCH
        if (preg_match('/LIMIT\s+(\d+|:\w+)/i', $sql, $matches)) {
            $limit = $matches[1];
            $sql = preg_replace('/LIMIT\s+(\d+|:\w+)/i', '', $sql);
            if (stripos($sql, 'ORDER BY') === false) {
                $sql .= " ORDER BY (SELECT NULL)";
            }
            $sql .= " OFFSET 0 ROWS FETCH NEXT {$limit} ROWS ONLY";
        }
        return $sql;
    }
    
    #[\ReturnTypeWillChange]
    public function prepare($query, $options = []) {
        $rewritten = $this->rewriteSql($query);
        return parent::prepare($rewritten, $options);
    }
    
    #[\ReturnTypeWillChange]
    public function query($query, ...$args) {
        $rewritten = $this->rewriteSql($query);
        return parent::query($rewritten, ...$args);
    }
    
    #[\ReturnTypeWillChange]
    public function exec($statement) {
        $rewritten = $this->rewriteSql($statement);
        return parent::exec($rewritten);
    }
}

function getDbConnection() {
    static $db = null;
    
    if ($db === null) {
        $repoType = getAppSetting('repo_db_type', 'sqlite');
        
        if ($repoType === 'mssql') {
            $host = getAppSetting('repo_mssql_host', 'localhost');
            $port = getAppSetting('repo_mssql_port', '1433');
            $dbName = getAppSetting('repo_mssql_db', 'PrefmonRepo');
            $user = getAppSetting('repo_mssql_user', 'sa');
            $pass = getAppSetting('repo_mssql_pass', '');
            
            // Connect to master database first to check database existence
            try {
                $masterDsn = "odbc:Driver={ODBC Driver 18 for SQL Server};Server={$host},{$port};Database=master;Encrypt=yes;TrustServerCertificate=yes;ConnectionTimeout=3;";
                $masterDb = new PDO($masterDsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);
                $check = $masterDb->prepare("SELECT database_id FROM sys.databases WHERE name = ?");
                $check->execute([$dbName]);
                if ($check->fetchColumn() === false) {
                    $masterDb->exec("CREATE DATABASE [{$dbName}]");
                }
                $masterDb = null;
            } catch (PDOException $e) {
                // Ignore failure if user doesn't have master access but db exists
            }
            
            try {
                $dsn = "odbc:Driver={ODBC Driver 18 for SQL Server};Server={$host},{$port};Database={$dbName};Encrypt=yes;TrustServerCertificate=yes;";
                $db = new PrefmonPDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ], 'mssql');
                
                initializeMssqlSchema($db);
            } catch (PDOException $e) {
                die("Database Connection Error (MSSQL Repository): " . $e->getMessage());
            }
        } else {
            // Portable SQLite Backend
            $dbPath = APP_DB_PATH;
            $dbDir = dirname($dbPath);
            if (!file_exists($dbDir)) {
                mkdir($dbDir, 0755, true);
            }
            $dbExists = file_exists($dbPath);
            try {
                $db = new PrefmonPDO("sqlite:" . $dbPath, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ], 'sqlite');
                
                // Enable foreign key constraints
                $db->exec("PRAGMA foreign_keys = ON;");
                
                if (!$dbExists || filesize($dbPath) === 0) {
                    initializeSchema($db);
                } else {
                    // Online schema upgrades for SQLite
                    try {
                        $db->exec("ALTER TABLE servers ADD COLUMN trust_server_cert INTEGER DEFAULT 0");
                    } catch (PDOException $e) {}
                    try {
                        $db->exec("ALTER TABLE top_queries ADD COLUMN query_plan TEXT");
                    } catch (PDOException $e) {}
                    try {
                        $db->exec("ALTER TABLE top_queries ADD COLUMN parameters TEXT");
                    } catch (PDOException $e) {}
                    try {
                        $db->exec("CREATE TABLE IF NOT EXISTS blocking_history (
                            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                            server_id           INTEGER REFERENCES servers(id) ON DELETE CASCADE,
                            collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
                            blocked_session_id  INTEGER,
                            blocked_sql         TEXT,
                            blocking_session_id INTEGER,
                            blocking_sql        TEXT,
                            wait_time_ms        INTEGER,
                            wait_type           TEXT,
                            resource_description TEXT
                        )");
                    } catch (PDOException $e) {}
                    try {
                        $db->exec("CREATE TABLE IF NOT EXISTS db_file_stats (
                            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                            server_id           INTEGER REFERENCES servers(id) ON DELETE CASCADE,
                            collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
                            database_name       TEXT,
                            file_name           TEXT,
                            file_type           TEXT,
                            physical_name       TEXT,
                            total_size_mb       REAL,
                            used_space_mb       REAL,
                            free_space_mb       REAL,
                            free_space_pct      REAL
                        )");
                    } catch (PDOException $e) {}
                    try {
                        $db->exec("CREATE TABLE IF NOT EXISTS triggered_alerts (
                            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                            server_id           INTEGER REFERENCES servers(id) ON DELETE CASCADE,
                            collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
                            alert_type          TEXT,
                            severity            TEXT,
                            message             TEXT,
                            email_sent          INTEGER DEFAULT 0,
                            email_error         TEXT
                        )");
                    } catch (PDOException $e) {}
                    try {
                        $db->exec("CREATE TABLE IF NOT EXISTS deadlock_history (
                            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                            server_id           INTEGER REFERENCES servers(id) ON DELETE CASCADE,
                            collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
                            deadlock_time       DATETIME,
                            database_name       TEXT,
                            victim_spid         INTEGER,
                            deadlock_graph      TEXT,
                            parsed_details      TEXT
                        )");
                    } catch (PDOException $e) {}
                    try {
                        $db->exec("CREATE TABLE IF NOT EXISTS db_backup_stats (
                            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                            server_id           INTEGER REFERENCES servers(id) ON DELETE CASCADE,
                            collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
                            database_name       TEXT,
                            recovery_model      TEXT,
                            last_full_backup    DATETIME,
                            full_backup_size_mb REAL,
                            last_diff_backup    DATETIME,
                            diff_backup_size_mb REAL,
                            last_log_backup     DATETIME,
                            log_backup_size_mb  REAL
                        )");
                    } catch (PDOException $e) {}
                    try {
                        $db->exec("ALTER TABLE db_backup_stats ADD COLUMN last_diff_backup DATETIME");
                    } catch (PDOException $e) {}
                    try {
                        $db->exec("ALTER TABLE db_backup_stats ADD COLUMN diff_backup_size_mb REAL");
                    } catch (PDOException $e) {}
                    
                    // Create index mappings if missing
                    try {
                        $db->exec("CREATE INDEX IF NOT EXISTS idx_snapshots_server_collected ON metric_snapshots (server_id, collected_at)");
                        $db->exec("CREATE INDEX IF NOT EXISTS idx_waits_server_collected ON wait_stats (server_id, collected_at)");
                        $db->exec("CREATE INDEX IF NOT EXISTS idx_queries_server_collected ON top_queries (server_id, collected_at)");
                        $db->exec("CREATE INDEX IF NOT EXISTS idx_idxstats_server_collected ON index_stats (server_id, collected_at)");
                        $db->exec("CREATE INDEX IF NOT EXISTS idx_blocks_server_collected ON blocking_history (server_id, collected_at)");
                        $db->exec("CREATE INDEX IF NOT EXISTS idx_files_server_collected ON db_file_stats (server_id, collected_at)");
                        $db->exec("CREATE INDEX IF NOT EXISTS idx_alerts_server_collected ON triggered_alerts (server_id, collected_at)");
                        $db->exec("CREATE INDEX IF NOT EXISTS idx_deadlocks_server_collected ON deadlock_history (server_id, collected_at)");
                        $db->exec("CREATE INDEX IF NOT EXISTS idx_backups_server_collected ON db_backup_stats (server_id, collected_at)");
                        $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs (created_at)");
                    } catch (PDOException $e) {}
                }
            } catch (PDOException $e) {
                die("Database Connection Error (SQLite): " . $e->getMessage());
            }
        }
    }
    return $db;
}

function initializeSchema(PDO $db) {
    // 1. Users table
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        username    TEXT NOT NULL UNIQUE,
        password    TEXT NOT NULL,
        email       TEXT,
        role        TEXT DEFAULT 'viewer',
        is_active   INTEGER DEFAULT 1,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_login  DATETIME
    )");
    
    // 2. Servers table
    $db->exec("CREATE TABLE IF NOT EXISTS servers (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        display_name    TEXT NOT NULL,
        hostname        TEXT NOT NULL,
        port            INTEGER DEFAULT 1433,
        instance_name   TEXT,
        username        TEXT NOT NULL,
        password        TEXT NOT NULL,
        environment     TEXT DEFAULT 'production',
        is_active       INTEGER DEFAULT 1,
        added_by        INTEGER REFERENCES users(id),
        added_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_checked    DATETIME,
        last_status     TEXT DEFAULT 'unknown',
        trust_server_cert INTEGER DEFAULT 0
    )");
    
    // 3. Metric snapshots table
    $db->exec("CREATE TABLE IF NOT EXISTS metric_snapshots (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id       INTEGER REFERENCES servers(id) ON DELETE CASCADE,
        collected_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        cpu_usage_pct   REAL,
        memory_used_mb  REAL,
        memory_total_mb REAL,
        page_life_exp   INTEGER,
        batch_req_sec   REAL,
        sql_comp_sec    REAL,
        sql_recomp_sec  REAL,
        lock_waits_sec  REAL,
        deadlocks_sec   REAL,
        disk_read_ms    REAL,
        disk_write_ms   REAL,
        active_conn     INTEGER,
        blocked_procs   INTEGER,
        tempdb_used_mb  REAL
    )");
    
    // 4. Wait stats table
    $db->exec("CREATE TABLE IF NOT EXISTS wait_stats (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id       INTEGER REFERENCES servers(id) ON DELETE CASCADE,
        collected_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        wait_type       TEXT NOT NULL,
        wait_time_ms    REAL,
        waiting_tasks   INTEGER,
        signal_wait_ms  REAL
    )");
    
    // 5. Top queries table
    $db->exec("CREATE TABLE IF NOT EXISTS top_queries (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id           INTEGER REFERENCES servers(id) ON DELETE CASCADE,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        query_hash          TEXT,
        query_text          TEXT,
        database_name       TEXT,
        total_cpu_ms        REAL,
        total_elapsed_ms    REAL,
        total_logical_reads INTEGER,
        execution_count     INTEGER,
        avg_cpu_ms          REAL,
        avg_elapsed_ms      REAL,
        avg_logical_reads   REAL,
        missing_index_hint  TEXT,
        query_plan          TEXT,
        parameters          TEXT
    )");
    
    // 6. Index stats table
    $db->exec("CREATE TABLE IF NOT EXISTS index_stats (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id           INTEGER REFERENCES servers(id) ON DELETE CASCADE,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        database_name       TEXT,
        schema_name         TEXT,
        table_name          TEXT,
        index_name          TEXT,
        index_type          TEXT,
        user_seeks          INTEGER,
        user_scans          INTEGER,
        user_lookups        INTEGER,
        user_updates        INTEGER,
        fragmentation_pct   REAL,
        page_count          INTEGER,
        issue_type          TEXT
    )");
    
    // 7. Recommendations table
    $db->exec("CREATE TABLE IF NOT EXISTS recommendations (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id       INTEGER REFERENCES servers(id) ON DELETE CASCADE,
        generated_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        category        TEXT,
        severity        TEXT,
        title           TEXT NOT NULL,
        description     TEXT,
        fix_script      TEXT,
        is_resolved     INTEGER DEFAULT 0,
        resolved_by     INTEGER REFERENCES users(id),
        resolved_at     DATETIME
    )");
    
    // 8. Audit log table
    $db->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
        action      TEXT NOT NULL,
        target_type TEXT,
        target_id   INTEGER,
        detail      TEXT,
        ip_address  TEXT,
        logged_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 10. Blocking history table
    $db->exec("CREATE TABLE IF NOT EXISTS blocking_history (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id           INTEGER REFERENCES servers(id) ON DELETE CASCADE,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        blocked_session_id  INTEGER,
        blocked_sql         TEXT,
        blocking_session_id INTEGER,
        blocking_sql        TEXT,
        wait_time_ms        INTEGER,
        wait_type           TEXT,
        resource_description TEXT
    )");

    // 11. DB file stats table
    $db->exec("CREATE TABLE IF NOT EXISTS db_file_stats (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id           INTEGER REFERENCES servers(id) ON DELETE CASCADE,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        database_name       TEXT,
        file_name           TEXT,
        file_type           TEXT,
        physical_name       TEXT,
        total_size_mb       REAL,
        used_space_mb       REAL,
        free_space_mb       REAL,
        free_space_pct      REAL
    )");

    // 12. Triggered alerts table
    $db->exec("CREATE TABLE IF NOT EXISTS triggered_alerts (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id           INTEGER REFERENCES servers(id) ON DELETE CASCADE,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        alert_type          TEXT,
        severity            TEXT,
        message             TEXT,
        email_sent          INTEGER DEFAULT 0,
        email_error         TEXT
    )");

    // 13. Deadlock history table
    $db->exec("CREATE TABLE IF NOT EXISTS deadlock_history (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id           INTEGER REFERENCES servers(id) ON DELETE CASCADE,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        deadlock_time       DATETIME,
        database_name       TEXT,
        victim_spid         INTEGER,
        deadlock_graph      TEXT,
        parsed_details      TEXT
    )");

    // 14. DB backup stats table
    $db->exec("CREATE TABLE IF NOT EXISTS db_backup_stats (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id           INTEGER REFERENCES servers(id) ON DELETE CASCADE,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        database_name       TEXT,
        recovery_model      TEXT,
        last_full_backup    DATETIME,
        full_backup_size_mb REAL,
        last_diff_backup    DATETIME,
        diff_backup_size_mb REAL,
        last_log_backup     DATETIME,
        log_backup_size_mb  REAL
    )");
    
    // 15. Create secondary indexes for performance optimization
    $db->exec("CREATE INDEX IF NOT EXISTS idx_snapshots_server_collected ON metric_snapshots (server_id, collected_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_waits_server_collected ON wait_stats (server_id, collected_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_queries_server_collected ON top_queries (server_id, collected_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_idxstats_server_collected ON index_stats (server_id, collected_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_blocks_server_collected ON blocking_history (server_id, collected_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_files_server_collected ON db_file_stats (server_id, collected_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_alerts_server_collected ON triggered_alerts (server_id, collected_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_deadlocks_server_collected ON deadlock_history (server_id, collected_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_backups_server_collected ON db_backup_stats (server_id, collected_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs (created_at)");

    // 9. Seeding initial Administrator user
    $checkStmt = $db->query("SELECT COUNT(*) FROM users");
    if ($checkStmt->fetchColumn() == 0) {
        $username = 'admin';
        $password = password_hash('Sumo@123', PASSWORD_BCRYPT);
        $email = 'admin@sqlperf.local';
        $role = 'admin';
        
        $insert = $db->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)");
        $insert->execute([$username, $password, $email, $role]);
    }
}

function initializeMssqlSchema(PDO $db) {
    // 1. Create tables if they do not exist
    $db->exec("IF OBJECT_ID('users', 'U') IS NULL
    CREATE TABLE users (
        id          INT IDENTITY(1,1) PRIMARY KEY,
        username    NVARCHAR(100) NOT NULL UNIQUE,
        password    NVARCHAR(255) NOT NULL,
        email       NVARCHAR(255),
        role        NVARCHAR(50) DEFAULT 'viewer',
        status      NVARCHAR(50) DEFAULT 'active',
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    $db->exec("IF OBJECT_ID('servers', 'U') IS NULL
    CREATE TABLE servers (
        id              INT IDENTITY(1,1) PRIMARY KEY,
        display_name    NVARCHAR(100) NOT NULL,
        hostname        NVARCHAR(255) NOT NULL,
        port            INT DEFAULT 1433,
        username        NVARCHAR(255),
        password        NVARCHAR(255),
        environment     NVARCHAR(50) DEFAULT 'production',
        is_active       INT DEFAULT 1,
        last_checked    DATETIME,
        last_status     NVARCHAR(50),
        trust_server_cert INT DEFAULT 0
    )");
    
    $db->exec("IF OBJECT_ID('metric_snapshots', 'U') IS NULL
    CREATE TABLE metric_snapshots (
        id                      INT IDENTITY(1,1) PRIMARY KEY,
        server_id               INT,
        collected_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
        cpu_usage_pct           REAL,
        memory_used_mb          REAL,
        memory_total_mb         REAL,
        page_life_exp           INT,
        buffer_cache_hit_ratio  REAL,
        disk_read_ms            REAL,
        disk_write_ms           REAL,
        active_connections      INT,
        blocked_procs           INT,
        batch_req_sec           REAL,
        sql_recomp_sec          REAL
    )");
    
    $db->exec("IF OBJECT_ID('wait_stats', 'U') IS NULL
    CREATE TABLE wait_stats (
        id                  INT IDENTITY(1,1) PRIMARY KEY,
        server_id           INT,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        wait_type           NVARCHAR(100),
        wait_time_ms        BIGINT,
        waiting_tasks_count BIGINT,
        max_wait_time_ms    BIGINT,
        signal_wait_time_ms BIGINT
    )");
    
    $db->exec("IF OBJECT_ID('top_queries', 'U') IS NULL
    CREATE TABLE top_queries (
        id              INT IDENTITY(1,1) PRIMARY KEY,
        server_id       INT,
        collected_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        query_hash      NVARCHAR(100),
        execution_count BIGINT,
        cpu_time_ms     BIGINT,
        logical_reads   BIGINT,
        logical_writes  BIGINT,
        elapsed_time_ms BIGINT,
        query_text      NVARCHAR(MAX),
        query_plan      NVARCHAR(MAX),
        parameters      NVARCHAR(MAX)
    )");
    
    $db->exec("IF OBJECT_ID('index_stats', 'U') IS NULL
    CREATE TABLE index_stats (
        id                  INT IDENTITY(1,1) PRIMARY KEY,
        server_id           INT,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        database_name       NVARCHAR(100),
        table_name          NVARCHAR(100),
        index_name          NVARCHAR(100),
        index_type          NVARCHAR(50),
        user_seeks          BIGINT,
        user_scans          BIGINT,
        user_lookups        BIGINT,
        user_updates        BIGINT,
        avg_fragmentation   REAL
    )");
    
    $db->exec("IF OBJECT_ID('blocking_history', 'U') IS NULL
    CREATE TABLE blocking_history (
        id              INT IDENTITY(1,1) PRIMARY KEY,
        server_id           INT,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        blocked_session_id  INT,
        blocked_sql         NVARCHAR(MAX),
        blocking_session_id INT,
        blocking_sql        NVARCHAR(MAX),
        wait_time_ms        INT,
        wait_type           NVARCHAR(100),
        resource_description NVARCHAR(MAX)
    )");
    
    $db->exec("IF OBJECT_ID('audit_logs', 'U') IS NULL
    CREATE TABLE audit_logs (
        id              INT IDENTITY(1,1) PRIMARY KEY,
        user_id         INT,
        action          NVARCHAR(100),
        target_type     NVARCHAR(100),
        target_id       INT,
        details         NVARCHAR(MAX),
        ip_address      NVARCHAR(45),
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    $db->exec("IF OBJECT_ID('settings', 'U') IS NULL
    CREATE TABLE settings (
        [key]   NVARCHAR(100) PRIMARY KEY,
        value   NVARCHAR(MAX)
    )");
    
    $db->exec("IF OBJECT_ID('db_file_stats', 'U') IS NULL
    CREATE TABLE db_file_stats (
        id              INT IDENTITY(1,1) PRIMARY KEY,
        server_id       INT,
        collected_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        database_name   NVARCHAR(100),
        file_name       NVARCHAR(255),
        file_type       NVARCHAR(50),
        physical_name   NVARCHAR(500),
        total_size_mb   REAL,
        used_space_mb   REAL,
        free_space_mb   REAL,
        free_space_pct  REAL
    )");
    
    $db->exec("IF OBJECT_ID('triggered_alerts', 'U') IS NULL
    CREATE TABLE triggered_alerts (
        id                  INT IDENTITY(1,1) PRIMARY KEY,
        server_id           INT,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        alert_type          NVARCHAR(100),
        severity            NVARCHAR(50),
        message             NVARCHAR(MAX),
        email_sent          INT DEFAULT 0,
        email_error         NVARCHAR(MAX)
    )");
    
    $db->exec("IF OBJECT_ID('deadlock_history', 'U') IS NULL
    CREATE TABLE deadlock_history (
        id                  INT IDENTITY(1,1) PRIMARY KEY,
        server_id           INT,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        deadlock_time       DATETIME,
        database_name       NVARCHAR(100),
        victim_spid         INT,
        deadlock_graph      NVARCHAR(MAX),
        parsed_details      NVARCHAR(MAX)
    )");
    
    $db->exec("IF OBJECT_ID('db_backup_stats', 'U') IS NULL
    CREATE TABLE db_backup_stats (
        id                  INT IDENTITY(1,1) PRIMARY KEY,
        server_id           INT,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        database_name       NVARCHAR(100),
        recovery_model      NVARCHAR(50),
        last_full_backup    DATETIME,
        full_backup_size_mb REAL,
        last_diff_backup    DATETIME,
        diff_backup_size_mb REAL,
        last_log_backup     DATETIME,
        log_backup_size_mb  REAL
    )");

    // 2. Create secondary indexes
    $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'idx_snapshots_server_collected' AND object_id = OBJECT_ID('metric_snapshots'))
        CREATE INDEX idx_snapshots_server_collected ON metric_snapshots (server_id, collected_at)");
        
    $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'idx_waits_server_collected' AND object_id = OBJECT_ID('wait_stats'))
        CREATE INDEX idx_waits_server_collected ON wait_stats (server_id, collected_at)");
        
    $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'idx_queries_server_collected' AND object_id = OBJECT_ID('top_queries'))
        CREATE INDEX idx_queries_server_collected ON top_queries (server_id, collected_at)");
        
    $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'idx_idxstats_server_collected' AND object_id = OBJECT_ID('index_stats'))
        CREATE INDEX idx_idxstats_server_collected ON index_stats (server_id, collected_at)");
        
    $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'idx_blocks_server_collected' AND object_id = OBJECT_ID('blocking_history'))
        CREATE INDEX idx_blocks_server_collected ON blocking_history (server_id, collected_at)");
        
    $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'idx_files_server_collected' AND object_id = OBJECT_ID('db_file_stats'))
        CREATE INDEX idx_files_server_collected ON db_file_stats (server_id, collected_at)");
        
    $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'idx_alerts_server_collected' AND object_id = OBJECT_ID('triggered_alerts'))
        CREATE INDEX idx_alerts_server_collected ON triggered_alerts (server_id, collected_at)");
        
    $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'idx_deadlocks_server_collected' AND object_id = OBJECT_ID('deadlock_history'))
        CREATE INDEX idx_deadlocks_server_collected ON deadlock_history (server_id, collected_at)");
        
    $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'idx_backups_server_collected' AND object_id = OBJECT_ID('db_backup_stats'))
        CREATE INDEX idx_backups_server_collected ON db_backup_stats (server_id, collected_at)");
        
    $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'idx_audit_created' AND object_id = OBJECT_ID('audit_logs'))
        CREATE INDEX idx_audit_created ON audit_logs (created_at)");

    // 3. Seed initial admin user
    $check = $db->query("SELECT COUNT(*) FROM users");
    if ($check->fetchColumn() == 0) {
        $username = 'admin';
        $password = password_hash('Sumo@123', PASSWORD_BCRYPT);
        $email = 'admin@sqlperf.local';
        $role = 'admin';
        
        $insert = $db->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)");
        $insert->execute([$username, $password, $email, $role]);
    }
}

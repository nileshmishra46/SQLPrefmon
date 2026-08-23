<?php
// includes/db.php

require_once dirname(__DIR__) . '/config/app.php';
require_once __DIR__ . '/helpers.php';

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
        
        // Rewrite sqlite literal datetime('now', '-X units') to MSSQL DATEADD
        $sql = preg_replace_callback('/datetime\(\'now\',\s*\'(-?\d+)\s+(\w+)\'\)/i', function($m) {
            $val = (int)$m[1];
            $unit = strtolower($m[2]);
            if (strpos($unit, 'minute') !== false) {
                return "DATEADD(minute, $val, GETDATE())";
            } elseif (strpos($unit, 'hour') !== false) {
                return "DATEADD(hour, $val, GETDATE())";
            } elseif (strpos($unit, 'day') !== false) {
                return "DATEADD(day, $val, GETDATE())";
            }
            return $m[0];
        }, $sql);
        
        // Rewrite sqlite dynamic datetime('now', :interval) to UDF helper
        $sql = preg_replace(
            '/datetime\(\'now\',\s*(:\w+)\)/i',
            "dbo.fn_sqlite_datetime(GETDATE(), $1)",
            $sql
        );
        
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
            $auth = getAppSetting('repo_mssql_auth', 'sql');
            $trustCert = getAppSetting('repo_mssql_trust_cert', 1);
            $encrypt = getAppSetting('repo_mssql_encrypt', 'mandatory');
            
            $encryptStr = "";
            if ($encrypt === 'strict') {
                $encryptStr = "Encrypt=Strict;";
            } elseif ($encrypt === 'optional') {
                $encryptStr = "Encrypt=no;";
            } else {
                $encryptStr = $trustCert ? "Encrypt=yes;TrustServerCertificate=yes;" : "Encrypt=yes;TrustServerCertificate=no;";
            }
            
            $masterDsn = "odbc:Driver={ODBC Driver 18 for SQL Server};Server={$host},{$port};Database=master;{$encryptStr}";
            $dsn = "odbc:Driver={ODBC Driver 18 for SQL Server};Server={$host},{$port};Database={$dbName};{$encryptStr}";
            
            if ($auth === 'windows') {
                $masterDsn .= "Trusted_Connection=yes;ConnectionTimeout=3;";
                $dsn .= "Trusted_Connection=yes;";
                $dbUser = null;
                $dbPass = null;
            } else {
                $masterDsn .= "ConnectionTimeout=3;";
                $dbUser = $user;
                $dbPass = $pass;
            }
            
            // Connect to master database first to check database existence
            try {
                $masterDb = new PDO($masterDsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);
                $check = $masterDb->prepare("SELECT is_read_committed_snapshot_on FROM sys.databases WHERE name = ?");
                $check->execute([$dbName]);
                $rcsiVal = $check->fetchColumn();
                if ($rcsiVal === false) {
                    $masterDb->exec("CREATE DATABASE [{$dbName}]");
                    try {
                        $masterDb->exec("ALTER DATABASE [{$dbName}] SET READ_COMMITTED_SNAPSHOT ON WITH ROLLBACK IMMEDIATE;");
                    } catch (PDOException $ex) {}
                } elseif ((int)$rcsiVal === 0) {
                    try {
                        $masterDb->exec("ALTER DATABASE [{$dbName}] SET READ_COMMITTED_SNAPSHOT ON WITH ROLLBACK IMMEDIATE;");
                    } catch (PDOException $ex) {}
                }
                $masterDb = null;
            } catch (PDOException $e) {
                // Ignore failure if user doesn't have master access but db exists
            }
            
            try {
                $db = new PrefmonPDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ], 'mssql');
                
                initializeMssqlSchema($db);
            } catch (Exception $e) {
                // Connection failed. Set global error and fall back to SQLite
                $GLOBALS['repo_connection_error'] = $e->getMessage();
                
                $dbPath = APP_DB_PATH;
                $dbExists = file_exists($dbPath);
                try {
                    $db = new PrefmonPDO("sqlite:" . $dbPath, null, null, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                    ], 'sqlite');
                    $db->exec("PRAGMA foreign_keys = ON;");
                    $db->exec("PRAGMA journal_mode = WAL;");
                    $db->exec("PRAGMA busy_timeout = 5000;");
                    $db->exec("PRAGMA synchronous = NORMAL;");
                    
                    if (!$dbExists || filesize($dbPath) === 0) {
                        initializeSchema($db);
                    }
                } catch (Exception $sqLiteEx) {
                    die("Database Connection Error (MSSQL failed and SQLite fallback failed): " . $e->getMessage());
                }
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
                
                // Enable foreign key constraints and WAL mode to prevent concurrency blocking
                $db->exec("PRAGMA foreign_keys = ON;");
                $db->exec("PRAGMA journal_mode = WAL;");
                $db->exec("PRAGMA busy_timeout = 5000;");
                $db->exec("PRAGMA synchronous = NORMAL;");
                
                if (!$dbExists || filesize($dbPath) === 0) {
                    initializeSchema($db);
                } else {
                    // Online schema upgrades for SQLite
                    try {
                        $db->exec("ALTER TABLE servers ADD COLUMN trust_server_cert INTEGER DEFAULT 0");
                    } catch (PDOException $e) {}
                    try {
                        $db->exec("ALTER TABLE servers ADD COLUMN hadr_role TEXT DEFAULT NULL");
                    } catch (PDOException $e) {}
                    try {
                        $db->exec("ALTER TABLE servers ADD COLUMN history_capture_mode TEXT DEFAULT 'collector'");
                    } catch (PDOException $e) {}
                    try {
                        $db->exec("CREATE TABLE IF NOT EXISTS alwayson_replicas (
                            id                      INTEGER PRIMARY KEY AUTOINCREMENT,
                            server_id               INTEGER REFERENCES servers(id) ON DELETE CASCADE,
                            collected_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
                            ag_name                 TEXT,
                            replica_server_name     TEXT,
                            role_desc               TEXT,
                            operational_state_desc  TEXT,
                            connected_state_desc    TEXT,
                            synchronization_health_desc TEXT
                        )");
                    } catch (PDOException $e) {}
                    try {
                        $db->exec("CREATE TABLE IF NOT EXISTS alwayson_databases (
                            id                      INTEGER PRIMARY KEY AUTOINCREMENT,
                            server_id               INTEGER REFERENCES servers(id) ON DELETE CASCADE,
                            collected_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
                            ag_name                 TEXT,
                            database_name           TEXT,
                            synchronization_state_desc TEXT,
                            synchronization_health_desc TEXT,
                            log_send_queue_size     REAL,
                            log_send_rate           REAL,
                            redo_queue_size         REAL,
                            redo_rate               REAL
                        )");
                    } catch (PDOException $e) {}
                    try {
                        $db->exec("CREATE TABLE IF NOT EXISTS alwayson_cluster (
                            id                      INTEGER PRIMARY KEY AUTOINCREMENT,
                            server_id               INTEGER REFERENCES servers(id) ON DELETE CASCADE,
                            collected_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
                            cluster_name            TEXT,
                            quorum_type_desc        TEXT,
                            quorum_state_desc       TEXT
                        )");
                    } catch (PDOException $e) {}
                    try {
                        $db->exec("CREATE TABLE IF NOT EXISTS alwayson_cluster_members (
                            id                      INTEGER PRIMARY KEY AUTOINCREMENT,
                            server_id               INTEGER REFERENCES servers(id) ON DELETE CASCADE,
                            collected_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
                            member_name             TEXT,
                            member_type_desc        TEXT,
                            member_state_desc       TEXT,
                            number_of_quorum_votes  INTEGER
                        )");
                    } catch (PDOException $e) {}
                    try {
                        $db->exec("CREATE INDEX IF NOT EXISTS idx_alwayson_replicas_collected ON alwayson_replicas (server_id, collected_at)");
                        $db->exec("CREATE INDEX IF NOT EXISTS idx_alwayson_dbs_collected ON alwayson_databases (server_id, collected_at)");
                        $db->exec("CREATE INDEX IF NOT EXISTS idx_alwayson_cluster_collected ON alwayson_cluster (server_id, collected_at)");
                        $db->exec("CREATE INDEX IF NOT EXISTS idx_alwayson_members_collected ON alwayson_cluster_members (server_id, collected_at)");
                        $db->exec("CREATE INDEX IF NOT EXISTS idx_ash_server_minute ON active_session_history (server_id, sample_minute)");
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
                    try {
                        $db->exec("CREATE TABLE IF NOT EXISTS agent_job_status (
                            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                            server_id           INTEGER REFERENCES servers(id) ON DELETE CASCADE,
                            collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
                            job_id              TEXT,
                            job_name            TEXT,
                            enabled             INTEGER,
                            description         TEXT,
                            current_status      TEXT,
                            last_run_time       DATETIME,
                            run_duration_sec    INTEGER,
                            last_outcome_message TEXT
                        )");
                    } catch (PDOException $e) {}
                    try {
                        $db->exec("CREATE TABLE IF NOT EXISTS agent_job_history (
                            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                            server_id           INTEGER REFERENCES servers(id) ON DELETE CASCADE,
                            collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
                            job_id              TEXT,
                            job_name            TEXT,
                            step_id             INTEGER,
                            step_name           TEXT,
                            run_status          TEXT,
                            run_time            DATETIME,
                            run_duration_sec    INTEGER,
                            message             TEXT
                        )");
                    } catch (PDOException $e) {}
                    try {
                        $db->exec("CREATE TABLE IF NOT EXISTS active_session_history (
                            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                            server_id           INTEGER REFERENCES servers(id) ON DELETE CASCADE,
                            sample_minute       DATETIME,
                            query_text          TEXT,
                            wait_type           TEXT,
                            samples_count       INTEGER,
                            total_wait_time_ms  INTEGER
                        )");
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
                        $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log (created_at)");
                        $db->exec("CREATE INDEX IF NOT EXISTS idx_agent_job_status_server_collected ON agent_job_status (server_id, collected_at)");
                        $db->exec("CREATE INDEX IF NOT EXISTS idx_agent_job_history_server_collected ON agent_job_history (server_id, collected_at)");
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
        trust_server_cert INTEGER DEFAULT 0,
        hadr_role       TEXT DEFAULT NULL,
        history_capture_mode TEXT DEFAULT 'collector'
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

    // 16. Agent Job Status table
    $db->exec("CREATE TABLE IF NOT EXISTS agent_job_status (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id           INTEGER REFERENCES servers(id) ON DELETE CASCADE,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        job_id              TEXT,
        job_name            TEXT,
        enabled             INTEGER,
        description         TEXT,
        current_status      TEXT,
        last_run_time       DATETIME,
        run_duration_sec    INTEGER,
        last_outcome_message TEXT
    )");

    // 17. Agent Job History table
    $db->exec("CREATE TABLE IF NOT EXISTS agent_job_history (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id           INTEGER REFERENCES servers(id) ON DELETE CASCADE,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        job_id              TEXT,
        job_name            TEXT,
        step_id             INTEGER,
        step_name           TEXT,
        run_status          TEXT,
        run_time            DATETIME,
        run_duration_sec    INTEGER,
        message             TEXT
    )");

    // 18. Always On Replicas table
    $db->exec("CREATE TABLE IF NOT EXISTS alwayson_replicas (
        id                      INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id               INTEGER REFERENCES servers(id) ON DELETE CASCADE,
        collected_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
        ag_name                 TEXT,
        replica_server_name     TEXT,
        role_desc               TEXT,
        operational_state_desc  TEXT,
        connected_state_desc    TEXT,
        synchronization_health_desc TEXT
    )");

    // 19. Always On Databases table
    $db->exec("CREATE TABLE IF NOT EXISTS alwayson_databases (
        id                      INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id               INTEGER REFERENCES servers(id) ON DELETE CASCADE,
        collected_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
        ag_name                 TEXT,
        database_name           TEXT,
        synchronization_state_desc TEXT,
        synchronization_health_desc TEXT,
        log_send_queue_size     REAL,
        log_send_rate           REAL,
        redo_queue_size         REAL,
        redo_rate               REAL
    )");

    // 20. Always On Cluster status table
    $db->exec("CREATE TABLE IF NOT EXISTS alwayson_cluster (
        id                      INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id               INTEGER REFERENCES servers(id) ON DELETE CASCADE,
        collected_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
        cluster_name            TEXT,
        quorum_type_desc        TEXT,
        quorum_state_desc       TEXT
    )");

    // 21. Always On Cluster Members table
    $db->exec("CREATE TABLE IF NOT EXISTS alwayson_cluster_members (
        id                      INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id               INTEGER REFERENCES servers(id) ON DELETE CASCADE,
        collected_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
        member_name             TEXT,
        member_type_desc        TEXT,
        member_state_desc       TEXT,
        number_of_quorum_votes  INTEGER
    )");
    
    // 22. Active Session History table
    $db->exec("CREATE TABLE IF NOT EXISTS active_session_history (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id           INTEGER REFERENCES servers(id) ON DELETE CASCADE,
        sample_minute       DATETIME,
        query_text          TEXT,
        wait_type           TEXT,
        samples_count       INTEGER,
        total_wait_time_ms  INTEGER
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
    $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log (created_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_agent_job_status_server_collected ON agent_job_status (server_id, collected_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_agent_job_history_server_collected ON agent_job_history (server_id, collected_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_alwayson_replicas_collected ON alwayson_replicas (server_id, collected_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_alwayson_dbs_collected ON alwayson_databases (server_id, collected_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_alwayson_cluster_collected ON alwayson_cluster (server_id, collected_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_alwayson_members_collected ON alwayson_cluster_members (server_id, collected_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ash_server_minute ON active_session_history (server_id, sample_minute)");

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
    // 1. Check SQL Server version is 2016+
    $version = (int)$db->query("SELECT CAST(SERVERPROPERTY('ProductMajorVersion') AS INT)")->fetchColumn();
    if ($version < 13) {
        throw new Exception("SQL Server version is $version. SQL Server 2016 (Major Version 13) or higher is required to support partitioning.");
    }

    // 2. Create Partition Function and Partition Scheme
    $db->exec("IF NOT EXISTS (SELECT * FROM sys.partition_functions WHERE name = 'pf_server_id')
        CREATE PARTITION FUNCTION pf_server_id (int) AS RANGE LEFT FOR VALUES (1, 5, 10)");
        
    $db->exec("IF NOT EXISTS (SELECT * FROM sys.partition_schemes WHERE name = 'ps_server_id')
        CREATE PARTITION SCHEME ps_server_id AS PARTITION pf_server_id ALL TO ([PRIMARY])");

    // Create fn_sqlite_datetime helper function for parameter-safe datetime query translation
    $db->exec("IF OBJECT_ID('dbo.fn_sqlite_datetime', 'FN') IS NULL
    BEGIN
        EXEC('
        CREATE FUNCTION dbo.fn_sqlite_datetime(@base DATETIME, @interval NVARCHAR(100))
        RETURNS DATETIME
        AS
        BEGIN
            DECLARE @num INT
            DECLARE @unit NVARCHAR(50)
            
            SET @interval = LTRIM(RTRIM(@interval))
            DECLARE @space INT = CHARINDEX('' '', @interval)
            IF @space > 0
            BEGIN
                SET @num = CAST(SUBSTRING(@interval, 1, @space - 1) AS INT)
                SET @unit = LOWER(SUBSTRING(@interval, @space + 1, LEN(@interval)))
                
                IF @unit LIKE ''%minute%''
                    RETURN DATEADD(minute, @num, @base)
                IF @unit LIKE ''%hour%''
                    RETURN DATEADD(hour, @num, @base)
                IF @unit LIKE ''%day%''
                    RETURN DATEADD(day, @num, @base)
            END
            
            RETURN @base
        END
        ')
    END");

    // Helper for online migration of existing tables
    $migrateToPartitioned = function($tableName) use ($db) {
        $exists = $db->query("SELECT OBJECT_ID('$tableName', 'U')")->fetchColumn();
        if (!$exists) {
            return;
        }
        
        $isPartitioned = $db->query("
            SELECT COUNT(*) 
            FROM sys.indexes i
            JOIN sys.data_spaces ds ON i.data_space_id = ds.data_space_id
            WHERE i.object_id = OBJECT_ID('$tableName') 
              AND i.type = 1 -- CLUSTERED
              AND ds.type = 'PS' -- Partition Scheme
        ")->fetchColumn() > 0;
        
        if ($isPartitioned) {
            return;
        }
        
        $pkName = $db->query("
            SELECT name 
            FROM sys.key_constraints 
            WHERE parent_object_id = OBJECT_ID('$tableName') 
              AND type = 'PK'
        ")->fetchColumn();
        
        if ($pkName) {
            $db->exec("ALTER TABLE [$tableName] DROP CONSTRAINT [$pkName]");
        }
        
        $db->exec("ALTER TABLE [$tableName] ALTER COLUMN server_id INT NOT NULL");
        $db->exec("ALTER TABLE [$tableName] ADD CONSTRAINT [PK_{$tableName}] PRIMARY KEY CLUSTERED (id, server_id) ON ps_server_id(server_id)");
    };

    // Run migration for existing tables
    $historicalTables = [
        'metric_snapshots', 'wait_stats', 'top_queries', 'index_stats', 'blocking_history',
        'db_file_stats', 'triggered_alerts', 'deadlock_history', 'db_backup_stats',
        'agent_job_status', 'agent_job_history', 'alwayson_replicas', 'alwayson_databases',
        'alwayson_cluster', 'alwayson_cluster_members', 'active_session_history'
    ];
    foreach ($historicalTables as $table) {
        $migrateToPartitioned($table);
    }

    // Online column migrations for metric_snapshots if needed
    $db->exec("IF OBJECT_ID('metric_snapshots', 'U') IS NOT NULL
    BEGIN
        IF COL_LENGTH('metric_snapshots', 'sql_comp_sec') IS NULL
            ALTER TABLE metric_snapshots ADD sql_comp_sec REAL;
        IF COL_LENGTH('metric_snapshots', 'lock_waits_sec') IS NULL
            ALTER TABLE metric_snapshots ADD lock_waits_sec REAL;
        IF COL_LENGTH('metric_snapshots', 'deadlocks_sec') IS NULL
            ALTER TABLE metric_snapshots ADD deadlocks_sec REAL;
        IF COL_LENGTH('metric_snapshots', 'tempdb_used_mb') IS NULL
            ALTER TABLE metric_snapshots ADD tempdb_used_mb REAL;
        IF COL_LENGTH('metric_snapshots', 'buffer_cache_hit_ratio') IS NULL
            ALTER TABLE metric_snapshots ADD buffer_cache_hit_ratio REAL;
    END");

    // Online column renaming / migration for wait_stats if needed
    $db->exec("IF OBJECT_ID('wait_stats', 'U') IS NOT NULL AND COL_LENGTH('wait_stats', 'waiting_tasks') IS NULL
    BEGIN
        IF COL_LENGTH('wait_stats', 'waiting_tasks_count') IS NOT NULL
            EXEC sp_rename 'wait_stats.waiting_tasks_count', 'waiting_tasks', 'COLUMN';
        ELSE
            ALTER TABLE wait_stats ADD waiting_tasks BIGINT;
    END");

    $db->exec("IF OBJECT_ID('wait_stats', 'U') IS NOT NULL AND COL_LENGTH('wait_stats', 'max_wait_time') IS NULL
    BEGIN
        IF COL_LENGTH('wait_stats', 'max_wait_time_ms') IS NOT NULL
            EXEC sp_rename 'wait_stats.max_wait_time_ms', 'max_wait_time', 'COLUMN';
        ELSE
            ALTER TABLE wait_stats ADD max_wait_time BIGINT;
    END");

    $db->exec("IF OBJECT_ID('wait_stats', 'U') IS NOT NULL AND COL_LENGTH('wait_stats', 'signal_wait_ms') IS NULL
    BEGIN
        IF COL_LENGTH('wait_stats', 'signal_wait_time_ms') IS NOT NULL
            EXEC sp_rename 'wait_stats.signal_wait_time_ms', 'signal_wait_ms', 'COLUMN';
        ELSE
            ALTER TABLE wait_stats ADD signal_wait_ms BIGINT;
    END");

    // Online column renaming / migration for top_queries if needed
    $db->exec("IF OBJECT_ID('top_queries', 'U') IS NOT NULL AND COL_LENGTH('top_queries', 'total_cpu_ms') IS NULL
    BEGIN
        IF COL_LENGTH('top_queries', 'cpu_time_ms') IS NOT NULL
            EXEC sp_rename 'top_queries.cpu_time_ms', 'total_cpu_ms', 'COLUMN';
        ELSE
            ALTER TABLE top_queries ADD total_cpu_ms REAL;
    END");

    $db->exec("IF OBJECT_ID('top_queries', 'U') IS NOT NULL AND COL_LENGTH('top_queries', 'total_elapsed_ms') IS NULL
    BEGIN
        IF COL_LENGTH('top_queries', 'elapsed_time_ms') IS NOT NULL
            EXEC sp_rename 'top_queries.elapsed_time_ms', 'total_elapsed_ms', 'COLUMN';
        ELSE
            ALTER TABLE top_queries ADD total_elapsed_ms REAL;
    END");

    $db->exec("IF OBJECT_ID('top_queries', 'U') IS NOT NULL AND COL_LENGTH('top_queries', 'total_logical_reads') IS NULL
    BEGIN
        IF COL_LENGTH('top_queries', 'logical_reads') IS NOT NULL
            EXEC sp_rename 'top_queries.logical_reads', 'total_logical_reads', 'COLUMN';
        ELSE
            ALTER TABLE top_queries ADD total_logical_reads BIGINT;
    END");

    $db->exec("IF OBJECT_ID('top_queries', 'U') IS NOT NULL
    BEGIN
        IF COL_LENGTH('top_queries', 'database_name') IS NULL
            ALTER TABLE top_queries ADD database_name NVARCHAR(100);
        IF COL_LENGTH('top_queries', 'avg_cpu_ms') IS NULL
            ALTER TABLE top_queries ADD avg_cpu_ms REAL;
        IF COL_LENGTH('top_queries', 'avg_elapsed_ms') IS NULL
            ALTER TABLE top_queries ADD avg_elapsed_ms REAL;
        IF COL_LENGTH('top_queries', 'avg_logical_reads') IS NULL
            ALTER TABLE top_queries ADD avg_logical_reads REAL;
        
        -- Convert MAX columns to VARCHAR(MAX) to prevent ODBC right-truncation errors
        ALTER TABLE top_queries ALTER COLUMN query_text VARCHAR(MAX) NULL;
        ALTER TABLE top_queries ALTER COLUMN query_plan VARCHAR(MAX) NULL;
        ALTER TABLE top_queries ALTER COLUMN parameters VARCHAR(MAX) NULL;
        
        IF COL_LENGTH('top_queries', 'missing_index_hint') IS NULL
            ALTER TABLE top_queries ADD missing_index_hint VARCHAR(MAX);
        ELSE
            ALTER TABLE top_queries ALTER COLUMN missing_index_hint VARCHAR(MAX) NULL;
    END");

    // 3. Create tables if they do not exist
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
        trust_server_cert INT DEFAULT 0,
        hadr_role       NVARCHAR(50) DEFAULT NULL,
        history_capture_mode NVARCHAR(50) DEFAULT 'collector'
    )");

    $db->exec("IF COL_LENGTH('servers', 'hadr_role') IS NULL
        ALTER TABLE servers ADD hadr_role NVARCHAR(50) DEFAULT NULL");
    
    $db->exec("IF COL_LENGTH('servers', 'history_capture_mode') IS NULL
        ALTER TABLE servers ADD history_capture_mode NVARCHAR(50) DEFAULT 'collector'");

    $db->exec("IF OBJECT_ID('metric_snapshots', 'U') IS NULL
    CREATE TABLE metric_snapshots (
        id                      INT IDENTITY(1,1) NOT NULL,
        server_id               INT NOT NULL,
        collected_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
        cpu_usage_pct           REAL,
        memory_used_mb          REAL,
        memory_total_mb         REAL,
        page_life_exp           INT,
        buffer_cache_hit_ratio  REAL,
        batch_req_sec           REAL,
        sql_comp_sec            REAL,
        sql_recomp_sec          REAL,
        lock_waits_sec          REAL,
        deadlocks_sec           REAL,
        disk_read_ms            REAL,
        disk_write_ms           REAL,
        active_conn             INT,
        blocked_procs           INT,
        tempdb_used_mb          REAL,
        CONSTRAINT PK_metric_snapshots PRIMARY KEY CLUSTERED (id, server_id)
    ) ON ps_server_id(server_id)");
    
    $db->exec("IF OBJECT_ID('wait_stats', 'U') IS NULL
    CREATE TABLE wait_stats (
        id                  INT IDENTITY(1,1) NOT NULL,
        server_id           INT NOT NULL,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        wait_type           NVARCHAR(100),
        wait_time_ms        BIGINT,
        waiting_tasks       BIGINT,
        max_wait_time       BIGINT,
        signal_wait_ms      BIGINT,
        CONSTRAINT PK_wait_stats PRIMARY KEY CLUSTERED (id, server_id)
    ) ON ps_server_id(server_id)");
    
    $db->exec("IF OBJECT_ID('top_queries', 'U') IS NULL
    CREATE TABLE top_queries (
        id                  INT IDENTITY(1,1) NOT NULL,
        server_id           INT NOT NULL,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        query_hash          VARCHAR(100),
        query_text          VARCHAR(MAX),
        database_name       VARCHAR(100),
        total_cpu_ms        REAL,
        total_elapsed_ms    REAL,
        total_logical_reads BIGINT,
        execution_count     BIGINT,
        avg_cpu_ms          REAL,
        avg_elapsed_ms      REAL,
        avg_logical_reads   REAL,
        missing_index_hint  VARCHAR(MAX),
        query_plan          VARCHAR(MAX),
        parameters          VARCHAR(MAX),
        CONSTRAINT PK_top_queries PRIMARY KEY CLUSTERED (id, server_id)
    ) ON ps_server_id(server_id)");
    
    $db->exec("IF OBJECT_ID('index_stats', 'U') IS NULL
    CREATE TABLE index_stats (
        id                  INT IDENTITY(1,1) NOT NULL,
        server_id           INT NOT NULL,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        database_name       NVARCHAR(100),
        table_name          NVARCHAR(100),
        index_name          NVARCHAR(100),
        index_type          NVARCHAR(50),
        user_seeks          BIGINT,
        user_scans          BIGINT,
        user_lookups        BIGINT,
        user_updates        BIGINT,
        avg_fragmentation   REAL,
        CONSTRAINT PK_index_stats PRIMARY KEY CLUSTERED (id, server_id)
    ) ON ps_server_id(server_id)");
    
    $db->exec("IF OBJECT_ID('blocking_history', 'U') IS NULL
    CREATE TABLE blocking_history (
        id              INT IDENTITY(1,1) NOT NULL,
        server_id           INT NOT NULL,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        blocked_session_id  INT,
        blocked_sql         NVARCHAR(MAX),
        blocking_session_id INT,
        blocking_sql        NVARCHAR(MAX),
        wait_time_ms        INT,
        wait_type           NVARCHAR(100),
        resource_description NVARCHAR(MAX),
        CONSTRAINT PK_blocking_history PRIMARY KEY CLUSTERED (id, server_id)
    ) ON ps_server_id(server_id)");
    
    // If legacy audit_logs exists, rename to audit_log
    $db->exec("IF OBJECT_ID('audit_logs', 'U') IS NOT NULL AND OBJECT_ID('audit_log', 'U') IS NULL
        EXEC sp_rename 'audit_logs', 'audit_log'");

    $db->exec("IF OBJECT_ID('audit_log', 'U') IS NULL
    CREATE TABLE audit_log (
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
        id              INT IDENTITY(1,1) NOT NULL,
        server_id       INT NOT NULL,
        collected_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        database_name   NVARCHAR(100),
        file_name       NVARCHAR(255),
        file_type       NVARCHAR(50),
        physical_name   NVARCHAR(500),
        total_size_mb   REAL,
        used_space_mb   REAL,
        free_space_mb   REAL,
        free_space_pct  REAL,
        CONSTRAINT PK_db_file_stats PRIMARY KEY CLUSTERED (id, server_id)
    ) ON ps_server_id(server_id)");
    
    $db->exec("IF OBJECT_ID('triggered_alerts', 'U') IS NULL
    CREATE TABLE triggered_alerts (
        id                  INT IDENTITY(1,1) NOT NULL,
        server_id           INT NOT NULL,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        alert_type          NVARCHAR(100),
        severity            NVARCHAR(50),
        message             NVARCHAR(MAX),
        email_sent          INT DEFAULT 0,
        email_error         NVARCHAR(MAX),
        CONSTRAINT PK_triggered_alerts PRIMARY KEY CLUSTERED (id, server_id)
    ) ON ps_server_id(server_id)");
    
    $db->exec("IF OBJECT_ID('deadlock_history', 'U') IS NULL
    CREATE TABLE deadlock_history (
        id                  INT IDENTITY(1,1) NOT NULL,
        server_id           INT NOT NULL,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        deadlock_time       DATETIME,
        database_name       NVARCHAR(100),
        victim_spid         INT,
        deadlock_graph      NVARCHAR(MAX),
        parsed_details      NVARCHAR(MAX),
        CONSTRAINT PK_deadlock_history PRIMARY KEY CLUSTERED (id, server_id)
    ) ON ps_server_id(server_id)");
    
    $db->exec("IF OBJECT_ID('db_backup_stats', 'U') IS NULL
    CREATE TABLE db_backup_stats (
        id                  INT IDENTITY(1,1) NOT NULL,
        server_id           INT NOT NULL,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        database_name       NVARCHAR(100),
        recovery_model      NVARCHAR(50),
        last_full_backup    DATETIME,
        full_backup_size_mb REAL,
        last_diff_backup    DATETIME,
        diff_backup_size_mb REAL,
        last_log_backup     DATETIME,
        log_backup_size_mb  REAL,
        CONSTRAINT PK_db_backup_stats PRIMARY KEY CLUSTERED (id, server_id)
    ) ON ps_server_id(server_id)");

    $db->exec("IF OBJECT_ID('agent_job_status', 'U') IS NULL
    CREATE TABLE agent_job_status (
        id                  INT IDENTITY(1,1) NOT NULL,
        server_id           INT NOT NULL,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        job_id              NVARCHAR(100),
        job_name            NVARCHAR(256),
        enabled             INT,
        description         NVARCHAR(MAX),
        current_status      NVARCHAR(100),
        last_run_time       DATETIME,
        run_duration_sec    INT,
        last_outcome_message NVARCHAR(MAX),
        CONSTRAINT PK_agent_job_status PRIMARY KEY CLUSTERED (id, server_id)
    ) ON ps_server_id(server_id)");

    $db->exec("IF OBJECT_ID('agent_job_history', 'U') IS NULL
    CREATE TABLE agent_job_history (
        id                  INT IDENTITY(1,1) NOT NULL,
        server_id           INT NOT NULL,
        collected_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        job_id              NVARCHAR(100),
        job_name            NVARCHAR(256),
        step_id             INT,
        step_name           NVARCHAR(256),
        run_status          NVARCHAR(100),
        run_time            DATETIME,
        run_duration_sec    INT,
        message             NVARCHAR(MAX),
        CONSTRAINT PK_agent_job_history PRIMARY KEY CLUSTERED (id, server_id)
    ) ON ps_server_id(server_id)");

    $db->exec("IF OBJECT_ID('alwayson_replicas', 'U') IS NULL
    CREATE TABLE alwayson_replicas (
        id                      INT IDENTITY(1,1) NOT NULL,
        server_id               INT NOT NULL,
        collected_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
        ag_name                 NVARCHAR(100),
        replica_server_name     NVARCHAR(100),
        role_desc               NVARCHAR(50),
        operational_state_desc  NVARCHAR(100),
        connected_state_desc    NVARCHAR(100),
        synchronization_health_desc NVARCHAR(100),
        CONSTRAINT PK_alwayson_replicas PRIMARY KEY CLUSTERED (id, server_id)
    ) ON ps_server_id(server_id)");

    $db->exec("IF OBJECT_ID('alwayson_databases', 'U') IS NULL
    CREATE TABLE alwayson_databases (
        id                      INT IDENTITY(1,1) NOT NULL,
        server_id               INT NOT NULL,
        collected_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
        ag_name                 NVARCHAR(100),
        database_name           NVARCHAR(100),
        synchronization_state_desc NVARCHAR(100),
        synchronization_health_desc NVARCHAR(100),
        log_send_queue_size     REAL,
        log_send_rate           REAL,
        redo_queue_size         REAL,
        redo_rate               REAL,
        CONSTRAINT PK_alwayson_databases PRIMARY KEY CLUSTERED (id, server_id)
    ) ON ps_server_id(server_id)");

    $db->exec("IF OBJECT_ID('alwayson_cluster', 'U') IS NULL
    CREATE TABLE alwayson_cluster (
        id                      INT IDENTITY(1,1) NOT NULL,
        server_id               INT NOT NULL,
        collected_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
        cluster_name            NVARCHAR(100),
        quorum_type_desc        NVARCHAR(100),
        quorum_state_desc       NVARCHAR(100),
        CONSTRAINT PK_alwayson_cluster PRIMARY KEY CLUSTERED (id, server_id)
    ) ON ps_server_id(server_id)");

    $db->exec("IF OBJECT_ID('alwayson_cluster_members', 'U') IS NULL
    CREATE TABLE alwayson_cluster_members (
        id                      INT IDENTITY(1,1) NOT NULL,
        server_id               INT NOT NULL,
        collected_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
        member_name             NVARCHAR(100),
        member_type_desc        NVARCHAR(100),
        member_state_desc       NVARCHAR(100),
        number_of_quorum_votes  INT,
        CONSTRAINT PK_alwayson_cluster_members PRIMARY KEY CLUSTERED (id, server_id)
    ) ON ps_server_id(server_id)");

    $db->exec("IF OBJECT_ID('active_session_history', 'U') IS NULL
    CREATE TABLE active_session_history (
        id                      INT IDENTITY(1,1) NOT NULL,
        server_id               INT NOT NULL,
        sample_minute           DATETIME,
        query_text              NVARCHAR(MAX),
        wait_type               NVARCHAR(100),
        samples_count           INT,
        total_wait_time_ms      BIGINT,
        CONSTRAINT PK_active_session_history PRIMARY KEY CLUSTERED (id, server_id)
    ) ON ps_server_id(server_id)");

    // Online column renaming for recommendations if needed
    $db->exec("IF OBJECT_ID('recommendations', 'U') IS NOT NULL AND COL_LENGTH('recommendations', 'generated_at') IS NULL
    BEGIN
        IF COL_LENGTH('recommendations', 'collected_at') IS NOT NULL
            EXEC sp_rename 'recommendations.collected_at', 'generated_at', 'COLUMN';
        ELSE
            ALTER TABLE recommendations ADD generated_at DATETIME DEFAULT CURRENT_TIMESTAMP;
    END");

    $db->exec("IF OBJECT_ID('recommendations', 'U') IS NULL
    CREATE TABLE recommendations (
        id              INT IDENTITY(1,1) PRIMARY KEY,
        server_id       INT REFERENCES servers(id) ON DELETE CASCADE,
        generated_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        category        NVARCHAR(100),
        severity        NVARCHAR(50),
        title           NVARCHAR(255),
        description     NVARCHAR(MAX),
        fix_script      NVARCHAR(MAX),
        is_resolved     INT DEFAULT 0,
        resolved_by     INT REFERENCES users(id) ON DELETE SET NULL,
        resolved_at     DATETIME
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

    $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'idx_agent_job_status_server_collected' AND object_id = OBJECT_ID('agent_job_status'))
        CREATE INDEX idx_agent_job_status_server_collected ON agent_job_status (server_id, collected_at)");

    $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'idx_agent_job_history_server_collected' AND object_id = OBJECT_ID('agent_job_history'))
        CREATE INDEX idx_agent_job_history_server_collected ON agent_job_history (server_id, collected_at)");
        
    $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'idx_alwayson_replicas_collected' AND object_id = OBJECT_ID('alwayson_replicas'))
        CREATE INDEX idx_alwayson_replicas_collected ON alwayson_replicas (server_id, collected_at)");

    $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'idx_alwayson_dbs_collected' AND object_id = OBJECT_ID('alwayson_databases'))
        CREATE INDEX idx_alwayson_dbs_collected ON alwayson_databases (server_id, collected_at)");

    $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'idx_alwayson_cluster_collected' AND object_id = OBJECT_ID('alwayson_cluster'))
        CREATE INDEX idx_alwayson_cluster_collected ON alwayson_cluster (server_id, collected_at)");

    $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'idx_alwayson_members_collected' AND object_id = OBJECT_ID('alwayson_cluster_members'))
        CREATE INDEX idx_alwayson_members_collected ON alwayson_cluster_members (server_id, collected_at)");
        
    $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'idx_audit_created' AND object_id = OBJECT_ID('audit_log'))
        CREATE INDEX idx_audit_created ON audit_log (created_at)");
        
    $db->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'idx_ash_server_minute' AND object_id = OBJECT_ID('active_session_history'))
        CREATE INDEX idx_ash_server_minute ON active_session_history (server_id, sample_minute)");

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

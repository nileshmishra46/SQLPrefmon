<?php
// includes/db.php

require_once dirname(__DIR__) . '/config/app.php';

function getDbConnection() {
    static $db = null;
    
    if ($db === null) {
        $dbPath = APP_DB_PATH;
        $dbDir = dirname($dbPath);
        
        // Ensure directory exists
        if (!file_exists($dbDir)) {
            mkdir($dbDir, 0755, true);
        }
        
        $dbExists = file_exists($dbPath);
        
        try {
            $db = new PDO("sqlite:" . $dbPath);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // Enable foreign key constraints
            $db->exec("PRAGMA foreign_keys = ON;");
            
            if (!$dbExists || filesize($dbPath) === 0) {
                initializeSchema($db);
            } else {
                // Online migration check to add trust_server_cert if missing
                try {
                    $db->exec("ALTER TABLE servers ADD COLUMN trust_server_cert INTEGER DEFAULT 0");
                } catch (PDOException $e) {
                    // Column already exists, safe to ignore
                }
            }
        } catch (PDOException $e) {
            die("Database Connection Error: " . $e->getMessage());
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
        missing_index_hint  TEXT
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

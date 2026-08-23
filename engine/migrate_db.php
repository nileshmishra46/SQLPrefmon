<?php
// engine/migrate_db.php
// SQLite to SQL Server Migration Utility

// Prevent timeouts
set_time_limit(0);
ini_set('memory_limit', '512M');

require_once dirname(__DIR__) . '/includes/helpers.php';

// Force load settings
$settingsPath = dirname(__DIR__) . '/config/settings.json';
if (!file_exists($settingsPath)) {
    die("Error: settings.json not found.\n");
}
$settings = json_decode(file_get_contents($settingsPath), true);

$repoHost = $settings['repo_mssql_host'] ?? 'localhost';
$repoPort = $settings['repo_mssql_port'] ?? '1433';
$repoDb = $settings['repo_mssql_db'] ?? 'PrefmonRepo';
$repoUser = $settings['repo_mssql_user'] ?? 'sa';
$repoPass = $settings['repo_mssql_pass'] ?? '';
$repoAuth = $settings['repo_mssql_auth'] ?? 'sql';
$repoTrustCert = $settings['repo_mssql_trust_cert'] ?? 1;
$repoEncrypt = $settings['repo_mssql_encrypt'] ?? 'mandatory';

echo "===========================================\n";
echo "    SQLite -> SQL Server Data Migrator     \n";
echo "===========================================\n";
echo "Source SQLite: data/sqlperf.db\n";
echo "Target MSSQL:  $repoHost:$repoPort (DB: $repoDb)\n";
echo "-------------------------------------------\n";

// 1. Connect to SQLite
$sqlitePath = dirname(__DIR__) . '/data/sqlperf.db';
if (!file_exists($sqlitePath)) {
    // Check fallback path in data/monitor.db
    $sqlitePath = dirname(__DIR__) . '/data/monitor.db';
    if (!file_exists($sqlitePath)) {
        die("Error: Source SQLite database not found at data/sqlperf.db or data/monitor.db.\n");
    }
}

try {
    $sqliteDb = new PDO("sqlite:" . $sqlitePath);
    $sqliteDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqliteDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Failed to connect to SQLite: " . $e->getMessage() . "\n");
}

// 2. Connect to MSSQL (using master first to create database)
$encryptStr = "";
if ($repoEncrypt === 'strict') {
    $encryptStr = "Encrypt=Strict;";
} elseif ($repoEncrypt === 'optional') {
    $encryptStr = "Encrypt=no;";
} else {
    $encryptStr = $repoTrustCert ? "Encrypt=yes;TrustServerCertificate=yes;" : "Encrypt=yes;TrustServerCertificate=no;";
}

$masterDsn = "odbc:Driver={ODBC Driver 18 for SQL Server};Server={$repoHost},{$repoPort};Database=master;{$encryptStr}";
$dsn = "odbc:Driver={ODBC Driver 18 for SQL Server};Server={$repoHost},{$repoPort};Database={$repoDb};{$encryptStr}";

if ($repoAuth === 'windows') {
    $masterDsn .= "Trusted_Connection=yes;ConnectionTimeout=5;";
    $dsn .= "Trusted_Connection=yes;";
    $dbUser = null;
    $dbPass = null;
} else {
    $masterDsn .= "ConnectionTimeout=5;";
    $dbUser = $repoUser;
    $dbPass = $repoPass;
}

try {
    echo "Connecting to SQL Server master database...\n";
    $masterDb = new PDO($masterDsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // Check/create database
    $check = $masterDb->prepare("SELECT is_read_committed_snapshot_on FROM sys.databases WHERE name = ?");
    $check->execute([$repoDb]);
    $rcsiVal = $check->fetchColumn();
    if ($rcsiVal === false) {
        echo "Creating database [$repoDb] on SQL Server...\n";
        $masterDb->exec("CREATE DATABASE [$repoDb]");
        try {
            $masterDb->exec("ALTER DATABASE [$repoDb] SET READ_COMMITTED_SNAPSHOT ON WITH ROLLBACK IMMEDIATE;");
        } catch (PDOException $ex) {}
    } elseif ((int)$rcsiVal === 0) {
        echo "Enabling RCSI on [$repoDb]...\n";
        try {
            $masterDb->exec("ALTER DATABASE [$repoDb] SET READ_COMMITTED_SNAPSHOT ON WITH ROLLBACK IMMEDIATE;");
        } catch (PDOException $ex) {}
    }
    $masterDb = null;
} catch (Exception $e) {
    echo "Warning: Master DB operations failed/skipped: " . $e->getMessage() . "\n";
}

try {
    echo "Connecting to SQL Server target database [$repoDb]...\n";
    $mssqlDb = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // Initialize target schema
    require_once dirname(__DIR__) . '/includes/db.php';
    echo "Initializing SQL Server database schema (including table partitioning)...\n";
    initializeMssqlSchema($mssqlDb);
    echo "Schema initialized successfully.\n\n";
} catch (Exception $e) {
    die("Failed to connect to target SQL Server database: " . $e->getMessage() . "\n");
}

// 3. Define tables to migrate (ordered to respect foreign key constraints)
$tables = [
    'users',
    'servers',
    'settings',
    'audit_log',
    'metric_snapshots',
    'wait_stats',
    'top_queries',
    'index_stats',
    'recommendations',
    'blocking_history',
    'db_file_stats',
    'triggered_alerts',
    'deadlock_history',
    'db_backup_stats',
    'agent_job_status',
    'agent_job_history',
    'alwayson_replicas',
    'alwayson_databases',
    'alwayson_cluster',
    'alwayson_cluster_members',
    'active_session_history'
];

// Temporarily disable constraint checks on SQL Server to avoid transaction order violations
try {
    $mssqlDb->exec("EXEC sp_MSforeachtable 'ALTER TABLE ? NOCHECK CONSTRAINT ALL'");
} catch (Exception $ex) {}

foreach ($tables as $tableName) {
    $hasIdentity = ($tableName !== 'settings');
    try {
        // Check if table exists in SQLite
        $tableExists = $sqliteDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$tableName'")->fetchColumn();
        if (!$tableExists) {
            echo "Skipping table [$tableName] (does not exist in source SQLite).\n";
            continue;
        }
        
        // Fetch rows from SQLite
        $rows = $sqliteDb->query("SELECT * FROM [$tableName]")->fetchAll();
        $totalRows = count($rows);
        if ($totalRows === 0) {
            echo "Table [$tableName] has 0 rows. Skipping migration.\n";
            continue;
        }
        
        echo "Migrating table [$tableName] ($totalRows rows)...\n";
        
        // Fetch target columns and metadata on MSSQL to intersect and handle schema mismatch/data types gracefully
        $colQuery = $mssqlDb->prepare("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ?");
        $colQuery->execute([$tableName]);
        $columnMetadata = $colQuery->fetchAll(PDO::FETCH_KEY_PAIR);
        
        if (empty($columnMetadata)) {
            echo "Skipping table [$tableName] (does not exist in target SQL Server).\n";
            continue;
        }
        
        $targetCols = array_keys($columnMetadata);
        
        // Get columns common to both source and target
        $commonCols = array_intersect(array_keys($rows[0]), $targetCols);
        
        // Delete existing rows on MSSQL to prevent duplicates
        $mssqlDb->exec("DELETE FROM [$tableName]");
        
        // Enable identity insert if applicable
        if ($hasIdentity) {
            $mssqlDb->exec("SET IDENTITY_INSERT [$tableName] ON");
        }
        
        // Construct insert statement
        $colList = implode(', ', array_map(function($c) { return "[$c]"; }, $commonCols));
        $placeholders = implode(', ', array_fill(0, count($commonCols), '?'));
        $insertSql = "INSERT INTO [$tableName] ($colList) VALUES ($placeholders)";
        $stmt = $mssqlDb->prepare($insertSql);
        
        // Insert rows in batches of 500
        $mssqlDb->beginTransaction();
        $batchSize = 500;
        $counter = 0;
        
        foreach ($rows as $row) {
            $paramIndex = 1;
            foreach ($commonCols as $colName) {
                $val = $row[$colName];
                $type = strtolower($columnMetadata[$colName] ?? '');
                
                $isNumeric = in_array($type, ['int', 'bigint', 'real', 'float', 'decimal', 'numeric', 'smallint', 'tinyint', 'bit']);
                $isDateTime = in_array($type, ['datetime', 'date', 'time', 'datetime2', 'smalldatetime']);
                
                if (($isNumeric || $isDateTime) && $val === '') {
                    $val = null;
                }
                
                // If the destination column is numeric but source value is a non-numeric string (e.g. 'State'), set to null
                if ($isNumeric && $val !== null && $val !== '' && !is_numeric($val)) {
                    $val = null;
                }
                
                if ($val === null) {
                    $stmt->bindValue($paramIndex, null, PDO::PARAM_NULL);
                } elseif ($type === 'nvarchar' && strlen($val) > 4000) {
                    $stmt->bindValue($paramIndex, $val, PDO::PARAM_LOB);
                } else {
                    $stmt->bindValue($paramIndex, $val, PDO::PARAM_STR);
                }
                
                $paramIndex++;
            }
            
            $stmt->execute();
            $counter++;
            
            if ($counter % $batchSize === 0) {
                $mssqlDb->commit();
                $mssqlDb->beginTransaction();
            }
        }
        
        if ($mssqlDb->inTransaction()) {
            $mssqlDb->commit();
        }
        
        echo "Successfully migrated [$tableName].\n";
        
    } catch (Exception $e) {
        if ($mssqlDb->inTransaction()) {
            $mssqlDb->rollBack();
        }
        echo "ERROR migrating table [$tableName]: " . $e->getMessage() . "\n";
    } finally {
        // Always turn off identity insert to avoid blocking subsequent tables
        if ($hasIdentity) {
            try {
                $mssqlDb->exec("SET IDENTITY_INSERT [$tableName] OFF");
            } catch (Exception $ex) {}
        }
    }
}

// Re-enable constraint checks on SQL Server
try {
    $mssqlDb->exec("EXEC sp_MSforeachtable 'ALTER TABLE ? WITH CHECK CHECK CONSTRAINT ALL'");
} catch (Exception $ex) {}

echo "\n===========================================\n";
echo "    Migration Completed Successfully!      \n";
echo "===========================================\n";

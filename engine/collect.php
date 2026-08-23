<?php
// engine/collect.php

// Ensure execution is CLI or browser manual trigger
if (php_sapi_name() !== 'cli') {
    // Enable manual trigger from admin or server detail pages
    header("Content-Type: text/plain");
}

// Set script execution limits
set_time_limit(180);
ini_set('memory_limit', '256M');

// Dynamically enable extensions if run from CLI on Windows
if (php_sapi_name() === 'cli' && !extension_loaded('pdo_sqlite')) {
    ini_set('extension', 'pdo_sqlite');
}
if (php_sapi_name() === 'cli' && !extension_loaded('openssl')) {
    ini_set('extension', 'openssl');
}
if (php_sapi_name() === 'cli' && !extension_loaded('pdo_odbc')) {
    ini_set('extension', 'pdo_odbc');
}

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once __DIR__ . '/connector.php';
require_once __DIR__ . '/dmv_queries.php';
require_once __DIR__ . '/analyzer.php';
require_once __DIR__ . '/alerts.php';

$db = getDbConnection();
$timestamp = date('Y-m-d H:i:s');
$logFile = APP_LOG_PATH;

// Ensure log folder exists
if (!file_exists(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

// Concurrency lock to prevent multiple simultaneous runs
$lockFile = dirname(__DIR__) . '/data/collector.lock';
if (!file_exists(dirname($lockFile))) {
    mkdir(dirname($lockFile), 0755, true);
}
$lockFp = fopen($lockFile, 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    if ($lockFp) {
        fclose($lockFp);
    }
    $lockMsg = "[" . date('Y-m-d H:i:s') . "] ERROR: Collector is already running in another process. Exiting to avoid collision." . PHP_EOL;
    file_put_contents($logFile, $lockMsg, FILE_APPEND);
    echo $lockMsg;
    exit(0);
}

if (!function_exists('writeLog')) {
    function writeLog($message) {
        global $logFile;
        $logMsg = "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
        file_put_contents($logFile, $logMsg, FILE_APPEND);
        echo $logMsg;
    }
}

writeLog("--- Starting Performance Metrics Collection Run ---");

// Get all active servers to monitor
try {
    $stmt = $db->query("SELECT * FROM servers WHERE is_active = 1");
    $servers = $stmt->fetchAll();
} catch (Exception $e) {
    writeLog("ERROR: Failed to fetch active servers: " . $e->getMessage());
    exit(1);
}

writeLog("Found " . count($servers) . " active server(s) to process.");

foreach ($servers as $srv) {
    $serverId = (int)$srv['id'];
    $serverName = $srv['display_name'];
    $env = $srv['environment'];
    
    writeLog("Processing server: $serverName (ID: $serverId, Env: $env)");
    
    $status = 'offline';
    $hadrRole = null;
    
    if ($env === 'demo') {
        // --- DEMO / SIMULATION MODE ---
        writeLog("Demo environment detected. Generating high-fidelity mock metrics...");
        
        // Setup transaction
        $db->beginTransaction();
        try {
            // Generate simulated metrics with random fluctuations
            $cpu = rand(15, 98); // High chance of CPU spikes
            $memTotal = 16384.0; // 16GB
            $memUsed = rand(8192, 15500); // 8-15GB
            $ple = rand(150, 750); // Under 300 triggers low PLE
            $batchRequests = rand(100, 1200);
            $sqlComp = rand(20, 180);
            $sqlRecomp = rand(5, 120); // Above 100 triggers warning
            $deadlocks = (rand(0, 100) > 85) ? rand(1, 3) : 0; // Occasional deadlocks
            $diskReadMs = rand(2, 60); // Above 20 triggers warning, above 50 critical
            $diskWriteMs = rand(1, 15);
            $activeConn = rand(30, 480);
            $blockedProcs = (rand(0, 10) > 7) ? rand(1, 8) : 0; // Occasional blocking
            $tempdbUsed = rand(200, 6000);
            
            // Insert metric snapshot
            $stmtInsert = $db->prepare("
                INSERT INTO metric_snapshots (
                    server_id, collected_at, cpu_usage_pct, memory_used_mb, memory_total_mb, 
                    page_life_exp, batch_req_sec, sql_comp_sec, sql_recomp_sec, lock_waits_sec, 
                    deadlocks_sec, disk_read_ms, disk_write_ms, active_conn, blocked_procs, tempdb_used_mb
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtInsert->execute([
                $serverId, $timestamp, $cpu, $memUsed, $memTotal, $ple, 
                $batchRequests, $sqlComp, $sqlRecomp, 
                $blockedProcs ? rand(1, 20) : 0, // lock waits
                $deadlocks, $diskReadMs, $diskWriteMs, $activeConn, $blockedProcs, $tempdbUsed
            ]);
            
            // Insert wait stats
            $waitTypes = [
                'CXPACKET' => rand(10000, 95000),
                'CXCONSUMER' => rand(8000, 75000),
                'PAGEIOLATCH_SH' => (rand(0, 10) > 6) ? rand(20000, 85000) : rand(1000, 5000),
                'LCK_M_X' => $blockedProcs ? rand(15000, 60000) : rand(0, 200),
                'ASYNC_NETWORK_IO' => rand(5000, 45000),
                'WRITELOG' => rand(2000, 18000),
                'SOS_SCHEDULER_YIELD' => rand(3000, 25000)
            ];
            
            $stmtWait = $db->prepare("
                INSERT INTO wait_stats (server_id, collected_at, wait_type, wait_time_ms, waiting_tasks, signal_wait_ms) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($waitTypes as $wType => $wTime) {
                // Signal wait is typically 10-35% of wait time
                $signalWait = round($wTime * (rand(10, 35) / 100));
                $waitingTasks = rand(10, 1500);
                $stmtWait->execute([$serverId, $timestamp, $wType, $wTime, $waitingTasks, $signalWait]);
            }
            
            $pollerEnabled = getAppSetting('poller_enabled', false);
            $captureMode = $srv['history_capture_mode'] ?? 'collector';
            if ($captureMode === 'collector' || !$pollerEnabled) {
                // Insert top expensive queries
                $mockQueries = [
                    [
                        'hash' => '0x8A2C345F',
                        'db' => 'Production_DB',
                        'text' => "SELECT s.SaleId, s.SaleDate, c.CustomerName, p.ProductName \nFROM Sales s \nINNER JOIN Customers c ON s.CustomerId = c.CustomerId \nINNER JOIN Products p ON s.ProductId = p.ProductId \nWHERE c.State = 'NY' ORDER BY s.SaleDate DESC",
                        'cpu' => rand(50000, 150000),
                        'elapsed' => rand(70000, 200000),
                        'reads' => rand(1500000, 4500000), // triggers logical reads rule
                        'execs' => rand(500, 3000),
                        'parameters' => '{"@State": "\'NY\'"}',
                        'query_plan' => '<?xml version="1.0" encoding="utf-16"?><ShowPlanXML xmlns="http://schemas.microsoft.com/sqlserver/2004/07/showplan" Version="1.5" Build="16.0.1000.6"><BatchSequence><Batch><Statements><StmtSimple StatementText="SELECT s.SaleId, s.SaleDate, c.CustomerName, p.ProductName FROM Sales s INNER JOIN Customers c ON s.CustomerId = c.CustomerId INNER JOIN Products p ON s.ProductId = p.ProductId WHERE c.State = \'NY\' ORDER BY s.SaleDate DESC" StatementId="1" StatementCompId="1" StatementType="SELECT" RetrValueSize="0" StatementOptmLevel="FULL" QueryHash="0x8A2C345F" QueryPlanHash="0x2B4A9C1D"><StatementParameters><ParameterList><ColumnReference Column="@State" ParameterCompiledValue="\'NY\'" /></ParameterList></StatementParameters><QueryPlan DegreeOfParallelism="2" MemoryGrant="1024" UsePlan="false"><RelOp NodeId="0" PhysicalOp="Sort" LogicalOp="Sort" EstimateRows="120" EstimateIO="0.012" EstimateCPU="0.085" AvgRowSize="80" EstimatedTotalSubtreeCost="0.25"><Sort Distinct="false"><OrderBy><ColumnReference Database="[Production_DB]" Schema="[dbo]" Table="[Sales]" Alias="[s]" Column="[SaleDate]" Descending="true" /></OrderBy></Sort></RelOp></QueryPlan></StmtSimple></Statements></Batch></BatchSequence></ShowPlanXML>'
                    ],
                    [
                        'hash' => '0x3F9D821A',
                        'db' => 'Production_DB',
                        'text' => "SELECT COUNT(*), OrderStatus \nFROM Orders \nGROUP BY OrderStatus \nHAVING COUNT(*) > 100",
                        'cpu' => rand(2000, 15000),
                        'elapsed' => rand(5500, 25000), // triggers slow run rule (> 5s avg)
                        'reads' => rand(20000, 80000),
                        'execs' => rand(2, 5),
                        'parameters' => null,
                        'query_plan' => '<?xml version="1.0" encoding="utf-16"?><ShowPlanXML xmlns="http://schemas.microsoft.com/sqlserver/2004/07/showplan" Version="1.5" Build="16.0.1000.6"><BatchSequence><Batch><Statements><StmtSimple StatementText="SELECT COUNT(*), OrderStatus FROM Orders GROUP BY OrderStatus HAVING COUNT(*) &gt; 100" StatementId="1" StatementCompId="1" StatementType="SELECT" QueryHash="0x3F9D821A"><QueryPlan DegreeOfParallelism="1" MemoryGrant="512"><RelOp NodeId="0" PhysicalOp="Hash Match" LogicalOp="Aggregate" EstimateRows="5" EstimateIO="0" EstimateCPU="0.005"><HashKeysBuild><ColumnReference Database="[Production_DB]" Schema="[dbo]" Table="[Orders]" Column="[OrderStatus]" /></HashKeysBuild></RelOp></QueryPlan></StmtSimple></Statements></Batch></BatchSequence></ShowPlanXML>'
                    ],
                    [
                        'hash' => '0xAB94C27D',
                        'db' => 'HR_Portal',
                        'text' => "UPDATE EmployeeProfile \nSET LastActiveDate = GETDATE() \nWHERE EmployeeId = @1",
                        'cpu' => rand(1500, 5000),
                        'elapsed' => rand(2000, 6000),
                        'reads' => rand(100, 500),
                        'execs' => rand(8000, 15000),
                        'parameters' => '{"@1": "1004"}',
                        'query_plan' => '<?xml version="1.0" encoding="utf-16"?><ShowPlanXML xmlns="http://schemas.microsoft.com/sqlserver/2004/07/showplan" Version="1.5" Build="16.0.1000.6"><BatchSequence><Batch><Statements><StmtSimple StatementText="UPDATE EmployeeProfile SET LastActiveDate = GETDATE() WHERE EmployeeId = @1" StatementId="1" StatementCompId="1" StatementType="UPDATE" QueryHash="0xAB94C27D"><StatementParameters><ParameterList><ColumnReference Column="@1" ParameterCompiledValue="1004" /></ParameterList></StatementParameters><QueryPlan DegreeOfParallelism="1" MemoryGrant="0"><RelOp NodeId="0" PhysicalOp="Clustered Index Update" LogicalOp="Update" EstimateRows="1"><Update><Object Database="[HR_Portal]" Schema="[dbo]" Table="[EmployeeProfile]" Index="[PK_EmployeeProfile]" /></Update></RelOp></QueryPlan></StmtSimple></Statements></Batch></BatchSequence></ShowPlanXML>'
                    ]
                ];
                
                $stmtQuery = $db->prepare("
                    INSERT INTO top_queries (
                        server_id, collected_at, query_hash, query_text, database_name, 
                        total_cpu_ms, total_elapsed_ms, total_logical_reads, execution_count, 
                        avg_cpu_ms, avg_elapsed_ms, avg_logical_reads, missing_index_hint,
                        query_plan, parameters
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($mockQueries as $q) {
                    $avgCpu = $q['cpu'] / $q['execs'];
                    $avgElapsed = $q['elapsed'] / $q['execs'];
                    $avgReads = $q['reads'] / $q['execs'];
                    $hint = ($q['reads'] > 1000000) ? 'CREATE NONCLUSTERED INDEX IX_Sales_Covering ON Sales (ProductId) INCLUDE (CustomerId, SaleDate)' : null;
                    
                    $stmtQuery->execute([
                        $serverId, $timestamp, $q['hash'], $q['text'], $q['db'], 
                        $q['cpu'], $q['elapsed'], $q['reads'], $q['execs'], 
                        $avgCpu, $avgElapsed, $avgReads, $hint,
                        $q['query_plan'], $q['parameters']
                    ]);
                }
            }
            
            // Insert index statistics
            $mockIndexes = [
                [
                    'db' => 'Production_DB',
                    'schema' => 'dbo',
                    'tbl' => 'Orders',
                    'name' => 'IX_Orders_OrderDate',
                    'type' => 'NONCLUSTERED',
                    'seeks' => rand(2000, 8000),
                    'scans' => rand(100, 500),
                    'lookups' => rand(500, 1500),
                    'updates' => rand(6000, 15000),
                    'frag' => rand(10, 85), // Random fragmentation (triggers index warning/info)
                    'pages' => rand(1200, 6500),
                    'issue' => 'fragmented'
                ],
                [
                    'db' => 'Production_DB',
                    'schema' => 'dbo',
                    'tbl' => 'SalesHistory',
                    'name' => 'IX_SalesHistory_Legacy',
                    'type' => 'NONCLUSTERED',
                    'seeks' => 0,
                    'scans' => 0,
                    'lookups' => 0,
                    'updates' => rand(1200, 8500),
                    'frag' => rand(1, 5),
                    'pages' => rand(800, 3200),
                    'issue' => 'unused' // triggers unused index alert
                ],
                [
                    'db' => 'HR_Portal',
                    'schema' => 'dbo',
                    'tbl' => 'EmployeeProfile',
                    'name' => 'MISSING_INDEX',
                    'type' => 'NONCLUSTERED',
                    'seeks' => 'State', // temporary storage for mock builder mapping
                    'scans' => 'ZipCode', // temporary storage
                    'lookups' => 'AddressLine1, City', // temporary storage
                    'updates' => 0,
                    'frag' => 18500, // missing index benefit score mapped here
                    'pages' => 0,
                    'issue' => 'missing' // triggers missing index alert
                ]
            ];
            
            $stmtIdx = $db->prepare("
                INSERT INTO index_stats (
                    server_id, collected_at, database_name, schema_name, table_name, 
                    index_name, index_type, user_seeks, user_scans, user_lookups, user_updates, 
                    fragmentation_pct, page_count, issue_type
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($mockIndexes as $i) {
                $stmtIdx->execute([
                    $serverId, $timestamp, $i['db'], $i['schema'], $i['tbl'], 
                    $i['name'], $i['type'], $i['seeks'], $i['scans'], $i['lookups'], $i['updates'], 
                    $i['frag'], $i['pages'], $i['issue']
                ]);
            }

            // Insert mock database file space statistics
            $mockDbFiles = [
                ['db' => 'master', 'name' => 'master', 'type' => 'ROWS', 'physical' => 'C:\SQLData\master.mdf', 'tot' => 50.0, 'used' => 38.2],
                ['db' => 'master', 'name' => 'mastlog', 'type' => 'LOG', 'physical' => 'C:\SQLLogs\mastlog.ldf', 'tot' => 20.0, 'used' => 4.5],
                ['db' => 'tempdb', 'name' => 'tempdev', 'type' => 'ROWS', 'physical' => 'D:\TempDB\tempdev.mdf', 'tot' => 1024.0, 'used' => 120.0],
                ['db' => 'tempdb', 'name' => 'templog', 'type' => 'LOG', 'physical' => 'D:\TempDB\templog.ldf', 'tot' => 512.0, 'used' => 45.0],
                ['db' => 'msdb', 'name' => 'MSDBData', 'type' => 'ROWS', 'physical' => 'C:\SQLData\MSDBData.mdf', 'tot' => 100.0, 'used' => 78.4],
                ['db' => 'msdb', 'name' => 'MSDBLog', 'type' => 'LOG', 'physical' => 'C:\SQLLogs\MSDBLog.ldf', 'tot' => 30.0, 'used' => 12.1],
                ['db' => 'Production_DB', 'name' => 'Prod_Data', 'type' => 'ROWS', 'physical' => 'E:\Data\Prod_Data.mdf', 'tot' => 25600.0, 'used' => 23552.0], // ~92% used (triggers alert!)
                ['db' => 'Production_DB', 'name' => 'Prod_Log', 'type' => 'LOG', 'physical' => 'F:\Logs\Prod_Log.ldf', 'tot' => 8192.0, 'used' => 1536.0],
                ['db' => 'HR_Portal', 'name' => 'HR_Data', 'type' => 'ROWS', 'physical' => 'E:\Data\HR_Data.mdf', 'tot' => 8192.0, 'used' => 4500.0],
                ['db' => 'HR_Portal', 'name' => 'HR_Log', 'type' => 'LOG', 'physical' => 'F:\Logs\HR_Log.ldf', 'tot' => 2048.0, 'used' => 300.0],
                ['db' => 'Reporting_DB', 'name' => 'Report_Data', 'type' => 'ROWS', 'physical' => 'E:\Data\Report_Data.mdf', 'tot' => 15360.0, 'used' => 12288.0],
                ['db' => 'Reporting_DB', 'name' => 'Report_Log', 'type' => 'LOG', 'physical' => 'F:\Logs\Report_Log.ldf', 'tot' => 4096.0, 'used' => 1024.0]
            ];
            
            $stmtDbFiles = $db->prepare("
                INSERT INTO db_file_stats (
                    server_id, collected_at, database_name, file_name, file_type, 
                    physical_name, total_size_mb, used_space_mb, free_space_mb, free_space_pct
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($mockDbFiles as $f) {
                // Add a small random fluctuation in used space (±50MB for larger, ±5MB for smaller)
                $fluct = (rand(0, 100) - 50) / 10.0; // -5 to +5
                if ($f['tot'] > 500.0) {
                    $fluct = (rand(0, 100) - 50) * 1.5; // -75 to +75MB
                }
                $used = max(1.0, min($f['tot'] - 1.0, $f['used'] + $fluct));
                $free = $f['tot'] - $used;
                $freePct = ($free / $f['tot']) * 100.0;
                
                $stmtDbFiles->execute([
                    $serverId, $timestamp, $f['db'], $f['name'], $f['type'], 
                    $f['physical'], $f['tot'], $used, $free, $freePct
                ]);
            }
            
            // Generate simulated backup statistics for DEMO
            $mockBackups = [
                ['db' => 'master', 'model' => 'SIMPLE', 'full_age' => 6, 'full_size' => 5.5, 'diff_age' => null, 'diff_size' => null, 'log_age' => null, 'log_size' => null],
                ['db' => 'msdb', 'model' => 'SIMPLE', 'full_age' => 12, 'full_size' => 15.2, 'diff_age' => null, 'diff_size' => null, 'log_age' => null, 'log_size' => null],
                ['db' => 'Production_DB', 'model' => 'FULL', 'full_age' => 8, 'full_size' => 1205.4, 'diff_age' => 4, 'diff_size' => 42.5, 'log_age' => 0.5, 'log_size' => 45.2],
                ['db' => 'Dev_DB', 'model' => 'FULL', 'full_age' => 36, 'full_size' => 245.2, 'diff_age' => 30, 'diff_size' => 12.4, 'log_age' => null, 'log_size' => null]
            ];
            
            $stmtBackupsInsert = $db->prepare("
                INSERT INTO db_backup_stats (
                    server_id, collected_at, database_name, recovery_model, 
                    last_full_backup, full_backup_size_mb, last_diff_backup, diff_backup_size_mb, last_log_backup, log_backup_size_mb
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($mockBackups as $b) {
                $fullTime = null;
                if ($b['full_age'] !== null) {
                    $fullTime = date('Y-m-d H:i:s', strtotime("-{$b['full_age']} hours"));
                }
                
                $diffTime = null;
                if ($b['diff_age'] !== null) {
                    $diffTime = date('Y-m-d H:i:s', strtotime("-{$b['diff_age']} hours"));
                }
                
                $logTime = null;
                if ($b['log_age'] !== null) {
                    $logTime = date('Y-m-d H:i:s', strtotime("-{$b['log_age']} hours"));
                }
                
                $stmtBackupsInsert->execute([
                    $serverId,
                    $timestamp,
                    $b['db'],
                    $b['model'],
                    $fullTime,
                    $b['full_size'],
                    $diffTime,
                    $b['diff_size'],
                    $logTime,
                    $b['log_size']
                ]);
            }
            
            // Generate simulated blocking events (30% probability)
            if (rand(1, 10) > 7) {
                $mockBlocks = [
                    [
                        'blocked_id' => 54,
                        'blocked_sql' => "UPDATE Inventory \nSET Quantity = Quantity - 1 \nWHERE ProductId = 104",
                        'blocking_id' => 43,
                        'blocking_sql' => "BEGIN TRANSACTION;\nUPDATE Inventory \nSET Quantity = 50 \nWHERE ProductId = 104;\n-- (Blocker idle in open transaction)",
                        'wait_time' => rand(125000, 310000), // 2.08 to 5.16 minutes
                        'wait_type' => 'LCK_M_U',
                        'resource' => 'KEY: 5:72057594043203584 (ad1092e0df8a)'
                    ],
                    [
                        'blocked_id' => 76,
                        'blocked_sql' => "SELECT SUM(TotalAmount) \nFROM Orders \nWHERE CustomerId = @1",
                        'blocking_id' => 61,
                        'blocking_sql' => "UPDATE Orders \nSET OrderStatus = 'Processing', TotalAmount = TotalAmount * 1.05 \nWHERE OrderStatus = 'Pending'",
                        'wait_time' => rand(150000, 240000), // 2.5 to 4 minutes
                        'wait_type' => 'LCK_M_S',
                        'resource' => 'OBJECT: 5:24483842:0'
                    ]
                ];
                
                $stmtCheck = $db->prepare("
                    SELECT id, collected_at, wait_time_ms 
                    FROM blocking_history 
                    WHERE server_id = ? 
                      AND blocked_session_id = ? 
                      AND blocking_session_id = ? 
                      AND blocked_sql = ? 
                      AND blocking_sql = ?
                    ORDER BY collected_at DESC LIMIT 1
                ");
                
                $stmtUpdate = $db->prepare("
                    UPDATE blocking_history 
                    SET wait_time_ms = ?, collected_at = ? 
                    WHERE id = ?
                ");
                
                $stmtBlockInsert = $db->prepare("
                    INSERT INTO blocking_history (
                        server_id, collected_at, blocked_session_id, blocked_sql, 
                        blocking_session_id, blocking_sql, wait_time_ms, wait_type, resource_description
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $blocksCount = rand(1, 2);
                $updatedCount = 0;
                $insertedCount = 0;
                
                for ($i = 0; $i < $blocksCount; $i++) {
                    $b = $mockBlocks[$i];
                    
                    $stmtCheck->execute([
                        $serverId, $b['blocked_id'], $b['blocking_id'], $b['blocked_sql'], $b['blocking_sql']
                    ]);
                    $existing = $stmtCheck->fetch();
                    
                    $isSameBlock = false;
                    if ($existing) {
                        $lastCollectedTime = strtotime($existing['collected_at']);
                        $currentTime = strtotime($timestamp);
                        $timeDiffSec = $currentTime - $lastCollectedTime;
                        if ($timeDiffSec > 0 && $timeDiffSec <= 900) {
                            $isSameBlock = true;
                        }
                    }
                    
                    if ($isSameBlock) {
                        // Increment wait time to simulate progress of block duration
                        $newWaitTime = $existing['wait_time_ms'] + rand(30000, 60000); 
                        $stmtUpdate->execute([$newWaitTime, $timestamp, $existing['id']]);
                        $updatedCount++;
                    } else {
                        $stmtBlockInsert->execute([
                            $serverId, $timestamp, $b['blocked_id'], $b['blocked_sql'],
                            $b['blocking_id'], $b['blocking_sql'], $b['wait_time'],
                            $b['wait_type'], $b['resource']
                        ]);
                        $insertedCount++;
                    }
                }
                writeLog("Populated simulated lock blocking events: $updatedCount updated, $insertedCount newly inserted.");
            }

            // Generate simulated deadlock events (20% probability)
            if (rand(1, 10) > 8) {
                $recentDlCheck = $db->prepare("SELECT COUNT(*) FROM deadlock_history WHERE server_id = ? AND deadlock_time >= datetime('now', '-5 minutes')");
                $recentDlCheck->execute([$serverId]);
                if ($recentDlCheck->fetchColumn() == 0) {
                    $mockDlTime = date('Y-m-d H:i:s');
                    $mockDlDb = "Production_DB";
                    $mockVictimSpid = 54;
                    
                    $mockDlGraph = '<deadlock victim="process54">
  <process-list>
    <process id="process54" taskpriority="0" spid="54" susername="web_user" hostname="PROD-WEB-01" clientapp="IIS Web App" currentdb="5" currentdbname="Production_DB" waittime="14820" status="suspended" transactionname="user_checkout" lasttranstarted="2026-08-15T17:50:10" lockTimeout="4294967295">
      <inputbuf>UPDATE Inventory SET Qty = Qty - 1 WHERE ProductId = 10452;</inputbuf>
    </process>
    <process id="process76" taskpriority="0" spid="76" susername="cron_worker" hostname="PROD-JOB-02" clientapp="PHP Job Scheduler" currentdb="5" currentdbname="Production_DB" waittime="12950" status="suspended" transactionname="update_orders" lasttranstarted="2026-08-15T17:50:12" lockTimeout="4294967295">
      <inputbuf>UPDATE Orders SET Status = \'Processing\' WHERE OrderId = 884021;</inputbuf>
    </process>
  </process-list>
  <resource-list>
    <keylock objectname="Production_DB.dbo.Inventory" associatedObjectId="72057594043203584" indexname="PK_Inventory" id="lockInventory" mode="X" owner-list="owner76" waiter-list="waiter54">
      <owner-list>
        <owner id="process76" mode="X" />
      </owner-list>
      <waiter-list>
        <waiter id="process54" mode="X" requestType="wait" />
      </waiter-list>
    </keylock>
    <keylock objectname="Production_DB.dbo.Orders" associatedObjectId="72057594043285623" indexname="PK_Orders" id="lockOrders" mode="X" owner-list="owner54" waiter-list="waiter76">
      <owner-list>
        <owner id="process54" mode="X" />
      </owner-list>
      <waiter-list>
        <waiter id="process76" mode="X" requestType="wait" />
      </waiter-list>
    </keylock>
  </resource-list>
</deadlock>';
                    
                    $parsedDl = [
                        'victim_id' => 'process54',
                        'processes' => [
                            [
                                'id' => 'process54',
                                'spid' => 54,
                                'hostname' => 'PROD-WEB-01',
                                'login' => 'web_user',
                                'status' => 'rolled back (victim)',
                                'sql_text' => 'UPDATE Inventory SET Qty = Qty - 1 WHERE ProductId = 10452;',
                                'waittime' => 14820,
                                'database_name' => 'Production_DB',
                                'lock_resource' => 'Production_DB.dbo.Inventory (PK_Inventory)',
                                'request_mode' => 'X',
                                'holder_spid' => 76
                            ],
                            [
                                'id' => 'process76',
                                'spid' => 76,
                                'hostname' => 'PROD-JOB-02',
                                'login' => 'cron_worker',
                                'status' => 'committed (winner)',
                                'sql_text' => 'UPDATE Orders SET Status = \'Processing\' WHERE OrderId = 884021;',
                                'waittime' => 12950,
                                'database_name' => 'Production_DB',
                                'lock_resource' => 'Production_DB.dbo.Orders (PK_Orders)',
                                'request_mode' => 'X',
                                'holder_spid' => 54
                            ]
                        ]
                    ];
                    
                    $stmtDlInsert = $db->prepare("INSERT INTO deadlock_history (server_id, collected_at, deadlock_time, database_name, victim_spid, deadlock_graph, parsed_details) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmtDlInsert->execute([
                        $serverId,
                        $timestamp,
                        $mockDlTime,
                        $mockDlDb,
                        $mockVictimSpid,
                        $mockDlGraph,
                        json_encode($parsedDl)
                    ]);
                    writeLog("Generated simulated deadlock event on server: DEMO.");
                }
            }
            
            // Generate simulated SQL Agent Jobs for DEMO
            $mockJobs = [
                [
                    'job_id' => 'DEMO-JOB-1-BACKUP',
                    'job_name' => 'Database Backup - Hourly Transaction Logs',
                    'enabled' => 1,
                    'description' => 'Executes hourly transaction log backups for the Production_DB database.',
                    'current_status' => 'Succeeded',
                    'last_run_time' => date('Y-m-d H:i:s', strtotime('-15 minutes')),
                    'run_duration_sec' => 45,
                    'last_outcome_message' => 'The job succeeded. The last step run was Step 1 (Backup Log).'
                ],
                [
                    'job_id' => 'DEMO-JOB-2-REBUILD',
                    'job_name' => 'Database Tuning - Weekly Index Rebuilds',
                    'enabled' => 1,
                    'description' => 'Performs index reorganizations and rebuilds on fragmented indexes to maintain high search performance.',
                    'current_status' => 'Running',
                    'last_run_time' => date('Y-m-d H:i:s'),
                    'run_duration_sec' => 120,
                    'last_outcome_message' => 'The job is currently running. Step 2 (Reorganize indexes) is active.'
                ],
                [
                    'job_id' => 'DEMO-JOB-3-CLEANUP',
                    'job_name' => 'System Maintenance - Purge Audit Records',
                    'enabled' => 1,
                    'description' => 'Deletes security and audit records older than 90 days from the compliance log table.',
                    'current_status' => 'Failed',
                    'last_run_time' => date('Y-m-d H:i:s', strtotime('-2 hours')),
                    'run_duration_sec' => 310,
                    'last_outcome_message' => 'The job failed. The last step run was Step 2 (Compress Logs). The job was invoked by Schedule 2 (Daily Compliance Cleanup).'
                ],
                [
                    'job_id' => 'DEMO-JOB-4-REPORT',
                    'job_name' => 'Data Sync - Daily Sales BI Report Aggregation',
                    'enabled' => 0,
                    'description' => 'Aggregates sales records into reporting datamart tables for Tableau and PowerBI dashboards.',
                    'current_status' => 'Idle/Never Run',
                    'last_run_time' => null,
                    'run_duration_sec' => null,
                    'last_outcome_message' => null
                ]
            ];

            $db->prepare("DELETE FROM agent_job_status WHERE server_id = ?")->execute([$serverId]);
            $stmtJobInsert = $db->prepare("
                INSERT INTO agent_job_status (
                    server_id, collected_at, job_id, job_name, enabled, description, 
                    current_status, last_run_time, run_duration_sec, last_outcome_message
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($mockJobs as $mj) {
                $stmtJobInsert->execute([
                    $serverId,
                    $timestamp,
                    $mj['job_id'],
                    $mj['job_name'],
                    $mj['enabled'],
                    $mj['description'],
                    $mj['current_status'],
                    $mj['last_run_time'],
                    $mj['run_duration_sec'],
                    $mj['last_outcome_message']
                ]);
            }

            // Generate simulated job history steps for DEMO
            $mockJobHist = [
                // JOB 1: Backup logs steps
                [
                    'job_id' => 'DEMO-JOB-1-BACKUP',
                    'job_name' => 'Database Backup - Hourly Transaction Logs',
                    'step_id' => 1,
                    'step_name' => 'Backup Log to Disk',
                    'run_status' => 'Succeeded',
                    'run_time' => date('Y-m-d H:i:s', strtotime('-15 minutes')),
                    'run_duration_sec' => 45,
                    'message' => "Processed 2382 pages for database 'Production_DB', file 'Prod_Log' on file 1.\nBACKUP LOG successfully processed 2382 pages in 0.452 seconds (41.134 MB/sec)."
                ],
                [
                    'job_id' => 'DEMO-JOB-1-BACKUP',
                    'job_name' => 'Database Backup - Hourly Transaction Logs',
                    'step_id' => 0, // Outcome step
                    'step_name' => '(Job Outcome)',
                    'run_status' => 'Succeeded',
                    'run_time' => date('Y-m-d H:i:s', strtotime('-15 minutes')),
                    'run_duration_sec' => 45,
                    'message' => 'The job succeeded. The last step run was Step 1 (Backup Log to Disk).'
                ],
                
                // JOB 2: Index rebuilds steps (In progress)
                [
                    'job_id' => 'DEMO-JOB-2-REBUILD',
                    'job_name' => 'Database Tuning - Weekly Index Rebuilds',
                    'step_id' => 1,
                    'step_name' => 'Rebuild high-frag clustered indexes',
                    'run_status' => 'Succeeded',
                    'run_time' => date('Y-m-d H:i:s', strtotime('-2 minutes')),
                    'run_duration_sec' => 120,
                    'message' => "Index rebuild completed for index 'PK_Orders' on table 'Orders'. Fragmentation reduced from 87% to 0.4%."
                ],
                [
                    'job_id' => 'DEMO-JOB-2-REBUILD',
                    'job_name' => 'Database Tuning - Weekly Index Rebuilds',
                    'step_id' => 2,
                    'step_name' => 'Reorganize nonclustered indexes',
                    'run_status' => 'In Progress',
                    'run_time' => date('Y-m-d H:i:s'),
                    'run_duration_sec' => 30,
                    'message' => 'Step is currently executing...'
                ],

                // JOB 3: Failed cleanup steps
                [
                    'job_id' => 'DEMO-JOB-3-CLEANUP',
                    'job_name' => 'System Maintenance - Purge Audit Records',
                    'step_id' => 1,
                    'step_name' => 'Identify old records & Delete',
                    'run_status' => 'Succeeded',
                    'run_time' => date('Y-m-d H:i:s', strtotime('-2 hours')),
                    'run_duration_sec' => 180,
                    'message' => "Deleted 450,238 records from dbo.AuditTrail successfully."
                ],
                [
                    'job_id' => 'DEMO-JOB-3-CLEANUP',
                    'job_name' => 'System Maintenance - Purge Audit Records',
                    'step_id' => 2,
                    'step_name' => 'Compress logs database',
                    'run_status' => 'Failed',
                    'run_time' => date('Y-m-d H:i:s', strtotime('-2 hours')),
                    'run_duration_sec' => 130,
                    'message' => "Error 1105: Could not allocate space for object 'tempdb.dbo.#CleanTemp' because the 'PRIMARY' filegroup is full. Create disk space by deleting unneeded files, dropping objects in the filegroup, adding additional files to the filegroup, or setting autogrow on for existing files in the filegroup."
                ],
                [
                    'job_id' => 'DEMO-JOB-3-CLEANUP',
                    'job_name' => 'System Maintenance - Purge Audit Records',
                    'step_id' => 0, // Outcome step
                    'step_name' => '(Job Outcome)',
                    'run_status' => 'Failed',
                    'run_time' => date('Y-m-d H:i:s', strtotime('-2 hours')),
                    'run_duration_sec' => 310,
                    'message' => 'The job failed. The last step run was Step 2 (Compress logs database). The job was invoked by Schedule 2 (Daily Compliance Cleanup).'
                ],
            ];

            $db->prepare("DELETE FROM agent_job_history WHERE server_id = ?")->execute([$serverId]);
            $stmtJobHistInsert = $db->prepare("
                INSERT INTO agent_job_history (
                    server_id, collected_at, job_id, job_name, step_id, step_name, 
                    run_status, run_time, run_duration_sec, message
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($mockJobHist as $mjh) {
                $stmtJobHistInsert->execute([
                    $serverId,
                    $timestamp,
                    $mjh['job_id'],
                    $mjh['job_name'],
                    $mjh['step_id'],
                    $mjh['step_name'],
                    $mjh['run_status'],
                    $mjh['run_time'],
                    $mjh['run_duration_sec'],
                    $mjh['message']
                ]);
            }
            
            // Generate simulated Always On and Cluster metrics for DEMO
            $hadrRole = ($serverId % 2 === 1) ? 'PRIMARY' : 'SECONDARY';
            
            // Delete old Always On records
            $db->prepare("DELETE FROM alwayson_replicas WHERE server_id = ?")->execute([$serverId]);
            $db->prepare("DELETE FROM alwayson_databases WHERE server_id = ?")->execute([$serverId]);
            $db->prepare("DELETE FROM alwayson_cluster WHERE server_id = ?")->execute([$serverId]);
            $db->prepare("DELETE FROM alwayson_cluster_members WHERE server_id = ?")->execute([$serverId]);
            
            // Replicas
            $mockReplicas = [
                [
                    'ag_name' => 'PROD-AG-01',
                    'replica' => 'PROD-SQL-01',
                    'role' => 'PRIMARY',
                    'op_state' => 'ONLINE',
                    'conn_state' => 'CONNECTED',
                    'health' => 'HEALTHY'
                ],
                [
                    'ag_name' => 'PROD-AG-01',
                    'replica' => 'PROD-SQL-02',
                    'role' => 'SECONDARY',
                    'op_state' => 'ONLINE',
                    'conn_state' => 'CONNECTED',
                    'health' => 'HEALTHY'
                ]
            ];
            
            $stmtRepInsert = $db->prepare("
                INSERT INTO alwayson_replicas (
                    server_id, collected_at, ag_name, replica_server_name, 
                    role_desc, operational_state_desc, connected_state_desc, synchronization_health_desc
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($mockReplicas as $mr) {
                $stmtRepInsert->execute([
                    $serverId,
                    $timestamp,
                    $mr['ag_name'],
                    $mr['replica'],
                    $mr['role'],
                    $mr['op_state'],
                    $mr['conn_state'],
                    $mr['health']
                ]);
            }
            
            // Databases
            $mockDbs = [
                [
                    'ag_name' => 'PROD-AG-01',
                    'db' => 'Production_DB',
                    'sync_state' => 'SYNCHRONIZED',
                    'sync_health' => 'HEALTHY',
                    'send_q' => ($hadrRole === 'PRIMARY' ? 0.0 : null),
                    'send_r' => ($hadrRole === 'PRIMARY' ? 145.5 : null),
                    'redo_q' => ($hadrRole === 'SECONDARY' ? 0.0 : null),
                    'redo_r' => ($hadrRole === 'SECONDARY' ? 142.2 : null)
                ],
                [
                    'ag_name' => 'PROD-AG-01',
                    'db' => 'HR_Portal',
                    'sync_state' => 'SYNCHRONIZED',
                    'sync_health' => 'HEALTHY',
                    'send_q' => ($hadrRole === 'PRIMARY' ? 0.0 : null),
                    'send_r' => ($hadrRole === 'PRIMARY' ? 24.8 : null),
                    'redo_q' => ($hadrRole === 'SECONDARY' ? 0.0 : null),
                    'redo_r' => ($hadrRole === 'SECONDARY' ? 24.5 : null)
                ]
            ];
            $stmtDbInsert = $db->prepare("
                INSERT INTO alwayson_databases (
                    server_id, collected_at, ag_name, database_name, 
                    synchronization_state_desc, synchronization_health_desc,
                    log_send_queue_size, log_send_rate, redo_queue_size, redo_rate
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($mockDbs as $md) {
                $stmtDbInsert->execute([
                    $serverId,
                    $timestamp,
                    $md['ag_name'],
                    $md['db'],
                    $md['sync_state'],
                    $md['sync_health'],
                    $md['send_q'],
                    $md['send_r'],
                    $md['redo_q'],
                    $md['redo_r']
                ]);
            }
            
            // Cluster
            $stmtClustInsert = $db->prepare("
                INSERT INTO alwayson_cluster (
                    server_id, collected_at, cluster_name, quorum_type_desc, quorum_state_desc
                ) VALUES (?, ?, ?, ?, ?)
            ");
            $stmtClustInsert->execute([
                $serverId,
                $timestamp,
                'PROD-WSFC-01',
                'Node and Disk Majority',
                'Normal Quorum'
            ]);
            
            // Members
            $mockMembers = [
                ['name' => 'PROD-SQL-01', 'type' => 'Cluster Node', 'state' => 'Online', 'votes' => 1],
                ['name' => 'PROD-SQL-02', 'type' => 'Cluster Node', 'state' => 'Online', 'votes' => 1],
                ['name' => 'Q-Disk-01', 'type' => 'Disk Witness', 'state' => 'Online', 'votes' => 1]
            ];
            $stmtMemInsert = $db->prepare("
                INSERT INTO alwayson_cluster_members (
                    server_id, collected_at, member_name, member_type_desc, member_state_desc, number_of_quorum_votes
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($mockMembers as $mm) {
                $stmtMemInsert->execute([
                    $serverId,
                    $timestamp,
                    $mm['name'],
                    $mm['type'],
                    $mm['state'],
                    $mm['votes']
                ]);
            }
            
            $db->commit();
            $status = 'online';
            writeLog("Successfully populated simulated metrics.");
        } catch (Exception $ex) {
            $db->rollBack();
            $status = 'error';
            writeLog("ERROR in Demo transaction: " . $ex->getMessage());
        }
        
    } else {
        // --- REAL SQL SERVER DMV METRICS COLLECTION ---
        writeLog("Attempting real connection to target server...");
        
        $decryptedPass = decryptPassword($srv['password']);
        
        try {
            $conn = getSqlServerConnection($srv['hostname'], $srv['port'], $srv['instance_name'], $srv['username'], $decryptedPass, (bool)($srv['trust_server_cert'] ?? false));
            writeLog("Connection established. Querying DMVs...");
            
            $db->beginTransaction();
            
            // 0. Fetch Availability Group Role (hadr_role)
            $hadrRoleStmt = null;
            try {
                $hadrRoleStmt = $conn->query(SQL_QUERY_HADR_ROLE);
                $hadrRow = $hadrRoleStmt->fetch();
                $hadrRole = $hadrRow['role_desc'] ?? null;
            } catch (Exception $e) {
                writeLog("WARN: Failed to query local Hadron AG role: " . $e->getMessage());
            } finally {
                if ($hadrRoleStmt) {
                    $hadrRoleStmt->closeCursor();
                }
            }
            
            // 1. Fetch CPU Usage
            $cpuPct = 0.0;
            $cpuStmt = null;
            try {
                $cpuStmt = $conn->query(SQL_QUERY_CPU);
                $cpuRow = $cpuStmt->fetch();
                $cpuPct = isset($cpuRow['cpu_usage_pct']) ? (float)$cpuRow['cpu_usage_pct'] : 0.0;
            } catch (Exception $e) {
                writeLog("WARN: Failed to query CPU DMV, using 0: " . $e->getMessage());
            } finally {
                if ($cpuStmt) {
                    $cpuStmt->closeCursor();
                }
            }
            
            // 2. Fetch Memory Statistics
            $memUsed = 0.0; $memTotal = 0.0; $ple = 600;
            $memStmt = null;
            try {
                $memStmt = $conn->query(SQL_QUERY_MEMORY);
                $memRow = $memStmt->fetch();
                $memUsed = (float)($memRow['memory_used_mb'] ?? 0);
                $memTotal = (float)($memRow['memory_total_mb'] ?? 0);
                $ple = (int)($memRow['page_life_exp'] ?? 600);
            } catch (Exception $e) {
                writeLog("WARN: Failed to query Memory DMVs: " . $e->getMessage());
            } finally {
                if ($memStmt) {
                    $memStmt->closeCursor();
                }
            }
            
            // 3. Fetch Throughput Stats & Compute Rates
            $batchReq = 0.0; $sqlComp = 0.0; $sqlRecomp = 0.0; $deadlocks = 0.0;
            $perfStmt = null;
            try {
                $perfStmt = $conn->query(SQL_QUERY_PERF_COUNTERS);
                $counters = $perfStmt->fetchAll();
                
                foreach ($counters as $c) {
                    $cVal = (float)$c['cntr_value'];
                    if ($c['counter_name'] === 'Batch Requests/sec') {
                        $batchReq = $cVal;
                    } elseif ($c['counter_name'] === 'SQL Compilations/sec') {
                        $sqlComp = $cVal;
                    } elseif ($c['counter_name'] === 'SQL Re-compilations/sec') {
                        $sqlRecomp = $cVal;
                    } elseif ($c['counter_name'] === 'Deadlocks/sec') {
                        $deadlocks = $cVal;
                    }
                }
            } catch (Exception $e) {
                writeLog("WARN: Failed to query performance counters: " . $e->getMessage());
            } finally {
                if ($perfStmt) {
                    $perfStmt->closeCursor();
                }
            }
            
            // 4. Fetch Latencies & Locks
            $lockWaits = 0; $activeConn = 0; $blockedProcs = 0; $tempdbUsed = 0.0; $diskReadMs = 0.0; $diskWriteMs = 0.0;
            $latStmt = null;
            try {
                $latStmt = $conn->query(SQL_QUERY_LATENCY_LOCKS);
                $latRow = $latStmt->fetch();
                if ($latRow) {
                    $activeConn = (int)($latRow['active_conn'] ?? 0);
                    $blockedProcs = (int)($latRow['blocked_procs'] ?? 0);
                    $tempdbUsed = (float)($latRow['tempdb_used_mb'] ?? 0);
                    
                    // Latency calculate
                    $reads = (float)($latRow['num_reads'] ?? 0);
                    $stallReads = (float)($latRow['stall_reads_ms'] ?? 0);
                    $diskReadMs = ($reads > 0) ? $stallReads / $reads : 0.0;
                    
                    $writes = (float)($latRow['num_writes'] ?? 0);
                    $stallWrites = (float)($latRow['stall_writes_ms'] ?? 0);
                    $diskWriteMs = ($writes > 0) ? $stallWrites / $writes : 0.0;
                    
                    $lockWaits = (float)($latRow['lock_waits_sec'] ?? 0);
                }
            } catch (Exception $e) {
                writeLog("WARN: Failed to query latency / locks: " . $e->getMessage());
            } finally {
                if ($latStmt) {
                    $latStmt->closeCursor();
                }
            }
            
            // Save metric snapshot to SQLite
            $stmtInsert = $db->prepare("
                INSERT INTO metric_snapshots (
                    server_id, collected_at, cpu_usage_pct, memory_used_mb, memory_total_mb, 
                    page_life_exp, batch_req_sec, sql_comp_sec, sql_recomp_sec, lock_waits_sec, 
                    deadlocks_sec, disk_read_ms, disk_write_ms, active_conn, blocked_procs, tempdb_used_mb
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtInsert->execute([
                $serverId, $timestamp, $cpuPct, $memUsed, $memTotal, $ple, 
                $batchReq, $sqlComp, $sqlRecomp, $lockWaits, $deadlocks, 
                $diskReadMs, $diskWriteMs, $activeConn, $blockedProcs, $tempdbUsed
            ]);
            
            // 5. Query Wait Stats
            $waitStmt = null;
            try {
                $waitStmt = $conn->query(SQL_QUERY_WAITS);
                $waitsList = $waitStmt->fetchAll();
                
                $stmtWait = $db->prepare("
                    INSERT INTO wait_stats (server_id, collected_at, wait_type, wait_time_ms, waiting_tasks, signal_wait_ms) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                foreach ($waitsList as $w) {
                    $stmtWait->execute([
                        $serverId, $timestamp, $w['wait_type'], (float)$w['wait_time_ms'], 
                        (int)$w['waiting_tasks'], (float)$w['signal_wait_ms']
                    ]);
                }
            } catch (Exception $e) {
                writeLog("WARN: Failed to collect Wait Stats: " . $e->getMessage());
            } finally {
                if ($waitStmt) {
                    $waitStmt->closeCursor();
                }
            }
            
            
            $pollerEnabled = getAppSetting('poller_enabled', false);
            $captureMode = $srv['history_capture_mode'] ?? 'collector';
            if ($captureMode === 'collector' || !$pollerEnabled) {
                // 6. Query Top Queries
                $qStmt = null;
                try {
                    $qStmt = $conn->query(SQL_QUERY_TOP_QUERIES);
                    $qList = $qStmt->fetchAll();
                    
                    $stmtQ = $db->prepare("
                        INSERT INTO top_queries (
                            server_id, collected_at, query_hash, query_text, database_name, 
                            total_cpu_ms, total_elapsed_ms, total_logical_reads, execution_count, 
                            avg_cpu_ms, avg_elapsed_ms, avg_logical_reads, missing_index_hint,
                            query_plan, parameters
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($qList as $q) {
                        $extractedParams = null;
                        if (!empty($q['query_plan'])) {
                            $extractedParams = extractParametersFromPlan($q['query_plan']);
                        }
                        
                        $stmtQ->execute([
                            $serverId, $timestamp, $q['query_hash'], $q['query_text'], $q['database_name'] ?? 'master',
                            $q['total_cpu_ms'], $q['total_elapsed_ms'], $q['total_logical_reads'], $q['execution_count'],
                            $q['avg_cpu_ms'], $q['avg_elapsed_ms'], $q['avg_logical_reads'], null,
                            $q['query_plan'] ?? null, $extractedParams
                        ]);
                    }
                } catch (Exception $e) {
                    writeLog("WARN: Failed to collect top queries: " . $e->getMessage());
                } finally {
                    if ($qStmt) {
                        $qStmt->closeCursor();
                    }
                }

                // 6b. Query blocking history
                $blockStmt = null;
                try {
                    writeLog("Checking for active blocked sessions...");
                    $blockingThresholdMin = getAppSetting('blocking_threshold_min', THRESHOLD_BLOCKING_THRESHOLD_MIN);
                    $blockingThresholdMs = $blockingThresholdMin * 60 * 1000;
                    
                    $blockStmt = $conn->prepare(SQL_QUERY_BLOCKING);
                    $blockStmt->bindValue(1, $blockingThresholdMs, PDO::PARAM_INT);
                    $blockStmt->execute();
                    $blockedSessions = $blockStmt->fetchAll();
                    
                    if (!empty($blockedSessions)) {
                        writeLog("Found " . count($blockedSessions) . " blocked session(s) active for longer than $blockingThresholdMin min.");
                        
                        $stmtCheck = $db->prepare("
                            SELECT id, collected_at, wait_time_ms 
                            FROM blocking_history 
                            WHERE server_id = ? 
                              AND blocked_session_id = ? 
                              AND blocking_session_id = ? 
                              AND blocked_sql = ? 
                              AND blocking_sql = ?
                            ORDER BY collected_at DESC LIMIT 1
                        ");
                        
                        $stmtUpdate = $db->prepare("
                            UPDATE blocking_history 
                            SET wait_time_ms = ?, collected_at = ? 
                            WHERE id = ?
                        ");
                        
                        $stmtInsert = $db->prepare("
                            INSERT INTO blocking_history (
                                server_id, collected_at, blocked_session_id, blocked_sql, 
                                blocking_session_id, blocking_sql, wait_time_ms, wait_type, resource_description
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        $updatedCount = 0;
                        $insertedCount = 0;
                        
                        foreach ($blockedSessions as $bs) {
                            $stmtCheck->execute([
                                $serverId, 
                                $bs['blocked_session_id'], 
                                $bs['blocking_session_id'], 
                                $bs['blocked_sql'], 
                                $bs['blocking_sql']
                            ]);
                            $existing = $stmtCheck->fetch();
                            
                            $isSameBlock = false;
                            if ($existing) {
                                $lastCollectedTime = strtotime($existing['collected_at']);
                                $currentTime = strtotime($timestamp);
                                $timeDiffSec = $currentTime - $lastCollectedTime;
                                
                                // If last captured block was within 15 minutes, treat it as the same active block
                                if ($timeDiffSec > 0 && $timeDiffSec <= 900) {
                                    $isSameBlock = true;
                                }
                            }
                            
                            if ($isSameBlock) {
                                $stmtUpdate->execute([$bs['wait_time_ms'], $timestamp, $existing['id']]);
                                $updatedCount++;
                            } else {
                                $stmtInsert->execute([
                                    $serverId, $timestamp, $bs['blocked_session_id'], $bs['blocked_sql'],
                                    $bs['blocking_session_id'], $bs['blocking_sql'], $bs['wait_time_ms'],
                                    $bs['wait_type'], $bs['resource_description']
                                ]);
                                $insertedCount++;
                            }
                        }
                        writeLog("Blocking tracking complete: $updatedCount updated, $insertedCount newly inserted.");
                    } else {
                        writeLog("No long-running blocked sessions found.");
                    }
                } catch (Exception $e) {
                    writeLog("WARN: Failed to collect blocking details: " . $e->getMessage());
                } finally {
                    if ($blockStmt) {
                        $blockStmt->closeCursor();
                    }
                }
            } else {
                writeLog("Skipping Top Queries and Blocking collection (handled by ASH).");
            }
            
            // 7. Query Global Missing Indexes
            $idxStmt = null;
            try {
                $idxStmt = $conn->query(SQL_QUERY_MISSING_INDEXES);
                $idxList = $idxStmt->fetchAll();
                
                $stmtIdx = $db->prepare("
                    INSERT INTO index_stats (
                        server_id, collected_at, database_name, schema_name, table_name, 
                        index_name, index_type, user_seeks, user_scans, user_lookups, user_updates, 
                        fragmentation_pct, page_count, issue_type
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($idxList as $idx) {
                    $stmtIdx->execute([
                        $serverId, $timestamp, $idx['database_name'], $idx['schema_name'], $idx['table_name'],
                        $idx['index_name'], $idx['index_type'], $idx['equality_columns'], $idx['inequality_columns'], 
                        $idx['included_columns'], 0, $idx['index_benefit_score'], 0, $idx['issue_type']
                    ]);
                }
            } catch (Exception $e) {
                writeLog("WARN: Failed to collect missing indexes: " . $e->getMessage());
            } finally {
                if ($idxStmt) {
                    $idxStmt->closeCursor();
                }
            }
            
            // 8. Query Database list and fetch index fragmentation / unused indexes
            $dbStmt = null;
            try {
                $dbStmt = $conn->query(SQL_QUERY_DATABASES);
                $databases = $dbStmt->fetchAll();
                
                $stmtFrag = $db->prepare("
                    INSERT INTO index_stats (
                        server_id, collected_at, database_name, schema_name, table_name, 
                        index_name, index_type, user_seeks, user_scans, user_lookups, user_updates, 
                        fragmentation_pct, page_count, issue_type
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                foreach ($databases as $dRow) {
                    $dName = $dRow['name'];
                    // Query fragmented indexes for this specific DB
                    $fragSql = "
                        USE [$dName];
                        SELECT TOP 5
                            DB_NAME() as database_name,
                            OBJECT_SCHEMA_NAME(ips.object_id) AS schema_name,
                            OBJECT_NAME(ips.object_id) AS table_name,
                            i.name AS index_name,
                            i.type_desc AS index_type,
                            ips.avg_fragmentation_in_percent AS fragmentation_pct,
                            ips.page_count
                        FROM sys.dm_db_index_physical_stats(DB_ID(), NULL, NULL, NULL, 'LIMITED') ips
                        INNER JOIN sys.indexes i ON ips.object_id = i.object_id AND ips.index_id = i.index_id
                        WHERE ips.avg_fragmentation_in_percent > 15 AND ips.page_count > 100
                        ORDER BY ips.avg_fragmentation_in_percent DESC;
                    ";
                    
                    $fStmt = null;
                    try {
                        $fStmt = $conn->query($fragSql);
                        $fList = $fStmt->fetchAll();
                        foreach ($fList as $f) {
                            $stmtFrag->execute([
                                $serverId, $timestamp, $f['database_name'], $f['schema_name'], $f['table_name'],
                                $f['index_name'], $f['index_type'], 0, 0, 0, 0,
                                $f['fragmentation_pct'], $f['page_count'], 'fragmented'
                            ]);
                        }
                    } catch (Exception $ex) {
                        // Database context switch error or permission error
                    } finally {
                        if ($fStmt) {
                            $fStmt->closeCursor();
                        }
                    }
                }
            } catch (Exception $e) {
                writeLog("WARN: Failed to query database index details: " . $e->getMessage());
            } finally {
                if ($dbStmt) {
                    $dbStmt->closeCursor();
                }
            }

            // 8b. Query Database File Spaces (MDF and LDF)
            $dfStmt = null;
            try {
                writeLog("Collecting Database MDF & LDF file metrics...");
                $dfStmt = $conn->query(SQL_QUERY_DB_FILES);
                $dfList = [];
                do {
                    if ($dfStmt->columnCount() > 0) {
                        $dfList = $dfStmt->fetchAll();
                        if (!empty($dfList)) {
                            break;
                        }
                    }
                } while ($dfStmt->nextRowset());
                
                $stmtDf = $db->prepare("
                    INSERT INTO db_file_stats (
                        server_id, collected_at, database_name, file_name, file_type, 
                        physical_name, total_size_mb, used_space_mb, free_space_mb, free_space_pct
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                foreach ($dfList as $df) {
                    $stmtDf->execute([
                        $serverId, $timestamp, $df['database_name'], $df['file_name'], $df['file_type'],
                        $df['physical_name'], (float)$df['total_size_mb'], (float)$df['used_space_mb'], 
                        (float)$df['free_space_mb'], (float)$df['free_space_pct']
                    ]);
                }
                writeLog("Successfully saved space metrics for " . count($dfList) . " database file(s).");
            } catch (Exception $e) {
                writeLog("WARN: Failed to collect DB File metrics: " . $e->getMessage());
            } finally {
                if ($dfStmt) {
                    $dfStmt->closeCursor();
                }
            }
            // 8c. Query Database Deadlock Graphs (Extended Events)
            $dlStmt = null;
            try {
                writeLog("Checking Extended Events ring buffer for new SQL deadlocks...");
                $dlStmt = $conn->query(SQL_QUERY_DEADLOCKS);
                $dlList = [];
                do {
                    if ($dlStmt->columnCount() > 0) {
                        $dlList = $dlStmt->fetchAll();
                        if (!empty($dlList)) {
                            break;
                        }
                    }
                } while ($dlStmt->nextRowset());
                
                if (!empty($dlList)) {
                    $stmtDlCheck = $db->prepare("SELECT COUNT(*) FROM deadlock_history WHERE server_id = ? AND deadlock_time = ?");
                    $stmtDlInsert = $db->prepare("
                        INSERT INTO deadlock_history (
                            server_id, collected_at, deadlock_time, database_name, victim_spid, deadlock_graph, parsed_details
                        ) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $newDlCount = 0;
                    foreach ($dlList as $dl) {
                        $dlTime = $dl['deadlock_time'];
                        $stmtDlCheck->execute([$serverId, $dlTime]);
                        if ($stmtDlCheck->fetchColumn() == 0) {
                            $dlGraph = $dl['deadlock_graph'];
                            $parsed = parseDeadlockXml($dlGraph);
                            
                            $dbName = 'Unknown';
                            $victimSpid = 0;
                            if ($parsed) {
                                foreach ($parsed['processes'] as $p) {
                                    if (!empty($p['database_name']) && $p['database_name'] !== 'Unknown') {
                                        $dbName = $p['database_name'];
                                    }
                                    if ($p['id'] === $parsed['victim_id']) {
                                        $victimSpid = $p['spid'];
                                    }
                                }
                            }
                            
                            $stmtDlInsert->execute([
                                $serverId,
                                $timestamp,
                                $dlTime,
                                $dbName,
                                $victimSpid,
                                $dlGraph,
                                $parsed ? json_encode($parsed) : null
                            ]);
                            $newDlCount++;
                        }
                    }
                    if ($newDlCount > 0) {
                        writeLog("Successfully saved $newDlCount newly detected deadlock report(s).");
                    }
                }
            } catch (Exception $e) {
                writeLog("WARN: Failed to check deadlock Extended Events ring buffer: " . $e->getMessage());
            } finally {
                if ($dlStmt) {
                    $dlStmt->closeCursor();
                }
            }
            // 8d. Query Database Backup Statistics
            $backupsStmt = null;
            try {
                writeLog("Querying database backup history...");
                $backupsStmt = $conn->query(SQL_QUERY_BACKUPS);
                $backupsList = [];
                do {
                    if ($backupsStmt->columnCount() > 0) {
                        $backupsList = $backupsStmt->fetchAll();
                        if (!empty($backupsList)) {
                            break;
                        }
                    }
                } while ($backupsStmt->nextRowset());
                
                if (!empty($backupsList)) {
                    $stmtBInsert = $db->prepare("
                        INSERT INTO db_backup_stats (
                            server_id, collected_at, database_name, recovery_model, 
                            last_full_backup, full_backup_size_mb, last_diff_backup, diff_backup_size_mb, last_log_backup, log_backup_size_mb
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    foreach ($backupsList as $b) {
                        $stmtBInsert->execute([
                            $serverId,
                            $timestamp,
                            $b['database_name'],
                            $b['recovery_model'],
                            $b['full_backup_time'],
                            $b['full_backup_size_mb'] !== null ? (float)$b['full_backup_size_mb'] : null,
                            $b['diff_backup_time'],
                            $b['diff_backup_size_mb'] !== null ? (float)$b['diff_backup_size_mb'] : null,
                            $b['log_backup_time'],
                            $b['log_backup_size_mb'] !== null ? (float)$b['log_backup_size_mb'] : null
                        ]);
                    }
                    writeLog("Successfully saved backup statistics for " . count($backupsList) . " database(s).");
                }
            } catch (Exception $e) {
                writeLog("WARN: Failed to query database backup history: " . $e->getMessage());
            } finally {
                if ($backupsStmt) {
                    $backupsStmt->closeCursor();
                }
            }
            
            // 8e. Query SQL Server Agent Job Status
            $jobsStmt = null;
            try {
                writeLog("Querying SQL Server Agent jobs...");
                $jobsStmt = $conn->query(SQL_QUERY_AGENT_JOBS);
                $jobsList = [];
                do {
                    if ($jobsStmt && $jobsStmt->columnCount() > 0) {
                        $jobsList = $jobsStmt->fetchAll();
                        if (!empty($jobsList)) {
                            break;
                        }
                    }
                } while ($jobsStmt && $jobsStmt->nextRowset());
                
                if (!empty($jobsList)) {
                    $db->prepare("DELETE FROM agent_job_status WHERE server_id = ?")->execute([$serverId]);
                    
                    $stmtJobInsert = $db->prepare("
                        INSERT INTO agent_job_status (
                            server_id, collected_at, job_id, job_name, enabled, description, 
                            current_status, last_run_time, run_duration_sec, last_outcome_message
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    foreach ($jobsList as $j) {
                        $stmtJobInsert->execute([
                            $serverId,
                            $timestamp,
                            $j['job_id'],
                            $j['job_name'],
                            (int)$j['enabled'],
                            $j['description'],
                            $j['current_status'],
                            $j['last_run_time'],
                            $j['run_duration_sec'] !== null ? (int)$j['run_duration_sec'] : null,
                            $j['last_outcome_message']
                        ]);
                    }
                    writeLog("Successfully saved job status for " . count($jobsList) . " job(s).");
                }
            } catch (Exception $e) {
                writeLog("WARN: Failed to query SQL Server Agent jobs: " . $e->getMessage());
            } finally {
                if ($jobsStmt) {
                    $jobsStmt->closeCursor();
                }
            }
            
            // 8f. Query SQL Server Agent Job Step History
            $jobHistStmt = null;
            try {
                writeLog("Querying SQL Server Agent job step history...");
                $jobHistStmt = $conn->query(SQL_QUERY_AGENT_JOB_HISTORY);
                $jobHistList = [];
                do {
                    if ($jobHistStmt && $jobHistStmt->columnCount() > 0) {
                        $jobHistList = $jobHistStmt->fetchAll();
                        if (!empty($jobHistList)) {
                            break;
                        }
                    }
                } while ($jobHistStmt && $jobHistStmt->nextRowset());
                
                if (!empty($jobHistList)) {
                    $db->prepare("DELETE FROM agent_job_history WHERE server_id = ?")->execute([$serverId]);
                    
                    $stmtJobHistInsert = $db->prepare("
                        INSERT INTO agent_job_history (
                            server_id, collected_at, job_id, job_name, step_id, step_name, 
                            run_status, run_time, run_duration_sec, message
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    foreach ($jobHistList as $jh) {
                        $stmtJobHistInsert->execute([
                            $serverId,
                            $timestamp,
                            $jh['job_id'],
                            $jh['job_name'],
                            (int)$jh['step_id'],
                            $jh['step_name'],
                            $jh['run_status'],
                            $jh['run_time'],
                            $jh['run_duration_sec'] !== null ? (int)$jh['run_duration_sec'] : null,
                            $jh['message']
                        ]);
                    }
                    writeLog("Successfully saved job step history for " . count($jobHistList) . " records.");
                }
            } catch (Exception $e) {
                writeLog("WARN: Failed to query SQL Server Agent job step history: " . $e->getMessage());
            } finally {
                if ($jobHistStmt) {
                    $jobHistStmt->closeCursor();
                }
            }
            
            // 8g. Query Always On status details (if Hadr role is not null)
            if ($hadrRole !== null) {
                writeLog("Always On Availability Group detected. Querying replica and cluster health...");
                
                // Delete previous entries
                $db->prepare("DELETE FROM alwayson_replicas WHERE server_id = ?")->execute([$serverId]);
                $db->prepare("DELETE FROM alwayson_databases WHERE server_id = ?")->execute([$serverId]);
                $db->prepare("DELETE FROM alwayson_cluster WHERE server_id = ?")->execute([$serverId]);
                $db->prepare("DELETE FROM alwayson_cluster_members WHERE server_id = ?")->execute([$serverId]);
                
                // Replicas
                $repStmt = null;
                try {
                    $repStmt = $conn->query(SQL_QUERY_HADR_REPLICAS);
                    $repList = $repStmt->fetchAll();
                    $stmtRepInsert = $db->prepare("
                        INSERT INTO alwayson_replicas (
                            server_id, collected_at, ag_name, replica_server_name, 
                            role_desc, operational_state_desc, connected_state_desc, synchronization_health_desc
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($repList as $r) {
                        $stmtRepInsert->execute([
                            $serverId, $timestamp, $r['ag_name'], $r['replica_server_name'],
                            $r['role_desc'], $r['operational_state_desc'], $r['connected_state_desc'], $r['synchronization_health_desc']
                        ]);
                    }
                    writeLog("Saved " . count($repList) . " AG replica status records.");
                } catch (Exception $e) {
                    writeLog("WARN: Failed to query AG Replicas: " . $e->getMessage());
                } finally {
                    if ($repStmt) $repStmt->closeCursor();
                }
                
                // Databases
                $dbHadrStmt = null;
                try {
                    $dbHadrStmt = $conn->query(SQL_QUERY_HADR_DATABASES);
                    $dbHadrList = $dbHadrStmt->fetchAll();
                    $stmtDbInsert = $db->prepare("
                        INSERT INTO alwayson_databases (
                            server_id, collected_at, ag_name, database_name, 
                            synchronization_state_desc, synchronization_health_desc,
                            log_send_queue_size, log_send_rate, redo_queue_size, redo_rate
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($dbHadrList as $d) {
                        $stmtDbInsert->execute([
                            $serverId, $timestamp, $d['ag_name'], $d['database_name'],
                            $d['synchronization_state_desc'], $d['synchronization_health_desc'],
                            $d['log_send_queue_size'], $d['log_send_rate'], $d['redo_queue_size'], $d['redo_rate']
                        ]);
                    }
                    writeLog("Saved " . count($dbHadrList) . " AG database sync status records.");
                } catch (Exception $e) {
                    writeLog("WARN: Failed to query AG Databases: " . $e->getMessage());
                } finally {
                    if ($dbHadrStmt) $dbHadrStmt->closeCursor();
                }
                
                // Cluster
                $clusterStmt = null;
                try {
                    $clusterStmt = $conn->query(SQL_QUERY_HADR_CLUSTER);
                    $clusterList = $clusterStmt->fetchAll();
                    $stmtClustInsert = $db->prepare("
                        INSERT INTO alwayson_cluster (
                            server_id, collected_at, cluster_name, quorum_type_desc, quorum_state_desc
                        ) VALUES (?, ?, ?, ?, ?)
                    ");
                    foreach ($clusterList as $c) {
                        $stmtClustInsert->execute([
                            $serverId, $timestamp, $c['cluster_name'], $c['quorum_type_desc'], $c['quorum_state_desc']
                        ]);
                    }
                } catch (Exception $e) {
                    writeLog("WARN: Failed to query WSFC Cluster Info: " . $e->getMessage());
                } finally {
                    if ($clusterStmt) $clusterStmt->closeCursor();
                }
                
                // Members
                $membersStmt = null;
                try {
                    $membersStmt = $conn->query(SQL_QUERY_HADR_CLUSTER_MEMBERS);
                    $membersList = $membersStmt->fetchAll();
                    $stmtMemInsert = $db->prepare("
                        INSERT INTO alwayson_cluster_members (
                            server_id, collected_at, member_name, member_type_desc, member_state_desc, number_of_quorum_votes
                        ) VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($membersList as $m) {
                        $stmtMemInsert->execute([
                            $serverId, $timestamp, $m['member_name'], $m['member_type_desc'], $m['member_state_desc'], $m['number_of_quorum_votes']
                        ]);
                    }
                    writeLog("Saved " . count($membersList) . " WSFC cluster member records.");
                } catch (Exception $e) {
                    writeLog("WARN: Failed to query WSFC Members: " . $e->getMessage());
                } finally {
                    if ($membersStmt) $membersStmt->closeCursor();
                }
            } else {
                // Delete any stale AG / WSFC status records if Always On is not configured or disabled
                $db->prepare("DELETE FROM alwayson_replicas WHERE server_id = ?")->execute([$serverId]);
                $db->prepare("DELETE FROM alwayson_databases WHERE server_id = ?")->execute([$serverId]);
                $db->prepare("DELETE FROM alwayson_cluster WHERE server_id = ?")->execute([$serverId]);
                $db->prepare("DELETE FROM alwayson_cluster_members WHERE server_id = ?")->execute([$serverId]);
            }
            
            $db->commit();
            $status = 'online';
            writeLog("Live collection run completed successfully.");
            
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $status = 'error';
            writeLog("ERROR on SQL Server query connection: " . $e->getMessage());
        }
    }
    
    // 9. Update server health checks status in inventory
    try {
        $update = $db->prepare("UPDATE servers SET last_checked = ?, last_status = ?, hadr_role = ? WHERE id = ?");
        $update->execute([$timestamp, $status, $hadrRole, $serverId]);
    } catch (Exception $e) {
        writeLog("ERROR: Failed to update server status: " . $e->getMessage());
    }
    
    // 10. Trigger Rule Analyzer
    if ($status === 'online') {
        try {
            writeLog("Triggering rule-based diagnostic analysis...");
            runAnalysis($serverId, $db);
            writeLog("Analysis completed successfully.");
        } catch (Exception $e) {
            writeLog("ERROR in analyzer module: " . $e->getMessage());
        }
    }

    // 10b. Trigger Alerts Check
    try {
        writeLog("Checking performance thresholds and system alerts...");
        checkAndTriggerAlerts($serverId, $db, $status);
        writeLog("Alert checks completed.");
    } catch (Exception $e) {
        writeLog("ERROR in alert engine: " . $e->getMessage());
    }
}

// 11. Purge Historical Records (dynamic retention config)
try {
    $retentionDays = (int)getAppSetting('retention_days', 30);
    writeLog("Purging metrics records older than {$retentionDays} days retention window...");
    $purgeDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
    
    $p1 = $db->prepare("DELETE FROM metric_snapshots WHERE collected_at < ?");
    $p1->execute([$purgeDate]);
    $c1 = $p1->rowCount();
    
    $p2 = $db->prepare("DELETE FROM wait_stats WHERE collected_at < ?");
    $p2->execute([$purgeDate]);
    $c2 = $p2->rowCount();
    
    $p3 = $db->prepare("DELETE FROM top_queries WHERE collected_at < ?");
    $p3->execute([$purgeDate]);
    $c3 = $p3->rowCount();
    
    $p4 = $db->prepare("DELETE FROM index_stats WHERE collected_at < ?");
    $p4->execute([$purgeDate]);
    $c4 = $p4->rowCount();
    
    $p5 = $db->prepare("DELETE FROM blocking_history WHERE collected_at < ?");
    $p5->execute([$purgeDate]);
    $c5 = $p5->rowCount();

    $p6 = $db->prepare("DELETE FROM db_file_stats WHERE collected_at < ?");
    $p6->execute([$purgeDate]);
    $c6 = $p6->rowCount();

    $p7 = $db->prepare("DELETE FROM triggered_alerts WHERE collected_at < ?");
    $p7->execute([$purgeDate]);
    $c7 = $p7->rowCount();

    $p8 = $db->prepare("DELETE FROM deadlock_history WHERE collected_at < ?");
    $p8->execute([$purgeDate]);
    $c8 = $p8->rowCount();

    $p9 = $db->prepare("DELETE FROM db_backup_stats WHERE collected_at < ?");
    $p9->execute([$purgeDate]);
    $c9 = $p9->rowCount();

    $p10 = $db->prepare("DELETE FROM agent_job_status WHERE collected_at < ?");
    $p10->execute([$purgeDate]);
    $c10 = $p10->rowCount();

    $p11 = $db->prepare("DELETE FROM agent_job_history WHERE collected_at < ?");
    $p11->execute([$purgeDate]);
    $c11 = $p11->rowCount();
    
    $p12 = $db->prepare("DELETE FROM alwayson_replicas WHERE collected_at < ?");
    $p12->execute([$purgeDate]);
    $c12 = $p12->rowCount();
    
    $p13 = $db->prepare("DELETE FROM alwayson_databases WHERE collected_at < ?");
    $p13->execute([$purgeDate]);
    $c13 = $p13->rowCount();
    
    $p14 = $db->prepare("DELETE FROM alwayson_cluster WHERE collected_at < ?");
    $p14->execute([$purgeDate]);
    $c14 = $p14->rowCount();
    
    $p15 = $db->prepare("DELETE FROM alwayson_cluster_members WHERE collected_at < ?");
    $p15->execute([$purgeDate]);
    $c15 = $p15->rowCount();
    
    writeLog("Purge complete. Records deleted: snapshots ($c1), waits ($c2), queries ($c3), indexes ($c4), blocks ($c5), db_files ($c6), alerts ($c7), deadlocks ($c8), backups ($c9), job_status ($c10), job_history ($c11), alwayson_replicas ($c12), alwayson_dbs ($c13), alwayson_cluster ($c14), alwayson_members ($c15).");
} catch (Exception $e) {
    writeLog("WARN: Purge process failed: " . $e->getMessage());
}

writeLog("--- Collection Run Finished ---");

/**
 * Parses SQL Server Deadlock Graph XML report to extract processes, locks, and victim information.
 *
 * @param string $xmlString Raw deadlock graph XML.
 * @return array|null Parsed data elements or null on failure.
 */
function parseDeadlockXml($xmlString) {
    try {
        $xml = @simplexml_load_string($xmlString);
        if (!$xml) return null;
        
        $victimId = null;
        if (isset($xml->deadlock['victim'])) {
            $victimId = (string)$xml->deadlock['victim'];
        } elseif (isset($xml['victim'])) {
            $victimId = (string)$xml['victim'];
        }
        
        $processes = [];
        $procNodes = null;
        if (isset($xml->{'process-list'}->process)) {
            $procNodes = $xml->{'process-list'}->process;
        } elseif (isset($xml->process)) {
            $procNodes = $xml->process;
        }
        
        if ($procNodes) {
            foreach ($procNodes as $p) {
                $id = (string)$p['id'];
                $spid = (int)$p['spid'];
                $hostname = (string)$p['hostname'];
                $login = (string)$p['loginname'];
                $status = (string)$p['status'];
                $waittime = (int)$p['waittime'];
                
                $dbName = (string)$p['currentdbname'] ?: (string)$p['currentdb'];
                if (empty($dbName) && isset($p['currentdb'])) {
                    $dbName = "DB_ID: " . (string)$p['currentdb'];
                }
                if (empty($dbName)) {
                    $dbName = 'Unknown';
                }
                
                $sql = "";
                if (isset($p->inputbuf)) {
                    $sql = trim((string)$p->inputbuf);
                }
                
                $processes[$id] = [
                    'id' => $id,
                    'spid' => $spid,
                    'hostname' => !empty($hostname) ? $hostname : 'N/A',
                    'login' => !empty($login) ? $login : 'N/A',
                    'status' => ($id === $victimId) ? 'rolled back (victim)' : 'committed (winner)',
                    'sql_text' => $sql,
                    'waittime' => $waittime,
                    'database_name' => $dbName,
                    'lock_resource' => '',
                    'request_mode' => '',
                    'holder_spid' => 0
                ];
            }
        }
        
        $resNodes = null;
        if (isset($xml->{'resource-list'})) {
            $resNodes = $xml->{'resource-list'};
        }
        
        if ($resNodes) {
            foreach ($resNodes->children() as $resType => $res) {
                $resName = (string)$res['objectname'] ?: (string)$res['associatedObjectId'] ?: $resType;
                if (empty($resName)) {
                    $resName = $resType;
                }
                
                $ownerId = null;
                if (isset($res->{'owner-list'}->owner)) {
                    $ownerId = (string)$res->{'owner-list'}->owner['id'];
                }
                
                if (isset($res->{'waiter-list'}->waiter)) {
                    foreach ($res->{'waiter-list'}->waiter as $waiter) {
                        $waiterId = (string)$waiter['id'];
                        $mode = (string)$waiter['mode'];
                        
                        if (isset($processes[$waiterId])) {
                            $processes[$waiterId]['lock_resource'] = $resName;
                            $processes[$waiterId]['request_mode'] = $mode;
                            if ($ownerId && isset($processes[$ownerId])) {
                                $processes[$waiterId]['holder_spid'] = $processes[$ownerId]['spid'];
                            }
                        }
                    }
                }
            }
        }
        
        return [
            'victim_id' => $victimId,
            'processes' => array_values($processes)
        ];
    } catch (Exception $ex) {
        return null;
    }
}

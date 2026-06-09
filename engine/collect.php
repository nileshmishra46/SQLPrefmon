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

$db = getDbConnection();
$timestamp = date('Y-m-d H:i:s');
$logFile = APP_LOG_PATH;

// Ensure log folder exists
if (!file_exists(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

function writeLog($message) {
    global $logFile;
    $logMsg = "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
    file_put_contents($logFile, $logMsg, FILE_APPEND);
    echo $logMsg;
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
            
            // Insert top expensive queries
            $mockQueries = [
                [
                    'hash' => '0x8A2C345F',
                    'db' => 'Production_DB',
                    'text' => "SELECT s.SaleId, s.SaleDate, c.CustomerName, p.ProductName \nFROM Sales s \nINNER JOIN Customers c ON s.CustomerId = c.CustomerId \nINNER JOIN Products p ON s.ProductId = p.ProductId \nWHERE c.State = 'NY' ORDER BY s.SaleDate DESC",
                    'cpu' => rand(50000, 150000),
                    'elapsed' => rand(70000, 200000),
                    'reads' => rand(1500000, 4500000), // triggers logical reads rule
                    'execs' => rand(500, 3000)
                ],
                [
                    'hash' => '0x3F9D821A',
                    'db' => 'Production_DB',
                    'text' => "SELECT COUNT(*), OrderStatus \nFROM Orders \nGROUP BY OrderStatus \nHAVING COUNT(*) > 100",
                    'cpu' => rand(2000, 15000),
                    'elapsed' => rand(5500, 25000), // triggers slow run rule (> 5s avg)
                    'reads' => rand(20000, 80000),
                    'execs' => rand(2, 5)
                ],
                [
                    'hash' => '0xAB94C27D',
                    'db' => 'HR_Portal',
                    'text' => "UPDATE EmployeeProfile \nSET LastActiveDate = GETDATE() \nWHERE EmployeeId = @1",
                    'cpu' => rand(1500, 5000),
                    'elapsed' => rand(2000, 6000),
                    'reads' => rand(100, 500),
                    'execs' => rand(8000, 15000)
                ]
            ];
            
            $stmtQuery = $db->prepare("
                INSERT INTO top_queries (
                    server_id, collected_at, query_hash, query_text, database_name, 
                    total_cpu_ms, total_elapsed_ms, total_logical_reads, execution_count, 
                    avg_cpu_ms, avg_elapsed_ms, avg_logical_reads, missing_index_hint
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($mockQueries as $q) {
                $avgCpu = $q['cpu'] / $q['execs'];
                $avgElapsed = $q['elapsed'] / $q['execs'];
                $avgReads = $q['reads'] / $q['execs'];
                $hint = ($q['reads'] > 1000000) ? 'CREATE NONCLUSTERED INDEX IX_Sales_Covering ON Sales (ProductId) INCLUDE (CustomerId, SaleDate)' : null;
                
                $stmtQuery->execute([
                    $serverId, $timestamp, $q['hash'], $q['text'], $q['db'], 
                    $q['cpu'], $q['elapsed'], $q['reads'], $q['execs'], 
                    $avgCpu, $avgElapsed, $avgReads, $hint
                ]);
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
            
            // 1. Fetch CPU Usage
            $cpuPct = 0.0;
            try {
                $cpuStmt = $conn->query(SQL_QUERY_CPU);
                $cpuRow = $cpuStmt->fetch();
                $cpuPct = isset($cpuRow['cpu_usage_pct']) ? (float)$cpuRow['cpu_usage_pct'] : 0.0;
            } catch (Exception $e) {
                writeLog("WARN: Failed to query CPU DMV, using 0: " . $e->getMessage());
            }
            
            // 2. Fetch Memory Statistics
            $memUsed = 0.0; $memTotal = 0.0; $ple = 600; $cacheHit = 100.0;
            try {
                $memStmt = $conn->query(SQL_QUERY_MEMORY);
                $memRow = $memStmt->fetch();
                $memUsed = (float)($memRow['memory_used_mb'] ?? 0);
                $memTotal = (float)($memRow['memory_total_mb'] ?? 0);
                $ple = (int)($memRow['page_life_exp'] ?? 600);
            } catch (Exception $e) {
                writeLog("WARN: Failed to query Memory DMVs: " . $e->getMessage());
            }
            
            // 3. Fetch Throughput Stats & Compute Rates
            $batchReq = 0.0; $sqlComp = 0.0; $sqlRecomp = 0.0; $deadlocks = 0.0;
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
                
                // Fetch the previous snapshot to calculate actual rate
                $prevSnapStmt = $db->prepare("
                    SELECT collected_at, batch_req_sec, sql_comp_sec, sql_recomp_sec, deadlocks_sec 
                    FROM metric_snapshots 
                    WHERE server_id = ? 
                    ORDER BY collected_at DESC LIMIT 1
                ");
                $prevSnapStmt->execute([$serverId]);
                $prevSnap = $prevSnapStmt->fetch();
                
                if ($prevSnap) {
                    $timeDiff = strtotime($timestamp) - strtotime($prevSnap['collected_at']);
                    if ($timeDiff > 0) {
                        // Counters are cumulative, calculate (Current - Previous) / Seconds
                        // If current is less than previous (e.g. server restart), fallback to 0
                        $calcBatch = ($batchReq >= $prevSnap['batch_req_sec']) ? ($batchReq - $prevSnap['batch_req_sec']) / $timeDiff : 0;
                        $calcComp = ($sqlComp >= $prevSnap['sql_comp_sec']) ? ($sqlComp - $prevSnap['sql_comp_sec']) / $timeDiff : 0;
                        $calcRecomp = ($sqlRecomp >= $prevSnap['sql_recomp_sec']) ? ($sqlRecomp - $prevSnap['sql_recomp_sec']) / $timeDiff : 0;
                        $calcDeadlocks = ($deadlocks >= $prevSnap['deadlocks_sec']) ? ($deadlocks - $prevSnap['deadlocks_sec']) / $timeDiff : 0;
                        
                        // We store the cumulative counter in DB, but we will present rates in the charts!
                    }
                }
            } catch (Exception $e) {
                writeLog("WARN: Failed to query performance counters: " . $e->getMessage());
            }
            
            // 4. Fetch Latencies & Locks
            $lockWaits = 0; $activeConn = 0; $blockedProcs = 0; $tempdbUsed = 0.0; $diskReadMs = 0.0; $diskWriteMs = 0.0;
            try {
                $latStmt = $conn->query(SQL_QUERY_LATENCY_LOCKS);
                $latRow = $latStmt->fetch();
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
            } catch (Exception $e) {
                writeLog("WARN: Failed to query latency / locks: " . $e->getMessage());
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
            }
            
            // 6. Query Top Queries
            try {
                $qStmt = $conn->query(SQL_QUERY_TOP_QUERIES);
                $qList = $qStmt->fetchAll();
                
                $stmtQ = $db->prepare("
                    INSERT INTO top_queries (
                        server_id, collected_at, query_hash, query_text, database_name, 
                        total_cpu_ms, total_elapsed_ms, total_logical_reads, execution_count, 
                        avg_cpu_ms, avg_elapsed_ms, avg_logical_reads, missing_index_hint
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($qList as $q) {
                    $stmtQ->execute([
                        $serverId, $timestamp, $q['query_hash'], $q['query_text'], $q['database_name'] ?? 'master',
                        $q['total_cpu_ms'], $q['total_elapsed_ms'], $q['total_logical_reads'], $q['execution_count'],
                        $q['avg_cpu_ms'], $q['avg_elapsed_ms'], $q['avg_logical_reads'], null
                    ]);
                }
            } catch (Exception $e) {
                writeLog("WARN: Failed to collect top queries: " . $e->getMessage());
            }
            
            // 7. Query Global Missing Indexes
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
            }
            
            // 8. Query Database list and fetch index fragmentation / unused indexes
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
                    }
                }
            } catch (Exception $e) {
                writeLog("WARN: Failed to query database index details: " . $e->getMessage());
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
        $update = $db->prepare("UPDATE servers SET last_checked = ?, last_status = ? WHERE id = ?");
        $update->execute([$timestamp, $status, $serverId]);
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
}

// 11. Purge Historical Records (auto-purge data older than 30 days)
try {
    writeLog("Purging metrics records older than 30 days retention window...");
    $purgeDate = date('Y-m-d H:i:s', strtotime('-30 days'));
    
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
    
    writeLog("Purge complete. Records deleted: snapshots ($c1), waits ($c2), queries ($c3), indexes ($c4).");
} catch (Exception $e) {
    writeLog("WARN: Purge process failed: " . $e->getMessage());
}

writeLog("--- Collection Run Finished ---");

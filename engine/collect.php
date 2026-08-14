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
            }

            // 6b. Query blocking history
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
    
    $p5 = $db->prepare("DELETE FROM blocking_history WHERE collected_at < ?");
    $p5->execute([$purgeDate]);
    $c5 = $p5->rowCount();
    
    writeLog("Purge complete. Records deleted: snapshots ($c1), waits ($c2), queries ($c3), indexes ($c4), blocks ($c5).");
} catch (Exception $e) {
    writeLog("WARN: Purge process failed: " . $e->getMessage());
}

writeLog("--- Collection Run Finished ---");

<?php
// engine/analyzer.php

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

/**
 * Run rule analysis on the latest metrics collected for a server
 * 
 * @param int $serverId
 * @param PDO $db
 */
function runAnalysis($serverId, $db) {
    // 1. Fetch latest snapshot
    $stmt = $db->prepare("SELECT * FROM metric_snapshots WHERE server_id = ? ORDER BY collected_at DESC LIMIT 1");
    $stmt->execute([$serverId]);
    $snapshot = $stmt->fetch();
    
    if (!$snapshot) {
        return; // No snapshot data to analyze
    }
    
    // 2. Fetch latest wait stats
    $stmt = $db->prepare("SELECT * FROM wait_stats WHERE server_id = ? AND collected_at = ?");
    $stmt->execute([$serverId, $snapshot['collected_at']]);
    $waits = $stmt->fetchAll();
    
    // 3. Fetch latest top queries
    $stmt = $db->prepare("SELECT * FROM top_queries WHERE server_id = ? AND collected_at = ?");
    $stmt->execute([$serverId, $snapshot['collected_at']]);
    $queries = $stmt->fetchAll();
    
    // 4. Fetch latest index stats
    $stmt = $db->prepare("SELECT * FROM index_stats WHERE server_id = ? AND collected_at = ?");
    $stmt->execute([$serverId, $snapshot['collected_at']]);
    $indexes = $stmt->fetchAll();
    
    // List to hold generated recommendations
    $recs = [];
    
    // --- 8.1 MEMORY RULES ---
    
    // Rule: PLE < 300 seconds
    $pleThresh = getAppSetting('ple_threshold', THRESHOLD_PLE_SEC);
    if ($snapshot['page_life_exp'] !== null && $snapshot['page_life_exp'] < $pleThresh) {
        $recs[] = [
            'category' => 'memory',
            'severity' => 'critical',
            'title' => 'Low Page Life Expectancy (PLE)',
            'description' => "Page Life Expectancy is currently {$snapshot['page_life_exp']} seconds (threshold: {$pleThresh}s). This indicates high buffer pool pressure where data pages are being flushed from cache too quickly.",
            'fix_script' => "-- 1. Review Max Server Memory setting\nEXEC sp_configure 'show advanced options', 1;\nRECONFIGURE;\nEXEC sp_configure 'max server memory (MB)';\n\n-- 2. Identify memory-intensive queries\nSELECT TOP 5 \n    total_logical_reads, \n    execution_count, \n    total_logical_reads/execution_count AS avg_logical_reads,\n    (SELECT SUBSTRING(text, statement_start_offset/2 + 1, (CASE WHEN statement_end_offset = -1 THEN LEN(CONVERT(nvarchar(max), text))*2 ELSE statement_end_offset END - statement_start_offset)/2) FROM sys.dm_exec_sql_text(sql_handle)) AS query_text\nFROM sys.dm_exec_query_stats\nORDER BY total_logical_reads DESC;"
        ];
    }
    
    // Rule: PLE trending down > 20% over 1 hour
    $prevPLEStmt = $db->prepare("
        SELECT page_life_exp FROM metric_snapshots 
        WHERE server_id = ? AND collected_at >= datetime('now', '-1 hour') AND collected_at < ?
        ORDER BY collected_at ASC LIMIT 1
    ");
    $prevPLEStmt->execute([$serverId, $snapshot['collected_at']]);
    $prevPLEVal = $prevPLEStmt->fetchColumn();
    if ($prevPLEVal && $snapshot['page_life_exp'] !== null) {
        $dropPct = (($prevPLEVal - $snapshot['page_life_exp']) / $prevPLEVal) * 100;
        if ($dropPct >= 20.0) {
            $recs[] = [
                'category' => 'memory',
                'severity' => 'warning',
                'title' => 'Page Life Expectancy Trending Down',
                'description' => "Page Life Expectancy has dropped by " . round($dropPct, 1) . "% over the last hour (from {$prevPLEVal}s to {$snapshot['page_life_exp']}s). This suggests a sudden query memory grant spike or cache-flushing process.",
                'fix_script' => "-- Check active heavy query memory grants\nSELECT \n    session_id, request_id, scheduler_id, dop, \n    requested_memory_kb / 1024.0 AS requested_mem_mb, \n    granted_memory_kb / 1024.0 AS granted_mem_mb, \n    used_memory_kb / 1024.0 AS used_mem_mb\nFROM sys.dm_exec_query_memory_grants;"
            ];
        }
    }
    
    // --- 8.2 CPU RULES ---
    
    // Rule: CPU > 85%
    $cpuThresh = getAppSetting('cpu_threshold', THRESHOLD_CPU_PCT);
    if ($snapshot['cpu_usage_pct'] !== null && $snapshot['cpu_usage_pct'] >= $cpuThresh) {
        $recs[] = [
            'category' => 'cpu',
            'severity' => 'critical',
            'title' => 'High Sustained CPU Usage',
            'description' => "Total CPU utilization is at " . round($snapshot['cpu_usage_pct'], 1) . "% (threshold: {$cpuThresh}%). Sustained high CPU leads to queuing and thread exhaustion.",
            'fix_script' => "-- Find top CPU-consuming active requests\nSELECT TOP 5\n    r.session_id, r.cpu_time, r.status, r.command, \n    SUBSTRING(st.text, (r.statement_start_offset/2)+1, ((CASE r.statement_end_offset WHEN -1 THEN DATALENGTH(st.text) ELSE r.statement_end_offset END - r.statement_start_offset)/2) + 1) AS query_text\nFROM sys.dm_exec_requests r\nCROSS APPLY sys.dm_exec_sql_text(r.sql_handle) st\nORDER BY r.cpu_time DESC;"
        ];
    }
    
    // Rule: SQL Recompilations/sec > 100
    $recompThresh = getAppSetting('recompile_threshold', THRESHOLD_RECOMPILE_SEC);
    if ($snapshot['sql_recomp_sec'] !== null && $snapshot['sql_recomp_sec'] >= $recompThresh) {
        $recs[] = [
            'category' => 'config',
            'severity' => 'warning',
            'title' => 'Excessive SQL Re-compilations',
            'description' => "SQL Recompilations are at " . round($snapshot['sql_recomp_sec'], 1) . "/sec (threshold: {$recompThresh}/sec). This drains CPU cycles. Often caused by temporary tables or missing parameterization.",
            'fix_script' => "-- Check plan cache for unparameterized queries\nSELECT TOP 10 usecounts, text\nFROM sys.dm_exec_cached_plans\nCROSS APPLY sys.dm_exec_sql_text(plan_handle)\nWHERE cacheobjtype = 'Compiled Plan'\nAND objtype = 'Adhoc'\nORDER BY usecounts DESC;"
        ];
    }
    
    // --- 8.3 I/O RULES ---
    
    // Rule: Avg disk read latency > 20ms or > 50ms
    if ($snapshot['disk_read_ms'] !== null) {
        if ($snapshot['disk_read_ms'] >= 50.0) {
            $recs[] = [
                'category' => 'io',
                'severity' => 'critical',
                'title' => 'Severe Disk Read Bottleneck',
                'description' => "Average physical disk read latency is {$snapshot['disk_read_ms']}ms (threshold: 50ms). This represents severe I/O degradation.",
                'fix_script' => "-- Identify files with highest I/O stalls\nSELECT \n    DB_NAME(database_id) AS db_name, \n    file_id, \n    io_stall_read_ms / num_of_reads AS avg_read_latency_ms,\n    io_stall_write_ms / num_of_writes AS avg_write_latency_ms\nFROM sys.dm_io_virtual_file_stats(NULL, NULL)\nWHERE num_of_reads > 0 AND num_of_writes > 0\nORDER BY avg_read_latency_ms DESC;"
            ];
        } else {
            $readLatencyThresh = getAppSetting('disk_read_latency', THRESHOLD_DISK_LATENCY_MS);
            if ($snapshot['disk_read_ms'] >= $readLatencyThresh) {
                $recs[] = [
                    'category' => 'io',
                    'severity' => 'warning',
                    'title' => 'High Disk Read Latency',
                    'description' => "Average physical disk read latency is {$snapshot['disk_read_ms']}ms (threshold: {$readLatencyThresh}ms). The storage subsystem is struggling to keep up with reads.",
                    'fix_script' => "-- Check which database processes are doing the most physical reads\nSELECT TOP 5\n    session_id, reads, logical_reads, writes, row_count\nFROM sys.dm_exec_sessions\nORDER BY reads DESC;"
                ];
            }
        }
    }
    
    // --- 8.4 WAIT STATISTICS RULES ---
    
    // Signal Waits Ratio > 25% of total waits
    $totalWaitTime = 0;
    $totalSignalTime = 0;
    foreach ($waits as $w) {
        $totalWaitTime += $w['wait_time_ms'];
        $totalSignalTime += $w['signal_wait_ms'];
    }
    $signalWaitThresh = getAppSetting('signal_wait_pct', THRESHOLD_SIGNAL_WAIT_PCT);
    if ($totalWaitTime > 0 && ($totalSignalTime / $totalWaitTime) * 100 > $signalWaitThresh) {
        $ratio = round(($totalSignalTime / $totalWaitTime) * 100, 1);
        $recs[] = [
            'category' => 'waits',
            'severity' => 'warning',
            'title' => 'High Signal Wait Ratio (CPU Bottleneck)',
            'description' => "Signal waits comprise {$ratio}% of total system wait time (threshold: {$signalWaitThresh}%). This suggests tasks are ready to run but are waiting for an available CPU thread.",
            'fix_script' => "-- Check scheduler tasks in runnable state\nSELECT \n    scheduler_id, current_tasks_count, runnable_tasks_count, work_queue_count\nFROM sys.dm_os_schedulers\nWHERE status = 'VISIBLE ONLINE';"
        ];
    }
    
    // Wait Type analysis
    foreach ($waits as $w) {
        if ($w['wait_time_ms'] > 5000) { // Only evaluate significant waits
            if ($w['wait_type'] === 'CXPACKET' || $w['wait_type'] === 'CXCONSUMER') {
                $recs[] = [
                    'category' => 'waits',
                    'severity' => 'info',
                    'title' => 'Parallel Coordination Waits (CXPACKET)',
                    'description' => "Waittype {$w['wait_type']} is prominent. Parallel queries are waiting for synchronized thread completion. Often solved by adjusting MAXDOP and Cost Threshold for Parallelism.",
                    'fix_script' => "-- Check MAXDOP and Cost Threshold for Parallelism settings\nSELECT name, value, value_in_use \nFROM sys.configurations \nWHERE name IN ('max degree of parallelism', 'cost threshold for parallelism');"
                ];
            } elseif (str_starts_with($w['wait_type'], 'LCK_M_')) {
                $recs[] = [
                    'category' => 'waits',
                    'severity' => 'warning',
                    'title' => 'Lock Contention Waits (' . $w['wait_type'] . ')',
                    'description' => "Database transactions are waiting to acquire locks, indicating blocking chains. High LCK waits lead to application timeouts.",
                    'fix_script' => "-- Find current blocking processes\nSELECT \n    blocking_session_id AS blocking, \n    session_id AS blocked, \n    wait_type, wait_time, r.command\nFROM sys.dm_exec_requests r\nWHERE blocking_session_id <> 0;"
                ];
            } elseif (str_starts_with($w['wait_type'], 'PAGEIOLATCH_')) {
                $recs[] = [
                    'category' => 'waits',
                    'severity' => 'warning',
                    'title' => 'I/O-Bound Page Reads (' . $w['wait_type'] . ')',
                    'description' => "Query operations are stalled waiting for data pages to be loaded from physical storage disk into buffer memory pool.",
                    'fix_script' => "-- Find tables with missing indexes that cause table scans (I/O reads)\nSELECT TOP 5 \n    DB_NAME(d.database_id) AS db_name, OBJECT_NAME(d.object_id, d.database_id) AS table_name,\n    equality_columns, inequality_columns, included_columns\nFROM sys.dm_db_missing_index_details d\nORDER BY object_id DESC;"
                ];
            } elseif ($w['wait_type'] === 'ASYNC_NETWORK_IO') {
                $recs[] = [
                    'category' => 'waits',
                    'severity' => 'warning',
                    'title' => 'Network Consumption Latency (ASYNC_NETWORK_IO)',
                    'description' => "SQL Server is waiting for the application client to ingest query results. Typically caused by pulling massive datasets, application RBAR (row-by-row) loops, or network bottlenecks.",
                    'fix_script' => "-- Identify sessions generating ASYNC_NETWORK_IO\nSELECT \n    session_id, status, hostname, program_name, \n    (SELECT text FROM sys.dm_exec_sql_text(sql_handle)) AS current_sql\nFROM sys.dm_exec_requests r\nJOIN sys.dm_exec_sessions s ON r.session_id = s.session_id\nWHERE r.wait_type = 'ASYNC_NETWORK_IO';"
                ];
            }
        }
    }
    
    // --- 8.5 INDEX RULES ---
    
    $indexFragThresh = getAppSetting('index_frag_pct', THRESHOLD_INDEX_FRAG_PCT);
    foreach ($indexes as $idx) {
        if ($idx['issue_type'] === 'fragmented' && $idx['fragmentation_pct'] >= $indexFragThresh) {
            $dbName = sanitize($idx['database_name']);
            $tbl = sanitize($idx['schema_name'] . '.' . $idx['table_name']);
            $idxName = sanitize($idx['index_name']);
            $pct = round($idx['fragmentation_pct'], 1);
            
            $recs[] = [
                'category' => 'index',
                'severity' => 'warning',
                'title' => "High Index Fragmentation: $idxName",
                'description' => "Index fragmentation for [$dbName].[{$tbl}].[$idxName] is at {$pct}% (threshold: {$indexFragThresh}%). Highly fragmented indexes degrade disk read throughput.",
                'fix_script' => "USE [$dbName];\nGO\nALTER INDEX [$idxName] ON [{$idx['schema_name']}].[{$idx['table_name']}] REBUILD;\nGO"
            ];
        } elseif ($idx['issue_type'] === 'fragmented' && $idx['fragmentation_pct'] >= 10.0 && $idx['fragmentation_pct'] < $indexFragThresh) {
            $dbName = sanitize($idx['database_name']);
            $tbl = sanitize($idx['schema_name'] . '.' . $idx['table_name']);
            $idxName = sanitize($idx['index_name']);
            $pct = round($idx['fragmentation_pct'], 1);
            
            $recs[] = [
                'category' => 'index',
                'severity' => 'info',
                'title' => "Moderate Index Fragmentation: $idxName",
                'description' => "Index fragmentation for [$dbName].[{$tbl}].[$idxName] is at {$pct}%. Consider reorganizing this index.",
                'fix_script' => "USE [$dbName];\nGO\nALTER INDEX [$idxName] ON [{$idx['schema_name']}].[{$idx['table_name']}] REORGANIZE;\nGO"
            ];
        } elseif ($idx['issue_type'] === 'missing') {
            $dbName = sanitize($idx['database_name']);
            $tbl = sanitize($idx['table_name']);
            
            // Extract missing details
            $eq = $idx['user_seeks'] ?: ''; // Equality columns mapped to user_seeks in table
            $ineq = $idx['user_scans'] ?: ''; // Inequality columns mapped to user_scans in table
            $inc = $idx['user_lookups'] ?: ''; // Included columns mapped to user_lookups in table
            $benefit = $idx['fragmentation_pct'] ?: 0; // Benefit score mapped to fragmentation in index_stats table for simplicity
            
            if ($benefit >= 10000) {
                // Construct beautiful CREATE INDEX script dynamically
                $indexColNames = [];
                if (!empty($eq)) $indexColNames[] = $eq;
                if (!empty($ineq)) $indexColNames[] = $ineq;
                $colsStr = implode(', ', $indexColNames);
                $incStr = !empty($inc) ? "\nINCLUDE ($inc)" : "";
                
                $recs[] = [
                    'category' => 'index',
                    'severity' => 'critical',
                    'title' => "High Value Missing Index on $tbl",
                    'description' => "An index recommendation exists for table [$dbName].[dbo].[$tbl] with an impact benefit score of " . number_format($benefit) . ". Creating this index could drastically reduce reads.",
                    'fix_script' => "USE [$dbName];\nGO\nCREATE NONCLUSTERED INDEX IX_{$tbl}_Covering \nON [dbo].[{$tbl}] ($colsStr){$incStr};\nGO"
                ];
            }
        } elseif ($idx['issue_type'] === 'unused') {
            $dbName = sanitize($idx['database_name']);
            $tbl = sanitize($idx['schema_name'] . '.' . $idx['table_name']);
            $idxName = sanitize($idx['index_name']);
            
            $recs[] = [
                'category' => 'index',
                'severity' => 'info',
                'title' => "Unused Database Index: $idxName",
                'description' => "Index [$dbName].[{$tbl}].[$idxName] has recorded 0 reads (seeks/scans) since the last instance restart, but is still receiving updates. Dropping it will decrease INSERT/UPDATE overhead.",
                'fix_script' => "USE [$dbName];\nGO\n-- WARNING: Verify index is not used in rare quarterly/yearly queries before dropping\n-- DROP INDEX [$idxName] ON [{$idx['schema_name']}].[{$idx['table_name']}];\nGO"
            ];
        }
    }
    
    // --- 8.6 QUERY RULES ---
    
    // Calculate total query CPU to see if a single query takes > 30% of total elapsed CPU
    $totalQueryCpu = 0;
    foreach ($queries as $q) {
        $totalQueryCpu += $q['total_cpu_ms'];
    }
    
    foreach ($queries as $q) {
        $qText = sanitize($q['query_text']);
        $dbName = sanitize($q['database_name']);
        
        // Single query > 30% of total cached query CPU
        if ($totalQueryCpu > 0 && ($q['total_cpu_ms'] / $totalQueryCpu) * 100 > 30.0) {
            $pct = round(($q['total_cpu_ms'] / $totalQueryCpu) * 100, 1);
            $recs[] = [
                'category' => 'query',
                'severity' => 'critical',
                'title' => 'Dominant CPU-Consuming Query',
                'description' => "A single query in database [$dbName] accounts for {$pct}% of the captured CPU plan cache. It has executed {$q['execution_count']} times with average CPU time of {$q['avg_cpu_ms']}ms.",
                'fix_script' => "-- Target Query:\n/* \n" . substr($q['query_text'], 0, 300) . "...\n*/\n\n-- Inspect execution plan for parameter sniffing or scans\n-- Check index coverage for database [$dbName]."
            ];
        }
        
        // Avg elapsed time > 5 seconds
        if ($q['avg_elapsed_ms'] > 5000.0) {
            $recs[] = [
                'category' => 'query',
                'severity' => 'warning',
                'title' => 'Slow Running Query',
                'description' => "Query in database [$dbName] takes an average of " . round($q['avg_elapsed_ms'] / 1000, 2) . " seconds per execution. It has executed {$q['execution_count']} times.",
                'fix_script' => "-- Slow Query:\n/* \n" . substr($q['query_text'], 0, 300) . "...\n*/\n\n-- Check for blocking locks or disk reads bottlenecks."
            ];
        }
        
        // Logical reads > 1,000,000 per execution
        if ($q['avg_logical_reads'] > 1000000.0) {
            $recs[] = [
                'category' => 'query',
                'severity' => 'warning',
                'title' => 'High Volume Logical Reads',
                'description' => "Query in database [$dbName] performs " . number_format($q['avg_logical_reads']) . " logical page reads per execution. This indicates table scans instead of index seeks.",
                'fix_script' => "-- Expensive Reads Query:\n/* \n" . substr($q['query_text'], 0, 300) . "...\n*/\n\n-- Ensure a covering nonclustered index exists to satisfy filter clauses."
            ];
        }
    }
    
    // 5. Insert new recommendations dynamically without duplicates
    foreach ($recs as $r) {
        // Check duplicate active recommendation
        $dupStmt = $db->prepare("
            SELECT COUNT(*) FROM recommendations 
            WHERE server_id = ? AND category = ? AND title = ? AND is_resolved = 0
        ");
        $dupStmt->execute([$serverId, $r['category'], $r['title']]);
        
        if ($dupStmt->fetchColumn() == 0) {
            $insert = $db->prepare("
                INSERT INTO recommendations (server_id, category, severity, title, description, fix_script) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $insert->execute([
                $serverId,
                $r['category'],
                $r['severity'],
                $r['title'],
                $r['description'],
                $r['fix_script']
            ]);
        }
    }
}

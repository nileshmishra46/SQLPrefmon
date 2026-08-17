<?php
// engine/dmv_queries.php

// 1. CPU Usage Query (Retrieves CPU usage from ring buffers)
define('SQL_QUERY_CPU', "
    DECLARE @ts_now bigint = (SELECT cpu_ticks / (cpu_ticks/ms_ticks) FROM sys.dm_os_sys_info WITH (NOLOCK)); 
    SELECT TOP(1) 
        SQLProcessUtilization AS cpu_usage_pct
    FROM ( 
        SELECT record.value('(./Record/@id)[1]', 'int') AS record_id, 
            record.value('(./Record/SchedulerMonitorEvent/SystemHealth/SystemIdle)[1]', 'int') AS SystemIdle, 
            record.value('(./Record/SchedulerMonitorEvent/SystemHealth/ProcessUtilization)[1]', 'int') AS SQLProcessUtilization, 
            TIMESTAMP 
        FROM ( 
            SELECT TIMESTAMP, CONVERT(xml, record) AS Record 
            FROM sys.dm_os_ring_buffers WITH (NOLOCK) 
            WHERE ring_buffer_type = N'RING_BUFFER_SCHEDULER_MONITOR' 
            AND record LIKE N'%<SystemHealth>%'
        ) AS x 
    ) AS y 
    ORDER BY record_id DESC;
");

// 2. Memory Stats Query (Retrieves physical memory usage and Buffer Pool performance counters)
define('SQL_QUERY_MEMORY', "
    SELECT 
        (SELECT CAST(cntr_value AS INT) FROM sys.dm_os_performance_counters WHERE object_name LIKE '%Buffer Manager%' AND counter_name = 'Page life expectancy') AS page_life_exp,
        (SELECT physical_memory_in_use_kb / 1024.0 FROM sys.dm_os_process_memory) AS memory_used_mb,
        (SELECT total_physical_memory_kb / 1024.0 FROM sys.dm_os_sys_memory) AS memory_total_mb,
        (SELECT CAST(cntr_value AS REAL) FROM sys.dm_os_performance_counters WHERE object_name LIKE '%Buffer Manager%' AND counter_name = 'Buffer cache hit ratio') AS buffer_cache_hit_ratio,
        (SELECT CAST(cntr_value AS REAL) FROM sys.dm_os_performance_counters WHERE object_name LIKE '%Buffer Manager%' AND counter_name = 'Buffer cache hit ratio base') AS buffer_cache_hit_ratio_base
");

// 3. Throughput & Latency Stats Query (Retrieves cumulative batch commands and latency counters)
define('SQL_QUERY_PERF_COUNTERS', "
    SELECT 
        counter_name, 
        cntr_value 
    FROM sys.dm_os_performance_counters 
    WHERE object_name LIKE '%SQL Statistics%' 
    AND counter_name IN ('Batch Requests/sec', 'SQL Compilations/sec', 'SQL Re-compilations/sec')
    UNION ALL
    SELECT 
        'Deadlocks/sec' as counter_name,
        cntr_value
    FROM sys.dm_os_performance_counters 
    WHERE object_name LIKE '%Locks%' 
    AND counter_name = 'Number of Deadlocks/sec'
    AND instance_name = '_Total'
");

// 4. Page Disk Latency & Lock Stats Query
define('SQL_QUERY_LATENCY_LOCKS', "
    SELECT 
        (SELECT SUM(waiting_tasks_count) FROM sys.dm_os_wait_stats WHERE wait_type LIKE 'LCK_M_%') AS lock_waits,
        (SELECT SUM(wait_time_ms) / 1000.0 FROM sys.dm_os_wait_stats WHERE wait_type LIKE 'LCK_M_%') AS lock_waits_sec,
        (SELECT SUM(num_of_reads) FROM sys.dm_io_virtual_file_stats(NULL, NULL)) AS num_reads,
        (SELECT SUM(io_stall_read_ms) FROM sys.dm_io_virtual_file_stats(NULL, NULL)) AS stall_reads_ms,
        (SELECT SUM(num_of_writes) FROM sys.dm_io_virtual_file_stats(NULL, NULL)) AS num_writes,
        (SELECT SUM(io_stall_write_ms) FROM sys.dm_io_virtual_file_stats(NULL, NULL)) AS stall_writes_ms,
        (SELECT SUM(user_connection_count) FROM sys.dm_db_file_space_usage, (SELECT COUNT(*) AS user_connection_count FROM sys.dm_exec_sessions WHERE is_user_process = 1) AS conn) AS active_conn,
        (SELECT COUNT(*) FROM sys.dm_exec_requests WHERE blocking_session_id <> 0) AS blocked_procs,
        (SELECT SUM(allocated_page_file_pages) * 8.0 / 1024.0 FROM sys.dm_db_file_space_usage) AS tempdb_used_mb
");

// 5. System Waits Query (Top 10 resource bottlenecks)
define('SQL_QUERY_WAITS', "
    SELECT TOP 10
        wait_type,
        wait_time_ms,
        waiting_tasks_count AS waiting_tasks,
        signal_wait_time_ms AS signal_wait_ms
    FROM sys.dm_os_wait_stats
    WHERE wait_type NOT IN (
        'CLR_SEMAPHORE','LAZYWRITER_SLEEP','RESOURCE_QUEUE','SLEEP_TASK',
        'SLEEP_SYSTEMTASK','SQLTRACE_BUFFER_FLUSH','WAITFOR',
        'LOGMGR_QUEUE','CHECKPOINT_QUEUE','REQUEST_FOR_DEADLOCK_SEARCH',
        'XE_TIMER_EVENT','BROKER_TO_FLUSH','BROKER_TASK_STOP','CLR_MANUAL_EVENT',
        'SP_SERVER_DIAGNOSTICS_SLEEP','DIRTY_PAGE_POLL','HADR_FILESTREAM_IOMGR_IOCOMPLETION',
        'QDS_PERSIST_TASK_MAIN_LOOP_SLEEP', 'QDS_CLEANUP_STALE_QUERIES_TASK_MAIN_LOOP_SLEEP',
        'XE_DISPATCHER_WAIT', 'XE_LIVE_TARGET_TVF', 'PREEMPTIVE_XE_DISPATCHER', 
        'DIRTY_PAGE_POLL', 'DISPATCHER_QUEUE_SEMAPHORE', 'FT_IFTS_SCHEDULER_VAL_KEEP_ALIVE'
    )
    AND wait_time_ms > 100
    ORDER BY wait_time_ms DESC;
");

// 6. Top Expensive Queries Query (By total CPU consumption)
define('SQL_QUERY_TOP_QUERIES', "
    SELECT TOP 10
        qs.query_hash,
        SUBSTRING(st.text, (qs.statement_start_offset/2)+1, 
            ((CASE qs.statement_end_offset 
                WHEN -1 THEN DATALENGTH(st.text) 
                ELSE qs.statement_end_offset 
              END - qs.statement_start_offset)/2) + 1) AS query_text,
        DB_NAME(st.dbid) AS database_name,
        qs.total_worker_time / 1000.0 AS total_cpu_ms,
        qs.total_elapsed_time / 1000.0 AS total_elapsed_ms,
        qs.total_logical_reads,
        qs.execution_count,
        (qs.total_worker_time / 1000.0) / qs.execution_count AS avg_cpu_ms,
        (qs.total_elapsed_time / 1000.0) / qs.execution_count AS avg_elapsed_ms,
        (qs.total_logical_reads * 1.0) / qs.execution_count AS avg_logical_reads,
        CAST(qp.query_plan AS NVARCHAR(MAX)) AS query_plan
    FROM sys.dm_exec_query_stats qs
    CROSS APPLY sys.dm_exec_sql_text(qs.sql_handle) st
    OUTER APPLY sys.dm_exec_query_plan(qs.plan_handle) qp
    ORDER BY qs.total_worker_time DESC;
");

// 7. Global Missing Indexes Query
define('SQL_QUERY_MISSING_INDEXES', "
    SELECT TOP 10
        DB_NAME(d.database_id) AS database_name,
        'dbo' AS schema_name,
        OBJECT_NAME(d.object_id, d.database_id) AS table_name,
        'MISSING_INDEX' AS index_name,
        'NONCLUSTERED' AS index_type,
        0 AS user_seeks,
        0 AS user_scans,
        0 AS user_lookups,
        0 AS user_updates,
        0.0 AS fragmentation_pct,
        0 AS page_count,
        'missing' AS issue_type,
        d.equality_columns,
        d.inequality_columns,
        d.included_columns,
        (s.user_seeks + s.user_scans) * s.avg_total_user_cost * (s.avg_user_impact / 100.0) AS index_benefit_score
    FROM sys.dm_db_missing_index_groups g
    INNER JOIN sys.dm_db_missing_index_group_stats s ON s.group_handle = g.index_group_handle
    INNER JOIN sys.dm_db_missing_index_details d ON d.index_handle = g.index_handle
    ORDER BY index_benefit_score DESC;
");

// 8. User Database List Query (To run database-specific index fragmentation analyses)
define('SQL_QUERY_DATABASES', "
    SELECT name 
    FROM sys.databases 
    WHERE name NOT IN ('master', 'tempdb', 'model', 'msdb', 'Resource') 
    AND state_desc = 'ONLINE';
");

// 9. Detailed Blocking Query (Retrieves blocked and blocking session SQL statements & wait details)
define('SQL_QUERY_BLOCKING', "
    SELECT 
        r.session_id AS blocked_session_id,
        r.blocking_session_id AS blocking_session_id,
        r.wait_time AS wait_time_ms,
        r.wait_type AS wait_type,
        r.wait_resource AS resource_description,
        SUBSTRING(st_blocked.text, (r.statement_start_offset/2)+1, 
            ((CASE r.statement_end_offset 
                WHEN -1 THEN DATALENGTH(st_blocked.text) 
                ELSE r.statement_end_offset 
              END - r.statement_start_offset)/2) + 1) AS blocked_sql,
        ISNULL(st_blocking.text, '(Idle Transaction or Blocker SQL unavailable)') AS blocking_sql
    FROM sys.dm_exec_requests r
    CROSS APPLY sys.dm_exec_sql_text(r.sql_handle) st_blocked
    OUTER APPLY (
        SELECT TOP 1 st_b.text
        FROM sys.dm_exec_connections conn_b
        CROSS APPLY sys.dm_exec_sql_text(conn_b.most_recent_sql_handle) st_b
        WHERE conn_b.session_id = r.blocking_session_id
    ) st_blocking
    WHERE r.blocking_session_id <> 0 
    AND r.wait_time >= CAST(? AS INT);
");

// 10. Database MDF & LDF File Sizes and Free Space
define('SQL_QUERY_DB_FILES', "
DECLARE @db_name NVARCHAR(256);
DECLARE @sql NVARCHAR(MAX);
CREATE TABLE #FileStats (
    database_name NVARCHAR(256),
    file_name NVARCHAR(256),
    file_type NVARCHAR(50),
    physical_name NVARCHAR(512),
    total_size_mb REAL,
    used_space_mb REAL
);

DECLARE db_cursor CURSOR FOR
SELECT name FROM sys.databases WHERE state_desc = 'ONLINE';

OPEN db_cursor;
FETCH NEXT FROM db_cursor INTO @db_name;

WHILE @@FETCH_STATUS = 0
BEGIN
    SET @sql = '
    USE [' + REPLACE(@db_name, ']', ']]') + '];
    INSERT INTO #FileStats (database_name, file_name, file_type, physical_name, total_size_mb, used_space_mb)
    SELECT 
        DB_NAME(),
        name,
        type_desc,
        physical_name,
        size * 8.0 / 1024.0,
        CAST(FILEPROPERTY(name, ''SpaceUsed'') AS FLOAT) * 8.0 / 1024.0
    FROM sys.database_files;';
    
    BEGIN TRY
        EXEC(@sql);
    END TRY
    BEGIN CATCH
        -- Ignore databases we cannot access
    END CATCH

    FETCH NEXT FROM db_cursor INTO @db_name;
END;

CLOSE db_cursor;
DEALLOCATE db_cursor;

SELECT 
    database_name,
    file_name,
    file_type,
    physical_name,
    total_size_mb,
    used_space_mb,
    (total_size_mb - used_space_mb) AS free_space_mb,
    CASE 
        WHEN total_size_mb > 0 THEN ((total_size_mb - used_space_mb) / total_size_mb) * 100.0 
        ELSE 0.0 
    END AS free_space_pct
FROM #FileStats;

DROP TABLE #FileStats;
");

define("SQL_QUERY_DEADLOCKS", "
SELECT 
    CAST(event_data.value('(event/@timestamp)[1]', 'VARCHAR(100)') AS DATETIME) AS deadlock_time,
    CAST(event_data.value('(event/data[@name=\"xml_report\"]/value)[1]', 'NVARCHAR(MAX)') AS NVARCHAR(MAX)) AS deadlock_graph
FROM (
    SELECT 
        CAST(target_data AS XML) AS TargetData
    FROM sys.dm_xe_session_targets st
    JOIN sys.dm_xe_sessions s ON s.address = st.event_session_address
    WHERE s.name = 'system_health'
      AND st.target_name = 'ring_buffer'
) AS TargetData
CROSS APPLY TargetData.nodes('RingBufferTarget/event[@name=\"xml_deadlock_report\"]') AS XEvent(event_data)
ORDER BY deadlock_time DESC;
");

define("SQL_QUERY_BACKUPS", "
WITH LastFull AS (
    SELECT 
        database_name,
        backup_finish_date AS full_backup_time,
        (backup_size / 1024.0 / 1024.0) AS full_backup_size_mb,
        ROW_NUMBER() OVER (PARTITION BY database_name ORDER BY backup_finish_date DESC) as rn
    FROM msdb.dbo.backupset
    WHERE type = 'D'
),
LastDiff AS (
    SELECT 
        database_name,
        backup_finish_date AS diff_backup_time,
        (backup_size / 1024.0 / 1024.0) AS diff_backup_size_mb,
        ROW_NUMBER() OVER (PARTITION BY database_name ORDER BY backup_finish_date DESC) as rn
    FROM msdb.dbo.backupset
    WHERE type = 'I'
),
LastLog AS (
    SELECT 
        database_name,
        backup_finish_date AS log_backup_time,
        (backup_size / 1024.0 / 1024.0) AS log_backup_size_mb,
        ROW_NUMBER() OVER (PARTITION BY database_name ORDER BY backup_finish_date DESC) as rn
    FROM msdb.dbo.backupset
    WHERE type = 'L'
)
SELECT 
    d.name AS database_name,
    d.recovery_model_desc AS recovery_model,
    lf.full_backup_time,
    lf.full_backup_size_mb,
    ld.diff_backup_time,
    ld.diff_backup_size_mb,
    ll.log_backup_time,
    ll.log_backup_size_mb
FROM sys.databases d
LEFT JOIN LastFull lf ON d.name = lf.database_name AND lf.rn = 1
LEFT JOIN LastDiff ld ON d.name = ld.database_name AND ld.rn = 1
LEFT JOIN LastLog ll ON d.name = ll.database_name AND ll.rn = 1
WHERE d.name <> 'tempdb' AND d.state_desc = 'ONLINE'
ORDER BY d.name ASC;
");

// 12. SQL Agent Jobs current status query
define("SQL_QUERY_AGENT_JOBS", "
SELECT 
    j.job_id,
    j.name AS job_name,
    j.enabled,
    j.description,
    CASE 
        WHEN ja.start_execution_date IS NOT NULL AND ja.stop_execution_date IS NULL THEN 'Running'
        WHEN js.last_run_outcome = 0 THEN 'Failed'
        WHEN js.last_run_outcome = 1 THEN 'Succeeded'
        WHEN js.last_run_outcome = 2 THEN 'Retry'
        WHEN js.last_run_outcome = 3 THEN 'Canceled'
        ELSE 'Idle/Never Run'
    END AS current_status,
    COALESCE(ja.start_execution_date, 
        CASE WHEN js.last_run_date > 0 THEN msdb.dbo.agent_datetime(js.last_run_date, js.last_run_time) ELSE NULL END
    ) AS last_run_time,
    CASE 
        WHEN ja.start_execution_date IS NOT NULL AND ja.stop_execution_date IS NULL THEN 
            DATEDIFF(second, ja.start_execution_date, GETDATE())
        ELSE 
            (js.last_run_duration / 10000 * 3600) + 
            ((js.last_run_duration % 10000) / 100 * 60) + 
            (js.last_run_duration % 100)
    END AS run_duration_sec,
    h.message AS last_outcome_message
FROM msdb.dbo.sysjobs j
LEFT JOIN msdb.dbo.sysjobservers js ON j.job_id = js.job_id
LEFT JOIN (
    SELECT job_id, start_execution_date, stop_execution_date,
           ROW_NUMBER() OVER (PARTITION BY job_id ORDER BY session_id DESC, start_execution_date DESC) as rn
    FROM msdb.dbo.sysjobactivity
) ja ON j.job_id = ja.job_id AND ja.rn = 1
LEFT JOIN (
    SELECT job_id, message,
           ROW_NUMBER() OVER (PARTITION BY job_id ORDER BY run_date DESC, run_time DESC) as rn
    FROM msdb.dbo.sysjobhistory
    WHERE step_id = 0
) h ON j.job_id = h.job_id AND h.rn = 1
ORDER BY j.name ASC;
");

// 13. SQL Agent Jobs step-by-step history query (last 48 hours)
define("SQL_QUERY_AGENT_JOB_HISTORY", "
SELECT 
    j.job_id,
    j.name AS job_name,
    h.step_id,
    h.step_name,
    CASE h.run_status
        WHEN 0 THEN 'Failed'
        WHEN 1 THEN 'Succeeded'
        WHEN 2 THEN 'Retry'
        WHEN 3 THEN 'Canceled'
        WHEN 4 THEN 'In Progress'
        ELSE 'Unknown'
    END AS run_status,
    CASE 
        WHEN h.run_date > 0 THEN msdb.dbo.agent_datetime(h.run_date, h.run_time)
        ELSE NULL 
    END AS run_time,
    (h.run_duration / 10000 * 3600) + 
    ((h.run_duration % 10000) / 100 * 60) + 
    (h.run_duration % 100) AS run_duration_sec,
    h.message
FROM msdb.dbo.sysjobhistory h
JOIN msdb.dbo.sysjobs j ON h.job_id = j.job_id
WHERE h.run_date >= CONVERT(INT, CONVERT(VARCHAR(8), DATEADD(day, -2, GETDATE()), 112))
ORDER BY run_time DESC, h.step_id ASC;
");

// 14. Always On Local Replica Role Query
define("SQL_QUERY_HADR_ROLE", "
IF SERVERPROPERTY('IsHadrEnabled') = 1
BEGIN
    SELECT CAST(rs.role_desc AS VARCHAR(50)) AS role_desc
    FROM sys.dm_hadr_availability_replica_states rs
    WHERE rs.is_local = 1
END
ELSE
BEGIN
    SELECT NULL AS role_desc
END
");

// 15. Always On Availability Group Replicas Query
define("SQL_QUERY_HADR_REPLICAS", "
IF SERVERPROPERTY('IsHadrEnabled') = 1
BEGIN
    SELECT 
        CAST(ag.name AS VARCHAR(100)) AS ag_name,
        CAST(ar.replica_server_name AS VARCHAR(100)) AS replica_server_name,
        CAST(rs.role_desc AS VARCHAR(50)) AS role_desc,
        CAST(rs.operational_state_desc AS VARCHAR(100)) AS operational_state_desc,
        CAST(rs.connected_state_desc AS VARCHAR(100)) AS connected_state_desc,
        CAST(rs.synchronization_health_desc AS VARCHAR(100)) AS synchronization_health_desc
    FROM sys.availability_groups ag
    JOIN sys.availability_replicas ar ON ag.group_id = ar.group_id
    JOIN sys.dm_hadr_availability_replica_states rs ON ar.replica_id = rs.replica_id
END
");

// 16. Always On Databases replica status Query
define("SQL_QUERY_HADR_DATABASES", "
IF SERVERPROPERTY('IsHadrEnabled') = 1
BEGIN
    SELECT 
        CAST(ag.name AS VARCHAR(100)) AS ag_name,
        CAST(DB_NAME(drs.database_id) AS VARCHAR(100)) AS database_name,
        CAST(drs.synchronization_state_desc AS VARCHAR(100)) AS synchronization_state_desc,
        CAST(drs.synchronization_health_desc AS VARCHAR(100)) AS synchronization_health_desc,
        CAST(drs.log_send_queue_size AS REAL) AS log_send_queue_size,
        CAST(drs.log_send_rate AS REAL) AS log_send_rate,
        CAST(drs.redo_queue_size AS REAL) AS redo_queue_size,
        CAST(drs.redo_rate AS REAL) AS redo_rate
    FROM sys.availability_groups ag
    JOIN sys.dm_hadr_database_replica_states drs ON ag.group_id = drs.group_id
    WHERE drs.is_local = 1
END
");

// 17. Windows Server Failover Cluster (WSFC) Quorum Info Query
define("SQL_QUERY_HADR_CLUSTER", "
IF SERVERPROPERTY('IsHadrEnabled') = 1
BEGIN
    SELECT 
        CAST(cluster_name AS VARCHAR(100)) AS cluster_name,
        CAST(quorum_type_desc AS VARCHAR(100)) AS quorum_type_desc,
        CAST(quorum_state_desc AS VARCHAR(100)) AS quorum_state_desc
    FROM sys.dm_hadr_cluster
END
");

// 18. Cluster Nodes / Quorum Health Query
define("SQL_QUERY_HADR_CLUSTER_MEMBERS", "
IF SERVERPROPERTY('IsHadrEnabled') = 1
BEGIN
    SELECT 
        CAST(member_name AS VARCHAR(100)) AS member_name,
        CAST(member_type_desc AS VARCHAR(100)) AS member_type_desc,
        CAST(member_state_desc AS VARCHAR(100)) AS member_state_desc,
        CAST(number_of_quorum_votes AS INT) AS number_of_quorum_votes
    FROM sys.dm_hadr_cluster_members
END
");




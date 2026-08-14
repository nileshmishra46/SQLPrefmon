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

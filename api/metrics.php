<?php
// api/metrics.php

header('Content-Type: application/json');

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$serverId = (int)($_GET['server_id'] ?? 0);
$range = $_GET['range'] ?? '24h'; // 1h, 6h, 24h, 7d

if ($serverId <= 0) {
    echo json_encode(['error' => 'Invalid or missing server_id parameter']);
    exit;
}

$db = getDbConnection();

// Verify server exists
$check = $db->prepare("SELECT id FROM servers WHERE id = ?");
$check->execute([$serverId]);
if (!$check->fetch()) {
    echo json_encode(['error' => 'Server not found']);
    exit;
}

// Map ranges to SQLite datetime strings
$intervalStr = '-24 hours';
if ($range === '1h') {
    $intervalStr = '-1 hours';
} elseif ($range === '6h') {
    $intervalStr = '-6 hours';
} elseif ($range === '7d') {
    $intervalStr = '-7 days';
}

$query = "
    SELECT 
        collected_at, 
        cpu_usage_pct, 
        memory_used_mb, 
        memory_total_mb, 
        page_life_exp, 
        batch_req_sec,
        sql_recomp_sec,
        disk_read_ms,
        disk_write_ms,
        active_conn,
        blocked_procs,
        tempdb_used_mb
    FROM metric_snapshots 
    WHERE server_id = :server_id 
    AND collected_at >= datetime('now', :interval)
    ORDER BY collected_at ASC
";

try {
    $stmt = $db->prepare($query);
    $stmt->bindValue(':server_id', $serverId, PDO::PARAM_INT);
    $stmt->bindValue(':interval', $intervalStr, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    
    // Format response structure
    $timestamps = [];
    $cpu = [];
    $ramPct = [];
    $ple = [];
    $batch = [];
    $recomp = [];
    $diskRead = [];
    $diskWrite = [];
    $conn = [];
    $blocked = [];
    $tempdb = [];
    
    foreach ($rows as $r) {
        $timestamps[] = date('m-d H:i', strtotime($r['collected_at']));
        $cpu[] = (float)$r['cpu_usage_pct'];
        $ramPct[] = $r['memory_total_mb'] > 0 ? round(($r['memory_used_mb'] / $r['memory_total_mb']) * 100, 1) : 0;
        $ple[] = (int)$r['page_life_exp'];
        $batch[] = (float)$r['batch_req_sec'];
        $recomp[] = (float)$r['sql_recomp_sec'];
        $diskRead[] = (float)$r['disk_read_ms'];
        $diskWrite[] = (float)$r['disk_write_ms'];
        $conn[] = (int)$r['active_conn'];
        $blocked[] = (int)$r['blocked_procs'];
        $tempdb[] = (float)$r['tempdb_used_mb'];
    }
    
    echo json_encode([
        'timestamps' => $timestamps,
        'cpu' => $cpu,
        'ram_pct' => $ramPct,
        'ple' => $ple,
        'batch_requests' => $batch,
        'recompilations' => $recomp,
        'disk_read_ms' => $diskRead,
        'disk_write_ms' => $diskWrite,
        'connections' => $conn,
        'blocked_processes' => $blocked,
        'tempdb_used_mb' => $tempdb
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

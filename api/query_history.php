<?php
// api/query_history.php

header('Content-Type: application/json');

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$serverId = (int)($_GET['server_id'] ?? 0);
$hash = $_GET['hash'] ?? '';

if ($serverId <= 0 || empty($hash)) {
    echo json_encode(['error' => 'Invalid or missing server_id or hash parameter']);
    exit;
}

$db = getDbConnection();

// Fetch all historical records for this query hash and server
$query = "
    SELECT collected_at, total_cpu_ms, total_elapsed_ms, execution_count, avg_cpu_ms, avg_elapsed_ms
    FROM top_queries 
    WHERE server_id = ? AND query_hash = ? 
    ORDER BY collected_at ASC
";

try {
    $stmt = $db->prepare($query);
    $stmt->execute([$serverId, $hash]);
    $rows = $stmt->fetchAll();
    
    $timestamps = [];
    $cpu = [];
    $duration = [];
    $execs = [];
    $avgCpu = [];
    $avgDuration = [];
    
    foreach ($rows as $r) {
        $timestamps[] = date('m-d H:i', strtotime($r['collected_at']));
        $cpu[] = (float)$r['total_cpu_ms'];
        $duration[] = (float)$r['total_elapsed_ms'];
        $execs[] = (int)$r['execution_count'];
        $avgCpu[] = (float)$r['avg_cpu_ms'];
        $avgDuration[] = (float)$r['avg_elapsed_ms'];
    }
    
    echo json_encode([
        'timestamps' => $timestamps,
        'total_cpu' => $cpu,
        'total_duration' => $duration,
        'execution_count' => $execs,
        'avg_cpu' => $avgCpu,
        'avg_duration' => $avgDuration
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

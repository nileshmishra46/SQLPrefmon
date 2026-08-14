<?php
// server/download_plan.php

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$queryId = (int)($_GET['id'] ?? 0);

if ($queryId <= 0) {
    http_response_code(400);
    die("Invalid query ID.");
}

$db = getDbConnection();

// Fetch the execution plan and query hash
$stmt = $db->prepare("SELECT query_hash, query_plan FROM top_queries WHERE id = ?");
$stmt->execute([$queryId]);
$query = $stmt->fetch();

if (!$query || empty($query['query_plan'])) {
    http_response_code(404);
    die("Execution plan not found for this query or was not collected.");
}

$planXml = $query['query_plan'];
$hash = sanitize($query['query_hash'] ?: 'unknown');

// Clean potential UTF-8 / UTF-16 BOM or whitespace at start
$planXml = trim($planXml);

// Set download headers to serve as .sqlplan file (opens directly in SSMS)
header('Content-Type: application/octet-stream; charset=utf-8');
header('Content-Disposition: attachment; filename="query_plan_' . $hash . '.sqlplan"');
header('Content-Length: ' . strlen($planXml));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo $planXml;
exit;

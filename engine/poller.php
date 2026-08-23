<?php
// engine/poller.php

// Run indefinitely
set_time_limit(0);
ini_set('memory_limit', '256M');

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once __DIR__ . '/connector.php';
require_once __DIR__ . '/dmv_queries.php';

// Concurrency lock to prevent multiple poller instances
$lockFile = dirname(__DIR__) . '/data/poller.lock';
if (!file_exists(dirname($lockFile))) {
    mkdir(dirname($lockFile), 0755, true);
}
$lockFp = fopen($lockFile, 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    if ($lockFp) {
        fclose($lockFp);
    }
    echo "Poller daemon is already running.\n";
    exit(0);
}

// Log start
$logFile = dirname(__DIR__) . '/logs/poller.log';
if (!file_exists(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

if (!function_exists('pollerLog')) {
    function pollerLog($msg) {
        global $logFile;
        $logMsg = "[" . date('Y-m-d H:i:s') . "] " . $msg . PHP_EOL;
        file_put_contents($logFile, $logMsg, FILE_APPEND);
        echo $logMsg;
    }
}

pollerLog("--- Active Session History Poller Daemon Started ---");

$db = getDbConnection();
$samplesBuffer = [];
$lastSettingsCheck = 0;
$pollerEnabled = true;
$pollInterval = 2; // Default 2 seconds
$recentlyLoggedQueries = [];

$stmtBlockCheck = $db->prepare("
    SELECT id, collected_at, wait_time_ms 
    FROM blocking_history 
    WHERE server_id = ? 
      AND blocked_session_id = ? 
      AND blocking_session_id = ? 
      AND blocked_sql = ? 
      AND blocking_sql = ?
    ORDER BY collected_at DESC LIMIT 1
");

$stmtBlockUpdate = $db->prepare("
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

$stmtQueryInsert = $db->prepare("
    INSERT INTO top_queries (
        server_id, collected_at, query_hash, query_text, database_name, 
        total_cpu_ms, total_elapsed_ms, total_logical_reads, execution_count, 
        avg_cpu_ms, avg_elapsed_ms, avg_logical_reads, missing_index_hint,
        query_plan, parameters
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

while (true) {
    $currentTime = time();
    
    // Periodically re-check settings (every 10 seconds)
    if ($currentTime - $lastSettingsCheck >= 10) {
        // Clear cached settings
        clearstatcache();
        $pollerEnabled = getAppSetting('poller_enabled', false, true);
        $pollInterval = (int)getAppSetting('poller_interval', 2, true);
        if ($pollInterval < 1) $pollInterval = 1;
        $lastSettingsCheck = $currentTime;
        
        if (!$pollerEnabled) {
            pollerLog("Poller has been disabled in settings. Exiting cleanly...");
            break;
        }
        
        // Touch the poller heartbeat file to show it is actively running
        file_put_contents(dirname(__DIR__) . '/data/poller_heartbeat.json', json_encode([
            'last_heartbeat' => $currentTime,
            'pid' => getmypid()
        ]));
    }
    
    // 1. Get active servers to poll
    try {
        $stmt = $db->query("SELECT * FROM servers WHERE is_active = 1");
        $servers = $stmt->fetchAll();
    } catch (Exception $e) {
        pollerLog("ERROR: Failed to fetch servers: " . $e->getMessage());
        sleep(5);
        continue;
    }
    
    $currentMinute = date('Y-m-d H:i:00');
    
    foreach ($servers as $srv) {
        $serverId = (int)$srv['id'];
        $serverName = $srv['display_name'];
        $env = $srv['environment'];
        $captureMode = $srv['history_capture_mode'] ?? 'collector';
        
        if ($env === 'demo') {
            // Generate simulated active session samples (30% chance of an execution sample)
            if (rand(1, 10) <= 5) {
                $queries = [
                    "SELECT TOP 50 * FROM Orders WHERE Status = 'Pending' ORDER BY OrderDate DESC",
                    "UPDATE Inventory SET Stock = Stock - 1 WHERE ProductID = 1045",
                    "SELECT COUNT(*), CategoryID FROM Products GROUP BY CategoryID",
                    "INSERT INTO AuditLogs (UserID, Action, LoggedAt) VALUES (41, 'LOGIN', GETDATE())"
                ];
                $waits = ['CPU', 'LCK_M_X', 'PAGEIOLATCH_SH', 'CXPACKET', 'WRITELOG'];
                
                $chosenQuery = $queries[array_rand($queries)];
                $chosenWait = $waits[array_rand($waits)];
                $waitTime = ($chosenWait === 'CPU') ? 0 : rand(50, 1500);
                
                $samplesBuffer[$serverId][$currentMinute][$chosenQuery][$chosenWait]['samples_count'] = 
                    ($samplesBuffer[$serverId][$currentMinute][$chosenQuery][$chosenWait]['samples_count'] ?? 0) + 1;
                $samplesBuffer[$serverId][$currentMinute][$chosenQuery][$chosenWait]['total_wait_time_ms'] = 
                    ($samplesBuffer[$serverId][$currentMinute][$chosenQuery][$chosenWait]['total_wait_time_ms'] ?? 0) + $waitTime;

                // Simulate historical ASH logging if mode is ash
                if ($captureMode === 'ash') {
                    $queryHash = '0x' . substr(md5($chosenQuery), 0, 8);
                    if (!isset($recentlyLoggedQueries[$serverId][$queryHash]) || (time() - $recentlyLoggedQueries[$serverId][$queryHash] >= 60)) {
                        $mockExecCount = rand(100, 2000);
                        $mockTotalCpu = rand(500, 50000);
                        $mockTotalElapsed = $mockTotalCpu * rand(1, 3);
                        $mockTotalReads = $mockExecCount * rand(100, 5000);
                        
                        $stmtQueryInsert->execute([
                            $serverId,
                            date('Y-m-d H:i:s'),
                            $queryHash,
                            $chosenQuery,
                            'demo_db',
                            $mockTotalCpu,
                            $mockTotalElapsed,
                            $mockTotalReads,
                            $mockExecCount,
                            $mockTotalCpu / $mockExecCount,
                            $mockTotalElapsed / $mockExecCount,
                            $mockTotalReads / $mockExecCount,
                            null,
                            '<?xml version="1.0" encoding="utf-16"?><ShowPlanXML xmlns="http://schemas.microsoft.com/sqlserver/2004/07/showplan" Version="1.5" Build="16.0.1000.6"><BatchSequence><Batch><Statements><StmtSimple StatementText=""/></Statements></Batch></BatchSequence></ShowPlanXML>',
                            null
                        ]);
                        $recentlyLoggedQueries[$serverId][$queryHash] = time();
                    }

                    // Simulate blocking if LCK_M_X wait
                    if ($chosenWait === 'LCK_M_X' && rand(1, 10) <= 3) {
                        $blockedSpid = rand(51, 120);
                        $blockerSpid = rand(51, 120);
                        if ($blockedSpid !== $blockerSpid) {
                            $blockedSql = $chosenQuery;
                            $blockingSql = "UPDATE Orders SET Status = 'Processing' WHERE OrderID = " . rand(1000, 9999);
                            $waitTimeMs = rand(5000, 30000);
                            
                            $stmtBlockCheck->execute([
                                $serverId, 
                                $blockedSpid, 
                                $blockerSpid, 
                                $blockedSql, 
                                $blockingSql
                            ]);
                            $existing = $stmtBlockCheck->fetch();
                            
                            $isSameBlock = false;
                            $timestamp = date('Y-m-d H:i:s');
                            if ($existing) {
                                $lastCollectedTime = strtotime($existing['collected_at']);
                                $currentTime = strtotime($timestamp);
                                $timeDiffSec = $currentTime - $lastCollectedTime;
                                
                                if ($timeDiffSec > 0 && $timeDiffSec <= 900) {
                                    $isSameBlock = true;
                                }
                            }
                            
                            if ($isSameBlock) {
                                $stmtBlockUpdate->execute([$waitTimeMs, $timestamp, $existing['id']]);
                            } else {
                                $stmtBlockInsert->execute([
                                    $serverId, 
                                    $timestamp, 
                                    $blockedSpid, 
                                    $blockedSql,
                                    $blockerSpid, 
                                    $blockingSql, 
                                    $waitTimeMs,
                                    'LCK_M_X', 
                                    'KEY: 5:72057594043039744 (a1b2c3d4e5f6)'
                                ]);
                            }
                        }
                    }
                }
            }
        } else {
            // Connect to target server and query active sessions
            try {
                $decryptedPass = decryptPassword($srv['password']);
                $srvConn = getSqlServerConnection(
                    $srv['hostname'],
                    $srv['port'],
                    $srv['instance_name'],
                    $srv['username'],
                    $decryptedPass,
                    (bool)($srv['trust_server_cert'] ?? false)
                );
                if ($srvConn) {
                    $sessionsStmt = $srvConn->query(SQL_QUERY_ACTIVE_SESSIONS);
                    $activeSessions = $sessionsStmt->fetchAll();
                    
                    foreach ($activeSessions as $session) {
                        $queryText = trim($session['query_text']);
                        if (empty($queryText)) continue;
                        
                        $waitType = $session['wait_type'];
                        $waitTime = (int)$session['wait_time_ms'];
                        
                        $samplesBuffer[$serverId][$currentMinute][$queryText][$waitType]['samples_count'] = 
                            ($samplesBuffer[$serverId][$currentMinute][$queryText][$waitType]['samples_count'] ?? 0) + 1;
                        $samplesBuffer[$serverId][$currentMinute][$queryText][$waitType]['total_wait_time_ms'] = 
                            ($samplesBuffer[$serverId][$currentMinute][$queryText][$waitType]['total_wait_time_ms'] ?? 0) + $waitTime;

                        if ($captureMode === 'ash') {
                            // 1. Log query historically if not throttled
                            $queryHash = $session['query_hash'] ?: '0x0000000000000000';
                            if (!isset($recentlyLoggedQueries[$serverId][$queryHash]) || (time() - $recentlyLoggedQueries[$serverId][$queryHash] >= 60)) {
                                $extractedParams = null;
                                if (!empty($session['query_plan'])) {
                                    $extractedParams = extractParametersFromPlan($session['query_plan']);
                                }
                                
                                $stmtQueryInsert->execute([
                                    $serverId,
                                    date('Y-m-d H:i:s'),
                                    $queryHash,
                                    $queryText,
                                    $session['database_name'] ?? 'master',
                                    (float)($session['total_cpu_ms'] ?? 0.0),
                                    (float)($session['total_elapsed_ms'] ?? 0.0),
                                    (int)($session['total_logical_reads'] ?? 0),
                                    (int)($session['execution_count'] ?? 1),
                                    (float)($session['avg_cpu_ms'] ?? 0.0),
                                    (float)($session['avg_elapsed_ms'] ?? 0.0),
                                    (float)($session['avg_logical_reads'] ?? 0.0),
                                    null,
                                    $session['query_plan'] ?? null,
                                    $extractedParams
                                ]);
                                $recentlyLoggedQueries[$serverId][$queryHash] = time();
                            }

                            // 2. Log blocking historically if session is blocked
                            $blockerSpid = (int)($session['blocking_session_id'] ?? 0);
                            if ($blockerSpid > 0) {
                                $blockedSpid = (int)$session['session_id'];
                                $blockingSql = $session['blocking_sql'] ?? '(Idle Transaction or Blocker SQL unavailable)';
                                $resourceDesc = $session['resource_description'] ?? '';
                                
                                $stmtBlockCheck->execute([
                                    $serverId,
                                    $blockedSpid,
                                    $blockerSpid,
                                    $queryText,
                                    $blockingSql
                                ]);
                                $existing = $stmtBlockCheck->fetch();
                                
                                $isSameBlock = false;
                                $timestamp = date('Y-m-d H:i:s');
                                if ($existing) {
                                    $lastCollectedTime = strtotime($existing['collected_at']);
                                    $currentTime = strtotime($timestamp);
                                    $timeDiffSec = $currentTime - $lastCollectedTime;
                                    
                                    if ($timeDiffSec > 0 && $timeDiffSec <= 900) {
                                        $isSameBlock = true;
                                    }
                                }
                                
                                if ($isSameBlock) {
                                    $stmtBlockUpdate->execute([$waitTime, $timestamp, $existing['id']]);
                                } else {
                                    $stmtBlockInsert->execute([
                                        $serverId,
                                        $timestamp,
                                        $blockedSpid,
                                        $queryText,
                                        $blockerSpid,
                                        $blockingSql,
                                        $waitTime,
                                        $waitType,
                                        $resourceDesc
                                    ]);
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // Silently skip server errors to avoid crash
            }
        }
    }
    
    // 2. Flush older minutes from buffer to the database
    foreach ($samplesBuffer as $sId => $minutes) {
        foreach ($minutes as $minuteStr => $queries) {
            // If the minute is in the past, flush it
            if ($minuteStr !== $currentMinute) {
                $db->beginTransaction();
                try {
                    $insertStmt = $db->prepare("
                        INSERT INTO active_session_history 
                        (server_id, sample_minute, query_text, wait_type, samples_count, total_wait_time_ms) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    
                    foreach ($queries as $qText => $waits) {
                        foreach ($waits as $wType => $data) {
                            $insertStmt->execute([
                                $sId,
                                $minuteStr,
                                $qText,
                                $wType,
                                $data['samples_count'],
                                $data['total_wait_time_ms']
                            ]);
                        }
                    }
                    $db->commit();
                } catch (Exception $ex) {
                    $db->rollBack();
                    pollerLog("ERROR: Failed to save batch samples for server $sId minute $minuteStr: " . $ex->getMessage());
                }
                
                // Clear the flushed minute from buffer
                unset($samplesBuffer[$sId][$minuteStr]);
            }
        }
    }
    
    // Sleep for interval
    sleep($pollInterval);
}

// Flush any remaining buffered samples in-memory before exiting
if (!empty($samplesBuffer)) {
    pollerLog("Flushing remaining buffered samples to database before exit...");
    foreach ($samplesBuffer as $sId => $minutes) {
        $db->beginTransaction();
        try {
            $insertStmt = $db->prepare("
                INSERT INTO active_session_history 
                (server_id, sample_minute, query_text, wait_type, samples_count, total_wait_time_ms) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($minutes as $minuteStr => $queries) {
                foreach ($queries as $qText => $waits) {
                    foreach ($waits as $wType => $data) {
                        $insertStmt->execute([
                            $sId,
                            $minuteStr,
                            $qText,
                            $wType,
                            $data['samples_count'],
                            $data['total_wait_time_ms']
                        ]);
                    }
                }
            }
            $db->commit();
        } catch (Exception $ex) {
            $db->rollBack();
            pollerLog("ERROR: Failed to flush remaining samples on exit: " . $ex->getMessage());
        }
    }
}

// Release lock
flock($lockFp, LOCK_UN);
fclose($lockFp);
if (file_exists($lockFile)) {
    unlink($lockFile);
}
pollerLog("--- Active Session History Poller Daemon Finished ---");

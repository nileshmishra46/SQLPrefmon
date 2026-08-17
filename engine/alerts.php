<?php
// engine/alerts.php

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

/**
 * Main function called from collect.php after each server monitoring run.
 * Evaluates performance statistics and triggers notifications if thresholds are breached.
 * 
 * @param int $serverId
 * @param PDO $db
 * @param string $latestStatus
 */
function checkAndTriggerAlerts($serverId, $db, $latestStatus = 'online') {
    // 1. Fetch server metadata
    $srvStmt = $db->prepare("SELECT display_name, hostname, environment FROM servers WHERE id = ?");
    $srvStmt->execute([$serverId]);
    $server = $srvStmt->fetch();
    if (!$server) {
        return;
    }

    $serverName = $server['display_name'];
    $env = $server['environment'];

    // 2. Fetch alert rule configurations and SMTP credentials from settings.json
    $smtpSettings = [
        'enabled'      => (bool)getAppSetting('smtp_enabled', false),
        'host'         => getAppSetting('smtp_host', ''),
        'port'         => (int)getAppSetting('smtp_port', 25),
        'user'         => getAppSetting('smtp_user', ''),
        'pass'         => getAppSetting('smtp_pass', ''),
        'secure'       => getAppSetting('smtp_secure', 'none'), // 'none', 'ssl', 'tls'
        'from'         => getAppSetting('smtp_from', 'alerts@sqlprefmon.local'),
        'to'           => getAppSetting('smtp_to', 'admin@sqlprefmon.local'),
        'rules'        => getAppSetting('alert_rules', [
            'offline'      => true,
            'cpu'          => true,
            'ple'          => true,
            'disk_latency' => true,
            'blocking'     => true,
            'db_file_space'=> true,
            'backups'      => true,
            'agent_jobs'   => true
        ]),
        'db_file_space_threshold_pct' => (float)getAppSetting('db_file_space_threshold_pct', 10.0)
    ];

    // --- RULE 1: Server Offline/Error ---
    $isOffline = ($latestStatus === 'offline' || $latestStatus === 'error');
    $offlineRulesEnabled = $smtpSettings['rules']['offline'] ?? true;
    if ($offlineRulesEnabled) {
        evaluateAlertState(
            $serverId, $db, 'Offline Server', 
            $isOffline, 'Critical',
            "SQL Server instance [{$serverName}] is OFFLINE or UNREACHABLE. Last checked status: {$latestStatus}.",
            "SQL Server instance [{$serverName}] has recovered and is now ONLINE.",
            $smtpSettings, $serverName, $env
        );
    }

    // If the server is offline, we cannot check other DMV metrics. Skip CPU/Memory/Disk checks.
    if ($isOffline) {
        return;
    }

    // --- Fetch Latest Snapshots for Evaluation ---
    $snapStmt = $db->prepare("SELECT * FROM metric_snapshots WHERE server_id = ? ORDER BY collected_at DESC LIMIT 1");
    $snapStmt->execute([$serverId]);
    $snapshot = $snapStmt->fetch();
    if (!$snapshot) {
        return;
    }

    // --- RULE 2: CPU Utilization ---
    $cpuRulesEnabled = $smtpSettings['rules']['cpu'] ?? true;
    if ($cpuRulesEnabled && $snapshot['cpu_usage_pct'] !== null) {
        $cpuThresh = (float)getAppSetting('cpu_threshold', THRESHOLD_CPU_PCT);
        $cpuVal = round($snapshot['cpu_usage_pct'], 1);
        $isCpuBreached = ($cpuVal >= $cpuThresh);
        
        evaluateAlertState(
            $serverId, $db, 'CPU Utilization', 
            $isCpuBreached, 'Critical',
            "High CPU utilization detected on [{$serverName}]: {$cpuVal}% (threshold: {$cpuThresh}%).",
            "CPU utilization on [{$serverName}] is back to normal: {$cpuVal}%.",
            $smtpSettings, $serverName, $env
        );
    }

    // --- RULE 3: Page Life Expectancy (PLE) ---
    $pleRulesEnabled = $smtpSettings['rules']['ple'] ?? true;
    if ($pleRulesEnabled && $snapshot['page_life_exp'] !== null) {
        $pleThresh = (int)getAppSetting('ple_threshold', THRESHOLD_PLE_SEC);
        $pleVal = (int)$snapshot['page_life_exp'];
        $isPleBreached = ($pleVal < $pleThresh);
        
        evaluateAlertState(
            $serverId, $db, 'Page Life Expectancy', 
            $isPleBreached, 'Warning',
            "Low Page Life Expectancy (PLE) detected on [{$serverName}]: {$pleVal}s (threshold: < {$pleThresh}s). Buffer pool pressure is high.",
            "Page Life Expectancy on [{$serverName}] has stabilized: {$pleVal}s.",
            $smtpSettings, $serverName, $env
        );
    }

    // --- RULE 4: Disk Latency ---
    $diskRulesEnabled = $smtpSettings['rules']['disk_latency'] ?? true;
    if ($diskRulesEnabled && ($snapshot['disk_read_ms'] !== null || $snapshot['disk_write_ms'] !== null)) {
        $latencyThresh = (float)getAppSetting('disk_read_latency', THRESHOLD_DISK_LATENCY_MS);
        $readLat = round($snapshot['disk_read_ms'] ?? 0.0, 1);
        $writeLat = round($snapshot['disk_write_ms'] ?? 0.0, 1);
        
        $isLatBreached = ($readLat >= $latencyThresh || $writeLat >= $latencyThresh);
        $severity = ($readLat >= 50.0 || $writeLat >= 50.0) ? 'Critical' : 'Warning';
        
        evaluateAlertState(
            $serverId, $db, 'Disk Latency', 
            $isLatBreached, $severity,
            "High disk physical I/O latency on [{$serverName}]: Read {$readLat}ms, Write {$writeLat}ms (threshold: {$latencyThresh}ms).",
            "Disk physical I/O latency on [{$serverName}] has resolved: Read {$readLat}ms, Write {$writeLat}ms.",
            $smtpSettings, $serverName, $env
        );
    }

    // --- RULE 5: Long-Running Blocking ---
    $blockRulesEnabled = $smtpSettings['rules']['blocking'] ?? true;
    if ($blockRulesEnabled && $snapshot['blocked_procs'] !== null) {
        $blockThreshold = (int)getAppSetting('blocked_processes_threshold', THRESHOLD_BLOCKED_PROCESSES);
        $blockedCount = (int)$snapshot['blocked_procs'];
        $isBlockBreached = ($blockedCount >= $blockThreshold);
        
        evaluateAlertState(
            $serverId, $db, 'Blocking sessions', 
            $isBlockBreached, 'Warning',
            "Heavy transaction blocking detected on [{$serverName}]: {$blockedCount} blocked sessions active (threshold: >= {$blockThreshold} processes).",
            "Transaction blocking on [{$serverName}] has cleared. Active blocked sessions: {$blockedCount}.",
            $smtpSettings, $serverName, $env
        );
    }

    // --- RULE 6: DB File Free Space ---
    $dbFileRulesEnabled = $smtpSettings['rules']['db_file_space'] ?? true;
    if ($dbFileRulesEnabled) {
        // Fetch the latest file stats for the server
        $latestFilesStmt = $db->prepare("
            SELECT database_name, file_name, file_type, total_size_mb, free_space_mb, free_space_pct 
            FROM db_file_stats 
            WHERE server_id = ? 
              AND collected_at = (SELECT MAX(collected_at) FROM db_file_stats WHERE server_id = ?)
        ");
        $latestFilesStmt->execute([$serverId, $serverId]);
        $files = $latestFilesStmt->fetchAll();
        
        if (!empty($files)) {
            $breachedFiles = [];
            $spaceThresh = $smtpSettings['db_file_space_threshold_pct'];
            
            foreach ($files as $f) {
                if ($f['free_space_pct'] !== null && $f['free_space_pct'] < $spaceThresh) {
                    $freeGb = round($f['free_space_mb'] / 1024.0, 2);
                    $totGb = round($f['total_size_mb'] / 1024.0, 2);
                    $pct = round($f['free_space_pct'], 1);
                    $breachedFiles[] = "{$f['database_name']} -> {$f['file_name']} ({$f['file_type']}): {$pct}% free ({$freeGb}GB of {$totGb}GB)";
                }
            }
            
            $isSpaceBreached = !empty($breachedFiles);
            $msg = "Low database file free capacity detected on [{$serverName}] (threshold: < {$spaceThresh}% free):\n" . implode("\n", $breachedFiles);
            
            evaluateAlertState(
                $serverId, $db, 'DB File Space', 
                $isSpaceBreached, 'Warning',
                $msg,
                "Database file space capacities on [{$serverName}] have recovered above the configured threshold limit (> {$spaceThresh}% free).",
                $smtpSettings, $serverName, $env
            );
        }
    }

    // --- RULE 7: Database Backups Overdue ---
    $backupRulesEnabled = $smtpSettings['rules']['backups'] ?? true;
    if ($backupRulesEnabled) {
        $latestBackupsStmt = $db->prepare("
            SELECT database_name, recovery_model, last_full_backup, last_diff_backup, last_log_backup 
            FROM db_backup_stats 
            WHERE server_id = ? 
              AND collected_at = (SELECT MAX(collected_at) FROM db_backup_stats WHERE server_id = ?)
        ");
        $latestBackupsStmt->execute([$serverId, $serverId]);
        $backups = $latestBackupsStmt->fetchAll();
        
        if (!empty($backups)) {
            $overdueList = [];
            $fullThreshHours = (int)getAppSetting('backup_full_threshold', 24);
            $diffThreshHours = (int)getAppSetting('backup_diff_threshold', 24);
            $logThreshHours = (int)getAppSetting('backup_log_threshold', 4);
            
            $now = time();
            foreach ($backups as $b) {
                $dbName = $b['database_name'];
                
                // 1. Check Full Backup
                if (empty($b['last_full_backup'])) {
                    $overdueList[] = "{$dbName}: No FULL database backup exists.";
                } else {
                    $fullAgeHours = ($now - strtotime($b['last_full_backup'])) / 3600;
                    if ($fullAgeHours > $fullThreshHours) {
                        $overdueList[] = "{$dbName}: Last FULL backup was " . round($fullAgeHours, 1) . " hours ago (threshold: {$fullThreshHours}h).";
                    }
                }
                
                // 1.5. Check Differential Backup (evaluate only if exists/configured)
                if (!empty($b['last_diff_backup'])) {
                    $diffAgeHours = ($now - strtotime($b['last_diff_backup'])) / 3600;
                    if ($diffAgeHours > $diffThreshHours) {
                        $overdueList[] = "{$dbName}: Last DIFF backup was " . round($diffAgeHours, 1) . " hours ago (threshold: {$diffThreshHours}h).";
                    }
                }
                
                // 2. Check Log Backup (only for FULL or BULK_LOGGED models)
                if (in_array(strtoupper($b['recovery_model'] ?? ''), ['FULL', 'BULK_LOGGED'])) {
                    if (empty($b['last_log_backup'])) {
                        $overdueList[] = "{$dbName}: Recovery Model is {$b['recovery_model']}, but no LOG backup exists.";
                    } else {
                        $logAgeHours = ($now - strtotime($b['last_log_backup'])) / 3600;
                        if ($logAgeHours > $logThreshHours) {
                            $overdueList[] = "{$dbName}: Last LOG backup was " . round($logAgeHours, 1) . " hours ago (threshold: {$logThreshHours}h).";
                        }
                    }
                }
            }
            
            $isBackupBreached = !empty($overdueList);
            $msg = "Overdue or missing database backups detected on [{$serverName}]:\n" . implode("\n", $overdueList);
            
            evaluateAlertState(
                $serverId, $db, 'Database Backups', 
                $isBackupBreached, 'Warning',
                $msg,
                "All database backups on [{$serverName}] are now healthy and up-to-date.",
                $smtpSettings, $serverName, $env
            );
        }
    }

    // --- RULE 8: SQL Server Agent Job Failures ---
    $jobRulesEnabled = $smtpSettings['rules']['agent_jobs'] ?? true;
    if ($jobRulesEnabled) {
        $latestJobsStmt = $db->prepare("
            SELECT job_name, last_run_time, last_outcome_message 
            FROM agent_job_status 
            WHERE server_id = ? 
              AND current_status = 'Failed'
              AND collected_at = (SELECT MAX(collected_at) FROM agent_job_status WHERE server_id = ?)
        ");
        $latestJobsStmt->execute([$serverId, $serverId]);
        $failedJobs = $latestJobsStmt->fetchAll();
        
        $isJobBreached = !empty($failedJobs);
        
        if ($isJobBreached) {
            $failedList = [];
            foreach ($failedJobs as $fj) {
                $failedList[] = "Job: {$fj['job_name']} | Last Run: {$fj['last_run_time']} | Error: " . ($fj['last_outcome_message'] ? substr($fj['last_outcome_message'], 0, 200) . '...' : 'Unknown error');
            }
            $msg = "Failed SQL Server Agent jobs detected on [{$serverName}]:\n" . implode("\n", $failedList);
            
            evaluateAlertState(
                $serverId, $db, 'SQL Agent Jobs', 
                $isJobBreached, 'Critical',
                $msg,
                "All SQL Server Agent jobs on [{$serverName}] are now running successfully or idle.",
                $smtpSettings, $serverName, $env
            );
        } else {
            evaluateAlertState(
                $serverId, $db, 'SQL Agent Jobs', 
                false, 'Critical',
                "",
                "All SQL Server Agent jobs on [{$serverName}] are now running successfully or idle.",
                $smtpSettings, $serverName, $env
            );
        }
    }
}

/**
 * State machine managing alert transitions. Throttles ongoing active alerts, and 
 * triggers green resolutions when conditions return to normal.
 */
function evaluateAlertState($serverId, $db, $alertType, $isBreached, $severity, $breachMessage, $resolvedMessage, $smtpSettings, $serverName, $env) {
    // Get the most recent triggered alert of this type for the server
    $stmt = $db->prepare("SELECT * FROM triggered_alerts WHERE server_id = ? AND alert_type = ? ORDER BY collected_at DESC LIMIT 1");
    $stmt->execute([$serverId, $alertType]);
    $lastAlert = $stmt->fetch();

    $logTime = date('Y-m-d H:i:s');

    if ($isBreached) {
        $shouldTrigger = false;
        
        if (!$lastAlert || $lastAlert['severity'] === 'Resolved') {
            // New alert event!
            $shouldTrigger = true;
        } else {
            // Alert is already active. Check throttle limit (e.g. 2 hours)
            $lastTriggeredTime = strtotime($lastAlert['collected_at']);
            $timeDiff = time() - $lastTriggeredTime;
            if ($timeDiff >= 7200) { // 2 hours throttle
                $shouldTrigger = true;
            }
        }

        if ($shouldTrigger) {
            triggerAlertEvent($serverId, $db, $alertType, $severity, $breachMessage, $logTime, $smtpSettings, $serverName, $env);
        }
    } else {
        // Condition is normal. Check if there was an unresolved alert to mark as Resolved.
        if ($lastAlert && $lastAlert['severity'] !== 'Resolved') {
            triggerAlertEvent($serverId, $db, $alertType, 'Resolved', $resolvedMessage, $logTime, $smtpSettings, $serverName, $env);
        }
    }
}

/**
 * Logs alert event in DB, format-compiles the HTML email template, and runs the SMTP delivery attempt.
 */
function triggerAlertEvent($serverId, $db, $alertType, $severity, $message, $collectedAt, $smtpSettings, $serverName, $env) {
    $emailSent = 0;
    $emailError = null;

    // Compile Subject and Body
    $subject = "[{$severity}] SQLPrefmon Alert: {$alertType} on {$serverName} ({$env})";
    
    // HTML Email Template
    $themeColor = '#4e73df'; // Info blue
    if ($severity === 'Critical') {
        $themeColor = '#e74a3b'; // Red
    } elseif ($severity === 'Warning') {
        $themeColor = '#f6c23e'; // Yellow
    } elseif ($severity === 'Resolved') {
        $themeColor = '#1cc88a'; // Green
    }

    $formattedMessage = nl2br(sanitize($message));
    $encodedServerName = sanitize($serverName);
    $encodedEnv = strtoupper(sanitize($env));
    $encodedType = sanitize($alertType);
    $encodedTime = sanitize($collectedAt);
    
    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f8f9fc; margin: 0; padding: 20px; color: #3a3b45; }
            .card { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); overflow: hidden; border-top: 5px solid {$themeColor}; }
            .header { background: #1a1c2e; padding: 20px; text-align: center; color: #ffffff; }
            .header h2 { margin: 0; font-size: 20px; font-weight: 600; letter-spacing: 0.5px; }
            .content { padding: 30px; }
            .badge { display: inline-block; padding: 5px 12px; border-radius: 20px; color: #ffffff; font-size: 12px; font-weight: bold; text-transform: uppercase; background-color: {$themeColor}; margin-bottom: 20px; }
            .details-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; font-size: 14px; }
            .details-table td { padding: 10px; border-bottom: 1px solid #eaecf4; }
            .details-table td.label { font-weight: 600; color: #858796; width: 140px; }
            .message-box { background-color: #f1f3f9; border-left: 4px solid {$themeColor}; padding: 15px; border-radius: 4px; font-size: 14px; line-height: 1.5; font-family: monospace; white-space: pre-wrap; margin-bottom: 25px; }
            .footer { background: #f8f9fc; padding: 15px; text-align: center; font-size: 11px; color: #b7b9cc; border-top: 1px solid #eaecf4; }
        </style>
    </head>
    <body>
        <div class='card'>
            <div class='header'>
                <h2>SQLPrefmon Notification</h2>
            </div>
            <div class='content'>
                <div class='badge'>{$severity}</div>
                <table class='details-table'>
                    <tr>
                        <td class='label'>Server Name</td>
                        <td>{$encodedServerName}</td>
                    </tr>
                    <tr>
                        <td class='label'>Environment</td>
                        <td><span style='font-size: 11px; font-weight: bold;'>{$encodedEnv}</span></td>
                    </tr>
                    <tr>
                        <td class='label'>Alert Type</td>
                        <td>{$encodedType}</td>
                    </tr>
                    <tr>
                        <td class='label'>Trigger Time</td>
                        <td>{$encodedTime}</td>
                    </tr>
                </table>
                <div class='message-box'>{$formattedMessage}</div>
                <p style='font-size: 13px; color: #858796;'>Please check the SQLPrefmon Monitoring Dashboard for full DMV diagnostics and index optimization scripts.</p>
            </div>
            <div class='footer'>
                This is an automated performance report from SQLPrefmon. Please do not reply directly to this mail.
            </div>
        </div>
    </body>
    </html>
    ";

    // Attempt Send
    if ($smtpSettings['enabled']) {
        try {
            sendSmtpEmail(
                $smtpSettings['host'], 
                $smtpSettings['port'], 
                $smtpSettings['user'], 
                $smtpSettings['pass'], 
                $smtpSettings['secure'], 
                $smtpSettings['from'], 
                $smtpSettings['to'], 
                $subject, 
                $body
            );
            $emailSent = 1;
        } catch (Exception $e) {
            $emailSent = 0;
            $emailError = $e->getMessage();
        }
    } else {
        // SMTP disabled, fall back to mock logging (extremely useful for development & demo mode)
        $emailSent = 1;
        $emailError = "SMTP notifications are currently disabled (Mocked)";
        
        $emailLogPath = dirname(__DIR__) . '/logs/emails.log';
        if (!file_exists(dirname($emailLogPath))) {
            mkdir(dirname($emailLogPath), 0755, true);
        }
        
        $logEntry = "=====================================================================" . PHP_EOL;
        $logEntry .= "TIMESTAMP: " . date('Y-m-d H:i:s') . PHP_EOL;
        $logEntry .= "TO: " . $smtpSettings['to'] . PHP_EOL;
        $logEntry .= "FROM: " . $smtpSettings['from'] . PHP_EOL;
        $logEntry .= "SUBJECT: " . $subject . PHP_EOL;
        $logEntry .= "---------------------------------------------------------------------" . PHP_EOL;
        $logEntry .= "BODY SUMMARY: " . strip_tags($message) . PHP_EOL;
        $logEntry .= "=====================================================================" . PHP_EOL . PHP_EOL;
        
        file_put_contents($emailLogPath, $logEntry, FILE_APPEND);
    }

    // Insert alert log into SQLite
    try {
        $insert = $db->prepare("
            INSERT INTO triggered_alerts (server_id, collected_at, alert_type, severity, message, email_sent, email_error) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([$serverId, $collectedAt, $alertType, $severity, $message, $emailSent, $emailError]);
    } catch (Exception $ex) {
        error_log("Failed to insert triggered alert: " . $ex->getMessage());
    }
}

/**
 * Socket-based SMTP mail delivery handler. Runs entirely on native PHP commands.
 */
function sendSmtpEmail($host, $port, $username, $password, $secure, $from, $to, $subject, $body) {
    $timeout = 10;
    
    // Connect to host
    $remote = $host;
    if ($secure === 'ssl') {
        $remote = 'ssl://' . $host;
    }
    
    $socket = @fsockopen($remote, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        throw new Exception("Could not connect to SMTP host: $errstr ($errno)");
    }
    
    // Read greeting
    $response = fgets($socket, 1024);
    
    // Say HELO/EHLO
    fwrite($socket, "EHLO " . gethostname() . "\r\n");
    $response = '';
    while ($line = fgets($socket, 1024)) {
        $response .= $line;
        if (substr($line, 3, 1) === ' ') {
            break;
        }
    }
    
    // STARTTLS if secure is tls
    if ($secure === 'tls') {
        fwrite($socket, "STARTTLS\r\n");
        $response = fgets($socket, 1024);
        if (strpos($response, '220') === false) {
            fclose($socket);
            throw new Exception("STARTTLS failed: " . $response);
        }
        
        // Enable encryption on socket
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            throw new Exception("Encryption handshaking failed.");
        }
        
        // Resend EHLO after TLS start
        fwrite($socket, "EHLO " . gethostname() . "\r\n");
        $response = '';
        while ($line = fgets($socket, 1024)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
    }
    
    // Authenticate if username is provided
    if (!empty($username)) {
        fwrite($socket, "AUTH LOGIN\r\n");
        $response = fgets($socket, 1024);
        if (strpos($response, '334') === false) {
            fclose($socket);
            throw new Exception("AUTH LOGIN failed: " . $response);
        }
        
        fwrite($socket, base64_encode($username) . "\r\n");
        $response = fgets($socket, 1024);
        if (strpos($response, '334') === false) {
            fclose($socket);
            throw new Exception("Username authentication failed: " . $response);
        }
        
        fwrite($socket, base64_encode($password) . "\r\n");
        $response = fgets($socket, 1024);
        if (strpos($response, '235') === false) {
            fclose($socket);
            throw new Exception("Password authentication failed: " . $response);
        }
    }
    
    // Mail from
    fwrite($socket, "MAIL FROM:<$from>\r\n");
    $response = fgets($socket, 1024);
    if (strpos($response, '250') === false) {
        fclose($socket);
        throw new Exception("MAIL FROM failed: " . $response);
    }
    
    // Recipient
    $recipients = array_map('trim', explode(',', $to));
    foreach ($recipients as $recipient) {
        fwrite($socket, "RCPT TO:<$recipient>\r\n");
        $response = fgets($socket, 1024);
        if (strpos($response, '250') === false && strpos($response, '251') === false) {
            fclose($socket);
            throw new Exception("RCPT TO <$recipient> failed: " . $response);
        }
    }
    
    // Data
    fwrite($socket, "DATA\r\n");
    $response = fgets($socket, 1024);
    if (strpos($response, '354') === false) {
        fclose($socket);
        throw new Exception("DATA command failed: " . $response);
    }
    
    // Send message headers & body
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: <{$from}>\r\n";
    $headers .= "To: {$to}\r\n";
    $headers .= "Subject: {$subject}\r\n";
    $headers .= "Date: " . date('r') . "\r\n";
    
    // Ensure body doesn't end with a lone period on a newline
    $body = str_replace("\n.", "\n..", $body);
    
    fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
    $response = fgets($socket, 1024);
    if (strpos($response, '250') === false) {
        fclose($socket);
        throw new Exception("Sending message body failed: " . $response);
    }
    
    // Quit
    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    return true;
}

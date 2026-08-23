<?php
// api/trigger_scheduler.php

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

// Require session authentication to prevent external DOS / abuse if not run via CLI
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

header('Content-Type: application/json');

// Check and heal/start the high-frequency poller daemon if enabled
$pollerEnabled = getAppSetting('poller_enabled', false);
if ($pollerEnabled) {
    $heartbeatPath = dirname(__DIR__) . '/data/poller_heartbeat.json';
    $startPoller = true;
    if (file_exists($heartbeatPath)) {
        $hb = json_decode(file_get_contents($heartbeatPath), true);
        if (time() - (int)$hb['last_heartbeat'] <= 15) {
            $startPoller = false; // Already running and healthy
        }
    }
    
    if ($startPoller) {
        $pollerScript = dirname(__DIR__) . '/engine/poller.php';
        if (stristr(PHP_OS, 'WIN')) {
            $pollerCmd = "cmd /c start /B php -d extension=pdo_sqlite -d extension=openssl -d extension=pdo_odbc " . escapeshellarg($pollerScript) . " > NUL 2>&1";
            pclose(popen($pollerCmd, "r"));
        } else {
            $pollerCmd = "php -d extension=pdo_sqlite -d extension=openssl -d extension=pdo_odbc " . escapeshellarg($pollerScript) . " > /dev/null 2>&1 &";
            exec($pollerCmd);
        }
    }
}

// Check if scheduler is enabled
$schedulerEnabled = getAppSetting('scheduler_enabled', false);
if (!$schedulerEnabled) {
    echo json_encode(['status' => 'disabled', 'message' => 'Scheduler is disabled in settings.']);
    exit;
}

$intervalMinutes = (int)getAppSetting('scheduler_interval', 5);
$lastRun = (int)getAppSetting('scheduler_last_run', 0);
$currentTime = time();

if (($currentTime - $lastRun) < ($intervalMinutes * 60)) {
    echo json_encode([
        'status' => 'skipped',
        'message' => 'Interval not met yet.',
        'seconds_remaining' => ($intervalMinutes * 60) - ($currentTime - $lastRun)
    ]);
    exit;
}

// Check locking to prevent concurrent runs
$lockFile = dirname(__DIR__) . '/data/collector.lock';
$lockDir = dirname($lockFile);
if (!file_exists($lockDir)) {
    mkdir($lockDir, 0755, true);
}
$lockFp = fopen($lockFile, 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    if ($lockFp) {
        fclose($lockFp);
    }
    echo json_encode([
        'status' => 'skipped',
        'message' => 'Data collection is currently running in another process.'
    ]);
    exit;
}

// Release lock right before spawning background command to allow the child to lock it
flock($lockFp, LOCK_UN);
fclose($lockFp);

// Interval met, let's trigger the collection in the background
// We update last_run timestamp first to prevent other concurrent requests from triggering it
$settingsPath = dirname(__DIR__) . '/config/settings.json';
if (file_exists($settingsPath)) {
    $settings = json_decode(file_get_contents($settingsPath), true);
} else {
    $settings = [];
}
$settings['scheduler_last_run'] = $currentTime;
file_put_contents($settingsPath, json_encode($settings, JSON_PRETTY_PRINT));

// Now run the collector script asynchronously in the background
$scriptPath = dirname(__DIR__) . '/engine/collect.php';

if (stristr(PHP_OS, 'WIN')) {
    // Windows background execution
    // start /B is a cmd.exe shell builtin, so we must execute it through cmd.exe
    $cmd = "cmd /c start /B php -d extension=pdo_sqlite -d extension=openssl -d extension=pdo_odbc " . escapeshellarg($scriptPath) . " > NUL 2>&1";
    pclose(popen($cmd, "r"));
} else {
    // Linux/Unix background execution
    $cmd = "php -d extension=pdo_sqlite -d extension=openssl -d extension=pdo_odbc " . escapeshellarg($scriptPath) . " > /dev/null 2>&1 &";
    exec($cmd);
}

echo json_encode([
    'status' => 'triggered',
    'message' => 'Data collection triggered in background.',
    'last_run' => $currentTime
]);

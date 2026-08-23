<?php
// admin/settings.php

$pageTitle = 'Global Threshold Settings';
require_once dirname(__DIR__) . '/templates/header.php';
require_once dirname(__DIR__) . '/includes/role_check.php';

// Only administrators are allowed to update global thresholds
requireRole('admin');

$settingsPath = dirname(__DIR__) . '/config/settings.json';
$error = '';
$success = '';

// Check connection health of current active repository database
$repoStatus = 'Offline';
$repoInfo = '';
$statusClass = 'badge-danger';
try {
    $activeDb = getDbConnection();
    if ($activeDb instanceof PrefmonPDO) {
        $dbType = $activeDb->getDbType();
        if ($dbType === 'mssql') {
            $repoStatus = 'Active & Online';
            $statusClass = 'badge-success';
            $repoInfo = getAppSetting('repo_mssql_db', 'PrefmonRepo') . ' on ' . getAppSetting('repo_mssql_host', 'localhost') . ':' . getAppSetting('repo_mssql_port', '1433');
        } else {
            $repoStatus = 'Active & Online';
            $statusClass = 'badge-success';
            $repoInfo = realpath(dirname(__DIR__) . '/data/monitor.db') ?: (dirname(__DIR__) . '/data/monitor.db');
        }
    }
} catch (Exception $e) {
    $repoStatus = 'Connection Error: ' . $e->getMessage();
    $statusClass = 'badge-danger';
}

// Load current settings or use fallback defaults
$cpu = getAppSetting('cpu_threshold', THRESHOLD_CPU_PCT);
$ple = getAppSetting('ple_threshold', THRESHOLD_PLE_SEC);
$readLatency = getAppSetting('disk_read_latency', THRESHOLD_DISK_LATENCY_MS);
$recomp = getAppSetting('recompile_threshold', THRESHOLD_RECOMPILE_SEC);
$signalWait = getAppSetting('signal_wait_pct', THRESHOLD_SIGNAL_WAIT_PCT);
$indexFrag = getAppSetting('index_frag_pct', THRESHOLD_INDEX_FRAG_PCT);
$retention = getAppSetting('retention_days', 30);
$blockingMin = getAppSetting('blocking_threshold_min', THRESHOLD_BLOCKING_THRESHOLD_MIN);
$backupFull = getAppSetting('backup_full_threshold', 24);
$backupDiff = getAppSetting('backup_diff_threshold', 24);
$backupLog = getAppSetting('backup_log_threshold', 4);
$appName = getAppSetting('app_name', 'SQLPrefmon');
$schedulerEnabled = getAppSetting('scheduler_enabled', false);
$schedulerInterval = (int)getAppSetting('scheduler_interval', 5);
$schedulerLastRun = (int)getAppSetting('scheduler_last_run', 0);
$pollerEnabled = getAppSetting('poller_enabled', false);
$pollerInterval = (int)getAppSetting('poller_interval', 2);

$repoType = getAppSetting('repo_db_type', 'sqlite');
$repoHost = getAppSetting('repo_mssql_host', 'localhost');
$repoPort = getAppSetting('repo_mssql_port', '1433');
$repoDb = getAppSetting('repo_mssql_db', 'PrefmonRepo');
$repoUser = getAppSetting('repo_mssql_user', 'sa');
$repoPass = getAppSetting('repo_mssql_pass', '');
$repoAuth = getAppSetting('repo_mssql_auth', 'sql');
$repoTrustCert = getAppSetting('repo_mssql_trust_cert', 1);
$repoEncrypt = getAppSetting('repo_mssql_encrypt', 'mandatory');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCsrfToken($csrfToken)) {
        $error = 'Invalid security token.';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'migrate_data') {
        // Run migration script
        $migrationScript = dirname(__DIR__) . '/engine/migrate_db.php';
        if (file_exists($migrationScript)) {
            ob_start();
            try {
                include $migrationScript;
            } catch (Exception $ex) {
                echo "Migration Exception: " . $ex->getMessage();
            }
            $migrationOutput = ob_get_clean();
            
            if (strpos($migrationOutput, 'Migration Completed Successfully!') !== false) {
                $success = 'Database migration from SQLite to SQL Server completed successfully!';
            } else {
                $error = 'Database migration failed. Details: <pre style="background: rgba(0,0,0,0.2); padding: 0.5rem; border-radius: 4px; font-family: monospace; white-space: pre-wrap; font-size: 0.75rem; color: #ef4444; margin-top: 0.5rem; text-align: left;">' . sanitize($migrationOutput) . '</pre>';
            }
        } else {
            $error = 'Migration script not found.';
        }
    } else {
        $newCpu = (float)($_POST['cpu_threshold'] ?? THRESHOLD_CPU_PCT);
        $newPle = (int)($_POST['ple_threshold'] ?? THRESHOLD_PLE_SEC);
        $newReadLatency = (float)($_POST['disk_read_latency'] ?? THRESHOLD_DISK_LATENCY_MS);
        $newRecomp = (int)($_POST['recompile_threshold'] ?? THRESHOLD_RECOMPILE_SEC);
        $newSignalWait = (float)($_POST['signal_wait_pct'] ?? THRESHOLD_SIGNAL_WAIT_PCT);
        $newIndexFrag = (float)($_POST['index_frag_pct'] ?? THRESHOLD_INDEX_FRAG_PCT);
        $newRetention = (int)($_POST['retention_days'] ?? 30);
        $newBlockingMin = (int)($_POST['blocking_threshold_min'] ?? THRESHOLD_BLOCKING_THRESHOLD_MIN);
        $newBackupFull = (int)($_POST['backup_full_threshold'] ?? 24);
        $newBackupDiff = (int)($_POST['backup_diff_threshold'] ?? 24);
        $newBackupLog = (int)($_POST['backup_log_threshold'] ?? 4);
        $newAppName = $_POST['app_name'] ?? 'SQLPrefmon';
        $newSchedulerEnabled = isset($_POST['scheduler_enabled']) && $_POST['scheduler_enabled'] === '1';
        $newSchedulerInterval = (int)($_POST['scheduler_interval'] ?? 5);
        $newPollerEnabled = isset($_POST['poller_enabled']) && $_POST['poller_enabled'] === '1';
        $newPollerInterval = (int)($_POST['poller_interval'] ?? 2);

        $newRepoType = $_POST['repo_db_type'] ?? 'sqlite';
        $newRepoHost = $_POST['repo_mssql_host'] ?? 'localhost';
        $newRepoPort = $_POST['repo_mssql_port'] ?? '1433';
        $newRepoDb = $_POST['repo_mssql_db'] ?? 'PrefmonRepo';
        $newRepoUser = $_POST['repo_mssql_user'] ?? 'sa';
        $newRepoPass = $_POST['repo_mssql_pass'] ?? '';
        $newRepoAuth = $_POST['repo_mssql_auth'] ?? 'sql';
        $newRepoTrustCert = isset($_POST['repo_mssql_trust_cert']) ? 1 : 0;
        $newRepoEncrypt = $_POST['repo_mssql_encrypt'] ?? 'mandatory';
        
        // If type changed or parameters are updated for mssql, validate connection
        if ($newRepoType === 'mssql') {
            try {
                $encryptStr = "";
                if ($newRepoEncrypt === 'strict') {
                    $encryptStr = "Encrypt=Strict;";
                } elseif ($newRepoEncrypt === 'optional') {
                    $encryptStr = "Encrypt=no;";
                } else {
                    $encryptStr = $newRepoTrustCert ? "Encrypt=yes;TrustServerCertificate=yes;" : "Encrypt=yes;TrustServerCertificate=no;";
                }
                
                $testDsn = "odbc:Driver={ODBC Driver 18 for SQL Server};Server={$newRepoHost},{$newRepoPort};Database=master;{$encryptStr}";
                
                if ($newRepoAuth === 'windows') {
                    $testDsn .= "Trusted_Connection=yes;ConnectionTimeout=3;";
                    $dbUser = null;
                    $dbPass = null;
                } else {
                    $testDsn .= "ConnectionTimeout=3;";
                    $dbUser = $newRepoUser;
                    $dbPass = $newRepoPass;
                }
                
                $testDb = new PDO($testDsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);
                $testDb = null;
            } catch (PDOException $e) {
                $error = 'Test connection to MSSQL Server failed: ' . $e->getMessage();
            }
        }
        
        $newSettings = [
            'cpu_threshold' => $newCpu,
            'ple_threshold' => $newPle,
            'disk_read_latency' => $newReadLatency,
            'recompile_threshold' => $newRecomp,
            'signal_wait_pct' => $newSignalWait,
            'index_frag_pct' => $newIndexFrag,
            'retention_days' => $newRetention,
            'blocking_threshold_min' => $newBlockingMin,
            'backup_full_threshold' => $newBackupFull,
            'backup_diff_threshold' => $newBackupDiff,
            'backup_log_threshold' => $newBackupLog,
            'app_name' => $newAppName,
            'repo_db_type' => $newRepoType,
            'repo_mssql_host' => $newRepoHost,
            'repo_mssql_port' => $newRepoPort,
            'repo_mssql_db' => $newRepoDb,
            'repo_mssql_user' => $newRepoUser,
            'repo_mssql_pass' => $newRepoPass,
            'repo_mssql_auth' => $newRepoAuth,
            'repo_mssql_trust_cert' => $newRepoTrustCert,
            'repo_mssql_encrypt' => $newRepoEncrypt,
            'scheduler_enabled' => $newSchedulerEnabled,
            'scheduler_interval' => $newSchedulerInterval,
            'scheduler_last_run' => (int)getAppSetting('scheduler_last_run', 0),
            'poller_enabled' => $newPollerEnabled,
            'poller_interval' => $newPollerInterval
        ];
        
        // Write to settings.json
        if (file_put_contents($settingsPath, json_encode($newSettings, JSON_PRETTY_PRINT))) {
            logAuditEvent($_SESSION['user_id'], 'update_global_thresholds', 'config', null, 'Modified monitoring threshold parameters.');
            $success = 'Global settings and alert thresholds updated successfully.';
            
            // Refresh local state variables
            $cpu = $newCpu;
            $ple = $newPle;
            $readLatency = $newReadLatency;
            $recomp = $newRecomp;
            $signalWait = $newSignalWait;
            $indexFrag = $newIndexFrag;
            $retention = $newRetention;
            $blockingMin = $newBlockingMin;
            $backupFull = $newBackupFull;
            $backupDiff = $newBackupDiff;
            $backupLog = $newBackupLog;
            $appName = $newAppName;
            $repoType = $newRepoType;
            $repoHost = $newRepoHost;
            $repoPort = $newRepoPort;
            $repoDb = $newRepoDb;
            $repoUser = $newRepoUser;
            $repoPass = $newRepoPass;
            $repoAuth = $newRepoAuth;
            $repoTrustCert = $newRepoTrustCert;
            $repoEncrypt = $newRepoEncrypt;
            $schedulerEnabled = $newSchedulerEnabled;
            $schedulerInterval = $newSchedulerInterval;
            $pollerEnabled = $newPollerEnabled;
            $pollerInterval = $newPollerInterval;
        } else {
            $error = 'Failed to write configurations to settings file. Verify file permissions.';
        }
    }
}
?>

<div class="dashboard-header-container animate-fade-in">
    <div class="dashboard-title-area">
        <h2>Global Alert Thresholds & Settings</h2>
        <p>Fine-tune rule triggers, warning tolerances, and historical metrics database cleanups.</p>
    </div>
    <div>
        <a href="index.php" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i>
            Back to Admin
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger animate-fade-in">
        <i class="fa-solid fa-circle-exclamation alert-icon"></i>
        <span><?= sanitize($error) ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success animate-fade-in">
        <i class="fa-solid fa-circle-check alert-icon"></i>
        <span><?= sanitize($success) ?></span>
    </div>
<?php endif; ?>

<div class="glass-card animate-fade-in" style="animation-delay: 0.1s; max-width: 800px; margin-bottom: 2rem;">
    <h3 style="margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">
        <i class="fa-solid fa-sliders" style="color: var(--color-primary); margin-right: 0.5rem;"></i>
        Rule Engine Threshold Parameters
    </h3>
    
    <form action="settings.php" method="POST" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
        
        <div class="form-group">
            <label for="cpu_threshold">CPU Utilization Trigger (%)</label>
            <input type="number" id="cpu_threshold" name="cpu_threshold" step="0.1" value="<?= $cpu ?>" class="no-icon-input" required>
            <small style="color: var(--text-muted); font-size: 0.75rem; display: block; margin-top: 0.25rem;">Alert triggers if total CPU usage is sustained at or above this percentage.</small>
        </div>
        
        <div class="form-group">
            <label for="ple_threshold">Page Life Expectancy (Seconds)</label>
            <input type="number" id="ple_threshold" name="ple_threshold" value="<?= $ple ?>" class="no-icon-input" required>
            <small style="color: var(--text-muted); font-size: 0.75rem; display: block; margin-top: 0.25rem;">Critical warning triggers if PLE drops below this seconds metric.</small>
        </div>
        
        <div class="form-group">
            <label for="disk_read_latency">Avg Disk Read Latency Limit (ms)</label>
            <input type="number" id="disk_read_latency" name="disk_read_latency" step="0.1" value="<?= $readLatency ?>" class="no-icon-input" required>
            <small style="color: var(--text-muted); font-size: 0.75rem; display: block; margin-top: 0.25rem;">Warning triggers if disk physical read time exceeds this limit.</small>
        </div>
        
        <div class="form-group">
            <label for="recompile_threshold">Excessive Recompilations Rate (/sec)</label>
            <input type="number" id="recompile_threshold" name="recompile_threshold" value="<?= $recomp ?>" class="no-icon-input" required>
            <small style="color: var(--text-muted); font-size: 0.75rem; display: block; margin-top: 0.25rem;">Triggers warning if compilations exceed this threshold count per second.</small>
        </div>
        
        <div class="form-group">
            <label for="signal_wait_pct">High Signal Waits Ratio (%)</label>
            <input type="number" id="signal_wait_pct" name="signal_wait_pct" step="0.1" value="<?= $signalWait ?>" class="no-icon-input" required>
            <small style="color: var(--text-muted); font-size: 0.75rem; display: block; margin-top: 0.25rem;">Triggers warning if CPU scheduler queue delays exceed this percentage of waits.</small>
        </div>
        
        <div class="form-group">
            <label for="index_frag_pct">Index Rebuild Fragmentation limit (%)</label>
            <input type="number" id="index_frag_pct" name="index_frag_pct" step="0.1" value="<?= $indexFrag ?>" class="no-icon-input" required>
            <small style="color: var(--text-muted); font-size: 0.75rem; display: block; margin-top: 0.25rem;">Rebuild recommendation triggers if fragmentation equals or exceeds this limit.</small>
        </div>
        
        <div class="form-group" style="grid-column: span 2; border-top: 1px solid var(--border-glass); padding-top: 1.5rem;">
            <label for="blocking_threshold_min">Blocking Alert Threshold (Minutes)</label>
            <input type="number" id="blocking_threshold_min" name="blocking_threshold_min" value="<?= $blockingMin ?>" class="no-icon-input" style="max-width: 300px;" required min="1">
            <small style="color: var(--text-muted); font-size: 0.75rem; display: block; margin-top: 0.25rem;">Only block chains lasting longer than this limit will be recorded for historical analysis. Short/intermittent blocks will be ignored.</small>
        </div>

        <div class="form-group" style="grid-column: span 1; border-top: 1px solid var(--border-glass); padding-top: 1.5rem;">
            <label for="backup_full_threshold">Full Backup Overdue Limit (Hours)</label>
            <input type="number" id="backup_full_threshold" name="backup_full_threshold" value="<?= $backupFull ?>" class="no-icon-input" required min="1">
            <small style="color: var(--text-muted); font-size: 0.75rem; display: block; margin-top: 0.25rem;">Raise an alert if a database has no Full Backup within this age.</small>
        </div>

        <div class="form-group" style="grid-column: span 1; border-top: 1px solid var(--border-glass); padding-top: 1.5rem;">
            <label for="backup_diff_threshold">Diff Backup Overdue Limit (Hours)</label>
            <input type="number" id="backup_diff_threshold" name="backup_diff_threshold" value="<?= $backupDiff ?>" class="no-icon-input" required min="1">
            <small style="color: var(--text-muted); font-size: 0.75rem; display: block; margin-top: 0.25rem;">Raise an alert if a database has no Differential Backup within this age.</small>
        </div>

        <div class="form-group" style="grid-column: span 1; border-top: 1px solid var(--border-glass); padding-top: 1.5rem;">
            <label for="backup_log_threshold">Log Backup Overdue Limit (Hours)</label>
            <input type="number" id="backup_log_threshold" name="backup_log_threshold" value="<?= $backupLog ?>" class="no-icon-input" required min="1">
            <small style="color: var(--text-muted); font-size: 0.75rem; display: block; margin-top: 0.25rem;">Raise an alert if a FULL recovery model database has no Log Backup within this age.</small>
        </div>

        <div class="form-group" style="grid-column: span 2; border-top: 1px solid var(--border-glass); padding-top: 1.5rem;">
            <label for="retention_days">Data Store Retention Duration (Days)</label>
            <input type="number" id="retention_days" name="retention_days" value="<?= $retention ?>" class="no-icon-input" style="max-width: 300px;" required>
            <small style="color: var(--text-muted); font-size: 0.75rem; display: block; margin-top: 0.25rem;">Snapshots and history metrics older than this number of days will be automatically deleted in background collection cycles.</small>
        </div>

        <!-- Background Scheduler Settings -->
        <div style="grid-column: span 2; border-top: 2px solid var(--border-glass); padding-top: 2rem; margin-top: 1rem;">
            <h3 style="margin-bottom: 0.5rem; color: var(--color-warning); display: flex; align-items: center; gap: 0.4rem;">
                <i class="fa-solid fa-clock"></i>
                <span>Application-Level Background Scheduler</span>
            </h3>
            <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1rem;">
                Enable/disable automatic performance metrics collection without configuring OS level cron jobs or task schedulers. This triggers runs asynchronously when users browse the dashboard.
            </p>
        </div>

        <div class="form-group" style="grid-column: span 1;">
            <label for="scheduler_enabled">Enable Scheduler</label>
            <select id="scheduler_enabled" name="scheduler_enabled" class="no-icon-input">
                <option value="0" <?= !$schedulerEnabled ? 'selected' : '' ?>>Disabled (Use Cron/Task Scheduler)</option>
                <option value="1" <?= $schedulerEnabled ? 'selected' : '' ?>>Enabled (Trigger on user activity)</option>
            </select>
        </div>

        <div class="form-group" style="grid-column: span 1;">
            <label for="scheduler_interval">Collection Interval (Minutes)</label>
            <select id="scheduler_interval" name="scheduler_interval" class="no-icon-input">
                <option value="1" <?= $schedulerInterval === 1 ? 'selected' : '' ?>>Every 1 Minute</option>
                <option value="5" <?= $schedulerInterval === 5 ? 'selected' : '' ?>>Every 5 Minutes</option>
                <option value="10" <?= $schedulerInterval === 10 ? 'selected' : '' ?>>Every 10 Minutes</option>
                <option value="15" <?= $schedulerInterval === 15 ? 'selected' : '' ?>>Every 15 Minutes</option>
                <option value="30" <?= $schedulerInterval === 30 ? 'selected' : '' ?>>Every 30 Minutes</option>
                <option value="60" <?= $schedulerInterval === 60 ? 'selected' : '' ?>>Every 60 Minutes</option>
            </select>
        </div>

        <div style="grid-column: span 2; background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); border-radius: 6px; padding: 0.75rem 1rem; font-size: 0.85rem; display: flex; flex-direction: column; gap: 0.25rem; margin-bottom: 1rem;">
            <div>
                <span style="color: var(--text-secondary);">Last Scheduler Execution:</span>
                <strong style="color: #ffffff;">
                    <?= $schedulerLastRun > 0 ? date('Y-m-d H:i:s', $schedulerLastRun) : 'Never executed' ?>
                </strong>
            </div>
            <?php if ($schedulerLastRun > 0 && $schedulerEnabled): ?>
                <?php
                    $nextRunTime = $schedulerLastRun + ($schedulerInterval * 60);
                    $remaining = $nextRunTime - time();
                ?>
                <div>
                    <span style="color: var(--text-secondary);">Next Eligible Run:</span>
                    <strong style="color: #ffffff;">
                        <?= date('Y-m-d H:i:s', $nextRunTime) ?>
                        (<?= $remaining > 0 ? 'in ' . round($remaining / 60, 1) . ' mins' : 'eligible now' ?>)
                    </strong>
                </div>
            <?php endif; ?>
        </div>

        <!-- Active Session History Poller Settings -->
        <div style="grid-column: span 2; border-top: 2px solid var(--border-glass); padding-top: 2rem; margin-top: 1rem;">
            <h3 style="margin-bottom: 0.5rem; color: var(--color-primary); display: flex; align-items: center; gap: 0.4rem;">
                <i class="fa-solid fa-gauge-high"></i>
                <span>Active Session History (ASH) Polling Engine</span>
            </h3>
            <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1rem;">
                Continuous wait-state and query wait analysis. When enabled, a high-frequency polling script queries executing transactions in the background to log queries running between scheduler runs (e.g., SolarWinds DPA style).
            </p>
        </div>

        <div class="form-group" style="grid-column: span 1;">
            <label for="poller_enabled">Enable High-Frequency Poller</label>
            <select id="poller_enabled" name="poller_enabled" class="no-icon-input">
                <option value="0" <?= !$pollerEnabled ? 'selected' : '' ?>>Disabled</option>
                <option value="1" <?= $pollerEnabled ? 'selected' : '' ?>>Enabled (Continuous sampling)</option>
            </select>
        </div>

        <div class="form-group" style="grid-column: span 1;">
            <label for="poller_interval">Sampling Interval (Seconds)</label>
            <select id="poller_interval" name="poller_interval" class="no-icon-input">
                <option value="1" <?= $pollerInterval === 1 ? 'selected' : '' ?>>Every 1 Second</option>
                <option value="2" <?= $pollerInterval === 2 ? 'selected' : '' ?>>Every 2 Seconds (Recommended)</option>
                <option value="5" <?= $pollerInterval === 5 ? 'selected' : '' ?>>Every 5 Seconds</option>
                <option value="10" <?= $pollerInterval === 10 ? 'selected' : '' ?>>Every 10 Seconds</option>
            </select>
        </div>

        <div style="grid-column: span 2; background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); border-radius: 6px; padding: 0.75rem 1rem; font-size: 0.85rem; display: flex; flex-direction: column; gap: 0.25rem; margin-bottom: 1rem;">
            <?php
                $heartbeatPath = dirname(__DIR__) . '/data/poller_heartbeat.json';
                $pollerStatus = 'Inactive';
                $pollerStatusClass = 'text-muted';
                if (file_exists($heartbeatPath)) {
                    $hb = json_decode(file_get_contents($heartbeatPath), true);
                    if (time() - $hb['last_heartbeat'] <= 15 && $pollerEnabled) {
                        $pollerStatus = 'Active (PID: ' . $hb['pid'] . ')';
                        $pollerStatusClass = 'text-success';
                    }
                }
            ?>
            <div>
                <span style="color: var(--text-secondary);">Poller Engine Status:</span>
                <strong class="<?= $pollerStatusClass ?>"><?= $pollerStatus ?></strong>
            </div>
        </div>

        <!-- Repository Database Backend Options -->
        <div style="grid-column: span 2; border-top: 2px solid var(--border-glass); padding-top: 2rem; margin-top: 1rem;">
            <h3 style="margin-bottom: 0.5rem; color: var(--color-primary); display: flex; align-items: center; justify-content: space-between;">
                <span><i class="fa-solid fa-database"></i> Repository Storage Engine Settings</span>
                <span class="db-badge <?= $statusClass ?>" style="font-size: 0.75rem; padding: 0.3rem 0.75rem; letter-spacing: 0.5px; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.4rem; font-weight: 600;">
                    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: <?= $statusClass === 'badge-success' ? '#10b981' : '#ef4444' ?>; box-shadow: 0 0 8px <?= $statusClass === 'badge-success' ? '#10b981' : '#ef4444' ?>;"></span>
                    <?= $repoStatus ?>
                </span>
            </h3>
            <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1rem;">
                Choose where to persist performance monitoring historical data. Switching engines requires manually seeding users if no sync utility is run.
            </p>
            
            <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); border-radius: 6px; padding: 0.75rem 1rem; margin-bottom: 1.5rem; display: flex; gap: 0.5rem; align-items: center; font-size: 0.85rem;">
                <i class="fa-solid fa-circle-info" style="color: var(--color-primary);"></i>
                <span style="color: var(--text-secondary);">Current Active Data Store Location:</span>
                <strong style="color: #ffffff; word-break: break-all;"><?= sanitize($repoInfo) ?></strong>
            </div>

        </div>

        <div class="form-group" style="grid-column: span 2;">
            <label for="repo_db_type">Repository Engine Type</label>
            <select id="repo_db_type" name="repo_db_type" class="no-icon-input" onchange="toggleMssqlParams(this.value)">
                <option value="sqlite" <?= $repoType === 'sqlite' ? 'selected' : '' ?>>Portable SQLite DB (Default, zero configuration)</option>
                <option value="mssql" <?= $repoType === 'mssql' ? 'selected' : '' ?>>Microsoft SQL Server Database (Centralized repository storage)</option>
            </select>
        </div>

        <div id="mssql_repo_fields" style="grid-column: span 2; display: <?= $repoType === 'mssql' ? 'grid' : 'none' ?>; grid-template-columns: repeat(2, 1fr); gap: 1.5rem;">
            <div class="form-group">
                <label for="repo_mssql_host">MSSQL Server IP / Hostname</label>
                <input type="text" id="repo_mssql_host" name="repo_mssql_host" value="<?= sanitize($repoHost) ?>" class="no-icon-input">
            </div>
            <div class="form-group">
                <label for="repo_mssql_port">Server Port</label>
                <input type="text" id="repo_mssql_port" name="repo_mssql_port" value="<?= sanitize($repoPort) ?>" class="no-icon-input">
            </div>
            <div class="form-group">
                <label for="repo_mssql_db">Repository Database Name</label>
                <input type="text" id="repo_mssql_db" name="repo_mssql_db" value="<?= sanitize($repoDb) ?>" class="no-icon-input">
                <small style="color: var(--text-muted); font-size: 0.7rem; display: block; margin-top: 0.25rem;">Database will be automatically created if it does not exist.</small>
            </div>
            <div class="form-group">
                <label for="repo_mssql_auth">Authentication Type</label>
                <select id="repo_mssql_auth" name="repo_mssql_auth" class="no-icon-input" onchange="toggleMssqlAuth(this.value)">
                    <option value="sql" <?= ($repoAuth ?? 'sql') === 'sql' ? 'selected' : '' ?>>SQL Server Authentication</option>
                    <option value="windows" <?= ($repoAuth ?? 'sql') === 'windows' ? 'selected' : '' ?>>Windows Authentication (Integrated Security)</option>
                </select>
            </div>
            <div class="form-group">
                <label for="repo_mssql_encrypt">Connection Encryption Mode</label>
                <select id="repo_mssql_encrypt" name="repo_mssql_encrypt" class="no-icon-input" onchange="toggleMssqlEncrypt(this.value)">
                    <option value="optional" <?= ($repoEncrypt ?? 'mandatory') === 'optional' ? 'selected' : '' ?>>Optional / Disabled (Encrypt=no)</option>
                    <option value="mandatory" <?= ($repoEncrypt ?? 'mandatory') === 'mandatory' ? 'selected' : '' ?>>Mandatory / Encrypted (Encrypt=yes)</option>
                    <option value="strict" <?= ($repoEncrypt ?? 'mandatory') === 'strict' ? 'selected' : '' ?>>Strict Encryption (Encrypt=Strict, SQL Server 2022+)</option>
                </select>
            </div>
            <div class="form-group" id="repo_mssql_trust_cert_container" style="display: <?= ($repoEncrypt ?? 'mandatory') === 'mandatory' ? 'flex' : 'none' ?>; align-items: center; margin-top: 1.5rem;">
                <label for="repo_mssql_trust_cert" style="display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer; color: var(--text-secondary);">
                    <input type="checkbox" id="repo_mssql_trust_cert" name="repo_mssql_trust_cert" value="1" <?= ($repoTrustCert ?? 1) ? 'checked' : '' ?> style="width: 16px; height: 16px; cursor: pointer;">
                    <span>Trust Server Certificate</span>
                </label>
            </div>
            <div class="form-group" id="repo_mssql_user_container" style="grid-column: span 2;">
                <label for="repo_mssql_user">SysAdmin Login Username</label>
                <input type="text" id="repo_mssql_user" name="repo_mssql_user" value="<?= sanitize($repoUser) ?>" class="no-icon-input">
            </div>
            <div class="form-group" id="repo_mssql_pass_container" style="grid-column: span 2;">
                <label for="repo_mssql_pass">SysAdmin Password</label>
                <input type="password" id="repo_mssql_pass" name="repo_mssql_pass" value="<?= sanitize($repoPass) ?>" class="no-icon-input" placeholder="••••••••">
                <small style="color: var(--text-muted); font-size: 0.7rem; display: block; margin-top: 0.25rem;">Requires sysadmin privileges to create repository database, tables, and indexes.</small>
            </div>
        </div>

        <script>
        function toggleMssqlParams(val) {
            const el = document.getElementById('mssql_repo_fields');
            if (val === 'mssql') {
                el.style.display = 'grid';
            } else {
                el.style.display = 'none';
            }
        }
        
        function toggleMssqlAuth(val) {
            const userEl = document.getElementById('repo_mssql_user_container');
            const passEl = document.getElementById('repo_mssql_pass_container');
            if (val === 'windows') {
                if (userEl) userEl.style.display = 'none';
                if (passEl) passEl.style.display = 'none';
            } else {
                if (userEl) userEl.style.display = 'block';
                if (passEl) passEl.style.display = 'block';
            }
        }
        
        function toggleMssqlEncrypt(val) {
            const trustCertContainer = document.getElementById('repo_mssql_trust_cert_container');
            if (val === 'mandatory') {
                if (trustCertContainer) trustCertContainer.style.display = 'flex';
            } else {
                if (trustCertContainer) trustCertContainer.style.display = 'none';
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const authSelect = document.getElementById('repo_mssql_auth');
            if (authSelect) {
                toggleMssqlAuth(authSelect.value);
            }
            
            const encryptSelect = document.getElementById('repo_mssql_encrypt');
            if (encryptSelect) {
                toggleMssqlEncrypt(encryptSelect.value);
            }
        });
        </script>
        
        <!-- General Customization Settings -->
        <div style="grid-column: span 2; border-top: 2px solid var(--border-glass); padding-top: 2rem; margin-top: 1rem;">
            <h3 style="margin-bottom: 0.5rem; color: var(--color-primary); display: flex; align-items: center; gap: 0.4rem;">
                <i class="fa-solid fa-desktop"></i>
                <span>Branding Customization</span>
            </h3>
            <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1rem;">
                Customize general application appearance settings.
            </p>
        </div>

        <div class="form-group" style="grid-column: span 2;">
            <label for="app_name">Custom Tool Display Name</label>
            <input type="text" id="app_name" name="app_name" value="<?= sanitize($appName) ?>" class="no-icon-input" style="max-width: 400px;" required>
            <small style="color: var(--text-muted); font-size: 0.75rem; display: block; margin-top: 0.25rem;">Change the display title of this performance monitor tool. Default is <strong>SQLPrefmon</strong>.</small>
        </div>
        
        <div style="grid-column: span 2; display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1rem;">
            <button type="submit" class="btn btn-primary btn-glow">
                <i class="fa-solid fa-floppy-disk"></i>
                <span>Save Parameters</span>
            </button>
        </div>
    </form>

    <?php if ($repoType === 'sqlite'): ?>
        <div style="margin-top: 2rem; background: rgba(16, 185, 129, 0.03); border: 1px dashed rgba(16, 185, 129, 0.3); border-radius: 6px; padding: 1.25rem 1.5rem; font-size: 0.85rem; display: flex; flex-direction: column; gap: 0.5rem; grid-column: span 2;">
            <div style="font-weight: 600; color: #10b981; display: flex; align-items: center; gap: 0.5rem; font-size: 0.95rem;">
                <i class="fa-solid fa-truck-ramp-box"></i>
                <span>SQLite -> SQL Server Data Migration Utility</span>
            </div>
            <div style="color: var(--text-secondary); font-size: 0.8rem; line-height: 1.5;">
                Copy all performance snapshot history, registered server targets, audit logs, and user credentials from the current SQLite file to the configured SQL Server instance.
                <br><strong style="color: var(--color-warning);">Warning:</strong> This will overwrite target tables on your SQL Server repository database.
            </div>
            <div style="margin-top: 0.25rem;">
                <form method="POST" action="settings.php" onsubmit="return confirm('This will copy all rows from SQLite to SQL Server and overwrite existing rows. Proceed?');">
                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                    <button type="submit" name="action" value="migrate_data" class="btn btn-glow" style="background: #10b981; color: #000; font-weight: 600; border: none; padding: 0.4rem 1rem; font-size: 0.8rem; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                        <i class="fa-solid fa-play"></i> Start Migration Now
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
require_once dirname(__DIR__) . '/templates/footer.php';
?>

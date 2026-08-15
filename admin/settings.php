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

$repoType = getAppSetting('repo_db_type', 'sqlite');
$repoHost = getAppSetting('repo_mssql_host', 'localhost');
$repoPort = getAppSetting('repo_mssql_port', '1433');
$repoDb = getAppSetting('repo_mssql_db', 'PrefmonRepo');
$repoUser = getAppSetting('repo_mssql_user', 'sa');
$repoPass = getAppSetting('repo_mssql_pass', '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCsrfToken($csrfToken)) {
        $error = 'Invalid security token.';
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

        $newRepoType = $_POST['repo_db_type'] ?? 'sqlite';
        $newRepoHost = $_POST['repo_mssql_host'] ?? 'localhost';
        $newRepoPort = $_POST['repo_mssql_port'] ?? '1433';
        $newRepoDb = $_POST['repo_mssql_db'] ?? 'PrefmonRepo';
        $newRepoUser = $_POST['repo_mssql_user'] ?? 'sa';
        $newRepoPass = $_POST['repo_mssql_pass'] ?? '';
        
        // If type changed or parameters are updated for mssql, validate connection
        if ($newRepoType === 'mssql') {
            try {
                $testDsn = "odbc:Driver={ODBC Driver 18 for SQL Server};Server={$newRepoHost},{$newRepoPort};Database=master;Encrypt=yes;TrustServerCertificate=yes;ConnectionTimeout=3;";
                $testDb = new PDO($testDsn, $newRepoUser, $newRepoPass, [
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
            'repo_db_type' => $newRepoType,
            'repo_mssql_host' => $newRepoHost,
            'repo_mssql_port' => $newRepoPort,
            'repo_mssql_db' => $newRepoDb,
            'repo_mssql_user' => $newRepoUser,
            'repo_mssql_pass' => $newRepoPass
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
            $repoType = $newRepoType;
            $repoHost = $newRepoHost;
            $repoPort = $newRepoPort;
            $repoDb = $newRepoDb;
            $repoUser = $newRepoUser;
            $repoPass = $newRepoPass;
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
                <label for="repo_mssql_user">SysAdmin Login Username</label>
                <input type="text" id="repo_mssql_user" name="repo_mssql_user" value="<?= sanitize($repoUser) ?>" class="no-icon-input">
            </div>
            <div class="form-group" style="grid-column: span 2;">
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
        </script>
        
        <div style="grid-column: span 2; display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1rem;">
            <button type="submit" class="btn btn-primary btn-glow">
                <i class="fa-solid fa-floppy-disk"></i>
                <span>Save Parameters</span>
            </button>
        </div>
    </form>
</div>

<?php
require_once dirname(__DIR__) . '/templates/footer.php';
?>

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

// Load current settings or use fallback defaults
$cpu = getAppSetting('cpu_threshold', THRESHOLD_CPU_PCT);
$ple = getAppSetting('ple_threshold', THRESHOLD_PLE_SEC);
$readLatency = getAppSetting('disk_read_latency', THRESHOLD_DISK_LATENCY_MS);
$recomp = getAppSetting('recompile_threshold', THRESHOLD_RECOMPILE_SEC);
$signalWait = getAppSetting('signal_wait_pct', THRESHOLD_SIGNAL_WAIT_PCT);
$indexFrag = getAppSetting('index_frag_pct', THRESHOLD_INDEX_FRAG_PCT);
$retention = getAppSetting('retention_days', 30);

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
        
        $newSettings = [
            'cpu_threshold' => $newCpu,
            'ple_threshold' => $newPle,
            'disk_read_latency' => $newReadLatency,
            'recompile_threshold' => $newRecomp,
            'signal_wait_pct' => $newSignalWait,
            'index_frag_pct' => $newIndexFrag,
            'retention_days' => $newRetention
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
            <label for="retention_days">Data Store Retention Duration (Days)</label>
            <input type="number" id="retention_days" name="retention_days" value="<?= $retention ?>" class="no-icon-input" style="max-width: 300px;" required>
            <small style="color: var(--text-muted); font-size: 0.75rem; display: block; margin-top: 0.25rem;">Snapshots and history metrics older than this number of days will be automatically deleted in background collection cycles.</small>
        </div>
        
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

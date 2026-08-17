<?php
// history/jobs.php
$pageTitle = 'SQL Server Agent Job Statuses';

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/templates/header.php';

$db = getDbConnection();

// Fetch servers for filter dropdown
$servers = $db->query("SELECT id, display_name FROM servers ORDER BY display_name ASC")->fetchAll();

$serverId = isset($_GET['server_id']) ? (int)$_GET['server_id'] : (count($servers) > 0 ? (int)$servers[0]['id'] : 0);
$selectedJobId = isset($_GET['job_id']) ? $_GET['job_id'] : null;

$jobs = [];
$stepHistory = [];
$errorMsg = null;

if ($serverId > 0) {
    try {
        // 1. Fetch latest agent jobs snapshot
        $stmt = $db->prepare("
            SELECT * FROM agent_job_status 
            WHERE server_id = ? 
              AND collected_at = (SELECT MAX(collected_at) FROM agent_job_status WHERE server_id = ?)
            ORDER BY job_name ASC
        ");
        $stmt->execute([$serverId, $serverId]);
        $jobs = $stmt->fetchAll();

        // 2. Fetch step history for selected job if present
        if ($selectedJobId !== null) {
            $stmtHist = $db->prepare("
                SELECT * FROM agent_job_history 
                WHERE server_id = ? AND job_id = ?
                ORDER BY run_time DESC, step_id ASC
            ");
            $stmtHist->execute([$serverId, $selectedJobId]);
            $stepHistory = $stmtHist->fetchAll();
        }
    } catch (Exception $e) {
        $errorMsg = "Database error: " . $e->getMessage();
    }
}

// Compute counts for cards
$totalJobs = count($jobs);
$enabledCount = 0;
$runningCount = 0;
$succeededCount = 0;
$failedCount = 0;

foreach ($jobs as $j) {
    if ($j['enabled']) $enabledCount++;
    if ($j['current_status'] === 'Running') $runningCount++;
    elseif ($j['current_status'] === 'Failed') $failedCount++;
    elseif ($j['current_status'] === 'Succeeded') $succeededCount++;
}

// Helper to format duration
function formatJobDuration($sec) {
    if ($sec === null) return 'N/A';
    if ($sec < 60) return $sec . 's';
    $min = floor($sec / 60);
    $sec = $sec % 60;
    if ($min < 60) return $min . 'm ' . $sec . 's';
    $hr = floor($min / 60);
    $min = $min % 60;
    return $hr . 'h ' . $min . 'm ' . $sec . 's';
}

// Find selected job details for header info
$selectedJobDetails = null;
if ($selectedJobId !== null) {
    foreach ($jobs as $j) {
        if ($j['job_id'] === $selectedJobId) {
            $selectedJobDetails = $j;
            break;
        }
    }
}
?>

<div class="dashboard-header-container animate-fade-in">
    <div class="dashboard-title-area">
        <h2>SQL Server Agent Job Monitoring</h2>
        <p>Monitor scheduled tasks, verify step execution statuses, audit job failures, and perform step-by-step error analysis.</p>
    </div>
</div>

<!-- Search & Filtering Panel -->
<div class="glass-card animate-fade-in" style="animation-delay: 0.05s; padding: 1.25rem; margin-bottom: 1.5rem;">
    <form action="jobs.php" method="GET" style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
        <div class="form-group" style="margin-bottom: 0; min-width: 250px; max-width: 350px;">
            <label for="server_id" style="font-weight: 500; font-size: 0.85rem;">Monitored Server</label>
            <select id="server_id" name="server_id" class="no-icon-input" style="padding: 0.6rem 1rem;">
                <?php foreach ($servers as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $serverId === (int)$s['id'] ? 'selected' : '' ?>><?= sanitize($s['display_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <button type="submit" class="btn btn-primary" style="padding: 0.6rem 1.5rem; width: auto; margin-bottom: 0;">
            <i class="fa-solid fa-filter"></i> Select Server
        </button>
    </form>
</div>

<?php if ($errorMsg): ?>
    <div class="alert alert-danger animate-fade-in"><?= sanitize($errorMsg) ?></div>
<?php endif; ?>

<!-- Summary Cards Grid -->
<div class="metrics-grid animate-fade-in" style="animation-delay: 0.1s; margin-bottom: 1.5rem;">
    <div class="glass-card stat-card">
        <div class="stat-card-icon icon-blue">
            <i class="fa-solid fa-tasks"></i>
        </div>
        <div class="stat-card-details">
            <h4>Total Jobs Monitored</h4>
            <p><?= $totalJobs ?> <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: normal;">(<?= $enabledCount ?> Enabled)</span></p>
        </div>
    </div>
    
    <div class="glass-card stat-card">
        <div class="stat-card-icon icon-info">
            <i class="fa-solid fa-spinner fa-spin"></i>
        </div>
        <div class="stat-card-details">
            <h4>Active / Running</h4>
            <p><?= $runningCount ?></p>
        </div>
    </div>

    <div class="glass-card stat-card">
        <div class="stat-card-icon icon-success">
            <i class="fa-solid fa-circle-check"></i>
        </div>
        <div class="stat-card-details">
            <h4>Succeeded (Last Outcome)</h4>
            <p><?= $succeededCount ?></p>
        </div>
    </div>

    <div class="glass-card stat-card">
        <div class="stat-card-icon icon-danger">
            <i class="fa-solid fa-circle-xmark"></i>
        </div>
        <div class="stat-card-details">
            <h4>Failed (Last Outcome)</h4>
            <p><?= $failedCount ?></p>
        </div>
    </div>
</div>

<div class="grid-3 animate-fade-in" style="grid-template-columns: 1.4fr 1.6fr; gap: 1.5rem; animation-delay: 0.15s; margin-bottom: 1.5rem;">
    <!-- Job Inventory List -->
    <div class="glass-card" style="padding: 1.5rem; display: flex; flex-direction: column; min-height: 450px;">
        <h3 style="margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">
            <i class="fa-solid fa-clipboard-list" style="color: var(--color-primary); margin-right: 0.5rem;"></i>
            Job Inventory Status
        </h3>
        
        <?php if (empty($jobs)): ?>
            <div style="flex: 1; display: flex; align-items: center; justify-content: center; text-align: center; color: var(--text-muted); padding: 3rem;">
                <div>
                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 2rem; margin-bottom: 1rem; color: var(--color-warning);"></i>
                    <p>No SQL Server Agent jobs detected on this server snapshot. Please check if SQL Server Agent service is running and collector executed correctly.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="table-responsive" style="margin-top: 0; overflow-y: auto; max-height: 550px;">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Job Name</th>
                            <th>Enabled</th>
                            <th>Last Run / Duration</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($jobs as $j): 
                            $isSelected = ($selectedJobId === $j['job_id']);
                            $selectedRowStyle = $isSelected ? 'style="background: rgba(59, 130, 246, 0.08); border-left: 3px solid var(--color-primary);"' : '';
                        ?>
                            <tr <?= $selectedRowStyle ?>>
                                <td>
                                    <strong style="color: var(--text-primary);" title="<?= sanitize($j['description'] ?? '') ?>"><?= sanitize($j['job_name']) ?></strong>
                                </td>
                                <td>
                                    <?php if ($j['enabled']): ?>
                                        <span class="badge badge-success" style="font-size: 0.65rem;">Yes</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary" style="font-size: 0.65rem; background: var(--text-muted);">No</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (empty($j['last_run_time'])): ?>
                                        <span style="color: var(--text-muted); font-style: italic;">Never Run</span>
                                    <?php else: ?>
                                        <span style="font-size: 0.8rem;"><?= date('m-d H:i', strtotime($j['last_run_time'])) ?></span>
                                        <small style="color: var(--text-muted); display: block; font-size: 0.65rem;">
                                            Dur: <?= formatJobDuration($j['run_duration_sec']) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($j['current_status'] === 'Running'): ?>
                                        <span class="db-badge badge-info"><i class="fa-solid fa-spinner fa-spin"></i> Running</span>
                                    <?php elseif ($j['current_status'] === 'Failed'): ?>
                                        <span class="db-badge badge-danger">Failed</span>
                                    <?php elseif ($j['current_status'] === 'Succeeded'): ?>
                                        <span class="db-badge badge-success">Succeeded</span>
                                    <?php elseif ($j['current_status'] === 'Retry'): ?>
                                        <span class="db-badge badge-warning">Retry</span>
                                    <?php elseif ($j['current_status'] === 'Canceled'): ?>
                                        <span class="db-badge badge-secondary">Canceled</span>
                                    <?php else: ?>
                                        <span class="db-badge badge-secondary" style="background: rgba(255,255,255,0.05); color: var(--text-muted);"><?= sanitize($j['current_status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="jobs.php?server_id=<?= $serverId ?>&job_id=<?= urlencode($j['job_id']) ?>" class="btn btn-secondary" style="padding: 0.2rem 0.5rem; font-size: 0.75rem;">
                                        <i class="fa-solid fa-magnifying-glass"></i> Steps
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Step-by-step Exec History & Diagnostics -->
    <div class="glass-card" style="padding: 1.5rem; display: flex; flex-direction: column; min-height: 450px;">
        <h3 style="margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">
            <i class="fa-solid fa-wrench" style="color: var(--color-warning); margin-right: 0.5rem;"></i>
            Step-by-step Error & Execution Analysis
        </h3>
        
        <?php if ($selectedJobId === null): ?>
            <div style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; color: var(--text-muted); padding: 3rem;">
                <i class="fa-solid fa-route" style="font-size: 3rem; margin-bottom: 1.25rem; color: var(--color-primary); opacity: 0.4;"></i>
                <h4>No Job Selected</h4>
                <p style="font-size: 0.85rem; max-width: 320px; margin-top: 0.5rem;">Select a job from the inventory list on the left to review its step execution flows, run historical times, and diagnose step failure exceptions.</p>
            </div>
        <?php else: ?>
            <div style="margin-bottom: 1rem;">
                <h4 style="color: #ffffff; font-weight: 600; margin-bottom: 0.25rem;">
                    <?= sanitize($selectedJobDetails['job_name'] ?? 'Job Execution') ?>
                </h4>
                <p style="font-size: 0.8rem; color: var(--text-muted); line-height: 1.4; margin-bottom: 0.75rem;">
                    <?= sanitize($selectedJobDetails['description'] ?? 'No description provided.') ?>
                </p>
                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <span class="badge badge-info" style="font-size: 0.7rem; background: rgba(59, 130, 246, 0.15); border: 1px solid rgba(59, 130, 246, 0.25);">
                        ID: <?= sanitize($selectedJobId) ?>
                    </span>
                    <span class="badge badge-info" style="font-size: 0.7rem; background: rgba(255,255,255,0.05); border: 1px solid var(--border-glass);">
                        Last Outcome: <?= sanitize($selectedJobDetails['current_status'] ?? 'Unknown') ?>
                    </span>
                </div>
            </div>

            <?php if (empty($stepHistory)): ?>
                <div style="flex: 1; display: flex; align-items: center; justify-content: center; text-align: center; color: var(--text-muted); padding: 2rem;">
                    <p>No execution step history logged in the SQLite repository database for this job in the last 48 hours. Ensure the collector is running.</p>
                </div>
            <?php else: 
                // Group step executions by run time to show multiple separate job executions
                $runs = [];
                foreach ($stepHistory as $sh) {
                    $runTime = $sh['run_time'] ?? 'Unknown Run';
                    $runs[$runTime][] = $sh;
                }
                
                // Sort runs DESC by time key
                krsort($runs);
                reset($runs);
            ?>
                <div style="overflow-y: auto; max-height: 500px; padding-right: 0.25rem;">
                    <?php 
                    $runIdx = 0;
                    foreach ($runs as $timeKey => $steps): 
                        $runIdx++;
                        $isLatestRun = ($runIdx === 1);
                        
                        // Check if any step in this run failed
                        $hasFailure = false;
                        $outcomeMsg = '';
                        $jobDuration = 0;
                        foreach ($steps as $st) {
                            if ($st['run_status'] === 'Failed') {
                                $hasFailure = true;
                            }
                            if ($st['step_id'] === 0) {
                                $outcomeMsg = $st['message'];
                                $jobDuration = $st['run_duration_sec'];
                            }
                        }
                        
                        $runHeaderBorder = $hasFailure ? 'border-left: 4px solid var(--color-danger);' : 'border-left: 4px solid var(--color-success);';
                        $runHeaderBg = $hasFailure ? 'background: rgba(231, 74, 59, 0.05);' : 'background: rgba(28, 200, 138, 0.05);';
                    ?>
                        <div class="glass-card" style="margin-bottom: 1.25rem; padding: 1rem; <?= $runHeaderBg ?> <?= $runHeaderBorder ?> border-radius: 6px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.5rem; margin-bottom: 0.75rem;">
                                <div>
                                    <span style="font-weight: 600; font-size: 0.85rem; color: var(--text-primary);">
                                        Run: <?= date('Y-m-d H:i:s', strtotime($timeKey)) ?>
                                    </span>
                                    <small style="display: block; font-size: 0.7rem; color: var(--text-muted); margin-top: 0.1rem;">
                                        Duration: <?= formatJobDuration($jobDuration) ?>
                                    </small>
                                </div>
                                <div>
                                    <?php if ($hasFailure): ?>
                                        <span class="db-badge badge-danger" style="font-size: 0.7rem; padding: 0.15rem 0.4rem;">Failed Execution</span>
                                    <?php else: ?>
                                        <span class="db-badge badge-success" style="font-size: 0.7rem; padding: 0.15rem 0.4rem;">Success</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- List Steps of this run -->
                            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                <?php foreach ($steps as $st): 
                                    if ($st['step_id'] === 0) continue; // Skip overall outcome step
                                    $stepFailed = ($st['run_status'] === 'Failed');
                                    $stepBg = $stepFailed ? 'rgba(231, 74, 59, 0.08)' : 'rgba(255,255,255,0.02)';
                                    $stepBorder = $stepFailed ? '1px solid rgba(231, 74, 59, 0.25)' : '1px solid var(--border-glass)';
                                ?>
                                    <div style="background: <?= $stepBg ?>; border: <?= $stepBorder ?>; padding: 0.75rem; border-radius: 4px;">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.25rem;">
                                            <span style="font-size: 0.8rem; font-weight: 600; color: var(--text-primary);">
                                                Step <?= $st['step_id'] ?>: <?= sanitize($st['step_name']) ?>
                                            </span>
                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                <small style="color: var(--text-muted); font-size: 0.7rem;">
                                                    <?= formatJobDuration($st['run_duration_sec']) ?>
                                                </small>
                                                <?php if ($st['run_status'] === 'Failed'): ?>
                                                    <span class="badge badge-danger" style="font-size: 0.6rem; padding: 0.05rem 0.25rem;">Failed</span>
                                                <?php elseif ($st['run_status'] === 'Succeeded'): ?>
                                                    <span class="badge badge-success" style="font-size: 0.6rem; padding: 0.05rem 0.25rem;">Succeeded</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary" style="font-size: 0.6rem; padding: 0.05rem 0.25rem;"><?= sanitize($st['run_status']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <?php if (!empty($st['message'])): ?>
                                            <div style="background: rgba(0,0,0,0.25); font-family: 'Courier New', Courier, monospace; font-size: 0.75rem; color: <?= $stepFailed ? 'var(--color-danger)' : '#a1b0cb' ?>; padding: 0.5rem; border-radius: 3px; border-left: 2px solid <?= $stepFailed ? 'var(--color-danger)' : 'var(--color-primary)' ?>; margin-top: 0.4rem; white-space: pre-wrap; word-break: break-all; max-height: 120px; overflow-y: auto;">
                                                <?= sanitize($st['message']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Show overall outcome message if present and has error -->
                            <?php if ($hasFailure && !empty($outcomeMsg)): ?>
                                <div style="margin-top: 0.75rem; padding-top: 0.5rem; border-top: 1px dotted rgba(231,74,59,0.3);">
                                    <strong style="font-size: 0.75rem; color: var(--color-danger); display: block; margin-bottom: 0.2rem;">Final Outcome Message:</strong>
                                    <div style="background: rgba(0,0,0,0.3); font-family: 'Courier New', Courier, monospace; font-size: 0.75rem; color: #ffb7b2; padding: 0.5rem; border-radius: 3px; border-left: 3px solid var(--color-danger); white-space: pre-wrap; word-break: break-all;">
                                        <?= sanitize($outcomeMsg) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/templates/footer.php';
?>

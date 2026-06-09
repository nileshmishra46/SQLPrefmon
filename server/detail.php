<?php
// server/detail.php

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$db = getDbConnection();

$serverId = (int)($_GET['id'] ?? 0);
if ($serverId <= 0) {
    header("Location: ../dashboard/index.php");
    exit;
}

// Fetch server details
$stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch();

if (!$server) {
    header("Location: ../dashboard/index.php");
    exit;
}

$pageTitle = $server['display_name'] . ' Profile';
require_once dirname(__DIR__) . '/templates/header.php';

// Fetch latest metrics snapshot
$snapStmt = $db->prepare("SELECT * FROM metric_snapshots WHERE server_id = ? ORDER BY collected_at DESC LIMIT 1");
$snapStmt->execute([$serverId]);
$latest = $snapStmt->fetch();

// Fetch last 20 snapshots for time-series charts
$chartStmt = $db->prepare("SELECT collected_at, cpu_usage_pct, memory_used_mb, memory_total_mb, batch_req_sec, sql_recomp_sec FROM metric_snapshots WHERE server_id = ? ORDER BY collected_at DESC LIMIT 20");
$chartStmt->execute([$serverId]);
$history = array_reverse($chartStmt->fetchAll());

// Fetch latest wait stats
$waits = [];
if ($latest) {
    $waitsStmt = $db->prepare("SELECT * FROM wait_stats WHERE server_id = ? AND collected_at = ? ORDER BY wait_time_ms DESC");
    $waitsStmt->execute([$serverId, $latest['collected_at']]);
    $waits = $waitsStmt->fetchAll();
}

// Fetch latest queries
$queries = [];
if ($latest) {
    $queriesStmt = $db->prepare("SELECT * FROM top_queries WHERE server_id = ? AND collected_at = ? ORDER BY total_cpu_ms DESC");
    $queriesStmt->execute([$serverId, $latest['collected_at']]);
    $queries = $queriesStmt->fetchAll();
}

// Fetch index stats split by issues
$fragmentedIndexes = [];
$unusedIndexes = [];
$missingIndexes = [];
if ($latest) {
    $idxStmt = $db->prepare("SELECT * FROM index_stats WHERE server_id = ? AND collected_at = ?");
    $idxStmt->execute([$serverId, $latest['collected_at']]);
    $idxStats = $idxStmt->fetchAll();
    
    foreach ($idxStats as $idx) {
        if ($idx['issue_type'] === 'fragmented') {
            $fragmentedIndexes[] = $idx;
        } elseif ($idx['issue_type'] === 'unused') {
            $unusedIndexes[] = $idx;
        } elseif ($idx['issue_type'] === 'missing') {
            $missingIndexes[] = $idx;
        }
    }
}
?>

<!-- Header -->
<div class="dashboard-header-container animate-fade-in">
    <div class="dashboard-title-area">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <h2><?= sanitize($server['display_name']) ?></h2>
            <span class="badge <?= $server['environment'] === 'production' ? 'env-production' : ($server['environment'] === 'staging' ? 'env-staging' : ($server['environment'] === 'dev' ? 'env-dev' : 'env-demo')) ?>">
                <?= sanitize($server['environment']) ?>
            </span>
            <span class="server-status-dot <?= $server['last_status'] === 'online' ? 'status-online' : 'status-offline' ?>" title="Status: <?= sanitize($server['last_status']) ?>"></span>
        </div>
        <p>Hostname: <code style="color: var(--color-primary);"><?= sanitize($server['hostname']) ?></code> &bull; Last checked: <?= sanitize($server['last_checked'] ?: 'Never') ?></p>
    </div>
    <div class="flex-gap-1">
        <!-- Manual Collection Trigger -->
        <form action="../engine/collect.php" method="GET" target="collect_frame" onsubmit="document.getElementById('collect_btn').innerHTML = '<i class=\'fa-solid fa-spinner fa-spin\'></i> Refreshing...';">
            <button type="submit" id="collect_btn" class="btn btn-secondary">
                <i class="fa-solid fa-arrows-rotate"></i>
                <span>Run Diagnostics</span>
            </button>
        </form>
        <iframe name="collect_frame" style="display:none;" onload="if(window.collectRun) { window.location.reload(); } window.collectRun=true;"></iframe>
        
        <?php if (isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'dba')): ?>
            <a href="../admin/servers.php" class="btn btn-secondary" title="Configure Settings">
                <i class="fa-solid fa-gear"></i>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$latest): ?>
    <div class="glass-card animate-fade-in" style="text-align: center; padding: 4rem 2rem; color: var(--text-secondary);">
        <i class="fa-solid fa-hourglass-empty" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1.5rem;"></i>
        <h2>Diagnostics Data Pending</h2>
        <p style="margin-top: 0.5rem; margin-bottom: 1.5rem;">The collector has not gathered any metrics snapshots for this server yet. Run a manual update using the button above.</p>
    </div>
<?php else: ?>

    <!-- Tabs Layout -->
    <div class="tabs-container animate-fade-in" style="animation-delay: 0.05s;">
        <div class="tabs-header">
            <button class="tab-btn active" data-tab="tab-overview">
                <i class="fa-solid fa-gauge-high"></i> Overview
            </button>
            <button class="tab-btn" data-tab="tab-waits">
                <i class="fa-solid fa-business-time"></i> Wait Statistics
            </button>
            <button class="tab-btn" data-tab="tab-queries">
                <i class="fa-solid fa-fire-flame-simple"></i> Expensive Queries
            </button>
            <button class="tab-btn" data-tab="tab-indexes">
                <i class="fa-solid fa-list-check"></i> Index Advisor
            </button>
        </div>
        
        <!-- Tab: Overview -->
        <div id="tab-overview" class="tab-pane active">
            <!-- Sparkline Statistics Overview -->
            <div class="metrics-grid-3" style="margin-bottom: 1.5rem;">
                <div class="glass-card stat-card" style="border-left: 4px solid var(--color-primary);">
                    <div class="stat-card-details">
                        <h4>CPU Usage</h4>
                        <p><?= round($latest['cpu_usage_pct'], 1) ?>%</p>
                    </div>
                </div>
                <div class="glass-card stat-card" style="border-left: 4px solid var(--color-success);">
                    <div class="stat-card-details">
                        <h4>Buffer Pool Memory</h4>
                        <p><?= round($latest['memory_used_mb']/1024, 1) ?> GB <span style="font-size: 0.8rem; font-weight: normal; color: var(--text-secondary);">allocated</span></p>
                    </div>
                </div>
                <div class="glass-card stat-card" style="border-left: 4px solid <?= $latest['page_life_exp'] < 300 ? 'var(--color-danger)' : 'var(--color-warning)' ?>;">
                    <div class="stat-card-details">
                        <h4>Page Life Expectancy</h4>
                        <p><?= $latest['page_life_exp'] ?>s</p>
                    </div>
                </div>
            </div>
            
            <div class="grid-2">
                <!-- Historic Metric Charts -->
                <div class="glass-card">
                    <h3 style="margin-bottom: 1rem;">CPU & Buffer Memory Trends (20 snapshots)</h3>
                    <div style="height: 250px; position: relative;">
                        <canvas id="cpuTrendChart"></canvas>
                    </div>
                </div>
                
                <!-- Detailed Metric Fields -->
                <div class="glass-card">
                    <h3 style="margin-bottom: 1rem;">Performance Snapshot Details</h3>
                    <div style="display: flex; flex-direction: column; gap: 0.75rem; font-size: 0.9rem;">
                        <div class="flex-between" style="border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.4rem;">
                            <span>Active Client Connections:</span>
                            <strong><?= (int)$latest['active_conn'] ?></strong>
                        </div>
                        <div class="flex-between" style="border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.4rem; color: <?= $latest['blocked_procs'] > 0 ? 'var(--color-danger)' : 'inherit' ?>;">
                            <span>Blocked Processes Chain:</span>
                            <strong><?= (int)$latest['blocked_procs'] ?></strong>
                        </div>
                        <div class="flex-between" style="border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.4rem;">
                            <span>Disk Latency (Read / Write):</span>
                            <strong><?= round($latest['disk_read_ms'], 1) ?>ms / <?= round($latest['disk_write_ms'], 1) ?>ms</strong>
                        </div>
                        <div class="flex-between" style="border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.4rem;">
                            <span>TempDB Size Allocation:</span>
                            <strong><?= round($latest['tempdb_used_mb'], 1) ?> MB</strong>
                        </div>
                        <div class="flex-between" style="border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.4rem;">
                            <span>Batch Commands Rate:</span>
                            <strong><?= number_format($latest['batch_req_sec']) ?> requests</strong>
                        </div>
                        <div class="flex-between" style="border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.4rem;">
                            <span>SQL Re-compilations Count:</span>
                            <strong><?= number_format($latest['sql_recomp_sec']) ?></strong>
                        </div>
                        <div class="flex-between" style="border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.4rem;">
                            <span>Deadlock Occurrences Count:</span>
                            <strong><?= (int)$latest['deadlocks_sec'] ?></strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tab: Wait Statistics -->
        <div id="tab-waits" class="tab-pane">
            <div class="grid-2" style="grid-template-columns: 1fr 1.2fr; gap: 1.5rem;">
                <div class="glass-card">
                    <h3 style="margin-bottom: 1rem;">Resource Bottleneck Allocation</h3>
                    <?php if (empty($waits)): ?>
                        <p style="color: var(--text-secondary); font-style: italic;">No waits recorded.</p>
                    <?php else: ?>
                        <div style="height: 300px; position: relative;">
                            <canvas id="serverWaitsChart"></canvas>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="glass-card">
                    <h3 style="margin-bottom: 1rem;">DMV sys.dm_os_wait_stats</h3>
                    <div class="table-responsive" style="margin-top: 0;">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Wait Type</th>
                                    <th style="text-align: right;">Time (ms)</th>
                                    <th style="text-align: right;">Tasks Count</th>
                                    <th style="text-align: right;">Signal Wait (ms)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($waits)): ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; color: var(--text-muted); font-style: italic;">No waits found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($waits as $w): ?>
                                        <tr>
                                            <td><strong><?= sanitize($w['wait_type']) ?></strong></td>
                                            <td style="text-align: right; font-family: monospace;"><?= number_format($w['wait_time_ms']) ?></td>
                                            <td style="text-align: right; font-family: monospace;"><?= number_format($w['waiting_tasks']) ?></td>
                                            <td style="text-align: right; font-family: monospace; color: var(--text-secondary);"><?= number_format($w['signal_wait_ms']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tab: Expensive Queries -->
        <div id="tab-queries" class="tab-pane">
            <div class="glass-card">
                <h3 style="margin-bottom: 1rem;">Top Expensive Cached Statements</h3>
                <div class="table-responsive" style="margin-top: 0;">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>Database</th>
                                <th>Statement text</th>
                                <th style="text-align: right;">Total CPU (ms)</th>
                                <th style="text-align: right;">Total Duration (ms)</th>
                                <th style="text-align: right;">Total Reads</th>
                                <th style="text-align: right;">Execs</th>
                                <th style="text-align: right;">Avg CPU (ms)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($queries)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: var(--text-muted); font-style: italic;">No queries collected.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($queries as $q): ?>
                                    <tr>
                                        <td><span class="badge badge-info"><?= sanitize($q['database_name']) ?></span></td>
                                        <td>
                                            <pre style="margin: 0; color: #a5d6ff; font-family: monospace; font-size: 0.8rem; max-width: 450px; overflow-x: auto; white-space: pre-wrap; word-break: break-all; max-height: 80px; overflow-y: auto;"><?= sanitize($q['query_text']) ?></pre>
                                        </td>
                                        <td style="text-align: right; font-family: monospace;"><?= number_format($q['total_cpu_ms'], 1) ?></td>
                                        <td style="text-align: right; font-family: monospace;"><?= number_format($q['total_elapsed_ms'], 1) ?></td>
                                        <td style="text-align: right; font-family: monospace;"><?= number_format($q['total_logical_reads']) ?></td>
                                        <td style="text-align: right; font-family: monospace;"><?= (int)$q['execution_count'] ?></td>
                                        <td style="text-align: right; font-family: monospace; font-weight: 600; color: var(--color-warning);"><?= number_format($q['avg_cpu_ms'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Tab: Index Advisor -->
        <div id="tab-indexes" class="tab-pane">
            <!-- Missing Indexes Card -->
            <div class="glass-card" style="margin-bottom: 1.5rem;">
                <h3 style="margin-bottom: 0.5rem; color: var(--color-primary);">Missing Index Recommendations</h3>
                <p style="color: var(--text-secondary); font-size: 0.85rem; margin-bottom: 1rem;">Recommended indexes detected by SQL Server execution planner based on query overhead costs.</p>
                <div class="table-responsive" style="margin-top: 0;">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>Database</th>
                                <th>Table</th>
                                <th>Equality Keys</th>
                                <th>Inequality Keys</th>
                                <th>Included Columns</th>
                                <th style="text-align: right;">Benefit score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($missingIndexes)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: var(--text-muted); font-style: italic;">No missing indexes identified.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($missingIndexes as $idx): ?>
                                    <tr>
                                        <td><strong><?= sanitize($idx['database_name']) ?></strong></td>
                                        <td><?= sanitize($idx['table_name']) ?></td>
                                        <td><code style="color: #61afef;"><?= sanitize($idx['user_seeks'] ?: '-') ?></code></td>
                                        <td><code style="color: #ffaa44;"><?= sanitize($idx['user_scans'] ?: '-') ?></code></td>
                                        <td><code style="color: var(--text-secondary);"><?= sanitize($idx['user_lookups'] ?: '-') ?></code></td>
                                        <td style="text-align: right; font-family: monospace; font-weight: 600; color: var(--color-danger);"><?= number_format($idx['fragmentation_pct']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="grid-2">
                <!-- Fragmented Indexes -->
                <div class="glass-card">
                    <h3 style="margin-bottom: 1rem; color: var(--color-warning);">Highly Fragmented Indexes</h3>
                    <div class="table-responsive" style="margin-top: 0;">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Index Name</th>
                                    <th>Table</th>
                                    <th style="text-align: right;">Frag %</th>
                                    <th style="text-align: right;">Pages</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($fragmentedIndexes)): ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; color: var(--text-muted); font-style: italic;">No fragmentation alerts.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($fragmentedIndexes as $idx): 
                                        $fVal = (float)$idx['fragmentation_pct'];
                                    ?>
                                        <tr>
                                            <td>
                                                <strong><?= sanitize($idx['index_name']) ?></strong><br>
                                                <small style="color: var(--text-muted);"><?= sanitize($idx['index_type']) ?></small>
                                            </td>
                                            <td><?= sanitize($idx['table_name']) ?></td>
                                            <td style="text-align: right; font-family: monospace; font-weight: 600; color: <?= $fVal > 30 ? 'var(--color-danger)' : 'var(--color-warning)' ?>;"><?= round($fVal, 1) ?>%</td>
                                            <td style="text-align: right; font-family: monospace; color: var(--text-secondary);"><?= number_format($idx['page_count']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Unused Indexes -->
                <div class="glass-card">
                    <h3 style="margin-bottom: 1rem; color: var(--color-info);">Unused Database Indexes</h3>
                    <div class="table-responsive" style="margin-top: 0;">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Index Name</th>
                                    <th>Table</th>
                                    <th style="text-align: right;">Writes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($unusedIndexes)): ?>
                                    <tr>
                                        <td colspan="3" style="text-align: center; color: var(--text-muted); font-style: italic;">No unused index alerts.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($unusedIndexes as $idx): ?>
                                        <tr>
                                            <td>
                                                <strong><?= sanitize($idx['index_name']) ?></strong><br>
                                                <small style="color: var(--text-muted);"><?= sanitize($idx['index_type']) ?></small>
                                            </td>
                                            <td><?= sanitize($idx['table_name']) ?></td>
                                            <td style="text-align: right; font-family: monospace; color: var(--color-danger);"><?= number_format($idx['user_updates']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ChartJS trends and wait scripts -->
    <?php 
    // Format History Chart inputs
    $timeLabels = [];
    $cpuData = [];
    $memData = [];
    foreach ($history as $h) {
        $timeLabels[] = date('H:i', strtotime($h['collected_at']));
        $cpuData[] = (float)$h['cpu_usage_pct'];
        // calculate Memory Used percentage
        $memData[] = $h['memory_total_mb'] > 0 ? (($h['memory_used_mb'] / $h['memory_total_mb']) * 100) : 0;
    }
    
    // Format Waits Chart inputs
    $waitLabels = [];
    $waitTimeData = [];
    foreach ($waits as $w) {
        $waitLabels[] = $w['wait_type'];
        $waitTimeData[] = (float)$w['wait_time_ms'];
    }
    ?>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        // 1. Overview Sparkline Trends Chart
        const cpuCtx = document.getElementById('cpuTrendChart');
        if (cpuCtx) {
            new Chart(cpuCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($timeLabels) ?>,
                    datasets: [
                        {
                            label: 'CPU Usage %',
                            data: <?= json_encode($cpuData) ?>,
                            borderColor: '#0088ff',
                            backgroundColor: 'rgba(0,136,255,0.05)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: true
                        },
                        {
                            label: 'RAM Allocated %',
                            data: <?= json_encode($memData) ?>,
                            borderColor: '#107c10',
                            backgroundColor: 'rgba(16,124,16,0.03)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            labels: { color: '#9ca3af', font: { family: 'Inter' } }
                        }
                    },
                    scales: {
                        x: {
                            grid: { color: 'rgba(255,255,255,0.03)' },
                            ticks: { color: '#9ca3af', font: { family: 'Inter', size: 10 } }
                        },
                        y: {
                            grid: { color: 'rgba(255,255,255,0.03)' },
                            ticks: { color: '#9ca3af', font: { family: 'Inter' } },
                            min: 0,
                            max: 100
                        }
                    }
                }
            });
        }
        
        // 2. Waits stats distribution chart
        const waitsCtx = document.getElementById('serverWaitsChart');
        if (waitsCtx) {
            new Chart(waitsCtx, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode($waitLabels) ?>,
                    datasets: [{
                        data: <?= json_encode($waitTimeData) ?>,
                        backgroundColor: [
                            '#0088ff',
                            '#00bcf2',
                            '#ffaa44',
                            '#d13438',
                            '#107c10',
                            '#a0aec0',
                            '#6b7280'
                        ],
                        borderWidth: 1.5,
                        borderColor: '#111827'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: { color: '#ffffff', font: { family: 'Inter', size: 10 } }
                        }
                    }
                }
            });
        }
    });
    </script>
<?php endif; ?>

<?php
require_once dirname(__DIR__) . '/templates/footer.php';
?>

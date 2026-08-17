<?php
// dashboard/index.php

$pageTitle = 'Dashboard Overview';
require_once dirname(__DIR__) . '/templates/header.php';

$db = getDbConnection();

// Fetch summary metrics
$serversCount = (int)$db->query("SELECT COUNT(*) FROM servers")->fetchColumn();
$onlineCount = (int)$db->query("SELECT COUNT(*) FROM servers WHERE last_status = 'online'")->fetchColumn();
$offlineCount = (int)$db->query("SELECT COUNT(*) FROM servers WHERE last_status IN ('offline', 'error')")->fetchColumn();
$activeRecsCount = (int)$db->query("SELECT COUNT(*) FROM recommendations WHERE is_resolved = 0")->fetchColumn();

// Fetch servers and their latest snapshot
$servers = $db->query("SELECT * FROM servers ORDER BY display_name ASC")->fetchAll();
$serverHealth = [];
foreach ($servers as $srv) {
    $snapStmt = $db->prepare("SELECT * FROM metric_snapshots WHERE server_id = ? ORDER BY collected_at DESC LIMIT 1");
    $snapStmt->execute([$srv['id']]);
    $snap = $snapStmt->fetch();
    
    $serverHealth[] = [
        'server' => $srv,
        'snapshot' => $snap
    ];
}

// Fetch global aggregated waits (from the latest snapshot of each server)
$globalWaits = [];
try {
    $globalWaits = $db->query("
        SELECT wait_type, SUM(wait_time_ms) as total_wait_time
        FROM wait_stats 
        WHERE collected_at IN (
            SELECT MAX(collected_at) FROM wait_stats GROUP BY server_id
        )
        GROUP BY wait_type
        ORDER BY total_wait_time DESC
        LIMIT 5
    ")->fetchAll();
} catch (Exception $e) {}

// Fetch global top expensive queries
$globalQueries = [];
try {
    $globalQueries = $db->query("
        SELECT q.*, s.display_name as server_name 
        FROM top_queries q
        JOIN servers s ON q.server_id = s.id
        WHERE q.collected_at IN (
            SELECT MAX(collected_at) FROM top_queries GROUP BY server_id
        )
        ORDER BY q.total_cpu_ms DESC 
        LIMIT 5
    ")->fetchAll();
} catch (Exception $e) {}

// Fetch active recommendations
$activeRecs = [];
try {
    $activeRecs = $db->query("
        SELECT r.*, s.display_name as server_name
        FROM recommendations r
        JOIN servers s ON r.server_id = s.id
        WHERE r.is_resolved = 0
        ORDER BY r.generated_at DESC
        LIMIT 5
    ")->fetchAll();
} catch (Exception $e) {}
?>

<!-- Dashboard Header -->
<div class="dashboard-header-container animate-fade-in">
    <div class="dashboard-title-area">
        <h2>Dashboard Overview</h2>
        <p>Real-time health index, query load distribution, and automated tuning recommendations.</p>
    </div>
    <div class="flex-gap-1">
        <!-- Manual Collection Trigger -->
        <form action="../engine/collect.php" method="GET" target="collect_frame" onsubmit="document.getElementById('collect_btn').innerHTML = '<i class=\'fa-solid fa-spinner fa-spin\'></i> Collecting...';">
            <button type="submit" id="collect_btn" class="btn btn-secondary">
                <i class="fa-solid fa-arrows-rotate"></i>
                <span>Refresh Diagnostics</span>
            </button>
        </form>
        <iframe name="collect_frame" style="display:none;" onload="if(window.collectRun) { window.location.reload(); } window.collectRun=true;"></iframe>
    </div>
</div>

<!-- Summary Metric Cards -->
<div class="metrics-grid-3 animate-fade-in" style="animation-delay: 0.05s;">
    <div class="glass-card stat-card">
        <div class="stat-card-icon icon-blue">
            <i class="fa-solid fa-database"></i>
        </div>
        <div class="stat-card-details">
            <h4>Connected Servers</h4>
            <p><?= $serversCount ?> <span style="font-size: 0.8rem; font-weight: normal; color: var(--text-secondary);">total</span></p>
        </div>
    </div>
    
    <div class="glass-card stat-card">
        <div class="stat-card-icon icon-success">
            <i class="fa-solid fa-circle-check"></i>
        </div>
        <div class="stat-card-details">
            <h4>Online Instances</h4>
            <p><?= $onlineCount ?> <span style="font-size: 0.8rem; font-weight: normal; color: var(--text-secondary);">active</span></p>
        </div>
    </div>
    
    <div class="glass-card stat-card">
        <div class="stat-card-icon icon-danger pulse-badge">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <div class="stat-card-details">
            <h4>Tuning Alerts</h4>
            <p><?= $activeRecsCount ?> <span style="font-size: 0.8rem; font-weight: normal; color: var(--text-secondary);">critical/warn</span></p>
        </div>
    </div>
</div>

<!-- Server Health Tiles Grid -->
<div class="animate-fade-in" style="animation-delay: 0.1s;">
    <h3 style="margin-bottom: 1rem;">Server Infrastructure Health</h3>
    
    <?php if (empty($serverHealth)): ?>
        <div class="glass-card" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
            <i class="fa-solid fa-server" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
            <h3>No Monitored SQL Servers Registered</h3>
            <p style="margin-top: 0.5rem; margin-bottom: 1.5rem;">To begin performance profiling, add your first Microsoft SQL Server instance configuration.</p>
            <a href="../admin/servers.php" class="btn btn-primary">
                <i class="fa-solid fa-plus"></i> Register Server
            </a>
        </div>
    <?php else: ?>
        <div class="server-grid">
            <?php foreach ($serverHealth as $sh): 
                $srv = $sh['server'];
                $snap = $sh['snapshot'];
                
                $statusCardClass = 'status-card-offline';
                if ($srv['last_status'] === 'online') {
                    $statusCardClass = 'status-card-online';
                } elseif ($srv['last_status'] === 'error') {
                    $statusCardClass = 'status-card-error';
                }
            ?>
                <div class="glass-card server-card <?= $statusCardClass ?>">
                    <div class="server-card-header">
                        <div class="server-card-title" style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                            <h3 style="margin: 0;"><?= sanitize($srv['display_name']) ?></h3>
                            <span class="badge <?= $srv['environment'] === 'production' ? 'env-production' : ($srv['environment'] === 'staging' ? 'env-staging' : ($srv['environment'] === 'dev' ? 'env-dev' : 'env-demo')) ?>">
                                <?= sanitize($srv['environment']) ?>
                            </span>
                            <?php if (!empty($srv['hadr_role'])): 
                                $roleBadgeClass = strtolower($srv['hadr_role']) === 'primary' ? 'badge-primary-role' : 'badge-secondary-role';
                            ?>
                                <span class="badge <?= $roleBadgeClass ?>" style="font-size: 0.65rem;">
                                    <?= sanitize($srv['hadr_role']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <span class="server-status-dot <?= $srv['last_status'] === 'online' ? 'status-online' : ($srv['last_status'] === 'error' ? 'status-offline' : 'status-offline') ?>"></span>
                    </div>
                    
                    <?php if (!$snap): ?>
                        <div style="padding: 1rem 0; text-align: center; color: var(--text-secondary); font-size: 0.85rem;">
                            <i class="fa-solid fa-clock-rotate-left" style="margin-right: 0.5rem;"></i>
                            No snapshots collected yet.
                        </div>
                    <?php else: 
                        // Determine progress fill colors
                        $cpuVal = (float)$snap['cpu_usage_pct'];
                        $cpuFill = 'fill-success';
                        if ($cpuVal >= THRESHOLD_CPU_PCT) $cpuFill = 'fill-danger';
                        elseif ($cpuVal >= 60.0) $cpuFill = 'fill-warning';
                        
                        $pleVal = (int)$snap['page_life_exp'];
                        $pleColor = 'color: var(--color-success);';
                        if ($pleVal < THRESHOLD_PLE_SEC) $pleColor = 'color: var(--color-danger);';
                        elseif ($pleVal < 500) $pleColor = 'color: var(--color-warning);';
                        
                        $memPct = $snap['memory_total_mb'] > 0 ? ($snap['memory_used_mb'] / $snap['memory_total_mb']) * 100 : 0;
                        $memFill = 'fill-success';
                        if ($memPct >= 90.0) $memFill = 'fill-danger';
                        elseif ($memPct >= 75.0) $memFill = 'fill-warning';
                    ?>
                        <!-- CPU Usage Progress -->
                        <div style="margin-bottom: 0.75rem;">
                            <div class="flex-between" style="font-size: 0.75rem; color: var(--text-secondary);">
                                <span>CPU Utilization</span>
                                <strong style="color: #ffffff;"><?= round($cpuVal, 1) ?>%</strong>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill <?= $cpuFill ?>" style="width: <?= min($cpuVal, 100) ?>%;"></div>
                            </div>
                        </div>
                        
                        <!-- Memory Usage Progress -->
                        <div style="margin-bottom: 1.25rem;">
                            <div class="flex-between" style="font-size: 0.75rem; color: var(--text-secondary);">
                                <span>Buffer Pool RAM</span>
                                <strong style="color: #ffffff;"><?= round($snap['memory_used_mb'] / 1024, 1) ?> / <?= round($snap['memory_total_mb'] / 1024, 1) ?> GB</strong>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill <?= $memFill ?>" style="width: <?= min($memPct, 100) ?>%;"></div>
                            </div>
                        </div>
                        
                        <!-- Metrics Row Info -->
                        <div class="server-metric-row">
                            <div class="server-metric-item">
                                <div class="server-metric-item-label">Page Life Exp</div>
                                <div class="server-metric-item-val" style="<?= $pleColor ?>"><?= $pleVal ?>s</div>
                            </div>
                            
                            <div class="server-metric-item">
                                <div class="server-metric-item-label">Blocked/Conn</div>
                                <div class="server-metric-item-val" style="<?= $snap['blocked_procs'] > 0 ? 'color: var(--color-danger);' : '' ?>">
                                    <?= (int)$snap['blocked_procs'] ?> / <?= (int)$snap['active_conn'] ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Server Tile footer links -->
                    <div style="border-top: 1px solid var(--border-glass); padding-top: 0.75rem; margin-top: 0.5rem; display: flex; justify-content: flex-end;">
                        <a href="../server/detail.php?id=<?= $srv['id'] ?>" class="btn btn-primary" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;">
                            <span>Profile Server</span>
                            <i class="fa-solid fa-arrow-trend-up"></i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Diagnostics Aggregations and Recommendations -->
<div class="grid-2 animate-fade-in" style="animation-delay: 0.15s; margin-top: 1rem;">
    <!-- Global Waits Chart -->
    <div class="glass-card">
        <h3 style="margin-bottom: 1rem;">
            <i class="fa-solid fa-chart-bar" style="color: var(--color-primary); margin-right: 0.5rem;"></i>
            Global Bottlenecks Wait Time (ms)
        </h3>
        <?php if (empty($globalWaits)): ?>
            <p style="color: var(--text-secondary); font-style: italic; text-align: center; padding: 2rem;">
                No wait statistics data collected yet.
            </p>
        <?php else: ?>
            <div style="height: 220px; position: relative;">
                <canvas id="globalWaitsChart"></canvas>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Active Recommendations -->
    <div class="glass-card">
        <h3 style="margin-bottom: 1rem;">
            <i class="fa-solid fa-circle-exclamation" style="color: var(--color-danger); margin-right: 0.5rem;"></i>
            Recent Performance Alerts
        </h3>
        
        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            <?php if (empty($activeRecs)): ?>
                <div style="text-align: center; padding: 2.5rem; color: var(--text-secondary); font-style: italic;">
                    🎉 Excellent! No active performance warnings or critical issues detected.
                </div>
            <?php else: ?>
                <?php foreach ($activeRecs as $ar): 
                    $sevClass = ($ar['severity'] === 'critical') ? 'badge-danger' : (($ar['severity'] === 'warning') ? 'badge-warning' : 'badge-info');
                ?>
                    <div style="background-color: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 0.85rem; border-radius: 8px; display: flex; justify-content: space-between; align-items: flex-start; gap: 0.75rem;">
                        <div>
                            <span class="badge <?= $sevClass ?>" style="font-size: 0.65rem; margin-bottom: 0.25rem;"><?= sanitize($ar['severity']) ?></span>
                            <h4 style="font-size: 0.9rem; font-weight: 600;"><?= sanitize($ar['title']) ?></h4>
                            <p style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.15rem;"><?= sanitize($ar['description']) ?></p>
                            <span style="font-size: 0.7rem; color: var(--text-muted); margin-top: 0.35rem; display: block;">
                                <i class="fa-solid fa-server"></i> <?= sanitize($ar['server_name']) ?> &bull; <i class="fa-solid fa-calendar-days"></i> <?= sanitize($ar['generated_at']) ?>
                            </span>
                        </div>
                        <a href="../recommendations/index.php" class="btn btn-secondary" style="padding: 0.3rem 0.5rem; font-size: 0.75rem;" title="View Fix Advice">
                            <i class="fa-solid fa-wrench"></i>
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Global Top Queries -->
<div class="glass-card animate-fade-in" style="animation-delay: 0.2s; margin-top: 1.5rem; margin-bottom: 2rem;">
    <h3 style="margin-bottom: 0.5rem;">
        <i class="fa-solid fa-fire" style="color: var(--color-warning); margin-right: 0.5rem;"></i>
        Global Top 5 Expensive Database Queries
    </h3>
    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th>Server</th>
                    <th>Database</th>
                    <th>Query Query Statement</th>
                    <th style="text-align: right;">Total CPU (ms)</th>
                    <th style="text-align: right;">Execution Count</th>
                    <th style="text-align: right;">Avg CPU (ms)</th>
                    <th style="text-align: right;">Avg Reads</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($globalQueries)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-muted); font-style: italic;">
                            No query performance data collected yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($globalQueries as $gq): ?>
                        <tr>
                            <td><strong><?= sanitize($gq['server_name']) ?></strong></td>
                            <td><span class="badge badge-info"><?= sanitize($gq['database_name']) ?></span></td>
                            <td>
                                <code style="color: #61afef; font-family: monospace; font-size: 0.8rem; display: block; max-width: 380px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= sanitize($gq['query_text']) ?>">
                                    <?= sanitize($gq['query_text']) ?>
                                </code>
                            </td>
                            <td style="text-align: right; font-family: monospace;"><?= number_format($gq['total_cpu_ms'], 1) ?></td>
                            <td style="text-align: right; font-family: monospace;"><?= (int)$gq['execution_count'] ?></td>
                            <td style="text-align: right; font-family: monospace; font-weight: 600; color: var(--color-warning);"><?= number_format($gq['avg_cpu_ms'], 2) ?></td>
                            <td style="text-align: right; font-family: monospace; color: var(--color-info);"><?= number_format($gq['avg_logical_reads']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Wait Stats Chart JS Initialization -->
<?php if (!empty($globalWaits)): 
    $labels = [];
    $data = [];
    foreach ($globalWaits as $gw) {
        $labels[] = $gw['wait_type'];
        $data[] = (float)$gw['total_wait_time'];
    }
?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById('globalWaitsChart');
    if (ctx) {
        const isLight = document.documentElement.classList.contains('light-theme');
        const labelColor = isLight ? '#0f172a' : '#ffffff';
        const tickColor = isLight ? '#475569' : '#9ca3af';
        const gridColor = isLight ? 'rgba(0, 0, 0, 0.05)' : 'rgba(255, 255, 255, 0.05)';

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($labels) ?>,
                datasets: [{
                    label: 'Cumulative Wait Time (ms)',
                    data: <?= json_encode($data) ?>,
                    backgroundColor: [
                        'rgba(0, 136, 255, 0.45)',
                        'rgba(0, 188, 242, 0.45)',
                        'rgba(255, 170, 68, 0.45)',
                        'rgba(209, 52, 56, 0.45)',
                        'rgba(16, 124, 16, 0.45)'
                    ],
                    borderColor: [
                        '#0088ff',
                        '#00bcf2',
                        '#ffaa44',
                        '#d13438',
                        '#107c10'
                    ],
                    borderWidth: 1.5,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1f2937',
                        titleColor: '#ffffff',
                        bodyColor: '#e5e7eb',
                        borderColor: 'rgba(255,255,255,0.1)',
                        borderWidth: 1
                    }
                },
                scales: {
                    x: {
                        grid: { color: gridColor },
                        ticks: { color: tickColor, font: { family: 'Inter' } }
                    },
                    y: {
                        grid: { display: false },
                        ticks: { color: labelColor, font: { family: 'Inter', weight: 'bold' } }
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

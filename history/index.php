<?php
// history/index.php

$pageTitle = 'Historical Performance Trends';

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$db = getDbConnection();

// Check CSV Export Trigger
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    require_once dirname(__DIR__) . '/includes/auth_check.php';
    
    $exportServer = (int)($_GET['server_id'] ?? 0);
    $exportRange = $_GET['range'] ?? '24h';
    
    if ($exportServer <= 0) {
        die("Invalid server selection.");
    }
    
    $intervalStr = '-24 hours';
    if ($exportRange === '1h') $intervalStr = '-1 hours';
    elseif ($exportRange === '6h') $intervalStr = '-6 hours';
    elseif ($exportRange === '7d') $intervalStr = '-7 days';
    
    // Fetch server name
    $stmtSrv = $db->prepare("SELECT display_name FROM servers WHERE id = ?");
    $stmtSrv->execute([$exportServer]);
    $srvName = $stmtSrv->fetchColumn();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sqlperf_' . strtolower(str_replace(' ', '_', $srvName)) . '_' . $exportRange . '_' . date('Ymd') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Put headers
    fputcsv($output, [
        'Collected At', 'CPU Usage (%)', 'Memory Used (MB)', 'Memory Total (MB)', 
        'Page Life Expectancy (sec)', 'Batch Requests/sec', 'SQL Compilations/sec', 
        'SQL Recompilations/sec', 'Lock Waits/sec', 'Deadlocks/sec', 
        'Avg Disk Read Latency (ms)', 'Avg Disk Write Latency (ms)', 
        'Active Connections', 'Blocked Processes', 'TempDB Used (MB)'
    ]);
    
    $exportQuery = "
        SELECT * FROM metric_snapshots 
        WHERE server_id = :server_id AND collected_at >= datetime('now', :interval) 
        ORDER BY collected_at ASC
    ";
    $stmtExp = $db->prepare($exportQuery);
    $stmtExp->execute([
        ':server_id' => $exportServer,
        ':interval' => $intervalStr
    ]);
    
    while ($row = $stmtExp->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['collected_at'],
            $row['cpu_usage_pct'],
            $row['memory_used_mb'],
            $row['memory_total_mb'],
            $row['page_life_exp'],
            $row['batch_req_sec'],
            $row['sql_comp_sec'],
            $row['sql_recomp_sec'],
            $row['lock_waits_sec'],
            $row['deadlocks_sec'],
            $row['disk_read_ms'],
            $row['disk_write_ms'],
            $row['active_conn'],
            $row['blocked_procs'],
            $row['tempdb_used_mb']
        ]);
    }
    
    fclose($output);
    exit;
}

// Proceed with standard page load
require_once dirname(__DIR__) . '/templates/header.php';

// Fetch lists of servers
$servers = $db->query("SELECT id, display_name FROM servers ORDER BY display_name ASC")->fetchAll();

// Get filter selections
$serverIdA = isset($_GET['server_a']) ? (int)$_GET['server_a'] : (count($servers) > 0 ? (int)$servers[0]['id'] : 0);
$serverIdB = isset($_GET['server_b']) ? (int)$_GET['server_b'] : 0;
$range = $_GET['range'] ?? '24h';
$metric = $_GET['metric'] ?? 'cpu'; // cpu, memory, ple, batch_requests, recompilations, read_latency, connections, blocked, tempdb
$compareMode = isset($_GET['compare']) && $_GET['compare'] == '1';

// Build metric label mapping
$metricLabels = [
    'cpu' => 'CPU Usage (%)',
    'memory' => 'Buffer Pool RAM Usage (%)',
    'ple' => 'Page Life Expectancy (Seconds)',
    'batch_requests' => 'Batch Requests Counter',
    'recompilations' => 'SQL Re-compilations Counter',
    'read_latency' => 'Disk Read Latency (ms)',
    'connections' => 'Active Connections Count',
    'blocked' => 'Blocked Processes Count',
    'tempdb' => 'TempDB Space Allocation (MB)'
];
$activeMetricLabel = $metricLabels[$metric] ?? 'Value';
?>

<div class="dashboard-header-container animate-fade-in">
    <div class="dashboard-title-area">
        <h2>Historical Performance Trends</h2>
        <p>Plot timeseries metrics, overlay comparison instances, and export performance snapshots.</p>
    </div>
</div>

<!-- Dynamic Search Filters -->
<div class="glass-card animate-fade-in" style="animation-delay: 0.05s; padding: 1.5rem; margin-bottom: 2rem;">
    <form action="index.php" method="GET" id="history-form" style="display: flex; flex-direction: column; gap: 1.25rem;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; align-items: flex-end;">
            
            <div class="form-group" style="margin-bottom: 0;">
                <label for="server_a">Primary Server (A)</label>
                <select id="server_a" name="server_a" class="no-icon-input" style="padding: 0.6rem 1rem;">
                    <?php foreach ($servers as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $serverIdA === (int)$s['id'] ? 'selected' : '' ?>><?= sanitize($s['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group" id="compare-server-container" style="margin-bottom: 0; display: <?= $compareMode ? 'block' : 'none' ?>;">
                <label for="server_b">Comparison Server (B)</label>
                <select id="server_b" name="server_b" class="no-icon-input" style="padding: 0.6rem 1rem;">
                    <option value="0">-- Select Server --</option>
                    <?php foreach ($servers as $s): ?>
                        <?php if ((int)$s['id'] !== $serverIdA): ?>
                            <option value="<?= $s['id'] ?>" <?= $serverIdB === (int)$s['id'] ? 'selected' : '' ?>><?= sanitize($s['display_name']) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <label for="metric">Performance Metric</label>
                <select id="metric" name="metric" class="no-icon-input" style="padding: 0.6rem 1rem;">
                    <?php foreach ($metricLabels as $mKey => $mName): ?>
                        <option value="<?= $mKey ?>" <?= $metric === $mKey ? 'selected' : '' ?>><?= sanitize($mName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <label for="range">Time Frame Range</label>
                <select id="range" name="range" class="no-icon-input" style="padding: 0.6rem 1rem;">
                    <option value="1h" <?= $range === '1h' ? 'selected' : '' ?>>Last 1 Hour</option>
                    <option value="6h" <?= $range === '6h' ? 'selected' : '' ?>>Last 6 Hours</option>
                    <option value="24h" <?= $range === '24h' ? 'selected' : '' ?>>Last 24 Hours</option>
                    <option value="7d" <?= $range === '7d' ? 'selected' : '' ?>>Last 7 Days</option>
                </select>
            </div>
        </div>
        
        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border-glass); padding-top: 1rem;">
            <div class="flex-gap-1">
                <input type="checkbox" id="compare" name="compare" value="1" <?= $compareMode ? 'checked' : '' ?> onchange="toggleCompareMode(this.checked)" style="width: auto; margin-right: 0.25rem;">
                <label for="compare" style="font-weight: 500; font-size: 0.9rem; cursor: pointer; color: var(--text-secondary);">Enable Instance Comparison Mode</label>
            </div>
            
            <div style="display: flex; gap: 0.75rem;">
                <!-- Download CSV button -->
                <button type="button" onclick="exportCSV()" class="btn btn-secondary">
                    <i class="fa-solid fa-file-csv"></i> Export CSV
                </button>
                <button type="submit" class="btn btn-primary btn-glow">
                    <i class="fa-solid fa-chart-line"></i> Plot Trends
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Chart Window -->
<div class="glass-card animate-fade-in" style="animation-delay: 0.1s; margin-bottom: 2rem;">
    <h3 style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
        <i class="fa-solid fa-chart-line" style="color: var(--color-primary);"></i>
        <span>Time-series Analytics Chart</span>
    </h3>
    
    <div style="height: 380px; position: relative;">
        <canvas id="historicalTrendsChart"></canvas>
    </div>
</div>

<script>
function toggleCompareMode(enabled) {
    const bContainer = document.getElementById('compare-server-container');
    if (enabled) {
        bContainer.style.display = 'block';
    } else {
        bContainer.style.display = 'none';
        document.getElementById('server_b').value = '0';
    }
}

function exportCSV() {
    const sId = document.getElementById('server_a').value;
    const r = document.getElementById('range').value;
    window.location.href = `history_index.php?action=export_csv&server_id=${sId}&range=${r}`;
}

// Chart.js loading scripts
document.addEventListener("DOMContentLoaded", function() {
    const range = "<?= sanitize($range) ?>";
    const metric = "<?= sanitize($metric) ?>";
    
    const serverAId = <?= (int)$serverIdA ?>;
    const serverBId = <?= (int)$serverIdB ?>;
    const compareMode = <?= $compareMode && $serverIdB > 0 ? 'true' : 'false' ?>;
    
    // We will query the api/metrics.php endpoint for Server A, then Server B if in compare mode
    fetch(`../api/metrics.php?server_id=${serverAId}&range=${range}`)
        .then(res => res.json())
        .then(dataA => {
            if (dataA.error) {
                alert("Error: " + dataA.error);
                return;
            }
            
            const serverAName = "<?= sanitize(count($servers) > 0 ? $servers[0]['display_name'] : 'Server A') ?>"; // fallback
            
            const datasets = [{
                label: `Server A (${dataA[metric] ? 'Active' : 'Missing'})`,
                data: dataA[metric] || [],
                borderColor: '#0088ff',
                backgroundColor: 'rgba(0,136,255,0.06)',
                borderWidth: 2.5,
                tension: 0.25,
                fill: true
            }];
            
            if (compareMode) {
                // Fetch Server B details
                fetch(`../api/metrics.php?server_id=${serverBId}&range=${range}`)
                    .then(res => res.json())
                    .then(dataB => {
                        if (!dataB.error) {
                            datasets.push({
                                label: 'Server B Comparison',
                                data: dataB[metric] || [],
                                borderColor: '#ffaa44',
                                backgroundColor: 'rgba(255,170,68,0.04)',
                                borderWidth: 2.5,
                                tension: 0.25,
                                fill: true
                            });
                        }
                        renderChart(dataA.timestamps, datasets);
                    });
            } else {
                renderChart(dataA.timestamps, datasets);
            }
        });
        
    function renderChart(timestamps, datasets) {
        const ctx = document.getElementById('historicalTrendsChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: timestamps,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: { color: '#f3f4f6', font: { family: 'Inter', size: 11 } }
                    },
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
                        grid: { color: 'rgba(255,255,255,0.03)' },
                        ticks: { color: '#9ca3af', font: { family: 'Inter', size: 10 } }
                    },
                    y: {
                        grid: { color: 'rgba(255,255,255,0.03)' },
                        ticks: { color: '#9ca3af', font: { family: 'Inter' } }
                    }
                }
            }
        });
    }
});
</script>

<?php
require_once dirname(__DIR__) . '/templates/footer.php';
?>

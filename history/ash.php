<?php
// history/ash.php
$pageTitle = 'Active Session Wait Analysis';

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/templates/header.php';

$db = getDbConnection();

// Fetch servers for filter dropdown
$servers = $db->query("SELECT id, display_name FROM servers ORDER BY display_name ASC")->fetchAll();

$serverId = isset($_GET['server_id']) ? (int)$_GET['server_id'] : (count($servers) > 0 ? (int)$servers[0]['id'] : 0);
$range = $_GET['range'] ?? '24h'; // 1h, 6h, 24h, 7d

$repoType = $db->getDbType();
if ($repoType === 'mssql') {
    $intervalStr = "DATEADD(hour, -24, GETDATE())";
    if ($range === '1h') $intervalStr = "DATEADD(minute, -60, GETDATE())";
    elseif ($range === '6h') $intervalStr = "DATEADD(hour, -6, GETDATE())";
    elseif ($range === '7d') $intervalStr = "DATEADD(day, -7, GETDATE())";
    
    $timeFilter = "sample_minute >= $intervalStr";
} else {
    // SQLite
    $intervalStr = '-24 hours';
    if ($range === '1h') $intervalStr = '-1 hours';
    elseif ($range === '6h') $intervalStr = '-6 hours';
    elseif ($range === '7d') $intervalStr = '-7 days';
    
    $timeFilter = "sample_minute >= datetime('now', :interval)";
}

$chartLabels = [];
$chartDataSets = [];
$waitTypes = [];
$topQueries = [];
$errorMsg = '';

if ($serverId > 0) {
    // 1. Fetch Timeline Data for stacked chart
    $timelineSql = "
        SELECT 
            sample_minute,
            wait_type,
            SUM(samples_count) AS total_samples,
            SUM(total_wait_time_ms) AS total_wait_ms
        FROM active_session_history
        WHERE server_id = :server_id AND $timeFilter
        GROUP BY sample_minute, wait_type
        ORDER BY sample_minute ASC
    ";
    
    try {
        $stmt = $db->prepare($timelineSql);
        $stmt->bindValue(':server_id', $serverId, PDO::PARAM_INT);
        if ($repoType === 'sqlite') {
            $stmt->bindValue(':interval', $intervalStr, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rawTimeline = $stmt->fetchAll();
        
        // Pivot timeline data for Chart.js
        $minuteData = []; // $minute => [$waitType => $samplesCount]
        foreach ($rawTimeline as $rec) {
            $min = $rec['sample_minute'];
            $wType = $rec['wait_type'];
            $cnt = (int)$rec['total_samples'];
            
            $minuteData[$min][$wType] = $cnt;
            if (!in_array($wType, $waitTypes)) {
                $waitTypes[] = $wType;
            }
        }
        
        ksort($minuteData);
        $chartLabels = array_keys($minuteData);
        
        // Fill datasets
        foreach ($waitTypes as $wt) {
            $chartDataSets[$wt] = array_fill(0, count($chartLabels), 0);
        }
        
        $idx = 0;
        foreach ($minuteData as $min => $waits) {
            foreach ($waits as $wt => $cnt) {
                $chartDataSets[$wt][$idx] = $cnt;
            }
            $idx++;
        }
    } catch (Exception $e) {
        $errorMsg = "Error loading timeline: " . $e->getMessage();
    }
    
    // 2. Fetch Top Wait-Time Queries for details table
    $queriesSql = "
        SELECT 
            query_text,
            wait_type,
            SUM(samples_count) AS total_samples,
            SUM(total_wait_time_ms) AS total_wait_ms
        FROM active_session_history
        WHERE server_id = :server_id AND $timeFilter
        GROUP BY query_text, wait_type
        ORDER BY total_samples DESC, total_wait_ms DESC
        LIMIT 25
    ";
    
    try {
        $stmtQ = $db->prepare($queriesSql);
        $stmtQ->bindValue(':server_id', $serverId, PDO::PARAM_INT);
        if ($repoType === 'sqlite') {
            $stmtQ->bindValue(':interval', $intervalStr, PDO::PARAM_STR);
        }
        $stmtQ->execute();
        $topQueries = $stmtQ->fetchAll();
    } catch (Exception $e) {
        $errorMsg .= " Error loading top queries: " . $e->getMessage();
    }
}

// Preset color map for wait categories (DPA Wait Colors)
$waitColors = [
    'CPU' => ['bg' => 'rgba(16, 185, 129, 0.45)', 'border' => '#10b981'],
    'LCK_M_X' => ['bg' => 'rgba(239, 68, 68, 0.45)', 'border' => '#ef4444'],
    'LCK_M_S' => ['bg' => 'rgba(249, 115, 22, 0.45)', 'border' => '#f97316'],
    'PAGEIOLATCH_SH' => ['bg' => 'rgba(59, 130, 246, 0.45)', 'border' => '#3b82f6'],
    'PAGEIOLATCH_EX' => ['bg' => 'rgba(37, 99, 235, 0.45)', 'border' => '#2563eb'],
    'CXPACKET' => ['bg' => 'rgba(139, 92, 246, 0.45)', 'border' => '#8b5cf6'],
    'ASYNC_NETWORK_IO' => ['bg' => 'rgba(236, 72, 153, 0.45)', 'border' => '#ec4899'],
    'WRITELOG' => ['bg' => 'rgba(107, 114, 128, 0.45)', 'border' => '#6b7280']
];
$fallbackColors = [
    ['bg' => 'rgba(6, 182, 212, 0.45)', 'border' => '#06b6d4'],
    ['bg' => 'rgba(20, 184, 166, 0.45)', 'border' => '#14b8a6'],
    ['bg' => 'rgba(234, 179, 8, 0.45)', 'border' => '#eab308']
];
?>

<div class="dashboard-header-container animate-fade-in">
    <div class="dashboard-title-area">
        <h2>Active Session Wait Analysis (ASH)</h2>
        <p>Continuous sample telemetry. Review active queries, wait distributions, and locking wait states (SolarWinds DPA Wait analysis style).</p>
    </div>
</div>

<?php if (!empty($errorMsg)): ?>
    <div class="alert alert-danger animate-fade-in">
        <i class="fa-solid fa-circle-exclamation alert-icon"></i>
        <span><?= sanitize($errorMsg) ?></span>
    </div>
<?php endif; ?>

<!-- Filters -->
<div class="glass-card animate-fade-in" style="animation-delay: 0.05s; padding: 1.5rem; margin-bottom: 2rem;">
    <form action="ash.php" method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; align-items: flex-end;">
        <div class="form-group" style="margin-bottom: 0;">
            <label for="server_id">Monitored Server</label>
            <select id="server_id" name="server_id" class="no-icon-input" style="padding: 0.6rem 1rem;">
                <?php foreach ($servers as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $serverId === (int)$s['id'] ? 'selected' : '' ?>><?= sanitize($s['display_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group" style="margin-bottom: 0;">
            <label for="range">Telemetry Range</label>
            <select id="range" name="range" class="no-icon-input" style="padding: 0.6rem 1rem;">
                <option value="1h" <?= $range === '1h' ? 'selected' : '' ?>>Last 1 Hour</option>
                <option value="6h" <?= $range === '6h' ? 'selected' : '' ?>>Last 6 Hours</option>
                <option value="24h" <?= $range === '24h' ? 'selected' : '' ?>>Last 24 Hours</option>
                <option value="7d" <?= $range === '7d' ? 'selected' : '' ?>>Last 7 Days</option>
            </select>
        </div>
        
        <button type="submit" class="btn btn-primary" style="height: 42px; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;">
            <i class="fa-solid fa-arrows-rotate"></i>
            <span>Refresh Telemetry</span>
        </button>
    </form>
</div>

<!-- Stacked Active Wait Chart -->
<div class="glass-card animate-fade-in" style="animation-delay: 0.1s; padding: 1.5rem; margin-bottom: 2rem;">
    <h3 style="margin-bottom: 1.25rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem; display: flex; align-items: center; justify-content: space-between;">
        <span><i class="fa-solid fa-chart-area" style="color: var(--color-primary); margin-right: 0.5rem;"></i> Active Sessions Workload (Wait-Time Timeline)</span>
        <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: normal;">Y-Axis shows count of active sampled threads</span>
    </h3>
    <div style="height: 350px; position: relative;">
        <?php if (empty($chartLabels)): ?>
            <div style="display: flex; justify-content: center; align-items: center; height: 100%; color: var(--text-muted);">
                No active session telemetry found in this range. Ensure the Poller daemon is active and running.
            </div>
        <?php else: ?>
            <canvas id="ashChart"></canvas>
        <?php endif; ?>
    </div>
</div>

<!-- Top Wait-Time Queries table -->
<div class="glass-card animate-fade-in" style="animation-delay: 0.15s; padding: 1.5rem;">
    <h3 style="margin-bottom: 1.25rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">
        <i class="fa-solid fa-fire" style="color: var(--color-warning); margin-right: 0.5rem;"></i>
        Top Active SQL Statements (By Sampled Occurrences)
    </h3>
    
    <div class="table-container">
        <?php if (empty($topQueries)): ?>
            <div style="text-align: center; padding: 2rem; color: var(--text-muted);">
                No query sample telemetry found in the selected range.
            </div>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 55%;">SQL Statement Text</th>
                        <th style="width: 15%;">Wait Category</th>
                        <th style="width: 15%; text-align: center;">Sampled Count</th>
                        <th style="width: 15%; text-align: right;">Total Wait Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topQueries as $q): ?>
                        <tr>
                            <td>
                                <div style="font-family: monospace; font-size: 0.85rem; max-height: 80px; overflow-y: auto; white-space: pre-wrap; word-break: break-all; color: #a5f3fc; background: rgba(0,0,0,0.15); padding: 0.5rem; border-radius: 4px; border: 1px solid rgba(255,255,255,0.02);">
                                    <?= sanitize($q['query_text']) ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge" style="font-size: 0.75rem; background: rgba(255,255,255,0.05); color: #ffffff; border: 1px solid rgba(255,255,255,0.1);">
                                    <?= sanitize($q['wait_type']) ?>
                                </span>
                            </td>
                            <td style="text-align: center; font-weight: 600; color: #ffffff;">
                                <?= (int)$q['total_samples'] ?>
                            </td>
                            <td style="text-align: right; color: var(--color-warning); font-weight: 500;">
                                <?php
                                    $ms = (int)$q['total_wait_ms'];
                                    if ($ms >= 1000) {
                                        echo round($ms / 1000, 2) . ' s';
                                    } else {
                                        echo $ms . ' ms';
                                    }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($chartLabels)): ?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById('ashChart').getContext('2d');
    
    const datasets = [];
    <?php 
    $fallbackIdx = 0;
    foreach ($chartDataSets as $wt => $dataPoints):
        // Map colors
        $colors = $waitColors[$wt] ?? $fallbackColors[$fallbackIdx % count($fallbackColors)];
        if (!isset($waitColors[$wt])) {
            $fallbackIdx++;
        }
    ?>
    datasets.push({
        label: <?= json_encode($wt) ?>,
        data: <?= json_encode($dataPoints) ?>,
        backgroundColor: <?= json_encode($colors['bg']) ?>,
        borderColor: <?= json_encode($colors['border']) ?>,
        borderWidth: 1.5,
        fill: true,
        tension: 0.35
    });
    <?php endforeach; ?>
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chartLabels) ?>,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: '#e5e7eb', font: { family: 'Inter', size: 11 } }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    backgroundColor: '#1f2937',
                    titleColor: '#ffffff',
                    bodyColor: '#e5e7eb',
                    borderColor: 'rgba(255,255,255,0.1)',
                    borderWidth: 1
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { color: '#9ca3af', font: { family: 'Inter', size: 10 } }
                },
                y: {
                    stacked: true,
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { color: '#9ca3af', font: { family: 'Inter', size: 10 } }
                }
            }
        }
    });
});
</script>
<?php endif; ?>

<?php
require_once dirname(__DIR__) . '/templates/footer.php';
?>

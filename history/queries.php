<?php
// history/queries.php
$pageTitle = 'Query Execution History Log';

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/templates/header.php';

$db = getDbConnection();

// Fetch servers for filter dropdown
$servers = $db->query("SELECT id, display_name FROM servers ORDER BY display_name ASC")->fetchAll();

$serverId = isset($_GET['server_id']) ? (int)$_GET['server_id'] : (count($servers) > 0 ? (int)$servers[0]['id'] : 0);
$range = $_GET['range'] ?? '24h'; // 1h, 6h, 24h, 7d, 30d
$search = $_GET['search'] ?? '';
$orderBy = $_GET['order_by'] ?? 'collected_at'; // collected_at, total_cpu_ms, total_elapsed_ms, total_logical_reads, execution_count

// Map range to SQLite date offset
$intervalStr = '-24 hours';
if ($range === '1h') $intervalStr = '-1 hours';
elseif ($range === '6h') $intervalStr = '-6 hours';
elseif ($range === '7d') $intervalStr = '-7 days';
elseif ($range === '30d') $intervalStr = '-30 days';

$params = [];
$whereClauses = ["server_id = :server_id"];
$params[':server_id'] = $serverId;

$whereClauses[] = "collected_at >= datetime('now', :interval)";
$params[':interval'] = $intervalStr;

if (!empty($search)) {
    $whereClauses[] = "(query_text LIKE :search OR query_hash LIKE :search_hash)";
    $params[':search'] = '%' . $search . '%';
    $params[':search_hash'] = '%' . $search . '%';
}

$allowedOrderBy = [
    'collected_at' => 'collected_at DESC',
    'total_cpu_ms' => 'total_cpu_ms DESC',
    'total_elapsed_ms' => 'total_elapsed_ms DESC',
    'total_logical_reads' => 'total_logical_reads DESC',
    'execution_count' => 'execution_count DESC'
];
$orderStr = $allowedOrderBy[$orderBy] ?? 'collected_at DESC';

$whereStr = implode(" AND ", $whereClauses);
$sql = "
    SELECT * FROM top_queries
    WHERE $whereStr
    ORDER BY $orderStr
    LIMIT 200
";

$queries = [];
if ($serverId > 0) {
    try {
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $val) {
            if (is_int($val)) {
                $stmt->bindValue($key, $val, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $val, PDO::PARAM_STR);
            }
        }
        $stmt->execute();
        $queries = $stmt->fetchAll();
    } catch (Exception $e) {
        $errorMsg = "Database error: " . $e->getMessage();
    }
}
?>

<div class="dashboard-header-container animate-fade-in">
    <div class="dashboard-title-area">
        <h2>Chronological Query History Log</h2>
        <p>Analyze historically captured SQL statements, their parameter bindings, and compiled execution plans (up to 30 days retention).</p>
    </div>
</div>

<!-- Search & Filtering Panel -->
<div class="glass-card animate-fade-in" style="animation-delay: 0.05s; padding: 1.5rem; margin-bottom: 2rem;">
    <form action="queries.php" method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; align-items: flex-end;">
        
        <div class="form-group" style="margin-bottom: 0;">
            <label for="server_id">Monitored Server</label>
            <select id="server_id" name="server_id" class="no-icon-input" style="padding: 0.6rem 1rem;">
                <?php foreach ($servers as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $serverId === (int)$s['id'] ? 'selected' : '' ?>><?= sanitize($s['display_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group" style="margin-bottom: 0;">
            <label for="range">Historical Range</label>
            <select id="range" name="range" class="no-icon-input" style="padding: 0.6rem 1rem;">
                <option value="1h" <?= $range === '1h' ? 'selected' : '' ?>>Last 1 Hour</option>
                <option value="6h" <?= $range === '6h' ? 'selected' : '' ?>>Last 6 Hours</option>
                <option value="24h" <?= $range === '24h' ? 'selected' : '' ?>>Last 24 Hours</option>
                <option value="7d" <?= $range === '7d' ? 'selected' : '' ?>>Last 7 Days</option>
                <option value="30d" <?= $range === '30d' ? 'selected' : '' ?>>Last 30 Days (Retention limit)</option>
            </select>
        </div>
        
        <div class="form-group" style="margin-bottom: 0;">
            <label for="search">Search Keywords / Hash</label>
            <input type="text" id="search" name="search" class="no-icon-input" placeholder="e.g. SELECT, UPDATE, 0x8A2..." value="<?= sanitize($search) ?>" style="padding: 0.6rem 1rem;">
        </div>
        
        <div class="form-group" style="margin-bottom: 0;">
            <label for="order_by">Order Metric By</label>
            <select id="order_by" name="order_by" class="no-icon-input" style="padding: 0.6rem 1rem;">
                <option value="collected_at" <?= $orderBy === 'collected_at' ? 'selected' : '' ?>>Capture Date/Time</option>
                <option value="total_cpu_ms" <?= $orderBy === 'total_cpu_ms' ? 'selected' : '' ?>>Total CPU Consumption</option>
                <option value="total_elapsed_ms" <?= $orderBy === 'total_elapsed_ms' ? 'selected' : '' ?>>Total Elapsed Duration</option>
                <option value="total_logical_reads" <?= $orderBy === 'total_logical_reads' ? 'selected' : '' ?>>Total Logical Reads</option>
                <option value="execution_count" <?= $orderBy === 'execution_count' ? 'selected' : '' ?>>Execution Count Rate</option>
            </select>
        </div>
        
        <div style="display: flex; gap: 0.5rem;">
            <button type="submit" class="btn btn-primary btn-block btn-glow" style="height: 41px;">
                <i class="fa-solid fa-magnifying-glass"></i> Filter
            </button>
            <a href="queries.php" class="btn btn-secondary" style="height: 41px; padding: 0.65rem 1rem;" title="Reset filters">
                <i class="fa-solid fa-arrows-rotate"></i>
            </a>
        </div>
    </form>
</div>

<?php if (isset($errorMsg)): ?>
    <div class="alert alert-danger animate-fade-in">
        <i class="fa-solid fa-triangle-exclamation alert-icon"></i>
        <span><?= sanitize($errorMsg) ?></span>
    </div>
<?php endif; ?>

<!-- Query Log Results Grid -->
<div class="glass-card animate-fade-in" style="animation-delay: 0.1s;">
    <h3 style="margin-bottom: 0.25rem; display: flex; align-items: center; gap: 0.5rem;">
        <i class="fa-solid fa-list-check" style="color: var(--color-primary);"></i>
        <span>Captured Query History (Showing last <?= count($queries) ?> records)</span>
    </h3>
    <p style="color: var(--text-secondary); font-size: 0.85rem; margin-bottom: 1.25rem;">Click on any query execution row to inspect compiled variables, plan download, and historical metrics trend.</p>
    
    <div class="table-responsive" style="margin-top: 0;">
        <table class="custom-table">
            <thead>
                <tr>
                    <th>Capture Time</th>
                    <th>Database</th>
                    <th>Query Hash</th>
                    <th>Statement text</th>
                    <th style="text-align: right;">CPU (ms)</th>
                    <th style="text-align: right;">Duration (ms)</th>
                    <th style="text-align: right;">Reads</th>
                    <th style="text-align: right;">Execs</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($queries)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: var(--text-muted); font-style: italic; padding: 3rem 1rem;">No historical queries matched the selected filters.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($queries as $q): ?>
                        <tr class="query-row" style="cursor: pointer;" onclick="toggleQueryDetail(<?= $q['id'] ?>, '<?= sanitize($q['query_hash']) ?>', <?= $serverId ?>)" title="Click to view detailed metrics history & plan">
                            <td style="white-space: nowrap; font-size: 0.85rem; color: var(--text-secondary);"><?= sanitize($q['collected_at']) ?></td>
                            <td><span class="badge badge-info" style="font-size: 0.7rem;"><?= sanitize($q['database_name']) ?></span></td>
                            <td><code style="font-size: 0.8rem; color: var(--color-info);"><?= sanitize($q['query_hash']) ?></code></td>
                            <td>
                                <pre style="margin: 0; color: #a5d6ff; font-family: monospace; font-size: 0.8rem; max-width: 350px; overflow-x: auto; white-space: pre-wrap; word-break: break-all; max-height: 50px; overflow-y: auto;"><?= sanitize($q['query_text']) ?></pre>
                            </td>
                            <td style="text-align: right; font-family: monospace; font-size: 0.85rem;"><?= number_format($q['total_cpu_ms'], 1) ?></td>
                            <td style="text-align: right; font-family: monospace; font-size: 0.85rem;"><?= number_format($q['total_elapsed_ms'], 1) ?></td>
                            <td style="text-align: right; font-family: monospace; font-size: 0.85rem; color: var(--text-secondary);"><?= number_format($q['total_logical_reads']) ?></td>
                            <td style="text-align: right; font-family: monospace; font-size: 0.85rem; font-weight: 600; color: var(--color-warning);"><?= (int)$q['execution_count'] ?></td>
                        </tr>
                        
                        <!-- Collapsible Detail Drawer Row -->
                        <tr id="query-detail-row-<?= $q['id'] ?>" class="query-detail-row" style="display: none; background-color: rgba(0, 0, 0, 0.25);">
                            <td colspan="8">
                                <div style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1.5rem;">
                                    <div class="grid-2" style="grid-template-columns: 1.2fr 0.8fr; gap: 1.5rem;">
                                        <!-- Left: SQL Query code block -->
                                        <div>
                                            <h4 style="margin-bottom: 0.5rem; color: var(--color-primary); font-size: 0.95rem;">SQL Statement Text</h4>
                                            <div class="recommendation-fix-box" style="margin-top: 0; max-height: 250px; overflow-y: auto; background-color: #050b14; border: 1px solid rgba(255,255,255,0.08);">
                                                <pre id="query-text-<?= $q['id'] ?>" style="color: #c9d1d9;"><?= sanitize($q['query_text']) ?></pre>
                                                <button class="copy-btn" onclick="copyQueryText(<?= $q['id'] ?>); event.stopPropagation();">
                                                    <i class="fa-regular fa-copy"></i> Copy SQL
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <!-- Right: Plan & Parameters -->
                                        <div style="display: flex; flex-direction: column; gap: 1rem;">
                                            <h4 style="margin-bottom: 0.5rem; color: var(--color-warning); font-size: 0.95rem;">Execution Plan & Parameters</h4>
                                            <div class="glass-card" style="padding: 1.25rem; border-radius: 8px; background-color: rgba(17, 24, 39, 0.4); border: 1px solid var(--border-glass);">
                                                <div style="margin-bottom: 1rem;">
                                                    <span style="font-size: 0.8rem; color: var(--text-secondary); display: block; margin-bottom: 0.25rem;">Query Hash Identifier:</span>
                                                    <code style="font-size: 0.85rem; color: var(--color-info);"><?= sanitize($q['query_hash']) ?></code>
                                                </div>
                                                
                                                <div style="margin-bottom: 1rem;">
                                                    <span style="font-size: 0.8rem; color: var(--text-secondary); display: block; margin-bottom: 0.4rem;">Compiled Execution Parameters:</span>
                                                    <?php 
                                                    $params = !empty($q['parameters']) ? json_decode($q['parameters'], true) : null;
                                                    if ($params): 
                                                    ?>
                                                        <div style="display: grid; grid-template-columns: auto 1fr; gap: 0.5rem 0.75rem; font-size: 0.85rem; background: rgba(0,0,0,0.2); padding: 0.6rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                            <?php foreach ($params as $pName => $pVal): ?>
                                                                <span style="color: var(--color-warning); font-family: monospace; font-weight: 600;"><?= sanitize($pName) ?>:</span>
                                                                <span style="color: var(--text-primary); font-family: monospace; word-break: break-all;"><?= sanitize($pVal) ?></span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span style="font-size: 0.8rem; color: var(--text-muted); font-style: italic;">No compiled parameter bindings cached.</span>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div>
                                                    <?php if (!empty($q['query_plan'])): ?>
                                                        <a href="../server/download_plan.php?id=<?= $q['id'] ?>" class="btn btn-secondary btn-block" style="font-size: 0.85rem; padding: 0.5rem 1rem;" onclick="event.stopPropagation();">
                                                            <i class="fa-solid fa-download"></i> Download .sqlplan
                                                        </a>
                                                    <?php else: ?>
                                                        <button class="btn btn-secondary btn-block" disabled style="font-size: 0.85rem; padding: 0.5rem 1rem; opacity: 0.5;" onclick="event.stopPropagation();">
                                                            <i class="fa-solid fa-ban"></i> Plan Not Collected
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Historical Trends Chart -->
                                    <div>
                                        <h4 style="margin-bottom: 0.5rem; color: var(--color-info); font-size: 0.95rem;">Query Performance Historical Analysis</h4>
                                        <div class="glass-card" style="padding: 1.25rem; border-radius: 8px; background-color: rgba(17, 24, 39, 0.4); border: 1px solid var(--border-glass);">
                                            <div style="height: 180px; position: relative;">
                                                <canvas id="query-history-chart-<?= $q['id'] ?>"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function copyQueryText(id) {
    const text = document.getElementById('query-text-' + id).innerText;
    navigator.clipboard.writeText(text).then(function() {
        const btn = document.querySelector('#query-detail-row-' + id + ' .copy-btn');
        const origText = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
        setTimeout(() => { btn.innerHTML = origText; }, 2000);
    });
}

const queryCharts = {};

function toggleQueryDetail(id, hash, serverId) {
    const detailRow = document.getElementById('query-detail-row-' + id);
    const isVisible = detailRow.style.display !== 'none';
    
    // Close other open query rows
    document.querySelectorAll('.query-detail-row').forEach(row => {
        row.style.display = 'none';
    });
    
    if (!isVisible) {
        detailRow.style.display = 'table-row';
        
        // Load query history chart if not already initialized
        if (!queryCharts[id]) {
            fetch(`../api/query_history.php?server_id=${serverId}&hash=${encodeURIComponent(hash)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.error) {
                        console.error(data.error);
                        return;
                    }
                    
                    const ctx = document.getElementById('query-history-chart-' + id).getContext('2d');
                    queryCharts[id] = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: data.timestamps,
                            datasets: [
                                {
                                    label: 'Avg Duration (ms)',
                                    data: data.avg_duration,
                                    borderColor: '#ffaa44',
                                    backgroundColor: 'rgba(255,170,68,0.05)',
                                    borderWidth: 2,
                                    tension: 0.25,
                                    yAxisID: 'y'
                                },
                                {
                                    label: 'Avg CPU (ms)',
                                    data: data.avg_cpu,
                                    borderColor: '#0088ff',
                                    backgroundColor: 'rgba(0,136,255,0.05)',
                                    borderWidth: 2,
                                    tension: 0.25,
                                    yAxisID: 'y1'
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    labels: { color: '#9ca3af', font: { family: 'Inter', size: 10 } }
                                }
                            },
                            scales: {
                                x: {
                                    grid: { color: 'rgba(255,255,255,0.03)' },
                                    ticks: { color: '#9ca3af', font: { family: 'Inter', size: 9 } }
                                },
                                y: {
                                    type: 'linear',
                                    display: true,
                                    position: 'left',
                                    grid: { color: 'rgba(255,255,255,0.03)' },
                                    ticks: { color: '#9ca3af', font: { family: 'Inter', size: 9 } },
                                    title: { display: true, text: 'Duration (ms)', color: '#ffaa44', font: { family: 'Inter', size: 9 } }
                                },
                                y1: {
                                    type: 'linear',
                                    display: true,
                                    position: 'right',
                                    grid: { drawOnChartArea: false },
                                    ticks: { color: '#9ca3af', font: { family: 'Inter', size: 9 } },
                                    title: { display: true, text: 'CPU (ms)', color: '#0088ff', font: { family: 'Inter', size: 9 } }
                                }
                            }
                        }
                    });
                });
        }
    } else {
        detailRow.style.display = 'none';
    }
}
</script>

<?php
require_once dirname(__DIR__) . '/templates/footer.php';
?>

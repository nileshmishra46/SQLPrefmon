<?php
// history/blocking.php
$pageTitle = 'Blocking History Log';

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/templates/header.php';

$db = getDbConnection();

// Fetch servers for filter dropdown
$servers = $db->query("SELECT id, display_name FROM servers ORDER BY display_name ASC")->fetchAll();

$serverId = isset($_GET['server_id']) ? (int)$_GET['server_id'] : (count($servers) > 0 ? (int)$servers[0]['id'] : 0);
$range = $_GET['range'] ?? '24h'; // 1h, 6h, 24h, 7d, 30d
$orderBy = $_GET['order_by'] ?? 'collected_at'; // collected_at, wait_time_ms

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

$allowedOrderBy = [
    'collected_at' => 'collected_at DESC',
    'wait_time_ms' => 'wait_time_ms DESC'
];
$orderStr = $allowedOrderBy[$orderBy] ?? 'collected_at DESC';

$whereStr = implode(" AND ", $whereClauses);
$sql = "
    SELECT * FROM blocking_history
    WHERE $whereStr
    ORDER BY $orderStr
    LIMIT 200
";

$blocks = [];
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
        $blocks = $stmt->fetchAll();
    } catch (Exception $e) {
        $errorMsg = "Database error: " . $e->getMessage();
    }
}

// Function to format milliseconds into human-readable duration
function formatDuration($ms) {
    $seconds = floor($ms / 1000);
    $minutes = floor($seconds / 60);
    $seconds = $seconds % 60;
    
    if ($minutes > 0) {
        return "{$minutes}m {$seconds}s";
    }
    return "{$seconds}s";
}
?>

<div class="dashboard-header-container animate-fade-in">
    <div class="dashboard-title-area">
        <h2>Blocked Sessions History Log</h2>
        <p>Analyze persistent locking bottlenecks. Captured chronological log of SPIDs and SQL statements that blocked each other.</p>
    </div>
</div>

<!-- Search & Filtering Panel -->
<div class="glass-card animate-fade-in" style="animation-delay: 0.05s; padding: 1.5rem; margin-bottom: 2rem;">
    <form action="blocking.php" method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; align-items: flex-end;">
        
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
            <label for="order_by">Order Metric By</label>
            <select id="order_by" name="order_by" class="no-icon-input" style="padding: 0.6rem 1rem;">
                <option value="collected_at" <?= $orderBy === 'collected_at' ? 'selected' : '' ?>>Capture Date/Time</option>
                <option value="wait_time_ms" <?= $orderBy === 'wait_time_ms' ? 'selected' : '' ?>>Wait Block Time</option>
            </select>
        </div>
        
        <div style="display: flex; gap: 0.5rem;">
            <button type="submit" class="btn btn-primary btn-block btn-glow" style="height: 41px;">
                <i class="fa-solid fa-magnifying-glass"></i> Filter Logs
            </button>
            <a href="blocking.php" class="btn btn-secondary" style="height: 41px; padding: 0.65rem 1rem;" title="Reset filters">
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

<!-- Results Grid -->
<div class="glass-card animate-fade-in" style="animation-delay: 0.1s;">
    <h3 style="margin-bottom: 0.25rem; display: flex; align-items: center; gap: 0.5rem;">
        <i class="fa-solid fa-ban" style="color: var(--color-danger);"></i>
        <span>Persistent Blocking Events Log (Showing <?= count($blocks) ?> events)</span>
    </h3>
    <p style="color: var(--text-secondary); font-size: 0.85rem; margin-bottom: 1.25rem;">Click on any block event row to inspect side-by-side SQL diff comparisons of the Blocked vs. Blocker queries.</p>
    
    <div class="table-responsive" style="margin-top: 0;">
        <table class="custom-table">
            <thead>
                <tr>
                    <th>Capture Time</th>
                    <th style="text-align: center;">Blocked SPID</th>
                    <th>Blocked Statement (Snippet)</th>
                    <th style="text-align: center;">Blocker SPID</th>
                    <th>Blocker Statement (Snippet)</th>
                    <th style="text-align: right;">Wait Duration</th>
                    <th>Wait Type</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($blocks)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-muted); font-style: italic; padding: 3rem 1rem;">No persistent blocking events recorded. (Only blocks lasting longer than <?= getAppSetting('blocking_threshold_min', THRESHOLD_BLOCKING_THRESHOLD_MIN) ?> minutes are logged).</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($blocks as $b): ?>
                        <tr class="query-row" style="cursor: pointer;" onclick="toggleBlockDetail(<?= $b['id'] ?>)" title="Click to view full blocking queries">
                            <td style="white-space: nowrap; font-size: 0.85rem; color: var(--text-secondary);"><?= sanitize($b['collected_at']) ?></td>
                            <td style="text-align: center;"><span class="badge badge-danger"><?= (int)$b['blocked_session_id'] ?></span></td>
                            <td>
                                <pre style="margin: 0; color: #f87171; font-family: monospace; font-size: 0.8rem; max-width: 250px; overflow-x: auto; white-space: nowrap; text-overflow: ellipsis;"><?= sanitize(str_replace("\n", " ", $b['blocked_sql'])) ?></pre>
                            </td>
                            <td style="text-align: center;"><span class="badge badge-warning"><?= (int)$b['blocking_session_id'] ?></span></td>
                            <td>
                                <pre style="margin: 0; color: #34d399; font-family: monospace; font-size: 0.8rem; max-width: 250px; overflow-x: auto; white-space: nowrap; text-overflow: ellipsis;"><?= sanitize(str_replace("\n", " ", $b['blocking_sql'])) ?></pre>
                            </td>
                            <td style="text-align: right; font-family: monospace; font-size: 0.85rem; font-weight: 600; color: var(--color-danger);"><?= formatDuration($b['wait_time_ms']) ?></td>
                            <td style="white-space: nowrap;"><code style="font-size: 0.8rem; color: var(--text-secondary);"><?= sanitize($b['wait_type']) ?></code></td>
                        </tr>
                        
                        <!-- Collapsible Detail Drawer Row -->
                        <tr id="block-detail-row-<?= $b['id'] ?>" class="query-detail-row" style="display: none; background-color: rgba(0, 0, 0, 0.25);">
                            <td colspan="7">
                                <div style="padding: 1.5rem; display: flex; flex-direction: column; gap: 1.5rem;">
                                    <div class="grid-2" style="gap: 1.5rem;">
                                        <!-- Left: Blocked Statement -->
                                        <div>
                                            <h4 style="margin-bottom: 0.5rem; color: var(--color-danger); font-size: 0.95rem; display: flex; align-items: center; gap: 0.4rem;">
                                                <i class="fa-solid fa-lock"></i>
                                                <span>Blocked Session (SPID: <?= (int)$b['blocked_session_id'] ?>)</span>
                                            </h4>
                                            <div class="recommendation-fix-box" style="margin-top: 0; max-height: 250px; overflow-y: auto; background-color: #0d0505; border: 1px solid rgba(239, 68, 68, 0.15);">
                                                <pre id="blocked-sql-<?= $b['id'] ?>" style="color: #fca5a5;"><?= sanitize($b['blocked_sql']) ?></pre>
                                                <button class="copy-btn" onclick="copyText('blocked-sql-<?= $b['id'] ?>', this); event.stopPropagation();">
                                                    <i class="fa-regular fa-copy"></i> Copy SQL
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <!-- Right: Blocker Statement -->
                                        <div>
                                            <h4 style="margin-bottom: 0.5rem; color: var(--color-success); font-size: 0.95rem; display: flex; align-items: center; gap: 0.4rem;">
                                                <i class="fa-solid fa-key"></i>
                                                <span>Blocker Session (SPID: <?= (int)$b['blocking_session_id'] ?>)</span>
                                            </h4>
                                            <div class="recommendation-fix-box" style="margin-top: 0; max-height: 250px; overflow-y: auto; background-color: #050d0a; border: 1px solid rgba(16, 185, 129, 0.15);">
                                                <pre id="blocking-sql-<?= $b['id'] ?>" style="color: #a7f3d0;"><?= sanitize($b['blocking_sql']) ?></pre>
                                                <button class="copy-btn" onclick="copyText('blocking-sql-<?= $b['id'] ?>', this); event.stopPropagation();">
                                                    <i class="fa-regular fa-copy"></i> Copy SQL
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Lock details metadata -->
                                    <div class="glass-card" style="padding: 1.25rem; border-radius: 8px; background-color: rgba(17, 24, 39, 0.4); border: 1px solid var(--border-glass);">
                                        <h4 style="margin-bottom: 0.75rem; color: var(--text-primary); font-size: 0.9rem; font-weight: 600;">Lock Contention Metadata</h4>
                                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; font-size: 0.85rem;">
                                            <div>
                                                <span style="color: var(--text-secondary);">Wait Duration:</span>
                                                <strong style="color: var(--color-danger); margin-left: 0.25rem;"><?= formatDuration($b['wait_time_ms']) ?> (<?= number_format($b['wait_time_ms']) ?> ms)</strong>
                                            </div>
                                            <div>
                                                <span style="color: var(--text-secondary);">Wait Type:</span>
                                                <code style="color: var(--color-info); margin-left: 0.25rem;"><?= sanitize($b['wait_type']) ?></code>
                                            </div>
                                            <div style="grid-column: span 2;">
                                                <span style="color: var(--text-secondary);">Locked Resource Description:</span>
                                                <code style="color: var(--text-primary); margin-left: 0.25rem; display: inline-block; word-break: break-all;"><?= sanitize($b['resource_description'] ?: '(Not reported)') ?></code>
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
function copyText(elementId, btn) {
    const text = document.getElementById(elementId).innerText;
    navigator.clipboard.writeText(text).then(function() {
        const origText = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
        setTimeout(() => { btn.innerHTML = origText; }, 2000);
    });
}

function toggleBlockDetail(id) {
    const detailRow = document.getElementById('block-detail-row-' + id);
    const isVisible = detailRow.style.display !== 'none';
    
    // Close other open block detail rows
    document.querySelectorAll('.query-detail-row').forEach(row => {
        row.style.display = 'none';
    });
    
    if (!isVisible) {
        detailRow.style.display = 'table-row';
    } else {
        detailRow.style.display = 'none';
    }
}
</script>

<?php
require_once dirname(__DIR__) . '/templates/footer.php';
?>

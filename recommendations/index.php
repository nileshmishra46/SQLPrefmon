<?php
// recommendations/index.php

$pageTitle = 'Tuning Recommendations';
require_once dirname(__DIR__) . '/templates/header.php';
require_once dirname(__DIR__) . '/includes/role_check.php';

$db = getDbConnection();
$error = '';
$success = '';

// Handle Resolve submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Only admin and dba can resolve recommendations
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'dba'])) {
        $error = 'Access Denied: You do not have permissions to resolve alerts.';
    } else {
        $recId = (int)($_POST['recommendation_id'] ?? 0);
        $notes = trim($_POST['resolution_notes'] ?? '');
        $csrfToken = $_POST['csrf_token'] ?? '';
        
        if (!validateCsrfToken($csrfToken)) {
            $error = 'Invalid security token.';
        } elseif ($recId <= 0) {
            $error = 'Invalid recommendation selection.';
        } else {
            // Check if recommendation exists and is unresolved
            $check = $db->prepare("SELECT title FROM recommendations WHERE id = ? AND is_resolved = 0");
            $check->execute([$recId]);
            $title = $check->fetchColumn();
            
            if (!$title) {
                $error = 'Recommendation not found or already resolved.';
            } else {
                $detailStr = "Resolved: " . $title;
                if (!empty($notes)) {
                    $detailStr .= " | Notes: " . $notes;
                }
                
                $stmt = $db->prepare("
                    UPDATE recommendations 
                    SET is_resolved = 1, resolved_by = ?, resolved_at = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ");
                $stmt->execute([$_SESSION['user_id'], $recId]);
                
                logAuditEvent($_SESSION['user_id'], 'resolve_recommendation', 'recommendation', $recId, $detailStr);
                $success = "Recommendation '$title' marked as resolved.";
            }
        }
    }
}

// Fetch query filters
$filterServer = isset($_GET['server_id']) ? (int)$_GET['server_id'] : 0;
$filterSeverity = $_GET['severity'] ?? 'all';
$filterCategory = $_GET['category'] ?? 'all';

// Build dynamic query
$queryStr = "
    SELECT r.*, s.display_name as server_name 
    FROM recommendations r
    JOIN servers s ON r.server_id = s.id
    WHERE r.is_resolved = 0
";
$params = [];

if ($filterServer > 0) {
    $queryStr .= " AND r.server_id = :server_id";
    $params[':server_id'] = $filterServer;
}
if ($filterSeverity !== 'all') {
    $queryStr .= " AND r.severity = :severity";
    $params[':severity'] = $filterSeverity;
}
if ($filterCategory !== 'all') {
    $queryStr .= " AND r.category = :category";
    $params[':category'] = $filterCategory;
}

$queryStr .= " ORDER BY r.generated_at DESC";

$stmt = $db->prepare($queryStr);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->execute();
$recommendations = $stmt->fetchAll();

// Fetch servers for filter dropdown
$servers = $db->query("SELECT id, display_name FROM servers ORDER BY display_name ASC")->fetchAll();
?>

<div class="dashboard-header-container animate-fade-in">
    <div class="dashboard-title-area">
        <h2>Tuning Recommendations</h2>
        <p>Analyze automated rule detections, review DBA repair scripts, and record resolutions.</p>
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

<!-- Filters Form -->
<div class="glass-card animate-fade-in" style="animation-delay: 0.05s; padding: 1.25rem; margin-bottom: 1.5rem;">
    <form action="index.php" method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)) 120px; gap: 1rem; align-items: flex-end;">
        <div class="form-group" style="margin-bottom: 0;">
            <label for="server_id">Database Server</label>
            <select id="server_id" name="server_id" class="no-icon-input" style="padding: 0.6rem 1rem;">
                <option value="0">-- All Servers --</option>
                <?php foreach ($servers as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $filterServer === (int)$s['id'] ? 'selected' : '' ?>><?= sanitize($s['display_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group" style="margin-bottom: 0;">
            <label for="severity">Severity Level</label>
            <select id="severity" name="severity" class="no-icon-input" style="padding: 0.6rem 1rem;">
                <option value="all">-- All Severities --</option>
                <option value="critical" <?= $filterSeverity === 'critical' ? 'selected' : '' ?>>Critical</option>
                <option value="warning" <?= $filterSeverity === 'warning' ? 'selected' : '' ?>>Warning</option>
                <option value="info" <?= $filterSeverity === 'info' ? 'selected' : '' ?>>Info</option>
            </select>
        </div>
        
        <div class="form-group" style="margin-bottom: 0;">
            <label for="category">Category</label>
            <select id="category" name="category" class="no-icon-input" style="padding: 0.6rem 1rem;">
                <option value="all">-- All Categories --</option>
                <option value="cpu" <?= $filterCategory === 'cpu' ? 'selected' : '' ?>>CPU</option>
                <option value="memory" <?= $filterCategory === 'memory' ? 'selected' : '' ?>>Memory</option>
                <option value="io" <?= $filterCategory === 'io' ? 'selected' : '' ?>>I/O</option>
                <option value="waits" <?= $filterCategory === 'waits' ? 'selected' : '' ?>>Wait Stats</option>
                <option value="index" <?= $filterCategory === 'index' ? 'selected' : '' ?>>Index Health</option>
                <option value="query" <?= $filterCategory === 'query' ? 'selected' : '' ?>>Top Queries</option>
                <option value="config" <?= $filterCategory === 'config' ? 'selected' : '' ?>>Configuration</option>
            </select>
        </div>
        
        <button type="submit" class="btn btn-primary" style="padding: 0.65rem; width: 100%;">
            <i class="fa-solid fa-filter"></i> Filter
        </button>
    </form>
</div>

<!-- Recommendations List -->
<div class="animate-fade-in" style="animation-delay: 0.1s; display: flex; flex-direction: column; gap: 1rem; margin-bottom: 2rem;">
    <?php if (empty($recommendations)): ?>
        <div class="glass-card" style="text-align: center; padding: 4rem; color: var(--text-secondary);">
            <i class="fa-solid fa-circle-check" style="font-size: 3.5rem; color: var(--color-success); margin-bottom: 1.5rem;"></i>
            <h2>All Diagnostics Healthy</h2>
            <p style="margin-top: 0.5rem;">There are no active tuning recommendations matching your filter parameters.</p>
        </div>
    <?php else: ?>
        <?php foreach ($recommendations as $rec): 
            $sevBadge = 'badge-info';
            if ($rec['severity'] === 'critical') $sevBadge = 'badge-danger';
            elseif ($rec['severity'] === 'warning') $sevBadge = 'badge-warning';
            
            // Map categories to icons
            $catIcon = 'fa-lightbulb';
            if ($rec['category'] === 'cpu') $catIcon = 'fa-microchip';
            elseif ($rec['category'] === 'memory') $catIcon = 'fa-memory';
            elseif ($rec['category'] === 'io') $catIcon = 'fa-hard-drive';
            elseif ($rec['category'] === 'waits') $catIcon = 'fa-clock';
            elseif ($rec['category'] === 'index') $catIcon = 'fa-table-cells';
            elseif ($rec['category'] === 'query') $catIcon = 'fa-magnifying-glass-chart';
            elseif ($rec['category'] === 'config') $catIcon = 'fa-sliders';
        ?>
            <div class="glass-card recommendation-card" style="padding: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.75rem; margin-bottom: 1rem;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
                            <span class="badge <?= $sevBadge ?>"><?= sanitize($rec['severity']) ?></span>
                            <span class="badge badge-info" style="background-color: rgba(255,255,255,0.05); border-color: rgba(255,255,255,0.1);"><i class="fa-solid <?= $catIcon ?>"></i> <?= sanitize($rec['category']) ?></span>
                        </div>
                        <h3 style="font-size: 1.25rem; font-weight: 600;"><?= sanitize($rec['title']) ?></h3>
                        <p style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.2rem;">
                            <i class="fa-solid fa-server"></i> <?= sanitize($rec['server_name']) ?> &bull; <i class="fa-solid fa-clock"></i> Generated: <?= sanitize($rec['generated_at']) ?>
                        </p>
                    </div>
                    
                    <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'dba'])): ?>
                        <!-- Quick Resolve Toggle Button -->
                        <button onclick="document.getElementById('resolve-box-<?= $rec['id'] ?>').style.display = 'block'; this.style.display = 'none';" class="btn btn-secondary" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;">
                            <i class="fa-solid fa-circle-check"></i> Resolve Alert
                        </button>
                    <?php endif; ?>
                </div>
                
                <p style="font-size: 0.95rem; color: var(--text-primary); margin-bottom: 1rem; line-height: 1.6;"><?= sanitize($rec['description']) ?></p>
                
                <?php if ($rec['fix_script']): ?>
                    <!-- Copyable T-SQL script -->
                    <div style="margin-top: 1rem;">
                        <h4 style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary); margin-bottom: 0.5rem;">Recommended T-SQL Fix Script:</h4>
                        <div class="recommendation-fix-box">
                            <pre><code id="script-code-<?= $rec['id'] ?>"><?= sanitize($rec['fix_script']) ?></code></pre>
                            <button class="copy-btn" onclick="copyToClipboard('script-code-<?= $rec['id'] ?>', this)">
                                <i class="fa-solid fa-copy"></i> Copy SQL
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Expanded Resolve Container -->
                <div id="resolve-box-<?= $rec['id'] ?>" style="display: none; margin-top: 1.25rem; border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 1.25rem;">
                    <form action="index.php?<?= http_build_query($_GET) ?>" method="POST" style="display: grid; grid-template-columns: 1fr 180px; gap: 1rem; align-items: flex-end;">
                        <input type="hidden" name="recommendation_id" value="<?= $rec['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                        <input type="hidden" name="action" value="resolve">
                        
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="notes-<?= $rec['id'] ?>">Resolution Notes / DBA Action Taken</label>
                            <input type="text" id="notes-<?= $rec['id'] ?>" name="resolution_notes" placeholder="e.g. Created missing nonclustered index; query execution duration dropped from 6s to 12ms." class="no-icon-input" required style="padding: 0.65rem 1rem;">
                        </div>
                        
                        <div style="display: flex; gap: 0.5rem;">
                            <button type="button" onclick="document.getElementById('resolve-box-<?= $rec['id'] ?>').style.display = 'none'; class='btn btn-secondary'; button = this.closest('.recommendation-card').querySelector('button[onclick*=\'resolve-box\']'); button.style.display = '';" class="btn btn-secondary" style="padding: 0.65rem 0.75rem; width: 50%;">Cancel</button>
                            <button type="submit" class="btn btn-primary" style="padding: 0.65rem 0.75rem; width: 50%; background-color: var(--color-success);">Submit</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php
require_once dirname(__DIR__) . '/templates/footer.php';
?>

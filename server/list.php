<?php
// server/list.php

$pageTitle = 'Monitored Instances';
require_once dirname(__DIR__) . '/templates/header.php';
require_once dirname(__DIR__) . '/includes/role_check.php';

$db = getDbConnection();

// Fetch filter queries
$search = trim($_GET['search'] ?? '');
$envFilter = $_GET['environment'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';

// Build dynamic ANSI-SQL query compatible with SQLite and MSSQL
$queryStr = "
    SELECT 
        s.*,
        ms.cpu_usage_pct,
        ms.active_conn,
        ms.blocked_procs,
        (
            SELECT COUNT(*) 
            FROM (
                SELECT server_id, alert_type, MAX(collected_at) as max_time 
                FROM triggered_alerts 
                GROUP BY server_id, alert_type
            ) latest
            JOIN triggered_alerts ta ON ta.server_id = latest.server_id 
                AND ta.alert_type = latest.alert_type 
                AND ta.collected_at = latest.max_time
            WHERE ta.server_id = s.id AND ta.severity <> 'Resolved'
        ) as active_alerts_count
    FROM servers s
    LEFT JOIN (
        SELECT server_id, MAX(collected_at) as max_collected
        FROM metric_snapshots
        GROUP BY server_id
    ) latest_ms ON s.id = latest_ms.server_id
    LEFT JOIN metric_snapshots ms ON latest_ms.server_id = ms.server_id AND latest_ms.max_collected = ms.collected_at
    WHERE 1=1
";

$params = [];

if ($search !== '') {
    $queryStr .= " AND (s.display_name LIKE :search_name OR s.hostname LIKE :search_host)";
    $params[':search_name'] = '%' . $search . '%';
    $params[':search_host'] = '%' . $search . '%';
}

if ($envFilter !== 'all') {
    $queryStr .= " AND s.environment = :environment";
    $params[':environment'] = $envFilter;
}

if ($statusFilter !== 'all') {
    $queryStr .= " AND s.last_status = :last_status";
    $params[':last_status'] = $statusFilter;
}

$queryStr .= " ORDER BY s.display_name ASC";

$stmt = $db->prepare($queryStr);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->execute();
$serversList = $stmt->fetchAll();

// Get environment counts for stats
$envs = ['production', 'staging', 'dev', 'demo'];
?>

<div class="dashboard-header-container animate-fade-in">
    <div class="dashboard-title-area">
        <h2>Monitored Database Instances</h2>
        <p>Review connection health, hardware utilization, and active alerts across all registered SQL Server hosts.</p>
    </div>
    <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'dba'])): ?>
        <div>
            <a href="../admin/servers.php" class="btn btn-primary">
                <i class="fa-solid fa-gear"></i>
                <span>Manage Servers</span>
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Search & Filtering Panel -->
<div class="glass-card animate-fade-in" style="animation-delay: 0.05s; padding: 1.25rem; margin-bottom: 1.5rem;">
    <form action="list.php" method="GET" style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
        
        <div class="form-group" style="margin-bottom: 0; min-width: 200px; flex: 1;">
            <label for="search" style="font-weight: 500; font-size: 0.85rem;">Search Instance Name / Host</label>
            <input type="text" id="search" name="search" value="<?= sanitize($search) ?>" class="no-icon-input" style="padding: 0.6rem 1rem;" placeholder="e.g. PROD-SQL-01...">
        </div>
        
        <div class="form-group" style="margin-bottom: 0; min-width: 150px;">
            <label for="environment" style="font-weight: 500; font-size: 0.85rem;">Environment</label>
            <select id="environment" name="environment" class="no-icon-input" style="padding: 0.6rem 1rem;">
                <option value="all" <?= $envFilter === 'all' ? 'selected' : '' ?>>All Environments</option>
                <option value="production" <?= $envFilter === 'production' ? 'selected' : '' ?>>Production</option>
                <option value="staging" <?= $envFilter === 'staging' ? 'selected' : '' ?>>Staging</option>
                <option value="dev" <?= $envFilter === 'dev' ? 'selected' : '' ?>>Development</option>
                <option value="demo" <?= $envFilter === 'demo' ? 'selected' : '' ?>>Demo Mode</option>
            </select>
        </div>
        
        <div class="form-group" style="margin-bottom: 0; min-width: 150px;">
            <label for="status" style="font-weight: 500; font-size: 0.85rem;">Connection Status</label>
            <select id="status" name="status" class="no-icon-input" style="padding: 0.6rem 1rem;">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <option value="online" <?= $statusFilter === 'online' ? 'selected' : '' ?>>Online</option>
                <option value="offline" <?= $statusFilter === 'offline' ? 'selected' : '' ?>>Offline</option>
                <option value="error" <?= $statusFilter === 'error' ? 'selected' : '' ?>>Connection Error</option>
            </select>
        </div>

        <button type="submit" class="btn btn-primary" style="padding: 0.6rem 1.5rem; width: auto; margin-bottom: 0;">
            <i class="fa-solid fa-magnifying-glass"></i> Filter
        </button>
        <?php if ($search !== '' || $envFilter !== 'all' || $statusFilter !== 'all'): ?>
            <a href="list.php" class="btn btn-secondary" style="padding: 0.6rem 1.5rem; width: auto; margin-bottom: 0;">
                Clear
            </a>
        <?php endif; ?>
    </form>
</div>

<?php if (empty($serversList)): ?>
    <div class="glass-card animate-fade-in" style="text-align: center; padding: 4rem 2rem; color: var(--text-secondary);">
        <i class="fa-solid fa-server" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
        <h2>No Monitored Instances Found</h2>
        <p style="margin-top: 0.5rem;">Try adjusting your filters or search criteria. If you have admin privileges, you can add new servers in the management page.</p>
    </div>
<?php else: ?>
    <!-- Servers Inventory Cards Grid -->
    <div class="server-grid">
        <?php foreach ($serversList as $srv): 
            $statusClass = 'status-unknown';
            $statusText = 'Unknown';
            $cardStatusClass = '';
            
            if ($srv['last_status'] === 'online') {
                $statusClass = 'status-online';
                $statusText = 'Online';
                $cardStatusClass = 'status-card-online';
            } elseif ($srv['last_status'] === 'offline') {
                $statusClass = 'status-offline';
                $statusText = 'Offline';
                $cardStatusClass = 'status-card-offline';
            } elseif ($srv['last_status'] === 'error') {
                $statusClass = 'status-offline';
                $statusText = 'Error';
                $cardStatusClass = 'status-card-error';
            }

            $cpuVal = $srv['cpu_usage_pct'] !== null ? round($srv['cpu_usage_pct'], 1) : null;
            
            $envClass = 'env-dev';
            if ($srv['environment'] === 'production') $envClass = 'env-production';
            elseif ($srv['environment'] === 'staging') $envClass = 'env-staging';
            elseif ($srv['environment'] === 'demo') $envClass = 'env-demo';
        ?>
            <div class="glass-card server-card <?= $cardStatusClass ?> animate-fade-in" style="display: flex; flex-direction: column; justify-content: space-between; padding: 1.5rem;">
                <div>
                    <!-- Server Header -->
                    <div class="server-card-header" style="margin-bottom: 1.25rem;">
                        <div class="server-card-title">
                            <h3 style="display: inline-flex; align-items: center; gap: 0.5rem; font-size: 1.15rem; flex-wrap: wrap;">
                                <span class="server-status-dot <?= $statusClass ?>"></span>
                                <span><?= sanitize($srv['display_name']) ?></span>
                                <?php if (!empty($srv['hadr_role'])): 
                                    $roleBadgeClass = strtolower($srv['hadr_role']) === 'primary' ? 'badge-primary-role' : 'badge-secondary-role';
                                ?>
                                    <span class="badge <?= $roleBadgeClass ?>" style="font-size: 0.65rem; font-weight: 600; padding: 0.15rem 0.4rem; border-radius: 4px;">
                                        <?= sanitize($srv['hadr_role']) ?>
                                    </span>
                                <?php endif; ?>
                            </h3>
                            <div style="font-size: 0.75rem; color: var(--text-secondary); font-family: monospace; margin-top: 0.25rem;">
                                <?= sanitize($srv['hostname']) ?><?= $srv['port'] != 1433 ? ':' . $srv['port'] : '' ?>
                            </div>
                        </div>
                        <span class="badge <?= $envClass ?>" style="text-transform: uppercase; font-size: 0.65rem; font-weight: 600; padding: 0.15rem 0.4rem; border-radius: 4px;">
                            <?= sanitize($srv['environment']) ?>
                        </span>
                    </div>

                    <!-- Server Metrics Grid (Internal) -->
                    <div class="server-metric-row" style="margin-bottom: 1rem;">
                        <div class="server-metric-item">
                            <div class="server-metric-item-label">CPU Load</div>
                            <div class="server-metric-item-val" style="font-family: monospace; font-size: 1.05rem;">
                                <?php if ($cpuVal === null): ?>
                                    <span style="font-size: 0.8rem; color: var(--text-muted); font-style: italic;">No data</span>
                                <?php else: ?>
                                    <span><?= $cpuVal ?>%</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="server-metric-item">
                            <div class="server-metric-item-label">Active Sessions</div>
                            <div class="server-metric-item-val" style="font-family: monospace; font-size: 1.05rem;">
                                <span><?= $srv['active_conn'] !== null ? $srv['active_conn'] : '0' ?></span>
                            </div>
                        </div>
                        <div class="server-metric-item">
                            <div class="server-metric-item-label">Blocked Procs</div>
                            <div class="server-metric-item-val" style="font-family: monospace; font-size: 1.05rem;">
                                <?php if ($srv['blocked_procs'] > 0): ?>
                                    <span style="color: var(--color-danger); font-weight: bold;"><?= $srv['blocked_procs'] ?></span>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">0</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="server-metric-item">
                            <div class="server-metric-item-label">Active Alerts</div>
                            <div class="server-metric-item-val" style="font-size: 0.95rem;">
                                <?php if ($srv['active_alerts_count'] > 0): ?>
                                    <span style="color: var(--color-danger); font-weight: bold;"><i class="fa-solid fa-triangle-exclamation"></i> <?= $srv['active_alerts_count'] ?></span>
                                <?php else: ?>
                                    <span style="color: var(--color-success);"><i class="fa-solid fa-circle-check"></i></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Server Card Actions -->
                <div style="display: flex; gap: 0.75rem; border-top: 1px solid var(--border-glass); padding-top: 1rem; margin-top: 0.5rem; align-items: center;">
                    <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'dba'])): ?>
                        <a href="../admin/servers.php?edit=<?= $srv['id'] ?>" class="btn btn-secondary" style="padding: 0.4rem 0.8rem; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.4rem;" title="Modify Connection details">
                            <i class="fa-solid fa-pen-to-square"></i>
                            <span>Modify</span>
                        </a>
                    <?php endif; ?>
                    <a href="../server/detail.php?id=<?= $srv['id'] ?>" class="btn btn-primary" style="padding: 0.4rem 0.8rem; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.4rem; margin-left: auto;">
                        <span>Profile</span>
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
require_once dirname(__DIR__) . '/templates/footer.php';
?>

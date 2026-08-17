<?php
// history/alwayson.php
$pageTitle = 'Always On & Cluster Health';

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/templates/header.php';

$db = getDbConnection();

// Fetch servers for filter dropdown
$servers = $db->query("SELECT id, display_name, hadr_role FROM servers ORDER BY display_name ASC")->fetchAll();

$serverId = isset($_GET['server_id']) ? (int)$_GET['server_id'] : (count($servers) > 0 ? (int)$servers[0]['id'] : 0);

$replicaRows = [];
$dbRows = [];
$clusterRow = null;
$memberRows = [];
$isHadrConfigured = false;
$serverRole = null;

if ($serverId > 0) {
    try {
        // Fetch current hadr_role of the server
        $roleStmt = $db->prepare("SELECT hadr_role FROM servers WHERE id = ?");
        $roleStmt->execute([$serverId]);
        $serverRole = $roleStmt->fetchColumn();
        
        // Fetch Always On replicas
        $repStmt = $db->prepare("SELECT * FROM alwayson_replicas WHERE server_id = ? ORDER BY ag_name, replica_server_name ASC");
        $repStmt->execute([$serverId]);
        $replicaRows = $repStmt->fetchAll();
        
        // Fetch Always On databases
        $dbStmt = $db->prepare("SELECT * FROM alwayson_databases WHERE server_id = ? ORDER BY ag_name, database_name ASC");
        $dbStmt->execute([$serverId]);
        $dbRows = $dbStmt->fetchAll();
        
        // Fetch Cluster status
        $clustStmt = $db->prepare("SELECT * FROM alwayson_cluster WHERE server_id = ? LIMIT 1");
        $clustStmt->execute([$serverId]);
        $clusterRow = $clustStmt->fetch();
        
        // Fetch Cluster members
        $memStmt = $db->prepare("SELECT * FROM alwayson_cluster_members WHERE server_id = ? ORDER BY member_name ASC");
        $memStmt->execute([$serverId]);
        $memberRows = $memStmt->fetchAll();
        
        if (!empty($replicaRows) || !empty($clusterRow)) {
            $isHadrConfigured = true;
        }
        if ($clusterRow === false) {
            $clusterRow = [];
        }
    } catch (Exception $e) {
        $errorMsg = "Database error: " . $e->getMessage();
    }
}
?>

<div class="dashboard-header-container animate-fade-in">
    <div class="dashboard-title-area">
        <h2>Always On & Failover Cluster Diagnostics</h2>
        <p>Monitor real-time replication health, log send/redo queue states, and WSFC quorum votes structure.</p>
    </div>
</div>

<!-- Selector Panel -->
<div class="glass-card animate-fade-in" style="animation-delay: 0.05s; padding: 1.5rem; margin-bottom: 2rem;">
    <form action="alwayson.php" method="GET" style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
        <div class="form-group" style="margin-bottom: 0; min-width: 250px; flex: 1;">
            <label for="server_id">Monitored Instance</label>
            <select id="server_id" name="server_id" class="no-icon-input" style="padding: 0.6rem 1rem;" onchange="this.form.submit()">
                <?php foreach ($servers as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $serverId === (int)$s['id'] ? 'selected' : '' ?>>
                        <?= sanitize($s['display_name']) ?> 
                        <?= !empty($s['hadr_role']) ? ' (' . sanitize($s['hadr_role']) . ')' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <button type="submit" class="btn btn-primary" style="height: 41px;">
                <i class="fa-solid fa-arrows-rotate"></i> Refresh Status
            </button>
        </div>
    </form>
</div>

<?php if (isset($errorMsg)): ?>
    <div class="alert alert-danger animate-fade-in">
        <i class="fa-solid fa-triangle-exclamation alert-icon"></i>
        <span><?= sanitize($errorMsg) ?></span>
    </div>
<?php endif; ?>

<?php if ($serverId === 0): ?>
    <div class="glass-card animate-fade-in" style="text-align: center; padding: 4rem 2rem; color: var(--text-secondary);">
        <i class="fa-solid fa-server" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
        <h2>No Registered Servers Found</h2>
        <p style="margin-top: 0.5rem;">Configure and register active database hosts in the administrator management panel first.</p>
    </div>
<?php elseif (!$isHadrConfigured): ?>
    <div class="glass-card animate-fade-in" style="padding: 3rem 2rem; text-align: center; color: var(--text-secondary);">
        <i class="fa-solid fa-network-wired" style="font-size: 4rem; color: var(--text-muted); margin-bottom: 1.5rem;"></i>
        <h2>Always On is Not Configured</h2>
        <p style="margin-top: 0.5rem; max-width: 600px; margin-left: auto; margin-right: auto; line-height: 1.6;">
            SQL Server Always On Availability Groups are not enabled or configured on the instance <strong><?= sanitize(array_column($servers, 'display_name', 'id')[$serverId] ?? 'Selected Server') ?></strong>.
        </p>
        <div style="margin-top: 1.5rem; display: inline-flex; justify-content: center; gap: 1rem; flex-wrap: wrap; font-size: 0.85rem; color: var(--text-muted);">
            <span><i class="fa-solid fa-circle-info"></i> Make sure the SQL Server HADR feature is enabled in SQL Server Configuration Manager.</span>
            <span><i class="fa-solid fa-circle-nodes"></i> Ensure the server is joined to a WSFC Windows Cluster.</span>
        </div>
    </div>
<?php else: ?>
    <!-- Active Always On Status Grid -->
    <div style="display: grid; grid-template-columns: 1fr; gap: 2rem;">
        
        <!-- Summary Cards Row -->
        <div class="metrics-grid animate-fade-in" style="animation-delay: 0.1s;">
            <!-- Cluster Name Card -->
            <div class="glass-card stat-card" style="padding: 1.25rem;">
                <div class="stat-card-icon icon-blue">
                    <i class="fa-solid fa-cubes"></i>
                </div>
                <div class="stat-card-details">
                    <h4>Failover Cluster</h4>
                    <p style="font-size: 1.4rem; font-family: var(--font-heading);"><?= sanitize($clusterRow['cluster_name'] ?? 'WSFC Cluster') ?></p>
                </div>
            </div>
            
            <!-- Quorum State Card -->
            <?php 
                $qState = $clusterRow['quorum_state_desc'] ?? 'Unknown';
                $qClass = 'icon-warning';
                if ($qState === 'Normal Quorum') $qClass = 'icon-success';
                elseif ($qState === 'Failed Quorum') $qClass = 'icon-danger';
            ?>
            <div class="glass-card stat-card" style="padding: 1.25rem;">
                <div class="stat-card-icon <?= $qClass ?>">
                    <i class="fa-solid fa-check-to-slot"></i>
                </div>
                <div class="stat-card-details">
                    <h4>Quorum State</h4>
                    <p style="font-size: 1.4rem; font-family: var(--font-heading);"><?= sanitize($qState) ?></p>
                </div>
            </div>
            
            <!-- Quorum Type Card -->
            <div class="glass-card stat-card" style="padding: 1.25rem;">
                <div class="stat-card-icon icon-blue" style="background: rgba(234, 179, 8, 0.1); color: var(--color-warning);">
                    <i class="fa-solid fa-circle-nodes"></i>
                </div>
                <div class="stat-card-details">
                    <h4>Quorum Type</h4>
                    <p style="font-size: 1.25rem; font-family: var(--font-heading); font-weight: 600;"><?= sanitize($clusterRow['quorum_type_desc'] ?? 'Unknown') ?></p>
                </div>
            </div>
            
            <!-- Local AG Node Role Card -->
            <?php 
                $roleBadge = strtolower($serverRole) === 'primary' ? 'badge-primary-role' : 'badge-secondary-role';
            ?>
            <div class="glass-card stat-card" style="padding: 1.25rem;">
                <div class="stat-card-icon icon-blue" style="background: rgba(0, 136, 255, 0.1); color: var(--color-primary-hover);">
                    <i class="fa-solid fa-server"></i>
                </div>
                <div class="stat-card-details">
                    <h4>Local Replica Role</h4>
                    <div style="margin-top: 0.25rem;">
                        <span class="badge <?= $roleBadge ?>" style="font-size: 0.95rem; padding: 0.25rem 0.65rem; border-radius: 6px;">
                            <?= sanitize($serverRole ?? 'SECONDARY') ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Availability Group Replicas -->
        <div class="glass-card animate-fade-in" style="animation-delay: 0.15s; padding: 1.5rem;">
            <h3 style="margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; font-size: 1.2rem;">
                <i class="fa-solid fa-server" style="color: var(--color-primary);"></i>
                <span>Availability Replicas Health</span>
            </h3>
            
            <div class="table-responsive" style="margin-top: 0;">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Availability Group</th>
                            <th>Replica Server Name</th>
                            <th>Current Role</th>
                            <th style="text-align: center;">Operational Status</th>
                            <th style="text-align: center;">Connection Status</th>
                            <th style="text-align: right;">Synchronization Health</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($replicaRows as $rep): 
                            $roleClass = strtolower($rep['role_desc']) === 'primary' ? 'badge-primary-role' : 'badge-secondary-role';
                            $opClass = strtolower($rep['operational_state_desc']) === 'online' ? 'badge-success' : 'badge-danger';
                            $connClass = strtolower($rep['connected_state_desc']) === 'connected' ? 'badge-success' : 'badge-danger';
                            
                            $healthClass = 'badge-success';
                            if (strtolower($rep['synchronization_health_desc']) === 'not_healthy') $healthClass = 'badge-danger';
                            elseif (strtolower($rep['synchronization_health_desc']) === 'partially_healthy') $healthClass = 'badge-warning';
                        ?>
                            <tr>
                                <td><strong style="color: var(--text-primary);"><?= sanitize($rep['ag_name']) ?></strong></td>
                                <td><code style="font-family: monospace; font-size: 0.9rem;"><?= sanitize($rep['replica_server_name']) ?></code></td>
                                <td><span class="badge <?= $roleClass ?>"><?= sanitize($rep['role_desc']) ?></span></td>
                                <td style="text-align: center;"><span class="badge <?= $opClass ?>"><?= sanitize($rep['operational_state_desc'] ?: 'OFFLINE') ?></span></td>
                                <td style="text-align: center;"><span class="badge <?= $connClass ?>"><?= sanitize($rep['connected_state_desc'] ?: 'DISCONNECTED') ?></span></td>
                                <td style="text-align: right;"><span class="badge <?= $healthClass ?>"><?= sanitize(str_replace('_', ' ', $rep['synchronization_health_desc'] ?: 'UNKNOWN')) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Databases Synchronization Details -->
        <div class="glass-card animate-fade-in" style="animation-delay: 0.2s; padding: 1.5rem;">
            <h3 style="margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; font-size: 1.2rem;">
                <i class="fa-solid fa-database" style="color: var(--color-info);"></i>
                <span>Database Sync Health & Throughput Queues</span>
            </h3>
            
            <div class="table-responsive" style="margin-top: 0;">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Database Name</th>
                            <th>Availability Group</th>
                            <th>Sync State</th>
                            <th>Sync Health</th>
                            <?php if (strtolower($serverRole) === 'primary'): ?>
                                <th style="text-align: right; width: 180px;">Log Send Queue</th>
                                <th style="text-align: right; width: 180px;">Log Send Rate</th>
                            <?php else: ?>
                                <th style="text-align: right; width: 180px;">Redo Queue</th>
                                <th style="text-align: right; width: 180px;">Redo Rate</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dbRows)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-muted); font-style: italic; padding: 2rem;">No databases are assigned to Availability Groups on this server.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($dbRows as $dbRow): 
                                $stateClass = 'badge-success';
                                if (in_array(strtolower($dbRow['synchronization_state_desc']), ['not synchronizing', 'suspended'])) $stateClass = 'badge-danger';
                                elseif (strtolower($dbRow['synchronization_state_desc']) === 'synchronizing') $stateClass = 'badge-warning';
                                
                                $hStateClass = 'badge-success';
                                if (strtolower($dbRow['synchronization_health_desc']) === 'not_healthy') $hStateClass = 'badge-danger';
                                elseif (strtolower($dbRow['synchronization_health_desc']) === 'partially_healthy') $hStateClass = 'badge-warning';
                            ?>
                                <tr>
                                    <td><strong style="color: var(--text-primary);"><?= sanitize($dbRow['database_name']) ?></strong></td>
                                    <td><?= sanitize($dbRow['ag_name']) ?></td>
                                    <td><span class="badge <?= $stateClass ?>"><?= sanitize($dbRow['synchronization_state_desc']) ?></span></td>
                                    <td><span class="badge <?= $hStateClass ?>"><?= sanitize(str_replace('_', ' ', $dbRow['synchronization_health_desc'] ?: 'HEALTHY')) ?></span></td>
                                    
                                    <?php if (strtolower($serverRole) === 'primary'): ?>
                                        <!-- Primary Node Metrics (Log Send Queue / Rate) -->
                                        <td style="text-align: right; font-family: monospace; font-size: 0.9rem;">
                                            <?php if ($dbRow['log_send_queue_size'] !== null): ?>
                                                <?php 
                                                    $qSize = (float)$dbRow['log_send_queue_size'];
                                                    $qText = $qSize > 1024 ? round($qSize / 1024, 1) . ' MB' : round($qSize) . ' KB';
                                                    $qPct = min(100, ($qSize / 10240) * 100); // 10MB scale limit
                                                    $qBarColor = 'fill-success';
                                                    if ($qSize > 5000) $qBarColor = 'fill-danger';
                                                    elseif ($qSize > 1000) $qBarColor = 'fill-warning';
                                                ?>
                                                <div class="flex-between" style="font-size: 0.8rem; margin-bottom: 2px;">
                                                    <span>Queue:</span>
                                                    <strong><?= $qText ?></strong>
                                                </div>
                                                <div class="progress-bar-container" style="height: 4px;">
                                                    <div class="progress-bar-fill <?= $qBarColor ?>" style="width: <?= $qPct ?>%;"></div>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted); font-style: italic;">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right; font-family: monospace; font-size: 0.9rem; font-weight: 600; color: var(--color-info);">
                                            <?= $dbRow['log_send_rate'] !== null ? number_format($dbRow['log_send_rate'], 1) . ' KB/s' : '<span style="color: var(--text-muted); font-style: italic;">N/A</span>' ?>
                                        </td>
                                    <?php else: ?>
                                        <!-- Secondary Node Metrics (Redo Queue / Rate) -->
                                        <td style="text-align: right; font-family: monospace; font-size: 0.9rem;">
                                            <?php if ($dbRow['redo_queue_size'] !== null): ?>
                                                <?php 
                                                    $rSize = (float)$dbRow['redo_queue_size'];
                                                    $rText = $rSize > 1024 ? round($rSize / 1024, 1) . ' MB' : round($rSize) . ' KB';
                                                    $rPct = min(100, ($rSize / 10240) * 100); // 10MB scale limit
                                                    $rBarColor = 'fill-success';
                                                    if ($rSize > 5000) $rBarColor = 'fill-danger';
                                                    elseif ($rSize > 1000) $rBarColor = 'fill-warning';
                                                ?>
                                                <div class="flex-between" style="font-size: 0.8rem; margin-bottom: 2px;">
                                                    <span>Queue:</span>
                                                    <strong><?= $rText ?></strong>
                                                </div>
                                                <div class="progress-bar-container" style="height: 4px;">
                                                    <div class="progress-bar-fill <?= $rBarColor ?>" style="width: <?= $rPct ?>%;"></div>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted); font-style: italic;">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right; font-family: monospace; font-size: 0.9rem; font-weight: 600; color: var(--color-success);">
                                            <?= $dbRow['redo_rate'] !== null ? number_format($dbRow['redo_rate'], 1) . ' KB/s' : '<span style="color: var(--text-muted); font-style: italic;">N/A</span>' ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Failover Cluster Quorum Node Members -->
        <div class="glass-card animate-fade-in" style="animation-delay: 0.25s; padding: 1.5rem;">
            <h3 style="margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; font-size: 1.2rem;">
                <i class="fa-solid fa-circle-nodes" style="color: var(--color-warning);"></i>
                <span>Windows Failover Cluster Node Members & Quorum Health</span>
            </h3>
            
            <div class="table-responsive" style="margin-top: 0;">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Cluster Member Node</th>
                            <th>Member Type</th>
                            <th style="text-align: center;">Node/Witness State</th>
                            <th style="text-align: right;">Quorum Votes Allocated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($memberRows as $mem): 
                            $stateBadge = strtolower($mem['member_state_desc']) === 'online' ? 'badge-success' : 'badge-danger';
                        ?>
                            <tr>
                                <td><strong style="color: var(--text-primary);"><?= sanitize($mem['member_name']) ?></strong></td>
                                <td><code style="color: var(--text-secondary);"><?= sanitize($mem['member_type_desc']) ?></code></td>
                                <td style="text-align: center;"><span class="badge <?= $stateBadge ?>"><?= sanitize($mem['member_state_desc']) ?></span></td>
                                <td style="text-align: right; font-family: monospace; font-size: 0.95rem; font-weight: bold; color: var(--color-primary-hover);">
                                    <?= (int)$mem['number_of_quorum_votes'] ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
    </div>
<?php endif; ?>

<?php
require_once dirname(__DIR__) . '/templates/footer.php';
?>

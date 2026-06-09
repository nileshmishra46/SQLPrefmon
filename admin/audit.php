<?php
// admin/audit.php

$pageTitle = 'Security Audit Logs';
require_once dirname(__DIR__) . '/templates/header.php';
require_once dirname(__DIR__) . '/includes/role_check.php';

// Only admins and DBAs can view logs
requireRole(['admin', 'dba']);

$db = getDbConnection();

// Simple pagination / limit
$limit = 100;
$query = "
    SELECT a.*, u.username 
    FROM audit_log a 
    LEFT JOIN users u ON a.user_id = u.id 
    ORDER BY a.logged_at DESC 
    LIMIT :limit
";
$stmt = $db->prepare($query);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();
?>

<div class="dashboard-header-container animate-fade-in">
    <div class="dashboard-title-area">
        <h2>Security Audit Logs</h2>
        <p>Review administrative changes, authentication attempts, and system events (showing latest <?= $limit ?> logs).</p>
    </div>
    <div>
        <a href="index.php" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i>
            Back to Admin
        </a>
    </div>
</div>

<div class="glass-card animate-fade-in" style="animation-delay: 0.1s; margin-bottom: 2rem;">
    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Target Type</th>
                    <th>Target ID</th>
                    <th>Details</th>
                    <th>Client IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-muted); font-style: italic;">
                            No audit log entries found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): 
                        // Style action badges based on action type
                        $actionClass = 'badge-info';
                        if (str_contains($l['action'], 'failed') || str_contains($l['action'], 'delete') || str_contains($l['action'], 'ban') || str_contains($l['action'], 'timeout')) {
                            $actionClass = 'badge-danger';
                        } elseif (str_contains($l['action'], 'success') || str_contains($l['action'], 'create') || str_contains($l['action'], 'resolve')) {
                            $actionClass = 'badge-success';
                        } elseif (str_contains($l['action'], 'update') || str_contains($l['action'], 'edit')) {
                            $actionClass = 'badge-warning';
                        }
                    ?>
                        <tr>
                            <td style="font-size: 0.8rem; font-family: monospace; color: var(--text-secondary); white-space: nowrap;">
                                <?= sanitize($l['logged_at']) ?>
                            </td>
                            <td>
                                <strong><?= sanitize($l['username'] ?? 'SYSTEM') ?></strong>
                            </td>
                            <td>
                                <span class="badge <?= $actionClass ?>"><?= sanitize($l['action']) ?></span>
                            </td>
                            <td style="font-size: 0.8rem; text-transform: uppercase; color: var(--text-secondary);"><?= sanitize($l['target_type'] ?: '-') ?></td>
                            <td style="font-family: monospace; font-size: 0.8rem; color: var(--text-muted);"><?= sanitize($l['target_id'] ?: '-') ?></td>
                            <td><?= sanitize($l['detail'] ?: '-') ?></td>
                            <td style="font-size: 0.8rem; font-family: monospace; color: var(--text-muted);"><?= sanitize($l['ip_address']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/templates/footer.php';
?>

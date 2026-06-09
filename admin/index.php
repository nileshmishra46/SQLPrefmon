<?php
// admin/index.php

$pageTitle = 'Administration Panel';
require_once dirname(__DIR__) . '/templates/header.php';
require_once dirname(__DIR__) . '/includes/role_check.php';

// Require either admin or dba roles
requireRole(['admin', 'dba']);

$db = getDbConnection();

// Fetch general stats for the admin overview
$userCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$serverCount = $db->query("SELECT COUNT(*) FROM servers")->fetchColumn();
$logCount = $db->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
$recCount = $db->query("SELECT COUNT(*) FROM recommendations WHERE is_resolved = 0")->fetchColumn();
?>

<div class="dashboard-header-container animate-fade-in">
    <div class="dashboard-title-area">
        <h2>System Administration</h2>
        <p>Manage monitoring targets, user permissions, global alert thresholds, and review logs.</p>
    </div>
</div>

<div class="metrics-grid-3 animate-fade-in" style="animation-delay: 0.1s;">
    <div class="glass-card stat-card">
        <div class="stat-card-icon icon-blue">
            <i class="fa-solid fa-server"></i>
        </div>
        <div class="stat-card-details">
            <h4>Monitored Servers</h4>
            <p><?= (int)$serverCount ?></p>
        </div>
    </div>
    <div class="glass-card stat-card">
        <div class="stat-card-icon icon-success">
            <i class="fa-solid fa-users"></i>
        </div>
        <div class="stat-card-details">
            <h4>Total Users</h4>
            <p><?= (int)$userCount ?></p>
        </div>
    </div>
    <div class="glass-card stat-card">
        <div class="stat-card-icon icon-warning">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <div class="stat-card-details">
            <h4>Active Alerts</h4>
            <p><?= (int)$recCount ?></p>
        </div>
    </div>
</div>

<div class="grid-2 animate-fade-in" style="animation-delay: 0.2s; margin-top: 1.5rem;">
    <!-- System Controls Card -->
    <div class="glass-card">
        <h3 style="margin-bottom: 1.25rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">
            <i class="fa-solid fa-gears" style="color: var(--color-primary); margin-right: 0.5rem;"></i>
            Configuration Tools
        </h3>
        
        <div style="display: flex; flex-direction: column; gap: 1rem;">
            <a href="servers.php" class="btn btn-secondary" style="justify-content: flex-start; padding: 1rem;">
                <i class="fa-solid fa-server" style="font-size: 1.2rem; width: 25px; text-align: center;"></i>
                <div style="text-align: left;">
                    <div style="font-weight: 600; color: #ffffff;">Server Inventory CRUD</div>
                    <div style="font-size: 0.75rem; color: var(--text-secondary);">Add, remove, or modify monitored SQL Server connections and test ODBC paths.</div>
                </div>
            </a>
            
            <a href="users.php" class="btn btn-secondary" style="justify-content: flex-start; padding: 1rem;">
                <i class="fa-solid fa-user-gear" style="font-size: 1.2rem; width: 25px; text-align: center;"></i>
                <div style="text-align: left;">
                    <div style="font-weight: 600; color: #ffffff;">User Permissions & Roles</div>
                    <div style="font-size: 0.75rem; color: var(--text-secondary);">Manage system users, activate/deactivate accounts, and edit roles (admin, dba, viewer).</div>
                </div>
            </a>
            
            <a href="audit.php" class="btn btn-secondary" style="justify-content: flex-start; padding: 1rem;">
                <i class="fa-solid fa-receipt" style="font-size: 1.2rem; width: 25px; text-align: center;"></i>
                <div style="text-align: left;">
                    <div style="font-weight: 600; color: #ffffff;">System Audit Trail</div>
                    <div style="font-size: 0.75rem; color: var(--text-secondary);">Inspect administrative audits, logins, server alterations, and severity logs.</div>
                </div>
            </a>
        </div>
    </div>
    
    <!-- Scheduler & System Details -->
    <div class="glass-card">
        <h3 style="margin-bottom: 1.25rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">
            <i class="fa-solid fa-clock" style="color: var(--color-warning); margin-right: 0.5rem;"></i>
            Collector Scheduling
        </h3>
        
        <div style="color: var(--text-secondary); font-size: 0.9rem; line-height: 1.6;">
            <p style="margin-bottom: 1rem;">
                Metrics are gathered in the background by calling the PHP CLI collector process. Ensure you have configured a task scheduler or cron daemon on your hosting environment.
            </p>
            
            <div style="background-color: rgba(0,0,0,0.2); border: 1px solid var(--border-glass); padding: 0.75rem; border-radius: 8px; margin-bottom: 1.25rem; font-family: monospace; font-size: 0.8rem; color: #38bdf8;">
                # Recommended Windows Task Scheduler Command:<br>
                php -d extension=pdo_sqlite -d extension=openssl -d extension=pdo_odbc "<?= sanitize(dirname(__DIR__)) ?>\engine\collect.php"<br><br>
                # Recommended Linux Cron Entry (every 5 minutes):<br>
                */5 * * * * php -d extension=pdo_sqlite -d extension=openssl -d extension=pdo_odbc "<?= sanitize(dirname(__DIR__)) ?>/engine/collect.php" >> "<?= sanitize(APP_LOG_PATH) ?>" 2>&1
            </div>
            
            <div style="display: flex; justify-content: space-between; font-size: 0.85rem; border-top: 1px solid var(--border-glass); padding-top: 1rem;">
                <span>Total Audit Events Logged:</span>
                <strong style="color: #ffffff;"><?= (int)$logCount ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 0.85rem; margin-top: 0.5rem;">
                <span>SQLite DB Size:</span>
                <strong style="color: #ffffff;">
                    <?php 
                        $size = file_exists(APP_DB_PATH) ? filesize(APP_DB_PATH) : 0;
                        echo round($size / 1024, 2) . ' KB';
                    ?>
                </strong>
            </div>
        </div>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/templates/footer.php';
?>

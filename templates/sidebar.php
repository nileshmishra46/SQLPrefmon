<?php
// templates/sidebar.php

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$db = getDbConnection();
$sidebarServers = [];
try {
    $stmt = $db->query("SELECT id, display_name, last_status, environment FROM servers ORDER BY display_name ASC");
    $sidebarServers = $stmt->fetchAll();
} catch (Exception $e) {
    // Database might not be initialized yet
}

$currentScript = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

function isLinkActive($page, $dir = null) {
    global $currentScript, $currentDir;
    if ($dir !== null) {
        return ($currentDir === $dir && $currentScript === $page) ? 'active' : '';
    }
    return ($currentScript === $page) ? 'active' : '';
}
?>
<div class="sidebar">
    <div class="sidebar-logo">
        <i class="fa-solid fa-server"></i>
        <h1>SQLPrefmon</h1>
    </div>
    
    <div class="sidebar-nav">
        <div class="nav-section">
            <div class="nav-section-title">Monitoring</div>
            
            <a href="../dashboard/index.php" class="nav-link <?= isLinkActive('index.php', 'dashboard') ?>">
                <i class="fa-solid fa-chart-pie"></i>
                <span>Dashboard</span>
            </a>
            
            <a href="../recommendations/index.php" class="nav-link <?= isLinkActive('index.php', 'recommendations') ?>">
                <i class="fa-solid fa-lightbulb"></i>
                <span>Recommendations</span>
            </a>
            
            <a href="../history/index.php" class="nav-link <?= isLinkActive('index.php', 'history') ?>">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <span>Historical Trends</span>
            </a>

            <a href="../history/queries.php" class="nav-link <?= isLinkActive('queries.php', 'history') ?>">
                <i class="fa-solid fa-terminal"></i>
                <span>Query History</span>
            </a>

            <a href="../history/blocking.php" class="nav-link <?= isLinkActive('blocking.php', 'history') ?>">
                <i class="fa-solid fa-ban"></i>
                <span>Blocking Log</span>
            </a>
        </div>
        
        <div class="nav-section">
            <div class="nav-section-title">Active Servers</div>
            <div class="sidebar-server-list">
                <?php if (empty($sidebarServers)): ?>
                    <div class="server-item" style="font-style: italic; color: var(--text-muted);">
                        No servers monitored
                    </div>
                <?php else: ?>
                    <?php foreach ($sidebarServers as $srv): 
                        $statusClass = 'status-unknown';
                        if ($srv['last_status'] === 'online') {
                            $statusClass = 'status-online';
                        } elseif ($srv['last_status'] === 'offline') {
                            $statusClass = 'status-offline';
                        } elseif ($srv['last_status'] === 'error') {
                            $statusClass = 'status-offline'; // Red indicator
                        }
                        
                        $isCurrentServer = ($currentDir === 'server' && isset($_GET['id']) && (int)$_GET['id'] === (int)$srv['id']);
                        $activeClass = $isCurrentServer ? 'style="color: var(--color-primary); font-weight: 600;"' : '';
                    ?>
                        <a href="../server/detail.php?id=<?= $srv['id'] ?>" class="server-item" <?= $activeClass ?>>
                            <span class="flex-gap-1">
                                <span class="server-status-dot <?= $statusClass ?>"></span>
                                <span><?= sanitize($srv['display_name']) ?></span>
                            </span>
                            <span class="badge badge-info" style="font-size: 0.65rem; padding: 0.1rem 0.3rem;"><?= sanitize($srv['environment']) ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'dba')): ?>
            <div class="nav-section">
                <div class="nav-section-title">System Administration</div>
                <a href="../admin/index.php" class="nav-link <?= isLinkActive('index.php', 'admin') || isLinkActive('users.php', 'admin') || isLinkActive('servers.php', 'admin') || isLinkActive('audit.php', 'admin') ?>">
                    <i class="fa-solid fa-sliders"></i>
                    <span>Admin Panel</span>
                </a>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="sidebar-user">
        <div>
            <div class="user-info-name"><?= sanitize($_SESSION['username'] ?? 'User') ?></div>
            <div class="user-info-role"><?= strtoupper(sanitize($_SESSION['role'] ?? 'viewer')) ?></div>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <a href="../auth/change_password.php" class="key-icon" title="Change Password">
                <i class="fa-solid fa-key"></i>
            </a>
            <a href="../auth/logout.php" class="logout-icon" title="Log Out">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
            </a>
        </div>
    </div>
</div>

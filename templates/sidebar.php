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
        <h1><?= sanitize(getAppSetting('app_name', 'SQLPrefmon')) ?></h1>
    </div>
    
    <div class="sidebar-nav">
        <div class="nav-section">
            <div class="nav-section-title">Monitoring</div>
            
            <a href="../dashboard/index.php" class="nav-link <?= isLinkActive('index.php', 'dashboard') ?>">
                <i class="fa-solid fa-chart-pie"></i>
                <span>Dashboard</span>
            </a>
            
            <a href="../server/list.php" class="nav-link <?= isLinkActive('list.php', 'server') ?>">
                <i class="fa-solid fa-server"></i>
                <span>Active Servers</span>
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

            <a href="../history/deadlocks.php" class="nav-link <?= isLinkActive('deadlocks.php', 'history') ?>">
                <i class="fa-solid fa-skull"></i>
                <span>Deadlocks Log</span>
            </a>

            <a href="../history/db_files.php" class="nav-link <?= isLinkActive('db_files.php', 'history') ?>">
                <i class="fa-solid fa-hard-drive"></i>
                <span>DB File Analysis</span>
            </a>

            <a href="../history/backups.php" class="nav-link <?= isLinkActive('backups.php', 'history') ?>">
                <i class="fa-solid fa-life-ring"></i>
                <span>Backup Monitoring</span>
            </a>

            <a href="../history/jobs.php" class="nav-link <?= isLinkActive('jobs.php', 'history') ?>">
                <i class="fa-solid fa-list-check"></i>
                <span>Agent Job Status</span>
            </a>

            <a href="../history/alwayson.php" class="nav-link <?= isLinkActive('alwayson.php', 'history') ?>">
                <i class="fa-solid fa-network-wired"></i>
                <span>Always On & Cluster</span>
            </a>

            <a href="../alerts/index.php" class="nav-link <?= isLinkActive('index.php', 'alerts') ?>">
                <i class="fa-solid fa-bell"></i>
                <span>Alert Center</span>
            </a>
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
            <a href="#" id="theme-customizer-btn" class="palette-icon" title="Theme Customizer" style="color: var(--text-muted); font-size: 1.1rem; transition: var(--transition-smooth); margin-right: 0.25rem;">
                <i class="fa-solid fa-palette"></i>
            </a>
            <a href="../auth/change_password.php" class="key-icon" title="Change Password">
                <i class="fa-solid fa-key"></i>
            </a>
            <a href="../auth/logout.php" class="logout-icon" title="Log Out">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
            </a>
        </div>
    </div>
</div>

<!-- Theme Customizer Drawer -->
<div id="theme-customizer-drawer" class="customizer-drawer">
    <div class="drawer-header">
        <h3>Theme Customizer</h3>
        <button id="close-customizer-btn">&times;</button>
    </div>
    <div class="drawer-content">
        <div class="customizer-section">
            <h4>Select Theme Mode</h4>
            <div class="theme-mode-options">
                <button class="theme-mode-btn" data-theme="dark">
                    <i class="fa-solid fa-moon"></i> Dark
                </button>
                <button class="theme-mode-btn" data-theme="light">
                    <i class="fa-solid fa-sun"></i> Light
                </button>
            </div>
        </div>

        <div class="customizer-section">
            <h4>Vibrant Accents</h4>
            <div class="accent-options-grid">
                <button class="accent-btn" data-primary="#0088ff" data-glow="rgba(0, 136, 255, 0.35)" data-hover="#33a3ff" style="background: #0088ff;" title="Ocean Blue"></button>
                <button class="accent-btn" data-primary="#10b981" data-glow="rgba(16, 185, 129, 0.35)" data-hover="#34d399" style="background: #10b981;" title="Emerald Green"></button>
                <button class="accent-btn" data-primary="#f97316" data-glow="rgba(249, 115, 22, 0.35)" data-hover="#fb923c" style="background: #f97316;" title="Sunset Orange"></button>
                <button class="accent-btn" data-primary="#8b5cf6" data-glow="rgba(139, 92, 246, 0.35)" data-hover="#a78bfa" style="background: #8b5cf6;" title="Royal Purple"></button>
                <button class="accent-btn" data-primary="#ef4444" data-glow="rgba(239, 68, 68, 0.35)" data-hover="#f87171" style="background: #ef4444;" title="Crimson Red"></button>
            </div>
        </div>

        <div class="customizer-section">
            <h4>Pastel Accents</h4>
            <div class="accent-options-grid">
                <button class="accent-btn" data-primary="#a78bfa" data-glow="rgba(167, 139, 250, 0.35)" data-hover="#c084fc" style="background: #a78bfa;" title="Pastel Violet"></button>
                <button class="accent-btn" data-primary="#86efac" data-glow="rgba(134, 239, 172, 0.35)" data-hover="#a7f3d0" style="background: #86efac;" title="Pastel Mint"></button>
                <button class="accent-btn" data-primary="#93c5fd" data-glow="rgba(147, 197, 253, 0.35)" data-hover="#bfdbfe" style="background: #93c5fd;" title="Pastel Sky"></button>
                <button class="accent-btn" data-primary="#fca5a5" data-glow="rgba(252, 165, 165, 0.35)" data-hover="#fecaca" style="background: #fca5a5;" title="Pastel Pink"></button>
                <button class="accent-btn" data-primary="#fef08a" data-glow="rgba(254, 240, 138, 0.35)" data-hover="#fef9c3" style="background: #fef08a;" title="Pastel Lemon"></button>
            </div>
        </div>
    </div>
</div>
<!-- Customizer backdrop -->
<div id="theme-customizer-backdrop" class="customizer-backdrop"></div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const customizerBtn = document.getElementById("theme-customizer-btn");
    const closeBtn = document.getElementById("close-customizer-btn");
    const drawer = document.getElementById("theme-customizer-drawer");
    const backdrop = document.getElementById("theme-customizer-backdrop");

    if (customizerBtn && drawer && backdrop) {
        customizerBtn.addEventListener("click", function(e) {
            e.preventDefault();
            drawer.classList.add("open");
            backdrop.classList.add("open");
        });

        const closeCustomizer = function() {
            drawer.classList.remove("open");
            backdrop.classList.remove("open");
        };

        closeBtn.addEventListener("click", closeCustomizer);
        backdrop.addEventListener("click", closeCustomizer);
    }

    // Theme Mode Toggle
    const themeBtns = document.querySelectorAll(".theme-mode-btn");
    const activeTheme = localStorage.getItem("prefmon-theme") || "dark";
    
    themeBtns.forEach(btn => {
        if (btn.getAttribute("data-theme") === activeTheme) {
            btn.classList.add("active");
        }
        btn.addEventListener("click", function() {
            themeBtns.forEach(b => b.classList.remove("active"));
            this.classList.add("active");
            
            const selectedTheme = this.getAttribute("data-theme");
            localStorage.setItem("prefmon-theme", selectedTheme);
            
            if (selectedTheme === "light") {
                document.documentElement.classList.add("light-theme");
            } else {
                document.documentElement.classList.remove("light-theme");
            }

            // Reload the page to apply theme changes globally and rebuild the charts correctly
            setTimeout(() => {
                location.reload();
            }, 100);
        });
    });

    // Accent Color Selector
    const accentBtns = document.querySelectorAll(".accent-btn");
    let currentAccent = null;
    try {
        currentAccent = JSON.parse(localStorage.getItem("prefmon-accent"));
    } catch(e) {}

    accentBtns.forEach(btn => {
        const primary = btn.getAttribute("data-primary");
        if (currentAccent && currentAccent.primary === primary) {
            btn.classList.add("active");
        } else if (!currentAccent && primary === "#0088ff") {
            btn.classList.add("active");
        }

        btn.addEventListener("click", function() {
            accentBtns.forEach(b => b.classList.remove("active"));
            this.classList.add("active");

            const primary = this.getAttribute("data-primary");
            const glow = this.getAttribute("data-glow");
            const hover = this.getAttribute("data-hover");

            const accent = { primary, glow, hover };
            localStorage.setItem("prefmon-accent", JSON.stringify(accent));

            document.documentElement.style.setProperty('--color-primary', primary);
            document.documentElement.style.setProperty('--color-primary-glow', glow);
            document.documentElement.style.setProperty('--color-primary-hover', hover);
        });
    });
});
</script>

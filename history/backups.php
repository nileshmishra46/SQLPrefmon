<?php
// history/backups.php
$pageTitle = 'Database Backup Monitoring';

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/templates/header.php';

$db = getDbConnection();

// Fetch servers for filter dropdown
$servers = $db->query("SELECT id, display_name FROM servers ORDER BY display_name ASC")->fetchAll();

$serverId = isset($_GET['server_id']) ? (int)$_GET['server_id'] : (count($servers) > 0 ? (int)$servers[0]['id'] : 0);

$backups = [];
$historyData = [];
$errorMsg = null;

$fullThresh = (int)getAppSetting('backup_full_threshold', 24);
$diffThresh = (int)getAppSetting('backup_diff_threshold', 24);
$logThresh = (int)getAppSetting('backup_log_threshold', 4);

if ($serverId > 0) {
    try {
        // 1. Fetch latest backup stats per database
        $stmt = $db->prepare("
            SELECT * FROM db_backup_stats 
            WHERE server_id = ? 
              AND collected_at = (SELECT MAX(collected_at) FROM db_backup_stats WHERE server_id = ?)
            ORDER BY database_name ASC
        ");
        $stmt->execute([$serverId, $serverId]);
        $backups = $stmt->fetchAll();

        // 2. Fetch historical backup sizes for Chart.js trend
        $histStmt = $db->prepare("
            SELECT collected_at, 
                   SUM(CASE WHEN full_backup_size_mb IS NULL THEN 0 ELSE full_backup_size_mb END) as total_full_mb,
                   SUM(CASE WHEN diff_backup_size_mb IS NULL THEN 0 ELSE diff_backup_size_mb END) as total_diff_mb,
                   SUM(CASE WHEN log_backup_size_mb IS NULL THEN 0 ELSE log_backup_size_mb END) as total_log_mb
            FROM db_backup_stats
            WHERE server_id = ?
            GROUP BY collected_at
            ORDER BY collected_at ASC
            LIMIT 50
        ");
        $histStmt->execute([$serverId]);
        $historyData = $histStmt->fetchAll();
    } catch (Exception $e) {
        $errorMsg = "Database error: " . $e->getMessage();
    }
}

// Compute counts
$totalDbs = count($backups);
$healthyCount = 0;
$overdueCount = 0;
$missingCount = 0;

$now = time();
foreach ($backups as &$b) {
    $status = 'healthy';
    
    // Check Full Backup status
    if (empty($b['last_full_backup'])) {
        $status = 'missing';
    } else {
        $fullAge = ($now - strtotime($b['last_full_backup'])) / 3600;
        if ($fullAge > $fullThresh) {
            $status = 'overdue';
        }
    }
    
    // Check Differential Backup status (if exists)
    if ($status !== 'missing' && !empty($b['last_diff_backup'])) {
        $diffAge = ($now - strtotime($b['last_diff_backup'])) / 3600;
        if ($diffAge > $diffThresh) {
            $status = 'overdue';
        }
    }
    
    // Check Log Backup status (only for FULL/BULK recovery models)
    if ($status !== 'missing' && in_array(strtoupper($b['recovery_model'] ?? ''), ['FULL', 'BULK_LOGGED'])) {
        if (empty($b['last_log_backup'])) {
            $status = 'overdue';
        } else {
            $logAge = ($now - strtotime($b['last_log_backup'])) / 3600;
            if ($logAge > $logThresh) {
                $status = 'overdue';
            }
        }
    }
    
    $b['status'] = $status;
    if ($status === 'healthy') $healthyCount++;
    elseif ($status === 'overdue') $overdueCount++;
    elseif ($status === 'missing') $missingCount++;
}
unset($b);

// Formatter helper for sizes
function formatSize($mb) {
    if ($mb === null) return 'N/A';
    if ($mb >= 1024.0) {
        return round($mb / 1024.0, 2) . ' GB';
    }
    return round($mb, 2) . ' MB';
}
?>

<div class="dashboard-header-container animate-fade-in">
    <div class="dashboard-title-area">
        <h2>Database Backup Monitoring</h2>
        <p>Ensure backup compliance and verify data durability parameters across all database recovery layers.</p>
    </div>
</div>

<!-- Search & Filtering Panel -->
<div class="glass-card animate-fade-in" style="animation-delay: 0.05s; padding: 1.5rem; margin-bottom: 1.5rem;">
    <form action="backups.php" method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; align-items: flex-end;">
        
        <div class="form-group" style="margin-bottom: 0;">
            <label for="server_id">Monitored Server</label>
            <select id="server_id" name="server_id" class="no-icon-input" style="padding: 0.6rem 1rem;">
                <?php foreach ($servers as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $serverId === (int)$s['id'] ? 'selected' : '' ?>><?= sanitize($s['display_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group" style="margin-bottom: 0;">
            <button type="submit" class="btn btn-primary" style="padding: 0.65rem; width: 100%;">
                <i class="fa-solid fa-filter"></i> Select Server
            </button>
        </div>
    </form>
</div>

<?php if ($errorMsg): ?>
    <div class="alert alert-danger animate-fade-in"><?= sanitize($errorMsg) ?></div>
<?php endif; ?>

<!-- Metrics Grid -->
<div class="metrics-grid animate-fade-in" style="animation-delay: 0.1s; margin-bottom: 1.5rem;">
    <div class="glass-card stat-card">
        <div class="stat-card-icon icon-blue">
            <i class="fa-solid fa-database"></i>
        </div>
        <div class="stat-card-details">
            <h4>Total Databases</h4>
            <p><?= $totalDbs ?></p>
        </div>
    </div>
    <div class="glass-card stat-card">
        <div class="stat-card-icon icon-success">
            <i class="fa-solid fa-circle-check"></i>
        </div>
        <div class="stat-card-details">
            <h4>Healthy Backups</h4>
            <p><?= $healthyCount ?></p>
        </div>
    </div>
    <div class="glass-card stat-card">
        <div class="stat-card-icon icon-warning">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <div class="stat-card-details">
            <h4>Overdue Backups</h4>
            <p><?= $overdueCount ?></p>
        </div>
    </div>
    <div class="glass-card stat-card">
        <div class="stat-card-icon icon-danger">
            <i class="fa-solid fa-circle-xmark"></i>
        </div>
        <div class="stat-card-details">
            <h4>No Backups</h4>
            <p><?= $missingCount ?></p>
        </div>
    </div>
</div>

<div class="grid-3 animate-fade-in" style="grid-template-columns: 2.2fr 0.8fr; gap: 1.5rem; animation-delay: 0.15s; margin-bottom: 1.5rem;">
    <!-- Backup status list table -->
    <div class="glass-card" style="padding: 1.5rem;">
        <h3 style="margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">
            <i class="fa-solid fa-list-check" style="color: var(--color-primary); margin-right: 0.5rem;"></i>
            Database Backup Inventory Age
        </h3>
        
        <?php if (empty($backups)): ?>
            <div style="padding: 3rem; text-align: center; color: var(--text-muted);">
                No databases or backup status logs found. Ensure collector scheduling runs successfully.
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Database</th>
                            <th>Recovery Model</th>
                            <th>Last Full Backup</th>
                            <th>Last Diff Backup</th>
                            <th>Last Log Backup</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $b): ?>
                            <tr>
                                <td><strong><?= sanitize($b['database_name']) ?></strong></td>
                                <td>
                                    <span class="db-badge badge-info" style="font-size: 0.75rem;"><?= sanitize($b['recovery_model']) ?></span>
                                </td>
                                <td>
                                    <?php if (empty($b['last_full_backup'])): ?>
                                        <span style="color: var(--color-danger); font-weight: 600;">
                                            <i class="fa-solid fa-circle-xmark"></i> None
                                        </span>
                                    <?php else: 
                                        $fullAgeHours = ($now - strtotime($b['last_full_backup'])) / 3600;
                                        $fullColor = ($fullAgeHours > $fullThresh) ? 'var(--color-warning)' : '#ffffff';
                                    ?>
                                        <span style="color: <?= $fullColor ?>;">
                                            <?= date('Y-m-d H:i', strtotime($b['last_full_backup'])) ?>
                                        </span>
                                        <small style="color: var(--text-muted); display: block; font-size: 0.7rem;">
                                            Age: <?= round($fullAgeHours, 1) ?>h | Size: <?= formatSize($b['full_backup_size_mb']) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (empty($b['last_diff_backup'])): ?>
                                        <span style="color: var(--text-muted); font-style: italic; font-size: 0.85rem;">
                                            None
                                        </span>
                                    <?php else: 
                                        $diffAgeHours = ($now - strtotime($b['last_diff_backup'])) / 3600;
                                        $diffColor = ($diffAgeHours > $diffThresh) ? 'var(--color-warning)' : '#ffffff';
                                    ?>
                                        <span style="color: <?= $diffColor ?>;">
                                            <?= date('Y-m-d H:i', strtotime($b['last_diff_backup'])) ?>
                                        </span>
                                        <small style="color: var(--text-muted); display: block; font-size: 0.7rem;">
                                            Age: <?= round($diffAgeHours, 1) ?>h | Size: <?= formatSize($b['diff_backup_size_mb']) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!in_array(strtoupper($b['recovery_model'] ?? ''), ['FULL', 'BULK_LOGGED'])): ?>
                                        <span style="color: var(--text-muted); font-style: italic; font-size: 0.8rem;">
                                            N/A (Simple)
                                        </span>
                                    <?php elseif (empty($b['last_log_backup'])): ?>
                                        <span style="color: var(--color-danger); font-weight: 600;">
                                            <i class="fa-solid fa-triangle-exclamation"></i> None
                                        </span>
                                    <?php else: 
                                        $logAgeHours = ($now - strtotime($b['last_log_backup'])) / 3600;
                                        $logColor = ($logAgeHours > $logThresh) ? 'var(--color-warning)' : '#ffffff';
                                    ?>
                                        <span style="color: <?= $logColor ?>;">
                                            <?= date('Y-m-d H:i', strtotime($b['last_log_backup'])) ?>
                                        </span>
                                        <small style="color: var(--text-muted); display: block; font-size: 0.7rem;">
                                            Age: <?= round($logAgeHours, 1) ?>h | Size: <?= formatSize($b['log_backup_size_mb']) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($b['status'] === 'healthy'): ?>
                                        <span class="db-badge badge-success">Healthy</span>
                                    <?php elseif ($b['status'] === 'overdue'): ?>
                                        <span class="db-badge badge-warning">Overdue</span>
                                    <?php else: ?>
                                        <span class="db-badge badge-danger">Missing</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Threshold info box & settings link -->
    <div class="glass-card" style="padding: 1.5rem; display: flex; flex-direction: column; justify-content: space-between;">
        <div>
            <h3 style="margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">
                <i class="fa-solid fa-shield-halved" style="color: var(--color-success); margin-right: 0.5rem;"></i>
                Alert Thresholds
            </h3>
            <p style="font-size: 0.85rem; color: var(--text-secondary); line-height: 1.6; margin-bottom: 1.25rem;">
                SQLPrefmon monitors backup ages and triggers diagnostic alerts if backups exceed configured limits:
            </p>
            
            <div style="display: flex; flex-direction: column; gap: 0.75rem; margin-bottom: 1.5rem;">
                <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 0.75rem; border-radius: 6px;">
                    <div style="font-size: 0.8rem; font-weight: 600; color: #ffffff;">Full Backup Limit:</div>
                    <div style="font-size: 1.2rem; font-weight: 700; color: var(--color-warning); margin-top: 0.2rem;">
                        <?= $fullThresh ?> Hours
                    </div>
                </div>
                <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 0.75rem; border-radius: 6px;">
                    <div style="font-size: 0.8rem; font-weight: 600; color: #ffffff;">Diff Backup Limit:</div>
                    <div style="font-size: 1.2rem; font-weight: 700; color: var(--color-warning); margin-top: 0.2rem;">
                        <?= $diffThresh ?> Hours
                    </div>
                </div>
                <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 0.75rem; border-radius: 6px;">
                    <div style="font-size: 0.8rem; font-weight: 600; color: #ffffff;">Log Backup Limit:</div>
                    <div style="font-size: 1.2rem; font-weight: 700; color: var(--color-warning); margin-top: 0.2rem;">
                        <?= $logThresh ?> Hours
                    </div>
                </div>
            </div>
        </div>
        
        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <a href="../admin/settings.php" class="btn btn-secondary btn-block">
                <i class="fa-solid fa-sliders"></i> Adjust Thresholds
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Storage Size Growth Chart -->
<div class="glass-card animate-fade-in" style="padding: 1.5rem; animation-delay: 0.2s;">
    <h3 style="margin-bottom: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">
        <i class="fa-solid fa-chart-line" style="color: var(--color-primary); margin-right: 0.5rem;"></i>
        Historical Backup Storage Allocation (MB)
    </h3>
    <div style="height: 300px; position: relative;">
        <canvas id="backupSizeChart"></canvas>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        <?php
        $labels = [];
        $fullSizes = [];
        $diffSizes = [];
        $logSizes = [];
        foreach ($historyData as $hd) {
            $labels[] = date('m-d H:i', strtotime($hd['collected_at']));
            $fullSizes[] = round($hd['total_full_mb'], 2);
            $diffSizes[] = round($hd['total_diff_mb'], 2);
            $logSizes[] = round($hd['total_log_mb'], 2);
        }
        ?>

        const ctx = document.getElementById('backupSizeChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($labels) ?>,
                datasets: [
                    {
                        label: 'Total Full Backups Size (MB)',
                        data: <?= json_encode($fullSizes) ?>,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        fill: true,
                        tension: 0.2,
                        borderWidth: 2
                    },
                    {
                        label: 'Total Diff Backups Size (MB)',
                        data: <?= json_encode($diffSizes) ?>,
                        borderColor: '#fbbf24',
                        backgroundColor: 'rgba(251, 191, 36, 0.1)',
                        fill: true,
                        tension: 0.2,
                        borderWidth: 2
                    },
                    {
                        label: 'Total Log Backups Size (MB)',
                        data: <?= json_encode($logSizes) ?>,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        fill: true,
                        tension: 0.2,
                        borderWidth: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: {
                            color: '#cbd5e1'
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.05)'
                        },
                        ticks: {
                            color: '#94a3b8',
                            maxRotation: 45,
                            minRotation: 45
                        }
                    },
                    y: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.05)'
                        },
                        ticks: {
                            color: '#94a3b8'
                        }
                    }
                }
            }
        });
    });
</script>

<?php
require_once dirname(__DIR__) . '/templates/footer.php';
?>

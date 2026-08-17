<?php
// history/db_files.php
$pageTitle = 'Database File Space Analysis';

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/templates/header.php';

$db = getDbConnection();

// Fetch monitored servers for filter dropdown
$servers = $db->query("SELECT id, display_name FROM servers ORDER BY display_name ASC")->fetchAll();

$serverId = isset($_GET['server_id']) ? (int)$_GET['server_id'] : (count($servers) > 0 ? (int)$servers[0]['id'] : 0);

// Get current space alert threshold from settings
$spaceThresh = (float)getAppSetting('db_file_space_threshold_pct', 10.0);

// 1. Fetch latest file stats snapshot timestamp
$maxTimeStmt = $db->prepare("SELECT MAX(collected_at) FROM db_file_stats WHERE server_id = ?");
$maxTimeStmt->execute([$serverId]);
$latestTime = $maxTimeStmt->fetchColumn();

$files = [];
if ($latestTime) {
    // 2. Fetch all file details at that latest timestamp
    $filesStmt = $db->prepare("
        SELECT * FROM db_file_stats 
        WHERE server_id = ? AND collected_at = ? 
        ORDER BY database_name ASC, file_type ASC, file_name ASC
    ");
    $filesStmt->execute([$serverId, $latestTime]);
    $files = $filesStmt->fetchAll();
}

// 3. Compute Summary Statistics
$totalMdfMb = 0.0;
$totalLdfMb = 0.0;
$totalUsedMb = 0.0;
$totalFreeMb = 0.0;
$uniqueDbs = [];
$alertingFilesCount = 0;

foreach ($files as $f) {
    $uniqueDbs[$f['database_name']] = true;
    if ($f['file_type'] === 'ROWS') {
        $totalMdfMb += $f['total_size_mb'];
    } else {
        $totalLdfMb += $f['total_size_mb'];
    }
    $totalUsedMb += $f['used_space_mb'];
    $totalFreeMb += $f['free_space_mb'];
    
    if ($f['free_space_pct'] !== null && $f['free_space_pct'] < $spaceThresh) {
        $alertingFilesCount++;
    }
}

$totalSizeMb = $totalMdfMb + $totalLdfMb;
$dbCount = count($uniqueDbs);
$overallFreePct = $totalSizeMb > 0 ? ($totalFreeMb / $totalSizeMb) * 100.0 : 0.0;
$overallUsedPct = 100.0 - $overallFreePct;

// Helper to format values
function formatBytesToGb($mb) {
    return round($mb / 1024.0, 2);
}

// 4. Fetch last 20 snapshots of total size for trend chart
$trendHistory = [];
if ($serverId > 0) {
    $trendStmt = $db->prepare("
        SELECT 
            collected_at,
            SUM(total_size_mb) as total_mb,
            SUM(CASE WHEN file_type = 'ROWS' THEN total_size_mb ELSE 0 END) as mdf_mb,
            SUM(CASE WHEN file_type = 'LOG' THEN total_size_mb ELSE 0 END) as ldf_mb
        FROM db_file_stats
        WHERE server_id = ?
        GROUP BY collected_at
        ORDER BY collected_at DESC
        LIMIT 20
    ");
    $trendStmt->execute([$serverId]);
    $trendHistory = array_reverse($trendStmt->fetchAll());
}
?>

<!-- Header -->
<div class="dashboard-header-container animate-fade-in">
    <div class="dashboard-title-area">
        <h2>Database File Space Analysis</h2>
        <p>Proactive growth monitoring of individual data (.mdf/.ndf) and log (.ldf) files across all SQL databases.</p>
    </div>
    <div class="flex-gap-1">
        <!-- Manual Collection Trigger -->
        <form action="../engine/collect.php" method="GET" target="collect_frame" onsubmit="document.getElementById('collect_btn').innerHTML = '<i class=\'fa-solid fa-spinner fa-spin\'></i> Refreshing...';">
            <button type="submit" id="collect_btn" class="btn btn-secondary">
                <i class="fa-solid fa-arrows-rotate"></i>
                <span>Run Diagnostics</span>
            </button>
        </form>
        <iframe name="collect_frame" style="display:none;" onload="if(window.collectRun) { window.location.reload(); } window.collectRun=true;"></iframe>
    </div>
</div>

<!-- Instance Filter Panel -->
<div class="glass-card animate-fade-in" style="animation-delay: 0.05s; padding: 1.25rem; margin-bottom: 1.5rem;">
    <form action="db_files.php" method="GET" style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
        <div class="form-group" style="margin-bottom: 0; min-width: 250px; max-width: 350px;">
            <label for="server_id" style="font-weight: 500; font-size: 0.85rem;">Monitored SQL Server Instance</label>
            <select id="server_id" name="server_id" class="no-icon-input" onchange="this.form.submit()" style="padding: 0.6rem 1rem;">
                <?php if (empty($servers)): ?>
                    <option value="0">No servers configured</option>
                <?php else: ?>
                    <?php foreach ($servers as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $serverId === (int)$s['id'] ? 'selected' : '' ?>><?= sanitize($s['display_name']) ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
        <noscript>
            <button type="submit" class="btn btn-primary">Filter</button>
        </noscript>
        <?php if ($latestTime): ?>
            <div style="font-size: 0.8rem; color: var(--text-secondary); padding-bottom: 0.5rem;">
                <i class="fa-solid fa-clock"></i> Last metrics snapshot: <strong><?= sanitize($latestTime) ?></strong>
            </div>
        <?php endif; ?>
    </form>
</div>

<?php if (empty($files)): ?>
    <div class="glass-card animate-fade-in" style="text-align: center; padding: 5rem 2rem; color: var(--text-secondary);">
        <i class="fa-solid fa-hourglass-empty" style="font-size: 3.5rem; color: var(--text-muted); margin-bottom: 1.5rem;"></i>
        <h2>No File Space Metrics Found</h2>
        <p style="margin-top: 0.5rem; margin-bottom: 1.5rem;">The collection engine has not recorded file dimensions for this instance yet. Run diagnostics to trigger collection.</p>
    </div>
<?php else: ?>

    <!-- Overview Metric Cards -->
    <div class="metrics-grid-4 animate-fade-in" style="animation-delay: 0.1s; margin-bottom: 2rem;">
        <div class="glass-card stat-card" style="border-left: 4px solid var(--color-primary);">
            <div class="stat-card-icon icon-blue">
                <i class="fa-solid fa-database"></i>
            </div>
            <div class="stat-card-details">
                <h4>Total Data Files (MDF)</h4>
                <p><?= formatBytesToGb($totalMdfMb) ?> GB <span style="font-size: 0.75rem; font-weight: normal; color: var(--text-secondary);">allocated</span></p>
            </div>
        </div>

        <div class="glass-card stat-card" style="border-left: 4px solid #a155e8;">
            <div class="stat-card-icon" style="color: #a155e8; background-color: rgba(161, 85, 232, 0.1);">
                <i class="fa-solid fa-file-invoice"></i>
            </div>
            <div class="stat-card-details">
                <h4>Total Log Files (LDF)</h4>
                <p><?= formatBytesToGb($totalLdfMb) ?> GB <span style="font-size: 0.75rem; font-weight: normal; color: var(--text-secondary);">allocated</span></p>
            </div>
        </div>

        <div class="glass-card stat-card" style="border-left: 4px solid var(--color-success);">
            <div class="stat-card-icon icon-success">
                <i class="fa-solid fa-circle-notch"></i>
            </div>
            <div class="stat-card-details">
                <h4>Overall Free Capacity</h4>
                <p><?= round($overallFreePct, 1) ?>% <span style="font-size: 0.75rem; font-weight: normal; color: var(--text-secondary);"><?= formatBytesToGb($totalFreeMb) ?> GB free</span></p>
            </div>
        </div>

        <div class="glass-card stat-card" style="border-left: 4px solid <?= $alertingFilesCount > 0 ? 'var(--color-danger)' : 'var(--color-success)' ?>;">
            <div class="stat-card-icon <?= $alertingFilesCount > 0 ? 'icon-danger pulse-badge' : 'icon-success' ?>">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div class="stat-card-details">
                <h4>Space Alerts</h4>
                <p><?= $alertingFilesCount ?> <span style="font-size: 0.75rem; font-weight: normal; color: var(--text-secondary);">files below <?= $spaceThresh ?>%</span></p>
            </div>
        </div>
    </div>

    <!-- Chart Panel -->
    <div class="glass-card animate-fade-in" style="animation-delay: 0.15s; margin-bottom: 2rem; padding: 1.5rem;">
        <h3 style="margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
            <i class="fa-solid fa-chart-line" style="color: var(--color-primary);"></i>
            <span>Allocation Trend & Space Growth History</span>
        </h3>
        <div style="height: 250px; position: relative;">
            <canvas id="sizeTrendChart"></canvas>
        </div>
    </div>

    <!-- Detailed Files Inventory -->
    <div class="glass-card animate-fade-in" style="animation-delay: 0.2s; padding: 1.5rem; margin-bottom: 2rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
            <div>
                <h3 style="margin-bottom: 0.25rem;">Detailed Files Inventory (<?= count($files) ?> files across <?= $dbCount ?> databases)</h3>
                <p style="color: var(--text-secondary); font-size: 0.85rem;">Inspect detailed allocations, file types, physical disks mapping, and free percentages.</p>
            </div>
            
            <!-- Quick Search Filter -->
            <div style="position: relative; min-width: 280px;">
                <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.85rem;">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </span>
                <input type="text" id="db-search" placeholder="Search by database or file name..." class="no-icon-input" style="padding-left: 2.25rem; width: 100%; border-radius: 20px;">
            </div>
        </div>

        <div class="table-responsive" style="margin-top: 0;">
            <table class="custom-table" id="db-files-table">
                <thead>
                    <tr>
                        <th>Database Name</th>
                        <th>Logical File Name</th>
                        <th>Type</th>
                        <th>Total Size (GB)</th>
                        <th>Used Space (GB)</th>
                        <th>Free Space (GB)</th>
                        <th style="width: 180px;">Utilization Space</th>
                        <th>Free %</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($files as $f): 
                        $pctFree = $f['free_space_pct'] !== null ? round($f['free_space_pct'], 1) : 0.0;
                        $pctUsed = 100.0 - $pctFree;
                        
                        $statusText = 'Healthy';
                        $statusClass = 'badge-success';
                        
                        if ($pctFree < 5.0) {
                            $statusText = 'Critical';
                            $statusClass = 'badge-danger';
                        } elseif ($pctFree < $spaceThresh) {
                            $statusText = 'Warning';
                            $statusClass = 'badge-warning';
                        }
                        
                        $typeText = $f['file_type'] === 'ROWS' ? 'MDF/Data' : 'LDF/Log';
                        $typeClass = $f['file_type'] === 'ROWS' ? 'badge-info' : 'badge-secondary';
                    ?>
                        <tr class="file-row" data-db="<?= sanitize(strtolower($f['database_name'])) ?>" data-file="<?= sanitize(strtolower($f['file_name'])) ?>">
                            <td>
                                <span style="font-weight: 600; color: var(--text-primary);"><?= sanitize($f['database_name']) ?></span>
                            </td>
                            <td>
                                <div style="display: flex; flex-direction: column;">
                                    <span><?= sanitize($f['file_name']) ?></span>
                                    <small style="color: var(--text-muted); font-size: 0.7rem; font-family: monospace;" title="<?= sanitize($f['physical_name']) ?>">
                                        <?= sanitize(basename($f['physical_name'])) ?>
                                    </small>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?= $typeClass ?>" style="font-size: 0.7rem;"><?= $typeText ?></span>
                            </td>
                            <td><strong><?= formatBytesToGb($f['total_size_mb']) ?> GB</strong></td>
                            <td><?= formatBytesToGb($f['used_space_mb']) ?> GB</td>
                            <td><?= formatBytesToGb($f['free_space_mb']) ?> GB</td>
                            <td>
                                <!-- Util Progress Bar -->
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <div class="progress-bar-container" style="background-color: var(--bg-card); border: 1px solid var(--border-glass); border-radius: 4px; height: 10px; flex-grow: 1; overflow: hidden; position: relative;">
                                        <div style="background-color: <?= $pctFree < $spaceThresh ? 'var(--color-danger)' : 'var(--color-primary)' ?>; width: <?= $pctUsed ?>%; height: 100%;"></div>
                                    </div>
                                    <span style="font-size: 0.75rem; width: 30px; text-align: right; color: var(--text-secondary);"><?= round($pctUsed) ?>%</span>
                                </div>
                            </td>
                            <td>
                                <strong style="color: <?= $pctFree < $spaceThresh ? 'var(--color-danger)' : 'var(--color-success)' ?>;"><?= $pctFree ?>%</strong>
                            </td>
                            <td>
                                <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Chart Configuration Script -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // 1. Text Search Filter Action
            const searchInput = document.getElementById("db-search");
            const rows = document.querySelectorAll(".file-row");
            
            searchInput.addEventListener("keyup", function() {
                const query = this.value.toLowerCase().trim();
                
                rows.forEach(row => {
                    const dbName = row.getAttribute("data-db");
                    const fileName = row.getAttribute("data-file");
                    
                    if (dbName.includes(query) || fileName.includes(query)) {
                        row.style.display = "";
                    } else {
                        row.style.display = "none";
                    }
                });
            });

            // 2. Render Size Trend History Chart
            const ctx = document.getElementById('sizeTrendChart').getContext('2d');
            
            <?php
            $labels = [];
            $totalData = [];
            $mdfData = [];
            $ldfData = [];
            foreach ($trendHistory as $th) {
                // Formatting date nicely
                $labels[] = date('M d H:i', strtotime($th['collected_at']));
                $totalData[] = formatBytesToGb($th['total_mb']);
                $mdfData[] = formatBytesToGb($th['mdf_mb']);
                $ldfData[] = formatBytesToGb($th['ldf_mb']);
            }
            ?>
            
            const labels = <?= json_encode($labels) ?>;
            const totalData = <?= json_encode($totalData) ?>;
            const mdfData = <?= json_encode($mdfData) ?>;
            const ldfData = <?= json_encode($ldfData) ?>;

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Total Capacity (GB)',
                            data: totalData,
                            borderColor: '#36b9cc',
                            backgroundColor: 'rgba(54, 185, 204, 0.05)',
                            fill: true,
                            tension: 0.3,
                            borderWidth: 2.5
                        },
                        {
                            label: 'Data Files Size (MDF) (GB)',
                            data: mdfData,
                            borderColor: '#4e73df',
                            backgroundColor: 'transparent',
                            tension: 0.3,
                            borderWidth: 2
                        },
                        {
                            label: 'Log Files Size (LDF) (GB)',
                            data: ldfData,
                            borderColor: '#a155e8',
                            backgroundColor: 'transparent',
                            tension: 0.3,
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
                                color: '#a0aec0',
                                font: {
                                    family: 'Inter',
                                    size: 11
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                color: 'rgba(255, 255, 255, 0.05)'
                            },
                            ticks: {
                                color: '#a0aec0',
                                font: {
                                    size: 10
                                }
                            }
                        },
                        y: {
                            grid: {
                                color: 'rgba(255, 255, 255, 0.05)'
                            },
                            ticks: {
                                color: '#a0aec0',
                                font: {
                                    size: 10
                                },
                                callback: function(value) {
                                    return value + ' GB';
                                }
                            }
                        }
                    }
                }
            });
        });
    </script>

<?php endif; ?>

<?php
require_once dirname(__DIR__) . '/templates/footer.php';
?>

<?php
// history/deadlocks.php
$pageTitle = 'Deadlock Analyzer Log';

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/templates/header.php';

$db = getDbConnection();

// Fetch servers for filter dropdown
$servers = $db->query("SELECT id, display_name FROM servers ORDER BY display_name ASC")->fetchAll();

$serverId = isset($_GET['server_id']) ? (int)$_GET['server_id'] : (count($servers) > 0 ? (int)$servers[0]['id'] : 0);
$range = $_GET['range'] ?? '24h'; // 1h, 6h, 24h, 7d, 30d

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

$whereStr = implode(" AND ", $whereClauses);
$sql = "
    SELECT * FROM deadlock_history
    WHERE $whereStr
    ORDER BY deadlock_time DESC
    LIMIT 100
";

$deadlocks = [];
$errorMsg = null;

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
        $deadlocks = $stmt->fetchAll();
    } catch (Exception $e) {
        $errorMsg = "Database error: " . $e->getMessage();
    }
}

// Select active deadlock to analyze
$selectedId = isset($_GET['selected_id']) ? (int)$_GET['selected_id'] : 0;
$activeDl = null;

if ($selectedId > 0) {
    foreach ($deadlocks as $dl) {
        if ((int)$dl['id'] === $selectedId) {
            $activeDl = $dl;
            break;
        }
    }
}

if (!$activeDl && !empty($deadlocks)) {
    $activeDl = $deadlocks[0];
    $selectedId = (int)$activeDl['id'];
}

// Format duration
function formatMs($ms) {
    if ($ms >= 1000) {
        return round($ms / 1000, 2) . 's';
    }
    return $ms . 'ms';
}
?>

<style>
    .deadlock-container {
        display: grid;
        grid-template-columns: 350px 1fr;
        gap: 1.5rem;
        margin-top: 1.5rem;
        min-height: 600px;
    }
    
    @media (max-width: 1024px) {
        .deadlock-container {
            grid-template-columns: 1fr;
        }
    }
    
    .deadlock-list-pane {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        max-height: 750px;
        overflow-y: auto;
        padding-right: 0.5rem;
    }
    
    .deadlock-item-card {
        padding: 1rem;
        cursor: pointer;
        border-left: 4px solid transparent;
        transition: all 0.2s ease;
    }
    
    .deadlock-item-card:hover {
        background: rgba(255, 255, 255, 0.05);
        transform: translateY(-2px);
    }
    
    .deadlock-item-card.active {
        background: rgba(var(--color-primary-rgb), 0.1);
        border-left-color: var(--color-primary);
    }
    
    .dl-meta-badge {
        display: inline-block;
        padding: 0.2rem 0.5rem;
        font-size: 0.7rem;
        font-weight: 600;
        border-radius: 4px;
        background: rgba(255, 255, 255, 0.1);
        color: var(--text-secondary);
        margin-top: 0.5rem;
    }
    
    .process-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
        margin-top: 1rem;
    }
    
    @media (max-width: 768px) {
        .process-grid {
            grid-template-columns: 1fr;
        }
    }
    
    .process-card {
        border-left: 4px solid transparent;
    }
    
    .process-card.victim {
        border-left-color: var(--color-danger);
        background: rgba(239, 68, 68, 0.02);
    }
    
    .process-card.winner {
        border-left-color: var(--color-success);
        background: rgba(16, 185, 129, 0.02);
    }
    
    .sql-code-box {
        background: rgba(0, 0, 0, 0.3);
        border: 1px solid var(--border-glass);
        padding: 0.75rem;
        border-radius: 6px;
        font-family: monospace;
        font-size: 0.8rem;
        color: #e2e8f0;
        overflow-x: auto;
        max-height: 120px;
        margin-top: 0.5rem;
        white-space: pre-wrap;
    }
    
    .relation-arrow {
        display: flex;
        justify-content: center;
        align-items: center;
        color: var(--text-muted);
        font-size: 1.5rem;
    }
</style>

<div class="dashboard-header-container animate-fade-in">
    <div class="dashboard-title-area">
        <h2>Extended Events Deadlock Analyzer</h2>
        <p>Analyze deadlocked database transactions. Captured from SQL Server's <code>system_health</code> Extended Events ring buffer.</p>
    </div>
</div>

<!-- Search & Filtering Panel -->
<div class="glass-card animate-fade-in" style="animation-delay: 0.05s; padding: 1.5rem; margin-bottom: 1.5rem;">
    <form action="deadlocks.php" method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; align-items: flex-end;">
        
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
                <option value="1h" <?= $range === '1h' ? 'selected' : '' ?>>Last Hour</option>
                <option value="6h" <?= $range === '6h' ? 'selected' : '' ?>>Last 6 Hours</option>
                <option value="24h" <?= $range === '24h' ? 'selected' : '' ?>>Last 24 Hours</option>
                <option value="7d" <?= $range === '7d' ? 'selected' : '' ?>>Last 7 Days</option>
                <option value="30d" <?= $range === '30d' ? 'selected' : '' ?>>Last 30 Days</option>
            </select>
        </div>
        
        <div class="form-group" style="margin-bottom: 0;">
            <button type="submit" class="btn btn-primary" style="padding: 0.65rem; width: 100%;">
                <i class="fa-solid fa-filter"></i> Apply Filter
            </button>
        </div>
    </form>
</div>

<?php if ($errorMsg): ?>
    <div class="alert alert-danger animate-fade-in"><?= sanitize($errorMsg) ?></div>
<?php endif; ?>

<div class="deadlock-container animate-fade-in" style="animation-delay: 0.1s;">
    <!-- LEFT PANE: Occurrence List -->
    <div class="deadlock-list-pane">
        <h3 style="font-size: 1rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem; color: var(--text-secondary);">
            <i class="fa-solid fa-list"></i> Deadlocks (<?= count($deadlocks) ?>)
        </h3>
        
        <?php if (empty($deadlocks)): ?>
            <div class="glass-card" style="padding: 2rem; text-align: center; color: var(--text-muted);">
                <i class="fa-solid fa-shield-halved" style="font-size: 2.5rem; display: block; margin-bottom: 1rem; color: var(--color-success);"></i>
                No deadlocks detected in selected range.
            </div>
        <?php else: ?>
            <?php foreach ($deadlocks as $dl): ?>
                <div onclick="location.href='deadlocks.php?server_id=<?= $serverId ?>&range=<?= $range ?>&selected_id=<?= $dl['id'] ?>'" 
                     class="glass-card deadlock-item-card <?= $selectedId === (int)$dl['id'] ? 'active' : '' ?>">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                        <span style="font-weight: 600; font-size: 0.85rem; color: #ffffff;">
                            <i class="fa-solid fa-skull" style="color: var(--color-danger); margin-right: 0.25rem;"></i>
                            Victim SPID: <?= (int)$dl['victim_spid'] ?>
                        </span>
                        <span style="font-size: 0.75rem; color: var(--text-muted);"><?= date('H:i:s', strtotime($dl['deadlock_time'])) ?></span>
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem;">
                        <?= date('Y-m-d', strtotime($dl['deadlock_time'])) ?>
                    </div>
                    <span class="dl-meta-badge">
                        <i class="fa-solid fa-database"></i> <?= sanitize($dl['database_name']) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- RIGHT PANE: Details Analysis -->
    <div class="deadlock-details-pane">
        <?php if (!$activeDl): ?>
            <div class="glass-card" style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; text-align: center; padding: 4rem; color: var(--text-muted);">
                <i class="fa-solid fa-diagram-predecessor" style="font-size: 4rem; margin-bottom: 1.5rem; color: var(--color-primary); opacity: 0.4;"></i>
                <h3>Select a Deadlock Graph</h3>
                <p style="max-width: 400px; margin-top: 0.5rem; font-size: 0.9rem;">Choose a deadlock occurrence from the left menu list to perform structural process lock cycle analysis.</p>
            </div>
        <?php else: 
            $parsed = null;
            if (!empty($activeDl['parsed_details'])) {
                $parsed = json_decode($activeDl['parsed_details'], true);
            }
        ?>
            <!-- Overview Banner -->
            <div class="glass-card" style="padding: 1.5rem; border-left: 4px solid var(--color-danger); margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <span style="background: rgba(239, 68, 68, 0.15); color: var(--color-danger); font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.6rem; border-radius: 4px; text-transform: uppercase;">
                            Deadlock Detected
                        </span>
                        <h3 style="margin-top: 0.5rem; color: #ffffff;">
                            Database: <?= sanitize($activeDl['database_name']) ?>
                        </h3>
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.25rem;">
                            Time of occurrence: <strong><?= sanitize($activeDl['deadlock_time']) ?></strong>
                        </p>
                    </div>
                    <div style="text-align: right;">
                        <span style="font-size: 0.8rem; color: var(--text-muted); display: block;">Victim SPID</span>
                        <strong style="font-size: 2.2rem; color: var(--color-danger); line-height: 1;"><?= (int)$activeDl['victim_spid'] ?></strong>
                    </div>
                </div>
            </div>
            
            <?php if (!$parsed): ?>
                <!-- Fallback if XML failed to parse -->
                <div class="glass-card" style="padding: 2rem; margin-bottom: 1.5rem;">
                    <h4 style="color: var(--color-warning); margin-bottom: 0.5rem;">
                        <i class="fa-solid fa-triangle-exclamation"></i> Detailed Parsing Unavailable
                    </h4>
                    <p style="font-size: 0.85rem; color: var(--text-secondary); line-height: 1.6;">
                        This deadlock report could not be pre-parsed dynamically. You can inspect the complete Extended Events graph structure directly from the raw XML file representation.
                    </p>
                </div>
            <?php else: ?>
                <!-- Side-by-Side Conflicting Transactions -->
                <h3 style="font-size: 1.1rem; color: #ffffff; margin-bottom: 0.75rem;">
                    <i class="fa-solid fa-diagram-predecessor"></i> Transaction Lock Cycle Nodes
                </h3>
                
                <div class="process-grid">
                    <?php 
                    foreach ($parsed['processes'] as $proc): 
                        $isVictim = ($proc['status'] === 'rolled back (victim)');
                    ?>
                        <div class="glass-card process-card <?= $isVictim ? 'victim' : 'winner' ?>" style="padding: 1.25rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem; margin-bottom: 0.75rem;">
                                <strong style="font-size: 0.95rem; color: #ffffff;">
                                    SPID: <?= (int)$proc['spid'] ?>
                                </strong>
                                <span style="font-size: 0.75rem; font-weight: 700; padding: 0.15rem 0.5rem; border-radius: 4px; 
                                             background: <?= $isVictim ? 'rgba(239, 68, 68, 0.15)' : 'rgba(16, 185, 129, 0.15)' ?>; 
                                             color: <?= $isVictim ? 'var(--color-danger)' : 'var(--color-success)' ?>;">
                                    <?= $isVictim ? 'Rolled Back (Victim)' : 'Committed (Winner)' ?>
                                </span>
                            </div>
                            
                            <table style="width: 100%; font-size: 0.8rem; line-height: 1.6; color: var(--text-secondary);">
                                <tr>
                                    <td style="width: 35%; font-weight: 600;">Client Host:</td>
                                    <td style="color: #ffffff;"><?= sanitize($proc['hostname']) ?></td>
                                </tr>
                                <tr>
                                    <td style="font-weight: 600;">Login User:</td>
                                    <td style="color: #ffffff;"><?= sanitize($proc['login']) ?></td>
                                </tr>
                                <tr>
                                    <td style="font-weight: 600;">Wait Time:</td>
                                    <td><?= formatMs((int)$proc['waittime']) ?></td>
                                </tr>
                                <tr>
                                    <td style="font-weight: 600;">Lock Requested:</td>
                                    <td>
                                        <?php if (!empty($proc['lock_resource'])): ?>
                                            <code style="color: var(--color-warning);"><?= sanitize($proc['request_mode']) ?></code> on <code><?= sanitize($proc['lock_resource']) ?></code>
                                        <?php else: ?>
                                            <span style="font-style: italic;">No resource wait parsed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="font-weight: 600;">Holding Lock:</td>
                                    <td>
                                        <?php if ((int)$proc['holder_spid'] > 0): ?>
                                            Held by SPID <strong><?= (int)$proc['holder_spid'] ?></strong>
                                        <?php else: ?>
                                            <span style="font-style: italic;">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                            
                            <div style="margin-top: 1rem;">
                                <strong style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; display: block;">SQL Command Buffer:</strong>
                                <div class="sql-code-box"><?= sanitize($proc['sql_text']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- Raw XML Graph -->
            <div class="glass-card" style="padding: 1.5rem; margin-top: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.75rem; margin-bottom: 1rem;">
                    <h3 style="font-size: 1.1rem; color: #ffffff; margin-bottom: 0;">
                        <i class="fa-solid fa-code"></i> Raw XML Deadlock Report
                    </h3>
                    <a href="data:text/xml;charset=utf-8,<?= rawurlencode($activeDl['deadlock_graph']) ?>" 
                       download="deadlock_<?= sanitize($activeDl['deadlock_time']) ?>.xml" 
                       class="btn btn-secondary btn-sm" style="padding: 0.4rem 0.8rem; font-size: 0.75rem;">
                        <i class="fa-solid fa-download"></i> Download Graph XML
                    </a>
                </div>
                
                <div style="position: relative;">
                    <textarea readonly style="width: 100%; height: 220px; font-family: monospace; font-size: 0.75rem; background: rgba(0,0,0,0.4); 
                                      border: 1px solid var(--border-glass); color: #a7f3d0; border-radius: 6px; padding: 0.75rem; resize: none; outline: none;"
                    ><?= sanitize($activeDl['deadlock_graph']) ?></textarea>
                </div>
            </div>
            
        <?php endif; ?>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/templates/footer.php';
?>

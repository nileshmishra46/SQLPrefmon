<?php
// admin/servers.php

$pageTitle = 'Server Inventory Management';
require_once dirname(__DIR__) . '/templates/header.php';
require_once dirname(__DIR__) . '/includes/role_check.php';

// Require DBA or admin roles to manage servers
requireRole(['admin', 'dba']);

$db = getDbConnection();
$error = '';
$success = '';
$testResult = null;

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editSrv = null;
if ($editId > 0) {
    $stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
    $stmt->execute([$editId]);
    $editSrv = $stmt->fetch();
}

// Handle CRUD and Connection Testing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCsrfToken($csrfToken)) {
        $error = 'Invalid security token.';
    } else {
        if ($action === 'create') {
            $displayName = trim($_POST['display_name'] ?? '');
            $hostname = trim($_POST['hostname'] ?? '');
            $port = (int)($_POST['port'] ?? 1433);
            $instanceName = trim($_POST['instance_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $environment = $_POST['environment'] ?? 'production';
            $trustServerCert = isset($_POST['trust_server_cert']) ? 1 : 0;
            $historyCaptureMode = $_POST['history_capture_mode'] ?? 'collector';
            
            if (empty($displayName) || empty($hostname) || empty($username)) {
                $error = 'Display Name, Hostname, and Username are required.';
            } else {
                $encryptedPassword = encryptPassword($password);
                
                $stmt = $db->prepare("INSERT INTO servers (display_name, hostname, port, instance_name, username, password, environment, added_by, trust_server_cert, history_capture_mode) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$displayName, $hostname, $port, $instanceName ?: null, $username, $encryptedPassword, $environment, $_SESSION['user_id'], $trustServerCert, $historyCaptureMode]);
                if (getAppSetting('repo_db_type', 'sqlite') === 'mssql') {
                    $serverId = (int)$db->query("SELECT @@IDENTITY")->fetchColumn();
                } else {
                    $serverId = (int)$db->lastInsertId();
                }
                
                if (getAppSetting('repo_db_type', 'sqlite') === 'mssql') {
                    try {
                        $stmtCheckBoundary = $db->prepare("
                            SELECT COUNT(*) 
                            FROM sys.partition_range_values prv 
                            JOIN sys.partition_functions pf ON prv.function_id = pf.function_id
                            WHERE pf.name = 'pf_server_id' AND CAST(prv.value AS INT) = ?
                        ");
                        $stmtCheckBoundary->execute([$serverId]);
                        $boundaryExists = $stmtCheckBoundary->fetchColumn() > 0;

                        if (!$boundaryExists) {
                            $db->exec("ALTER PARTITION SCHEME ps_server_id NEXT USED [PRIMARY]");
                            $db->prepare("ALTER PARTITION FUNCTION pf_server_id() SPLIT RANGE (?)")->execute([$serverId]);
                        }
                    } catch (Exception $e) {
                        error_log("Failed to split partition boundary for server ID $serverId: " . $e->getMessage());
                    }
                }
                
                logAuditEvent($_SESSION['user_id'], 'create_server', 'server', $serverId, "Added server: $displayName ($environment)");
                $success = "Server '$displayName' added to inventory.";
            }
        } elseif ($action === 'update') {
            $serverId = (int)$_POST['server_id'] ?? 0;
            $displayName = trim($_POST['display_name'] ?? '');
            $hostname = trim($_POST['hostname'] ?? '');
            $port = (int)($_POST['port'] ?? 1433);
            $instanceName = trim($_POST['instance_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $environment = $_POST['environment'] ?? 'production';
            $trustServerCert = isset($_POST['trust_server_cert']) ? 1 : 0;
            $historyCaptureMode = $_POST['history_capture_mode'] ?? 'collector';
            
            if (empty($displayName) || empty($hostname) || empty($username)) {
                $error = 'Display Name, Hostname, and Username are required.';
            } else {
                if ($password !== '') {
                    $encryptedPassword = encryptPassword($password);
                    $stmt = $db->prepare("UPDATE servers SET display_name = ?, hostname = ?, port = ?, instance_name = ?, username = ?, password = ?, environment = ?, trust_server_cert = ?, history_capture_mode = ? WHERE id = ?");
                    $stmt->execute([$displayName, $hostname, $port, $instanceName ?: null, $username, $encryptedPassword, $environment, $trustServerCert, $historyCaptureMode, $serverId]);
                } else {
                    $stmt = $db->prepare("UPDATE servers SET display_name = ?, hostname = ?, port = ?, instance_name = ?, username = ?, environment = ?, trust_server_cert = ?, history_capture_mode = ? WHERE id = ?");
                    $stmt->execute([$displayName, $hostname, $port, $instanceName ?: null, $username, $environment, $trustServerCert, $historyCaptureMode, $serverId]);
                }
                
                logAuditEvent($_SESSION['user_id'], 'update_server', 'server', $serverId, "Updated server: $displayName ($environment)");
                $success = "Server '$displayName' details updated successfully.";
                $editId = 0;
                $editSrv = null;
            }
        } elseif ($action === 'delete') {
            $serverId = (int)($_POST['server_id'] ?? 0);
            
            // Get display name
            $nameQuery = $db->prepare("SELECT display_name FROM servers WHERE id = ?");
            $nameQuery->execute([$serverId]);
            $displayName = $nameQuery->fetchColumn();
            
            $stmt = $db->prepare("DELETE FROM servers WHERE id = ?");
            $stmt->execute([$serverId]);
            
            logAuditEvent($_SESSION['user_id'], 'delete_server', 'server', $serverId, "Removed server: $displayName");
            $success = "Server '$displayName' removed from inventory.";
        } elseif ($action === 'test_conn') {
            $serverId = (int)($_POST['server_id'] ?? 0);
            
            // Fetch server info
            $stmt = $db->prepare("SELECT * FROM servers WHERE id = ?");
            $stmt->execute([$serverId]);
            $srv = $stmt->fetch();
            
            if ($srv) {
                if ($srv['environment'] === 'demo') {
                    // Instantly succeed for Demo environment
                    $update = $db->prepare("UPDATE servers SET last_checked = CURRENT_TIMESTAMP, last_status = 'online' WHERE id = ?");
                    $update->execute([$serverId]);
                    
                    $testResult = [
                        'status' => 'success',
                        'message' => 'Connection succeeded! (Simulated Demo Server)',
                        'server_id' => $serverId
                    ];
                    logAuditEvent($_SESSION['user_id'], 'test_connection_success', 'server', $serverId, "Tested connection: {$srv['display_name']} (Simulated Success)");
                } else {
                    // Load connector to check real server connection
                    require_once dirname(__DIR__) . '/engine/connector.php';
                    $decryptedPass = decryptPassword($srv['password']);
                    
                    $status = 'offline';
                    $testMessage = '';
                    
                    try {
                        $conn = testSqlServerConnection($srv['hostname'], $srv['port'], $srv['instance_name'], $srv['username'], $decryptedPass, (bool)($srv['trust_server_cert'] ?? false));
                        if ($conn) {
                            $status = 'online';
                            $testMessage = 'Connection succeeded! Found SQL Server version: ' . $conn;
                        }
                    } catch (Exception $e) {
                        $status = 'error';
                        $testMessage = $e->getMessage();
                    }
                    
                    // Update server status in db
                    $update = $db->prepare("UPDATE servers SET last_checked = CURRENT_TIMESTAMP, last_status = ? WHERE id = ?");
                    $update->execute([$status, $serverId]);
                    
                    $testResult = [
                        'status' => ($status === 'online') ? 'success' : 'failed',
                        'message' => $testMessage,
                        'server_id' => $serverId
                    ];
                    
                    logAuditEvent($_SESSION['user_id'], ($status === 'online') ? 'test_connection_success' : 'test_connection_failed', 'server', $serverId, "Tested connection: {$srv['display_name']} - Status: $status. Details: $testMessage");
                }
            } else {
                $error = 'Server not found.';
            }
        }
    }
}

// Fetch all servers
$servers = $db->query("SELECT id, display_name, hostname, port, instance_name, username, environment, last_checked, last_status, trust_server_cert, history_capture_mode FROM servers ORDER BY display_name ASC")->fetchAll();
$globalPollerEnabled = getAppSetting('poller_enabled', false);
?>

<div class="dashboard-header-container animate-fade-in">
    <div class="dashboard-title-area">
        <h2>Server Inventory Management</h2>
        <p>Register SQL Server monitoring targets, check credentials, and diagnose connection health.</p>
    </div>
    <div>
        <a href="index.php" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i>
            Back to Admin
        </a>
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

<?php if ($testResult): ?>
    <div class="alert <?= $testResult['status'] === 'success' ? 'alert-success' : 'alert-danger' ?> animate-fade-in">
        <i class="fa-solid <?= $testResult['status'] === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> alert-icon"></i>
        <div>
            <strong>Connection Test Result:</strong>
            <p style="margin-top: 0.25rem; font-size: 0.85rem;"><?= sanitize($testResult['message']) ?></p>
        </div>
    </div>
<?php endif; ?>

<div class="grid-2 animate-fade-in" style="animation-delay: 0.1s; margin-bottom: 2rem; grid-template-columns: 1.55fr 1fr;">
    <!-- Servers List -->
    <div class="glass-card">
        <h3 style="margin-bottom: 1rem;">Monitored SQL Instances</h3>
        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Display Name</th>
                        <th>Connection DSN</th>
                        <th>Env</th>
                        <th>Capture Mode</th>
                        <th>Status</th>
                        <th>Last Checked</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($servers)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--text-muted); font-style: italic;">
                                No servers registered in the database inventory. Use the registration form to add your first instance.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($servers as $srv): 
                            $statusBadge = 'badge-info';
                            if ($srv['last_status'] === 'online') {
                                $statusBadge = 'badge-success';
                            } elseif ($srv['last_status'] === 'offline') {
                                $statusBadge = 'badge-danger';
                            } elseif ($srv['last_status'] === 'error') {
                                $statusBadge = 'badge-warning';
                            }
                            
                            $dsn = $srv['hostname'];
                            if ($srv['port'] != 1433) {
                                $dsn .= ':' . $srv['port'];
                            }
                            if ($srv['instance_name']) {
                                $dsn .= '\\' . $srv['instance_name'];
                            }
                        ?>
                            <tr>
                                <td>
                                    <strong><a href="../server/detail.php?id=<?= $srv['id'] ?>"><?= sanitize($srv['display_name']) ?></a></strong>
                                </td>
                                <td style="font-size: 0.8rem; font-family: monospace; color: var(--color-info);"><?= sanitize($dsn) ?></td>
                                <td>
                                    <span class="badge <?= $srv['environment'] === 'production' ? 'env-production' : ($srv['environment'] === 'staging' ? 'env-staging' : ($srv['environment'] === 'dev' ? 'env-dev' : 'env-demo')) ?>" style="padding: 0.1rem 0.3rem;">
                                        <?= sanitize($srv['environment']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (($srv['history_capture_mode'] ?? 'collector') === 'ash'): ?>
                                        <span class="badge badge-success" style="padding: 0.1rem 0.3rem;">ASH</span>
                                        <?php if (!$globalPollerEnabled): ?>
                                            <i class="fa-solid fa-triangle-exclamation" style="color: var(--color-warning); margin-left: 0.25rem; cursor: help;" title="Warning: Global ASH Poller is disabled. Falling back to periodic Data Collector."></i>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge badge-info" style="padding: 0.1rem 0.3rem;">Collector</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= $statusBadge ?>"><?= sanitize($srv['last_status']) ?></span>
                                </td>
                                <td style="font-size: 0.8rem; color: var(--text-secondary);"><?= sanitize($srv['last_checked'] ?: 'Never') ?></td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; gap: 0.5rem; justify-content: flex-end;">
                                        <!-- Test Conn button -->
                                        <form action="servers.php" method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="test_conn">
                                            <input type="hidden" name="server_id" value="<?= $srv['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                            <button type="submit" class="btn btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.75rem;" title="Test ODBC Connection">
                                                <i class="fa-solid fa-plug-circle-check"></i> Test
                                            </button>
                                        </form>
                                        
                                        <!-- Delete Button with Inline Two-Stage Confirmation -->
                                        <form action="servers.php" method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="server_id" value="<?= $srv['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                            <div style="display: inline-flex; gap: 0.25rem;" class="delete-zone">
                                                <button type="button" class="btn btn-danger btn-delete-init" style="padding: 0.35rem 0.5rem; font-size: 0.75rem;" title="Delete server" onclick="showDeleteConfirm(this)">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                                <button type="submit" class="btn btn-danger btn-delete-confirm" style="padding: 0.35rem 0.5rem; font-size: 0.75rem; display: none;" title="Confirm deletion">
                                                    <i class="fa-solid fa-triangle-exclamation"></i> Delete?
                                                </button>
                                                <button type="button" class="btn btn-secondary btn-delete-cancel" style="padding: 0.35rem 0.5rem; font-size: 0.75rem; display: none;" title="Cancel" onclick="cancelDelete(this)">
                                                    <i class="fa-solid fa-xmark"></i>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Register/Modify Server Form -->
    <div class="glass-card">
        <h3 style="margin-bottom: 1.25rem;"><?= $editSrv ? 'Modify Connection: ' . sanitize($editSrv['display_name']) : 'Register Database Server' ?></h3>
        <form action="servers.php" method="POST">
            <input type="hidden" name="action" value="<?= $editSrv ? 'update' : 'create' ?>">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            <?php if ($editSrv): ?>
                <input type="hidden" name="server_id" value="<?= (int)$editSrv['id'] ?>">
            <?php endif; ?>
            
            <div class="form-group">
                <label for="display_name">Friendly Display Name</label>
                <div class="input-with-icon">
                    <i class="fa-solid fa-tag input-icon"></i>
                    <input type="text" id="display_name" name="display_name" placeholder="e.g. SQL01 - Production" value="<?= $editSrv ? sanitize($editSrv['display_name']) : '' ?>" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="hostname">Server Hostname or IP</label>
                <div class="input-with-icon">
                    <i class="fa-solid fa-network-wired input-icon"></i>
                    <input type="text" id="hostname" name="hostname" placeholder="e.g. localhost, sqlserver.local" value="<?= $editSrv ? sanitize($editSrv['hostname']) : '' ?>" required>
                </div>
            </div>
            
            <div class="grid-2" style="gap: 1rem;">
                <div class="form-group">
                    <label for="port">Port</label>
                    <input type="number" id="port" name="port" value="<?= $editSrv ? (int)$editSrv['port'] : '1433' ?>" class="no-icon-input" required>
                </div>
                
                <div class="form-group">
                    <label for="instance_name">Named Instance (Optional)</label>
                    <input type="text" id="instance_name" name="instance_name" placeholder="e.g. SQLEXPRESS" value="<?= $editSrv ? sanitize($editSrv['instance_name']) : '' ?>" class="no-icon-input">
                </div>
            </div>
            
            <div class="form-group">
                <label for="username">Monitoring SQL Login</label>
                <div class="input-with-icon">
                    <i class="fa-solid fa-user-shield input-icon"></i>
                    <input type="text" id="username" name="username" placeholder="e.g. sqlperf_monitor" value="<?= $editSrv ? sanitize($editSrv['username']) : '' ?>" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-with-icon">
                    <i class="fa-solid fa-key input-icon"></i>
                    <input type="password" id="password" name="password" placeholder="<?= $editSrv ? 'Leave empty to keep unchanged' : 'Enter credential password' ?>" <?= $editSrv ? '' : 'required' ?>>
                </div>
            </div>
            
            <div class="form-group" style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1.25rem;">
                <input type="checkbox" id="trust_server_cert" name="trust_server_cert" value="1" style="width: auto; margin-right: 0.5rem; cursor: pointer;" <?= $editSrv && $editSrv['trust_server_cert'] ? 'checked' : '' ?>>
                <label for="trust_server_cert" style="display: inline; margin-bottom: 0; cursor: pointer; color: var(--text-secondary); font-weight: 500;">Trust Server Certificate (Mandatory for SQL Server 2022 / ODBC 18)</label>
            </div>
            
            <div class="form-group">
                <label for="environment">Environment Tag</label>
                <div class="input-with-icon">
                    <i class="fa-solid fa-tags input-icon"></i>
                    <select id="environment" name="environment" required>
                        <option value="demo" <?= $editSrv && $editSrv['environment'] === 'demo' ? 'selected' : '' ?>>Demo / Simulator Mode (Produces realistic mock data)</option>
                        <option value="production" <?= !$editSrv || $editSrv['environment'] === 'production' ? 'selected' : '' ?>>Production Instance (Real connection)</option>
                        <option value="staging" <?= $editSrv && $editSrv['environment'] === 'staging' ? 'selected' : '' ?>>Staging Instance (Real connection)</option>
                        <option value="dev" <?= $editSrv && $editSrv['environment'] === 'dev' ? 'selected' : '' ?>>Dev/Test Instance (Real connection)</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label for="history_capture_mode">Historical Query & Blocking Capture Mode</label>
                <div class="input-with-icon">
                    <i class="fa-solid fa-clock-rotate-left input-icon"></i>
                    <select id="history_capture_mode" name="history_capture_mode" required>
                        <option value="collector" <?= !$editSrv || ($editSrv['history_capture_mode'] ?? 'collector') === 'collector' ? 'selected' : '' ?>>Data Collector (Periodic, every 5 minutes)</option>
                        <option value="ash" <?= $editSrv && ($editSrv['history_capture_mode'] ?? '') === 'ash' ? 'selected' : '' ?>>Active Session History (Continuous, real-time)</option>
                    </select>
                </div>
            </div>
            
            <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                <button type="submit" class="btn btn-primary btn-glow" style="flex: 1;">
                    <i class="fa-solid <?= $editSrv ? 'fa-floppy-disk' : 'fa-circle-plus' ?>"></i>
                    <span><?= $editSrv ? 'Save Changes' : 'Register Server' ?></span>
                </button>
                <?php if ($editSrv): ?>
                    <a href="servers.php" class="btn btn-secondary" style="display: inline-flex; align-items: center; justify-content: center;">
                        Cancel
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
function showDeleteConfirm(btn) {
    const zone = btn.closest('.delete-zone');
    zone.querySelector('.btn-delete-init').style.display = 'none';
    zone.querySelector('.btn-delete-confirm').style.display = 'inline-flex';
    zone.querySelector('.btn-delete-cancel').style.display = 'inline-flex';
}

function cancelDelete(btn) {
    const zone = btn.closest('.delete-zone');
    zone.querySelector('.btn-delete-init').style.display = 'inline-flex';
    zone.querySelector('.btn-delete-confirm').style.display = 'none';
    zone.querySelector('.btn-delete-cancel').style.display = 'none';
}
</script>

<?php
require_once dirname(__DIR__) . '/templates/footer.php';
?>

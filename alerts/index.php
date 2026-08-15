<?php
// alerts/index.php
$pageTitle = 'Alert Notification Center';

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/templates/header.php';

$db = getDbConnection();
$error = '';
$success = '';

$isAdminOrDba = (isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'dba'));

// Load current configuration settings or fallbacks
$smtpEnabled = (bool)getAppSetting('smtp_enabled', false);
$smtpHost    = getAppSetting('smtp_host', '');
$smtpPort    = (int)getAppSetting('smtp_port', 25);
$smtpUser    = getAppSetting('smtp_user', '');
$smtpPass    = getAppSetting('smtp_pass', '');
$smtpSecure  = getAppSetting('smtp_secure', 'none');
$smtpFrom    = getAppSetting('smtp_from', 'alerts@sqlprefmon.local');
$smtpTo      = getAppSetting('smtp_to', 'admin@sqlprefmon.local');
$spaceThresh = (float)getAppSetting('db_file_space_threshold_pct', 10.0);
$rules       = getAppSetting('alert_rules', [
    'offline'      => true,
    'cpu'          => true,
    'ple'          => true,
    'disk_latency' => true,
    'blocking'     => true,
    'db_file_space'=> true
]);

// 1. Process Settings Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    if (!$isAdminOrDba) {
        $error = 'Unauthorized action. Only administrators can change SMTP configs.';
    } else {
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!validateCsrfToken($csrfToken)) {
            $error = 'Invalid security token.';
        } else {
            $smtpEnabled = isset($_POST['smtp_enabled']) ? true : false;
            $smtpHost    = trim($_POST['smtp_host'] ?? '');
            $smtpPort    = (int)($_POST['smtp_port'] ?? 25);
            $smtpUser    = trim($_POST['smtp_user'] ?? '');
            $smtpPass    = trim($_POST['smtp_pass'] ?? '');
            $smtpSecure  = $_POST['smtp_secure'] ?? 'none';
            $smtpFrom    = trim($_POST['smtp_from'] ?? 'alerts@sqlprefmon.local');
            $smtpTo      = trim($_POST['smtp_to'] ?? 'admin@sqlprefmon.local');
            $spaceThresh = (float)($_POST['db_file_space_threshold_pct'] ?? 10.0);
            
            $rules = [
                'offline'      => isset($_POST['rule_offline']),
                'cpu'          => isset($_POST['rule_cpu']),
                'ple'          => isset($_POST['rule_ple']),
                'disk_latency' => isset($_POST['rule_disk_latency']),
                'blocking'     => isset($_POST['rule_blocking']),
                'db_file_space'=> isset($_POST['rule_db_file_space'])
            ];
            
            // Save to config/settings.json
            $settingsPath = dirname(__DIR__) . '/config/settings.json';
            $existingSettings = file_exists($settingsPath) ? json_decode(file_get_contents($settingsPath), true) : [];
            
            $newSettings = array_merge($existingSettings, [
                'smtp_enabled' => $smtpEnabled,
                'smtp_host'    => $smtpHost,
                'smtp_port'    => $smtpPort,
                'smtp_user'    => $smtpUser,
                'smtp_pass'    => $smtpPass,
                'smtp_secure'  => $smtpSecure,
                'smtp_from'    => $smtpFrom,
                'smtp_to'      => $smtpTo,
                'db_file_space_threshold_pct' => $spaceThresh,
                'alert_rules'  => $rules
            ]);
            
            if (file_put_contents($settingsPath, json_encode($newSettings, JSON_PRETTY_PRINT))) {
                logAuditEvent($_SESSION['user_id'], 'update_alert_settings', 'config', null, 'Modified email alert & SMTP configuration.');
                $success = 'Alert routing and SMTP settings updated successfully.';
            } else {
                $error = 'Failed to write settings to file. Verify file permissions.';
            }
        }
    }
}

// 2. Process Test Email Trigger
$testEmailResult = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_email') {
    if (!$isAdminOrDba) {
        $error = 'Unauthorized action.';
    } else {
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!validateCsrfToken($csrfToken)) {
            $error = 'Invalid security token.';
        } else {
            // Load code
            require_once dirname(__DIR__) . '/engine/alerts.php';
            
            $testSubject = "[TEST] SQLPrefmon Alert Notification Engine Validate";
            $testBody = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: 'Segoe UI', sans-serif; background-color: #f8f9fc; padding: 20px; color: #3a3b45; }
                    .card { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; border-top: 5px solid #4e73df; padding: 25px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
                    h2 { color: #1a1c2e; margin-top: 0; }
                </style>
            </head>
            <body>
                <div class='card'>
                    <h2>SQLPrefmon Email Validation</h2>
                    <p>Congratulations! This test mail confirms that your SQLPrefmon alert notifications engine and SMTP configurations are working correctly.</p>
                    <p style='color: #858796; font-size: 13px;'>Test triggered at: " . date('Y-m-d H:i:s') . "</p>
                </div>
            </body>
            </html>
            ";
            
            try {
                if ($smtpEnabled) {
                    sendSmtpEmail($smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpSecure, $smtpFrom, $smtpTo, $testSubject, $testBody);
                    $success = "Test email sent successfully to: {$smtpTo}!";
                } else {
                    // Log email to mock file
                    $emailLogPath = dirname(__DIR__) . '/logs/emails.log';
                    if (!file_exists(dirname($emailLogPath))) {
                        mkdir(dirname($emailLogPath), 0755, true);
                    }
                    $logEntry = "TIMESTAMP: " . date('Y-m-d H:i:s') . " (TEST EMAIL LOGGED - SMTP DISABLED)" . PHP_EOL;
                    $logEntry .= "TO: " . $smtpTo . PHP_EOL;
                    $logEntry .= "SUBJECT: " . $testSubject . PHP_EOL . PHP_EOL;
                    file_put_contents($emailLogPath, $logEntry, FILE_APPEND);
                    
                    $success = "SMTP is disabled. Test notification successfully written to logs/emails.log!";
                }
            } catch (Exception $e) {
                $error = "Failed to route test notification email: " . $e->getMessage();
            }
        }
    }
}

// 3. Fetch Triggered Alerts Logs
$alertLogs = [];
try {
    $alertLogs = $db->query("
        SELECT a.*, s.display_name, s.environment 
        FROM triggered_alerts a 
        LEFT JOIN servers s ON a.server_id = s.id 
        ORDER BY a.collected_at DESC 
        LIMIT 100
    ")->fetchAll();
} catch (Exception $e) {
    // Database schema might not be updated yet
}
?>

<!-- Header -->
<div class="dashboard-header-container animate-fade-in">
    <div class="dashboard-title-area">
        <h2>Alert Notification Center</h2>
        <p>Audit triggered warning/critical system alerts and configure SMTP email delivery parameters.</p>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger animate-fade-in">
        <i class="fa-solid fa-triangle-exclamation alert-icon"></i>
        <span><?= sanitize($error) ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success animate-fade-in">
        <i class="fa-solid fa-circle-check alert-icon"></i>
        <span><?= sanitize($success) ?></span>
    </div>
<?php endif; ?>

<!-- Tabs -->
<div class="tabs-container animate-fade-in" style="animation-delay: 0.05s;">
    <div class="tabs-header">
        <button class="tab-btn active" data-tab="tab-alert-logs">
            <i class="fa-solid fa-clock-rotate-left"></i> Triggered Alerts Audit
        </button>
        <?php if ($isAdminOrDba): ?>
            <button class="tab-btn" data-tab="tab-email-config">
                <i class="fa-solid fa-envelope-open-text"></i> Email & SMTP Settings
            </button>
        <?php endif; ?>
    </div>
    
    <!-- Tab 1: Triggered Alerts Logs -->
    <div id="tab-alert-logs" class="tab-pane active">
        <div class="glass-card" style="padding: 1.5rem; margin-top: 0;">
            <h3 style="margin-bottom: 0.25rem;">Captured Alert Trigger History</h3>
            <p style="color: var(--text-secondary); font-size: 0.85rem; margin-bottom: 1.5rem;">Historical listing of threshold breaches and automatic recovery event logs (up to 30 days retention).</p>
            
            <?php if (empty($alertLogs)): ?>
                <div style="text-align: center; padding: 4rem 2rem; color: var(--text-secondary);">
                    <i class="fa-solid fa-bell-slash" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1.5rem;"></i>
                    <h4>No Alerts Logged Yet</h4>
                    <p style="margin-top: 0.5rem;">Everything is normal! No threshold breaches or server outages have been detected.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive" style="margin-top: 0;">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>Server</th>
                                <th>Time</th>
                                <th>Alert Type</th>
                                <th>Severity</th>
                                <th>Alert Message</th>
                                <th>Email Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alertLogs as $log): 
                                $sevClass = 'badge-info';
                                if ($log['severity'] === 'Critical') {
                                    $sevClass = 'badge-danger';
                                } elseif ($log['severity'] === 'Warning') {
                                    $sevClass = 'badge-warning';
                                } elseif ($log['severity'] === 'Resolved') {
                                    $sevClass = 'badge-success';
                                }
                            ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; flex-direction: column;">
                                            <span style="font-weight: 600; color: var(--text-primary);"><?= sanitize($log['display_name'] ?? 'Deleted Server') ?></span>
                                            <span class="badge <?= $log['environment'] === 'production' ? 'env-production' : 'badge-secondary' ?>" style="font-size: 0.65rem; width: fit-content; padding: 0.1rem 0.3rem; margin-top: 0.25rem;">
                                                <?= sanitize($log['environment'] ?? 'DEMO') ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td><?= sanitize($log['collected_at']) ?></td>
                                    <td><strong><?= sanitize($log['alert_type']) ?></strong></td>
                                    <td>
                                        <span class="badge <?= $sevClass ?>"><?= sanitize($log['severity']) ?></span>
                                    </td>
                                    <td style="max-width: 320px; font-size: 0.85rem; line-height: 1.4;">
                                        <span style="white-space: pre-wrap; font-family: monospace;"><?= sanitize($log['message']) ?></span>
                                    </td>
                                    <td>
                                        <?php if ($log['email_sent'] === 1): ?>
                                            <span style="color: var(--color-success); font-size: 0.85rem;">
                                                <i class="fa-solid fa-circle-check"></i> Routed
                                            </span>
                                            <?php if ($log['email_error']): ?>
                                                <div style="font-size: 0.7rem; color: var(--text-muted);"><?= sanitize($log['email_error']) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: var(--color-danger); font-size: 0.85rem;" title="<?= sanitize($log['email_error'] ?: 'Delivery failed') ?>">
                                                <i class="fa-solid fa-circle-xmark"></i> Failed
                                            </span>
                                            <div style="font-size: 0.7rem; color: var(--color-danger); max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= sanitize($log['email_error']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tab 2: Notification Config & SMTP Setup -->
    <?php if ($isAdminOrDba): ?>
        <div id="tab-email-config" class="tab-pane">
            <div style="display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 2rem;">
                
                <!-- SMTP Settings Form -->
                <div class="glass-card" style="padding: 1.5rem; margin-top: 0;">
                    <h3 style="margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-glass); padding-bottom: 0.5rem;">
                        <i class="fa-solid fa-gears" style="color: var(--color-primary); margin-right: 0.5rem;"></i>
                        Alert Configuration & SMTP Server setup
                    </h3>
                    
                    <form action="index.php" method="POST" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                        <input type="hidden" name="action" value="save_settings">
                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                        
                        <div class="form-group" style="grid-column: span 2; display: flex; align-items: center; gap: 0.75rem; border-bottom: 1px dashed var(--border-glass); padding-bottom: 1rem; margin-bottom: 0.5rem;">
                            <input type="checkbox" id="smtp_enabled" name="smtp_enabled" <?= $smtpEnabled ? 'checked' : '' ?> style="width: 18px; height: 18px; cursor: pointer;">
                            <div>
                                <label for="smtp_enabled" style="font-weight: 600; cursor: pointer; margin-bottom: 0.15rem;">Enable Email Notifications</label>
                                <small style="color: var(--text-secondary); display: block; font-size: 0.75rem;">If unchecked, alert notifications will be silently logged to <code>logs/emails.log</code> instead of routing via SMTP.</small>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="smtp_host">SMTP Server Host</label>
                            <input type="text" id="smtp_host" name="smtp_host" value="<?= sanitize($smtpHost) ?>" placeholder="e.g. smtp.gmail.com" class="no-icon-input" required>
                        </div>

                        <div class="form-group">
                            <label for="smtp_port">SMTP Port</label>
                            <input type="number" id="smtp_port" name="smtp_port" value="<?= $smtpPort ?>" placeholder="e.g. 587" class="no-icon-input" required>
                        </div>

                        <div class="form-group">
                            <label for="smtp_user">SMTP Username</label>
                            <input type="text" id="smtp_user" name="smtp_user" value="<?= sanitize($smtpUser) ?>" placeholder="SMTP login username" class="no-icon-input">
                        </div>

                        <div class="form-group">
                            <label for="smtp_pass">SMTP Password</label>
                            <input type="password" id="smtp_pass" name="smtp_pass" value="<?= sanitize($smtpPass) ?>" placeholder="SMTP login password" class="no-icon-input">
                        </div>

                        <div class="form-group">
                            <label for="smtp_secure">SMTP Security Protocol</label>
                            <select id="smtp_secure" name="smtp_secure" class="no-icon-input" style="padding: 0.6rem 1rem;">
                                <option value="none" <?= $smtpSecure === 'none' ? 'selected' : '' ?>>None (Plain SMTP / STARTTLS upgrade)</option>
                                <option value="ssl" <?= $smtpSecure === 'ssl' ? 'selected' : '' ?>>SSL (Implicit secure encryption)</option>
                                <option value="tls" <?= $smtpSecure === 'tls' ? 'selected' : '' ?>>TLS (Explicit upgrade encryption)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="db_file_space_threshold_pct">DB File Low Free Space Alert (%)</label>
                            <input type="number" id="db_file_space_threshold_pct" name="db_file_space_threshold_pct" value="<?= $spaceThresh ?>" step="0.1" max="50" min="1" class="no-icon-input" required>
                        </div>

                        <div class="form-group">
                            <label for="smtp_from">Sender Address (From)</label>
                            <input type="email" id="smtp_from" name="smtp_from" value="<?= sanitize($smtpFrom) ?>" placeholder="sender@domain.com" class="no-icon-input" required>
                        </div>

                        <div class="form-group">
                            <label for="smtp_to">Recipient List (To)</label>
                            <input type="text" id="smtp_to" name="smtp_to" value="<?= sanitize($smtpTo) ?>" placeholder="e.g. admin1@local,admin2@local" class="no-icon-input" required>
                            <small style="color: var(--text-muted); font-size: 0.7rem; display: block; margin-top: 0.25rem;">Separate multiple recipient emails with commas.</small>
                        </div>

                        <div class="form-group" style="grid-column: span 2; border-top: 1px dashed var(--border-glass); padding-top: 1rem;">
                            <label style="font-weight: 600; margin-bottom: 0.75rem;">Enable Alerts for Specific Rules</label>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <input type="checkbox" id="rule_offline" name="rule_offline" <?= ($rules['offline'] ?? true) ? 'checked' : '' ?>>
                                    <label for="rule_offline" style="cursor: pointer; margin-bottom:0;">Server Offline/Unreachable</label>
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <input type="checkbox" id="rule_cpu" name="rule_cpu" <?= ($rules['cpu'] ?? true) ? 'checked' : '' ?>>
                                    <label for="rule_cpu" style="cursor: pointer; margin-bottom:0;">High CPU Utilization</label>
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <input type="checkbox" id="rule_ple" name="rule_ple" <?= ($rules['ple'] ?? true) ? 'checked' : '' ?>>
                                    <label for="rule_ple" style="cursor: pointer; margin-bottom:0;">Low Page Life Expectancy</label>
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <input type="checkbox" id="rule_disk_latency" name="rule_disk_latency" <?= ($rules['disk_latency'] ?? true) ? 'checked' : '' ?>>
                                    <label for="rule_disk_latency" style="cursor: pointer; margin-bottom:0;">Disk Latency Bottlenecks</label>
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <input type="checkbox" id="rule_blocking" name="rule_blocking" <?= ($rules['blocking'] ?? true) ? 'checked' : '' ?>>
                                    <label for="rule_blocking" style="cursor: pointer; margin-bottom:0;">Active Blocked processes</label>
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <input type="checkbox" id="rule_db_file_space" name="rule_db_file_space" <?= ($rules['db_file_space'] ?? true) ? 'checked' : '' ?>>
                                    <label for="rule_db_file_space" style="cursor: pointer; margin-bottom:0;">DB MDF/LDF Low Space</label>
                                </div>
                            </div>
                        </div>

                        <div style="grid-column: span 2; display: flex; justify-content: flex-end; margin-top: 1rem;">
                            <button type="submit" class="btn btn-primary btn-glow">
                                <i class="fa-solid fa-floppy-disk"></i> Save Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Test E-mail trigger and info card -->
                <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                    
                    <!-- Send Test Mail Panel -->
                    <div class="glass-card" style="padding: 1.5rem; margin-top: 0;">
                        <h3 style="margin-bottom: 1rem;"><i class="fa-solid fa-paper-plane" style="color: var(--color-primary); margin-right: 0.5rem;"></i> Validate Configuration</h3>
                        <p style="color: var(--text-secondary); font-size: 0.85rem; margin-bottom: 1.25rem;">Send a mock diagnostic alert to verify that SMTP routing is operating correctly.</p>
                        
                        <form action="index.php" method="POST">
                            <input type="hidden" name="action" value="test_email">
                            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                            <button type="submit" class="btn btn-secondary btn-block">
                                <i class="fa-solid fa-envelope-circle-check"></i> Send Test Notification
                            </button>
                        </form>
                    </div>

                    <!-- Email Formatting details -->
                    <div class="glass-card" style="padding: 1.5rem; margin-top: 0; border-left: 4px solid var(--color-primary);">
                        <h4 style="margin-bottom: 0.5rem;"><i class="fa-solid fa-circle-info"></i> Alert Routing Details</h4>
                        <p style="font-size: 0.8rem; line-height: 1.5; color: var(--text-secondary); margin-bottom: 0.5rem;">
                            <strong>Mock Mode Support:</strong> When email notifications are disabled, SQLPrefmon writes simulated mails into the file <code>logs/emails.log</code>.
                        </p>
                        <p style="font-size: 0.8rem; line-height: 1.5; color: var(--text-secondary); margin-bottom: 0.5rem;">
                            <strong>Anti-Spam Throttling:</strong> To prevent floods, alert emails are throttled to a maximum of <strong>once every 2 hours</strong> per alert type per server while remaining active.
                        </p>
                        <p style="font-size: 0.8rem; line-height: 1.5; color: var(--text-secondary);">
                            <strong>Auto-Resolution:</strong> Once metrics drop back below threshold parameters, a green "Resolved" email is sent immediately.
                        </p>
                        <p style="font-size: 0.8rem; line-height: 1.5; color: var(--text-secondary); margin-top: 0.5rem; border-top: 1px dashed var(--border-glass); padding-top: 0.5rem;">
                            <strong>Data Retention Purge:</strong> Metrics snapshots and alerts logs are automatically kept for <strong><?= (int)getAppSetting('retention_days', 30) ?> days</strong>. Admins can configure this duration in the <a href="../admin/settings.php" style="color: var(--color-primary); text-decoration: underline;">Global Settings Panel</a>.
                        </p>
                    </div>
                </div>

            </div>
        </div>
    <?php endif; ?>
</div>

<?php
require_once dirname(__DIR__) . '/templates/footer.php';
?>

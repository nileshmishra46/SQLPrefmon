<?php
// admin/users.php

$pageTitle = 'User Management';
require_once dirname(__DIR__) . '/templates/header.php';
require_once dirname(__DIR__) . '/includes/role_check.php';

// Only administrators are allowed to manage users
requireRole('admin');

$db = getDbConnection();
$error = '';
$success = '';

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCsrfToken($csrfToken)) {
        $error = 'Invalid security token.';
    } else {
        if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $role = $_POST['role'] ?? 'viewer';
            
            if (empty($username) || empty($password)) {
                $error = 'Username and Password are required.';
            } else {
                // Check if username already exists
                $check = $db->prepare("SELECT id FROM users WHERE username = ?");
                $check->execute([$username]);
                if ($check->fetch()) {
                    $error = 'Username already exists.';
                } else {
                    $hashed = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $db->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$username, $hashed, $email, $role]);
                    if (getAppSetting('repo_db_type', 'sqlite') === 'mssql') {
                        $newUserId = (int)$db->query("SELECT @@IDENTITY")->fetchColumn();
                    } else {
                        $newUserId = (int)$db->lastInsertId();
                    }
                    
                    logAuditEvent($_SESSION['user_id'], 'create_user', 'user', $newUserId, "Created user: $username ($role)");
                    $success = "User '$username' created successfully.";
                }
            }
        } elseif ($action === 'update_role') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $role = $_POST['role'] ?? 'viewer';
            
            if ($userId === (int)$_SESSION['user_id']) {
                $error = 'You cannot modify your own role.';
            } else {
                $stmt = $db->prepare("UPDATE users SET role = ? WHERE id = ?");
                $stmt->execute([$role, $userId]);
                
                logAuditEvent($_SESSION['user_id'], 'update_user_role', 'user', $userId, "Updated role to: $role");
                $success = "User role updated successfully.";
            }
        } elseif ($action === 'toggle_active') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $status = (int)($_POST['is_active'] ?? 0);
            
            if ($userId === (int)$_SESSION['user_id']) {
                $error = 'You cannot deactivate your own account.';
            } else {
                $stmt = $db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                $stmt->execute([$status, $userId]);
                
                $statusText = $status ? 'activated' : 'deactivated';
                logAuditEvent($_SESSION['user_id'], 'toggle_user_status', 'user', $userId, "User $statusText.");
                $success = "User status updated successfully.";
            }
        } elseif ($action === 'delete') {
            $userId = (int)($_POST['user_id'] ?? 0);
            
            if ($userId === (int)$_SESSION['user_id']) {
                $error = 'You cannot delete your own account.';
            } else {
                // Fetch username for logging
                $nameQuery = $db->prepare("SELECT username FROM users WHERE id = ?");
                $nameQuery->execute([$userId]);
                $username = $nameQuery->fetchColumn();
                
                $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                
                logAuditEvent($_SESSION['user_id'], 'delete_user', 'user', $userId, "Deleted user: $username");
                $success = "User '$username' has been deleted.";
            }
        }
    }
}

// Fetch all users
$users = $db->query("SELECT id, username, email, role, is_active, created_at, last_login FROM users ORDER BY username ASC")->fetchAll();
?>

<div class="dashboard-header-container animate-fade-in">
    <div class="dashboard-title-area">
        <h2>User Account Management</h2>
        <p>Control client credentials, assign roles, and audit access permissions.</p>
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

<div class="grid-2 animate-fade-in" style="animation-delay: 0.1s; margin-bottom: 2rem; grid-template-columns: 1.5fr 1fr;">
    <!-- Users List -->
    <div class="glass-card">
        <h3 style="margin-bottom: 1rem;">Registered Accounts</h3>
        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td>
                                <strong><?= sanitize($u['username']) ?></strong>
                                <?php if ($u['id'] === (int)$_SESSION['user_id']): ?>
                                    <span style="font-size: 0.7rem; padding: 0.1rem 0.3rem; background-color: rgba(255,255,255,0.1); border-radius: 4px; margin-left: 0.25rem;">(You)</span>
                                <?php endif; ?>
                            </td>
                            <td><?= sanitize($u['email'] ?: 'N/A') ?></td>
                            <td>
                                <form action="users.php" method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="update_role">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                    <select name="role" onchange="this.form.submit()" style="padding: 0.25rem 0.5rem; font-size: 0.8rem; width: auto; background-color: #0b0f19; height: auto;" <?= $u['id'] === (int)$_SESSION['user_id'] ? 'disabled' : '' ?>>
                                        <option value="viewer" <?= $u['role'] === 'viewer' ? 'selected' : '' ?>>Viewer</option>
                                        <option value="dba" <?= $u['role'] === 'dba' ? 'selected' : '' ?>>DBA</option>
                                        <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                    </select>
                                </form>
                            </td>
                            <td>
                                <span class="badge <?= $u['is_active'] ? 'badge-success' : 'badge-danger' ?>">
                                    <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td style="font-size: 0.8rem; color: var(--text-secondary);"><?= sanitize($u['last_login'] ?: 'Never') ?></td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; gap: 0.5rem; justify-content: flex-end;">
                                    <!-- Toggle Status Button -->
                                    <form action="users.php" method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <input type="hidden" name="is_active" value="<?= $u['is_active'] ? 0 : 1 ?>">
                                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                        <button type="submit" class="btn btn-secondary" style="padding: 0.3rem 0.5rem; font-size: 0.8rem;" <?= $u['id'] === (int)$_SESSION['user_id'] ? 'disabled' : '' ?> title="<?= $u['is_active'] ? 'Deactivate Account' : 'Activate Account' ?>">
                                            <i class="fa-solid <?= $u['is_active'] ? 'fa-ban' : 'fa-check-double' ?>"></i>
                                        </button>
                                    </form>
                                    
                                    <!-- Delete Button -->
                                    <form action="users.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this user? This cannot be undone.');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                        <button type="submit" class="btn btn-danger" style="padding: 0.3rem 0.5rem; font-size: 0.8rem;" <?= $u['id'] === (int)$_SESSION['user_id'] ? 'disabled' : '' ?> title="Delete Account">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Add User Form -->
    <div class="glass-card">
        <h3 style="margin-bottom: 1.25rem;">Create New Account</h3>
        <form action="users.php" method="POST">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            
            <div class="form-group">
                <label for="username">Username</label>
                <div class="input-with-icon">
                    <i class="fa-solid fa-user input-icon"></i>
                    <input type="text" id="username" name="username" placeholder="e.g. jdoe" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="email">Email Address</label>
                <div class="input-with-icon">
                    <i class="fa-solid fa-envelope input-icon"></i>
                    <input type="email" id="email" name="email" placeholder="e.g. user@domain.com">
                </div>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-with-icon">
                    <i class="fa-solid fa-lock input-icon"></i>
                    <input type="password" id="password" name="password" placeholder="Min. 8 characters" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="role">User Role</label>
                <div class="input-with-icon">
                    <i class="fa-solid fa-shield input-icon"></i>
                    <select id="role" name="role" required>
                        <option value="viewer">Viewer (Read-only access to metrics)</option>
                        <option value="dba">DBA (Manage servers, resolve recommendations)</option>
                        <option value="admin">Administrator (Full access, user administration)</option>
                    </select>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary btn-block btn-glow" style="margin-top: 1rem;">
                <i class="fa-solid fa-user-plus"></i>
                <span>Add Account</span>
            </button>
        </form>
    </div>
</div>

<?php
require_once dirname(__DIR__) . '/templates/footer.php';
?>

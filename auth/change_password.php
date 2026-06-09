<?php
// auth/change_password.php

$pageTitle = 'Change Password';
require_once dirname(__DIR__) . '/templates/header.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCsrfToken($csrfToken)) {
        $error = 'Invalid security token.';
    } elseif (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $error = 'All fields are required.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New passwords do not match.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'New password must be at least 8 characters long.';
    } else {
        $db = getDbConnection();
        
        // Fetch current password hash
        $stmt = $db->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $currentHash = $stmt->fetchColumn();
        
        if ($currentHash && password_verify($currentPassword, $currentHash)) {
            // Update password
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $update = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $update->execute([$newHash, $_SESSION['user_id']]);
            
            logAuditEvent($_SESSION['user_id'], 'change_password_success', 'user', $_SESSION['user_id'], 'Password changed successfully.');
            $success = 'Your password has been updated successfully.';
        } else {
            $error = 'Current password is incorrect.';
            logAuditEvent($_SESSION['user_id'], 'change_password_failed', 'user', $_SESSION['user_id'], 'Attempted to change password but current password was incorrect.');
        }
    }
}
?>

<div class="dashboard-header-container animate-fade-in">
    <div class="dashboard-title-area">
        <h2>Change Password</h2>
        <p>Update your credentials. Ensure you choose a strong password to safeguard monitoring resources.</p>
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

<div class="glass-card animate-fade-in" style="animation-delay: 0.1s; max-width: 500px; margin-bottom: 2rem;">
    <form action="change_password.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
        
        <div class="form-group">
            <label for="current_password">Current Password</label>
            <div class="input-with-icon">
                <i class="fa-solid fa-lock input-icon"></i>
                <input type="password" id="current_password" name="current_password" placeholder="Enter current password" required>
            </div>
        </div>
        
        <div class="form-group">
            <label for="new_password">New Password</label>
            <div class="input-with-icon">
                <i class="fa-solid fa-key input-icon"></i>
                <input type="password" id="new_password" name="new_password" placeholder="Min. 8 characters" required>
            </div>
        </div>
        
        <div class="form-group">
            <label for="confirm_password">Confirm New Password</label>
            <div class="input-with-icon">
                <i class="fa-solid fa-square-check input-icon"></i>
                <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter new password" required>
            </div>
        </div>
        
        <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1.5rem;">
            <button type="submit" class="btn btn-primary btn-glow">
                <i class="fa-solid fa-floppy-disk"></i>
                <span>Update Password</span>
            </button>
        </div>
    </form>
</div>

<?php
require_once dirname(__DIR__) . '/templates/footer.php';
?>

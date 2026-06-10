<?php
// auth/login.php

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    // Enable secure cookie settings
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: ../dashboard/index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCsrfToken($csrfToken)) {
        $error = 'Invalid security token. Please try again.';
    } elseif (empty($username) || empty($password)) {
        $error = 'Username and password are required.';
    } else {
        $db = getDbConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && $user['is_active'] && password_verify($password, $user['password'])) {
            // Setup session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['last_activity'] = time();
            
            // Regenerate session id for security
            session_regenerate_id(true);
            
            // Update last login timestamp
            $update = $db->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
            $update->execute([$user['id']]);
            
            // Audit log
            logAuditEvent($user['id'], 'login_success', 'user', $user['id'], 'User logged in successfully.');
            
            header("Location: ../dashboard/index.php");
            exit;
        } else {
            $error = 'Invalid username or password.';
            // Audit log for failure (using user_id = null)
            logAuditEvent(null, 'login_failed', 'user', null, "Attempted username: " . sanitize($username));
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - MSSQL Performance Monitor</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- App Styling -->
    <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body class="login-body">
    <div class="login-container">
        <div class="glass-card login-card animate-fade-in">
            <div class="login-header">
                <div class="login-logo-circle">
                    <i class="fa-solid fa-database database-glow-icon"></i>
                </div>
                <h2>MSSQL Performance</h2>
                <p>SQL Server Real-Time Diagnostics & Optimization</p>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger animate-shake">
                    <i class="fa-solid fa-circle-exclamation alert-icon"></i>
                    <span><?= sanitize($error) ?></span>
                </div>
            <?php endif; ?>
            
            <form action="login.php" method="POST" class="login-form">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-with-icon">
                        <i class="fa-solid fa-user input-icon"></i>
                        <input type="text" id="username" name="username" placeholder="Enter username" required autocomplete="username">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-with-icon">
                        <i class="fa-solid fa-lock input-icon"></i>
                        <input type="password" id="password" name="password" placeholder="Enter password" required autocomplete="current-password">
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block btn-glow">
                    <span>Log In</span>
                    <i class="fa-solid fa-arrow-right-to-bracket"></i>
                </button>
            </form>
            
            <div class="login-footer animate-fade-in" style="animation-delay: 0.2s;">
                <p style="margin-bottom: 0.5rem; font-weight: 500;">
                    <i class="fa-solid fa-circle-info" style="color: var(--color-info); margin-right: 0.25rem;"></i>
                    Demo Setup Credentials:
                </p>
                <div style="display: flex; gap: 0.75rem; justify-content: center;">
                    <span>Username: <code>admin</code></span>
                    <span>Password: <code>Sumo@123</code></span>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

<?php
// includes/role_check.php

function requireRole($allowedRoles) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['role'])) {
        header("Location: " . dirname($_SERVER['SCRIPT_NAME']) . "/../auth/login.php");
        exit;
    }
    
    $userRole = $_SESSION['role'];
    
    // Admin has access to everything
    if ($userRole === 'admin') {
        return true;
    }
    
    $allowed = false;
    if (is_array($allowedRoles)) {
        $allowed = in_array($userRole, $allowedRoles);
    } else {
        $allowed = ($userRole === $allowedRoles);
    }
    
    if (!$allowed) {
        http_response_code(403);
        
        // Show an elegant access denied page matching the app's aesthetics
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Access Denied - SQL Server Monitor</title>
            <link rel="stylesheet" href="../assets/css/app.css">
            <style>
                body {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    height: 100vh;
                    margin: 0;
                    background-color: #0b0f19;
                    font-family: 'Inter', sans-serif;
                }
                .error-card {
                    text-align: center;
                    max-width: 500px;
                    padding: 2.5rem;
                }
                .error-icon {
                    font-size: 4rem;
                    color: #d13438;
                    margin-bottom: 1.5rem;
                }
                h1 {
                    font-family: 'Outfit', sans-serif;
                    color: #ffffff;
                    margin-bottom: 1rem;
                }
                p {
                    color: #a0aec0;
                    margin-bottom: 2rem;
                    line-height: 1.6;
                }
            </style>
        </head>
        <body>
            <div class="glass-card error-card">
                <div class="error-icon">⚠️</div>
                <h1>Access Denied</h1>
                <p>You do not have the required permissions to view this resource. This action has been logged for security purposes.</p>
                <a href="../dashboard/index.php" class="btn btn-primary">Go to Dashboard</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
    
    return true;
}

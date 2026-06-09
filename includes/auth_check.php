<?php
// includes/auth_check.php

require_once dirname(__DIR__) . '/config/app.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: " . dirname($_SERVER['SCRIPT_NAME']) . "/../auth/login.php");
    exit;
}

// Inactivity session timeout check
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_LIFETIME_SEC)) {
    // Audit log (attempt best effort)
    require_once __DIR__ . '/helpers.php';
    logAuditEvent($_SESSION['user_id'], 'session_timeout', 'user', $_SESSION['user_id'], 'Session timed out due to inactivity.');
    
    // Clear session
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    
    header("Location: " . dirname($_SERVER['SCRIPT_NAME']) . "/../auth/login.php?timeout=1");
    exit;
}

// Update last activity timestamp
$_SESSION['last_activity'] = time();

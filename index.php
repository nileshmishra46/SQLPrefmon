<?php
// index.php

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

// Redirect based on authentication state
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard/index.php");
} else {
    header("Location: auth/login.php");
}
exit;

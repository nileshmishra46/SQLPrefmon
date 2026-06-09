<?php
// includes/helpers.php

require_once dirname(__DIR__) . '/config/app.php';
require_once __DIR__ . '/db.php';

// AES-256-CBC Encryption for storing SQL Server passwords
function encryptPassword($password) {
    $key = hash('sha256', APP_KEY, true);
    $ivLength = openssl_cipher_iv_length('aes-256-cbc');
    $iv = openssl_random_pseudo_bytes($ivLength);
    $encrypted = openssl_encrypt($password, 'aes-256-cbc', $key, 0, $iv);
    return base64_encode($iv . $encrypted);
}

// AES-256-CBC Decryption for retrieval of SQL Server credentials
function decryptPassword($encryptedBase64) {
    $data = base64_decode($encryptedBase64);
    $key = hash('sha256', APP_KEY, true);
    $ivLength = openssl_cipher_iv_length('aes-256-cbc');
    
    if (strlen($data) <= $ivLength) {
        return false;
    }
    
    $iv = substr($data, 0, $ivLength);
    $encrypted = substr($data, $ivLength);
    return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
}

// CSRF Protection
function getCsrfToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken($token) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Input Sanitization
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// Client IP Retrieval
function getClientIp() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}

// System Audit Log Helper
function logAuditEvent($userId, $action, $targetType = null, $targetId = null, $detail = null) {
    try {
        $db = getDbConnection();
        $ip = getClientIp();
        
        $stmt = $db->prepare("INSERT INTO audit_log (user_id, action, target_type, target_id, detail, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $action, $targetType, $targetId, $detail, $ip]);
    } catch (Exception $e) {
        // Silently fail or log to error log to avoid breaking core flows
        error_log("Failed to write audit log: " . $e->getMessage());
    }
}

// Read application dynamic settings with global constants fallback
function getAppSetting($key, $default) {
    static $settings = null;
    if ($settings === null) {
        $path = dirname(__DIR__) . '/config/settings.json';
        if (file_exists($path)) {
            $settings = json_decode(file_get_contents($path), true);
        } else {
            $settings = [];
        }
    }
    return isset($settings[$key]) ? $settings[$key] : $default;
}
?>

<?php
// includes/helpers.php

// Try to enable required extensions dynamically if they aren't loaded yet
if (!extension_loaded('openssl')) {
    @ini_set('extension', 'openssl');
}
if (!extension_loaded('pdo_sqlite')) {
    @ini_set('extension', 'pdo_sqlite');
}
if (!extension_loaded('pdo_odbc')) {
    @ini_set('extension', 'pdo_odbc');
}

require_once dirname(__DIR__) . '/config/app.php';
require_once __DIR__ . '/db.php';

// AES-256-CBC Encryption for storing SQL Server passwords
function encryptPassword($password) {
    $key = hash('sha256', APP_KEY, true);
    if (extension_loaded('openssl')) {
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        if (function_exists('random_bytes')) {
            $iv = random_bytes($ivLength);
        } else {
            $iv = openssl_random_pseudo_bytes($ivLength);
        }
        $encrypted = openssl_encrypt($password, 'aes-256-cbc', $key, 0, $iv);
        return 'openssl:' . base64_encode($iv . $encrypted);
    } else {
        // Fallback encryption when OpenSSL is not available
        $fallbackEncrypted = '';
        $keyLen = strlen(APP_KEY);
        for ($i = 0; $i < strlen($password); $i++) {
            $fallbackEncrypted .= $password[$i] ^ APP_KEY[$i % $keyLen];
        }
        return 'fallback:' . base64_encode($fallbackEncrypted);
    }
}

// AES-256-CBC Decryption for retrieval of SQL Server credentials
function decryptPassword($encryptedBase64) {
    $key = hash('sha256', APP_KEY, true);
    
    // Check encryption method prefix
    if (strpos($encryptedBase64, 'openssl:') === 0) {
        if (!extension_loaded('openssl')) {
            throw new Exception("The stored password was encrypted using OpenSSL, but the PHP OpenSSL extension is not currently loaded. Please enable extension=openssl in your php.ini.");
        }
        $data = base64_decode(substr($encryptedBase64, 8));
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        if (strlen($data) <= $ivLength) {
            return false;
        }
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);
        return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
    } elseif (strpos($encryptedBase64, 'fallback:') === 0) {
        $passwordData = base64_decode(substr($encryptedBase64, 9));
        if ($passwordData === false) {
            return false;
        }
        $decrypted = '';
        $keyLen = strlen(APP_KEY);
        for ($i = 0; $i < strlen($passwordData); $i++) {
            $decrypted .= $passwordData[$i] ^ APP_KEY[$i % $keyLen];
        }
        return $decrypted;
    } else {
        // Legacy passwords (no prefix) - default to openssl
        if (!extension_loaded('openssl')) {
            throw new Exception("The stored password requires the PHP OpenSSL extension, which is not loaded. Please enable extension=openssl in your php.ini.");
        }
        $data = base64_decode($encryptedBase64);
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        if (strlen($data) <= $ivLength) {
            return false;
        }
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);
        return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
    }
}

// CSRF Protection
function getCsrfToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        if (function_exists('random_bytes')) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
        } else {
            $_SESSION['csrf_token'] = bin2hex(md5(uniqid(rand(), true)));
        }
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

/**
 * Extracts parameter names and compiled values from SQL Server query plan XML.
 * 
 * @param string $xmlContent
 * @return string|null JSON string of parameters or null
 */
function extractParametersFromPlan($xmlContent) {
    if (empty($xmlContent)) {
        return null;
    }
    
    $params = [];
    try {
        $xml = @simplexml_load_string($xmlContent);
        if ($xml === false) {
            return extractParametersWithRegex($xmlContent);
        }
        
        $xml->registerXPathNamespace('sp', 'http://schemas.microsoft.com/sqlserver/2004/07/showplan');
        $nodes = $xml->xpath('//sp:ParameterList/sp:ColumnReference');
        if ($nodes) {
            foreach ($nodes as $node) {
                $name = (string)$node['Column'];
                $val = (string)$node['ParameterCompiledValue'];
                $params[$name] = $val;
            }
        }
    } catch (Exception $e) {
        return extractParametersWithRegex($xmlContent);
    }
    
    return !empty($params) ? json_encode($params) : null;
}

/**
 * Fallback parameter extraction using regular expressions if SimpleXML parsing fails.
 * 
 * @param string $xmlContent
 * @return string|null JSON string of parameters or null
 */
function extractParametersWithRegex($xmlContent) {
    if (empty($xmlContent)) {
        return null;
    }
    
    $params = [];
    preg_match_all('/Column="([^"]+)"[^>]*ParameterCompiledValue="([^"]*)"/', $xmlContent, $matches, PREG_SET_ORDER);
    if (!empty($matches)) {
        foreach ($matches as $match) {
            $params[$match[1]] = $match[2];
        }
    }
    return !empty($params) ? json_encode($params) : null;
}
?>

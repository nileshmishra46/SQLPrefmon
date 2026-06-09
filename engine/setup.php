<?php
// engine/setup.php

// Enable extensions if run from Windows Winget package CLI
if (php_sapi_name() === 'cli' && !extension_loaded('pdo_sqlite')) {
    ini_set('extension', 'pdo_sqlite');
}
if (php_sapi_name() === 'cli' && !extension_loaded('openssl')) {
    ini_set('extension', 'openssl');
}

require_once dirname(__DIR__) . '/includes/db.php';

try {
    echo "===========================================" . PHP_EOL;
    echo "   SQL Server Performance Monitor Setup    " . PHP_EOL;
    echo "===========================================" . PHP_EOL;
    echo "Initializing SQLite Database tables..." . PHP_EOL;
    
    // Establishing connection automatically runs migrations in includes/db.php
    $db = getDbConnection();
    
    echo "SQLite database initialized successfully." . PHP_EOL;
    echo "Location: " . APP_DB_PATH . PHP_EOL;
    echo "-------------------------------------------" . PHP_EOL;
    echo "Default Admin Seeding Checked:" . PHP_EOL;
    
    $stmt = $db->query("SELECT id, username, role FROM users WHERE username = 'admin'");
    $admin = $stmt->fetch();
    if ($admin) {
        echo "Default Administrator active:" . PHP_EOL;
        echo "  Username: admin" . PHP_EOL;
        echo "  Password: Sumo@123" . PHP_EOL;
        echo "  Role: " . $admin['role'] . PHP_EOL;
    } else {
        echo "WARNING: Default administrator user was not found." . PHP_EOL;
    }
    echo "===========================================" . PHP_EOL;
} catch (Exception $e) {
    echo "FATAL ERROR during setup: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

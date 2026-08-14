<?php
// config/app.php

// Application Database Path
define('APP_DB_PATH', dirname(__DIR__) . '/data/sqlperf.db');

// Application Log Path
define('APP_LOG_PATH', dirname(__DIR__) . '/logs/collector.log');

// Secret Key for AES-256 Encryption of SQL Server Passwords
define('APP_KEY', 'x8v!D9z$L2pQ&m5wK#sF2hG@7tJ%uW9Y');

// Security & Sessions
define('SESSION_LIFETIME_SEC', 1800); // 30 minutes

// Global Default Monitoring Alert Thresholds
define('THRESHOLD_CPU_PCT', 85.0);
define('THRESHOLD_PLE_SEC', 300);
define('THRESHOLD_DISK_LATENCY_MS', 20.0);
define('THRESHOLD_RECOMPILE_SEC', 100);
define('THRESHOLD_INDEX_FRAG_PCT', 30.0);
define('THRESHOLD_BLOCKED_PROCESSES', 5);
define('THRESHOLD_SIGNAL_WAIT_PCT', 25.0);
define('THRESHOLD_BLOCKING_THRESHOLD_MIN', 2);

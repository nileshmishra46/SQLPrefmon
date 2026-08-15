<?php
// engine/connector.php

/**
 * Connect to Microsoft SQL Server via PDO ODBC
 * 
 * @param string $hostname
 * @param int $port
 * @param string|null $instanceName
 * @param string $username
 * @param string $password
 * @return PDO
 * @throws Exception
 */
function getSqlServerConnection($hostname, $port = 1433, $instanceName = null, $username = '', $password = '', $trustServerCert = false) {
    $serverStr = $hostname;
    
    // Handle instance name vs port formatting in SQL Server
    if ($instanceName) {
        $serverStr .= '\\' . $instanceName;
    } else if ($port && $port != 1433) {
        // SQL Server network ports are typically appended with a comma
        $serverStr .= ',' . $port;
    }
    
    // Array of ODBC drivers to try sequentially
    $drivers = [
        'ODBC Driver 18 for SQL Server',
        'ODBC Driver 17 for SQL Server',
        'SQL Server'
    ];
    
    $lastException = null;
    
    foreach ($drivers as $driver) {
        try {
            $dsn = "odbc:Driver={" . $driver . "};Server=" . $serverStr . ";Database=master;MARS_Connection=yes;";
            
            if ($trustServerCert) {
                $dsn .= "TrustServerCertificate=yes;";
            } else if ($driver === 'ODBC Driver 18 for SQL Server') {
                // ODBC Driver 18 requires explicit trust settings for self-signed certificates
                $dsn .= "TrustServerCertificate=no;";
            }
            
            // Set short connection timeout (5 seconds) to avoid hanging PHP process
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5
            ];
            
            return new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            $lastException = $e;
        }
    }
    
    throw new Exception("ODBC Driver Connection Failed. Host attempted: $serverStr. Details: " . $lastException->getMessage());
}

/**
 * Validate connection credentials and return server version
 * 
 * @return string
 * @throws Exception
 */
function testSqlServerConnection($hostname, $port = 1433, $instanceName = null, $username = '', $password = '', $trustServerCert = false) {
    $conn = getSqlServerConnection($hostname, $port, $instanceName, $username, $password, $trustServerCert);
    
    $stmt = $conn->query("SELECT @@VERSION");
    $version = $stmt->fetchColumn();
    
    // Close connection
    $conn = null;
    
    return $version;
}

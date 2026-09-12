<?php
// ============================================================
//  config/db.php  —  Database & Network Configuration
// ============================================================

// ── DATABASE CONNECTION ────────────────────────────────────────
$dbHost   = 'localhost';
$dbName   = 'wi_attend';
$dbUser   = 'root';
$dbPass   = ''; // XAMPP default is empty

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// ============================================================
// NETWORK CONFIGURATION - FULLY DYNAMIC (AUTO-DETECT)
// ============================================================
// 
// The system now AUTOMATICALLY DETECTS your WiFi subnet!
// 
// HOW IT WORKS:
//   1. When you run the scanner, it checks your current network
//   2. Automatically detects the subnet (192.168.18, 192.168.1, 10.0.0, etc.)
//   3. Scans ONLY devices on that subnet
//   4. When you switch WiFi → it auto-adjusts to the new subnet
//
// OPTIONAL: If auto-detection fails, set a hardcoded subnet:
// 
// define('NETWORK_SUBNET', '192.168.18');  // Home WiFi
// define('NETWORK_SUBNET', '192.168.100'); // Office WiFi
// define('NETWORK_SUBNET', '10.0.0');      // College WiFi
//
// Otherwise, leave this commented out for full auto-detection!
//

// define('NETWORK_SUBNET', '192.168.18');  // UNCOMMENT ONLY if auto-detect fails
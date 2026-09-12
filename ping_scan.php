<?php
// ============================================================
//  ping_scan.php  —  STANDALONE PING + MAC SCANNER
//  Run this anytime to ping network and see all MACs
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';
requireLogin();

$startTime = microtime(true);

// ── AUTO-DETECT SUBNET ──────────────────────────────────────
$subnet = getCurrentNetworkSubnet();

if (!$subnet) {
    die("<h1>❌ Could not detect subnet</h1>");
}

echo "<h1>🔍 Network Ping & Scan</h1>";
echo "<p>Subnet: <strong>$subnet</strong></p>";
echo "<p>Pinging all 254 devices...</p>";
echo "<hr>";

// ── PING ALL DEVICES ────────────────────────────────────────
shell_exec('arp -d * 2>NUL');
usleep(300000);

echo "⏳ Pinging devices...<br>";

for ($i = 1; $i <= 254; $i++) {
    $ip = "{$subnet}.{$i}";
    pclose(popen("start /B ping -n 1 -w 100 {$ip} >NUL 2>&1", 'r'));
    
    if ($i % 50 === 0) {
        echo "Pinged up to {$ip}<br>";
        flush();
    }
}

echo "⏳ Waiting for responses...<br>";
usleep(2000000); // 2 seconds for all responses

// ── READ ARP TABLE ─────────────────────────────────────────
echo "<hr>";
echo "<h2>📊 Found Devices</h2>";

$isWindows = stripos(PHP_OS, 'WIN') === 0;
if ($isWindows) {
    $output = shell_exec('arp -a 2>NUL');
} else {
    $output = shell_exec('ip neigh show 2>/dev/null') ?: shell_exec('arp -an 2>/dev/null');
}

$detectedMACs = [];
if ($output) {
    preg_match_all('/([0-9a-fA-F]{2}[:-]){5}[0-9a-fA-F]{2}/', $output, $matches);
    foreach ($matches[0] as $mac) {
        $mac = strtolower(str_replace('-', ':', $mac));
        $clean = str_replace(':', '', $mac);
        $firstByte = hexdec(substr($clean, 0, 2));
        if (($firstByte & 0x01) === 0) {
            $detectedMACs[] = $mac;
        }
    }
}

$detectedMACs = array_unique($detectedMACs);
sort($detectedMACs);

$loadTime = round((microtime(true) - $startTime) * 1000, 0);
echo "✅ Found <strong>" . count($detectedMACs) . "</strong> device(s) in {$loadTime}ms<br><br>";

// ── ORGANIZE & DISPLAY ──────────────────────────────────────
$registered = [];
$unknown = [];
$randomized = [];

foreach ($detectedMACs as $mac) {
    $stmt = $pdo->prepare("
        SELECT s.full_name, m.device_label 
        FROM mac_addresses m
        JOIN staff s ON s.staff_id = m.staff_id
        WHERE m.mac_address = ? AND m.is_active = 1 AND s.is_active = 1
    ");
    $stmt->execute([$mac]);
    $device = $stmt->fetch();
    
    if ($device) {
        $registered[] = [
            'mac' => $mac,
            'staff' => $device['full_name'],
            'label' => $device['device_label']
        ];
    } else {
        // Check if randomized
        $clean = str_replace(':', '', $mac);
        $firstByte = hexdec(substr($clean, 0, 2));
        $isRandomized = ($firstByte & 0x02) === 0x02;
        
        if ($isRandomized) {
            $randomized[] = $mac;
        } else {
            $unknown[] = $mac;
        }
    }
}

// ── REGISTERED DEVICES ──────────────────────────────────────
echo "<h3>✅ Registered Devices (" . count($registered) . ")</h3>";
echo "<table border='1' style='border-collapse:collapse;width:100%;margin-bottom:20px'>";
echo "<tr style='background:#10b981;color:white'><th style='padding:10px'>MAC Address</th><th>Staff Name</th><th>Device Label</th></tr>";
foreach ($registered as $d) {
    echo "<tr><td style='padding:10px'><code>{$d['mac']}</code></td><td>{$d['staff']}</td><td>{$d['label']}</td></tr>";
}
echo "</table>";

// ── RANDOMIZED MACS ─────────────────────────────────────────
if (!empty($randomized)) {
    echo "<h3>⚠️ Randomized MACs (" . count($randomized) . ")</h3>";
    echo "<p style='color:orange'>These devices have MAC randomization enabled (likely phones)</p>";
    echo "<table border='1' style='border-collapse:collapse;width:100%;margin-bottom:20px'>";
    echo "<tr style='background:#f59e0b;color:white'><th style='padding:10px'>MAC Address</th><th>Notes</th></tr>";
    foreach ($randomized as $mac) {
        echo "<tr><td style='padding:10px'><code>{$mac}</code></td><td>Turn off MAC randomization and re-register</td></tr>";
    }
    echo "</table>";
}

// ── UNKNOWN MACS ────────────────────────────────────────────
if (!empty($unknown)) {
    echo "<h3>❓ Unknown Devices (" . count($unknown) . ")</h3>";
    echo "<table border='1' style='border-collapse:collapse;width:100%;margin-bottom:20px'>";
    echo "<tr style='background:#666;color:white'><th style='padding:10px'>MAC Address</th><th>Action</th></tr>";
    foreach ($unknown as $mac) {
        echo "<tr><td style='padding:10px'><code>{$mac}</code></td><td><a href='mac_register.php'>Register This Device</a></td></tr>";
    }
    echo "</table>";
}

// ── SUMMARY ─────────────────────────────────────────────────
echo "<hr>";
echo "<h3>📋 Summary</h3>";
echo "✅ Registered: " . count($registered) . "<br>";
echo "⚠️ Randomized: " . count($randomized) . "<br>";
echo "❓ Unknown: " . count($unknown) . "<br>";
echo "<strong>Total: " . count($detectedMACs) . " devices</strong><br><br>";

echo "<a href='ping_scan.php' style='padding:10px 20px;background:#10b981;color:white;border-radius:5px;text-decoration:none;display:inline-block'>🔄 Scan Again</a> ";
echo "<a href='scanner.php' style='padding:10px 20px;background:#38bdf8;color:white;border-radius:5px;text-decoration:none;display:inline-block'>→ Full Scanner</a>";
?>

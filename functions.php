<?php
// ============================================================
//  includes/functions.php  —  Shared helper functions
// ============================================================

/**
 * Block access to a page unless an admin is logged in.
 */
function requireLogin(): void
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Safely escape a value for HTML output.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Normalise a MAC address to lowercase, colon-separated form:
 * "AA-BB-CC-DD-EE-FF" -> "aa:bb:cc:dd:ee:ff"
 */
function normaliseMAC(string $mac): string
{
    $mac = strtolower(trim($mac));
    $mac = str_replace('-', ':', $mac);
    $mac = preg_replace('/[^0-9a-f:]/', '', $mac);
    return $mac;
}

/**
 * Validate that a string is a properly formatted MAC address.
 */
function isValidMAC(string $mac): bool
{
    return (bool) preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac);
}

/**
 * Is this a multicast/broadcast MAC address rather than a real device?
 */
function isMulticastMAC(string $mac): bool
{
    $clean = strtolower(str_replace([':', '-'], '', $mac));
    if (strlen($clean) < 2) return false;
    $firstByte = hexdec(substr($clean, 0, 2));
    return ($firstByte & 0x01) === 0x01;
}

/**
 * Turn a number of minutes into a readable "Xh Ym" string.
 */
function formatDuration(?int $minutes): string
{
    if ($minutes === null) {
        return '—';
    }
    $minutes = max(0, $minutes);
    $hours   = intdiv($minutes, 60);
    $mins    = $minutes % 60;

    if ($hours > 0 && $mins > 0) return "{$hours}h {$mins}m";
    if ($hours > 0)              return "{$hours}h";
    return "{$mins}m";
}

/**
 * AUTO-DETECT current network subnet
 * Works on HOME, OFFICE, RESTAURANT, or ANY WiFi
 * 
 * Returns subnet like "192.168.1" or "192.168.18" or "10.0.0"
 */
function getCurrentNetworkSubnet(): ?string
{
    $isWindows = stripos(PHP_OS, 'WIN') === 0;
    
    if ($isWindows) {
        return detectWindowsSubnet();
    } else {
        return detectUnixSubnet();
    }
}

/**
 * WINDOWS: Get current WiFi subnet from ipconfig
 */
function detectWindowsSubnet(): ?string
{
    $ipconfig = shell_exec('ipconfig /all 2>NUL');
    if (!$ipconfig) return null;
    
    // Look for WiFi adapter and its IPv4 address
    if (preg_match('/Wireless LAN adapter.*?IPv4 Address.*?(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/is', $ipconfig, $m)) {
        return extractSubnet($m[1]);
    }
    
    // Fallback: Look for Qualcomm WiFi chipset
    if (preg_match('/Qualcomm.*?IPv4 Address.*?(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/is', $ipconfig, $m)) {
        return extractSubnet($m[1]);
    }
    
    // Fallback: Any WiFi or 802.11
    if (preg_match('/802\.11.*?IPv4 Address.*?(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/is', $ipconfig, $m)) {
        return extractSubnet($m[1]);
    }
    
    // Last resort: Just find any IPv4 that's not 127.x.x.x
    if (preg_match_all('/IPv4 Address.*?(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/s', $ipconfig, $matches)) {
        foreach ($matches[1] as $ip) {
            if (strpos($ip, '127.') !== 0 && strpos($ip, '169.') !== 0) {
                return extractSubnet($ip);
            }
        }
    }
    
    return null;
}

/**
 * UNIX/LINUX/MAC: Get current subnet
 */
function detectUnixSubnet(): ?string
{
    // Try to get WiFi adapter IP
    $output = shell_exec('ip addr show 2>/dev/null');
    if ($output && preg_match_all('/inet\s+(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/', $output, $matches)) {
        foreach ($matches[1] as $ip) {
            if (strpos($ip, '127.') !== 0 && strpos($ip, '169.') !== 0) {
                return extractSubnet($ip);
            }
        }
    }
    
    // Fallback
    $localIp = $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname());
    if ($localIp && filter_var($localIp, FILTER_VALIDATE_IP)) {
        return extractSubnet($localIp);
    }
    
    return null;
}

/**
 * Extract subnet from IP
 * "192.168.18.45" → "192.168.18"
 */
function extractSubnet(string $ip): ?string
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return null;
    $parts = explode('.', $ip);
    if (count($parts) !== 4) return null;
    return "{$parts[0]}.{$parts[1]}.{$parts[2]}";
}

/**
 * Scan the local network and return every MAC address currently
 * visible in the system's ARP table.
 */
function getConnectedMACs(): array
{
    $isWindows = stripos(PHP_OS, 'WIN') === 0;

    pingSweepSubnet($isWindows);

    if ($isWindows) {
        $output = shell_exec('arp -a 2>NUL');
    } else {
        $output = shell_exec('ip neigh show 2>/dev/null') ?: shell_exec('arp -an 2>/dev/null');
    }

    if (!$output) {
        return [];
    }

    preg_match_all('/([0-9a-fA-F]{2}[:-]){5}[0-9a-fA-F]{2}/', $output, $matches);

    $macs = array_map('normaliseMAC', $matches[0] ?? []);
    $macs = array_unique($macs);
    $macs = array_filter($macs, fn($m) => !isMulticastMAC($m));

    return array_values($macs);
}

/**
 * Ping every device on current subnet to populate ARP cache
 */
function pingSweepSubnet(bool $isWindows): void
{
    // IMPORTANT: Auto-detect subnet EVERY time (not hardcoded)
    $subnet = getCurrentNetworkSubnet();
    
    if (!$subnet) {
        return; // Can't detect, skip ping
    }

    // Fast single-pass ping with batch processing
    $batchSize = 50;
    $timeout = 200; // 200ms per ping
    
    for ($i = 1; $i <= 254; $i += $batchSize) {
        $end = min($i + $batchSize - 1, 254);
        
        for ($j = $i; $j <= $end; $j++) {
            $ip = "{$subnet}.{$j}";
            if ($isWindows) {
                pclose(popen("start /B ping -n 1 -w {$timeout} {$ip} >NUL 2>&1", 'r'));
            } else {
                shell_exec("ping -c 1 -W 0.2 {$ip} >/dev/null 2>&1 &");
            }
        }
        usleep(500000); // 0.5s between batches
    }

    usleep(200000); // Final settle time
}

/**
 * Compare check-in time against expected start time
 */
function checkInStatus(?string $actualCheckIn, ?string $expectedIn, int $graceMinutes = 0): array
{
    if (!$expectedIn) {
        return ['label' => 'No schedule set', 'badge' => 'badge-blue'];
    }
    if (!$actualCheckIn) {
        return ['label' => 'Not checked in yet', 'badge' => 'badge-orange'];
    }

    $actualTime   = strtotime($actualCheckIn);
    $expectedTime = strtotime(date('Y-m-d', $actualTime) . ' ' . $expectedIn) + ($graceMinutes * 60);
    $diffMinutes  = (int) round(($actualTime - $expectedTime) / 60);

    if ($diffMinutes <= 0) {
        return ['label' => 'On Time', 'badge' => 'badge-green'];
    }
    return ['label' => "Late by {$diffMinutes}m", 'badge' => 'badge-red'];
}

/**
 * Compare check-out time against expected end time
 */
function checkOutStatus(?string $actualCheckOut, ?string $expectedOut): array
{
    if (!$expectedOut) {
        return ['label' => 'No schedule set', 'badge' => 'badge-blue'];
    }
    if (!$actualCheckOut) {
        return ['label' => 'Still in office', 'badge' => 'badge-green'];
    }

    $actualTime   = strtotime($actualCheckOut);
    $expectedTime = strtotime(date('Y-m-d', $actualTime) . ' ' . $expectedOut);
    $diffMinutes  = (int) round(($expectedTime - $actualTime) / 60);

    if ($diffMinutes > 0) {
        return ['label' => "Left Early by {$diffMinutes}m", 'badge' => 'badge-orange'];
    }
    return ['label' => 'On Time', 'badge' => 'badge-green'];
}
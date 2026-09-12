<?php
// ============================================================
//  scanner.php  —  THE CORE ENGINE  (v3 — stale-entry aware)
//
//  Scans the local network neighbour table, matches detected MACs
//  against registered staff devices, then records CHECK-IN / CHECK-OUT.
//
//  v3 improvements (over v2):
//    • Reads the NEIGHBOUR table (netsh / ip neigh) instead of the
//      raw ARP table, so it can see each entry's STATE. Stale and
//      unreachable entries are discarded. This is what stops a
//      device appearing twice after it changes MAC address (e.g.
//      turning off iOS/Android Private Wi-Fi Address), because the
//      old randomized MAC lingers in the cache as a dead entry.
//    • Last-seen grace window (default 180s). Neighbour entries
//      only stay "Reachable" for ~30s, and the ping sweep is
//      throttled to 8s and non-blocking, so a device that IS
//      present will sometimes momentarily drop out. The grace
//      window prevents that from flapping check-outs.
//    • Grace cache is keyed per subnet, so switching networks
//      starts a clean window rather than carrying ghosts across.
//
//  v2 features retained:
//    • Fast async ping sweep (non-blocking)
//    • Auto-detects subnet from the server's own IP
//    • Auto-detects the active Wi-Fi zone by gateway match, and
//      clears stale presence when the network changes
//
//  Run options:
//    1. Manually: http://localhost/wi-attend/scanner.php
//    2. Windows Task Scheduler: php C:\xampp\htdocs\wi-attend\scanner.php
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';
requireLogin();

// How long a device may go unseen before it counts as gone.
// Raise it if you see flapping check-outs, lower it for a faster
// check-out response. 180s is a sensible default for a 20s refresh.
define('SEEN_GRACE_SECONDS', 180);

// ── Detect this machine's active IPv4 + subnet + gateway ─────────
// Works on whatever network you're plugged into right now (home,
// office, college) — no hardcoded 192.168.1 needed.
//
// Cached for 10s: running ipconfig spawns a process (~100-300ms).
// Caching it stops every page load paying that cost, while still
// re-detecting quickly after you switch Wi-Fi.
function detectNetwork(): array {
    $cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wiattend_net.json';
    if (is_file($cache) && (time() - filemtime($cache)) < 10) {
        $c = json_decode(@file_get_contents($cache), true);
        if (is_array($c) && !empty($c['ip'])) return $c;
    }
    $result = detectNetworkRaw();
    @file_put_contents($cache, json_encode($result));
    return $result;
}

function detectNetworkRaw(): array {
    $ip = null; $subnet = null; $gateway = null;

    if (PHP_OS_FAMILY === 'Windows') {
        $out = shell_exec('ipconfig');
        // IMPORTANT: a machine can have several IPv4s — e.g. a
        // VirtualBox Host-Only adapter (192.168.56.1, NO gateway)
        // alongside the real Wi-Fi adapter (has a gateway). We must
        // pick the adapter that ACTUALLY reaches the network, i.e.
        // the one whose block contains a real Default Gateway.
        //
        // Strategy: walk the output block-by-block. Adapters are
        // separated by blank lines. Within each block, capture the
        // IPv4 and the Default Gateway. Choose the first block that
        // has BOTH a valid IPv4 and a valid IPv4 gateway.
        $blocks = preg_split('/\r?\n\r?\n/', $out);
        $fallbackIp = null; // any non-loopback IPv4, used only if nothing has a gateway
        foreach ($blocks as $block) {
            $blockIp = null; $blockGw = null;
            if (preg_match('/IPv4 Address[.\s]*:\s*([\d.]+)/i', $block, $m)) {
                $cand = $m[1];
                if (strpos($cand, '127.') !== 0 && strpos($cand, '169.254.') !== 0) {
                    $blockIp = $cand;
                    if ($fallbackIp === null) $fallbackIp = $cand;
                }
            }
            // Gateway line may have an IPv6 on its own line then the IPv4
            // on the next; grab any IPv4 that appears after "Default Gateway".
            if (preg_match('/Default Gateway[.\s]*:\s*(.*?)(?:\r?\n\r?\n|$)/is', $block, $gm)) {
                if (preg_match('/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/', $gm[1], $g2)) {
                    if ($g2[1] !== '0.0.0.0') $blockGw = $g2[1];
                }
            }
            if ($blockIp && $blockGw) {
                $ip = $blockIp; $gateway = $blockGw;
                break; // this is the real, internet-facing adapter
            }
        }
        if (!$ip) $ip = $fallbackIp; // no gateway found anywhere — best effort
    } else {
        // Linux/mac: the default route already tells us the right adapter.
        $gateway = trim(shell_exec("ip route 2>/dev/null | awk '/default/ {print \$3; exit}'"));
        $iface   = trim(shell_exec("ip route 2>/dev/null | awk '/default/ {print \$5; exit}'"));
        if ($iface) {
            $ip = trim(shell_exec("ip -4 addr show " . escapeshellarg($iface) . " 2>/dev/null | awk '/inet /{print \$2}' | cut -d/ -f1 | head -n1"));
        }
        if (!$ip) {
            $ip = trim(shell_exec("hostname -I 2>/dev/null | awk '{print \$1}'"));
        }
    }

    // Fallback if detection failed entirely
    if (!$ip) {
        $ip = defined('NETWORK_SUBNET') ? NETWORK_SUBNET . '.1' : '192.168.1.1';
    }
    // /24 subnet prefix (first three octets)
    $parts = explode('.', $ip);
    $subnet = "{$parts[0]}.{$parts[1]}.{$parts[2]}";

    return ['ip' => $ip, 'subnet' => $subnet, 'gateway' => $gateway];
}

// ── BACKGROUND ping sweep ────────────────────────────────────────
// Fires all pings into the background and returns IMMEDIATELY —
// it does NOT wait for replies. This is the key to a fast page:
// the sweep warms the neighbour table for the *next* scan while this
// page renders instantly from whatever is already cached.
//
// It also throttles: a fresh sweep runs at most once every 8s
// (tracked via a temp stamp file), so rapid page loads / auto-
// refreshes don't spawn hundreds of ping processes.
function pingSubnet(string $subnet): void {
    $stamp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wiattend_lastping.txt';
    $now   = time();
    if (is_file($stamp)) {
        $last = (int) @file_get_contents($stamp);
        if (($now - $last) < 8) {
            return; // pinged < 8s ago — neighbour table still warm, skip the sweep
        }
    }
    @file_put_contents($stamp, (string) $now);

    if (PHP_OS_FAMILY === 'Windows') {
        // One batch launches all pings at once; batch itself launched
        // detached. popen returns instantly — NO usleep, NO blocking.
        $bat = sys_get_temp_dir() . '\\wiattend_ping.bat';
        $lines = "@echo off\r\n";
        for ($i = 1; $i <= 150; $i++) {
            $lines .= "start /b /min ping -n 1 -w 200 {$subnet}.{$i} >nul 2>&1\r\n";
        }
        @file_put_contents($bat, $lines);
        @pclose(@popen("start /b cmd /c \"{$bat}\"", "r"));
    } else {
        for ($i = 1; $i <= 150; $i++) {
            exec("ping -c 1 -W 1 {$subnet}.{$i} > /dev/null 2>&1 &");
        }
    }
    // Intentionally NO usleep — the page must not block on ping replies.
}

// ── NEIGHBOUR TABLE READER (replaces plain ARP read) ─────────────
// The ARP table (arp -a) reports every cached entry with no way to
// tell a live device from a dead one. The neighbour table exposes a
// STATE per entry, which is what lets us throw away ghosts.
//
// States we accept:
//   Reachable — confirmed within roughly the last 30 seconds
//   Delay / Probe — currently being re-verified, very likely present
// States we reject:
//   Stale — not confirmed recently; this is where a device's OLD MAC
//           sits after it switches to/from a randomized address
//   Unreachable / Incomplete — no reply to ARP probes
//   Permanent — static entries, i.e. multicast and broadcast rows
//
// Returns an array of uppercase colon-separated MACs, or an empty
// array if the command output could not be parsed (e.g. a localised
// Windows install, where the state words are translated). The caller
// falls back to getConnectedMACs() in that case.
function getLiveNeighbours(): array {
    $macs = [];

    if (PHP_OS_FAMILY === 'Windows') {
        $out = shell_exec('netsh interface ipv4 show neighbors') ?: '';
        foreach (preg_split('/\r?\n/', $out) as $line) {
            if (!preg_match(
                '/^\s*(\d{1,3}(?:\.\d{1,3}){3})\s+([0-9a-f]{2}(?:-[0-9a-f]{2}){5})\s+(.+?)\s*$/i',
                $line, $m
            )) continue;

            [, $ip, $mac, $stateRaw] = $m;
            // "Reachable (Router)" — take the leading word only
            $state = strtolower(strtok(trim($stateRaw), ' ('));

            if (!in_array($state, ['reachable', 'delay', 'probe'], true)) continue;
            if (!isRealHostAddress($ip, $mac)) continue;

            $macs[] = strtoupper(str_replace('-', ':', $mac));
        }
    } elseif (PHP_OS_FAMILY === 'Darwin') {
        // macOS has no `ip neigh`. arp -an marks dead entries as
        // (incomplete); everything else is treated as live.
        $out = shell_exec('arp -an') ?: '';
        foreach (preg_split('/\r?\n/', $out) as $line) {
            if (stripos($line, 'incomplete') !== false) continue;
            if (!preg_match('/\((\d{1,3}(?:\.\d{1,3}){3})\)\s+at\s+([0-9a-f:]{11,17})/i', $line, $m)) continue;

            $ip  = $m[1];
            $mac = normaliseMacOctets($m[2]);
            if (!$mac || !isRealHostAddress($ip, $mac)) continue;

            $macs[] = $mac;
        }
    } else {
        $out = shell_exec('ip neigh show') ?: '';
        foreach (preg_split('/\r?\n/', $out) as $line) {
            if (!preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}).*?lladdr\s+([0-9a-f:]{17})\s+(.+)$/i', $line, $m)) continue;

            $state = strtoupper(trim($m[3]));
            if (!preg_match('/REACHABLE|DELAY|PROBE/', $state)) continue;

            $ip  = $m[1];
            $mac = strtoupper($m[2]);
            if (!isRealHostAddress($ip, $mac)) continue;

            $macs[] = $mac;
        }
    }

    return array_values(array_unique($macs));
}

// Filters out multicast / broadcast rows that are not real devices.
function isRealHostAddress(string $ip, string $mac): bool {
    if (preg_match('/^(0\.|127\.|22[4-9]\.|23\d\.|255\.)/', $ip)) return false;
    if (substr($ip, -4) === '.255') return false;

    $clean = strtolower(preg_replace('/[^0-9a-f]/i', '', $mac));
    if ($clean === 'ffffffffffff') return false;      // broadcast
    if (substr($clean, 0, 6) === '01005e') return false; // IPv4 multicast
    if (substr($clean, 0, 4) === '3333') return false;   // IPv6 multicast

    return true;
}

// macOS arp prints single-digit octets unpadded (a:b:c:1:2:3).
function normaliseMacOctets(string $mac): ?string {
    $parts = explode(':', $mac);
    if (count($parts) !== 6) return null;
    foreach ($parts as &$p) {
        if ($p === '' || strlen($p) > 2) return null;
        $p = str_pad(strtoupper($p), 2, '0', STR_PAD_LEFT);
    }
    return implode(':', $parts);
}

// ── THIS MACHINE'S OWN MAC ───────────────────────────────────────
// A host never appears in its own neighbour table: it does not ARP
// for an address it already owns. So the laptop running the server
// is invisible to the scan unless we add it ourselves.
//
// We look up the MAC of whichever adapter holds $ip — the adapter
// detectNetwork() already decided is the real, gateway-facing one.
// Cached for 60s; the MAC of a given adapter does not change often.
function getLocalMac(string $ip): ?string {
    $cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wiattend_selfmac.json';
    if (is_file($cache) && (time() - filemtime($cache)) < 60) {
        $c = json_decode(@file_get_contents($cache), true);
        if (is_array($c) && ($c['ip'] ?? null) === $ip && !empty($c['mac'])) {
            return $c['mac'];
        }
    }

    $mac = getLocalMacRaw($ip);
    @file_put_contents($cache, json_encode(['ip' => $ip, 'mac' => $mac]));
    return $mac;
}

function getLocalMacRaw(string $ip): ?string {
    if (PHP_OS_FAMILY === 'Windows') {
        // Note: plain `ipconfig` does NOT print MAC addresses.
        // `ipconfig /all` does, under "Physical Address".
        $out = shell_exec('ipconfig /all') ?: '';
        foreach (preg_split('/\r?\n\r?\n/', $out) as $block) {
            // The IPv4 line may be suffixed, e.g. "192.168.1.5(Preferred)"
            if (!preg_match('/IPv4 Address[.\s]*:\s*' . preg_quote($ip, '/') . '\b/i', $block)) continue;
            if (preg_match('/Physical Address[.\s]*:\s*([0-9A-F]{2}(?:-[0-9A-F]{2}){5})/i', $block, $m)) {
                return strtoupper(str_replace('-', ':', $m[1]));
            }
        }
    } elseif (PHP_OS_FAMILY === 'Darwin') {
        $out = shell_exec('ifconfig') ?: '';
        foreach (preg_split('/\n(?=\S)/', $out) as $block) {
            if (!preg_match('/\binet\s+' . preg_quote($ip, '/') . '\b/', $block)) continue;
            if (preg_match('/\bether\s+([0-9a-f:]{11,17})/i', $block, $m)) {
                return normaliseMacOctets($m[1]);
            }
        }
    } else {
        // Find the interface holding this IP, then read its MAC.
        $out = shell_exec('ip -o -4 addr show 2>/dev/null') ?: '';
        foreach (preg_split('/\r?\n/', $out) as $line) {
            if (!preg_match('/^\d+:\s*(\S+)\s+inet\s+' . preg_quote($ip, '/') . '\//', $line, $m)) continue;
            $iface = $m[1];
            $mac = @file_get_contents("/sys/class/net/{$iface}/address");
            if ($mac && preg_match('/^[0-9a-f:]{17}$/i', trim($mac))) {
                return strtoupper(trim($mac));
            }
        }
    }

    return null;
}

// ── LAST-SEEN GRACE WINDOW ───────────────────────────────────────
// Merges the MACs seen right now with those seen within the grace
// window, so a device that briefly falls out of "Reachable" between
// ping sweeps is not checked out and then straight back in.
//
// The cache is keyed by subnet: moving from college to home starts a
// fresh window instead of dragging the other site's devices along.
function applySeenGrace(array $liveMacs, string $subnet, int $graceSeconds): array {
    $key  = preg_replace('/[^0-9]/', '_', $subnet);
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "wiattend_seen_{$key}.json";

    $seen = is_file($file) ? (json_decode(@file_get_contents($file), true) ?: []) : [];
    if (!is_array($seen)) $seen = [];
    $now = time();

    foreach ($liveMacs as $mac) {
        $seen[$mac] = $now;
    }
    foreach ($seen as $mac => $ts) {
        if (!is_int($ts) || ($now - $ts) > $graceSeconds) unset($seen[$mac]);
    }
    @file_put_contents($file, json_encode($seen));

    return array_keys($seen);
}

// ── MAC helper ─────────────────────────────────────────────────
function isLikelyRandomizedMac(string $mac): bool {
    $clean = strtolower(str_replace([':', '-'], '', $mac));
    if (strlen($clean) < 2) return false;
    $firstByte = hexdec(substr($clean, 0, 2));
    $isMulticast = ($firstByte & 0x01) === 0x01;
    $isLocallyAdministered = ($firstByte & 0x02) === 0x02;
    return !$isMulticast && $isLocallyAdministered;
}

// ── Run the scan ──────────────────────────────────────────────
$log     = [];
$scanned = [];

// Step 0: detect the network we're actually on right now
$net     = detectNetwork();
$subnet  = $net['subnet'];
$gateway = $net['gateway'];
$log[] = ['info', "🔔 Detected network {$subnet}.0/24 (this machine: {$net['ip']}" . ($gateway ? ", gateway: {$gateway}" : "") . ")"];
$log[] = ['info', "🔔 Pinging subnet {$subnet}.0/24 to detect all connected devices..."];
pingSubnet($subnet);

// Step 1: read the neighbour table, keeping only live entries.
// If the neighbour read returns nothing (unparseable output on a
// localised Windows, or an unusual environment), fall back to the
// original ARP read so the scanner still works — just without the
// stale-entry filtering.
$liveMACs   = getLiveNeighbours();
$usedLegacy = false;

if (empty($liveMACs)) {
    $liveMACs   = getConnectedMACs();
    $usedLegacy = true;
    $log[] = ['warn', "⚠️ Could not read neighbour states — fell back to the raw ARP table. Stale entries from devices that changed MAC may still appear."];
}

// Step 1a: add THIS machine. The server never shows up in its own
// neighbour table, so without this the laptop running the scanner
// can never be checked in.
$selfMac = getLocalMac($net['ip']);
if ($selfMac) {
    if (!in_array($selfMac, $liveMACs, true)) {
        $liveMACs[] = $selfMac;
    }
    $log[] = ['info', "🖥️ This server ({$net['ip']}) added as {$selfMac} — a host never appears in its own neighbour table."];
} else {
    $log[] = ['warn', "⚠️ Could not read this machine's own MAC address, so the server's own device will not be detected."];
}

// Step 1b: apply the grace window so brief drop-outs don't flap.
$detectedMACs = applySeenGrace($liveMACs, $subnet, SEEN_GRACE_SECONDS);
$heldInGrace  = array_values(array_diff($detectedMACs, $liveMACs));

$log[] = ['info', "🔍 Network scan complete. " . count($liveMACs) . " device(s) live right now, " . count($detectedMACs) . " counted as present (grace window: " . SEEN_GRACE_SECONDS . "s)."];
if ($heldInGrace) {
    $log[] = ['info', "⏳ " . count($heldInGrace) . " device(s) not answering this instant but still inside the grace window — not checked out yet."];
}

// Step 2: pick the Wi-Fi zone that matches THIS network.
// Match by gateway first, then subnet, then fall back to first active.
//
// IMPORTANT: we first discover which columns the wifi_zones table
// actually has, and only query the ones present. This means the
// scanner works WHETHER OR NOT you've run wifi_zones_patch.sql — it
// will never crash with "Unknown column 'gateway_ip'".
$zoneCols = [];
try {
    foreach ($pdo->query("SHOW COLUMNS FROM wifi_zones") as $col) {
        $zoneCols[strtolower($col['Field'])] = true;
    }
} catch (Throwable $e) {
    $zoneCols = [];
}
$hasGatewayCol = isset($zoneCols['gateway_ip']);
$hasRouterCol  = isset($zoneCols['router_ip']);
$hasSubnetCol  = isset($zoneCols['subnet']);

$zone = null;

// 2a: try to match by gateway (most reliable) if such a column exists
if (!$zone && $gateway && ($hasGatewayCol || $hasRouterCol)) {
    $conds = [];
    $args  = [];
    if ($hasGatewayCol) { $conds[] = "gateway_ip = ?"; $args[] = $gateway; }
    if ($hasRouterCol)  { $conds[] = "router_ip = ?";  $args[] = $gateway; }
    $sql = "SELECT * FROM wifi_zones WHERE is_active = 1 AND (" . implode(' OR ', $conds) . ") LIMIT 1";
    $zStmt = $pdo->prepare($sql);
    $zStmt->execute($args);
    $zone = $zStmt->fetch();
}

// 2b: try to match by subnet if that column exists
if (!$zone && $hasSubnetCol) {
    $zStmt = $pdo->prepare("SELECT * FROM wifi_zones WHERE is_active = 1 AND subnet = ? LIMIT 1");
    $zStmt->execute([$subnet]);
    $zone = $zStmt->fetch();
}

// 2c: last resort — first active zone
if (!$zone) {
    $zone = $pdo->query("SELECT * FROM wifi_zones WHERE is_active = 1 LIMIT 1")->fetch();
}

if (!$zone) {
    $log[] = ['error', "❌ No active Wi-Fi zones configured. Please add one in Wi-Fi Zones."];
    $zoneId = 1;
} else {
    $zoneId = $zone['wifi_zone_id'];
    $ssidLabel = $zone['ssid'] ?? '';
    $log[] = ['info', "📶 Zone: {$zone['zone_name']}" . ($ssidLabel ? " (SSID: {$ssidLabel})" : "")];
}

// Step 2b: NETWORK-SWITCH CLEANUP.
// If anyone is still marked 'present' but was checked in on a
// DIFFERENT zone than the one we're on now, they can't possibly be
// on this network — check them out immediately. This is what stops
// the dashboard showing yesterday's / another-site's stale sessions
// when you move from college → office → home.
$stale = $pdo->prepare("
    SELECT al.log_id, al.staff_id, al.check_in, s.full_name, m.mac_address
    FROM   attendance_logs al
    JOIN   staff        s ON s.staff_id = al.staff_id
    JOIN   mac_addresses m ON m.mac_id  = al.mac_id
    WHERE  al.status = 'present'
    AND    DATE(al.check_in) = CURDATE()
    AND    al.wifi_zone_id <> ?
");
$stale->execute([$zoneId]);
$staleRows = $stale->fetchAll();
$staleCleared = 0;
foreach ($staleRows as $p) {
    $checkIn  = new DateTime($p['check_in']);
    $checkOut = new DateTime();
    $duration = max(0, (int) round(($checkOut->getTimestamp() - $checkIn->getTimestamp()) / 60));
    $pdo->prepare("
        UPDATE attendance_logs
        SET check_out = NOW(), status = 'left', duration_minutes = ?
        WHERE log_id = ?
    ")->execute([$duration, $p['log_id']]);
    $staleCleared++;
    $log[] = ['checkout', "🔴 AUTO-CHECKOUT (network changed): {$p['full_name']} — {$p['mac_address']} @ " . date('H:i:s')];
}

// Step 3: match each detected MAC to a staff member
$newCheckIns = 0; $alreadyHere = 0; $unknown = 0; $unknownRandomized = 0;

foreach ($detectedMACs as $mac) {
    $stmt = $pdo->prepare("
        SELECT m.mac_id, m.staff_id, m.device_label, s.full_name
        FROM   mac_addresses m
        JOIN   staff s ON s.staff_id = m.staff_id
        WHERE  m.mac_address = ?
        AND    m.is_active   = 1
        AND    s.is_active   = 1
    ");
    $stmt->execute([$mac]);
    $device = $stmt->fetch();

    if (!$device) {
        $unknown++;
        if (isLikelyRandomizedMac($mac)) {
            $unknownRandomized++;
            $log[] = ['warn', "⚠️ Unrecognized device with randomized MAC — {$mac} — likely a phone with Private Wi-Fi Address enabled. If this should be tracked, turn off MAC randomization for this network on that device and re-register its MAC."];
        }
        continue;
    }

    // Already present today ON THIS zone?
    $existing = $pdo->prepare("
        SELECT log_id FROM attendance_logs
        WHERE  staff_id = ?
        AND    status   = 'present'
        AND    wifi_zone_id = ?
        AND    DATE(check_in) = CURDATE()
        LIMIT  1
    ");
    $existing->execute([$device['staff_id'], $zoneId]);

    if ($existing->fetch()) {
        $alreadyHere++;
        $log[] = ['ok', "✅ {$device['full_name']} — already present ({$mac})"];
    } else {
        $pdo->prepare("
            INSERT INTO attendance_logs (staff_id, mac_id, wifi_zone_id, check_in, status)
            VALUES (?, ?, ?, NOW(), 'present')
        ")->execute([$device['staff_id'], $device['mac_id'], $zoneId]);
        $newCheckIns++;
        $deviceLabel = $device['device_label'] ?? 'device';
        $log[] = ['checkin', "🟢 CHECK-IN: {$device['full_name']} — {$mac} ({$deviceLabel}) @ " . date('H:i:s')];
    }

    $scanned[] = $device['staff_id'];
}

// Step 4: CHECK-OUTs — present on THIS zone but MAC not seen this scan
// (and outside the grace window, since $detectedMACs already includes
// anything seen recently).
$presentStaff = $pdo->prepare("
    SELECT al.log_id, al.staff_id, al.check_in, s.full_name, m.mac_address
    FROM   attendance_logs al
    JOIN   staff        s ON s.staff_id = al.staff_id
    JOIN   mac_addresses m ON m.mac_id  = al.mac_id
    WHERE  al.status = 'present'
    AND    al.wifi_zone_id = ?
    AND    DATE(al.check_in) = CURDATE()
");
$presentStaff->execute([$zoneId]);
$presentStaff = $presentStaff->fetchAll();

$checkOuts = 0;
foreach ($presentStaff as $p) {
    if (!in_array($p['staff_id'], $scanned)) {
        $checkIn  = new DateTime($p['check_in']);
        $checkOut = new DateTime();
        $duration = max(0, (int) round(($checkOut->getTimestamp() - $checkIn->getTimestamp()) / 60));

        $pdo->prepare("
            UPDATE attendance_logs
            SET    check_out        = NOW(),
                   status           = 'left',
                   duration_minutes = ?
            WHERE  log_id = ?
        ")->execute([$duration, $p['log_id']]);

        $checkOuts++;
        $log[] = ['checkout', "🔴 CHECK-OUT: {$p['full_name']} — {$p['mac_address']} @ " . date('H:i:s') . " (duration: " . formatDuration($duration) . ")"];
    }
}

$log[] = ['info', "─── Scan summary: {$newCheckIns} new check-in(s), {$checkOuts} check-out(s), {$staleCleared} cleared from other networks, {$alreadyHere} already present, {$unknown} unknown device(s) ignored ({$unknownRandomized} with randomized MACs)."];

// Live count of staff currently present on this zone (for the header pill)
$presentNow = ($newCheckIns + $alreadyHere);

// ── HTML OUTPUT ───────────────────────────────────────────────
$pageTitle = 'Scanner';
include 'includes/header.php';
?>

<style>
/* ── Scanner page polish (scoped) ─────────────────────────── */
.scan-hero{
    display:flex;align-items:flex-start;justify-content:space-between;
    gap:20px;flex-wrap:wrap;margin-bottom:22px;
    padding:22px 24px;border-radius:16px;
    background:
        radial-gradient(1200px 200px at 0% 0%, rgba(0,212,170,.10), transparent 60%),
        linear-gradient(135deg, rgba(16,185,129,.06), rgba(56,189,248,.04));
    border:1px solid var(--border);
    position:relative;overflow:hidden;
}
.scan-hero::after{
    content:"";position:absolute;right:-40px;top:-40px;
    width:180px;height:180px;border-radius:50%;
    background:radial-gradient(circle, rgba(0,212,170,.18), transparent 70%);
    filter:blur(6px);pointer-events:none;
}
.scan-hero h2{margin:0 0 6px;font-size:22px;letter-spacing:.3px;display:flex;align-items:center;gap:10px}
.scan-hero p{margin:0;color:var(--muted);font-size:13px;max-width:520px}
.scan-pill{
    display:inline-flex;align-items:center;gap:8px;
    padding:7px 14px;border-radius:999px;font-size:12px;font-weight:600;
    background:rgba(16,185,129,.12);color:#10b981;border:1px solid rgba(16,185,129,.3);
    white-space:nowrap;
}
.scan-pill .dot{width:8px;height:8px;border-radius:50%;background:#10b981;box-shadow:0 0 0 0 rgba(16,185,129,.6);animation:pulse 1.8s infinite}
@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(16,185,129,.5)}70%{box-shadow:0 0 0 8px rgba(16,185,129,0)}100%{box-shadow:0 0 0 0 rgba(16,185,129,0)}}

.scan-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:22px}
.scan-stat{
    padding:16px 18px;border-radius:14px;border:1px solid var(--border);
    background:linear-gradient(180deg, rgba(255,255,255,.02), transparent);
    position:relative;overflow:hidden;
}
.scan-stat .n{font-size:28px;font-weight:700;line-height:1;font-family:var(--mono)}
.scan-stat .l{margin-top:8px;font-size:11px;letter-spacing:1px;text-transform:uppercase;color:var(--muted)}
.scan-stat.accent .n{color:var(--accent)}
.scan-stat.green  .n{color:#10b981}
.scan-stat.blue   .n{color:#38bdf8}
.scan-stat.amber  .n{color:#f59e0b}

.console{
    background:#080b12;border:1px solid var(--border);border-radius:12px;
    overflow:hidden;box-shadow:inset 0 0 40px rgba(0,0,0,.4);
}
.console-bar{
    display:flex;align-items:center;gap:8px;padding:10px 14px;
    background:rgba(255,255,255,.02);border-bottom:1px solid var(--border);
}
.console-bar .tl{display:flex;gap:6px}
.console-bar .tl i{width:11px;height:11px;border-radius:50%;display:inline-block}
.console-bar .tl i:nth-child(1){background:#f43f5e}
.console-bar .tl i:nth-child(2){background:#f59e0b}
.console-bar .tl i:nth-child(3){background:#10b981}
.console-bar .title{font-family:var(--mono);font-size:12px;color:var(--muted);margin-left:6px}
.console-body{
    padding:16px 18px;font-family:var(--mono);font-size:12.5px;line-height:1.85;
    max-height:360px;overflow:auto;
}
.console-body .line{padding:2px 0;display:flex;gap:10px;align-items:flex-start}
.console-body .line .txt{flex:1;word-break:break-word}
.scan-actions{margin-top:18px;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
</style>

<div class="scan-hero">
    <div>
        <h2>🛰️ Network Scanner</h2>
        <p>Live Wi-Fi presence detection — reads the local neighbour table, discards stale entries, and matches live devices to registered staff.</p>
    </div>
    <div style="display:flex;flex-direction:column;gap:10px;align-items:flex-end">
        <span class="scan-pill"><span class="dot"></span> LIVE · auto-refresh</span>
        <?php if (!empty($zone['zone_name'])): ?>
            <span style="font-size:12px;color:var(--muted);font-family:var(--mono)">
                📶 <?= e($zone['zone_name']) ?><?= !empty($net['subnet']) ? " · " . e($net['subnet']) . ".0/24" : "" ?>
            </span>
        <?php elseif (!empty($net['subnet'])): ?>
            <span style="font-size:12px;color:var(--muted);font-family:var(--mono)">
                📡 <?= e($net['subnet']) ?>.0/24
            </span>
        <?php endif; ?>
        <?php if ($usedLegacy): ?>
            <span style="font-size:11px;color:#f59e0b;font-family:var(--mono)">
                ⚠️ legacy ARP mode — stale entries possible
            </span>
        <?php endif; ?>
    </div>
</div>

<div class="scan-stats">
    <div class="scan-stat green">
        <div class="n"><?= (int)$presentNow ?></div>
        <div class="l">Present now</div>
    </div>
    <div class="scan-stat accent">
        <div class="n"><?= count($liveMACs) ?></div>
        <div class="l">Live on network</div>
    </div>
    <div class="scan-stat amber">
        <div class="n"><?= count($heldInGrace) ?></div>
        <div class="l">Held in grace</div>
    </div>
    <div class="scan-stat blue">
        <div class="n"><?= (int)$newCheckIns ?></div>
        <div class="l">New check-ins</div>
    </div>
    <div class="scan-stat amber">
        <div class="n"><?= (int)$checkOuts ?></div>
        <div class="l">Check-outs</div>
    </div>
</div>

<div class="card" style="padding:20px">
    <div class="console">
        <div class="console-bar">
            <span class="tl"><i></i><i></i><i></i></span>
            <span class="title">scan-log — <?= date('d M Y · H:i:s') ?></span>
        </div>
        <div class="console-body">
            <?php foreach ($log as [$type, $text]): ?>
                <?php
                    $color = match($type) {
                        'checkin'  => '#10b981',
                        'checkout' => '#f43f5e',
                        'ok'       => '#6b7a99',
                        'error'    => '#f43f5e',
                        'warn'     => '#f59e0b',
                        default    => '#dce4f0',
                    };
                ?>
                <div class="line"><span class="txt" style="color:<?= $color ?>"><?= e($text) ?></span></div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="scan-actions">
        <a href="scanner.php" class="btn btn-primary">🔄 Run Again</a>
        <a href="index.php"   class="btn btn-ghost">← Back to Dashboard</a>
    </div>
</div>

<div class="card" style="padding:20px">
    <div class="card-title" style="display:flex;align-items:center;gap:8px;font-size:15px;margin-bottom:14px">
        📋 All Detected Devices
        <span style="font-family:var(--mono);font-size:12px;color:var(--accent);background:rgba(0,212,170,.1);padding:2px 10px;border-radius:999px;border:1px solid rgba(0,212,170,.25)"><?= count($detectedMACs) ?></span>
    </div>

    <?php if (empty($detectedMACs)): ?>
        <p style="text-align:center;color:var(--muted);padding:24px">No devices detected. Make sure the server is on the same WiFi network.</p>
    <?php else: ?>

    <?php
    $liveLookup = array_flip($liveMACs);
    $organized = [];
    foreach ($detectedMACs as $mac) {
        $stmt = $pdo->prepare("
            SELECT m.mac_id, m.staff_id, m.device_label, s.full_name, al.log_id
            FROM mac_addresses m
            JOIN staff s ON s.staff_id = m.staff_id
            LEFT JOIN attendance_logs al ON al.staff_id = m.staff_id
                AND al.status = 'present' AND DATE(al.check_in) = CURDATE()
            WHERE m.mac_address = ? AND m.is_active = 1 AND s.is_active = 1
        ");
        $stmt->execute([$mac]);
        $device = $stmt->fetch();

        $organized[] = [
            'mac' => $mac,
            'registered' => (bool)$device,
            'staff_name' => $device['full_name'] ?? null,
            'device_label' => $device['device_label'] ?? null,
            'is_present' => (bool)($device['log_id'] ?? false),
            'randomized' => isLikelyRandomizedMac($mac),
            'live' => isset($liveLookup[$mac]),
            'self' => ($selfMac !== null && $mac === $selfMac),
        ];
    }
    usort($organized, function($a, $b) {
        if ($a['registered'] && $b['registered']) {
            return $b['is_present'] <=> $a['is_present'];
        }
        return $b['registered'] <=> $a['registered'];
    });
    ?>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>MAC Address</th>
                <th>Staff Name</th>
                <th>Device Label</th>
                <th>Signal</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($organized as $i => $item): ?>
            <tr style="<?= $item['is_present'] ? 'background:rgba(16,185,129,0.15);font-weight:600' : ($item['registered'] ? 'background:rgba(0,212,170,0.08)' : '') ?>">
                <td class="mono" style="color:var(--muted);font-size:12px"><?= $i + 1 ?></td>
                <td>
                    <code style="background:var(--bg);padding:2px 6px;border-radius:3px;color:<?= $item['registered'] ? 'var(--accent)' : '#f59e0b' ?>;font-size:11px">
                        <?= e($item['mac']) ?>
                        <?php if ($item['randomized'] && !$item['registered']): ?>
                            <span title="Randomized MAC">⚠️</span>
                        <?php endif; ?>
                    </code>
                </td>
                <td>
                    <?php if ($item['registered']): ?>
                        <strong><?= e($item['staff_name']) ?></strong>
                    <?php else: ?>
                        <span style="color:var(--muted)">—</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:12px;color:var(--muted)">
                    <?= e($item['device_label'] ?? '—') ?>
                </td>
                <td style="font-size:12px">
                    <?php if ($item['self']): ?>
                        <span style="color:#38bdf8" title="This machine — the one running the server">🖥️ This PC</span>
                    <?php elseif ($item['live']): ?>
                        <span style="color:#10b981">● Live</span>
                    <?php else: ?>
                        <span style="color:#f59e0b" title="Not answering right now but still inside the grace window">◐ Grace</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($item['is_present']): ?>
                        <span class="badge badge-green">✅ PRESENT</span>
                    <?php elseif ($item['registered']): ?>
                        <span class="badge badge-blue">Registered</span>
                    <?php elseif ($item['randomized']): ?>
                        <span class="badge badge-orange">⚠️ Randomized</span>
                    <?php else: ?>
                        <span class="badge badge-gray">Unknown</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php endif; ?>
</div>

<script>
// Auto-refresh, but gently:
//  • Every 20s instead of 5s (each reload re-runs a DB scan; 5s was
//    hammering the server and made navigating AWAY feel laggy).
//  • Pauses while the tab is hidden or when you're leaving the page,
//    so clicking to another page isn't fighting a pending reload.
let reloadTimer = setTimeout(doReload, 20000);
function doReload() {
    if (document.visibilityState === 'visible') location.reload();
}
// Cancel the pending reload the moment you navigate away — this is
// what makes Scanner → other-page feel instant now.
window.addEventListener('beforeunload', () => clearTimeout(reloadTimer));
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') clearTimeout(reloadTimer);
});
</script>

<?php include 'includes/footer.php'; ?>
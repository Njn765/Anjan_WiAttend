<?php
// ============================================================
//  includes/functions.php  —  Shared Helper Functions
// ============================================================

// ── Session guard: redirect to login if not authenticated ────
function requireLogin() {
    if (!isset($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

// ── Sanitise output to prevent XSS ───────────────────────────
function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// ── Format duration (minutes → "2h 15m") ─────────────────────
function formatDuration($minutes) {
    if ($minutes === null) return '—';
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
}

// ── Validate MAC address format (aa:bb:cc:dd:ee:ff) ───────────
function isValidMAC($mac) {
    return (bool) preg_match('/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/', $mac);
}

// ── Normalise MAC to lowercase ────────────────────────────────
function normaliseMAC($mac) {
    return strtolower(str_replace('-', ':', trim($mac)));
}

// ============================================================
//  CORE: Scan the local network for connected MAC addresses
//  Works on:
//    • Linux  (Raspberry Pi, Ubuntu) — reads /proc/net/arp
//    • Windows XAMPP                 — runs  arp -a
// ============================================================
function getConnectedMACs() {
    $macs = [];

    // ── Linux path ───────────────────────────────────────────
    if (file_exists('/proc/net/arp')) {
        $lines = file('/proc/net/arp', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach (array_slice($lines, 1) as $line) {          // skip header row
            $parts = preg_split('/\s+/', trim($line));
            // Column 3 is the HW address in ARP table
            if (isset($parts[3]) && $parts[3] !== '00:00:00:00:00:00') {
                if (preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $parts[3])) {
                    $macs[] = normaliseMAC($parts[3]);
                }
            }
        }
    }
    // ── Windows path ─────────────────────────────────────────
    else {
        exec('arp -a', $output);
        foreach ($output as $line) {
            // Windows format: "192.168.1.x  aa-bb-cc-dd-ee-ff  dynamic"
            if (preg_match('/([0-9a-f]{2}[:-]){5}[0-9a-f]{2}/i', $line, $matches)) {
                $macs[] = normaliseMAC($matches[0]);
            }
        }
    }

    return array_unique($macs);
}

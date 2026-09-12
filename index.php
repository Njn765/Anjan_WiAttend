<?php
// ============================================================
//  index.php  —  Modern Real-Time Presence Dashboard
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';
requireLogin();

// ── Calculate Dashboard Stats ─────────────────────────────────
$totalStaff = $pdo->query("SELECT COUNT(*) FROM staff WHERE is_active = 1")->fetchColumn();

// Currently in office
$presentNow = $pdo->query("
    SELECT COUNT(*) FROM attendance_logs 
    WHERE status = 'present' AND DATE(check_in) = CURDATE()
")->fetchColumn();

// Late arrivals — anyone whose first check-in today is after their
// own scheduled start time + grace period. Staff with no schedule
// are compared against a 09:00 AM default. Matches the timeliness
// logic on the reports page so dashboard and reports never disagree.
$lateArrivals = $pdo->query("
    SELECT COUNT(*) FROM (
        SELECT al.staff_id
        FROM   attendance_logs al
        LEFT JOIN staff_schedules ss ON ss.staff_id = al.staff_id
        WHERE  DATE(al.check_in) = CURDATE()
        GROUP BY al.staff_id
        HAVING TIME(MIN(al.check_in)) > ADDTIME(
            COALESCE(MAX(ss.expected_in), '09:00:00'),
            SEC_TO_TIME(COALESCE(MAX(ss.grace_minutes), 0) * 60)
        )
    ) AS late_today
")->fetchColumn();

// Left early (checked out before expected end time)
// IMPORTANT: only counts a staff member if their MOST RECENT session
// today is the one that ended early AND is still closed (status = 'left').
// If they reconnected afterward, their latest session's status is
// 'present' (an open session), so they are correctly excluded here and
// instead counted in "Currently Online" — this keeps the two stats from
// double-labeling someone as both "left early" and "present".
$leftEarly = $pdo->query("
    SELECT COUNT(*) FROM (
        SELECT al.staff_id
        FROM attendance_logs al
        LEFT JOIN staff_schedules ss ON ss.staff_id = al.staff_id
        WHERE DATE(al.check_in) = CURDATE()
        AND al.log_id = (
            SELECT a2.log_id
            FROM attendance_logs a2
            WHERE a2.staff_id = al.staff_id
            AND DATE(a2.check_in) = CURDATE()
            ORDER BY a2.log_id DESC
            LIMIT 1
        )
        AND al.status = 'left'
        AND al.check_out IS NOT NULL
        AND ss.expected_out IS NOT NULL
        AND TIME(al.check_out) < TIME(ss.expected_out)
    ) AS left_early_today
")->fetchColumn();

// ── Search functionality ──────────────────────────────────────
$searchQuery = trim($_GET['search'] ?? '');
$searchCondition = '';
$searchParams = [];

if ($searchQuery) {
    $searchCondition = "AND (s.full_name LIKE ? OR s.email LIKE ? OR m.mac_address LIKE ?)";
    $searchTerm = "%{$searchQuery}%";
    $searchParams = [$searchTerm, $searchTerm, $searchTerm];
}

// ── Staff Presence Log (one row per staff member) ─────────────
// Shows each person ONCE with their current status, first arrival,
// total time worked today, and latest departure.
$searchCondition = '';
$searchParams    = [];

if (!empty($searchQuery)) {
    $searchCondition = "AND (s.full_name LIKE :q1 OR s.email LIKE :q2 OR m2.mac_address LIKE :q3)";
    $searchParams = [':q1' => "%$searchQuery%", ':q2' => "%$searchQuery%", ':q3' => "%$searchQuery%"];
}

$query = "
    SELECT 
        s.staff_id,
        s.full_name,
        s.job_title,
        d.dept_name,
        -- First arrival today
        MIN(al.check_in) AS first_arrival,
        -- Latest departure (NULL if still present)
        MAX(al.check_out) AS last_departure,
        -- Are they currently present? (any open session today)
        MAX(CASE WHEN al.status = 'present' THEN 1 ELSE 0 END) AS is_present,
        -- Total completed minutes + running session minutes
        COALESCE(SUM(
            CASE 
                WHEN al.status = 'left' THEN al.duration_minutes
                WHEN al.status = 'present' THEN ROUND((UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(al.check_in)) / 60)
                ELSE 0
            END
        ), 0) AS total_minutes,
        -- Session count
        COUNT(al.log_id) AS sessions,
        -- Total reconnections (brief WiFi drops that were auto-merged)
        COALESCE(SUM(al.reconnections), 0) AS total_reconnections,
        -- Latest device/zone info (from the most recent log)
        (SELECT m.device_label FROM attendance_logs a2 
         JOIN mac_addresses m ON m.mac_id = a2.mac_id 
         WHERE a2.staff_id = s.staff_id AND DATE(a2.check_in) = CURDATE() 
         ORDER BY a2.log_id DESC LIMIT 1) AS device_label,
        (SELECT w.zone_name FROM attendance_logs a3 
         JOIN wifi_zones w ON w.wifi_zone_id = a3.wifi_zone_id 
         WHERE a3.staff_id = s.staff_id AND DATE(a3.check_in) = CURDATE() 
         ORDER BY a3.log_id DESC LIMIT 1) AS zone_name
    FROM staff s
    LEFT JOIN attendance_logs al ON al.staff_id = s.staff_id AND DATE(al.check_in) = CURDATE()
    LEFT JOIN departments d ON d.department_id = s.department_id
    LEFT JOIN mac_addresses m2 ON m2.staff_id = s.staff_id
    WHERE s.is_active = 1
    $searchCondition
    GROUP BY s.staff_id, s.full_name, s.job_title, d.dept_name
    ORDER BY 
        is_present DESC,
        CASE WHEN MIN(al.check_in) IS NULL THEN 1 ELSE 0 END,
        MIN(al.check_in) ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute($searchParams);
$presenceLog = $stmt->fetchAll();

// Get individual session details per staff member (for expandable timeline)
$sessionDetails = [];
$detailQuery = $pdo->query("
    SELECT al.staff_id, al.check_in, al.check_out, al.status,
           al.duration_minutes, al.reconnections, al.reconnect_log,
           m.device_label, w.zone_name
    FROM   attendance_logs al
    JOIN   mac_addresses m ON m.mac_id = al.mac_id
    LEFT JOIN wifi_zones w ON w.wifi_zone_id = al.wifi_zone_id
    WHERE  DATE(al.check_in) = CURDATE()
    ORDER BY al.check_in ASC
");
foreach ($detailQuery->fetchAll() as $d) {
    $sessionDetails[(int)$d['staff_id']][] = $d;
}

$pageTitle = 'Dashboard';
include 'includes/header.php';

$engDateStr = date('l, j F Y'); // e.g. "Wednesday, 1 July 2026"
?>

<style>
/* ─────────────────────────────────────────────────────────── */
/* Dashboard Custom Styles - Enhanced Styling                  */
/* ─────────────────────────────────────────────────────────── */

/* ─────────────────────────────────────────────────────────── */
/* Dashboard Custom Styles - Enhanced Styling                  */
/* ─────────────────────────────────────────────────────────── */

/* Sidebar Branding */
.sidebar-brand {
    padding: 24px 20px;
    border-bottom: 2px solid rgba(0, 212, 170, 0.2);
    margin-bottom: 12px;
}

.sidebar-brand-title {
    font-size: 18px;
    font-weight: 900;
    background: linear-gradient(135deg, #00d4aa 0%, #38bdf8 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    letter-spacing: 1px;
    font-family: 'Courier New', monospace;
    margin: 0 0 4px 0;
}

.sidebar-brand-subtitle {
    font-size: 11px;
    color: #00d4aa;
    letter-spacing: 2px;
    text-transform: uppercase;
    font-weight: 700;
    font-family: 'Courier New', monospace;
    margin: 0;
}

.dashboard-container {
    max-width: 1400px;
    margin: 0 auto;
}

/* Dashboard Header with Live Indicator */
.dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 32px;
    padding: 26px 30px;
    border: 1px solid var(--border);
    border-radius: 18px;
    background:
        radial-gradient(1200px 240px at 0% 0%, rgba(0, 212, 170, 0.09), transparent 62%),
        linear-gradient(135deg, rgba(16, 185, 129, 0.045), rgba(56, 189, 248, 0.03));
    position: relative;
    overflow: hidden;
    gap: 20px;
}
.dashboard-header::after {
    content: "";
    position: absolute;
    right: -60px;
    top: -60px;
    width: 220px;
    height: 220px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(0, 212, 170, 0.14), transparent 70%);
    filter: blur(10px);
    pointer-events: none;
}

.dashboard-title {
    flex: 1;
    position: relative;
    z-index: 1;
}

.dashboard-title h1 {
    font-size: 34px;
    font-weight: 800;
    margin-bottom: 6px;
    letter-spacing: 1px;
    background: linear-gradient(135deg, #00d4aa 0%, #38bdf8 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    font-family: 'Space Grotesk', 'Segoe UI', system-ui, sans-serif;
    text-transform: none;
}

.dashboard-title .subtitle {
    color: var(--muted);
    font-size: 13.5px;
    font-weight: 500;
    letter-spacing: .2px;
}

.dashboard-info {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 16px;
    position: relative;
    z-index: 1;
}

/* Live Status card */
.live-status {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 18px;
    background: rgba(255, 255, 255, 0.02);
    border: 1px solid var(--border);
    border-radius: 14px;
    backdrop-filter: blur(10px);
}

.live-status-text {
    display: flex;
    flex-direction: column;
    gap: 3px;
    align-items: flex-end;
}

.live-status-text .status {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: #10b981;
    font-weight: 700;
    font-size: 11px;
    letter-spacing: 1.2px;
    text-transform: uppercase;
}
.live-status-text .status::before {
    content: "";
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #10b981;
    box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.6);
    animation: pulse-glow 1.9s infinite;
}

@keyframes pulse-glow {
    0%   { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.55); }
    70%  { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
    100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}

.live-status-text .clock {
    color: var(--text);
    font-weight: 700;
    font-size: 22px;
    font-family: var(--mono);
    letter-spacing: 1px;
    line-height: 1.05;
}

.live-status-text .clock-date {
    color: var(--muted);
    font-size: 12px;
    font-weight: 500;
    font-family: var(--mono);
    letter-spacing: .3px;
}

.logout-section {
    display: flex;
    gap: 8px;
    align-items: center;
}

.logout-btn a {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 16px;
    background: linear-gradient(135deg, rgba(244, 63, 94, 0.15) 0%, rgba(244, 63, 94, 0.05) 100%);
    border: 1px solid rgba(244, 63, 94, 0.3);
    color: #f43f5e;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    cursor: pointer;
}

.logout-btn a:hover {
    background: linear-gradient(135deg, rgba(244, 63, 94, 0.25) 0%, rgba(244, 63, 94, 0.1) 100%);
    border-color: rgba(244, 63, 94, 0.5);
    transform: translateY(-2px);
}

/* Stats Grid - Enhanced */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 36px;
}

.stat-card {
    background: linear-gradient(135deg, rgba(0, 212, 170, 0.08) 0%, rgba(0, 170, 136, 0.08) 100%);
    border: 1px solid rgba(0, 212, 170, 0.2);
    border-radius: 14px;
    padding: 14px;
    position: relative;
    overflow: hidden;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    backdrop-filter: blur(10px);
}

.stat-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 100%;
    height: 100%;
    background: radial-gradient(circle, rgba(0, 212, 170, 0.1) 0%, transparent 70%);
    animation: float 15s infinite ease-in-out;
}

@keyframes float {
    0%, 100% { transform: translate(0, 0) rotate(0deg); }
    33% { transform: translate(10px, -10px) rotate(2deg); }
    66% { transform: translate(-10px, 10px) rotate(-2deg); }
}

.stat-card::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, transparent 0%, transparent 70%, rgba(0, 212, 170, 0.05) 100%);
    pointer-events: none;
}

.stat-card:hover {
    transform: translateY(-8px);
    border-color: rgba(0, 212, 170, 0.4);
    box-shadow: 0 20px 40px rgba(0, 212, 170, 0.15);
}

.stat-card-content {
    position: relative;
    z-index: 1;
    display: flex;
    flex-direction: column;
}

.stat-icon {
    font-size: 20px;
    margin-bottom: 8px;
    display: inline-block;
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, rgba(0, 212, 170, 0.2) 0%, rgba(16, 185, 129, 0.2) 100%);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.stat-label {
    color: var(--muted);
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 6px;
    font-weight: 700;
}

.stat-value {
    font-family: var(--mono);
    font-size: 26px;
    font-weight: 800;
    background: linear-gradient(135deg, #00d4aa 0%, #10b981 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin-bottom: 2px;
    letter-spacing: -1px;
}

.stat-detail {
    color: var(--muted);
    font-size: 10px;
    font-weight: 500;
}

.stat-detail strong {
    color: var(--text);
    font-weight: 700;
}

/* Search Section - Enhanced */
.search-section {
    margin-bottom: 36px;
}

.search-bar {
    position: relative;
    display: flex;
    align-items: center;
}

.search-icon {
    position: absolute;
    left: 16px;
    color: var(--muted);
    font-size: 18px;
    display: flex;
    align-items: center;
}

.search-bar input {
    width: 100%;
    padding: 14px 16px 14px 48px;
    background: rgba(0, 212, 170, 0.08);
    border: 2px solid rgba(0, 212, 170, 0.2);
    border-radius: 10px;
    color: var(--text);
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    transition: all 0.3s ease;
}

.search-bar input::placeholder {
    color: var(--muted);
}

.search-bar input:focus {
    outline: none;
    border-color: rgba(0, 212, 170, 0.5);
    background: rgba(0, 212, 170, 0.12);
    box-shadow: 0 0 20px rgba(0, 212, 170, 0.1);
}

/* Presence Table Card - Enhanced */
.presence-table-card {
    background: rgba(15, 23, 42, 0.4);
    border: 1px solid rgba(0, 212, 170, 0.15);
    border-radius: 14px;
    padding: 28px;
    backdrop-filter: blur(10px);
}

.presence-table-header {
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.presence-table-title {
    font-size: 22px;
    font-weight: 800;
    background: linear-gradient(135deg, #00d4aa 0%, #10b981 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin: 0;
}

.presence-table-subtitle {
    color: var(--muted);
    font-size: 12px;
    margin-top: 6px;
    font-weight: 500;
}

.presence-table {
    width: 100%;
    border-collapse: collapse;
}

.presence-table thead {
    border-bottom: 2px solid rgba(0, 212, 170, 0.2);
}

.presence-table th {
    text-align: left;
    padding: 14px 16px;
    color: var(--muted);
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.presence-table tbody tr {
    border-bottom: 1px solid rgba(0, 212, 170, 0.1);
    transition: all 0.2s ease;
}

.presence-table tbody tr:hover {
    background: rgba(0, 212, 170, 0.08);
    border-bottom-color: rgba(0, 212, 170, 0.2);
}

.presence-table td {
    padding: 16px;
    font-size: 13px;
    vertical-align: middle;
    color: var(--text);
}

.staff-name-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}

.staff-avatar {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: linear-gradient(135deg, #00d4aa 0%, #10b981 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-weight: 800;
    font-size: 16px;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0, 212, 170, 0.3);
}

.staff-info h4 {
    margin: 0;
    font-size: 13px;
    font-weight: 700;
    color: var(--text);
    letter-spacing: -0.3px;
}

.staff-info p {
    margin: 4px 0 0 0;
    color: var(--muted);
    font-size: 11px;
    font-weight: 500;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-active {
    background: rgba(16, 185, 129, 0.2);
    color: #10b981;
    border: 1px solid rgba(16, 185, 129, 0.3);
}

.status-inactive {
    background: rgba(107, 122, 153, 0.15);
    color: #6b7a99;
    border: 1px solid rgba(107, 122, 153, 0.2);
}

.status-badge span {
    display: inline-block;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
    flex-shrink: 0;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.4; }
}

.time-cell {
    font-family: var(--mono);
    font-size: 12px;
    color: #10b981;
    font-weight: 600;
    letter-spacing: 0.5px;
}

.duration-cell {
    font-family: var(--mono);
    font-size: 12px;
    color: #00d4aa;
    font-weight: 600;
}

.device-cell {
    font-size: 12px;
}

.device-cell-label {
    color: var(--text);
    margin-bottom: 3px;
    font-weight: 600;
}

.device-cell-zone {
    color: var(--muted);
    font-size: 11px;
    font-weight: 500;
}

.empty-state {
    text-align: center;
    padding: 60px 24px;
    color: var(--muted);
}

.empty-state-icon {
    font-size: 56px;
    margin-bottom: 16px;
    opacity: 0.4;
}

.empty-state p {
    font-size: 14px;
    margin: 0;
}

/* Responsive */
@media (max-width: 768px) {
    .dashboard-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 20px;
        padding: 22px;
    }

    .dashboard-title h1 {
        font-size: 28px;
    }

    .dashboard-info {
        align-items: flex-start;
        width: 100%;
    }

    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .presence-table {
        font-size: 11px;
    }

    .presence-table td {
        padding: 12px;
    }

    .staff-name-cell {
        gap: 8px;
    }

    .staff-avatar {
        width: 32px;
        height: 32px;
        font-size: 14px;
    }

    .stat-value {
        font-size: 32px;
    }
}

/* Hide row actions on mobile */
@media (max-width: 480px) {
    .presence-table thead th:last-child,
    .presence-table tbody td:last-child {
        display: none;
    }
}
</style>

<!-- Dashboard Header -->
<div class="dashboard-container">
    <div class="dashboard-header">
        <div class="dashboard-title">
            <h1>Wi-Attend</h1>
            <p class="subtitle">Real-Time Wi-Fi Attendance Monitoring</p>
        </div>
        <div class="dashboard-info">
            <div class="live-status">
                <div class="live-status-text">
                    <div class="status">Live Monitoring</div>
                    <div class="clock" id="current-time"><?= date('g:i A') ?></div>
                    <div class="clock-date"><?= $engDateStr ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-card-content">
                <div class="stat-icon">👥</div>
                <div class="stat-label">Total Staff</div>
                <div class="stat-value"><?= $totalStaff ?></div>
                <div class="stat-detail">Registered employees</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-card-content">
                <div class="stat-icon">✅</div>
                <div class="stat-label">Currently Online</div>
                <div class="stat-value"><?= $presentNow ?></div>
                <div class="stat-detail"><strong><?= $totalStaff > 0 ? round(($presentNow / $totalStaff) * 100) : 0 ?>%</strong> of staff</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-card-content">
                <div class="stat-icon">⏰</div>
                <div class="stat-label">Late Arrivals</div>
                <div class="stat-value"><?= $lateArrivals ?></div>
                <div class="stat-detail">Past scheduled start</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-card-content">
                <div class="stat-icon">🚪</div>
                <div class="stat-label">Left Early</div>
                <div class="stat-value"><?= $leftEarly ?></div>
                <div class="stat-detail">Before shift end</div>
            </div>
        </div>
    </div>

    <!-- Search Bar -->
    <div class="search-section">
        <form method="GET" class="search-bar">
            <span class="search-icon">🔍</span>
            <input type="text" name="search" placeholder="Search by name, email or MAC address..."
                   value="<?= e($searchQuery) ?>">
        </form>
    </div>

    <!-- Staff Presence Log -->
    <div class="presence-table-card">
        <div class="presence-table-header">
            <div>
                <h2 class="presence-table-title">📊 Staff Presence Log</h2>
                <p class="presence-table-subtitle">Real-time Wi-Fi connection status — one row per staff member</p>
            </div>
        </div>

        <?php if (empty($presenceLog)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📭</div>
                <p><?= $searchQuery ? "No staff found matching your search." : "No attendance activity recorded today yet." ?></p>
            </div>
        <?php else: ?>
            <table class="presence-table">
                <thead>
                    <tr>
                        <th style="width: 26%">Staff Member</th>
                        <th style="width: 12%">Status</th>
                        <th style="width: 12%">First In</th>
                        <th style="width: 12%">Last Out</th>
                        <th style="width: 10%">Today</th>
                        <th style="width: 8%">Sessions</th>
                        <th style="width: 20%">Device / Zone</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($presenceLog as $row): ?>
                    <?php
                        $isPresent   = (int) ($row['is_present'] ?? 0);
                        $hasActivity = !empty($row['first_arrival']);
                    ?>
                    <tr style="<?= $isPresent ? 'background:rgba(0, 212, 170, 0.04)' : '' ?>">
                        <!-- Staff Member -->
                        <td>
                            <div class="staff-name-cell">
                                <div class="staff-avatar">
                                    <?= strtoupper(substr($row['full_name'], 0, 1)) ?>
                                </div>
                                <div class="staff-info">
                                    <h4><?= e($row['full_name']) ?></h4>
                                    <p><?= e($row['job_title'] ?? $row['dept_name'] ?? '—') ?></p>
                                </div>
                            </div>
                        </td>

                        <!-- Status -->
                        <td>
                            <?php if (!$hasActivity): ?>
                                <span class="status-badge status-inactive">
                                    <span></span> No Activity
                                </span>
                            <?php elseif ($isPresent): ?>
                                <span class="status-badge status-active">
                                    <span></span> Active
                                </span>
                            <?php else: ?>
                                <span class="status-badge status-inactive">
                                    <span></span> Offline
                                </span>
                            <?php endif; ?>
                        </td>

                        <!-- First Arrival -->
                        <td class="time-cell">
                            <?= $hasActivity ? date('g:i A', strtotime($row['first_arrival'])) : '<span style="color:var(--muted)">—</span>' ?>
                        </td>

                        <!-- Last Departure -->
                        <td class="time-cell">
                            <?php if ($isPresent): ?>
                                <span style="color:var(--accent);font-size:11px">Still here</span>
                            <?php elseif ($hasActivity && $row['last_departure']): ?>
                                <?= date('g:i A', strtotime($row['last_departure'])) ?>
                            <?php else: ?>
                                <span style="color:var(--muted)">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Total Time Today -->
                        <td class="duration-cell">
                            <?= $hasActivity ? formatDuration((int) $row['total_minutes']) : '<span style="color:var(--muted)">—</span>' ?>
                        </td>

                        <!-- Sessions Count (clickable to expand) -->
                        <td style="text-align:center">
                            <?php if ($hasActivity): ?>
                                <?php $staffSessions = $sessionDetails[(int)$row['staff_id']] ?? []; ?>
                                <?php $totalReconnects = (int)($row['total_reconnections'] ?? 0); ?>
                                <a href="#" onclick="toggleSessions(<?= (int)$row['staff_id'] ?>); return false;"
                                   style="display:inline-flex;align-items:center;gap:4px;color:var(--accent);font-family:var(--mono);font-size:13px;font-weight:600;text-decoration:none"
                                   title="Click to see session timeline">
                                    <?= (int)$row['sessions'] ?>
                                    <?php if ($totalReconnects > 0): ?>
                                        <span style="font-size:10px;color:var(--warning)">(+<?= $totalReconnects ?> reconn.)</span>
                                    <?php endif; ?>
                                    <span style="font-size:10px">▼</span>
                                </a>
                            <?php else: ?>
                                <span style="color:var(--muted)">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Device / Zone -->
                        <td>
                            <?php if ($hasActivity): ?>
                                <div class="device-cell">
                                    <div class="device-cell-label"><?= e($row['device_label'] ?? 'Device') ?></div>
                                    <div class="device-cell-zone">📶 <?= e($row['zone_name'] ?? '—') ?></div>
                                </div>
                            <?php else: ?>
                                <span style="color:var(--muted)">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- Expandable Session Timeline -->
                    <?php if ($hasActivity): ?>
                    <?php $staffSessions = $sessionDetails[(int)$row['staff_id']] ?? []; ?>
                    <tr id="sessions-<?= (int)$row['staff_id'] ?>" style="display:none">
                        <td colspan="7" style="padding:0 16px 16px 68px;background:rgba(0,212,170,0.02)">
                            <div style="border:1px solid rgba(0,212,170,0.12);border-radius:8px;padding:14px 18px;margin-top:4px">
                                <div style="font-size:11px;color:var(--accent);font-weight:700;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px">
                                    📋 Session Timeline — <?= e($row['full_name']) ?>
                                </div>
                                <?php foreach ($staffSessions as $si => $sess): ?>
                                <div style="display:flex;align-items:flex-start;gap:12px;padding:8px 0;<?= $si > 0 ? 'border-top:1px solid rgba(0,212,170,0.08)' : '' ?>">
                                    <div style="min-width:24px;text-align:center;font-family:var(--mono);font-size:11px;color:var(--muted);padding-top:2px">#<?= $si + 1 ?></div>
                                    <div style="flex:1">
                                        <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
                                            <span style="font-family:var(--mono);font-size:12px;color:#10b981">
                                                <?= $si > 0 ? '🔄 Reconnected' : '🟢 Connected' ?>: <?= date('g:i A', strtotime($sess['check_in'])) ?>
                                            </span>
                                            <?php if ($sess['check_out']): ?>
                                                <span style="font-family:var(--mono);font-size:12px;color:#f43f5e">
                                                    🔴 Disconnected: <?= date('g:i A', strtotime($sess['check_out'])) ?>
                                                </span>
                                                <span style="font-family:var(--mono);font-size:11px;color:var(--muted)">
                                                    (<?= formatDuration((int)$sess['duration_minutes']) ?>)
                                                </span>
                                            <?php else: ?>
                                                <span style="font-size:12px;color:var(--accent)">● Still connected</span>
                                            <?php endif; ?>
                                            <span style="font-size:11px;color:var(--muted)">
                                                <?= e($sess['device_label'] ?? 'Device') ?> · <?= e($sess['zone_name'] ?? '—') ?>
                                            </span>
                                        </div>
                                        <?php $reconnects = json_decode($sess['reconnect_log'] ?? '[]', true) ?: []; ?>
                                        <?php if (!empty($reconnects)): ?>
                                            <div style="margin-top:6px;padding-left:8px;border-left:2px solid rgba(245,158,11,0.3)">
                                                <?php foreach ($reconnects as $rc): ?>
                                                <div style="font-size:11px;color:#f59e0b;padding:2px 0;font-family:var(--mono)">
                                                    ⚡ Disconnected: <?= e($rc['out']) ?> → Reconnected: <?= e($rc['in']) ?>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Live Update Script -->
<script>
// Toggle session timeline visibility
function toggleSessions(staffId) {
    var row = document.getElementById('sessions-' + staffId);
    if (row) {
        row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
    }
}

// Update clock every second
function updateClock() {
    const now = new Date();
    let hours = now.getHours();
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12 || 12;
    const minutes = String(now.getMinutes()).padStart(2, '0');
    document.getElementById('current-time').textContent = `${hours}:${minutes} ${ampm}`;
}

updateClock();
setInterval(updateClock, 1000);

// Auto-refresh dashboard every 60 seconds
setTimeout(() => location.reload(), 60000);
</script>

<?php include 'includes/footer.php'; ?>
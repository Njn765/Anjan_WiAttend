<?php
// ============================================================
//  includes/header.php  —  Shared nav + styles
//  Usage: include it at the top of every page AFTER starting
//         the session and requiring login.
//  Pass $pageTitle = "Page Name" before including.
// ============================================================
$pageTitle = $pageTitle ?? 'Wi-Attend';
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — Wi-Attend</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=Inter:wght@300;400;500;600&family=Syne:wght@700;800&display=swap" rel="stylesheet">
    <style>
        /* ── Variables ──────────────────────────────── */
        :root {
            --bg:       #0b0f1a;
            --sidebar:  #111827;
            --card:     #1a2235;
            --border:   #243050;
            --text:     #dce4f0;
            --muted:    #6b7a99;
            --accent:   #00d4aa;
            --blue:     #3b82f6;
            --danger:   #f43f5e;
            --warning:  #f59e0b;
            --success:  #10b981;
            --cyan:     #38bdf8;
            --mono:     'IBM Plex Mono', monospace;
            --sans:     'Inter', sans-serif;
        }

        /* ── Reset ──────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { display: flex; min-height: 100vh; background: var(--bg); color: var(--text); font-family: var(--sans); font-size: 14px; }
        a { color: var(--accent); text-decoration: none; }

        /* ── Sidebar ────────────────────────────────── */
        .sidebar {
            width: 220px; min-height: 100vh; background: var(--sidebar);
            border-right: 1px solid var(--border); display: flex; flex-direction: column;
            position: fixed; top: 0; left: 0; z-index: 100;
        }
        .sidebar-logo {
            padding: 24px 20px 20px;
            border-bottom: 1px solid rgba(0, 212, 170, 0.15);
        }
        .sidebar-logo h1 {
            font-family: 'Courier New', 'Courier', monospace;
            font-size: 20px;
            font-weight: 900;
            letter-spacing: 2px;
            text-transform: uppercase;
            background: linear-gradient(135deg, #00d4aa 0%, #38bdf8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .sidebar-logo span {
            font-size: 10px; color: var(--accent); display: block; margin-top: 4px;
            letter-spacing: 3px; text-transform: uppercase; font-weight: 600;
            font-family: 'Courier New', monospace; opacity: 0.7;
        }
        .nav { padding: 16px 0; flex: 1; }
        .nav a {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 20px; color: var(--muted); text-decoration: none;
            font-size: 13px; font-weight: 500; border-left: 3px solid transparent;
            transition: all .2s ease;
        }
        .nav a:hover {
            color: var(--text); background: rgba(0, 212, 170, 0.04);
        }
        .nav a.active {
            color: var(--accent); border-left-color: var(--accent);
            background: rgba(0, 212, 170, 0.08);
            font-weight: 600;
        }
        .nav .nav-icon { font-size: 16px; width: 20px; text-align: center; }
        .nav-section {
            font-size: 10px; font-family: var(--mono); color: var(--muted);
            padding: 14px 20px 4px; letter-spacing: 1.5px; text-transform: uppercase;
        }
        .sidebar-footer {
            padding: 16px 20px; border-top: 1px solid rgba(0, 212, 170, 0.1);
            font-size: 12px; color: var(--muted);
        }
        .sidebar-footer a { color: var(--danger); text-decoration: none; font-weight: 600; }

        /* ── Main content ───────────────────────────── */
        .main { margin-left: 220px; flex: 1; padding: 32px; min-height: 100vh; }
        .page-header { margin-bottom: 28px; }
        .page-header h2 {
            font-size: 22px; font-weight: 700; color: var(--text);
            display: flex; align-items: center; gap: 8px;
        }
        .page-header p { color: var(--muted); font-size: 13px; margin-top: 4px; }

        /* ── Cards ──────────────────────────────────── */
        .card {
            background: var(--card); border: 1px solid var(--border);
            border-radius: 12px; padding: 24px; margin-bottom: 24px;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .card:hover {
            border-color: rgba(0, 212, 170, 0.2);
            box-shadow: 0 4px 20px rgba(0, 212, 170, 0.05);
        }
        .card-title {
            font-family: var(--mono); font-size: 12px; color: var(--accent);
            letter-spacing: 1px; text-transform: uppercase; margin-bottom: 18px;
            display: flex; align-items: center; gap: 8px;
        }

        /* ── Stat grid ──────────────────────────────── */
        .stats {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 16px; margin-bottom: 24px;
        }
        .stat {
            background: linear-gradient(135deg, rgba(0, 212, 170, 0.04) 0%, rgba(0, 170, 136, 0.04) 100%);
            border: 1px solid var(--border); border-radius: 12px;
            padding: 20px; position: relative; overflow: hidden;
            transition: all 0.3s ease;
        }
        .stat:hover {
            transform: translateY(-3px);
            border-color: rgba(0, 212, 170, 0.25);
            box-shadow: 0 8px 24px rgba(0, 212, 170, 0.08);
        }
        .stat::before {
            content: ''; position: absolute; top: 0; left: 0; width: 3px; height: 100%;
        }
        .stat.green::before  { background: linear-gradient(180deg, var(--success), var(--accent)); }
        .stat.blue::before   { background: linear-gradient(180deg, var(--blue), var(--cyan)); }
        .stat.orange::before { background: linear-gradient(180deg, var(--warning), #fbbf24); }
        .stat.red::before    { background: linear-gradient(180deg, var(--danger), #fb7185); }
        .stat-label {
            font-size: 11px; color: var(--muted); text-transform: uppercase;
            letter-spacing: 1px; margin-bottom: 8px; font-weight: 600;
        }
        .stat-value {
            font-family: var(--mono); font-size: 30px; font-weight: 600;
            background: linear-gradient(135deg, #00d4aa 0%, #10b981 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* ── Table ──────────────────────────────────── */
        table { width: 100%; border-collapse: collapse; }
        thead th {
            text-align: left; padding: 10px 14px; font-size: 11px;
            font-family: var(--mono); color: var(--muted); text-transform: uppercase;
            letter-spacing: 1px; border-bottom: 2px solid rgba(0, 212, 170, 0.15);
        }
        tbody tr {
            border-bottom: 1px solid rgba(36, 48, 80, 0.5);
            transition: all 0.2s ease;
        }
        tbody tr:hover {
            background: rgba(0, 212, 170, 0.04);
            border-bottom-color: rgba(0, 212, 170, 0.15);
        }
        tbody td { padding: 11px 14px; font-size: 13px; color: var(--text); }
        .mono { font-family: var(--mono); font-size: 12px; color: var(--accent); }

        /* ── Badges ─────────────────────────────────── */
        .badge {
            display: inline-block; padding: 3px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 600; font-family: var(--mono);
            border: 1px solid transparent;
        }
        .badge-green  { background: rgba(16,185,129,.12); color: var(--success); border-color: rgba(16,185,129,.2); }
        .badge-red    { background: rgba(244,63,94,.12);  color: var(--danger);  border-color: rgba(244,63,94,.2); }
        .badge-orange { background: rgba(245,158,11,.12); color: var(--warning); border-color: rgba(245,158,11,.2); }
        .badge-blue   { background: rgba(59,130,246,.12); color: var(--blue);    border-color: rgba(59,130,246,.2); }

        /* ── Forms ──────────────────────────────────── */
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label {
            font-size: 12px; color: var(--muted); font-weight: 600;
            letter-spacing: .5px; text-transform: uppercase;
        }
        input, select, textarea {
            background: var(--bg); border: 1px solid var(--border); border-radius: 8px;
            padding: 10px 14px; color: var(--text); font-size: 13px; font-family: var(--sans);
            outline: none; transition: border-color .2s ease, box-shadow .2s ease;
        }
        input:focus, select:focus, textarea:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(0, 212, 170, 0.1);
        }
        input::placeholder { color: var(--muted); }

        /* ── Buttons ────────────────────────────────── */
        .btn {
            display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px;
            border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;
            border: none; text-decoration: none; transition: all .2s ease;
        }
        .btn:hover { opacity: .9; transform: translateY(-1px); }
        .btn-primary {
            background: linear-gradient(135deg, var(--accent), var(--success));
            color: #0b0f1a;
            box-shadow: 0 2px 10px rgba(0, 212, 170, 0.25);
        }
        .btn-primary:hover {
            box-shadow: 0 4px 16px rgba(0, 212, 170, 0.35);
        }
        .btn-blue { background: var(--blue); color: #fff; }
        .btn-danger {
            background: rgba(244,63,94,.12); color: var(--danger);
            border: 1px solid rgba(244,63,94,.25);
        }
        .btn-danger:hover {
            background: rgba(244,63,94,.2); border-color: rgba(244,63,94,.4);
        }
        .btn-success {
            background: rgba(16,185,129,.12); color: var(--success);
            border: 1px solid rgba(16,185,129,.25);
        }
        .btn-success:hover {
            background: rgba(16,185,129,.2); border-color: rgba(16,185,129,.4);
        }
        .btn-ghost {
            background: transparent; border: 1px solid var(--border); color: var(--text);
        }
        .btn-ghost:hover { border-color: rgba(0, 212, 170, 0.3); color: var(--accent); }
        .btn-sm { padding: 5px 12px; font-size: 12px; }

        /* ── Alert messages ─────────────────────────── */
        .alert {
            padding: 14px 18px; border-radius: 10px; margin-bottom: 20px;
            font-size: 13px; border-left: 4px solid;
        }
        .alert-success { background: rgba(16,185,129,.08);  border-color: var(--success); color: var(--success); }
        .alert-error   { background: rgba(244,63,94,.08);   border-color: var(--danger);  color: var(--danger);  }
        .alert-info    { background: rgba(59,130,246,.08);  border-color: var(--blue);    color: var(--blue);    }

        /* ── Pulse dot ──────────────────────────────── */
        .pulse { display: inline-flex; align-items: center; gap: 6px; }
        .pulse-dot {
            width: 8px; height: 8px; border-radius: 50%; background: var(--success);
            box-shadow: 0 0 8px rgba(16, 185, 129, 0.6);
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse { 0%,100% { opacity: 1; transform: scale(1); } 50% { opacity: .5; transform: scale(.8); } }

        /* ── Responsive ─────────────────────────────── */
        @media (max-width: 768px) {
            .sidebar { width: 60px; }
            .sidebar-logo h1, .sidebar-logo span, .nav-section,
            .sidebar-footer strong, .sidebar-footer br { display: none; }
            .sidebar-logo { padding: 16px 8px; text-align: center; }
            .nav a { padding: 12px 0; justify-content: center; border-left: none; border-bottom: 2px solid transparent; }
            .nav a.active { border-bottom-color: var(--accent); border-left-color: transparent; }
            .nav a span:last-child { display: none; }
            .main { margin-left: 60px; padding: 20px; }
            .stats { grid-template-columns: repeat(2, 1fr); }
            .form-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- ── Sidebar ──────────────────────────────────────────────── -->
<aside class="sidebar">
    <div class="sidebar-logo">
        <h1>Wi-Attend</h1>
        <span>Attendance System</span>
    </div>

    <nav class="nav">
        <div class="nav-section">Monitor</div>
        <a href="index.php"        class="<?= $currentPage === 'index.php'        ? 'active' : '' ?>">
            <span class="nav-icon">📡</span> Dashboard
        </a>
        <a href="scanner.php"      class="<?= $currentPage === 'scanner.php'      ? 'active' : '' ?>">
            <span class="nav-icon">🔍</span> Run Scanner
        </a>
        <a href="reports.php"      class="<?= $currentPage === 'reports.php'      ? 'active' : '' ?>">
            <span class="nav-icon">📋</span> Reports
        </a>

        <div class="nav-section">Manage</div>
        <a href="staff.php"        class="<?= $currentPage === 'staff.php'        ? 'active' : '' ?>">
            <span class="nav-icon">👥</span> Staff
        </a>
        <a href="schedules.php"    class="<?= $currentPage === 'schedules.php'    ? 'active' : '' ?>">
            <span class="nav-icon">📅</span> Schedules
        </a>
        <a href="mac_register.php" class="<?= $currentPage === 'mac_register.php' ? 'active' : '' ?>">
            <span class="nav-icon">💻</span> MAC Addresses
        </a>
        <a href="wifi_zones.php"   class="<?= $currentPage === 'wifi_zones.php'   ? 'active' : '' ?>">
            <span class="nav-icon">📶</span> Wi-Fi Zones
        </a>
    </nav>

    <div class="sidebar-footer">
        Logged in as <strong><?= e($_SESSION['admin_username'] ?? 'Admin') ?></strong><br>
        <a href="logout.php">Sign out</a>
    </div>
</aside>

<!-- ── Page content starts here ─────────────────────────────── -->
<main class="main">
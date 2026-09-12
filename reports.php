<?php
// ============================================================
//  reports.php  —  View & filter attendance history
//  Staff-grouped view: one summary row per person, click the
//  name to expand a full session timeline (connect / disconnect
//  / reconnect / leave) with timeliness judgements.
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';
requireLogin();

// ── Timeliness helper (arrival) ───────────────────────────────
if (!function_exists('checkInStatus')) {
    function checkInStatus(string $checkIn, ?string $expectedIn, int $graceMinutes = 0): array {
        if (empty($expectedIn)) {
            return ['label' => 'No schedule', 'badge' => 'badge-gray'];
        }
        try {
            $actual = new DateTime($checkIn);
        } catch (Exception $e) {
            return ['label' => '—', 'badge' => 'badge-gray'];
        }
        $expected   = new DateTime($actual->format('Y-m-d') . ' ' . $expectedIn);
        $graceLimit = (clone $expected)->modify("+{$graceMinutes} minutes");

        if ($actual <= $expected) {
            $diff = $expected->getTimestamp() - $actual->getTimestamp();
            if ($diff >= 300) {
                $mins = (int) round($diff / 60);
                return ['label' => "Early ({$mins}m)", 'badge' => 'badge-blue'];
            }
            return ['label' => 'On time', 'badge' => 'badge-green'];
        }
        if ($actual <= $graceLimit) {
            return ['label' => 'On time', 'badge' => 'badge-green'];
        }
        $lateMins = (int) round(($actual->getTimestamp() - $expected->getTimestamp()) / 60);
        return ['label' => "Late ({$lateMins}m)", 'badge' => 'badge-orange'];
    }
}

// ── Timeliness helper (departure) ─────────────────────────────
if (!function_exists('checkOutStatus')) {
    function checkOutStatus(?string $checkOut, ?string $expectedOut): array {
        if (empty($checkOut)) {
            return ['label' => 'Still present', 'badge' => 'badge-green'];
        }
        if (empty($expectedOut)) {
            return ['label' => '', 'badge' => ''];
        }
        try {
            $actual = new DateTime($checkOut);
        } catch (Exception $e) {
            return ['label' => '', 'badge' => ''];
        }
        $expected = new DateTime($actual->format('Y-m-d') . ' ' . $expectedOut);
        $diff = $actual->getTimestamp() - $expected->getTimestamp(); // + = stayed longer
        $mins = (int) round(abs($diff) / 60);

        if ($mins < 5) {
            return ['label' => 'Left on time', 'badge' => 'badge-green'];
        }
        if ($diff < 0) {
            return ['label' => "Left early ({$mins}m)", 'badge' => 'badge-orange'];
        }
        return ['label' => "Overtime ({$mins}m)", 'badge' => 'badge-blue'];
    }
}

// Human gap between two datetimes, e.g. "1m", "2h 14m"
if (!function_exists('humanGap')) {
    function humanGap(string $from, string $to): string {
        $secs = max(0, strtotime($to) - strtotime($from));
        $mins = (int) round($secs / 60);
        if ($mins < 1)   return 'under 1m';
        if ($mins < 60)  return "{$mins}m";
        $h = intdiv($mins, 60); $m = $mins % 60;
        return $m ? "{$h}h {$m}m" : "{$h}h";
    }
}

// Real connected minutes for a session, computed from timestamps.
// For an open (still-connected) session, measures up to "now".
// This does NOT trust duration_minutes, which can be 0/stale.
if (!function_exists('sessionMinutes')) {
    function sessionMinutes(array $sess): int {
        $start = strtotime($sess['check_in']);
        if (!$start) return 0;
        $end = !empty($sess['check_out']) ? strtotime($sess['check_out']) : time();
        return (int) max(0, round(($end - $start) / 60));
    }
}

// ── Filters ───────────────────────────────────────────────────
$filterDate    = $_GET['date']     ?? date('Y-m-d');
$filterStaffId = (int)($_GET['staff_id'] ?? 0);
$filterStatus  = $_GET['status']   ?? '';

// ── Build query ───────────────────────────────────────────────
$conditions = ["DATE(al.check_in) = :date"];
$params     = [':date' => $filterDate];

if ($filterStaffId) {
    $conditions[] = "al.staff_id = :staff_id";
    $params[':staff_id'] = $filterStaffId;
}
if ($filterStatus) {
    $conditions[] = "al.status = :status";
    $params[':status'] = $filterStatus;
}
$where = implode(' AND ', $conditions);

// reconnections / reconnect_log may not exist on older schemas.
$hasReconnCols = false;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM attendance_logs")->fetchAll(PDO::FETCH_COLUMN);
    $hasReconnCols = in_array('reconnect_log', $cols, true);
} catch (Throwable $e) {
    $hasReconnCols = false;
}
$reconnSelect = $hasReconnCols
    ? "al.reconnections, al.reconnect_log,"
    : "0 AS reconnections, '[]' AS reconnect_log,";

$logsStmt = $pdo->prepare("
    SELECT al.log_id, al.staff_id, s.full_name, s.job_title,
           d.dept_name, m.mac_address, m.device_label,
           w.zone_name, al.check_in, al.check_out,
           al.duration_minutes, al.status,
           {$reconnSelect}
           sc.expected_in, sc.expected_out, sc.grace_minutes
    FROM   attendance_logs al
    JOIN   staff         s ON s.staff_id      = al.staff_id
    JOIN   mac_addresses m ON m.mac_id        = al.mac_id
    JOIN   wifi_zones    w ON w.wifi_zone_id  = al.wifi_zone_id
    LEFT JOIN departments d ON d.department_id = s.department_id
    LEFT JOIN staff_schedules sc ON sc.staff_id = al.staff_id
    WHERE  $where
    ORDER BY al.check_in ASC
");
$logsStmt->execute($params);
$logs = $logsStmt->fetchAll();

// ── Group sessions by staff member ────────────────────────────
$byStaff = [];
foreach ($logs as $log) {
    $sid = (int) $log['staff_id'];
    if (!isset($byStaff[$sid])) {
        $byStaff[$sid] = [
            'staff_id'  => $sid,
            'full_name' => $log['full_name'],
            'job_title' => $log['job_title'],
            'dept_name' => $log['dept_name'],
            'sessions'  => [],
        ];
    }
    $byStaff[$sid]['sessions'][] = $log;
}
uasort($byStaff, fn($a, $b) => strcasecmp($a['full_name'], $b['full_name']));

// ── Day summary counts (UNIQUE staff, not sessions) ────────────
// Count UNIQUE staff members by their actual presence state:
// - "Present" = has at least one open session today (status='present' AND check_out IS NULL)
// - "Left" = all sessions today are closed (latest session has check_out is NOT NULL)
$presentStaffCount = 0;
$leftStaffCount    = 0;
foreach ($byStaff as $person) {
    $hasOpenSession = false;
    foreach ($person['sessions'] as $sess) {
        if ($sess['status'] === 'present' && empty($sess['check_out'])) {
            $hasOpenSession = true;
            break;
        }
    }
    if ($hasOpenSession) {
        $presentStaffCount++;
    } else {
        $leftStaffCount++;
    }
}

$allStaff = $pdo->query("SELECT staff_id, full_name FROM staff WHERE is_active=1 ORDER BY full_name")->fetchAll();

$pageTitle = 'Reports';
include 'includes/header.php';
?>

<style>
/* ── Reports page — redesign (scoped) ─────────────────────── */
.rp-hero{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;
    margin-bottom:20px;padding:22px 24px;border-radius:16px;
    background:radial-gradient(1200px 220px at 0% 0%, rgba(0,212,170,.10), transparent 60%),
    linear-gradient(135deg, rgba(16,185,129,.06), rgba(56,189,248,.04));
    border:1px solid var(--border);position:relative;overflow:hidden;}
.rp-hero::after{content:"";position:absolute;right:-50px;top:-50px;width:200px;height:200px;border-radius:50%;
    background:radial-gradient(circle, rgba(0,212,170,.16), transparent 70%);filter:blur(8px);pointer-events:none;}
.rp-hero h2{margin:0 0 6px;font-size:22px;letter-spacing:.3px;display:flex;align-items:center;gap:10px}
.rp-hero p{margin:0;color:var(--muted);font-size:13px;max-width:560px}
.rp-chips{display:flex;gap:10px;flex-wrap:wrap}
.rp-chip{padding:10px 16px;border-radius:12px;border:1px solid var(--border);
    background:rgba(255,255,255,.02);text-align:center;min-width:88px;}
.rp-chip .n{font-size:22px;font-weight:700;font-family:var(--mono);line-height:1}
.rp-chip .l{margin-top:5px;font-size:10px;letter-spacing:1px;text-transform:uppercase;color:var(--muted)}
.rp-chip.green .n{color:#10b981}
.rp-chip.red .n{color:#f43f5e}
.rp-chip.blue .n{color:#38bdf8}

.rp-card{border:1px solid var(--border);border-radius:16px;padding:22px 24px;margin-bottom:20px;
    background:linear-gradient(180deg, rgba(255,255,255,.02), transparent);}
.rp-card-title{display:flex;align-items:center;gap:9px;font-size:15px;font-weight:700;margin-bottom:18px;flex-wrap:wrap}
.rp-card-title .ic{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;font-size:15px;
    background:rgba(0,212,170,.14);border:1px solid rgba(0,212,170,.3)}

/* staff summary rows */
.rep-list{display:flex;flex-direction:column;gap:10px}
.rep-row{border:1px solid var(--border);border-radius:12px;background:rgba(255,255,255,.02);overflow:hidden;
    transition:border-color .15s ease}
.rep-row:hover{border-color:rgba(0,212,170,.4)}
.rep-row.open{border-color:rgba(0,212,170,.55)}
.rep-head{display:flex;align-items:center;gap:14px;padding:14px 16px;cursor:pointer;user-select:none}
.rep-head:hover{background:rgba(0,212,170,.04)}
.rep-avatar{width:44px;height:44px;border-radius:12px;flex-shrink:0;display:grid;place-items:center;
    font-weight:700;font-size:16px;color:#04110d;
    background:linear-gradient(135deg,#00d4aa,#10b981);box-shadow:0 4px 14px rgba(0,212,170,.25)}
.rep-id{flex:1;min-width:0}
.rep-name{font-weight:600;font-size:14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.rep-sub{font-size:12px;color:var(--muted);margin-top:2px}
.rep-stats{display:flex;gap:20px;align-items:center;flex-shrink:0;flex-wrap:wrap}
.rep-stat{text-align:center}
.rep-stat .t{font-family:var(--mono);font-size:14px;font-weight:700;color:var(--text);line-height:1}
.rep-stat .k{font-size:9px;letter-spacing:.6px;text-transform:uppercase;color:var(--muted);margin-top:3px}
.rep-stat.in .t{color:#10b981}
.rep-stat.out .t{color:#f43f5e}
.rep-chev{color:var(--muted);transition:transform .2s ease;flex-shrink:0;font-size:13px}
.rep-row.open .rep-chev{transform:rotate(180deg)}
@media(max-width:760px){.rep-stat.dur,.rep-stat.sess{display:none}}

/* expanded timeline */
.rep-body{display:none;padding:6px 16px 18px 74px;border-top:1px solid var(--border);
    background:rgba(0,212,170,.015)}
.rep-row.open .rep-body{display:block}
.tl{position:relative;padding-left:22px}
.tl::before{content:"";position:absolute;left:6px;top:10px;bottom:10px;width:2px;background:var(--border)}
.tl-item{position:relative;padding:12px 0}
.tl-item:not(:last-child){border-bottom:1px dashed rgba(255,255,255,.05)}
.tl-dot{position:absolute;left:-22px;top:16px;width:12px;height:12px;border-radius:50%;
    border:2px solid var(--bg);box-shadow:0 0 0 1px var(--border)}
.tl-dot.on{background:#10b981}
.tl-sess{font-size:11px;color:var(--accent);font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px}
.tl-line{display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:12.5px;margin:3px 0}
.tl-line .mono{font-family:var(--mono)}
.tl-time{font-family:var(--mono);font-weight:600}
.tl-time.in{color:#10b981}
.tl-time.out{color:#f43f5e}
.tl-muted{color:var(--muted);font-size:12px}
.tl-gap{margin:4px 0 4px 2px;padding:4px 10px;border-radius:6px;display:inline-flex;align-items:center;gap:6px;
    font-size:11.5px;color:#f59e0b;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25)}
.tl-blip{font-size:11px;color:#f59e0b;font-family:var(--mono);margin:2px 0 2px 14px;
    padding-left:10px;border-left:2px solid rgba(245,158,11,.3)}
.tl-zone{font-size:11px;color:var(--muted)}
.rep-empty{text-align:center;color:var(--muted);padding:36px 20px;font-size:13px}
</style>

<div class="rp-hero">
    <div>
        <h2>📋 Attendance Reports</h2>
        <p>Pick a date and staff member, then click any name to expand their full connection timeline — arrivals, drops, reconnections, and departures with timeliness.</p>
    </div>
    <div class="rp-chips">
        <div class="rp-chip green"><div class="n"><?= $presentStaffCount ?></div><div class="l">Present</div></div>
        <div class="rp-chip red"><div class="n"><?= $leftStaffCount ?></div><div class="l">Left</div></div>
        <div class="rp-chip blue"><div class="n"><?= count($logs) ?></div><div class="l">Sessions</div></div>
    </div>
</div>

<!-- Filter Form -->
<div class="rp-card">
    <div class="rp-card-title"><span class="ic">🔎</span> Filter Records</div>
    <form method="GET" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
        <div class="form-group">
            <label>DATE</label>
            <input type="date" name="date" value="<?= e($filterDate) ?>">
        </div>
        <div class="form-group">
            <label>STAFF MEMBER</label>
            <select name="staff_id">
                <option value="">All Staff</option>
                <?php foreach ($allStaff as $s): ?>
                    <option value="<?= $s['staff_id'] ?>" <?= $filterStaffId == $s['staff_id'] ? 'selected' : '' ?>>
                        <?= e($s['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>STATUS</label>
            <select name="status">
                <option value="">All</option>
                <option value="present" <?= $filterStatus === 'present' ? 'selected' : '' ?>>Present</option>
                <option value="left"    <?= $filterStatus === 'left'    ? 'selected' : '' ?>>Left</option>
                <option value="absent"  <?= $filterStatus === 'absent'  ? 'selected' : '' ?>>Absent</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-bottom:1px">Filter</button>
        <a href="reports.php" class="btn btn-ghost" style="margin-bottom:1px">Reset</a>
    </form>
</div>

<!-- Staff-grouped report -->
<div class="rp-card">
    <div class="rp-card-title" style="margin-bottom:16px">
        <span class="ic">🗓️</span> Attendance — <?= date('d F Y', strtotime($filterDate)) ?>
        <span style="font-family:var(--mono);font-size:12px;color:var(--accent);background:rgba(0,212,170,.1);padding:2px 10px;border-radius:999px;border:1px solid rgba(0,212,170,.25)"><?= count($byStaff) ?> staff · <?= count($logs) ?> sessions</span>
    </div>

    <?php if (empty($byStaff)): ?>
        <div class="rep-empty">No attendance records found for this date / filter.</div>
    <?php else: ?>
        <div class="rep-list">
            <?php foreach ($byStaff as $person): ?>
                <?php
                    $sessions = $person['sessions'];
                    $first    = $sessions[0];
                    $last     = end($sessions);
                    reset($sessions);

                    $firstIn   = $first['check_in'];
                    $anyOpen   = false;
                    $totalMins = 0;
                    foreach ($sessions as $s) {
                        if (empty($s['check_out'])) $anyOpen = true;
                        $totalMins += sessionMinutes($s);
                    }
                    $lastOut  = $anyOpen ? null : $last['check_out'];
                    $initial  = strtoupper(substr(trim($person['full_name']), 0, 1));
                    $statusBadge = $anyOpen ? 'badge-green' : 'badge-red';
                    $statusText  = $anyOpen ? 'Present' : 'Left';
                ?>
                <div class="rep-row" id="rep-<?= $person['staff_id'] ?>">
                    <div class="rep-head" onclick="toggleRep(<?= $person['staff_id'] ?>)">
                        <div class="rep-avatar"><?= e($initial) ?></div>
                        <div class="rep-id">
                            <div class="rep-name">
                                <?= e($person['full_name']) ?>
                                <span class="badge <?= $statusBadge ?>"><?= $statusText ?></span>
                            </div>
                            <div class="rep-sub">
                                <?= e($person['dept_name'] ?? 'No department') ?><?= $person['job_title'] ? ' · ' . e($person['job_title']) : '' ?>
                            </div>
                        </div>
                        <div class="rep-stats">
                            <div class="rep-stat in">
                                <div class="t"><?= date('H:i', strtotime($firstIn)) ?></div>
                                <div class="k">First in</div>
                            </div>
                            <div class="rep-stat out">
                                <div class="t"><?= $lastOut ? date('H:i', strtotime($lastOut)) : '—' ?></div>
                                <div class="k">Last out</div>
                            </div>
                            <div class="rep-stat dur">
                                <div class="t"><?= formatDuration($totalMins) ?></div>
                                <div class="k">On site</div>
                            </div>
                            <div class="rep-stat sess">
                                <div class="t"><?= count($sessions) ?></div>
                                <div class="k">Sessions</div>
                            </div>
                        </div>
                        <span class="rep-chev">▾</span>
                    </div>

                    <div class="rep-body">
                        <div class="tl">
                            <?php foreach ($sessions as $si => $sess): ?>
                                <?php
                                    if ($si > 0) {
                                        $prev = $sessions[$si - 1];
                                        if (!empty($prev['check_out'])) {
                                            $gap = humanGap($prev['check_out'], $sess['check_in']);
                                            echo '<div class="tl-gap">🔌 Offline for ' . e($gap)
                                               . ' &nbsp;·&nbsp; reconnected ' . date('g:i A', strtotime($sess['check_in'])) . '</div>';
                                        }
                                    }
                                    $isRecon    = $si > 0;
                                    $isLastSess = ($si === count($sessions) - 1);
                                    // Arrival timeliness only makes sense on the FIRST arrival of the day.
                                    $inStat = $isRecon
                                        ? null
                                        : checkInStatus($sess['check_in'], $sess['expected_in'], (int)($sess['grace_minutes'] ?? 0));
                                    $sessMins = sessionMinutes($sess);
                                ?>
                                <div class="tl-item">
                                    <span class="tl-dot on"></span>
                                    <div class="tl-sess">Session #<?= $si + 1 ?><?= $isRecon ? ' · Reconnection' : '' ?></div>

                                    <div class="tl-line">
                                        <span><?= $isRecon ? '🔄 Reconnected' : '🟢 Connected' ?>:</span>
                                        <span class="tl-time in"><?= date('g:i A', strtotime($sess['check_in'])) ?></span>
                                        <?php if ($inStat): ?>
                                            <span class="badge <?= $inStat['badge'] ?>"><?= e($inStat['label']) ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <?php $blips = json_decode($sess['reconnect_log'] ?? '[]', true) ?: []; ?>
                                    <?php foreach ($blips as $rc): ?>
                                        <div class="tl-blip">⚡ Brief drop: <?= e($rc['out'] ?? '?') ?> → back <?= e($rc['in'] ?? '?') ?></div>
                                    <?php endforeach; ?>

                                    <div class="tl-line">
                                        <?php if (!empty($sess['check_out'])): ?>
                                            <?php
                                                // Departure timeliness only on the FINAL session — leaving
                                                // mid-day just to reconnect isn't "leaving early".
                                                $outStat = $isLastSess
                                                    ? checkOutStatus($sess['check_out'], $sess['expected_out'] ?? null)
                                                    : ['label' => '', 'badge' => ''];
                                            ?>
                                            <span>🔴 Disconnected:</span>
                                            <span class="tl-time out"><?= date('g:i A', strtotime($sess['check_out'])) ?></span>
                                            <span class="tl-muted">(<?= formatDuration($sessMins) ?> connected)</span>
                                            <?php if (!empty($outStat['label'])): ?>
                                                <span class="badge <?= $outStat['badge'] ?>"><?= e($outStat['label']) ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span>🟢 <span class="tl-time in">Still connected</span></span>
                                            <span class="tl-muted">(<?= formatDuration($sessMins) ?> so far)</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="tl-line">
                                        <span class="tl-zone">📍 <?= e($sess['zone_name'] ?? '—') ?> · <?= e($sess['device_label'] ?: 'Device') ?> · <span class="mono"><?= e($sess['mac_address']) ?></span></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function toggleRep(id){
    document.getElementById('rep-' + id).classList.toggle('open');
}
</script>

<?php include 'includes/footer.php'; ?>
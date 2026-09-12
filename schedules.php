<?php
// ============================================================
//  schedules.php  —  Manage staff work schedules
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';
requireLogin();

$msg     = '';
$msgType = 'success';
$editing = null; // holds the schedule row being edited

// ── Handle Edit: load existing schedule into form ─────────────
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt   = $pdo->prepare("
        SELECT ss.*, s.full_name
        FROM   staff_schedules ss
        JOIN   staff s ON s.staff_id = ss.staff_id
        WHERE  ss.schedule_id = ?
    ");
    $stmt->execute([$editId]);
    $editing = $stmt->fetch();
}

// ── Add or Update schedule ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $scheduleId  = (int) ($_POST['schedule_id']  ?? 0); // 0 = new
    $staffId     = (int) ($_POST['staff_id']     ?? 0);
    $expectedIn  = trim($_POST['expected_in']    ?? '');
    $expectedOut = trim($_POST['expected_out']   ?? '');
    $graceMins   = (int) ($_POST['grace_minutes'] ?? 15);
    $isActive    = isset($_POST['is_active']) ? 1 : 0;

    if (!$staffId) {
        $msg = 'Please select a staff member.';
        $msgType = 'error';
    } elseif (!$expectedIn) {
        $msg = 'Expected arrival time is required.';
        $msgType = 'error';
    } elseif (!$expectedOut) {
        $msg = 'Expected departure time is required.';
        $msgType = 'error';
    } else {
        try {
            if ($scheduleId > 0) {
                // ── UPDATE existing record directly by schedule_id ──
                $pdo->prepare("
                    UPDATE staff_schedules
                    SET    expected_in   = ?,
                           expected_out  = ?,
                           grace_minutes = ?,
                           is_active     = ?
                    WHERE  schedule_id   = ?
                ")->execute([$expectedIn, $expectedOut, $graceMins, $isActive, $scheduleId]);
                $msg = 'Schedule updated successfully.';
            } else {
                // ── INSERT or UPDATE by staff_id (new schedule) ──
                $checkStmt = $pdo->prepare("SELECT schedule_id FROM staff_schedules WHERE staff_id = ?");
                $checkStmt->execute([$staffId]);
                $existing = $checkStmt->fetch();

                if ($existing) {
                    $pdo->prepare("
                        UPDATE staff_schedules
                        SET    expected_in   = ?,
                               expected_out  = ?,
                               grace_minutes = ?,
                               is_active     = ?
                        WHERE  staff_id      = ?
                    ")->execute([$expectedIn, $expectedOut, $graceMins, $isActive, $staffId]);
                    $msg = 'Schedule updated successfully.';
                } else {
                    $pdo->prepare("
                        INSERT INTO staff_schedules
                               (staff_id, expected_in, expected_out, grace_minutes, is_active)
                        VALUES (?, ?, ?, ?, ?)
                    ")->execute([$staffId, $expectedIn, $expectedOut, $graceMins, $isActive]);
                    $msg = 'Schedule created successfully.';
                }
            }
        } catch (PDOException $e) {
            $msg     = 'Error: ' . $e->getMessage();
            $msgType = 'error';
        }
    }

    // After save, clear editing state and reload page cleanly
    if ($msgType === 'success') {
        header('Location: schedules.php?msg=' . urlencode($msg));
        exit;
    }
}

// ── Delete schedule ───────────────────────────────────────────
if (isset($_GET['delete'])) {
    try {
        $pdo->prepare("DELETE FROM staff_schedules WHERE schedule_id = ?")
            ->execute([(int) $_GET['delete']]);
        header('Location: schedules.php?msg=deleted');
        exit;
    } catch (PDOException $e) {
        $msg     = 'Error deleting schedule: ' . $e->getMessage();
        $msgType = 'error';
    }
}

// ── Flash message from redirect ───────────────────────────────
if (isset($_GET['msg']) && !$msg) {
    $msg = ($_GET['msg'] === 'deleted') ? 'Schedule deleted successfully.' : htmlspecialchars($_GET['msg']);
}

// ── Fetch data ────────────────────────────────────────────────
$allStaff = $pdo->query("
    SELECT s.staff_id, s.full_name, d.dept_name
    FROM   staff s
    LEFT JOIN departments d ON d.department_id = s.department_id
    WHERE  s.is_active = 1
    ORDER BY s.full_name
")->fetchAll();

$schedules = $pdo->query("
    SELECT ss.*, s.full_name, d.dept_name
    FROM   staff_schedules ss
    JOIN   staff s ON s.staff_id = ss.staff_id
    LEFT JOIN departments d ON d.department_id = s.department_id
    ORDER BY s.full_name
")->fetchAll();

$pageTitle = 'Schedules';

// Summary figures for header chips
$totalSchedules  = count($schedules);
$activeSchedules = 0;
foreach ($schedules as $__sc) { if (!empty($__sc['is_active'])) $activeSchedules++; }
$staffCount = count($allStaff);

include 'includes/header.php';
?>

<style>
/* ── Schedules page — redesign (scoped) ───────────────────── */
.sc-hero{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;
    margin-bottom:20px;padding:22px 24px;border-radius:16px;
    background:radial-gradient(1200px 220px at 0% 0%, rgba(0,212,170,.10), transparent 60%),
    linear-gradient(135deg, rgba(16,185,129,.06), rgba(56,189,248,.04));
    border:1px solid var(--border);position:relative;overflow:hidden;}
.sc-hero::after{content:"";position:absolute;right:-50px;top:-50px;width:200px;height:200px;border-radius:50%;
    background:radial-gradient(circle, rgba(0,212,170,.16), transparent 70%);filter:blur(8px);pointer-events:none;}
.sc-hero h2{margin:0 0 6px;font-size:22px;letter-spacing:.3px;display:flex;align-items:center;gap:10px}
.sc-hero p{margin:0;color:var(--muted);font-size:13px;max-width:520px}
.sc-chips{display:flex;gap:10px;flex-wrap:wrap}
.sc-chip{padding:10px 16px;border-radius:12px;border:1px solid var(--border);
    background:rgba(255,255,255,.02);text-align:center;min-width:92px;}
.sc-chip .n{font-size:22px;font-weight:700;font-family:var(--mono);line-height:1}
.sc-chip .l{margin-top:5px;font-size:10px;letter-spacing:1px;text-transform:uppercase;color:var(--muted)}
.sc-chip.accent .n{color:var(--accent)}
.sc-chip.green .n{color:#10b981}
.sc-chip.blue .n{color:#38bdf8}

/* collapsible info */
.sc-help{border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:20px;
    background:radial-gradient(900px 160px at 0% 0%, rgba(56,189,248,.07), transparent 60%);}
.sc-help summary{display:flex;align-items:center;gap:10px;padding:14px 18px;cursor:pointer;
    list-style:none;user-select:none;transition:background .15s ease}
.sc-help summary::-webkit-details-marker{display:none}
.sc-help summary:hover{background:rgba(56,189,248,.05)}
.sc-help[open] summary{border-bottom:1px solid var(--border)}
.sc-help .ic{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;flex-shrink:0;
    background:rgba(56,189,248,.14);border:1px solid rgba(56,189,248,.3);font-size:14px}
.sc-help h3{margin:0;font-size:14px}
.sc-help .chev{margin-left:auto;color:var(--muted);transition:transform .2s ease}
.sc-help[open] .chev{transform:rotate(180deg)}
.sc-help-body{padding:16px 18px;font-size:13px;color:var(--muted);line-height:1.9}
.sc-help-body b{color:var(--text)}

.sc-card{border:1px solid var(--border);border-radius:16px;padding:22px 24px;margin-bottom:20px;
    background:linear-gradient(180deg, rgba(255,255,255,.02), transparent);}
.sc-card.editing{border-color:#f59e0b;box-shadow:0 0 0 1px rgba(245,158,11,.25)}
.sc-card-title{display:flex;align-items:center;gap:9px;font-size:15px;font-weight:700;margin-bottom:18px;flex-wrap:wrap}
.sc-card-title .ic{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;font-size:15px;
    background:rgba(0,212,170,.14);border:1px solid rgba(0,212,170,.3)}
.sc-card-title.edit .ic{background:rgba(245,158,11,.14);border-color:rgba(245,158,11,.35)}

/* schedule list */
.sched-list{display:flex;flex-direction:column;gap:10px}
.sched-row{display:flex;align-items:center;gap:14px;padding:14px 16px;border-radius:12px;
    border:1px solid var(--border);background:rgba(255,255,255,.02);
    transition:border-color .15s ease, transform .12s ease;}
.sched-row:hover{border-color:rgba(0,212,170,.4);transform:translateX(2px)}
.sched-row.is-editing{border-color:#f59e0b;background:rgba(245,158,11,.06)}
.sched-avatar{width:46px;height:46px;border-radius:13px;flex-shrink:0;display:grid;place-items:center;
    font-weight:700;font-size:17px;color:#04110d;
    background:linear-gradient(135deg,#00d4aa,#10b981);box-shadow:0 4px 14px rgba(0,212,170,.25)}
.sched-main{flex:1;min-width:0}
.sched-name{font-weight:600;font-size:14px;margin-bottom:4px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.sched-dept{font-size:12px;color:var(--muted)}
.sched-times{display:flex;align-items:center;gap:16px;flex-shrink:0;flex-wrap:wrap}
.sched-time{text-align:center}
.sched-time .t{font-family:var(--mono);font-size:15px;font-weight:700;color:#00d4aa;line-height:1}
.sched-time .k{font-size:9px;letter-spacing:.6px;text-transform:uppercase;color:var(--muted);margin-top:3px}
.sched-arrow{color:var(--muted);font-size:14px}
.sched-actions{display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap}
.sched-empty{text-align:center;color:var(--muted);padding:36px 20px;font-size:13px}
@media(max-width:720px){.sched-times{display:none}}
</style>

<div class="sc-hero">
    <div>
        <h2>⏰ Staff Schedules</h2>
        <p>Set expected arrival and departure times so the reports page can flag who was on time, early, or late.</p>
    </div>
    <div class="sc-chips">
        <div class="sc-chip accent"><div class="n"><?= (int)$totalSchedules ?></div><div class="l">Schedules</div></div>
        <div class="sc-chip green"><div class="n"><?= (int)$activeSchedules ?></div><div class="l">Active</div></div>
        <div class="sc-chip blue"><div class="n"><?= (int)$staffCount ?></div><div class="l">Staff</div></div>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert alert-<?= $msgType === 'error' ? 'error' : 'success' ?>"><?= e($msg) ?></div>
<?php endif; ?>

<!-- Info box (collapsible) -->
<details class="sc-help">
    <summary>
        <span class="ic">ℹ️</span>
        <h3>How Schedules Work</h3>
        <span class="chev">▾</span>
    </summary>
    <div class="sc-help-body">
        <b>Expected Arrival Time:</b> when the staff member should arrive (e.g. 09:00)<br>
        <b>Grace Period:</b> how many minutes late before they're marked "Late" (e.g. 15 mins)<br>
        <b>Expected Departure Time:</b> when they should leave (e.g. 17:00)
    </div>
</details>

<!-- Add / Edit Form -->
<div class="sc-card <?= $editing ? 'editing' : '' ?>">
    <div class="sc-card-title <?= $editing ? 'edit' : '' ?>">
        <span class="ic"><?= $editing ? '✏️' : '➕' ?></span>
        <?= $editing ? 'Editing Schedule — ' . e($editing['full_name']) : 'Set Work Schedule' ?>
        <?php if ($editing): ?>
            <a href="schedules.php" style="font-size:12px;margin-left:8px;color:var(--muted);font-weight:400">✕ Cancel edit</a>
        <?php endif; ?>
    </div>

    <form method="POST">
        <!-- Hidden: pass schedule_id when editing so we UPDATE the right row -->
        <?php if ($editing): ?>
            <input type="hidden" name="schedule_id" value="<?= $editing['schedule_id'] ?>">
        <?php endif; ?>

        <div class="form-grid">
            <div class="form-group">
                <label>STAFF MEMBER *</label>
                <select name="staff_id" required <?= $editing ? 'disabled' : '' ?>>
                    <option value="">— Select Staff —</option>
                    <?php foreach ($allStaff as $s): ?>
                        <option value="<?= $s['staff_id'] ?>"
                            <?= ($editing && $editing['staff_id'] == $s['staff_id']) ? 'selected' : '' ?>>
                            <?= e($s['full_name']) ?> (<?= e($s['dept_name'] ?? 'No Dept') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($editing): ?>
                    <!-- Send staff_id even though select is disabled -->
                    <input type="hidden" name="staff_id" value="<?= $editing['staff_id'] ?>">
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>EXPECTED ARRIVAL TIME *</label>
                <input type="time" name="expected_in" required
                       value="<?= $editing ? e(substr($editing['expected_in'], 0, 5)) : '' ?>">
            </div>

            <div class="form-group">
                <label>EXPECTED DEPARTURE TIME *</label>
                <input type="time" name="expected_out" required
                       value="<?= $editing ? e(substr($editing['expected_out'], 0, 5)) : '' ?>">
            </div>

            <div class="form-group">
                <label>GRACE PERIOD (minutes)</label>
                <input type="number" name="grace_minutes" min="0" max="120"
                       value="<?= $editing ? (int)$editing['grace_minutes'] : 15 ?>">
            </div>
        </div>

        <div style="margin-top:18px;display:flex;align-items:center;gap:12px">
            <label style="display:flex;align-items:center;gap:8px;color:var(--text);cursor:pointer;margin:0">
                <input type="checkbox" name="is_active" value="1"
                       <?= (!$editing || $editing['is_active']) ? 'checked' : '' ?>>
                Active
            </label>
        </div>

        <div style="margin-top:18px;display:flex;gap:10px">
            <button type="submit" class="btn btn-primary">
                <?= $editing ? '💾 Save Changes' : 'Save Schedule' ?>
            </button>
            <?php if ($editing): ?>
                <a href="schedules.php" class="btn btn-ghost">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- All Schedules list -->
<div class="sc-card">
    <div class="sc-card-title" style="margin-bottom:16px">
        <span class="ic">🗓️</span> All Staff Schedules
        <span style="font-family:var(--mono);font-size:12px;color:var(--accent);background:rgba(0,212,170,.1);padding:2px 10px;border-radius:999px;border:1px solid rgba(0,212,170,.25)"><?= count($schedules) ?></span>
    </div>

    <?php if (empty($schedules)): ?>
        <div class="sched-empty">No schedules set yet. Create one using the form above.</div>
    <?php else: ?>
        <div class="sched-list">
            <?php foreach ($schedules as $sch): ?>
                <?php
                    $isEditRow = ($editing && $editing['schedule_id'] == $sch['schedule_id']);
                    $initial   = strtoupper(substr(trim($sch['full_name']), 0, 1));
                ?>
                <div class="sched-row <?= $isEditRow ? 'is-editing' : '' ?>">
                    <div class="sched-avatar"><?= e($initial) ?></div>
                    <div class="sched-main">
                        <div class="sched-name">
                            <?= e($sch['full_name']) ?>
                            <span class="badge <?= $sch['is_active'] ? 'badge-green' : 'badge-red' ?>"><?= $sch['is_active'] ? 'Active' : 'Inactive' ?></span>
                            <span class="badge badge-blue"><?= (int)$sch['grace_minutes'] ?> min grace</span>
                        </div>
                        <div class="sched-dept"><?= e($sch['dept_name'] ?? 'No department') ?></div>
                    </div>
                    <div class="sched-times">
                        <div class="sched-time">
                            <div class="t"><?= date('H:i', strtotime($sch['expected_in'])) ?></div>
                            <div class="k">Arrive</div>
                        </div>
                        <span class="sched-arrow">→</span>
                        <div class="sched-time">
                            <div class="t"><?= date('H:i', strtotime($sch['expected_out'])) ?></div>
                            <div class="k">Leave</div>
                        </div>
                    </div>
                    <div class="sched-actions">
                        <a href="schedules.php?edit=<?= $sch['schedule_id'] ?>" class="btn btn-info btn-sm">✏️ Edit</a>
                        <a href="schedules.php?delete=<?= $sch['schedule_id'] ?>" class="btn btn-danger btn-sm"
                           onclick="return confirm('Delete schedule for <?= e($sch['full_name']) ?>?')">Delete</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
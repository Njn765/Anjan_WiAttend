<?php
// ============================================================
//  staff.php  —  Add & manage staff members
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';
requireLogin();

$msg = '';
$msgType = 'success';

// ── Toggle active/inactive ────────────────────────────────────
if (isset($_GET['toggle'])) {
    $id = (int) $_GET['toggle'];
    $pdo->prepare("UPDATE staff SET is_active = 1 - is_active WHERE staff_id = ?")
        ->execute([$id]);
    header('Location: staff.php?msg=updated');
    exit;
}

// ── Delete staff ──────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $pdo->prepare("DELETE FROM staff WHERE staff_id = ?")->execute([$id]);
    header('Location: staff.php?msg=deleted');
    exit;
}

// ── Add new staff ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['full_name']     ?? '');
    $email = trim($_POST['email']         ?? '');
    $phone = trim($_POST['phone']         ?? '');
    $dept  = (int) ($_POST['department_id'] ?? 0);
    $title = trim($_POST['job_title']     ?? '');

    if (!$name) {
        $msg = 'Full name is required.'; $msgType = 'error';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO staff (full_name, email, phone, department_id, job_title)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $email ?: null, $phone ?: null, $dept ?: null, $title ?: null]);
            $msg = "Staff member \"$name\" added successfully.";
        } catch (PDOException $e) {
            $msg = 'Error: ' . $e->getMessage(); $msgType = 'error';
        }
    }
}

if (isset($_GET['msg'])) {
    $msg = match($_GET['msg']) {
        'updated' => 'Staff status updated.',
        'deleted' => 'Staff member deleted.',
        default   => ''
    };
}

// ── Fetch all staff ───────────────────────────────────────────
$allStaff = $pdo->query("
    SELECT s.*, d.dept_name,
           COUNT(m.mac_id) AS mac_count
    FROM   staff s
    LEFT JOIN departments  d ON d.department_id = s.department_id
    LEFT JOIN mac_addresses m ON m.staff_id     = s.staff_id
    GROUP BY s.staff_id
    ORDER BY s.is_active DESC, s.full_name
")->fetchAll();

$departments = $pdo->query("SELECT * FROM departments ORDER BY dept_name")->fetchAll();

$pageTitle = 'Staff';

// Summary figures for header chips
$totalStaff  = count($allStaff);
$activeStaff = 0;
$withMacs    = 0;
foreach ($allStaff as $__s) {
    if (!empty($__s['is_active'])) $activeStaff++;
    if (($__s['mac_count'] ?? 0) > 0) $withMacs++;
}

include 'includes/header.php';
?>

<style>
/* ── Staff page — redesign (scoped) ───────────────────────── */
.st-hero{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;
    margin-bottom:20px;padding:22px 24px;border-radius:16px;
    background:radial-gradient(1200px 220px at 0% 0%, rgba(0,212,170,.10), transparent 60%),
    linear-gradient(135deg, rgba(16,185,129,.06), rgba(56,189,248,.04));
    border:1px solid var(--border);position:relative;overflow:hidden;}
.st-hero::after{content:"";position:absolute;right:-50px;top:-50px;width:200px;height:200px;border-radius:50%;
    background:radial-gradient(circle, rgba(0,212,170,.16), transparent 70%);filter:blur(8px);pointer-events:none;}
.st-hero h2{margin:0 0 6px;font-size:22px;letter-spacing:.3px;display:flex;align-items:center;gap:10px}
.st-hero p{margin:0;color:var(--muted);font-size:13px;max-width:520px}
.st-chips{display:flex;gap:10px;flex-wrap:wrap}
.st-chip{padding:10px 16px;border-radius:12px;border:1px solid var(--border);
    background:rgba(255,255,255,.02);text-align:center;min-width:92px;}
.st-chip .n{font-size:22px;font-weight:700;font-family:var(--mono);line-height:1}
.st-chip .l{margin-top:5px;font-size:10px;letter-spacing:1px;text-transform:uppercase;color:var(--muted)}
.st-chip.accent .n{color:var(--accent)}
.st-chip.green .n{color:#10b981}
.st-chip.blue .n{color:#38bdf8}

.st-card{border:1px solid var(--border);border-radius:16px;padding:22px 24px;margin-bottom:20px;
    background:linear-gradient(180deg, rgba(255,255,255,.02), transparent);}
.st-card-title{display:flex;align-items:center;gap:9px;font-size:15px;font-weight:700;margin-bottom:18px}
.st-card-title .ic{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;
    background:rgba(0,212,170,.14);border:1px solid rgba(0,212,170,.3);font-size:15px}

/* staff list */
.staff-list{display:flex;flex-direction:column;gap:10px}
.staff-row{display:flex;align-items:center;gap:14px;padding:14px 16px;border-radius:12px;
    border:1px solid var(--border);background:rgba(255,255,255,.02);
    transition:border-color .15s ease, transform .12s ease;}
.staff-row:hover{border-color:rgba(0,212,170,.4);transform:translateX(2px)}
.staff-row.inactive{opacity:.62}
.staff-avatar{width:46px;height:46px;border-radius:13px;flex-shrink:0;display:grid;place-items:center;
    font-weight:700;font-size:17px;color:#04110d;
    background:linear-gradient(135deg,#00d4aa,#10b981);box-shadow:0 4px 14px rgba(0,212,170,.25)}
.staff-row.inactive .staff-avatar{background:linear-gradient(135deg,#475569,#334155);box-shadow:none;color:#cbd5e1}
.staff-main{flex:1;min-width:0}
.staff-name{font-weight:600;font-size:14px;margin-bottom:3px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.staff-sub{font-size:12px;color:var(--muted);display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.staff-sub .dept{color:var(--accent)}
.staff-right{display:flex;align-items:center;gap:10px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end}
.staff-empty{text-align:center;color:var(--muted);padding:36px 20px;font-size:13px}
@media(max-width:680px){.staff-sub .email{display:none}}
</style>

<div class="st-hero">
    <div>
        <h2>👥 Staff Management</h2>
        <p>Add staff members and manage their profiles, departments, and registered devices.</p>
    </div>
    <div class="st-chips">
        <div class="st-chip accent"><div class="n"><?= (int)$totalStaff ?></div><div class="l">Total</div></div>
        <div class="st-chip green"><div class="n"><?= (int)$activeStaff ?></div><div class="l">Active</div></div>
        <div class="st-chip blue"><div class="n"><?= (int)$withMacs ?></div><div class="l">With MACs</div></div>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert alert-<?= $msgType === 'error' ? 'error' : 'success' ?>"><?= e($msg) ?></div>
<?php endif; ?>

<!-- Add Staff Form -->
<div class="st-card">
    <div class="st-card-title"><span class="ic">➕</span> Add New Staff Member</div>
    <form method="POST">
        <div class="form-grid">
            <div class="form-group">
                <label>FULL NAME *</label>
                <input type="text" name="full_name" placeholder="e.g. Anjan Sharma" required>
            </div>
            <div class="form-group">
                <label>EMAIL</label>
                <input type="email" name="email" placeholder="anjan@company.com">
            </div>
            <div class="form-group">
                <label>PHONE</label>
                <input type="text" name="phone" placeholder="+977 98XXXXXXXX">
            </div>
            <div class="form-group">
                <label>DEPARTMENT</label>
                <select name="department_id">
                    <option value="">— Select Department —</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['department_id'] ?>"><?= e($dept['dept_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>JOB TITLE</label>
                <input type="text" name="job_title" placeholder="e.g. Software Engineer">
            </div>
        </div>
        <div style="margin-top:18px">
            <button type="submit" class="btn btn-primary">Add Staff Member</button>
        </div>
    </form>
</div>

<!-- Staff List -->
<div class="st-card">
    <div class="st-card-title" style="margin-bottom:16px">
        <span class="ic">👥</span> All Staff
        <span style="font-family:var(--mono);font-size:12px;color:var(--accent);background:rgba(0,212,170,.1);padding:2px 10px;border-radius:999px;border:1px solid rgba(0,212,170,.25)"><?= count($allStaff) ?></span>
    </div>

    <?php if (empty($allStaff)): ?>
        <div class="staff-empty">No staff added yet. Add your first member using the form above.</div>
    <?php else: ?>
        <div class="staff-list">
            <?php foreach ($allStaff as $s): ?>
                <?php $initial = strtoupper(substr(trim($s['full_name']), 0, 1)); ?>
                <div class="staff-row <?= $s['is_active'] ? '' : 'inactive' ?>">
                    <div class="staff-avatar"><?= e($initial) ?></div>
                    <div class="staff-main">
                        <div class="staff-name">
                            <?= e($s['full_name']) ?>
                            <span class="badge <?= $s['is_active'] ? 'badge-green' : 'badge-red' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span>
                            <span class="badge <?= $s['mac_count'] > 0 ? 'badge-green' : 'badge-red' ?>"><?= (int)$s['mac_count'] ?> MAC<?= $s['mac_count'] == 1 ? '' : 's' ?></span>
                        </div>
                        <div class="staff-sub">
                            <?php if (!empty($s['dept_name'])): ?><span class="dept"><?= e($s['dept_name']) ?></span><?php endif; ?>
                            <?php if (!empty($s['job_title'])): ?><span>· <?= e($s['job_title']) ?></span><?php endif; ?>
                            <?php if (!empty($s['email'])): ?><span class="email">· <?= e($s['email']) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="staff-right">
                        <a href="mac_register.php?staff_id=<?= $s['staff_id'] ?>" class="btn btn-blue btn-sm">MACs</a>
                        <a href="staff.php?toggle=<?= $s['staff_id'] ?>" class="btn btn-ghost btn-sm"
                           onclick="return confirm('Toggle status?')"><?= $s['is_active'] ? 'Disable' : 'Enable' ?></a>
                        <a href="staff.php?delete=<?= $s['staff_id'] ?>" class="btn btn-danger btn-sm"
                           onclick="return confirm('Delete <?= e($s['full_name']) ?>? This cannot be undone.')">Delete</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
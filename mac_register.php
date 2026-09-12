<?php
// ============================================================
//  mac_register.php  —  Register device MAC addresses for staff
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';
requireLogin();

$msg = '';
$msgType = 'success';
$filterStaff = isset($_GET['staff_id']) ? (int) $_GET['staff_id'] : null;

// ── Delete a MAC ──────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $pdo->prepare("DELETE FROM mac_addresses WHERE mac_id = ?")->execute([$id]);
    header('Location: mac_register.php' . ($filterStaff ? "?staff_id=$filterStaff" : ''));
    exit;
}

// ── Add new MAC ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $staffId = (int) ($_POST['staff_id']    ?? 0);
    $mac     = normaliseMAC($_POST['mac_address'] ?? '');
    $label   = trim($_POST['device_label']  ?? '');

    if (!$staffId) {
        $msg = 'Please select a staff member.'; $msgType = 'error';
    } elseif (!isValidMAC($mac)) {
        $msg = 'Invalid MAC address format. Use: aa:bb:cc:dd:ee:ff'; $msgType = 'error';
    } else {
        try {
            $pdo->prepare("
                INSERT INTO mac_addresses (staff_id, mac_address, device_label)
                VALUES (?, ?, ?)
            ")->execute([$staffId, $mac, $label ?: null]);
            $msg = "MAC address registered successfully.";
            $filterStaff = $filterStaff ?: $staffId;
        } catch (PDOException $e) {
            // Unique constraint violation = already registered
            if ($e->getCode() == 23000) {
                $msg = "This MAC address is already registered."; $msgType = 'error';
            } else {
                $msg = 'Error: ' . $e->getMessage(); $msgType = 'error';
            }
        }
    }
}

// ── Load data ─────────────────────────────────────────────────
$allStaff = $pdo->query("SELECT staff_id, full_name FROM staff WHERE is_active=1 ORDER BY full_name")->fetchAll();

$query = "
    SELECT m.*, s.full_name
    FROM   mac_addresses m
    JOIN   staff s ON s.staff_id = m.staff_id
";
if ($filterStaff) {
    $stmt = $pdo->prepare($query . " WHERE m.staff_id = ? ORDER BY m.registered_at DESC");
    $stmt->execute([$filterStaff]);
} else {
    $stmt = $pdo->query($query . " ORDER BY m.registered_at DESC");
}
$macs = $stmt->fetchAll();

$selectedStaff = $filterStaff
    ? $pdo->prepare("SELECT full_name FROM staff WHERE staff_id = ?")
    : null;
if ($selectedStaff) {
    $selectedStaff->execute([$filterStaff]);
    $selectedStaff = $selectedStaff->fetchColumn();
}

$pageTitle = 'MAC Addresses';

// Small summary figures for the header chips
$totalMacs   = count($macs);
$activeMacs  = 0;
foreach ($macs as $__m) { if (!empty($__m['is_active'])) $activeMacs++; }
$staffCount  = count($allStaff);

include 'includes/header.php';
?>

<style>
/* ── MAC Register page — full redesign (scoped) ───────────── */
.mr-hero{
    display:flex;align-items:flex-start;justify-content:space-between;
    gap:20px;flex-wrap:wrap;margin-bottom:20px;
    padding:22px 24px;border-radius:16px;
    background:
        radial-gradient(1200px 220px at 0% 0%, rgba(0,212,170,.10), transparent 60%),
        linear-gradient(135deg, rgba(16,185,129,.06), rgba(56,189,248,.04));
    border:1px solid var(--border);position:relative;overflow:hidden;
}
.mr-hero::after{
    content:"";position:absolute;right:-50px;top:-50px;width:200px;height:200px;
    border-radius:50%;background:radial-gradient(circle, rgba(0,212,170,.16), transparent 70%);
    filter:blur(8px);pointer-events:none;
}
.mr-hero h2{margin:0 0 6px;font-size:22px;letter-spacing:.3px;display:flex;align-items:center;gap:10px}
.mr-hero p{margin:0;color:var(--muted);font-size:13px;max-width:520px}
.mr-chips{display:flex;gap:10px;flex-wrap:wrap}
.mr-chip{
    padding:10px 16px;border-radius:12px;border:1px solid var(--border);
    background:rgba(255,255,255,.02);text-align:center;min-width:92px;
}
.mr-chip .n{font-size:22px;font-weight:700;font-family:var(--mono);line-height:1}
.mr-chip .l{margin-top:5px;font-size:10px;letter-spacing:1px;text-transform:uppercase;color:var(--muted)}
.mr-chip.green .n{color:#10b981}
.mr-chip.accent .n{color:var(--accent)}
.mr-chip.blue .n{color:#38bdf8}

/* info card (collapsible) */
.mac-help{border:1px solid var(--border);border-radius:16px;overflow:hidden;
    background:radial-gradient(900px 160px at 0% 0%, rgba(56,189,248,.08), transparent 60%),
    linear-gradient(135deg, rgba(56,189,248,.04), rgba(0,212,170,.03));margin-bottom:20px;}
.mac-help-head{display:flex;align-items:center;gap:10px;padding:16px 20px;
    cursor:pointer;list-style:none;user-select:none;
    transition:background .15s ease;}
.mac-help-head::-webkit-details-marker{display:none} /* hide default arrow */
.mac-help-head:hover{background:rgba(56,189,248,.05)}
.mac-help[open] .mac-help-head{border-bottom:1px solid var(--border);}
.mac-help-head .ic{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;
    background:rgba(56,189,248,.14);border:1px solid rgba(56,189,248,.3);font-size:16px;flex-shrink:0;}
.mac-help-head h3{margin:0;font-size:15px;letter-spacing:.2px}
.mac-help-head p{margin:2px 0 0;font-size:12px;color:var(--muted)}
.mac-help-head .chev{margin-left:auto;color:var(--muted);font-size:13px;
    transition:transform .2s ease;flex-shrink:0;}
.mac-help[open] .mac-help-head .chev{transform:rotate(180deg)}
.mac-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;padding:18px 20px;}
.mac-tile{display:flex;gap:12px;align-items:flex-start;padding:14px 16px;border-radius:12px;
    background:rgba(255,255,255,.02);border:1px solid var(--border);
    transition:border-color .15s ease, transform .15s ease;}
.mac-tile:hover{border-color:var(--accent);transform:translateY(-2px)}
.mac-tile .emoji{font-size:20px;line-height:1;margin-top:2px}
.mac-tile .body{flex:1;min-width:0}
.mac-tile .os{font-size:13px;font-weight:700;color:var(--text);margin-bottom:5px}
.mac-tile .step{font-size:12.5px;color:var(--muted);line-height:1.6}
.mac-tile code{font-family:var(--mono);color:var(--accent);background:var(--bg);padding:2px 7px;
    border-radius:5px;font-size:11.5px;border:1px solid var(--border);white-space:nowrap;}
.mac-note{margin:0 20px 18px;padding:12px 14px;border-radius:10px;display:flex;gap:10px;align-items:flex-start;
    background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.28);}
.mac-note .warn{font-size:16px;line-height:1.4}
.mac-note .t{font-size:12.5px;color:#f7c66b;line-height:1.6}
.mac-note .t b{color:#f59e0b}

/* form card */
.mr-card{border:1px solid var(--border);border-radius:16px;padding:22px 24px;margin-bottom:20px;
    background:linear-gradient(180deg, rgba(255,255,255,.02), transparent);}
.mr-card-title{display:flex;align-items:center;gap:9px;font-size:15px;font-weight:700;margin-bottom:18px}
.mr-card-title .ic{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;
    background:rgba(0,212,170,.14);border:1px solid rgba(0,212,170,.3);font-size:15px}

/* device list */
.dev-list{display:flex;flex-direction:column;gap:10px}
.dev-row{display:flex;align-items:center;gap:14px;padding:14px 16px;border-radius:12px;
    border:1px solid var(--border);background:rgba(255,255,255,.02);
    transition:border-color .15s ease, transform .12s ease;}
.dev-row:hover{border-color:rgba(0,212,170,.4);transform:translateX(2px)}
.dev-avatar{width:42px;height:42px;border-radius:12px;flex-shrink:0;display:grid;place-items:center;
    font-weight:700;font-size:16px;color:#04110d;
    background:linear-gradient(135deg,#00d4aa,#10b981);box-shadow:0 4px 14px rgba(0,212,170,.25)}
.dev-main{flex:1;min-width:0}
.dev-name{font-weight:600;font-size:14px;margin-bottom:3px}
.dev-meta{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.dev-mac{font-family:var(--mono);font-size:12px;color:var(--accent);background:var(--bg);
    padding:3px 9px;border-radius:6px;border:1px solid var(--border)}
.dev-label{font-size:12px;color:var(--muted)}
.dev-right{display:flex;align-items:center;gap:14px;flex-shrink:0}
.dev-date{font-size:11px;color:var(--muted);text-align:right;font-family:var(--mono)}
.dev-empty{text-align:center;color:var(--muted);padding:36px 20px;font-size:13px}
@media(max-width:640px){.dev-date{display:none}}
</style>

<div class="mr-hero">
    <div>
        <h2>💻 MAC Address Registration</h2>
        <p>Register staff devices so the scanner can identify them on the network by their Wi-Fi hardware address.</p>
    </div>
    <div class="mr-chips">
        <div class="mr-chip accent"><div class="n"><?= (int)$totalMacs ?></div><div class="l">Devices</div></div>
        <div class="mr-chip green"><div class="n"><?= (int)$activeMacs ?></div><div class="l">Active</div></div>
        <div class="mr-chip blue"><div class="n"><?= (int)$staffCount ?></div><div class="l">Staff</div></div>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert alert-<?= $msgType === 'error' ? 'error' : 'success' ?>"><?= e($msg) ?></div>
<?php endif; ?>

<!-- How to find your MAC (click to expand) -->
<details class="mac-help">
    <summary class="mac-help-head">
        <span class="ic">🔎</span>
        <div>
            <h3>How to Find a Device MAC Address</h3>
            <p>Click to see per-device steps for finding the Wi-Fi MAC.</p>
        </div>
        <span class="chev">▾</span>
    </summary>
    <div class="mac-grid">
        <div class="mac-tile">
            <span class="emoji">🪟</span>
            <div class="body"><div class="os">Windows</div>
                <div class="step">Run <code>ipconfig /all</code> and look for <b style="color:var(--text)">Physical Address</b> under your Wi-Fi adapter.</div></div>
        </div>
        <div class="mac-tile">
            <span class="emoji">🐧</span>
            <div class="body"><div class="os">Linux / macOS</div>
                <div class="step">Run <code>ip link</code> or <code>ifconfig</code> and read the <b style="color:var(--text)">ether</b> value.</div></div>
        </div>
        <div class="mac-tile">
            <span class="emoji">🤖</span>
            <div class="body"><div class="os">Android</div>
                <div class="step">Settings → About phone → Status → <b style="color:var(--text)">Wi-Fi MAC address</b>.</div></div>
        </div>
        <div class="mac-tile">
            <span class="emoji">📱</span>
            <div class="body"><div class="os">iPhone / iPad</div>
                <div class="step">Settings → General → About → <b style="color:var(--text)">Wi-Fi Address</b>.</div></div>
        </div>
    </div>
    <div class="mac-note">
        <span class="warn">⚠️</span>
        <div class="t"><b>Phones hide their real MAC by default.</b> On the Wi-Fi you'll use, turn off
            <b>Private Wi-Fi Address</b> (iPhone) or <b>Randomized MAC</b> (Android) for that network,
            then read the address above — otherwise the scanner sees a different MAC each time and can't match the device.</div>
    </div>
</details>

<!-- Register Form -->
<div class="mr-card">
    <div class="mr-card-title"><span class="ic">➕</span> Register New MAC Address</div>
    <form method="POST">
        <div class="form-grid">
            <div class="form-group">
                <label>STAFF MEMBER *</label>
                <select name="staff_id" required>
                    <option value="">— Select Staff —</option>
                    <?php foreach ($allStaff as $s): ?>
                        <option value="<?= $s['staff_id'] ?>"
                            <?= ($filterStaff == $s['staff_id'] || ($_POST['staff_id'] ?? '') == $s['staff_id']) ? 'selected' : '' ?>>
                            <?= e($s['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>MAC ADDRESS * (format: aa:bb:cc:dd:ee:ff)</label>
                <input type="text" name="mac_address"
                       placeholder="e.g. b8:27:eb:4a:3c:11"
                       value="<?= e($_POST['mac_address'] ?? '') ?>"
                       pattern="^([0-9a-fA-F]{2}[:\-]){5}[0-9a-fA-F]{2}$"
                       required>
            </div>
            <div class="form-group">
                <label>DEVICE LABEL (optional)</label>
                <input type="text" name="device_label"
                       placeholder="e.g. Work Laptop, iPhone"
                       value="<?= e($_POST['device_label'] ?? '') ?>">
            </div>
        </div>
        <div style="margin-top:18px">
            <button type="submit" class="btn btn-primary">Register MAC Address</button>
            <?php if ($filterStaff): ?>
                <a href="mac_register.php" class="btn btn-ghost" style="margin-left:8px">View All</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Registered MACs List -->
<div class="mr-card">
    <div class="mr-card-title" style="justify-content:space-between">
        <span style="display:flex;align-items:center;gap:9px">
            <span class="ic">📶</span>
            Registered Devices<?= $filterStaff ? " — " . e($selectedStaff) : '' ?>
            <span style="font-family:var(--mono);font-size:12px;color:var(--accent);background:rgba(0,212,170,.1);padding:2px 10px;border-radius:999px;border:1px solid rgba(0,212,170,.25)"><?= count($macs) ?></span>
        </span>
        <?php if ($filterStaff): ?>
            <a href="mac_register.php" style="color:var(--muted);font-size:13px;font-weight:400">← Show all</a>
        <?php endif; ?>
    </div>

    <?php if (empty($macs)): ?>
        <div class="dev-empty">No MAC addresses registered yet. Add one using the form above.</div>
    <?php else: ?>
        <div class="dev-list">
            <?php foreach ($macs as $m): ?>
                <?php $initial = strtoupper(substr(trim($m['full_name']), 0, 1)); ?>
                <div class="dev-row">
                    <div class="dev-avatar"><?= e($initial) ?></div>
                    <div class="dev-main">
                        <div class="dev-name"><?= e($m['full_name']) ?></div>
                        <div class="dev-meta">
                            <span class="dev-mac"><?= e($m['mac_address']) ?></span>
                            <?php if (!empty($m['device_label'])): ?>
                                <span class="dev-label">· <?= e($m['device_label']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="dev-right">
                        <span class="badge <?= $m['is_active'] ? 'badge-green' : 'badge-red' ?>">
                            <?= $m['is_active'] ? 'Active' : 'Disabled' ?>
                        </span>
                        <span class="dev-date"><?= date('d M Y', strtotime($m['registered_at'])) ?></span>
                        <a href="mac_register.php?delete=<?= $m['mac_id'] ?><?= $filterStaff ? "&staff_id=$filterStaff" : '' ?>"
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('Remove this MAC address?')">Remove</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
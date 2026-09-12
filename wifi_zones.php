<?php
// ============================================================
//  wifi_zones.php  —  Manage Wi-Fi access point zones
// ============================================================
session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';
requireLogin();

$msg = ''; $msgType = 'success';

// ── Delete zone ───────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM wifi_zones WHERE wifi_zone_id = ?")->execute([(int)$_GET['delete']]);
    header('Location: wifi_zones.php?msg=deleted'); exit;
}

// ── Add zone ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['zone_name']    ?? '');
    $rMac    = normaliseMAC($_POST['router_mac'] ?? '');
    $ssid    = trim($_POST['ssid']         ?? '');
    $locDesc = trim($_POST['location_desc']?? '');

    if (!$name || !$ssid) {
        $msg = 'Zone name and SSID are required.'; $msgType = 'error';
    } elseif ($rMac && !isValidMAC($rMac)) {
        $msg = 'Invalid router MAC format.'; $msgType = 'error';
    } else {
        try {
            $pdo->prepare("INSERT INTO wifi_zones (zone_name, router_mac, ssid, location_desc) VALUES (?,?,?,?)")
                ->execute([$name, $rMac ?: '00:00:00:00:00:00', $ssid, $locDesc ?: null]);
            $msg = "Wi-Fi zone \"$name\" added.";
        } catch (PDOException $e) {
            $msg = 'Error: ' . $e->getMessage(); $msgType = 'error';
        }
    }
}

if (isset($_GET['msg'])) $msg = $_GET['msg'] === 'deleted' ? 'Zone deleted.' : '';

$zones = $pdo->query("
    SELECT w.*
    FROM   wifi_zones w
    ORDER BY w.zone_name
")->fetchAll();

$pageTitle = 'Wi-Fi Zones';

// Summary figures for header chips
$totalZones  = count($zones);
$activeZones = 0;
foreach ($zones as $__z) {
    if (!empty($__z['is_active'])) $activeZones++;
}

include 'includes/header.php';
?>

<style>
/* ── Wi-Fi Zones page — redesign (scoped) ─────────────────── */
.wz-hero{
    display:flex;align-items:flex-start;justify-content:space-between;
    gap:20px;flex-wrap:wrap;margin-bottom:20px;
    padding:22px 24px;border-radius:16px;
    background:
        radial-gradient(1200px 220px at 0% 0%, rgba(0,212,170,.10), transparent 60%),
        linear-gradient(135deg, rgba(16,185,129,.06), rgba(56,189,248,.04));
    border:1px solid var(--border);position:relative;overflow:hidden;
}
.wz-hero::after{content:"";position:absolute;right:-50px;top:-50px;width:200px;height:200px;
    border-radius:50%;background:radial-gradient(circle, rgba(0,212,170,.16), transparent 70%);
    filter:blur(8px);pointer-events:none;}
.wz-hero h2{margin:0 0 6px;font-size:22px;letter-spacing:.3px;display:flex;align-items:center;gap:10px}
.wz-hero p{margin:0;color:var(--muted);font-size:13px;max-width:520px}
.wz-chips{display:flex;gap:10px;flex-wrap:wrap}
.wz-chip{padding:10px 16px;border-radius:12px;border:1px solid var(--border);
    background:rgba(255,255,255,.02);text-align:center;min-width:92px;}
.wz-chip .n{font-size:22px;font-weight:700;font-family:var(--mono);line-height:1}
.wz-chip .l{margin-top:5px;font-size:10px;letter-spacing:1px;text-transform:uppercase;color:var(--muted)}
.wz-chip.accent .n{color:var(--accent)}
.wz-chip.green .n{color:#10b981}
.wz-chip.blue .n{color:#38bdf8}

.wz-card{border:1px solid var(--border);border-radius:16px;padding:22px 24px;margin-bottom:20px;
    background:linear-gradient(180deg, rgba(255,255,255,.02), transparent);}
.wz-card-title{display:flex;align-items:center;gap:9px;font-size:15px;font-weight:700;margin-bottom:18px}
.wz-card-title .ic{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;
    background:rgba(0,212,170,.14);border:1px solid rgba(0,212,170,.3);font-size:15px}

/* zone list */
.zone-list{display:flex;flex-direction:column;gap:10px}
.zone-row{display:flex;align-items:center;gap:14px;padding:16px;border-radius:12px;
    border:1px solid var(--border);background:rgba(255,255,255,.02);
    transition:border-color .15s ease, transform .12s ease;}
.zone-row:hover{border-color:rgba(0,212,170,.4);transform:translateX(2px)}
.zone-ic{width:44px;height:44px;border-radius:12px;flex-shrink:0;display:grid;place-items:center;
    font-size:20px;background:linear-gradient(135deg,#00d4aa,#10b981);
    box-shadow:0 4px 14px rgba(0,212,170,.25)}
.zone-ic.off{background:linear-gradient(135deg,#475569,#334155);box-shadow:none;opacity:.8}
.zone-main{flex:1;min-width:0}
.zone-name{font-weight:600;font-size:15px;margin-bottom:4px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.zone-meta{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.zone-ssid{font-family:var(--mono);font-size:12px;color:var(--accent);background:var(--bg);
    padding:3px 9px;border-radius:6px;border:1px solid var(--border)}
.zone-mac{font-family:var(--mono);font-size:11px;color:var(--muted)}
.zone-loc{font-size:12px;color:var(--muted)}
.zone-right{display:flex;align-items:center;gap:16px;flex-shrink:0}
.zone-logs{text-align:center}
.zone-logs .n{font-family:var(--mono);font-size:18px;font-weight:700;color:#38bdf8;line-height:1}
.zone-logs .l{font-size:10px;letter-spacing:.5px;text-transform:uppercase;color:var(--muted);margin-top:3px}
.zone-empty{text-align:center;color:var(--muted);padding:36px 20px;font-size:13px}
@media(max-width:640px){.zone-logs{display:none}}
</style>

<div class="wz-hero">
    <div>
        <h2>📶 Wi-Fi Zone Management</h2>
        <p>Each zone maps to one physical router or access point. The scanner tags attendance to whichever zone a device is seen on.</p>
    </div>
    <div class="wz-chips">
        <div class="wz-chip accent"><div class="n"><?= (int)$totalZones ?></div><div class="l">Zones</div></div>
        <div class="wz-chip green"><div class="n"><?= (int)$activeZones ?></div><div class="l">Active</div></div>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert alert-<?= $msgType === 'error' ? 'error' : 'success' ?>"><?= e($msg) ?></div>
<?php endif; ?>

<div class="wz-card">
    <div class="wz-card-title"><span class="ic">➕</span> Add Wi-Fi Zone</div>
    <form method="POST">
        <div class="form-grid">
            <div class="form-group">
                <label>ZONE NAME *</label>
                <input type="text" name="zone_name" placeholder="e.g. Main Office" required>
            </div>
            <div class="form-group">
                <label>SSID (Network Name) *</label>
                <input type="text" name="ssid" placeholder="e.g. Office-WiFi" required>
            </div>
            <div class="form-group">
                <label>ROUTER MAC ADDRESS</label>
                <input type="text" name="router_mac" placeholder="e.g. ac:84:c6:xx:xx:xx">
            </div>
            <div class="form-group">
                <label>LOCATION DESCRIPTION</label>
                <input type="text" name="location_desc" placeholder="e.g. 2nd floor, east wing">
            </div>
        </div>
        <div style="margin-top:18px">
            <button type="submit" class="btn btn-primary">Add Zone</button>
        </div>
    </form>
</div>

<div class="wz-card">
    <div class="wz-card-title" style="margin-bottom:16px">
        <span class="ic">🗂️</span> All Wi-Fi Zones
        <span style="font-family:var(--mono);font-size:12px;color:var(--accent);background:rgba(0,212,170,.1);padding:2px 10px;border-radius:999px;border:1px solid rgba(0,212,170,.25)"><?= count($zones) ?></span>
    </div>

    <?php if (empty($zones)): ?>
        <div class="zone-empty">No zones configured yet. Add one using the form above.</div>
    <?php else: ?>
        <div class="zone-list">
            <?php foreach ($zones as $z): ?>
            <div class="zone-row">
                <div class="zone-ic <?= $z['is_active'] ? '' : 'off' ?>">📡</div>
                <div class="zone-main">
                    <div class="zone-name">
                        <?= e($z['zone_name']) ?>
                        <span class="badge <?= $z['is_active'] ? 'badge-green' : 'badge-red' ?>"><?= $z['is_active'] ? 'Active' : 'Inactive' ?></span>
                    </div>
                    <div class="zone-meta">
                        <span class="zone-ssid"><?= e($z['ssid']) ?></span>
                        <span class="zone-mac"><?= e($z['router_mac']) ?></span>
                        <?php if (!empty($z['location_desc'])): ?>
                            <span class="zone-loc">· 📍 <?= e($z['location_desc']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="zone-right">
                    <a href="wifi_zones.php?delete=<?= $z['wifi_zone_id'] ?>"
                       class="btn btn-danger btn-sm"
                       onclick="return confirm('Delete this zone?')">Delete</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
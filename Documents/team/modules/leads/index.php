<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
requireRole(['admin','marketing']);

$pageTitle = 'Leads';
$db   = getDB();
$user = currentUser();

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        error_log("CSRF mismatch in leads delete");
        redirect("index.php?error=csrf");
    } else {
        try {
            $db->prepare("DELETE FROM leads WHERE id=?")->execute([(int)$_POST['delete_id']]);
            logActivity($user['id'], 'Deleted lead', 'leads');
            redirect('index.php?msg=deleted');
        } catch (Exception $e) {
            $errors[] = "Database Error: " . $e->getMessage();
        }
    }
}

// Update status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        error_log("CSRF mismatch in leads update status");
        redirect("index.php?error=csrf");
    } else {
        try {
            $db->prepare("UPDATE leads SET status=? WHERE id=?")->execute([sanitize($_POST['status']),(int)$_POST['lead_id']]);
            redirect('index.php?msg=updated');
        } catch (Exception $e) {
            $errors[] = "Database Error: " . $e->getMessage();
        }
    }
}
$errors = [];
$search = $_GET['q']      ?? '';
$status = $_GET['status'] ?? '';
$source = $_GET['source'] ?? '';

$sql = "SELECT * FROM leads WHERE 1=1";
$params = [];

if ($user['role'] === 'marketing') {
    $sql .= " AND assigned_to = ?";
    $params[] = $user['id'];
}

if ($status) { $sql .= " AND status=?";  $params[] = $status; }
if ($source) { $sql .= " AND source=?";  $params[] = $source; }
if ($search) { $sql .= " AND (student_name LIKE ? OR phone LIKE ? OR class LIKE ?)"; $params = array_merge($params,["%$search%","%$search%","%$search%"]); }
$sql .= " ORDER BY created_at DESC";

$stmt = $db->prepare($sql); $stmt->execute($params);
$leads = $stmt->fetchAll();

$sources = $db->query("SELECT DISTINCT source FROM leads WHERE source IS NOT NULL AND source!='' ORDER BY source")->fetchAll(PDO::FETCH_COLUMN);

$isMarketing = $user['role'] === 'marketing';
$userId = $user['id'];

if ($isMarketing) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE status='New' AND assigned_to=?");
    $stmt->execute([$userId]);
    $totalNew = $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE status='Converted' AND assigned_to=?");
    $stmt->execute([$userId]);
    $totalConverted = $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE status='Rejected' AND assigned_to=?");
    $stmt->execute([$userId]);
    $totalRejected = $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE assigned_to=?");
    $stmt->execute([$userId]);
    $totalLeadsStats = $stmt->fetchColumn();
} else {
    $totalNew       = $db->query("SELECT COUNT(*) FROM leads WHERE status='New'")->fetchColumn();
    $totalConverted = $db->query("SELECT COUNT(*) FROM leads WHERE status='Converted'")->fetchColumn();
    $totalRejected  = $db->query("SELECT COUNT(*) FROM leads WHERE status='Rejected'")->fetchColumn();
    $totalLeadsStats = $db->query("SELECT COUNT(*) FROM leads")->fetchColumn();
}
$convRate = $totalLeadsStats > 0 ? round(($totalConverted / $totalLeadsStats) * 100) : 0;

$root = '../../';
require_once '../../includes/header.php';
?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'csrf'): ?>
<div class="alert alert-danger" data-auto-dismiss>⚠️ Security token mismatch. Please try again.</div>
<?php endif; ?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success" data-auto-dismiss><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg> Lead <?= sanitize($_GET['msg']) ?>!</div>
<?php endif; ?>

<?php foreach ($errors as $e): ?>
<div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> <?= $e ?></div>
<?php endforeach; ?>

<div class="page-header mb-24">
    <div class="page-header-left">
        <h1 class="align-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg> Leads</h1>
        <p>Track and manage prospective student leads</p>
    </div>
    <div class="page-header-actions">
        <a href="pipeline.php" class="btn btn-primary align-icon" style="background: linear-gradient(135deg, #06b6d4, #3b82f6); border:none;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="9" y1="3" x2="9" y2="21"></line></svg> Pipeline Board</a>
        <a href="allocate.php" class="btn btn-secondary align-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg> Allocate</a>
        <a href="import.php" class="btn btn-secondary align-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg> Import</a>
        <a href="add.php" class="btn btn-primary align-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Add Lead</a>
    </div>
</div>

<!-- Funnel Stats Grid -->
<style>
.funnel-wrap {
    display: flex;
    margin-bottom: 24px;
    border-radius: 20px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.06);
    background: #fff;
    border: 1px solid rgba(255,255,255,0.8);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
}
.f-step {
    flex: 1;
    display: flex;
    align-items: center;
    padding: 24px 24px 24px 40px;
    gap: 16px;
    position: relative;
    transition: transform 0.2s ease;
}
.f-step:first-child {
    padding-left: 28px;
    border-top-left-radius: 20px;
    border-bottom-left-radius: 20px;
}
.f-step:last-child {
    border-top-right-radius: 20px;
    border-bottom-right-radius: 20px;
}
/* Chevron logic */
.f-step:not(:last-child)::after {
    content: '';
    position: absolute;
    right: -24px;
    top: 0;
    bottom: 0;
    width: 24px;
    background: inherit;
    clip-path: polygon(0 0, 100% 50%, 0 100%);
    z-index: 2;
}

/* Glassmorphism Icon */
.f-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1), inset 0 1px 1px rgba(255,255,255,0.4);
    position: relative;
}
.f-icon svg {
    color: #fff;
    position: relative;
    z-index: 2;
    filter: drop-shadow(0 2px 2px rgba(0,0,0,0.1));
}

.f-new { background: linear-gradient(135deg, rgba(168,85,247,0.15), rgba(147,51,234,0.05)); z-index: 3; }
.f-new-icon { background: linear-gradient(135deg, #a855f7, #7e22ce); }
.f-new-text { color: #6b21a8; }

.f-conv { background: linear-gradient(135deg, rgba(16,185,129,0.15), rgba(5,150,105,0.05)); z-index: 2; }
.f-conv-icon { background: linear-gradient(135deg, #10b981, #047857); }
.f-conv-text { color: #15803d; }

.f-rej { background: linear-gradient(135deg, rgba(251,113,133,0.15), rgba(225,29,72,0.05)); z-index: 1; }
.f-rej-icon { background: linear-gradient(135deg, #fb7185, #e11d48); }
.f-rej-text { color: #be123c; }

.f-val { font-size: 34px; font-weight: 800; color: var(--text); line-height: 1; letter-spacing: -1px; display: flex; align-items: center; gap: 8px; }
.f-lbl { font-size: 14px; font-weight: 700; margin-top: 6px; }

.f-pill {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 99px;
    font-size: 13px;
    font-weight: 800;
}
.pill-green { background: #dcfce7; color: #16a34a; box-shadow: inset 0 0 0 1px #bbf7d0; }
.pill-gray { background: #f1f5f9; color: #64748b; box-shadow: inset 0 0 0 1px #e2e8f0; }

@media (max-width: 768px) {
    .funnel-wrap { flex-direction: column; }
    .f-step:not(:last-child)::after {
        right: 0; bottom: -24px; width: 100%; height: 24px; top: auto;
        clip-path: polygon(0 0, 50% 100%, 100% 0);
    }
    .f-step { padding: 24px; }
    .f-step:first-child { padding-left: 24px; }
}
</style>

<div class="funnel-wrap">
    <!-- New Leads / Pool -->
    <div class="f-step f-new">
        <div class="f-icon f-new-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
        </div>
        <div>
            <div class="f-val"><?= $totalNew ?></div>
            <div class="f-lbl f-new-text">New Leads</div>
        </div>
    </div>
    
    <!-- Converted -->
    <div class="f-step f-conv">
        <div class="f-icon f-conv-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
        </div>
        <div>
            <div class="f-val">
                <?= $totalConverted ?>
                <span class="f-pill <?= $convRate > 0 ? 'pill-green' : 'pill-gray' ?>">(<?= $convRate ?>%)</span>
            </div>
            <div class="f-lbl f-conv-text">Converted</div>
        </div>
    </div>
    
    <!-- Rejected -->
    <div class="f-step f-rej">
        <div class="f-icon f-rej-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="23" y1="11" x2="17" y2="11"></line></svg>
        </div>
        <div>
            <div class="f-val"><?= $totalRejected ?></div>
            <div class="f-lbl f-rej-text">Rejected</div>
        </div>
    </div>
</div>


<div class="card">
    <div class="filter-bar">
        <form method="GET" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
            <div class="search-box" style="flex:1;min-width:200px">
                <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg></span>
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, phone, class..." data-search="leadsTable">
            </div>
            <select name="status" style="padding:9px 12px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-size:13px;background:#fff;outline:none">
                <option value="">All Status</option>
                <?php foreach (['New', 'Message Sent', 'To Call', 'Done Calling', 'Contacted', 'Converted', 'Rejected'] as $s): ?>
                <option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
            <select name="source" style="padding:9px 12px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-size:13px;background:#fff;outline:none">
                <option value="">All Sources</option>
                <?php foreach ($sources as $src): ?>
                <option value="<?= $src ?>" <?= $source===$src?'selected':'' ?>><?= $src ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
            <?php if ($status||$source||$search): ?><a href="index.php" class="btn btn-secondary btn-sm"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg> Clear</a><?php endif; ?>
        </form>
    </div>

    <div class="table-wrap">
    <table class="data-table">
        <thead><tr><th>#</th><th>Name</th><th>Class</th><th>City</th><th>Phone</th><th>WhatsApp</th><th>Source</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if ($leads): foreach ($leads as $i => $lead):
            $sc = match($lead['status']) {'New'=>'badge-blue','Contacted'=>'badge-amber','Converted'=>'badge-green','Rejected'=>'badge-red',default=>'badge-gray'};
        ?>
        <tr>
            <td class="font-mono text-muted"><?= $i+1 ?></td>
            <td><strong><?= sanitize($lead['student_name'] ?: 'Unknown') ?></strong></td>
            <td><?= sanitize($lead['class']??'-') ?></td>
            <td><?= sanitize($lead['city']??'-') ?></td>
            <td class="font-mono" style="font-size:13px"><?= sanitize($lead['phone']??'-') ?></td>
            <td class="font-mono" style="font-size:13px"><?= sanitize($lead['whatsapp']??'-') ?></td>
            <td><?= sanitize($lead['source']??'-') ?></td>
            <td>
                <form method="POST" style="display:inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
                    <input type="hidden" name="update_status" value="1">
                    <select name="status" onchange="this.form.submit()" style="padding:4px 8px;border-radius:20px;font-size:11.5px;font-weight:700;border:1.5px solid var(--border);background:#fff;cursor:pointer">
                        <?php foreach (['New', 'Message Sent', 'To Call', 'Done Calling', 'Contacted', 'Converted', 'Rejected'] as $s): ?>
                        <option value="<?= $s ?>" <?= $lead['status']===$s?'selected':'' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </td>
            <td class="text-muted" style="font-size:12.5px"><?= date('d M Y', strtotime($lead['created_at'])) ?></td>
            <td>
                <div style="display:flex;gap:6px">
                    <a href="edit.php?id=<?= $lead['id'] ?>" class="btn btn-secondary btn-sm"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></a>
                    <form method="POST" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="delete_id" value="<?= $lead['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete this lead?"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="10">
            <div class="empty-state">
                <div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg></div>
                <h3>No leads found</h3>
                <a href="add.php" class="btn btn-primary btn-sm"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Add First Lead</a>
            </div>
        </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

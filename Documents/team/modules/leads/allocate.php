<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/email.php';
requireRole(['admin', 'marketing']);

$pageTitle = 'Allocate Leads';
$db = getDB();
$user = currentUser();
$root = '../../';

// ─────────────────────────────────────────
// HANDLE ALLOCATION
// ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['allocate'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        error_log("CSRF mismatch in leads allocate");
        redirect("allocate.php?error=csrf");
    } else {
        $lead_ids = array_map('intval', $_POST['lead_ids'] ?? []);
        $assign_to = (int)$_POST['assign_to'];
        if ($lead_ids && $assign_to) {
            try {
                $placeholders = implode(',', array_fill(0, count($lead_ids), '?'));
                // Correct param order: assigned_to first (for SET), then lead_ids (for WHERE IN)
                $db->prepare("UPDATE leads SET assigned_to=?, updated_at=NOW() WHERE id IN ($placeholders)")
                    ->execute(array_merge([$assign_to], $lead_ids));

                // Notify Team Member
                $uData = $db->prepare("SELECT name, email FROM users WHERE id=?");
                $uData->execute([$assign_to]);
                $assignee = $uData->fetch();
                if ($assignee && $assignee['email']) {
                    sendLeadAllocationAlert($assignee['email'], $assignee['name'], count($lead_ids));
                }

                logActivity($user['id'], "Allocated " . count($lead_ids) . " leads to user #$assign_to", 'leads');
                redirect("allocate.php?msg=allocated&count=" . count($lead_ids));
            } catch (Exception $e) {
                $errors[] = "Database Error: " . $e->getMessage();
            }
        }
    }
}

// ─────────────────────────────────────────
// FILTERS & DATA
// ─────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$filterSource = $_GET['source'] ?? '';
$filterAssigned = $_GET['assigned'] ?? 'unassigned'; // default: show unassigned
$search = $_GET['q'] ?? '';

$sql = "SELECT l.*, u.name as assigned_name FROM leads l LEFT JOIN users u ON u.id=l.assigned_to WHERE 1=1";
$params = [];

if ($filterStatus) {
    $sql .= " AND l.status=?";
    $params[] = $filterStatus;
}
if ($filterSource) {
    $sql .= " AND l.source=?";
    $params[] = $filterSource;
}
if ($filterAssigned === 'unassigned') {
    $sql .= " AND l.assigned_to IS NULL";
}
elseif ($filterAssigned === 'assigned') {
    $sql .= " AND l.assigned_to IS NOT NULL";
}
if ($search) {
    $sql .= " AND (l.student_name LIKE ? OR l.phone LIKE ? OR l.class LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
$sql .= " ORDER BY l.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$leads = $stmt->fetchAll();

// Marketing team members (role = marketing or admin)
$teamMembers = $db->query("SELECT id, name, email FROM users WHERE role IN ('marketing','admin') ORDER BY name")->fetchAll();

// Sources for filter
$sources = $db->query("SELECT DISTINCT source FROM leads WHERE source IS NOT NULL AND source!='' ORDER BY source")->fetchAll(PDO::FETCH_COLUMN);

// Stats per team member
$memberStats = [];
foreach ($teamMembers as $m) {
    $cnt = $db->prepare("SELECT COUNT(*) FROM leads WHERE assigned_to=?");
    $cnt->execute([$m['id']]);
    $memberStats[$m['id']] = $cnt->fetchColumn();
}

$errors = [];
require_once '../../includes/header.php';
?>

<?php foreach ($errors as $e): ?>
<div class="alert alert-danger" data-auto-dismiss style="margin-bottom:20px">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> <?= $e ?>
</div>
<?php endforeach; ?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'csrf'): ?>
<div class="alert alert-danger" data-auto-dismiss style="margin-bottom:20px">⚠️ Security token mismatch. Please try again.</div>
<?php endif; ?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success" data-auto-dismiss><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg>
    <?=(int)$_GET['count']?> lead(s) allocated successfully!
</div>
<?php
endif; ?>

<div class="page-header">
    <div class="page-header-left">
        <h1><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:8px"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg> Allocate Leads</h1>
        <p>Assign leads to your marketing team members</p>
    </div>
    <div class="page-header-actions">
        <a href="index.php" class="btn btn-secondary"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> All Leads</a>
        <a href="import.php" class="btn btn-primary"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg> Import Leads</a>
    </div>
</div>

<!-- Team Overview Cards -->
<div class="stats-grid" style="margin-bottom:25px; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr))">
    <?php foreach ($teamMembers as $m): ?>
    <div class="stat-card accent-blue" style="display:flex; align-items:center; gap:16px; padding:16px 20px">
        <div style="width:48px; height:48px; border-radius:12px; background:var(--blue-light); display:flex; align-items:center; justify-content:center; font-size:18px; font-weight:800; color:var(--blue); border:2.5px solid var(--blue-mid); flex-shrink:0">
            <?= strtoupper(substr($m['name'], 0, 2))?>
        </div>
        <div style="flex:1; min-width:0">
            <div style="font-weight:800; font-family:'Poppins',sans-serif; font-size:14px; color:var(--blue-deep); white-space:nowrap; overflow:hidden; text-overflow:ellipsis">
                <?= sanitize($m['name'])?>
            </div>
            <div style="font-size:11px; color:var(--text-light); font-weight:600; text-transform:uppercase; letter-spacing:0.5px">
                <?= $m['id'] === $user['id'] ? 'You · ' : ''?>Marketing
            </div>
        </div>
        <div style="text-align:right">
            <div style="font-size:22px; font-weight:900; font-family:'Poppins',sans-serif; color:var(--blue); line-height:1"><?= $memberStats[$m['id']]?></div>
            <div style="font-size:10px; color:var(--text-light); font-weight:700; text-transform:uppercase">Leads</div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <form method="GET" style="display:contents">
        <div style="position:relative;flex:1"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-light)"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg><input type="text" name="q" class="input" placeholder="Search leads..." value="<?= sanitize($search)?>" style="padding-left:34px"></div>
        <select name="status" class="input">
            <option value="">All Statuses</option>
            <?php foreach (['New', 'Message Sent', 'To Call', 'Done Calling', 'Contacted', 'Converted', 'Rejected'] as $s): ?>
            <option value="<?= $s?>" <?=$filterStatus === $s ? 'selected' : ''?>>
                <?= $s?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="source" class="input">
            <option value="">All Sources</option>
            <?php foreach ($sources as $s): ?>
            <option value="<?= $s?>" <?=$filterSource === $s ? 'selected' : ''?>>
                <?= sanitize($s)?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="assigned" class="input">
            <option value="all" <?=$filterAssigned === 'all' ? 'selected' : ''?>>All Leads</option>
            <option value="unassigned" <?=$filterAssigned === 'unassigned' ? 'selected' : ''?>>Unassigned Only</option>
            <option value="assigned" <?=$filterAssigned === 'assigned' ? 'selected' : ''?>>Assigned Only</option>
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
        <button type="button" class="btn btn-warning align-icon" onclick="openModal('smartSelectModal')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg> Smart Select</button>
        <a href="allocate.php" class="btn btn-secondary">Reset</a>
    </form>
</div>

<!-- Smart Select Modal -->
<div class="modal-overlay" id="smartSelectModal">
    <div class="modal" style="max-width:500px">
        <div class="modal-header">
            <div class="modal-title">Smart Select Leads</div>
            <button class="modal-close" onclick="closeModal('smartSelectModal')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
        </div>
        <div class="modal-body">
            <p style="font-size:13px; color:var(--text-muted); margin-bottom:12px">Paste lead data (Name - Phone) to automatically select matching leads from the current list.</p>
            <textarea id="smartPasteArea" class="input" rows="10" placeholder="Name - Phone&#10;9876543210&#10;..." style="font-family:monospace; margin-bottom:16px"></textarea>
            <div style="display:flex;gap:10px;justify-content:flex-end">
                <button type="button" class="btn btn-secondary" onclick="closeModal('smartSelectModal')">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="runSmartSelect()">Select Matching Leads</button>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Action Bar (Floating) -->
<style>
.bulk-bar-floating {
    position: fixed;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%) translateY(100px);
    background: rgba(15, 23, 42, 0.9);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    padding: 12px 24px;
    border-radius: 99px;
    box-shadow: 0 20px 50px -12px rgba(15, 23, 42, 0.5);
    display: flex;
    align-items: center;
    gap: 20px;
    z-index: 1000;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    border: 1.5px solid rgba(255, 255, 255, 0.1);
    color: #fff;
    min-width: 500px;
}
.bulk-bar-floating.show {
    transform: translateX(-50%) translateY(0);
}
.bulk-bar-floating select {
    background: rgba(255, 255, 255, 0.1) !important;
    border: 1px solid rgba(255, 255, 255, 0.2) !important;
    color: #fff !important;
    height: 38px !important;
    font-size: 13px !important;
    border-radius: 8px !important;
    padding: 0 12px !important;
}
.bulk-bar-floating select option {
    background: #1e293b;
    color: #fff;
}
</style>

<div class="bulk-bar-floating" id="bulkBar">
    <div style="display:flex; align-items:center; gap:10px">
        <div style="width:36px; height:36px; border-radius:50%; background:var(--blue); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:14px" id="bulkCount">0</div>
        <div style="font-weight:700; font-size:14px; white-space:nowrap">Leads Selected</div>
    </div>
    <div style="width:1px; height:30px; background:rgba(255,255,255,0.1)"></div>
    <form method="POST" id="allocateForm" style="display:flex; align-items:center; gap:12px; flex:1">
        <?= csrfField() ?>
        <input type="hidden" name="allocate" value="1">
        <div id="bulkLeadInputs"></div>
        <select name="assign_to" required style="flex:1">
            <option value="">- Select Assignee -</option>
            <?php foreach ($teamMembers as $m): ?>
            <option value="<?= $m['id']?>">
                <?= sanitize($m['name'])?> (<?= $memberStats[$m['id']]?> leads)
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary" onclick="return confirmAllocate()" style="border-radius:99px; padding:8px 20px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px"><polyline points="20 6 9 17 4 12"></polyline></svg> Assign
        </button>
        <button type="button" class="btn btn-white" onclick="clearSelection()" style="border-radius:99px; width:36px; height:36px; padding:0; display:flex; align-items:center; justify-content:center; background:rgba(255,255,255,0.1); border:none; color:#fff">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
    </form>
</div>

<!-- Leads Table -->
<div class="section-card">
    <div class="section-header" style="justify-content:space-between">
        <h3><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:4px"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg> Leads (
            <?= count($leads)?>)
        </h3>
        <button class="btn btn-secondary" onclick="selectAll()">Select All</button>
    </div>

    <?php if (empty($leads)): ?>
    <div class="empty-state">
        <div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 17H2L12 2l10 15z"></path></svg></div>
        <h3>No leads found</h3>
        <p>Try changing filters or <a href="import.php">import new leads</a></p>
    </div>
    <?php
else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th><input type="checkbox" id="masterCheck" onchange="toggleAll(this)"></th>
                    <th>Student</th>
                    <th>Phone</th>
                    <th>Class</th>
                    <th>Source</th>
                    <th>Status</th>
                    <th>Assigned To</th>
                    <th>Added</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leads as $lead): ?>
                <tr class="lead-row" data-id="<?= $lead['id']?>">
                    <td><input type="checkbox" class="lead-check" value="<?= $lead['id']?>" onchange="updateBulkBar()">
                    </td>
                    <td>
                        <strong>
                            <?= sanitize($lead['student_name'])?>
                        </strong>
                        <?php if ($lead['parent_name']): ?>
                        <br><small style="color:var(--text-muted)">
                            <?= sanitize($lead['parent_name'])?>
                        </small>
                        <?php
        endif; ?>
                    </td>
                    <td>
                        <?= sanitize($lead['phone'])?>
                    </td>
                    <td>
                        <?= sanitize($lead['class'] ?? '-')?>
                    </td>
                    <td>
                        <?= sanitize($lead['source'] ?? '-')?>
                    </td>
                    <td>
                        <?php $sc = match($lead['status']) { 'New'=>'badge-blue', 'Message Sent'=>'badge-blue', 'To Call'=>'badge-amber', 'Done Calling'=>'badge-amber', 'Contacted'=>'badge-amber', 'Converted'=>'badge-green', 'Rejected'=>'badge-red', default=>'badge-gray' }; ?>
                        <span class="badge <?= $sc ?>">
                            <?= $lead['status']?>
                        </span>
                    </td>
                    <td>
                        <?php if ($lead['assigned_to']): ?>
                        <span class="assigned-pill">
                            <?= sanitize($lead['assigned_name'])?>
                        </span>
                        <?php
        else: ?>
                        <span style="color:var(--text-muted);font-size:13px">Unassigned</span>
                        <?php
        endif; ?>
                    </td>
                    <td style="font-size:13px;color:var(--text-muted)">
                        <?= date('d M y', strtotime($lead['created_at']))?>
                    </td>
                    <td>
                        <!-- Quick reassign -->
                        <form method="POST" style="display:inline-flex;gap:6px;align-items:center;margin:0">
                            <?= csrfField() ?>
                            <input type="hidden" name="allocate" value="1">
                            <input type="hidden" name="lead_ids[]" value="<?= $lead['id']?>">
                            <select name="assign_to" class="input" style="padding:4px 8px;font-size:12px;height:30px"
                                onchange="this.form.submit()">
                                <option value="">Reassign...</option>
                                <?php foreach ($teamMembers as $m): ?>
                                <option value="<?= $m['id']?>" <?=$lead['assigned_to'] == $m['id'] ? 'selected' : ''?>>
                                    <?= sanitize($m['name'])?>
                                </option>
                                <?php
        endforeach; ?>
                            </select>
                        </form>
                    </td>
                </tr>
                <?php
    endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
endif; ?>
</div>

<script>
    function updateBulkBar() {
        const checks = document.querySelectorAll('.lead-check:checked');
        const bar = document.getElementById('bulkBar');
        const inputs = document.getElementById('bulkLeadInputs');
        document.getElementById('bulkCount').textContent = checks.length;
        if (checks.length > 0) {
            bar.classList.add('show');
        } else {
            bar.classList.remove('show');
        }
        inputs.innerHTML = Array.from(checks).map(c => `<input type="hidden" name="lead_ids[]" value="${c.value}">`).join('');
        document.getElementById('masterCheck').indeterminate =
            checks.length > 0 && checks.length < document.querySelectorAll('.lead-check').length;
        document.getElementById('masterCheck').checked = checks.length === document.querySelectorAll('.lead-check').length;
    }

    function toggleAll(master) {
        document.querySelectorAll('.lead-check').forEach(c => c.checked = master.checked);
        updateBulkBar();
    }

    function selectAll() {
        document.querySelectorAll('.lead-check').forEach(c => c.checked = true);
        document.getElementById('masterCheck').checked = true;
        updateBulkBar();
    }

    function clearSelection() {
        document.querySelectorAll('.lead-check').forEach(c => c.checked = false);
        document.getElementById('masterCheck').checked = false;
        updateBulkBar();
    }

    function runSmartSelect() {
        const text = document.getElementById('smartPasteArea').value.trim();
        if (!text) { alert('Please paste some lead data.'); return; }
        
        // Simple JS parser for phone numbers
        const lines = text.split('\n');
        const searchPhones = [];
        lines.forEach(line => {
            const m = line.match(/(\d{10,15})/);
            if (m) searchPhones.push(m[1].slice(-10)); // Match last 10 digits for flexibility
        });
        
        if (searchPhones.length === 0) {
            alert('No valid phone numbers detected.');
            return;
        }
        
        let foundCount = 0;
        const allChecks = document.querySelectorAll('.lead-check');
        allChecks.forEach(chk => {
            const row = chk.closest('tr');
            // Phone is index 2, Name is index 1
            const phoneCell = row.cells[2].textContent.trim().replace(/[^\d]/g, '').slice(-10);
            const nameCell = row.cells[1].textContent.trim().toLowerCase();
            
            let matched = false;
            if (searchPhones.includes(phoneCell)) matched = true;
            
            // Also try to match name if line contains it
            if (!matched) {
                lines.forEach(line => {
                    if (line.toLowerCase().includes(nameCell) && nameCell.length > 3) matched = true;
                });
            }

            if (matched) {
                chk.checked = true;
                row.style.backgroundColor = '#f0f9ff'; // Brief highlight
                foundCount++;
            }
        });
        
        if (foundCount > 0) {
            updateBulkBar();
            closeModal('smartSelectModal');
            // Show a nice toast or alert
            const msg = `Successfully selected ${foundCount} matching leads.`;
            alert(msg);
        } else {
            alert('No matching leads found in the current list. Make sure the phone numbers match exactly (last 10 digits).');
        }
    }

    function confirmAllocate() {
        const cnt = document.querySelectorAll('.lead-check:checked').length;
        const member = document.querySelector('[name="assign_to"]').selectedOptions[0]?.text;
        if (!cnt) { alert('Select at least one lead.'); return false; }
        if (!document.querySelector('[name="assign_to"]').value) { alert('Please select a team member.'); return false; }
        return confirm(`Assign ${cnt} lead(s) to ${member}?`);
    }
</script>

<?php require_once '../../includes/footer.php'; ?>
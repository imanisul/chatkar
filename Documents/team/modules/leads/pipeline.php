<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/email.php';
requireRole(['admin', 'marketing']);

$pageTitle = 'Pipeline Board';
$db = getDB();
$user = currentUser();

// Handle Status Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        redirect("pipeline.php?error=csrf");
    }

    $leadId = (int)$_POST['lead_id'];
    $action = $_POST['action'];
    $newStatus = '';

    if ($action === 'send_wa') {
        $newStatus = 'Message Sent';
        logActivity($user['id'], "Sent WhatsApp and moved lead #$leadId to Message Sent queue", 'leads');
    }
    elseif ($action === 'to_call') {
        $newStatus = 'To Call';
        logActivity($user['id'], "Queued lead #$leadId for calling", 'leads');
    }
    elseif ($action === 'done_calling') {
        $newStatus = 'Done Calling';
        logActivity($user['id'], "Completed call for lead #$leadId", 'leads');
    }
    elseif ($action === 'convert') {
        $newStatus = 'Converted';
        logActivity($user['id'], "Converted lead #$leadId", 'leads');
    }
    elseif ($action === 'reject') {
        $newStatus = 'Rejected';
        logActivity($user['id'], "Rejected lead #$leadId", 'leads');
    }
    elseif ($action === 'reassign') {
        $assignTo = (int)$_POST['assign_to'];
        $assignVal = $assignTo > 0 ? $assignTo : null;
        $db->prepare("UPDATE leads SET assigned_to=?, updated_at=NOW() WHERE id=?")->execute([$assignVal, $leadId]);
        logActivity($user['id'], "Reassigned lead #$leadId to user #$assignTo", 'leads');

        if ($assignTo > 0) {
            $uData = $db->prepare("SELECT name, email FROM users WHERE id=?");
            $uData->execute([$assignTo]);
            $assignee = $uData->fetch();
            if ($assignee && $assignee['email']) {
                sendLeadAllocationAlert($assignee['email'], $assignee['name'], 1);
            }
        }
        redirect("pipeline.php?msg=" . urlencode("Lead reassigned successfully."));
    }

    if ($newStatus) {
        try {
            $db->prepare("UPDATE leads SET status=?, updated_at=NOW() WHERE id=?")->execute([$newStatus, $leadId]);
            redirect("pipeline.php?msg=" . urlencode("Lead moved successfully."));
        } catch (Exception $e) {
            $errMsg = urlencode($e->getMessage());
            redirect("pipeline.php?error=db&details={$errMsg}");
        }
        exit;
    }
    // For other actions like reassign
    if (!isset($_GET['msg']) && !isset($_GET['error'])) {
        redirect("pipeline.php?msg=" . urlencode("Lead updated successfully."));
    }
}

$sql = "SELECT id, assigned_to, student_name, parent_name, class, phone, whatsapp, city, source, status, created_at FROM leads WHERE status IN ('New', 'Message Sent', 'To Call', 'Done Calling') ";
$params = [];

if ($user['role'] === 'marketing') {
    $sql .= " AND assigned_to = ? ";
    $params[] = $user['id'];
}
$sql .= " ORDER BY created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$activeLeads = $stmt->fetchAll();

$teamMembers = $db->query("SELECT id, name FROM users WHERE role IN ('marketing','admin') AND status='active' ORDER BY name")->fetchAll();

// Group leads by status
$board = [
    'New' => [],
    'Message Sent' => [],
    'To Call' => [],
    'Done Calling' => []
];

foreach ($activeLeads as $l) {
    if (isset($board[$l['status']])) {
        $board[$l['status']][] = $l;
    }
}

$root = '../../';
require_once '../../includes/header.php';
?>

<style>
    /* Premium Glassmorphism Pipeline */
    .pl-wrapper {
        display: flex;
        gap: 20px;
        padding-bottom: 40px;
        overflow-x: auto;
        min-height: calc(100vh - 200px);
    }

    .pl-col {
        flex: 0 0 320px;
        background: rgba(255, 255, 255, 0.4);
        border-radius: 20px;
        padding: 16px;
        border: 1px solid rgba(255, 255, 255, 0.7);
        display: flex;
        flex-direction: column;
        gap: 16px;
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
    }

    .pl-col-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding-bottom: 12px;
        border-bottom: 2px solid rgba(0, 0, 0, 0.05);
    }

    .pl-col-title {
        font-size: 16px;
        font-weight: 900;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .pl-count {
        background: #e2e8f0;
        color: #475569;
        padding: 2px 8px;
        border-radius: 99px;
        font-size: 12px;
        font-weight: 800;
    }

    /* Pipeline Cards */
    .pl-card {
        background: #fff;
        border-radius: 16px;
        padding: 16px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
        border: 1px solid rgba(0, 0, 0, 0.04);
        transition: transform 0.2s, box-shadow 0.2s;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .pl-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.06);
    }

    .pl-lead-name {
        font-weight: 800;
        color: #0f172a;
        font-size: 15px;
    }

    .pl-parent-name {
        font-size: 13px;
        color: #64748b;
        font-weight: 600;
        margin-top: -6px;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .pl-meta {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        font-weight: 600;
        color: #64748b;
    }

    .pl-meta svg {
        opacity: 0.6;
    }

    .pl-actions {
        display: flex;
        gap: 8px;
        margin-top: 6px;
        border-top: 1px dashed rgba(0, 0, 0, 0.08);
        padding-top: 12px;
    }

    .pl-btn {
        flex: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 8px;
        border-radius: 10px;
        font-size: 12px;
        font-weight: 800;
        cursor: pointer;
        border: none;
        transition: all 0.2s;
    }

    .btn-wa {
        background: #dcfce7;
        color: #16a34a;
    }

    .btn-wa:hover {
        background: #22c55e;
        color: #fff;
    }

    .btn-call {
        background: #e0f2fe;
        color: #0284c7;
    }

    .btn-call:hover {
        background: #0ea5e9;
        color: #fff;
    }

    .btn-done {
        background: #f3e8ff;
        color: #7e22ce;
    }

    .btn-done:hover {
        background: #a855f7;
        color: #fff;
    }

    /* Custom Col Headers */
    .col-new .pl-col-title {
        color: #8b5cf6;
    }

    .col-new .pl-count {
        background: #ede9fe;
        color: #7c3aed;
    }

    .col-msg .pl-col-title {
        color: #0ea5e9;
    }

    .col-msg .pl-count {
        background: #e0f2fe;
        color: #0284c7;
    }

    .col-call .pl-col-title {
        color: #f59e0b;
    }

    .col-call .pl-count {
        background: #fef3c7;
        color: #d97706;
    }

    .col-done .pl-col-title {
        color: #10b981;
    }

    .col-done .pl-count {
        background: #dcfce7;
        color: #16a34a;
    }

    /* Scrollbar styling for board */
    .pl-wrapper::-webkit-scrollbar {
        height: 8px;
    }

    .pl-wrapper::-webkit-scrollbar-track {
        background: rgba(0, 0, 0, 0.02);
        border-radius: 4px;
    }

    .pl-wrapper::-webkit-scrollbar-thumb {
        background: rgba(0, 0, 0, 0.1);
        border-radius: 4px;
    }

    .pl-wrapper::-webkit-scrollbar-thumb:hover {
        background: rgba(0, 0, 0, 0.2);
    }
</style>

<?php if (isset($_GET['error'])): ?>
<div class="alert alert-danger" data-auto-dismiss>
    ⚠️ <?= sanitize(isset($_GET['details']) ? "Database Error: " . urldecode($_GET['details']) : "Security token mismatch. Please try again.") ?>
</div>
<?php endif; ?>

<div class="page-header mb-24">
    <div class="page-header-left">
        <h1 class="align-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color:#0ea5e9">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="9" y1="3" x2="9" y2="21"></line>
            </svg> Pipeline Board</h1>
        <p>Interactive CRM Workflow</p>
    </div>
    <div class="page-header-actions">
        <a href="index.php" class="btn btn-secondary align-icon"><svg width="14" height="14" viewBox="0 0 24 24"
                fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="19" y1="12" x2="5" y2="12"></line>
                <polyline points="12 19 5 12 12 5"></polyline>
            </svg> List View</a>
    </div>
</div>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success" data-auto-dismiss>✅
    <?= sanitize($_GET['msg'])?>
</div>
<?php
endif; ?>

<div class="pl-wrapper">
    <!-- NEW COLUMN -->
    <div class="pl-col col-new">
        <div class="pl-col-header">
            <div class="pl-col-title">📥 Inbox</div>
            <div class="pl-count">
                <?= count($board['New'])?>
            </div>
        </div>
        <?php foreach ($board['New'] as $l): ?>
        <div class="pl-card">
            <div class="pl-lead-name"><a href="edit.php?id=<?= $l['id']?>" style="color:inherit;text-decoration:none;">
                    <?= sanitize($l['student_name'] ?: 'Unknown Lead')?>
                </a></div>
            <?php if (!empty($l['parent_name'])): ?>
            <div class="pl-parent-name">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                <?= sanitize($l['parent_name'])?>
            </div>
            <?php
    endif; ?>
            <div class="pl-meta"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2.5">
                    <path
                        d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z">
                    </path>
                </svg>
                <?= sanitize($l['phone'] ?: '-')?>
            </div>
            <div class="pl-meta"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2.5">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                </svg>
                <?= date('d M Y', strtotime($l['created_at']))?>
            </div>
            <div class="pl-assignee" style="margin-top: 8px;">
                <form method="POST" style="margin:0;">
                    <?= csrfField()?>
                    <input type="hidden" name="lead_id" value="<?= $l['id']?>">
                    <input type="hidden" name="action" value="reassign">
                    <select name="assign_to" onchange="this.form.submit()" style="width:100%; padding:6px 8px; font-size:11.5px; border-radius:8px; border:1px solid rgba(0,0,0,0.08); background:#f8fafc; color:#475569; font-weight:600; outline:none; cursor:pointer;">
                        <option value="">👤 Unassigned</option>
                        <?php foreach ($teamMembers as $m): ?>
                        <option value="<?= $m['id']?>" <?= $l['assigned_to'] == $m['id'] ? 'selected' : ''?>>👤 <?= sanitize($m['name'])?></option>
                        <?php
    endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="pl-actions">
                <form method="POST" style="flex:1">
                    <?= csrfField()?>
                    <input type="hidden" name="lead_id" value="<?= $l['id']?>">
                    <input type="hidden" name="action" value="send_wa">
                    <?php $waPhone = $l['whatsapp'] ?: $l['phone']; ?>
                    <button type="button" class="pl-btn btn-wa" style="width:100%"
                        onclick="window.open('https://wa.me/<?= preg_replace('/[^0-9]/', '', $waPhone)?>', '_blank'); this.closest('form').submit();">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path
                                d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z">
                            </path>
                        </svg>
                        Send WA
                    </button>
                </form>
            </div>
        </div>
        <?php
endforeach;
if (empty($board['New'])): ?>
        <div style="text-align:center; padding: 20px; font-size:13px; font-weight:700; color:#cbd5e1;">Drop Zone Empty
        </div>
        <?php
endif; ?>
    </div>

    <!-- MESSAGE SENT COLUMN -->
    <div class="pl-col col-msg">
        <div class="pl-col-header">
            <div class="pl-col-title">💬 Message Sent</div>
            <div class="pl-count">
                <?= count($board['Message Sent'] ?? []) ?>
            </div>
        </div>
        <?php foreach ($board['Message Sent'] ?? [] as $l): ?>
        <div class="pl-card" style="border-left: 4px solid #0ea5e9;">
            <div class="pl-lead-name"><a href="edit.php?id=<?= $l['id']?>" style="color:inherit;text-decoration:none;">
                    <?= sanitize($l['student_name'] ?: 'Unknown Lead')?>
                </a></div>
            <?php if (!empty($l['parent_name'])): ?>
            <div class="pl-parent-name">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                <?= sanitize($l['parent_name'])?>
            </div>
            <?php endif; ?>
            <div class="pl-meta"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2.5">
                    <path
                        d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z">
                    </path>
                </svg>
                <?= sanitize($l['phone'] ?: '-')?>
            </div>
            <div class="pl-assignee" style="margin-top: 8px;">
                <form method="POST" style="margin:0;">
                    <?= csrfField()?>
                    <input type="hidden" name="lead_id" value="<?= $l['id']?>">
                    <input type="hidden" name="action" value="reassign">
                    <select name="assign_to" onchange="this.form.submit()" style="width:100%; padding:6px 8px; font-size:11.5px; border-radius:8px; border:1px solid rgba(0,0,0,0.08); background:#f8fafc; color:#475569; font-weight:600; outline:none; cursor:pointer;">
                        <option value="">👤 Unassigned</option>
                        <?php foreach ($teamMembers as $m): ?>
                        <option value="<?= $m['id']?>" <?= $l['assigned_to'] == $m['id'] ? 'selected' : ''?>>👤 <?= sanitize($m['name'])?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="pl-actions">
                <form method="POST" style="flex:1">
                    <?= csrfField()?>
                    <input type="hidden" name="lead_id" value="<?= $l['id']?>">
                    <input type="hidden" name="action" value="to_call">
                    <button type="submit" class="pl-btn btn-call" style="width:100%">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                        </svg>
                        Move to Call Queue
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; if (empty($board['Message Sent'])): ?>
        <div style="text-align:center; padding: 20px; font-size:13px; font-weight:700; color:#cbd5e1;">Drop Zone Empty</div>
        <?php endif; ?>
    </div>


    <!-- TO CALL COLUMN -->
    <div class="pl-col col-call">
        <div class="pl-col-header">
            <div class="pl-col-title">📞 To Call</div>
            <div class="pl-count">
                <?= count($board['To Call'])?>
            </div>
        </div>
        <?php foreach ($board['To Call'] as $l): ?>
        <div class="pl-card">
            <div class="pl-lead-name"><a href="edit.php?id=<?= $l['id']?>" style="color:inherit;text-decoration:none;">
                    <?= sanitize($l['student_name'] ?: 'Unknown Lead')?>
                </a></div>
            <?php if (!empty($l['parent_name'])): ?>
            <div class="pl-parent-name">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                <?= sanitize($l['parent_name'])?>
            </div>
            <?php
    endif; ?>
            <div class="pl-meta"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2.5">
                    <path
                        d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z">
                    </path>
                </svg>
                <?= sanitize($l['phone'] ?: '-')?>
            </div>
            <div class="pl-assignee" style="margin-top: 8px;">
                <form method="POST" style="margin:0;">
                    <?= csrfField()?>
                    <input type="hidden" name="lead_id" value="<?= $l['id']?>">
                    <input type="hidden" name="action" value="reassign">
                    <select name="assign_to" onchange="this.form.submit()" style="width:100%; padding:6px 8px; font-size:11.5px; border-radius:8px; border:1px solid rgba(0,0,0,0.08); background:#f8fafc; color:#475569; font-weight:600; outline:none; cursor:pointer;">
                        <option value="">👤 Unassigned</option>
                        <?php foreach ($teamMembers as $m): ?>
                        <option value="<?= $m['id']?>" <?= $l['assigned_to'] == $m['id'] ? 'selected' : ''?>>👤 <?= sanitize($m['name'])?></option>
                        <?php
    endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="pl-actions">
                <form method="POST" style="flex:1">
                    <?= csrfField()?>
                    <input type="hidden" name="lead_id" value="<?= $l['id']?>">
                    <input type="hidden" name="action" value="done_calling">
                    <button type="submit" class="pl-btn btn-done" style="width:100%">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        Log as Called
                    </button>
                </form>
            </div>
        </div>
        <?php
endforeach;
if (empty($board['To Call'])): ?>
        <div style="text-align:center; padding: 20px; font-size:13px; font-weight:700; color:#cbd5e1;">Drop Zone Empty
        </div>
        <?php
endif; ?>
    </div>

    <!-- DONE CALLING COLUMN -->
    <div class="pl-col col-done">
        <div class="pl-col-header">
            <div class="pl-col-title">✅ Done Calling</div>
            <div class="pl-count">
                <?= count($board['Done Calling'])?>
            </div>
        </div>
        <?php foreach ($board['Done Calling'] as $l): ?>
        <div class="pl-card" style="border-left: 4px solid #10b981;">
            <div class="pl-lead-name"><a href="edit.php?id=<?= $l['id']?>" style="color:inherit;text-decoration:none;">
                    <?= sanitize($l['student_name'] ?: 'Unknown Lead')?>
                </a></div>
            <?php if (!empty($l['parent_name'])): ?>
            <div class="pl-parent-name">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                <?= sanitize($l['parent_name'])?>
            </div>
            <?php
    endif; ?>
            <div class="pl-assignee" style="margin-top: 8px;">
                <form method="POST" style="margin:0;">
                    <?= csrfField()?>
                    <input type="hidden" name="lead_id" value="<?= $l['id']?>">
                    <input type="hidden" name="action" value="reassign">
                    <select name="assign_to" onchange="this.form.submit()" style="width:100%; padding:6px 8px; font-size:11.5px; border-radius:8px; border:1px solid rgba(0,0,0,0.08); background:#f8fafc; color:#475569; font-weight:600; outline:none; cursor:pointer;">
                        <option value="">👤 Unassigned</option>
                        <?php foreach ($teamMembers as $m): ?>
                        <option value="<?= $m['id']?>" <?= $l['assigned_to'] == $m['id'] ? 'selected' : ''?>>👤 <?= sanitize($m['name'])?></option>
                        <?php
    endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="pl-actions" style="border-top:none; padding-top:4px;">
                <form method="POST" style="flex:1; display:flex; gap:6px;">
                    <?= csrfField()?>
                    <input type="hidden" name="lead_id" value="<?= $l['id']?>">
                    <button type="submit" name="action" value="convert" class="pl-btn"
                        style="background:#dcfce7; color:#16a34a; flex:1">Convert</button>
                    <button type="submit" name="action" value="reject" class="pl-btn"
                        style="background:#fee2e2; color:#ef4444; flex:1">Reject</button>
                </form>
            </div>
        </div>
        <?php
endforeach;
if (empty($board['Done Calling'])): ?>
        <div style="text-align:center; padding: 20px; font-size:13px; font-weight:700; color:#cbd5e1;">Drop Zone Empty
        </div>
        <?php
endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
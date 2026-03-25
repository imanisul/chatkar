<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
requireRole(['admin','marketing']);

$pageTitle = 'Edit Lead';
$db   = getDB();
$user = currentUser();
$id   = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM leads WHERE id=?");
$stmt->execute([$id]);
$lead = $stmt->fetch();
if (!$lead) redirect('index.php');

// Restriction: Marketing users only see their own leads
if ($user['role'] === 'marketing' && $lead['assigned_to'] != $user['id']) {
    redirect('index.php?error=unauthorized');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Security token mismatch. Please try again.";
    } else {
        $name       = sanitize($_POST['student_name'] ?? '');
        $class      = sanitize($_POST['class']        ?? '');
        $phone      = sanitize($_POST['phone']        ?? '');
        $source     = sanitize($_POST['source']       ?? '');
        $status     = sanitize($_POST['status']       ?? 'New');
        $notes      = sanitize($_POST['notes']        ?? '');
        $parent     = sanitize($_POST['parent_name']  ?? '');
        $email      = sanitize($_POST['email']        ?? '');
        $followup   = $_POST['followup_date'] ?? null;

        if (!$name) $errors[] = 'Name is required.';

        if (!$errors) {
            try {
                $db->prepare("UPDATE leads SET student_name=?,parent_name=?,class=?,phone=?,email=?,source=?,status=?,notes=?,followup_date=?,updated_at=NOW() WHERE id=?")
                   ->execute([$name,$parent,$class,$phone,$email,$source,$status,$notes,$followup?:null,$id]);
                logActivity($user['id'], "Updated lead: $name", 'leads');
                redirect('index.php?msg=updated');
            } catch (Exception $e) {
                $errors[] = "Database Error: " . $e->getMessage();
            }
        }
    }
}

$v = fn($f) => htmlspecialchars($_POST[$f] ?? $lead[$f] ?? '');
$root = '../../';
require_once '../../includes/header.php';
?>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> <?= $e ?></div><?php endforeach; ?>

<div class="breadcrumb">
    <a href="index.php"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg> Leads</a>
    <span class="sep">/</span>
    <span>Edit Lead</span>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg> Edit Lead - <?= sanitize($lead['student_name']) ?></div>
        <a href="index.php" class="btn btn-secondary btn-sm"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> Back</a>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <div class="form-grid">
                <div class="form-group">
                    <label>Student Name *</label>
                    <input type="text" name="student_name" value="<?= $v('student_name') ?>" required>
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="tel" name="phone" value="<?= $v('phone') ?>">
                </div>
                <div class="form-group">
                    <label>Class</label>
                    <input type="text" name="class" value="<?= $v('class') ?>">
                </div>
                <div class="form-group">
                    <label>Source</label>
                    <select name="source">
                        <option value="">Select Source</option>
                        <?php foreach (['Walk-in','Phone','Website','Social Media','Reference','WhatsApp','Google','Other'] as $src): ?>
                        <option value="<?= $src ?>" <?= ($lead['source']===$src)?'selected':'' ?>><?= $src ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <?php foreach (['New','To Call','Done Calling','Contacted','Message Sent','Converted','Rejected'] as $s): ?>
                        <option value="<?= $s ?>" <?= ($lead['status']===$s)?'selected':'' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Parent Name</label>
                    <input type="text" name="parent_name" value="<?= $v('parent_name') ?>" placeholder="Parent / guardian">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" value="<?= $v('email') ?>" placeholder="parent@email.com">
                </div>
                <div class="form-group">
                    <label><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> Followup Date</label>
                    <input type="date" name="followup_date" value="<?= $v('followup_date') ?>">
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label>Notes</label>
                    <textarea name="notes"><?= $v('notes') ?></textarea>
                </div>
            </div>
            <div class="form-actions">
                <a href="index.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg> Update Lead</button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

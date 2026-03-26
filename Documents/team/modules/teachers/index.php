<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
requireRole(['admin']);

$pageTitle = 'Teachers';
$db   = getDB();
$user = currentUser();

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        error_log("CSRF mismatch in teacher delete");
        redirect('index.php?error=csrf');
    }
    $delId = (int)$_POST['delete_id'];
    
    try {
        $db->beginTransaction();
        // Cleanup relative tables
        $db->prepare("DELETE FROM batch_teachers WHERE teacher_id=?")->execute([$delId]);
        $db->prepare("DELETE FROM teacher_subjects WHERE teacher_id=?")->execute([$delId]);
        $db->prepare("DELETE FROM teacher_irregularities WHERE teacher_id=?")->execute([$delId]);
        $db->prepare("DELETE FROM teacher_class_log WHERE teacher_id=?")->execute([$delId]);
        
        $db->prepare("DELETE FROM teachers WHERE user_id=?")->execute([$delId]);
        $db->prepare("DELETE FROM users WHERE id=? AND role='teacher'")->execute([$delId]);
        
        $db->commit();
        logActivity($user['id'], "Deleted teacher ID $delId", 'teachers');
        redirect('index.php?msg=deleted');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("Teacher delete failed: " . $e->getMessage());
        redirect('index.php?error=delete_failed');
    }
}

$search = $_GET['q'] ?? '';
$sql = "SELECT u.*, t.subject, t.qualification, t.experience,
        (SELECT COUNT(*) FROM teacher_irregularities ir WHERE ir.teacher_id=u.id AND ir.is_lop=1) as total_lops
        FROM users u LEFT JOIN teachers t ON t.user_id=u.id WHERE u.role='teacher'";
$params = [];
if ($search) {
    $sql .= " AND (u.name LIKE ? OR u.email LIKE ? OR t.subject LIKE ?)";
    $params = ["%$search%","%$search%","%$search%"];
}
$sql .= " ORDER BY u.created_at DESC";
$stmt = $db->prepare($sql); $stmt->execute($params);
$teachers = $stmt->fetchAll();

$root = '../../';
require_once '../../includes/header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success" data-auto-dismiss>
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg> Teacher
    <?= match(sanitize($_GET['msg'])) {'added'=>'added!','updated'=>'updated!',default=>'deleted.'} ?>
</div>
<?php endif; ?>

<div class="page-header">
    <div class="page-header-left">
        <h1><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:8px"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg> Teachers</h1>
        <p><?= count($teachers) ?> professional educators registered</p>
    </div>
    <div class="page-header-actions">
        <button data-export-table="teachersTable" class="btn btn-secondary"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg> Export</button>
        <a href="add.php" class="btn btn-primary"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Add Teacher</a>
    </div>
</div>

<div class="card">
    <div class="filter-bar">
        <form method="GET" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
            <div class="search-box" style="flex:1;min-width:220px">
                <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg></span>
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                    placeholder="Search name, email, subject..." data-search="teachersTable">
            </div>
            <button type="submit" class="btn btn-secondary btn-sm">Search</button>
            <?php if ($search): ?><a href="index.php" class="btn btn-secondary btn-sm"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg> Clear</a>
            <?php endif; ?>
        </form>
    </div>
<div class="section-card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Teacher Name</th>
                    <th>Contact Info</th>
                    <th>Expertise</th>
                    <th>Stats</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($teachers): foreach ($teachers as $t): ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:12px">
                            <div style="width:40px;height:40px;border-radius:12px;background:var(--blue-light);display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:900;color:var(--blue);border:2px solid var(--blue-mid);flex-shrink:0">
                                <?= strtoupper(substr($t['name'],0,1)) ?>
                            </div>
                            <div>
                                <div style="font-weight:800; color:var(--text); line-height:1.2"><?= sanitize($t['name']) ?></div>
                                <div style="font-size:11px; color:var(--text-light); font-weight:600; text-transform:uppercase; letter-spacing:0.3px; margin-top:2px"><?= sanitize($t['qualification']??'N/A') ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div style="font-size:13.5px; font-weight:600"><?= sanitize($t['email']) ?></div>
                        <div style="font-size:11.5px; color:var(--text-light); font-family:monospace"><?= sanitize($t['phone']??'-') ?></div>
                    </td>
                    <td>
                        <div style="display:inline-flex; align-items:center; gap:6px; background:var(--bg2); padding:4px 10px; border-radius:8px; border:1px solid var(--border)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="color:var(--blue)"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
                            <span style="font-size:12.5px; font-weight:700"><?= sanitize($t['subject']??'General') ?></span>
                        </div>
                    </td>
                    <td>
                        <div style="font-size:12px; font-weight:600; color:var(--text-mid)"><?= sanitize($t['experience']??'0') ?> Exp.</div>
                        <?php if (!empty($t['total_lops'])): ?>
                        <div style="margin-top:4px;"><span class="badge" style="background:#fee2e2; color:#b91c1c; font-size:10px; padding:2px 6px; letter-spacing:0.5px; border:1px solid #fca5a5;"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:text-bottom;margin-right:2px"><rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect><line x1="9" y1="1" x2="9" y2="4"></line><line x1="15" y1="1" x2="15" y2="4"></line><line x1="9" y1="20" x2="9" y2="23"></line><line x1="15" y1="20" x2="15" y2="23"></line><line x1="20" y1="9" x2="23" y2="9"></line><line x1="20" y1="14" x2="23" y2="14"></line><line x1="1" y1="9" x2="4" y2="9"></line><line x1="1" y1="14" x2="4" y2="14"></line></svg><?= $t['total_lops'] ?> LOPs</span></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-<?= $t['status']==='active'?'converted':'rejected' ?>">
                            <?= ucfirst($t['status']) ?>
                        </span></td>
                    <td>
                        <div style="display:flex;gap:8px">
                            <a href="edit.php?id=<?= $t['id'] ?>" class="btn btn-secondary btn-sm" title="Edit Teacher">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            </a>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="delete_id" value="<?= $t['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" data-confirm="Are you sure you want to delete this teacher? This action is irreversible.">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state">
                            <div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg></div>
                            <h3>No teachers found</h3>
                            <p>Try refining your search or add a new teacher.</p>
                            <a href="add.php" class="btn btn-primary btn-sm" style="margin-top:12px">Add First Teacher</a>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<?php require_once '../../includes/footer.php'; ?>
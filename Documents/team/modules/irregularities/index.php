<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/email.php';
requireLogin();

$pageTitle = 'Irregularities';
$db   = getDB();
$user = currentUser();
$role = $user['role'];
$canMark   = in_array($role, ['admin','mentor']);
$canManage = in_array($role, ['admin','mentor']);

// ── Add Irregularity (mentor/admin only) ─────────────────────
if ($canMark && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_irregularity'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        redirect("index.php?error=csrf");
    }
    $teacher_id = (int)$_POST['teacher_id'];
    $type       = sanitize($_POST['type']);
    $date       = $_POST['date'];
    $timetable_id = (int)($_POST['timetable_id'] ?? 0);
    $description  = sanitize($_POST['description'] ?? '');
    $severity     = sanitize($_POST['severity'] ?? 'Medium');

    if ($teacher_id && $type && $date) {
        try {
            $db->prepare("INSERT INTO teacher_irregularities (teacher_id, marked_by, type, date, timetable_id, description, severity, status)
                          VALUES (?,?,?,?,?,?,?,'Open')")
               ->execute([$teacher_id, $user['id'], $type, $date, $timetable_id, $description, $severity]);
            
            // Notify Teacher
            $tData = $db->prepare("SELECT name, email FROM users WHERE id=?");
            $tData->execute([$teacher_id]);
            $teacher = $tData->fetch();
            if ($teacher && $teacher['email']) {
                sendIrregularityAlert($teacher['email'], $teacher['name'], $type, $date, $severity, $description);
            }

            logActivity($user['id'], "Marked irregularity [$type] for teacher #$teacher_id on $date", 'irregularities');
            redirect('index.php?msg=marked');
        } catch(PDOException $e) {
            $dbError = $e->getMessage();
        }
    }
}

// ── Resolve Irregularity ─────────────────────────────────────
if ($canManage && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_id'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        redirect("index.php?error=csrf");
    }
    $note = sanitize($_POST['resolve_note'] ?? '');
    $isLop = isset($_POST['is_lop']) ? 1 : 0;
    try {
        $db->prepare("UPDATE teacher_irregularities SET status='Resolved', resolved_by=?, resolved_at=NOW(), resolve_note=?, is_lop=? WHERE id=?")
           ->execute([$user['id'], $note, $isLop, (int)$_POST['resolve_id']]);
    } catch (Exception $e) {
        // Fallback for older schema
        $db->prepare("UPDATE teacher_irregularities SET status='Resolved', resolved_by=?, resolved_at=NOW(), resolve_note=? WHERE id=?")
           ->execute([$user['id'], $note, (int)$_POST['resolve_id']]);
    }
    logActivity($user['id'], "Resolved irregularity #".(int)$_POST['resolve_id'], 'irregularities');
    redirect('index.php?msg=resolved');
}

// ── Submit Reason (teacher only) ─────────────────────────────
if ($role === 'teacher' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_reason_id'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        redirect("index.php?error=csrf");
    }
    $reason = sanitize($_POST['teacher_reason'] ?? '');
    $irrId = (int)$_POST['submit_reason_id'];
    
    // Ensure it belongs to the teacher and is Open
    $chk = $db->prepare("SELECT id FROM teacher_irregularities WHERE id=? AND teacher_id=? AND status='Open'");
    $chk->execute([$irrId, $user['id']]);
    if ($chk->fetch()) {
        try {
            $db->prepare("UPDATE teacher_irregularities SET teacher_reason=? WHERE id=?")
               ->execute([$reason, $irrId]);
        } catch(Exception $e) {}
        redirect('index.php?msg=reason_added');
    }
}

// ── Delete (admin only) ───────────────────────────────────────
if ($role === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        redirect("index.php?error=csrf");
    }
    $db->prepare("DELETE FROM teacher_irregularities WHERE id=?")->execute([(int)$_POST['delete_id']]);
    redirect('index.php?msg=deleted');
}

// ── Filters ───────────────────────────────────────────────────
$filterTeacher  = $_GET['teacher_id'] ?? '';
$filterType     = $_GET['type']       ?? '';
$filterStatus   = $_GET['status']     ?? '';
$filterSeverity = $_GET['severity']   ?? '';
$filterDateFrom = $_GET['date_from']  ?? '';
$filterDateTo   = $_GET['date_to']    ?? '';
$search         = $_GET['q']          ?? '';

$sql = "SELECT ir.*, 
               t.name AS teacher_name,
               m.name AS marker_name,
               rv.name AS resolver_name,
               tt.subject, tt.start_time, tt.class AS class_name
        FROM teacher_irregularities ir
        LEFT JOIN users t  ON ir.teacher_id  = t.id
        LEFT JOIN users m  ON ir.marked_by   = m.id
        LEFT JOIN users rv ON ir.resolved_by  = rv.id
        LEFT JOIN timetable tt ON ir.timetable_id = tt.id
        WHERE 1=1";
$params = [];

// Teacher sees only their own
if ($role === 'teacher') {
    $sql .= " AND ir.teacher_id=?"; $params[] = $user['id'];
}

if ($search)        { $sql .= " AND t.name LIKE ?";       $params[] = "%$search%"; }
if ($filterTeacher) { $sql .= " AND ir.teacher_id=?";     $params[] = (int)$filterTeacher; }
if ($filterType)    { $sql .= " AND ir.type=?";            $params[] = $filterType; }
if ($filterStatus)  { $sql .= " AND ir.status=?";          $params[] = $filterStatus; }
if ($filterSeverity){ $sql .= " AND ir.severity=?";        $params[] = $filterSeverity; }
if ($filterDateFrom){ $sql .= " AND ir.date>=?";           $params[] = $filterDateFrom; }
if ($filterDateTo)  { $sql .= " AND ir.date<=?";           $params[] = $filterDateTo; }

$sql .= " ORDER BY ir.date DESC, ir.created_at DESC";
$records = [];
try {
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $records = $stmt->fetchAll();
} catch (Exception $e) {}

// Teachers list
$teachers = $canMark ? $db->query("SELECT id, name FROM users WHERE role='teacher' ORDER BY name")->fetchAll() : [];

// Stats
$myStats = ['open'=>0, 'resolved'=>0, 'total'=>0, 'high'=>0, 'this_month'=>0, 'lop'=>0];
try {
    if ($role === 'teacher') {
        $s1 = $db->prepare("SELECT COUNT(*) FROM teacher_irregularities WHERE teacher_id=? AND status='Open'"); $s1->execute([$user['id']]); $myStats['open'] = (int)$s1->fetchColumn();
        $s2 = $db->prepare("SELECT COUNT(*) FROM teacher_irregularities WHERE teacher_id=? AND status='Resolved'"); $s2->execute([$user['id']]); $myStats['resolved'] = (int)$s2->fetchColumn();
        $s3 = $db->prepare("SELECT COUNT(*) FROM teacher_irregularities WHERE teacher_id=?"); $s3->execute([$user['id']]); $myStats['total'] = (int)$s3->fetchColumn();
        $s4 = $db->prepare("SELECT COUNT(*) FROM teacher_irregularities WHERE teacher_id=? AND is_lop=1"); $s4->execute([$user['id']]); $myStats['lop'] = (int)$s4->fetchColumn();
    } elseif ($canManage) {
        $myStats['open']     = (int)$db->query("SELECT COUNT(*) FROM teacher_irregularities WHERE status='Open'")->fetchColumn();
        $myStats['resolved'] = (int)$db->query("SELECT COUNT(*) FROM teacher_irregularities WHERE status='Resolved'")->fetchColumn();
        $myStats['high']     = (int)$db->query("SELECT COUNT(*) FROM teacher_irregularities WHERE severity='High' AND status='Open'")->fetchColumn();
        $myStats['total']    = (int)$db->query("SELECT COUNT(*) FROM teacher_irregularities")->fetchColumn();
        $myStats['this_month'] = (int)$db->query("SELECT COUNT(*) FROM teacher_irregularities WHERE MONTH(date)=MONTH(CURDATE()) AND YEAR(date)=YEAR(CURDATE())")->fetchColumn();
        $myStats['lop']      = (int)$db->query("SELECT COUNT(*) FROM teacher_irregularities WHERE is_lop=1")->fetchColumn();
    }
} catch (Exception $e) {}

// Timetable slots for modal (all teachers)
$slots = [];
if ($canMark) {
    try {
        $slots = $db->query("SELECT tt.id, tt.subject, tt.class AS class_name, tt.day, tt.start_time, u.name AS teacher_name, u.id AS teacher_id FROM timetable tt LEFT JOIN users u ON tt.teacher_id=u.id ORDER BY u.name, tt.day, tt.start_time")->fetchAll();
    } catch (Exception $e) {}
}

$TYPES = ['Absent','Late Arrival','Early Departure','Class Not Taken','Misbehavior','Incomplete Syllabus','Other'];
$SEVERITIES = ['Low','Medium','High'];

$root = '../../';
require_once '../../includes/header.php';
?>

<?php if (isset($dbError)): ?>
<div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> Error saving irregularity:
    <?= htmlspecialchars($dbError) ?>
</div>
<?php endif; ?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-<?= $_GET['msg']==='deleted'?'danger':'success' ?>" data-auto-dismiss>
    <?= match($_GET['msg']){'marked'=>'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> Irregularity marked successfully.','resolved'=>'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg> Marked as resolved.','reason_added'=>'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg> Reason submitted successfully.','deleted'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg> Record deleted.',default=>'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg> Done.'} ?>
</div>
<?php endif; ?>

<!-- Hero Banner -->
<div class="page-header mb-24">
    <div class="page-header-left">
        <h1 class="align-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
            <?= $role==='teacher' ? 'My Irregularities' : 'Teacher Irregularities' ?>
        </h1>
        <p>
            <?= $role==='teacher' ? 'Flags raised by your mentor or admin about your classes' : 'Track and manage teacher irregularities, absences and class issues' ?>
        </p>
    </div>
    <div class="page-header-actions">
        <?php if ($canMark): ?>
        <button class="btn btn-primary align-icon" onclick="openModal('addModal')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Mark Irregularity</button>
        <a href="../leave/index.php" class="btn btn-secondary align-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg> Leave Requests</a>
        <?php endif; ?>
    </div>
</div>

<!-- Bento Grid Stats -->
<style>
.stats-bento { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px; }
.bento-card {
    background: #fff; border-radius: 20px; padding: 24px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.03), 0 1px 2px rgba(0,0,0,0.02);
    border: 1px solid var(--border);
    display: flex; flex-direction: column; justify-content: center;
    position: relative; overflow: hidden;
}
.bento-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
}
.bc-red::before { background: #ef4444; }
.bc-green::before { background: #10b981; }
.bc-blue::before { background: #3b82f6; }

.bc-title { font-size: 14px; font-weight: 700; color: var(--text-mid); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
.bc-val-group { display: flex; align-items: baseline; gap: 16px; }
.bc-val { font-size: 36px; font-weight: 800; color: var(--text); line-height: 1; letter-spacing: -1px; }
.bc-sub { font-size: 14px; font-weight: 600; color: var(--text-light); }

.bc-multi-row { display: flex; align-items: center; justify-content: space-between; margin-top: 12px; }
.bc-mr-val { font-size: 20px; font-weight: 700; color: var(--text); }
.bc-mr-lbl { font-size: 12px; font-weight: 600; color: var(--text-mid); }

@media (max-width: 900px) {
    .stats-bento { grid-template-columns: 1fr; }
}
</style>

<div class="stats-bento">
    <?php if ($role === 'teacher'): ?>
        <!-- Col 1: Active -->
        <div class="bento-card bc-red">
            <div class="bc-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> My Action Required</div>
            <div class="bc-val-group">
                <div class="bc-val"><?= $myStats['open'] ?></div>
                <div class="bc-sub">Open Issues</div>
            </div>
        </div>
        <!-- Col 2: Resolved -->
        <div class="bento-card bc-green">
            <div class="bc-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Resolved</div>
            <div class="bc-val-group">
                <div class="bc-val"><?= $myStats['resolved'] ?></div>
            </div>
        </div>
        <!-- Col 3: Total -->
        <div class="bento-card bc-blue">
            <div class="bc-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg> Total Flags</div>
            <div class="bc-val-group">
                <div class="bc-val"><?= $myStats['total'] ?></div>
            </div>
        </div>
        <!-- Col 4: LOP -->
        <div class="bento-card bc-red" style="background: linear-gradient(to right, #fff, #fef2f2);">
            <div class="bc-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect><rect x="9" y="9" width="6" height="6"></rect><line x1="9" y1="1" x2="9" y2="4"></line><line x1="15" y1="1" x2="15" y2="4"></line><line x1="9" y1="20" x2="9" y2="23"></line><line x1="15" y1="20" x2="15" y2="23"></line><line x1="20" y1="9" x2="23" y2="9"></line><line x1="20" y1="14" x2="23" y2="14"></line><line x1="1" y1="9" x2="4" y2="9"></line><line x1="1" y1="14" x2="4" y2="14"></line></svg> Loss of Pay (LOP)</div>
            <div class="bc-val-group">
                <div class="bc-val" style="color: #b91c1c;"><?= $myStats['lop'] ?></div>
                <div class="bc-sub">Total LOPs</div>
            </div>
        </div>
    <?php else: ?>
        <!-- Col 1: Active Issues -->
        <div class="bento-card bc-red" style="background: linear-gradient(to right, #fff, #fef2f2);">
            <div class="bc-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> Active Issues</div>
            <div class="bc-val-group" style="margin-bottom: 8px;">
                <div class="bc-val" style="color: #b91c1c;"><?= $myStats['open'] ?></div>
                <div class="bc-sub">Open Records</div>
            </div>
            <div class="bc-multi-row" style="border-top: 1px solid rgba(239, 68, 68, 0.2); padding-top: 12px; margin-top: auto;">
                <span class="bc-mr-lbl" style="color: #991b1b; display: flex; align-items: center; gap: 4px;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg> High Severity</span>
                <span class="bc-mr-val" style="color: #991b1b;"><?= $myStats['high'] ?></span>
            </div>
        </div>
        
        <!-- Col 2: Efficiency (Resolved) -->
        <div class="bento-card bc-green">
            <div class="bc-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Efficiency</div>
            <div class="bc-val-group">
                <div class="bc-val"><?= $myStats['resolved'] ?></div>
            </div>
            <div class="bc-mr-lbl" style="margin-top: 16px;">Records Resolved</div>
        </div>

        <!-- Col 3: Overview -->
        <div class="bento-card bc-blue">
            <div class="bc-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> Overview</div>
            <div class="bc-multi-row">
                <span class="bc-mr-lbl">This Month</span>
                <span class="bc-mr-val"><?= $myStats['this_month'] ?></span>
            </div>
            <div class="bc-multi-row" style="margin-top: 8px;">
                <span class="bc-mr-lbl">Total Logged</span>
                <span class="bc-mr-val"><?= $myStats['total'] ?></span>
            </div>
        </div>

        <!-- Col 4: LOP count -->
        <div class="bento-card bc-red" style="background: linear-gradient(to right, #fff, #fef2f2);">
            <div class="bc-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect><rect x="9" y="9" width="6" height="6"></rect><line x1="9" y1="1" x2="9" y2="4"></line><line x1="15" y1="1" x2="15" y2="4"></line><line x1="9" y1="20" x2="9" y2="23"></line><line x1="15" y1="20" x2="15" y2="23"></line><line x1="20" y1="9" x2="23" y2="9"></line><line x1="20" y1="14" x2="23" y2="14"></line><line x1="1" y1="9" x2="4" y2="9"></line><line x1="1" y1="14" x2="4" y2="14"></line></svg> Loss of Pay</div>
            <div class="bc-val-group">
                <div class="bc-val" style="color: #b91c1c;"><?= $myStats['lop'] ?></div>
                <div class="bc-sub">Platform Wide LOPs</div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Filters -->
<!-- Inline Filter Bar -->
<style>
.inline-filters {
    display: flex; gap: 12px; align-items: center; flex-wrap: wrap;
    background: #fff; padding: 12px 16px; border-radius: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.03); border: 1px solid var(--border); margin-bottom: 24px;
}
.inline-filters .search-box {
    position: relative; flex: 1; min-width: 200px; max-width: 320px;
}
.inline-filters .search-box svg {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-light);
}
.inline-filters .search-box input {
    width: 100%; padding: 8px 12px 8px 36px; border: 1px solid var(--border); border-radius: 8px; font-weight: 500;
}
.inline-filters .filter-sel {
    padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px; font-weight: 500; color: var(--text-mid); background: #f8fafc; cursor: pointer;
}
.inline-filters .filter-date {
    display: flex; gap: 8px; align-items: center; background: #f8fafc; padding: 4px 12px; border-radius: 8px; border: 1px solid var(--border);
}
.inline-filters .filter-date input { border: none; background: transparent; font-weight: 500; color: var(--text-mid); outline: none; }
.inline-filters .filter-date span { color: var(--text-light); font-size: 13px; font-weight: 600; }
.inline-btn { padding: 8px 16px; border-radius: 8px; font-weight: 700; display: flex; align-items: center; gap: 6px; }
</style>

<form method="GET" class="inline-filters">
    <?php if ($canManage): ?>
    <div class="search-box">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <input type="text" name="q" placeholder="Search teacher alias..." value="<?= sanitize($search) ?>">
    </div>
    
    <select name="teacher_id" class="filter-sel">
        <option value="">All Teachers</option>
        <?php foreach ($teachers as $t): ?>
        <option value="<?= $t['id'] ?>" <?=$filterTeacher==$t['id']?'selected':'' ?>><?= sanitize($t['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <select name="type" class="filter-sel">
        <option value="">All Types</option>
        <?php foreach ($TYPES as $tp): ?>
        <option value="<?=$tp?>" <?=$filterType===$tp?'selected':'' ?>><?=$tp?></option>
        <?php endforeach; ?>
    </select>

    <select name="severity" class="filter-sel">
        <option value="">All Severity</option>
        <?php foreach ($SEVERITIES as $sv): ?>
        <option value="<?=$sv?>" <?=$filterSeverity===$sv?'selected':'' ?>><?=$sv?></option>
        <?php endforeach; ?>
    </select>

    <select name="status" class="filter-sel">
        <option value="">Status...</option>
        <option value="Open" <?=$filterStatus==='Open' ?'selected':'' ?>>Open</option>
        <option value="Resolved" <?=$filterStatus==='Resolved' ?'selected':'' ?>>Resolved</option>
    </select>

    <div class="filter-date d-none d-md-flex">
        <span>Range</span>
        <input type="date" name="date_from" value="<?= $filterDateFrom ?>" style="width:115px;">
        <span style="margin: 0 -4px">-</span>
        <input type="date" name="date_to" value="<?= $filterDateTo ?>" style="width:115px;">
    </div>

    <button type="submit" class="btn btn-primary inline-btn"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg> Filter</button>
    <?php if($search || $filterTeacher || $filterType || $filterSeverity || $filterStatus || $filterDateFrom): ?>
    <a href="index.php" class="text-muted" style="font-size: 13px; font-weight: 600; margin-left: 4px; text-decoration: none;">Reset</a>
    <?php endif; ?>
</form>

<!-- Records -->
<div class="section-card">
    <div class="section-header">
        <h3><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:4px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg> Records <span style="font-size:14px;color:var(--text-muted);font-weight:500">(
                <?= count($records) ?> found)
            </span></h3>
    </div>

    <?php if (empty($records)): ?>
    <div class="empty-state">
        <div style="font-size:48px;margin-bottom:12px">
            <?= $role==='teacher' ? '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>' : '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>' ?>
        </div>
        <h3>
            <?= $role==='teacher' ? 'No issues found!' : 'No irregularities recorded' ?>
        </h3>
        <p>
            <?= $role==='teacher' ? 'Great - no irregularities have been flagged for you.' : 'Use the "Mark Irregularity" button to log an issue.' ?>
        </p>
    </div>
    <?php else: ?>
<style>
/* Refined Irr Card */
.irr-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 20px 24px;
    display: flex;
    gap: 24px;
    position: relative;
    box-shadow: 0 2px 8px rgba(0,0,0,0.02);
    transition: all 0.2s;
    margin-bottom: 16px;
}
.irr-card:hover {
    box-shadow: 0 8px 24px rgba(0,0,0,0.06);
    transform: translateY(-2px);
}
.irr-card.irr-resolved { background: #f8fafc; border-color: rgba(16, 185, 129, 0.2); }

/* Left Pillar: Avatar & Teacher Info */
.irr-teacher-col {
    width: 200px;
    flex-shrink: 0;
    border-right: 1px solid var(--border);
    padding-right: 20px;
    display: flex;
    gap: 12px;
}
.irr-avatar-solid {
    width: 44px; height: 44px;
    border-radius: 50%;
    color: #fff;
    font-weight: 800; font-size: 16px;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    flex-shrink: 0;
}
.irr-avatar-solid.bg-1 { background: linear-gradient(135deg, #f43f5e, #be123c); }
.irr-avatar-solid.bg-2 { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
.irr-avatar-solid.bg-3 { background: linear-gradient(135deg, #10b981, #047857); }
.irr-avatar-solid.bg-4 { background: linear-gradient(135deg, #0ea5e9, #b45309); }
.irr-avatar-solid.bg-5 { background: linear-gradient(135deg, #8b5cf6, #5b21b6); }

/* Body Area */
.irr-body-col { flex: 1; min-width: 0; }

/* Status Bar Context */
.irr-status-bar {
    display: flex; gap: 8px; align-items: center; margin-bottom: 12px;
}

/* Timeline / Stepper */
.irr-timeline {
    position: relative;
    padding-left: 20px;
    margin-top: 16px;
}
.irr-timeline::before {
    content: ''; position: absolute; left: 6px; top: 6px; bottom: 0;
    width: 2px; background: #e2e8f0;
}
.irr-tl-step { position: relative; margin-bottom: 12px; }
.irr-tl-step:last-child { margin-bottom: 0; }
.irr-tl-step::before {
    content: ''; position: absolute; left: -20px; top: 4px;
    width: 14px; height: 14px; border-radius: 50%;
    background: #fff; border: 3px solid #cbd5e1; z-index: 2;
}
.irr-tl-step.step-red::before { border-color: #ef4444; }
.irr-tl-step.step-green::before { border-color: #10b981; }

.irr-tl-title { font-size: 13px; font-weight: 700; color: var(--text); }
.irr-tl-meta { font-size: 12px; color: var(--text-light); margin-top: 2px; }
.irr-tl-note { margin-top: 6px; background: #f8fafc; border-left: 3px solid #10b981; padding: 8px 12px; font-size: 13px; color: var(--text-mid); }

/* Custom Badge Overrides */
.irr-sev-badge { border-radius: 6px; font-weight: 800; font-size: 12px; padding: 4px 10px; }
.irr-sev-badge.sev-high { background: #fee2e2; color: #b91c1c; box-shadow: inset 0 0 0 1px #fca5a5; }

/* Three-Dot Menu */
.irr-menu-wrap { position: absolute; top: 16px; right: 20px; }
.irr-menu-btn {
    background: none; border: none; padding: 4px; border-radius: 6px;
    color: var(--text-light); cursor: pointer; transition: 0.2s;
}
.irr-menu-btn:hover { background: #f1f5f9; color: var(--text-mid); }
.irr-dropdown {
    position: absolute; right: 0; top: 100%; mt: 4px;
    background: #fff; border: 1px solid var(--border); border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1); width: 140px; z-index: 100;
    display: none; flex-direction: column; overflow: hidden;
}
.irr-dropdown.show { display: flex; }
.irr-dropdown button {
    background: none; border: none; width: 100%; text-align: left;
    padding: 10px 16px; font-size: 13px; font-weight: 600; color: var(--text-mid);
    cursor: pointer; display: flex; align-items: center; gap: 8px;
}
.irr-dropdown button:hover { background: #f8fafc; color: var(--text); }
.irr-dropdown button.text-danger { color: #dc2626; }
.irr-dropdown button.text-danger:hover { background: #fef2f2; }

@media (max-width: 768px) {
    .irr-card { flex-direction: column; gap: 16px; }
    .irr-teacher-col { width: 100%; border-right: none; border-bottom: 1px solid var(--border); padding-bottom: 16px; padding-right: 0; }
    .irr-menu-wrap { top: 16px; right: 16px; }
}
</style>

<script>
function toggleMenu(id) {
    document.querySelectorAll('.irr-dropdown').forEach(m => { if(m.id !== 'memu-'+id) m.classList.remove('show'); });
    document.getElementById('memu-'+id).classList.toggle('show');
}
document.addEventListener('click', function(e) {
    if(!e.target.closest('.irr-menu-wrap')) {
        document.querySelectorAll('.irr-dropdown').forEach(m => m.classList.remove('show'));
    }
});
</script>

    <div class="irr-list">
        <?php foreach ($records as $r):
            $sevClass = match($r['severity']){'High'=>'sev-high','Medium'=>'sev-medium','Low'=>'sev-low',default=>'sev-low'};
            $statusClass = $r['status']==='Resolved' ? 'irr-resolved' : 'irr-open';
            $typeIcon = match($r['type']){
                'Absent'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg>','Late Arrival'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>','Early Departure'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>',
                'Class Not Taken'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>','Misbehavior'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>','Incomplete Syllabus'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>',
                default=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>'
            };
            $bgId = (abs(crc32($r['teacher_name'] ?? 'A')) % 5) + 1;
        ?>
        <div class="irr-card <?= $statusClass ?>">
            
            <?php if ($canManage): ?>
            <!-- Three Dot Menu -->
            <div class="irr-menu-wrap">
                <button class="irr-menu-btn" onclick="toggleMenu(<?= $r['id'] ?>)"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"></circle><circle cx="12" cy="5" r="1"></circle><circle cx="12" cy="19" r="1"></circle></svg></button>
                <div class="irr-dropdown" id="memu-<?= $r['id'] ?>">
                    <?php if ($r['status']==='Open'): ?>
                    <button onclick="openResolve(<?= $r['id'] ?>)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Resolve</button>
                    <?php endif; ?>
                    <?php if ($role==='admin'): ?>
                    <form method="POST" onsubmit="return confirm('Delete this flag definitively?')">
                        <input type="hidden" name="delete_id" value="<?= $r['id'] ?>">
                        <button type="submit" class="text-danger"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg> Delete</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Teacher Identity Pillar -->
            <div class="irr-teacher-col">
                <div class="irr-avatar-solid bg-<?= $bgId ?>">
                    <?= strtoupper(substr($r['teacher_name']??'T',0,2)) ?>
                </div>
                <div>
                    <div style="font-weight:700; color:var(--text); font-size:15px; margin-bottom: 2px;">
                        <?= sanitize($r['teacher_name']??'-') ?>
                    </div>
                    <div style="font-size:12px; color:var(--text-light); display:flex; gap:4px; align-items:center;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        <?= date('d M Y', strtotime($r['date'])) ?>
                    </div>
                </div>
            </div>

            <!-- Content Area -->
            <div class="irr-body-col">
                
                <!-- Status Bar -->
                <div class="irr-status-bar">
                    <span class="irr-type-badge" style="padding: 4px 10px; font-size: 12px; border-radius: 6px;">
                        <?= $typeIcon ?> <?= $r['type'] ?>
                    </span>
                    <span class="irr-sev-badge <?= $sevClass ?>"><?= $r['severity'] ?></span>
                    <span class="irr-status-badge <?= $r['status']==='Resolved'?'isb-resolved':'isb-open' ?>" style="padding: 4px 10px; font-size: 12px; border-radius: 6px; box-shadow: none;">
                        <?= $r['status'] ?>
                    </span>
                    
                    <?php if ($r['subject']): ?>
                    <span style="font-size:12px; font-weight:600; color:var(--text-mid); background:#f1f5f9; padding:4px 10px; border-radius:6px; margin-left:auto; display:flex; align-items:center; gap:4px;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
                        <?= sanitize($r['subject']) ?> (<?= sanitize($r['class_name']??'') ?>) - <?= date('h:i A', strtotime($r['start_time'])) ?>
                    </span>
                    <?php endif; ?>
                </div>

                <?php if ($r['description']): ?>
                <div style="font-size:14px; color:var(--text); line-height:1.5; margin-bottom: 20px;">
                    <?= sanitize($r['description']) ?>
                </div>
                <?php endif; ?>

                <!-- Timeline Stepper -->
                <div class="irr-timeline">
                    <div class="irr-tl-step step-red">
                        <div class="irr-tl-title">Flag Raised</div>
                        <div class="irr-tl-meta">By <strong><?= sanitize($r['marker_name']??'-') ?></strong> · <?= date('d M Y, h:i A', strtotime($r['created_at'])) ?></div>
                    </div>
                    
                    <?php if (!empty($r['teacher_reason'])): ?>
                    <div class="irr-tl-step" style="--step-color:#3b82f6">
                        <div class="irr-tl-title" style="color:#3b82f6;">Teacher Reply</div>
                        <div class="irr-tl-note" style="border-left-color:#3b82f6"><?= nl2br(sanitize($r['teacher_reason'])) ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($r['status']==='Resolved'): ?>
                    <div class="irr-tl-step step-green">
                        <div class="irr-tl-title" style="color:#10b981;">Resolved</div>
                        <div class="irr-tl-meta">By <strong><?= sanitize($r['resolver_name']??'') ?></strong> · <?= date('d M Y, h:i A', strtotime($r['resolved_at'])) ?></div>
                        <?php if ($r['resolve_note']): ?>
                        <div class="irr-tl-note"><?= nl2br(sanitize($r['resolve_note'])) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($r['is_lop'])): ?>
                        <div style="margin-top:8px;"><span class="irr-sev-badge sev-high" style="display:inline-flex; align-items:center; gap:4px; font-size:11px; padding:2px 8px;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> LOP APPLIED</span></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($role==='teacher' && $r['status']==='Open' && empty($r['teacher_reason'])): ?>
                <div style="margin-top:16px;">
                    <button class="btn btn-secondary" style="font-size:12px; padding:6px 12px; display:inline-flex; align-items:center; gap:4px;" onclick="openReasonModal(<?= $r['id'] ?>)">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg> Submit Reason
                    </button>
                </div>
                <?php endif; ?>
                </div>

            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── ADD MODAL ───────────────────────────────────────────── -->
<?php if ($canMark): ?>
<div class="modal-overlay" id="addModal">
    <div class="modal" style="max-width:560px">
        <div class="modal-header">
            <h3><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> Mark Irregularity</h3>
            <button onclick="closeModal('addModal')" class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
        </div>
        <div class="modal-body">
            <form method="POST" id="addIrrForm">
                <?= csrfField() ?>
                <input type="hidden" name="add_irregularity" value="1">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Teacher *</label>
                        <select name="teacher_id" class="input" id="teacherSelect" required onchange="loadSlots()">
                            <option value="">Select Teacher...</option>
                            <?php foreach ($teachers as $t): ?>
                            <option value="<?= $t['id'] ?>">
                                <?= sanitize($t['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Date *</label>
                        <input type="date" name="date" class="input" id="irrDate" value="<?= date('Y-m-d') ?>" required
                            onchange="loadSlots()">
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Class / Slot (optional)</label>
                        <select name="timetable_id" class="input" id="slotSelect">
                            <option value="">- Select slot (optional) -</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Type *</label>
                        <select name="type" class="input" required>
                            <option value="">Select type...</option>
                            <?php foreach ($TYPES as $tp): ?>
                            <option value="<?=$tp?>">
                                <?=$tp?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Severity</label>
                        <select name="severity" class="input">
                            <?php foreach ($SEVERITIES as $sv): ?>
                            <option value="<?=$sv?>" <?=$sv==='Medium' ?'selected':''?>>
                                <?=$sv?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Description / Notes</label>
                        <textarea name="description" class="input" rows="3"
                            placeholder="Describe the issue briefly..."></textarea>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> Mark Irregularity</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Resolve Modal -->
<div class="modal-overlay" id="resolveModal">
    <div class="modal" style="max-width:440px">
        <div class="modal-header">
            <h3><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg> Resolve Irregularity</h3>
            <button onclick="closeModal('resolveModal')" class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="resolve_id" id="resolveId">
                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="d-flex align-items-center" style="gap:8px; cursor:pointer; padding: 12px; border: 1px solid #fca5a5; background: #fef2f2; border-radius: 8px;">
                        <input type="checkbox" name="is_lop" value="1" style="width:16px; height:16px; accent-color: #ef4444;">
                        <span style="font-weight:700; color:#b91c1c;">Mark as Loss of Pay (LOP)</span>
                    </label>
                </div>
                <div class="form-group">
                    <label>Resolution Note (optional)</label>
                    <textarea name="resolve_note" class="input" rows="3"
                        placeholder="Describe how this was resolved or any action taken..."></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('resolveModal')">Cancel</button>
                    <button type="submit" class="btn btn-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg> Mark Resolved</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Slot data from PHP
    const allSlots = <?= json_encode($slots) ?>;

    function loadSlots() {
        const tid = document.getElementById('teacherSelect').value;
        const date = document.getElementById('irrDate').value;
        const sel = document.getElementById('slotSelect');
        const day = date ? new Date(date).toLocaleDateString('en-US', { weekday: 'long' }) : '';
        sel.innerHTML = '<option value="">- Select slot (optional) -</option>';
        if (!tid) return;
        allSlots
            .filter(s => s.teacher_id == tid && (!day || s.day === day))
            .forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = `${s.day} · ${s.subject} · ${s.class_name} · ${s.start_time}`;
                sel.appendChild(opt);
            });
    }

    function openResolve(id) {
        document.getElementById('resolveId').value = id;
        openModal('resolveModal');
    }
</script>
<?php endif; ?>

<!-- Teacher Reason Modal -->
<?php if ($role === 'teacher'): ?>
<div class="modal-overlay" id="reasonModal">
    <div class="modal" style="max-width:440px">
        <div class="modal-header">
            <h3><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg> Submit Reason</h3>
            <button onclick="closeModal('reasonModal')" class="modal-close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="submit_reason_id" id="reasonId">
                <div class="form-group">
                    <label>Your Reason / Explanation</label>
                    <textarea name="teacher_reason" class="input" rows="4" required placeholder="Provide your reason for this irregularity..."></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('reasonModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg> Submit Reason</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
    function openReasonModal(id) {
        document.getElementById('reasonId').value = id;
        openModal('reasonModal');
    }
</script>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
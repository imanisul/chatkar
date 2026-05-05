<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
requireLogin();

$pageTitle = 'Syllabus & Topics';
$db   = getDB();
$user = currentUser();
$canManage = in_array($user['role'], ['admin','mentor']);
$isTeacher = $user['role'] === 'teacher';

// STRICT: Teacher sees only their allocated classes and subjects
// Pull from ALL sources: batch_teachers, batch_subjects, timetable, teacher_subjects
$teacherClasses  = [];
$teacherSubjects = [];
if ($isTeacher) {
    try {
        // Classes: from batch_teachers->batches + timetable
        $tc = $db->prepare("
            SELECT DISTINCT `class` FROM (
                SELECT b.`class` FROM batch_teachers bt
                JOIN batches b ON b.id = bt.batch_id
                WHERE bt.teacher_id = ? AND b.`class` IS NOT NULL AND b.`class` != ''
                UNION
                SELECT `class` FROM timetable
                WHERE teacher_id = ? AND `class` IS NOT NULL AND `class` != ''
            ) AS teacher_classes
            ORDER BY `class`
        ");
        $tc->execute([$user['id'], $user['id']]);
        $teacherClasses = $tc->fetchAll(PDO::FETCH_COLUMN);
    } catch(Exception $e) {}
    try {
        // Subjects: from batch_teachers + batch_subjects + timetable + teacher_subjects
        $ts2 = $db->prepare("
            SELECT DISTINCT subject FROM (
                SELECT bt.subject FROM batch_teachers bt
                WHERE bt.teacher_id = ? AND bt.subject IS NOT NULL AND bt.subject != ''
                UNION
                SELECT bs.subject FROM batch_subjects bs
                JOIN batch_teachers bt ON bt.batch_id = bs.batch_id
                WHERE bt.teacher_id = ? AND bs.subject IS NOT NULL AND bs.subject != ''
                UNION
                SELECT subject FROM timetable
                WHERE teacher_id = ? AND subject IS NOT NULL AND subject != ''
                UNION
                SELECT subject FROM teacher_subjects
                WHERE teacher_id = ? AND subject IS NOT NULL AND subject != ''
            ) AS teacher_subjs
            ORDER BY subject
        ");
        $ts2->execute([$user['id'], $user['id'], $user['id'], $user['id']]);
        $teacherSubjects = $ts2->fetchAll(PDO::FETCH_COLUMN);
    } catch(Exception $e) {}
}

// ── Add Topic ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_topic'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        redirect('index.php?error=csrf');
    }
    if ($canManage || $isTeacher) {
        $cls=sanitize($_POST['class']??''); $subj=sanitize($_POST['subject']??'');
        $topic=sanitize($_POST['topic']??''); $tid=$isTeacher?$user['id']:(int)($_POST['teacher_id']??0);
        if ($cls&&$subj&&$topic) {
            try {
                $db->prepare("INSERT INTO syllabus (class,subject,topic,status,teacher_id) VALUES (?,?,?,'Pending',?)")
                   ->execute([$cls,$subj,$topic,$tid?:null]);
                logActivity($user['id'],"Added topic: $topic",'syllabus');
            } catch (Exception $e) {
                error_log("Add Topic Error: " . $e->getMessage());
            }
        }
        redirect('index.php?msg=added&class='.urlencode($cls).'&subject='.urlencode($subj));
    }
}

// ── Toggle Topic Status ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['toggle_id'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        redirect('index.php?error=csrf&class='.urlencode($_POST['stay_class']??'').'&subject='.urlencode($_POST['stay_subj']??''));
    }
    $id=(int)$_POST['toggle_id']; $cur=$_POST['current_status']??'Pending';
    $new=$cur==='Completed'?'Pending':'Completed';
    
    // IDOR Protection: Check if teacher owns this class/subject
    $canModify = $canManage;
    if (!$canModify && $isTeacher) {
        $check = $db->prepare("SELECT class, subject FROM syllabus WHERE id = ?");
        $check->execute([$id]);
        $topic = $check->fetch();
        if ($topic && in_array($topic['class'], $teacherClasses) && in_array($topic['subject'], $teacherSubjects)) {
            $canModify = true;
        }
    }

    if ($id && $canModify) {
        if ($new==='Completed') {
            $db->prepare("UPDATE syllabus SET status=?,completed_by=?,completed_at=NOW() WHERE id=?")->execute([$new,$user['id'],$id]);
        } else {
            $db->prepare("UPDATE syllabus SET status=?,completed_by=NULL,completed_at=NULL WHERE id=?")->execute([$new,$id]);
        }
    }
    redirect('index.php?class='.urlencode($_POST['stay_class']??'').'&subject='.urlencode($_POST['stay_subj']??'').'&msg=updated');
}

// ── Delete Topic ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_id'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        redirect('index.php?error=csrf');
    }
    if ($canManage) {
        try {
            $db->prepare("DELETE FROM syllabus WHERE id=?")->execute([(int)$_POST['delete_id']]);
        } catch (Exception $e) {
            error_log("Delete Topic Error: " . $e->getMessage());
        }
        redirect('index.php?class='.urlencode($_POST['stay_class']??'').'&msg=deleted');
    }
}

// ── Add Chapter ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_chapter'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        redirect('index.php?error=csrf');
    }
    if ($canManage || $isTeacher) {
        $cls=sanitize($_POST['class']??''); $subj=sanitize($_POST['subject']??'');
        $chName=sanitize($_POST['chapter_name']??''); $ord=(int)($_POST['chapter_order']??0);
        $tid=$isTeacher?$user['id']:(int)($_POST['teacher_id']??0);
        if ($cls&&$subj&&$chName) {
            try {
                $db->prepare("INSERT INTO chapters (class,subject,chapter_name,chapter_order,teacher_id) VALUES (?,?,?,?,?)")
                   ->execute([$cls,$subj,$chName,$ord,$tid?:null]);
                logActivity($user['id'],"Added chapter: $chName",'syllabus');
            } catch (Exception $e) {
                error_log("Add Chapter Error: " . $e->getMessage());
            }
        }
        redirect('index.php?msg=ch_added&class='.urlencode($cls).'&subject='.urlencode($subj));
    }
}

// ── Toggle Chapter Status ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['toggle_chapter'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        redirect('index.php?error=csrf&class='.urlencode($_POST['stay_class']??'').'&subject='.urlencode($_POST['stay_subj']??''));
    }
    $id=(int)$_POST['chapter_id'];
    
    // IDOR Protection
    $canModify = $canManage;
    if (!$canModify && $isTeacher) {
        $check = $db->prepare("SELECT class, subject FROM chapters WHERE id = ?");
        $check->execute([$id]);
        $chData = $check->fetch();
        if ($chData && in_array($chData['class'], $teacherClasses) && in_array($chData['subject'], $teacherSubjects)) {
            $canModify = true;
        }
    }

    if ($id && $canModify) {
        $s=$db->prepare("SELECT status FROM chapters WHERE id=?"); $s->execute([$id]); $ch=$s->fetch();
        $new=($ch['status']==='Completed')?'In Progress':'Completed';
        if ($new==='Completed') {
            $db->prepare("UPDATE chapters SET status=?,completed_by=?,completed_at=NOW() WHERE id=?")->execute([$new,$user['id'],$id]);
        } else {
            $db->prepare("UPDATE chapters SET status=?,completed_by=NULL,completed_at=NULL WHERE id=?")->execute([$new,$id]);
        }
        logActivity($user['id'],"Chapter $new: ID $id",'syllabus');
    }
    redirect('index.php?class='.urlencode($_POST['stay_class']??'').'&subject='.urlencode($_POST['stay_subj']??'').'&msg=ch_updated');
}

// ── Filters ───────────────────────────────────────────────
$selClass   = $_GET['class']   ?? '';
$selSubject = $_GET['subject'] ?? '';

if ($isTeacher) {
    // Auto-select first class
    if (!$selClass && !empty($teacherClasses)) $selClass = $teacherClasses[0];
    // Auto-select first subject for selected class
    if (!$selSubject && $selClass) {
        try {
            $ts3 = $db->prepare("SELECT DISTINCT subject FROM timetable WHERE teacher_id=? AND class=? LIMIT 1");
            $ts3->execute([$user['id'], $selClass]); $r3 = $ts3->fetch();
            if ($r3) $selSubject = $r3['subject'];
        } catch(Exception $e) {}
    }
}

// Classes available to current user
if ($isTeacher) {
    $allClasses = $teacherClasses;
} else {
    $allClasses = [];
    try {
        $classes = $db->query("SELECT DISTINCT class FROM syllabus ORDER BY class")->fetchAll(PDO::FETCH_COLUMN);
        $stdClasses = $db->query("SELECT DISTINCT class FROM students WHERE class IS NOT NULL AND class!='' ORDER BY class")->fetchAll(PDO::FETCH_COLUMN);
        $allClasses = array_unique(array_merge($classes, $stdClasses));
        sort($allClasses);
    } catch(Exception $e) {}
    if (empty($allClasses)) $allClasses=['Class 8','Class 9','Class 10'];
}

$teachers = [];
try { $teachers = $db->query("SELECT u.id,u.name FROM users u WHERE u.role='teacher' AND u.status='active' ORDER BY u.name")->fetchAll(); } catch(Exception $e) {}

// Subjects for selected class (teacher: only their assigned subjects for that class)
$subjects=[];
if ($selClass) {
    try {
        if ($isTeacher) {
            // Pull subjects from ALL allocation sources for this specific class
            $ss=$db->prepare("
                SELECT DISTINCT subject FROM (
                    SELECT bt.subject FROM batch_teachers bt
                    JOIN batches b ON b.id = bt.batch_id
                    WHERE bt.teacher_id = ? AND b.`class` = ? AND bt.subject IS NOT NULL AND bt.subject != ''
                    UNION
                    SELECT bs.subject FROM batch_subjects bs
                    JOIN batches b ON b.id = bs.batch_id
                    JOIN batch_teachers bt ON bt.batch_id = b.id
                    WHERE bt.teacher_id = ? AND b.`class` = ? AND bs.subject IS NOT NULL AND bs.subject != ''
                    UNION
                    SELECT subject FROM timetable
                    WHERE teacher_id = ? AND `class` = ? AND subject IS NOT NULL AND subject != ''
                    UNION
                    SELECT subject FROM teacher_subjects
                    WHERE teacher_id = ? AND subject IS NOT NULL AND subject != ''
                ) AS teacher_subjects_for_class
                ORDER BY subject
            ");
            $ss->execute([$user['id'],$selClass,$user['id'],$selClass,$user['id'],$selClass,$user['id']]);
            $subjects=$ss->fetchAll(PDO::FETCH_COLUMN);
            // Fallback: if no class-specific subjects, use all teacher subjects
            if (empty($subjects)) $subjects = $teacherSubjects;
        } else {
            $ss=$db->prepare("SELECT DISTINCT subject FROM syllabus WHERE class=? ORDER BY subject");
            $ss->execute([$selClass]); $subjects=$ss->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch(Exception $e) {}
}

// Topics - teacher restricted to their class+subject
$topicSql = "SELECT s.*,u.name as comp_name FROM syllabus s LEFT JOIN users u ON s.completed_by=u.id WHERE 1=1";
$topicParams = [];
if ($selClass)   { $topicSql.=" AND s.class=?";   $topicParams[]=$selClass; }
if ($selSubject) { $topicSql.=" AND s.subject=?";  $topicParams[]=$selSubject; }
// Teacher: enforce class+subject filter strictly
if ($isTeacher) {
    if (!$selClass && !empty($teacherClasses)) { 
        $topicSql.=" AND s.class IN (".implode(',',array_fill(0,count($teacherClasses),'?')).")"; 
        $topicParams = array_merge($topicParams,$teacherClasses); 
    }
    if (!$selSubject) { 
        if ($selClass && !empty($subjects)) {
            // Restrict to class-specific subjects
            $topicSql.=" AND s.subject IN (".implode(',',array_fill(0,count($subjects),'?')).")"; 
            $topicParams = array_merge($topicParams,$subjects);
        } elseif (!$selClass && !empty($teacherSubjects)) {
            // Fallback to all their subjects if no class selected
            $topicSql.=" AND s.subject IN (".implode(',',array_fill(0,count($teacherSubjects),'?')).")"; 
            $topicParams = array_merge($topicParams,$teacherSubjects);
        }
    }
}
$topicSql.=" ORDER BY s.subject,s.id";
$allTopics = [];
try { $ts=$db->prepare($topicSql); $ts->execute($topicParams); $allTopics=$ts->fetchAll(); } catch(Exception $e) {}

// Group by subject
$bySubject=[];
foreach ($allTopics as $t) $bySubject[$t['subject']][]=$t;

// Chapters (teacher restricted)
$chapters=[];
if ($selClass) {
    $cSql="SELECT c.*,u.name as teacher_name,cb.name as comp_name FROM chapters c LEFT JOIN users u ON c.teacher_id=u.id LEFT JOIN users cb ON c.completed_by=cb.id WHERE c.class=?";
    $cParams=[$selClass];
    if ($selSubject) { $cSql.=" AND c.subject=?"; $cParams[]=$selSubject; }
    if ($isTeacher && !$selSubject && !empty($subjects)) { $cSql.=" AND c.subject IN (".implode(',',array_fill(0,count($subjects),'?')).")"; $cParams=array_merge($cParams,$subjects); }
    $cSql.=" ORDER BY c.subject,c.chapter_order,c.id";
    try { $cs=$db->prepare($cSql); $cs->execute($cParams); $chapters=$cs->fetchAll(); } catch(Exception $e) {}
}
$chBySubj=[];
$chapterLogs=[];
if ($chapters) {
    $chIds = array_column($chapters, 'id');
    if ($chIds) {
        $lStmt = $db->prepare("SELECT chapter_id, date, topic_taught FROM teacher_class_log WHERE chapter_id IN (".implode(',', $chIds).") ORDER BY date DESC");
        $lStmt->execute();
        foreach ($lStmt->fetchAll() as $l) { $chapterLogs[$l['chapter_id']][] = $l; }
    }
}
foreach ($chapters as $c) $chBySubj[$c['subject']][]=$c;

// Overall progress per class
$progressByClass=[];
try {
    foreach ($db->query("SELECT class,COUNT(*) as total,SUM(status='Completed') as done FROM syllabus GROUP BY class")->fetchAll() as $r) {
        $progressByClass[$r['class']]=['total'=>$r['total'],'done'=>$r['done'],'pct'=>$r['total']>0?round($r['done']/$r['total']*100):0];
    }
} catch(Exception $e) {}

$root='../../'; require_once '../../includes/header.php'; ?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'csrf'): ?>
<div class="alert alert-danger" data-auto-dismiss>⚠️ Security token mismatch. Please try again.</div>
<?php endif; ?>
<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success" data-auto-dismiss><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg>
    <?= match($_GET['msg']){'added'=>'Topic added!','updated'=>'Status updated!','deleted'=>'Deleted.','ch_added'=>'Chapter added!','ch_updated'=>'Chapter updated!',default=>'Done!'} ?>
</div>
<?php endif; ?>

<div class="page-header mb-24">
    <div class="page-header-left">
        <h1 class="align-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg> Syllabus</h1>
        <p>Track chapters and topic completion</p>
    </div>
    <div class="page-header-actions">
        <?php if ($canManage || $isTeacher): ?>
        <button class="btn btn-primary align-icon" onclick="openModal('addTopicModal')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Add Topic</button>
        <button class="btn btn-secondary align-icon" onclick="openModal('addChapterModal')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg> Add Chapter</button>
        <?php endif; ?>
    </div>
</div>

<!-- Class Progress Cards (when no class selected) -->
<?php if (!$selClass): ?>
<div class="stats-grid three-col mb-24">
    <?php foreach ($allClasses as $cls):
        $p=$progressByClass[$cls]??['total'=>0,'done'=>0,'pct'=>0];
        $pct=$p['pct']; $col=$pct>=75?'var(--green)':($pct>=50?'var(--amber)':'var(--red)');
    ?>
    <a href="?class=<?= urlencode($cls) ?>" style="text-decoration:none">
        <div class="card" style="padding:22px;transition:all .2s;border:1.5px solid var(--border-light)">
            <div style="font-weight:900;font-size:16px;margin-bottom:12px;color:var(--text);letter-spacing:-0.3px">
                <?= sanitize($cls) ?>
            </div>
            <div style="height:8px;background:var(--bg2);border-radius:99px;overflow:hidden;margin-bottom:10px">
                <div
                    style="height:100%;width:<?= $pct ?>%;background:<?= $col ?>;border-radius:99px;transition:width .3s;box-shadow:0 0 10px <?= $col ?>44">
                </div>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--text-mid);font-weight:700">
                <span>
                    <?= $p['done'] ?> / <?= $p['total'] ?> topics
                </span>
                <span style="color:<?= $col ?>">
                    <?= $pct ?>%
                </span>
            </div>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card" style="margin-bottom:28px">
    <div class="card-body" style="padding:18px 20px">
        <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
            <select name="class" onchange="this.form.submit()"
                style="padding:11px 16px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-size:13.5px;min-width:150px;font-weight:600">
                <option value="">All Classes</option>
                <?php foreach ($allClasses as $c): ?>
                <option value="<?= $c ?>" <?=$selClass===$c?'selected':'' ?>>
                    <?= $c ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($selClass): ?>
            <select name="subject" onchange="this.form.submit()"
                style="padding:11px 16px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-size:13.5px;min-width:180px;font-weight:600">
                <option value="">All Subjects</option>
                <?php foreach ($subjects as $s): ?>
                <option value="<?= $s ?>" <?=$selSubject===$s?'selected':'' ?>>
                    <?= $s ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <?php if ($selClass): ?><a href="?" class="btn btn-outline-secondary btn-sm align-icon" style="border-width:2px;font-weight:700"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg> Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if ($selClass): ?>

<!-- Chapters -->
<?php if ($chapters): ?>
<div class="card mb-24">
    <div class="card-header">
        <div class="card-title align-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg> Chapters -
            <?= sanitize($selClass) ?>
            <?= $selSubject?' · '.sanitize($selSubject):'' ?>
        </div>
    </div>
    <div class="card-body" style="padding:12px">
        <?php foreach ($chBySubj as $subj => $chs): ?>
        <div style="margin-bottom:14px">
            <div
                style="font-size:12px;font-weight:800;color:var(--text-light);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;padding:0 4px">
                <?= sanitize($subj) ?>
            </div>
            <?php foreach ($chs as $ch):
                $isDone = $ch['status']==='Completed';
                $isIP   = $ch['status']==='In Progress';
            ?>
            <div
                style="display:flex;align-items:center;gap:10px;padding:12px;border-radius:var(--r);margin-bottom:6px;background:<?= $isDone?'var(--green-light)':($isIP?'var(--amber-light)':'var(--bg2)') ?>;border:1.5px solid <?= $isDone?'var(--green-mid)':($isIP?'var(--amber-mid)':'var(--border)') ?>">
                <div style="font-size:18px">
                    <?= $isDone?'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg>':($isIP?'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>':'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>') ?>
                </div>
                <div style="flex:1">
                    <div
                        style="font-weight:700;font-size:13px;<?= $isDone?'text-decoration:line-through;color:var(--text-mid)':'' ?>">
                        <?= sanitize($ch['chapter_name']) ?>
                    </div>

                    <!-- Class History for this Chapter -->
                    <?php if (!empty($chapterLogs[$ch['id']])): ?>
                    <div
                        style="margin-top:6px; background:rgba(255,255,255,0.5); padding:8px; border-radius:8px; border:1px solid rgba(0,0,0,0.05)">
                        <div
                            style="font-size:10px; font-weight:800; color:var(--text-light); text-transform:uppercase; margin-bottom:4px">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg> Class History</div>
                        <?php foreach ($chapterLogs[$ch['id']] as $log): ?>
                        <div style="font-size:11.5px; color:var(--text-mid); margin-bottom:3px; display:flex; gap:6px">
                            <span style="color:var(--blue); font-weight:700; white-space:nowrap">
                                <?= date('d M', strtotime($log['date'])) ?>:
                            </span>
                            <span>
                                <?= sanitize($log['topic_taught']) ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div style="font-size:11px;color:var(--text-light);margin-top:4px"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        <?= sanitize($ch['teacher_name'] ?? "") ?>
                    </div>
                    <?php if ($isDone && $ch['comp_name']): ?>
                    <div style="font-size:11px;color:var(--green)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg> Done by
                        <?= sanitize($ch['comp_name']) ?> ·
                        <?= date('d M Y',strtotime($ch['completed_at'])) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($canManage || $isTeacher): ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <input type="hidden" name="toggle_chapter" value="1">
                    <input type="hidden" name="chapter_id" value="<?= $ch['id'] ?>">
                    <input type="hidden" name="stay_class" value="<?= urlencode($selClass) ?>">
                    <input type="hidden" name="stay_subj" value="<?= urlencode($selSubject) ?>">
                    <button type="submit" class="btn <?= $isDone?'btn-secondary':'btn-success' ?> btn-sm align-icon">
                        <?= $isDone?'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg> Reopen':'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Mark Done' ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Topics -->
<?php if ($bySubject): ?>
<?php foreach ($bySubject as $subj => $topics):
    $done = count(array_filter($topics,fn($t)=>$t['status']==='Completed'));
    $total = count($topics);
    $pct = $total>0?round($done/$total*100):0;
    $col=$pct>=75?'var(--green)':($pct>=50?'var(--amber)':'var(--red)');
?>
<div class="card" style="margin-bottom:18px">
    <div class="card-header">
        <div class="card-title">
            <?= sanitize($subj) ?>
        </div>
        <div style="display:flex;align-items:center;gap:10px">
            <span style="font-size:13px;font-weight:700;color:<?= $col ?>">
                <?= $done ?>/
                <?= $total ?> (
                <?= $pct ?>%)
            </span>
            <div style="width:80px;height:6px;background:var(--border);border-radius:99px;overflow:hidden">
                <div style="height:100%;width:<?= $pct ?>%;background:<?= $col ?>;border-radius:99px"></div>
            </div>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Topic</th>
                    <th>Status</th>
                    <?php if ($canManage): ?>
                    <th>Completed By</th>
                    <?php endif; ?>
                    <?php if ($canManage || $isTeacher): ?>
                    <th>Action</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topics as $i => $t): ?>
                <tr style="<?= $t['status']==='Completed'?'background:var(--green-light)':'' ?>">
                    <td class="font-mono text-muted" style="font-size:11px">
                        <?= $i+1 ?>
                    </td>
                    <td
                        style="<?= $t['status']==='Completed'?'text-decoration:line-through;color:var(--text-mid)':'' ?>">
                        <strong>
                            <?= sanitize($t['topic']) ?>
                        </strong></td>
                    <td><span class="badge <?= $t['status']==='Completed'?'badge-green':'badge-gray' ?>">
                            <?= $t['status'] ?>
                        </span></td>
                    <?php if ($canManage): ?>
                    <td style="font-size:12px;color:var(--text-mid)">
                        <?= $t['comp_name']?sanitize($t['comp_name']):'-' ?>
                    </td>
                    <?php endif; ?>
                    <?php if ($canManage || $isTeacher): ?>
                    <td>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                            <input type="hidden" name="toggle_id" value="<?= $t['id'] ?>">
                            <input type="hidden" name="current_status" value="<?= $t['status'] ?>">
                            <input type="hidden" name="stay_class" value="<?= urlencode($selClass) ?>">
                            <input type="hidden" name="stay_subj" value="<?= urlencode($selSubject) ?>">
                            <button type="submit"
                                class="btn <?= $t['status']==='Completed'?'btn-secondary':'btn-success' ?> btn-sm"
                                style="font-size:11px">
                                <?= $t['status']==='Completed'?'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>':'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>' ?>
                            </button>
                        </form>
                        <?php if ($canManage): ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                            <input type="hidden" name="delete_id" value="<?= $t['id'] ?>">
                            <input type="hidden" name="stay_class" value="<?= urlencode($selClass) ?>">
                            <button type="submit" class="btn btn-danger btn-sm" style="font-size:11px"
                                data-confirm="Delete this topic?"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="empty-state" style="padding:50px">
    <div class="empty-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg></div>
    <h3>No topics found for
        <?= sanitize($selClass) ?>
        <?= $selSubject?' · '.sanitize($selSubject):'' ?>
    </h3>
    <?php if ($canManage || $isTeacher): ?><button class="btn btn-primary" onclick="openModal('addTopicModal')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Add First Topic</button>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php else: ?>
<?php if (!$allClasses): ?>
<div class="empty-state" style="padding:60px">
    <div class="empty-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg></div>
    <h3>Select a class above to view syllabus</h3>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Add Topic Modal -->
<div class="modal-overlay" id="addTopicModal">
    <div class="modal" style="max-width:500px">
        <div class="modal-header">
            <div class="modal-title"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Add Topic</div>
            <button class="modal-close" onclick="closeModal('addTopicModal')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="add_topic" value="1">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Class *</label>
                        <select name="class" required>
                            <option value="">Select Class</option>
                            <?php foreach ($allClasses as $c): ?>
                            <option value="<?= $c ?>" <?=$selClass===$c?'selected':'' ?>>
                                <?= $c ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Subject *</label>
                        <select name="subject" required>
                            <option value="">- Select Subject -</option>
                            <?php
                            // Teachers only see their allocated subjects; admin/mentor see all
                            if ($isTeacher && !empty($teacherSubjects)) {
                                $platformSubjs = $teacherSubjects;
                            } else {
                                $platformSubjs=$db->query("SELECT DISTINCT subject FROM syllabus WHERE subject!='' ORDER BY subject")->fetchAll(PDO::FETCH_COLUMN);
                                if(empty($platformSubjs)) $platformSubjs=['English Grammar','EVS','Maths','Science','Social Science'];
                            }
                            foreach($platformSubjs as $ps): ?>
                            <option value="<?= htmlspecialchars($ps) ?>" <?=$selSubject===$ps?'selected':'' ?>>
                                <?= $ps ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Topic Name *</label>
                        <input type="text" name="topic" placeholder="e.g. Real Numbers" required>
                    </div>
                    <?php if ($canManage): ?>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Assign Teacher</label>
                        <select name="teacher_id">
                            <option value="">- No Teacher -</option>
                            <?php foreach ($teachers as $t): ?>
                            <option value="<?= $t['id'] ?>">
                                <?= sanitize($t['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
                    <button type="button" class="btn btn-secondary"
                        onclick="closeModal('addTopicModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg> Add Topic</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Chapter Modal -->
<div class="modal-overlay" id="addChapterModal">
    <div class="modal" style="max-width:500px">
        <div class="modal-header">
            <div class="modal-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg> Add Chapter</div>
            <button class="modal-close" onclick="closeModal('addChapterModal')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="add_chapter" value="1">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Class *</label>
                        <select name="class" required>
                            <option value="">Select Class</option>
                            <?php foreach ($allClasses as $c): ?>
                            <option value="<?= $c ?>" <?=$selClass===$c?'selected':'' ?>>
                                <?= $c ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Subject *</label>
                        <select name="subject" required>
                            <option value="">- Select Subject -</option>
                            <?php
                            // Reuse the same filtered subject list
                            $chapterSubjs = ($isTeacher && !empty($teacherSubjects)) ? $teacherSubjects : ($platformSubjs??['English Grammar','EVS','Maths','Science','Social Science']);
                            foreach($chapterSubjs as $ps): ?>
                            <option value="<?= htmlspecialchars($ps) ?>" <?=$selSubject===$ps?'selected':'' ?>>
                                <?= $ps ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Chapter Name *</label>
                        <input type="text" name="chapter_name" placeholder="e.g. Real Numbers" required>
                    </div>
                    <div class="form-group">
                        <label>Order</label>
                        <input type="number" name="chapter_order" placeholder="1, 2, 3..." min="0">
                    </div>
                    <?php if ($canManage): ?>
                    <div class="form-group">
                        <label>Assign Teacher</label>
                        <select name="teacher_id">
                            <option value="">- Select -</option>
                            <?php foreach ($teachers as $t): ?>
                            <option value="<?= $t['id'] ?>">
                                <?= sanitize($t['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
                    <button type="button" class="btn btn-secondary"
                        onclick="closeModal('addChapterModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg> Add Chapter</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
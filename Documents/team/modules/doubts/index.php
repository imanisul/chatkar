<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/email.php';
require_once '../../includes/student_notifications.php';
requireRole(['admin','mentor','teacher']);

$pageTitle = 'Doubt Sessions';
$db   = getDB();
$user = currentUser();
$canManage = in_array($user['role'], ['admin','mentor']);
$isTeacher = $user['role'] === 'teacher';

// ── Reply to a doubt (teacher/admin) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['reply_doubt'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        error_log("CSRF mismatch in doubt reply");
        redirect('index.php?error=csrf');
    }
    $doubtId = (int)$_POST['doubt_id'];
    $reply   = sanitize($_POST['reply_notes'] ?? '');
    if ($doubtId && $reply) {
        try {
            // Update the doubt with reply notes and mark as Completed
            $db->prepare("UPDATE doubt_sessions SET notes=?, status='Completed', teacher_id=? WHERE id=?")
               ->execute([$reply, $user['id'], $doubtId]);

            // Fetch doubt + student info for notification
            $dq = $db->prepare("SELECT ds.*, s.name as student_name, s.email as student_email FROM doubt_sessions ds JOIN students s ON s.id=ds.student_id WHERE ds.id=?");
            $dq->execute([$doubtId]);
            $doubt = $dq->fetch();

            if ($doubt) {
                // In-app notification to student
                sendStudentNotification(
                    $db,
                    (int)$doubt['student_id'],
                    "Your Doubt Was Answered!",
                    "Your doubt in {$doubt['subject']} has been answered by your teacher. Check the Doubts section!",
                    'success'
                );

                // Email to student
                if (!empty($doubt['student_email'])) {
                    sendDoubtReplyEmail(
                        $doubt['student_email'],
                        $doubt['student_name'],
                        $doubt['subject'],
                        $doubt['topic'] ?? '',
                        $reply,
                        $user['name']
                    );
                }
            }
            logActivity($user['id'], "Replied to doubt #{$doubtId}", 'doubts');
        } catch (Exception $e) {}
        redirect('index.php?msg=replied');
    }
}

// ── Add session ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_session'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        error_log("CSRF mismatch in doubt session add");
        redirect('index.php?error=csrf');
    }
    $sid=(int)$_POST['student_id'];
    $tid=$isTeacher?$user['id']:(int)($_POST['teacher_id']??0);
    $subj=sanitize($_POST['subject']??''); $topic=sanitize($_POST['topic']??'');
    $desc=sanitize($_POST['doubt_description']??''); $dt=$_POST['session_date']??date('Y-m-d');
    $time=$_POST['session_time']??''; $dur=(int)($_POST['duration_minutes']??30);
    $notes=sanitize($_POST['notes']??''); $status=sanitize($_POST['status']??'Completed');
    if ($sid && $subj) {
        $sc=$db->prepare("SELECT class FROM students WHERE id=?"); $sc->execute([$sid]); $sr=$sc->fetch();
        try {
            $db->prepare("INSERT INTO doubt_sessions (student_id,teacher_id,class,subject,topic,doubt_description,session_date,session_time,duration_minutes,status,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$sid,$tid?:null,$sr['class']??'',$subj,$topic,$desc,$dt,$time?:'00:00:00',$dur,$status,$notes,$user['id']]);
            logActivity($user['id'],"Doubt session: $subj for student $sid",'doubts');
        } catch(Exception $e) {}
        redirect('index.php?msg=added');
    }
}

// ── Delete ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_id']) && $canManage) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        error_log("CSRF mismatch in doubt session delete");
        redirect('index.php?error=csrf');
    }
    $db->prepare("DELETE FROM doubt_sessions WHERE id=?")->execute([(int)$_POST['delete_id']]);
    redirect('index.php?msg=deleted');
}

// ── Filters + Fetch ─────────────────────────────────────────────────────────
$fClass=$_GET['class']??''; $fSubject=$_GET['subject']??''; $fFrom=$_GET['from']??''; $fTo=$_GET['to']??''; $q=$_GET['q']??''; $fStatus=$_GET['status_filter']??'';
$sql="SELECT ds.*,s.name as student_name,s.email as student_email,s.phone as student_phone,u.name as teacher_name FROM doubt_sessions ds JOIN students s ON ds.student_id=s.id LEFT JOIN users u ON ds.teacher_id=u.id WHERE 1=1";
$params=[];
if ($isTeacher) { $sql.=" AND ds.teacher_id=?"; $params[]=$user['id']; }
if ($fClass)    { $sql.=" AND ds.class=?";      $params[]=$fClass; }
if ($fSubject)  { $sql.=" AND ds.subject=?";    $params[]=$fSubject; }
if ($fFrom)     { $sql.=" AND ds.session_date>=?"; $params[]=$fFrom; }
if ($fTo)       { $sql.=" AND ds.session_date<=?"; $params[]=$fTo; }
if ($fStatus)   { $sql.=" AND ds.status=?";        $params[]=$fStatus; }
if ($q)         { $sql.=" AND (s.name LIKE ? OR ds.topic LIKE ? OR ds.subject LIKE ?)"; $params[]="%$q%"; $params[]="%$q%"; $params[]="%$q%"; }
$sql.=" ORDER BY FIELD(ds.status,'Pending','Scheduled','Completed','Cancelled'), ds.session_date DESC, ds.id DESC";
$sessions=[];
try { $st=$db->prepare($sql); $st->execute($params); $sessions=$st->fetchAll(); } catch(Exception $e) {}

$classes=[];
try { $classes=$db->query("SELECT DISTINCT class FROM students ORDER BY class")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e) {}

$teachers=[];
try { $teachers=$db->query("SELECT id,name FROM users WHERE role='teacher' AND status='active' ORDER BY name")->fetchAll(); } catch(Exception $e) {}

$students=[];
if ($isTeacher) {
    try {
        $mc=$db->prepare("SELECT DISTINCT class FROM timetable WHERE teacher_id=?"); $mc->execute([$user['id']]); $mc=$mc->fetchAll(PDO::FETCH_COLUMN);
        if ($mc) { $ph=implode(',',array_fill(0,count($mc),'?')); $st2=$db->prepare("SELECT id,name,class,phone FROM students WHERE class IN ($ph) ORDER BY class,name"); $st2->execute($mc); $students=$st2->fetchAll(); }
    } catch(Exception $e) {}
} else {
    try { $students=$db->query("SELECT id,name,class,phone FROM students ORDER BY class,name")->fetchAll(); } catch(Exception $e) {}
}
$platformSubjects = [];
try { $platformSubjects = $db->query("SELECT DISTINCT subject FROM syllabus WHERE subject IS NOT NULL AND subject!='' ORDER BY subject")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e) {}
if (empty($platformSubjects)) $platformSubjects = ['English Grammar','EVS','Maths','Science','Social Science'];

// Stats logic
$totalSessions  = count($sessions);
$pendingCount   = count(array_filter($sessions, fn($s)=>$s['status']==='Pending'));
$completedCount = count(array_filter($sessions, fn($s)=>$s['status']==='Completed'));

// 7-day trend calculation
$sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
$trendSessions = count(array_filter($sessions, fn($s)=>$s['session_date'] >= $sevenDaysAgo));
$prevTrendSessions = count(array_filter($sessions, fn($s)=>$s['session_date'] < $sevenDaysAgo && $s['session_date'] >= date('Y-m-d', strtotime('-14 days'))));
$trendPct = $prevTrendSessions > 0 ? round((($trendSessions - $prevTrendSessions) / $prevTrendSessions) * 100) : 100;

$root='../../'; require_once '../../includes/header.php';
?>
<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success" data-auto-dismiss><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg>
    <?= match($_GET['msg']){'replied'=>'Doubt answered! Student notified via email & in-app notification.','added'=>'Session recorded!','deleted'=>'Deleted.',default=>'Done!'} ?>
</div>
<?php endif; ?>

<div class="hero-glass mb-24">
    <div class="hero-glass-left">
        <h1 class="align-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg> Doubt Sessions</h1>
        <p>Track, resolve, and answer student questions elegantly.</p>
    </div>
    <div class="hero-glass-actions" style="display:flex; gap:12px;">
        <button class="btn btn-secondary align-icon" onclick="openModal('addDoubtModal')" style="background:rgba(255,255,255,0.7); color:#1e293b; border:1px solid rgba(0,0,0,0.1);"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Add Session</button>
        <?php if ($sessions): ?>
        <button onclick="exportCSV()" class="btn btn-primary align-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg> Download CSV</button>
        <?php endif; ?>
    </div>
</div>

<!-- ── Doubts Split-Level Stats ─────────────────────────────── -->
<?php
// Stats calculations
$answeredPct    = $totalSessions > 0 ? round($completedCount / $totalSessions * 100) : 0;
$sc2 = array_count_values(array_column($sessions,'subject'));
arsort($sc2);
$topSubject = array_key_first($sc2) ?? ''; // REDESIGN FIX: default to empty string
$topCount   = $topSubject ? $sc2[$topSubject] : 0;

// Urgency level
$urgencyColor  = $pendingCount > 5 ? '#ef4444' : '#0ea5e9';
$urgencyBg     = $pendingCount > 5 ? 'rgba(239,68,68,0.08)' : 'rgba(245,158,11,0.07)';
$urgencyBorder = $pendingCount > 5 ? 'rgba(239,68,68,0.2)' : 'rgba(245,158,11,0.2)';

// Ring circumference for SVG: r=28, circumference = 2*pi*28 ≈ 175.9
$ringCirc   = 175.9;
$ringOffset = $ringCirc - ($ringCirc * $answeredPct / 100);

// Subject color palette
$subjectColors = [
    'Maths'=>['#eff6ff','#2563eb'],
    'Science'=>['#f0fdf4','#16a34a'],
    'English Grammar'=>['#fdf4ff','#9333ea'],
    'Hindi'=>['#fff7ed','#ea580c'],
    'Social Science'=>['#f0f9ff','#0284c7'],
    'Computer Science'=>['#eef2ff','#4f46e5'],
    'Physics'=>['#fff1f2','#e11d48'],
    'Chemistry'=>['#faf5ff','#7c3aed']
];
$sc = $subjectColors[$topSubject] ?? ['#f8fafc','#64748b'];
?>
<style>
.doubt-stats-grid {
    display: grid;
    grid-template-columns: 1fr 1.4fr 1fr;
    gap: 16px;
    margin-bottom: 28px;
}
@media (max-width: 900px) { .doubt-stats-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 580px)  { .doubt-stats-grid { grid-template-columns: 1fr; } }

/* Glass base */
.ds-card {
    background: rgba(255,255,255,0.75);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid rgba(255,255,255,0.6);
    border-radius: 22px;
    box-shadow: 0 8px 32px rgba(79,70,229,0.06), 0 2px 4px rgba(0,0,0,0.04);
    padding: 24px 22px;
    transition: transform .25s ease, box-shadow .25s ease;
    position: relative;
    overflow: hidden;
}
.ds-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 16px 40px rgba(79,70,229,0.12), 0 2px 4px rgba(0,0,0,0.04);
}

/* Primary card bg pattern */
.ds-primary::before {
    content: '';
    position: absolute;
    top: -30px; right: -30px;
    width: 120px; height: 120px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(79,70,229,0.08) 0%, transparent 70%);
}
.ds-primary::after {
    content: '';
    position: absolute;
    bottom: -40px; left: -20px;
    width: 140px; height: 140px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(79,70,229,0.05) 0%, transparent 70%);
}

/* Labels */
.ds-eyebrow {
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: var(--text-light);
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.ds-big-num {
    font-size: 52px;
    font-weight: 900;
    color: var(--text);
    line-height: 1;
    letter-spacing: -2px;
}
.ds-sub-text {
    font-size: 12px;
    color: var(--text-light);
    font-weight: 600;
    margin-top: 6px;
}

/* Center panel — split vertically */
.ds-center {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 0;
}
.ds-half {
    background: rgba(255,255,255,0.75);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid rgba(255,255,255,0.6);
    border-radius: 18px;
    box-shadow: 0 6px 24px rgba(0,0,0,0.05);
    padding: 18px 20px;
    flex: 1;
    transition: transform .25s ease, box-shadow .25s ease;
    position: relative;
    overflow: hidden;
}
.ds-sub-card {
    background: #f8fafc; border-radius: 20px; padding: 20px; display: flex; flex-direction: column;
    justify-content: space-between; border: 1px solid transparent; transition: all 0.3s;
}

.ds-val-small { font-size: 32px; font-weight: 900; line-height: 1; margin: 4px 0; }

/* Filter Pills */
.filter-pills { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.pill-btn {
    padding: 6px 14px; border-radius: 99px; font-size: 13px; font-weight: 700;
    border: 1.5px solid var(--border); background: var(--white); color: var(--text-light);
    cursor: pointer; transition: all 0.2s; white-space: nowrap;
}
.pill-btn:hover { border-color: var(--primary); color: var(--primary); }
.pill-btn.active { background: var(--primary); color: var(--white); border-color: var(--primary); }

/* Merged Search */
.search-merged {
    position: relative; display: flex; align-items: center; background: #f8fafc;
    border: 1.5px solid var(--border); border-radius: 12px; transition: all 0.2s;
    min-width: 240px;
}
.search-merged:focus-within { border-color: var(--primary); background: var(--white); box-shadow: 0 0 0 4px rgba(79,70,229,0.1); }
.search-merged svg { position: absolute; left: 12px; color: var(--text-light); pointer-events: none; }
.search-merged input {
    width: 100%; border: none; background: transparent; padding: 10px 12px 10px 38px;
    font-size: 14px; outline: none; font-family: inherit;
}

/* Skeleton State */
.skeleton-row {
    height: 80px; background: linear-gradient(90deg, #f1f5f9 25%, #f8fafc 50%, #f1f5f9 75%);
    background-size: 200% 100%; animation: skeleton-loading 1.5s infinite;
    border-radius: 16px; margin-bottom: 12px; border: 1px solid #f1f5f9;
}
@keyframes skeleton-loading { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

/* Rings & Effects */
.ring-glow { filter: drop-shadow(0 0 8px #10b981); }
@keyframes pulse-ring { 0% { opacity: 0.6; } 50% { opacity: 1; } 100% { opacity: 0.6; } }
.pulsing { animation: pulse-ring 2s infinite; }

/* Subject badge */
.ds-subject-tag {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    border-radius: 99px;
    font-size: 15px;
    font-weight: 800;
    letter-spacing: -0.3px;
    margin-top: 12px;
}

/* Status Split Panel */
.ds-status-inner {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

/* Hero Value */
.ds-hero-val {
    font-size: 48px;
    font-weight: 900;
    color: #7c3aed;
    line-height: 1;
    letter-spacing: -2px;
    margin: 8px 0;
}

/* Trend indicator */
.ds-trend {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 700;
    margin-top: 8px;
    padding: 4px 10px;
    border-radius: 99px;
    width: fit-content;
}
.ds-trend.trend-up {
    color: #16a34a;
    background: rgba(22, 163, 106, 0.08);
}
.ds-trend.trend-down {
    color: #ef4444;
    background: rgba(239, 68, 68, 0.08);
}

/* Sparkline container */
.sparkline-wrap {
    height: 40px;
    margin-top: 12px;
    opacity: 0.6;
}

/* Subject badge */
.subject-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 99px;
    font-size: 14px;
    font-weight: 800;
}
/* Premium App List View */
.doubt-list-container {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 12px;
}
.dl-row {
    background: rgba(255,255,255,0.7);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(255,255,255,0.9);
    border-radius: 16px;
    padding: 16px 24px;
    box-shadow: 0 4px 15px rgba(31, 38, 135, 0.03);
    display: flex;
    align-items: center;
    gap: 20px;
    transition: all 0.25s ease;
}
.dl-row:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 30px rgba(139, 92, 246, 0.08);
    border-color: rgba(139, 92, 246, 0.3);
    background: #fff;
}
.dl-col-student {
    flex: 0 0 220px;
    display: flex;
    align-items: center;
    gap: 14px;
    border-right: 1px dashed rgba(0,0,0,0.08);
    padding-right: 16px;
}
.dl-avatar {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: linear-gradient(135deg, #a78bfa, #8b5cf6);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    font-weight: 800;
    flex-shrink: 0;
}
.dl-name { font-weight: 800; font-size: 15px; color: #1e293b; letter-spacing:-0.2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
.dl-class { font-size: 11px; font-weight: 800; color: #8b5cf6; background: #ede9fe; padding: 2px 8px; border-radius: 6px; margin-top: 2px; display: inline-block; }

.dl-col-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.dl-subject {
    font-size: 11px;
    font-weight: 800;
    color: #0284c7;
    background: #e0f2fe;
    padding: 4px 10px;
    border-radius: 99px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    width: fit-content;
}
.dl-topic {
    font-size: 14.5px;
    font-weight: 800;
    color: #334155;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.dl-desc {
    font-size: 13px;
    color: #64748b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.dl-col-meta {
    flex: 0 0 130px;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
    border-left: 1px dashed rgba(0,0,0,0.08);
    padding-left: 16px;
}
.dl-date {
    font-size: 12px;
    font-weight: 700;
    color: #94a3b8;
    display:flex;
    align-items:center;
    gap:4px;
}

.dl-col-actions {
    flex: 0 0 170px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    align-items: center;
}

@media(max-width:1024px) {
    .dl-row { flex-wrap: wrap; }
    .dl-col-actions { flex: 1; justify-content: flex-start; margin-top: 8px; border-top: 1px dashed rgba(0,0,0,0.08); padding-top: 16px; }
}
@media(max-width:768px) {
    .dl-row { flex-direction: column; align-items: stretch; gap: 12px;}
    .dl-col-student { border-right: none; padding-right: 0; padding-bottom: 12px; border-bottom: 1px dashed rgba(0,0,0,0.08); width: 100%; flex: auto;}
    .dl-col-meta { border-left: none; padding-left: 0; align-items: flex-start; flex-direction: row; justify-content: space-between; width: 100%; flex: auto;}
    .dl-col-actions { width: 100%; border-top: none; padding-top: 0; margin-top: 0; }
}
</style>

<div class="doubt-stats-grid">

    <!-- HERO: Total Sessions -->
    <div class="ds-card" style="background:#f5f3ff; border-color: #ddd6fe;">
        <div>
            <div class="ds-eyebrow" style="color:#7c3aed">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                Total Sessions
            </div>
            <div class="ds-hero-val"><?= $totalSessions ?></div>
            <div class="ds-trend <?= $trendPct >= 0 ? 'trend-up' : 'trend-down' ?>">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="<?= $trendPct >= 0 ? '23 6 13.5 15.5 8.5 10.5 1 18' : '23 18 13.5 8.5 8.5 13.5 1 6' ?>"></polyline><polyline points="<?= $trendPct >= 0 ? '17 6 23 6 23 12' : '17 18 23 18 23 12' ?>"></polyline></svg>
                <?= abs($trendPct) ?>% vs last week
            </div>
        </div>
        <div class="sparkline-wrap">
            <svg viewBox="0 0 140 40" preserveAspectRatio="none" style="width:100%; height:100%">
                <path d="M0,35 Q20,10 40,25 T80,5 T120,30 T140,15" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" />
            </svg>
        </div>
    </div>

    <!-- STATUS SPLIT -->
    <div class="ds-status-inner">
        <!-- Pending -->
        <div class="ds-sub-card <?= $pendingCount > 0 ? 'pulsing' : '' ?>" 
             style="background: <?= $urgencyBg ?>; border-color: <?= $urgencyBorder ?>;">
            <div class="ds-eyebrow" style="color: <?= $urgencyColor ?>;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                Pending
            </div>
            <div class="ds-val-small" style="color: <?= $urgencyColor ?>;"><?= $pendingCount ?></div>
            <div style="font-size:11px; font-weight:700; color:<?= $urgencyColor ?>88;">
                <?= $pendingCount > 0 ? 'Requires attention' : 'All caught up! <svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2.5\" stroke-linecap=\"round\" stroke-linejoin=\"round\" style=\"display:inline-block;vertical-align:text-bottom;margin-right:2px\"><polygon points=\"12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2\"></polygon></svg>' ?>
            </div>
        </div>

        <!-- Answered -->
        <div class="ds-sub-card" style="background: rgba(16,185,129,0.06); border-color: rgba(16,185,129,0.15);">
            <div class="ds-eyebrow" style="color: #059669;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                Answered
            </div>
            <div style="display:flex; align-items:center; gap:12px;">
                <div class="ds-val-small" style="color: #059669;"><?= $completedCount ?></div>
                <svg width="36" height="36" viewBox="0 0 64 64" class="<?= $answeredPct == 100 ? 'ring-glow' : '' ?>">
                    <circle cx="32" cy="32" r="28" fill="none" stroke="#e2e8f0" stroke-width="6"/>
                    <circle cx="32" cy="32" r="28" fill="none" stroke="#10b981" stroke-width="6" stroke-linecap="round"
                            stroke-dasharray="<?= $ringCirc ?>" stroke-dashoffset="<?= $ringOffset ?>" style="transition:all 1s" />
                </svg>
            </div>
            <div style="font-size:11px; font-weight:700; color:#05966988;"><?= $answeredPct ?>% Resolution</div>
        </div>
    </div>

    <!-- TOP SUBJECT -->
    <div class="ds-card" style="background: <?= $sc[0] ?>; border-color: <?= $sc[1] ?>33;">
        <div>
            <div class="ds-eyebrow" style="color: <?= $sc[1] ?>;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                Top Subject
            </div>
            <?php if ($topSubject): ?>
                <div class="subject-badge" style="background:<?= $sc[1] ?>; color:#fff;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                    <?= htmlspecialchars($topSubject) ?>
                </div>
                <div style="font-size:12px; font-weight:700; color:<?= $sc[1] ?>; margin-top:12px;">
                    <?= $topCount ?> Doubts Raised
                </div>
            <?php else: ?>
                <div style="text-align:center; padding-top:10px;">
                    <div style="font-size:24px; margin-bottom:4px;">🌱</div>
                    <div style="font-size:11px; font-weight:700; color:var(--text-light);">No Data Yet</div>
                </div>
            <?php endif; ?>
        </div>
        <div style="font-size:11px; font-weight:600; color:var(--text-light);">
            Most active category
        </div>
    </div>

</div>


<!-- Filters -->
<div class="card" style="margin-bottom:24px; border:none; box-shadow:none; background:transparent;">
    <form method="GET" id="filterForm" style="display:flex; flex-direction:column; gap:16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <!-- Merged Search -->
            <div class="search-merged">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search student or topic (press enter)...">
            </div>

            <!-- Pill Status Filter -->
            <div class="filter-pills">
                <input type="hidden" name="status_filter" id="status_filter" value="<?= htmlspecialchars($fStatus) ?>">
                <button type="button" class="pill-btn <?= !$fStatus ? 'active' : '' ?>" onclick="setStatus('')">All</button>
                <button type="button" class="pill-btn <?= $fStatus=='Pending' ? 'active' : '' ?>" onclick="setStatus('Pending')">Pending</button>
                <button type="button" class="pill-btn <?= $fStatus=='Completed' ? 'active' : '' ?>" onclick="setStatus('Completed')">Completed</button>
                <button type="button" class="pill-btn <?= $fStatus=='Scheduled' ? 'active' : '' ?>" onclick="setStatus('Scheduled')">Scheduled</button>
            </div>
        </div>

        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <select name="class" onchange="this.form.submit()" style="padding:10px 14px; border-radius:12px; border:1.5px solid var(--border); font-size:13px; font-weight:600; min-width:140px;">
                <option value="">All Classes</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= $c ?>" <?=$fClass===$c?'selected':'' ?>><?= $c ?></option>
                <?php endforeach; ?>
            </select>

            <select name="subject" onchange="this.form.submit()" style="padding:10px 14px; border-radius:12px; border:1.5px solid var(--border); font-size:13px; font-weight:600; min-width:160px;">
                <option value="">All Subjects</option>
                <?php foreach ($platformSubjects as $s): ?>
                    <option value="<?= $s ?>" <?=$fSubject===$s?'selected':'' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>

            <div style="display:flex; align-items:center; background:#f8fafc; border:1.5px solid var(--border); border-radius:12px; padding:0 8px;">
                <input type="date" name="from" value="<?= $fFrom ?>" onchange="this.form.submit()" style="border:none; background:transparent; font-size:12px; padding:8px; font-family:inherit; font-weight:600; outline:none;">
                <span style="color:var(--text-light); font-weight:800; padding:0 4px;">→</span>
                <input type="date" name="to" value="<?= $fTo ?>" onchange="this.form.submit()" style="border:none; background:transparent; font-size:12px; padding:8px; font-family:inherit; font-weight:600; outline:none;">
            </div>

            <?php if ($fClass||$fSubject||$fFrom||$fTo||$q||$fStatus): ?>
                <a href="index.php" style="font-size:13px; font-weight:700; color:var(--text-light); text-decoration:none; margin-left:8px;">Reset Filters</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<script>
function setStatus(val) {
    document.getElementById('status_filter').value = val;
    document.getElementById('filterForm').submit();
}
</script>

<div class="doubt-list-container" id="doubtsTable">
    <?php if ($sessions): ?>
        <?php foreach ($sessions as $i => $s):
            $scl = match($s['status']){'Completed'=>'badge-green','Pending'=>'badge-amber','Scheduled'=>'badge-blue','Cancelled'=>'badge-red',default=>'badge-gray'};
        ?>
        <div class="dl-row">
            
            <div class="dl-col-student">
                <div class="dl-avatar">
                    <?= strtoupper(substr($s['student_name'],0,1)) ?>
                </div>
                <div style="min-width:0; overflow:hidden;">
                    <div class="dl-name" title="<?= sanitize($s['student_name']) ?>"><?= sanitize($s['student_name']) ?></div>
                    <div class="dl-class"><?= sanitize($s['class']??'-') ?></div>
                </div>
            </div>

            <div class="dl-col-content">
                <div class="dl-subject">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
                    <?= sanitize($s['subject']) ?>
                </div>
                <?php if ($s['topic']): ?>
                <div class="dl-topic" title="<?= sanitize($s['topic']) ?>">
                    <?= sanitize($s['topic']) ?>
                </div>
                <?php endif; ?>
                <div class="dl-desc" title="<?= sanitize(strip_tags($s['doubt_description'])) ?>">
                    <?= $s['doubt_description'] ? htmlspecialchars($s['doubt_description']) : '<span style="color:#cbd5e1;font-weight:500;font-style:italic">No description provided</span>' ?>
                </div>
            </div>

            <div class="dl-col-meta">
                <span class="badge <?= $scl ?>" style="font-size:11px; padding:4px 10px;"><?= $s['status'] ?></span>
                <div class="dl-date">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    <?= date('d M, Y',strtotime($s['session_date'])) ?>
                </div>
            </div>

            <div class="dl-col-actions">
                <?php if ($s['status'] === 'Pending' || $s['status'] === 'Scheduled'): ?>
                <button class="btn btn-primary btn-sm" onclick="openReply(<?= $s['id'] ?>, '<?= addslashes(htmlspecialchars($s['student_name'])) ?>', '<?= addslashes(htmlspecialchars($s['subject'])) ?>', '<?= addslashes(htmlspecialchars($s['doubt_description']??'')) ?>')">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg> Reply
                </button>
                <?php elseif ($s['notes']): ?>
                <div style="display:flex;align-items:center;gap:6px">
                    <span style="font-size:11px;color:#16a34a;font-weight:800;background:#dcfce7;padding:4px 8px;border-radius:6px;border:1px solid #bbf7d0" title="Replied"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></span>
                    <button class="btn btn-secondary btn-sm" style="background:#fff" onclick="showDetail('<?= addslashes(htmlspecialchars_decode($s['topic']??'')) ?>','<?= addslashes(htmlspecialchars_decode($s['doubt_description']??'')) ?>','<?= addslashes(htmlspecialchars_decode($s['notes']??'')) ?>')">
                        View
                    </button>
                </div>
                <?php else: ?>
                <button class="btn btn-secondary btn-sm" onclick="openReply(<?= $s['id'] ?>, '<?= addslashes(htmlspecialchars($s['student_name'])) ?>', '<?= addslashes(htmlspecialchars($s['subject'])) ?>', '<?= addslashes(htmlspecialchars($s['doubt_description']??'')) ?>')">
                    Add Reply
                </button>
                <?php endif; ?>

                <?php if ($canManage): ?>
                <form method="POST" style="margin:0">
                    <?= csrfField() ?>
                    <input type="hidden" name="delete_id" value="<?= $s['id'] ?>">
                    <button class="btn border-red text-red btn-sm" style="padding:6px; background:#fff" title="Delete" data-confirm="Delete this session?"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg></button>
                </form>
                <?php endif; ?>
            </div>

        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div style="padding:60px 20px; text-align:center; background:rgba(255,255,255,0.6); backdrop-filter:blur(10px); border-radius:20px; border:1px dashed var(--border);">
            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:16px;"><circle cx="12" cy="12" r="10"></circle><line x1="8" y1="12" x2="16" y2="12"></line><line x1="12" y1="8" x2="12" y2="16"></line></svg>
            <h2 style="font-weight:800; color:var(--text); margin-bottom:8px;">No Doubts Found</h2>
            <p style="color:var(--text-light); font-size:15px; margin-bottom:24px;">There are no active doubt requests matching your criteria.</p>
            <button class="btn btn-primary" onclick="openModal('addDoubtModal')" style="padding:12px 32px; border-radius:12px; margin:0 auto;">Record New Session</button>
        </div>
    <?php endif; ?>
</div>

<!-- Reply Modal -->
<div class="modal-overlay" id="replyDoubtModal">
    <div class="modal" style="max-width:520px">
        <div class="modal-header">
            <div class="modal-title"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg> Reply to Doubt</div><button class="modal-close"
                onclick="closeModal('replyDoubtModal')"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="reply_doubt" value="1">
            <input type="hidden" name="doubt_id" id="replyDoubtId">
            <div class="modal-body">
                <div id="replyMeta"
                    style="background:#f8fafc;border-radius:12px;padding:14px 16px;margin-bottom:18px;border:1.5px solid #e2e8f0">
                    <div style="font-size:12px;font-weight:800;text-transform:uppercase;color:#4b5563;margin-bottom:6px">
                        Student's Doubt</div>
                    <div style="font-size:13px;font-weight:700;color:#1e1b4b" id="replyStudentName"></div>
                    <div style="font-size:12px;color:#6b7280;margin-top:4px" id="replySubjectText"></div>
                    <div style="font-size:13px;color:#374151;margin-top:10px;line-height:1.6;font-style:italic"
                        id="replyDoubtDesc"></div>
                </div>
                <div class="form-group">
                    <label>Your Reply / Answer *</label>
                    <textarea name="reply_notes" rows="5"
                        placeholder="Type your answer here... Help the student understand clearly!" required
                        style="width:100%;padding:12px 14px;border-radius:12px;border:1.5px solid var(--border);font-size:14px;font-family:inherit;resize:vertical"></textarea>
                    <div style="font-size:11px;color:#16a34a;font-weight:700;margin-top:6px">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg> Student will be notified via email &amp; in-app notification automatically.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary"
                    onclick="closeModal('replyDoubtModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg> Send Reply</button>
            </div>
        </form>
    </div>
</div>

<!-- View Detail Modal -->
<div class="modal-overlay" id="detailModal">
    <div class="modal" style="max-width:440px">
        <div class="modal-header">
            <div class="modal-title"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg> Session Detail</div><button class="modal-close"
                onclick="closeModal('detailModal')"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
        </div>
        <div class="modal-body">
            <p style="font-weight:700;margin-bottom:6px">Topic:</p>
            <div id="dTopic"
                style="background:var(--bg2);border-radius:var(--r);padding:10px;margin-bottom:12px;font-size:13px">
            </div>
            <p style="font-weight:700;margin-bottom:6px">Doubt Description:</p>
            <div id="dDesc"
                style="background:var(--bg2);border-radius:var(--r);padding:10px;margin-bottom:12px;font-size:13px;min-height:32px">
            </div>
            <p style="font-weight:700;margin-bottom:6px">Teacher's Reply:</p>
            <div id="dNotes"
                style="background:#dcfce7;border-radius:var(--r);padding:10px;font-size:13px;min-height:32px;color:#15803d">
            </div>
        </div>
    </div>
</div>

<!-- Add Session Modal -->
<div class="modal-overlay" id="addDoubtModal">
    <div class="modal" style="max-width:580px">
        <div class="modal-header">
            <div class="modal-title"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Add Doubt Session</div><button class="modal-close"
                onclick="closeModal('addDoubtModal')"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="add_session" value="1">
            <div class="modal-body" id="addDoubtBody">
                <!-- Step 1 -->
                <div id="addStep1">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px;background:#f0fbff;padding:12px;border-radius:10px;border:1.5px solid #bae6fd">
                        <div style="background:#0ea5e9;color:#fff;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px">1</div>
                        <div style="font-weight:700;color:#0369a1;font-size:14px">Basic Information</div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group"><label>Student *</label><select name="student_id" required>
                                <option value="">Select Student</option>
                                <?php $grp=[]; foreach ($students as $s) $grp[$s['class']][]=$s; foreach ($grp as $cls=>$studs): ?>
                                <optgroup label="<?= $cls ?>">
                                    <?php foreach ($studs as $s): ?>
                                    <option value="<?= $s['id'] ?>">
                                        <?= sanitize($s['name']) ?>
                                        <?= $s['phone']?' - '.$s['phone']:'' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endforeach; ?>
                            </select></div>
                        <?php if ($canManage): ?>
                        <div class="form-group"><label>Teacher *</label><select name="teacher_id" required>
                                <option value="">Select</option>
                                <?php foreach ($teachers as $t): ?>
                                <option value="<?= $t['id'] ?>">
                                    <?= sanitize($t['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select></div>
                        <?php endif; ?>
                        <div class="form-group" style="grid-column:1/-1"><label>Subject *</label><select name="subject" required>
                                <option value="">- Select -</option>
                                <?php foreach ($platformSubjects as $s): ?>
                                <option value="<?= $s ?>">
                                    <?= $s ?>
                                </option>
                                <?php endforeach; ?>
                            </select></div>
                    </div>
                </div>

                <!-- Step 2 -->
                <div id="addStep2" style="display:none">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px;background:#f0fdf4;padding:12px;border-radius:10px;border:1.5px solid #bbf7d0">
                        <div style="background:#22c55e;color:#fff;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px">2</div>
                        <div style="font-weight:700;color:#15803d;font-size:14px">Session Details & Status</div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group"><label>Topic</label><input type="text" name="topic"
                                placeholder="e.g. Quadratic Equations"></div>
                        <div class="form-group"><label>Date *</label><input type="date" name="session_date"
                                value="<?= date('Y-m-d') ?>" required></div>
                        <div class="form-group"><label>Time</label><input type="time" name="session_time"></div>
                        <div class="form-group"><label>Duration (min)</label><input type="number" name="duration_minutes"
                                value="30" min="5"></div>
                        <div class="form-group"><label>Status</label><select name="status">
                                <option value="Completed">✅ Completed</option>
                                <option value="Pending">⏳ Pending</option>
                                <option value="Scheduled">📅 Scheduled</option>
                                <option value="Cancelled">❌ Cancelled</option>
                            </select></div>
                        <div class="form-group" style="grid-column:1/-1"><label>Doubt Description</label><textarea
                                name="doubt_description" rows="2" placeholder="What was the student's doubt?"></textarea>
                        </div>
                        <div class="form-group" style="grid-column:1/-1"><label>Teacher Notes / Reply</label><textarea
                                name="notes" rows="2" placeholder="How was it resolved?"></textarea></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <div style="display:flex; gap:10px; width:100%; justify-content:flex-end">
                    <button type="button" class="btn btn-secondary" id="btnBack" style="display:none" onclick="prevStep()">Back</button>
                    <button type="button" class="btn btn-secondary" id="btnCancelAdd" onclick="closeModal('addDoubtModal')">Cancel</button>
                    <button type="button" class="btn btn-primary" id="btnNext" onclick="nextStep()">Next Step <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-left:2px"><polyline points="9 18 15 12 9 6"></polyline></svg></button>
                    <button type="submit" class="btn btn-primary" id="btnSave" style="display:none"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg> Save Session</button>
                </div>
            </div>
        </form>
    </div>
</div>


<script>
    function openReply(id, studentName, subject, desc) {
        document.getElementById('replyDoubtId').value = id;
        document.getElementById('replyStudentName').textContent = studentName;
        document.getElementById('replySubjectText').textContent = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg> ' + subject;
        document.getElementById('replyDoubtDesc').textContent = desc || '(No description provided)';
        openModal('replyDoubtModal');
    }
    function showDetail(t, d, n) {
        document.getElementById('dTopic').textContent = t || '-';
        document.getElementById('dDesc').textContent = d || '-';
        document.getElementById('dNotes').textContent = n || '-';
        openModal('detailModal');
    }
    function exportCSV() {
        var rows = [['#', 'Student', 'Class', 'Subject', 'Topic', 'Date', 'Status']];
        document.querySelectorAll('.dl-row').forEach(function (c, i) {
            let name = c.querySelector('.dl-name')?.innerText || '';
            let cls = c.querySelector('.dl-class')?.innerText || '';
            let subj = c.querySelector('.dl-subject')?.innerText || '';
            let topic = c.querySelector('.dl-topic')?.innerText || '';
            let dt = c.querySelector('.dl-date')?.innerText || '';
            let stat = c.querySelector('.badge')?.innerText || '';
            rows.push([i + 1, name, cls, subj, topic, dt, stat]);
        });
        var csv = rows.map(r => r.map(c => '"' + (c || '').replace(/"/g, '""') + '"').join(',')).join('\n');
        var a = document.createElement('a'); a.href = 'data:text/csv,' + encodeURIComponent(csv); a.download = 'doubt_sessions.csv'; a.click();
    }
    function nextStep() {
        document.getElementById('addStep1').style.display = 'none';
        document.getElementById('addStep2').style.display = 'block';
        document.getElementById('btnBack').style.display = 'block';
        document.getElementById('btnCancelAdd').style.display = 'none';
        document.getElementById('btnNext').style.display = 'none';
        document.getElementById('btnSave').style.display = 'block';
    }
    function prevStep() {
        document.getElementById('addStep1').style.display = 'block';
        document.getElementById('addStep2').style.display = 'none';
        document.getElementById('btnBack').style.display = 'none';
        document.getElementById('btnCancelAdd').style.display = 'block';
        document.getElementById('btnNext').style.display = 'block';
        document.getElementById('btnSave').style.display = 'none';
    }
    // Reset steps when opening modal
    const originalOpenModal = window.openModal;
    window.openModal = function(id) {
        if(id === 'addDoubtModal') prevStep();
        originalOpenModal(id);
    }
</script>
<?php require_once '../../includes/footer.php'; ?>
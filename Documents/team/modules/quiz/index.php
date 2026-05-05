<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
requireRole(['admin','mentor','teacher']);

$pageTitle = 'Quiz Manager';
$db   = getDB();
$user = currentUser();
$isAdmin  = $user['role'] === 'admin';
$canManage = in_array($user['role'], ['admin','mentor','teacher']);

$errors = [];

// Delete quiz (admin only)
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_quiz'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Security token mismatch.";
    } else {
        $qid = (int)$_POST['delete_quiz'];
        try {
            $db->prepare("DELETE FROM quiz_questions WHERE quiz_id=?")->execute([$qid]);
            $db->prepare("DELETE FROM student_quiz_attempts WHERE quiz_id=?")->execute([$qid]);
            $db->prepare("DELETE FROM quizzes WHERE id=?")->execute([$qid]);
            logActivity($user['id'], "Deleted quiz #$qid", 'quiz');
            redirect('index.php?msg=deleted');
        } catch (Exception $e) {
            $errors[] = "Database Error: " . $e->getMessage();
        }
    }
}

// Toggle quiz status
if ($canManage && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Security token mismatch.";
    } else {
        $qid    = (int)$_POST['quiz_id'];
        $newSt  = $_POST['new_status'] ?? 'draft';
        try {
            $db->prepare("UPDATE quizzes SET status=? WHERE id=?")->execute([$newSt, $qid]);
            
            // Automatically email students if publishing
            if ($newSt === 'published') {
                $qStmt = $db->prepare("SELECT * FROM quizzes WHERE id=?");
                $qStmt->execute([$qid]);
                $quiz = $qStmt->fetch();
                if ($quiz) {
                    try {
                        require_once '../../includes/email.php';
                        if ($quiz['batch_id']) {
                            $sStmt = $db->prepare("SELECT name,email FROM students WHERE batch_id=? AND status='active'");
                            $sStmt->execute([$quiz['batch_id']]);
                        } elseif ($quiz['class']) {
                            $sStmt = $db->prepare("SELECT name,email FROM students WHERE class=? AND status='active'");
                            $sStmt->execute([$quiz['class']]);
                        } else {
                            $sStmt = $db->query("SELECT name,email FROM students WHERE status='active'");
                        }
                        
                        $typeLabel = match($quiz['quiz_type']??'regular') {
                            'weekly'=>'Weekly Quiz',
                            'monthly'=>'Monthly Test',
                            'dpp'=>'DPP',
                            default=>'Quiz'
                        };
                        
                        foreach ($sStmt->fetchAll() as $stu) {
                            if (!empty($stu['email'])) {
                                sendMaterialUploadEmail(
                                    $stu['email'], 
                                    $stu['name'], 
                                    $typeLabel, 
                                    $quiz['title'], 
                                    $quiz['subject'] ?: 'All Subjects', 
                                    $user['name'] ?? 'Your Teacher', 
                                    'https://team.heyyguru.in/student/quiz.php'
                                );
                            }
                        }
                    } catch(Exception $e) {}
                }
            }
            
            redirect('index.php?msg=updated');
        } catch (Exception $e) {
            $errors[] = "Database Error: " . $e->getMessage();
        }
    }
}

// Add new quiz
if ($canManage && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_quiz'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Security token mismatch.";
    } else {
        $title    = sanitize($_POST['title']    ?? '');
        $desc     = sanitize($_POST['desc']     ?? '');
        $batchId  = (int)($_POST['batch_id']   ?? 0);
        $class    = sanitize($_POST['class']   ?? '');
        $subject  = sanitize($_POST['subject'] ?? '');
        $quizType = in_array($_POST['quiz_type']??'',['regular','weekly','monthly']) ? $_POST['quiz_type'] : 'regular';
        $duration = (int)($_POST['duration_minutes'] ?? 30);
        $deadline = $_POST['deadline'] ?? null;
        $totalMks = (int)($_POST['total_marks'] ?? 10);

        if (!$title) $errors[] = 'Quiz title is required.';

        if (!$errors) {
            try {
                $db->prepare("INSERT INTO quizzes (title,description,batch_id,class,subject,quiz_type,duration_minutes,deadline,total_marks,created_by,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,'draft',NOW())")
                   ->execute([$title,$desc,$batchId?:null,$class,$subject,$quizType,$duration,$deadline?:null,$totalMks,$user['id']]);
                $newId = $db->lastInsertId();
                logActivity($user['id'], "Created $quizType quiz: $title", 'quiz');
                redirect("questions.php?quiz_id=$newId&msg=created");
            } catch (Exception $e) {
                $errors[] = "Database Error: " . $e->getMessage();
            }
        }
    }
}

$isTeacher = $user['role'] === 'teacher';

$teacherSubjects = [];
if ($isTeacher) {
    try {
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

$batches = [];
try { $batches = $db->query("SELECT id,name,class FROM batches WHERE status='active' ORDER BY name")->fetchAll(); } catch(Exception $e) {}

$subjects = [];
if ($isTeacher && !empty($teacherSubjects)) {
    $subjects = $teacherSubjects;
} else {
    try { $subjects = $db->query("SELECT DISTINCT subject FROM syllabus WHERE subject IS NOT NULL ORDER BY subject")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e) {}
    if (empty($subjects)) $subjects = ['Maths','Science','English','Hindi','Social Science','Computer'];
}

// Exclude DPP quizzes (managed separately in modules/dpp)
$quizzes = [];
try {
    $qSql = "
        SELECT q.*, u.name as creator, b.name as batch_name,
               (SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=q.id) as qcount,
               (SELECT COUNT(*) FROM student_quiz_attempts WHERE quiz_id=q.id) as attempts
        FROM quizzes q
        LEFT JOIN users u ON u.id=q.created_by
        LEFT JOIN batches b ON b.id=q.batch_id
        WHERE q.quiz_type != 'dpp'
    ";
    $params = [];

    if ($isTeacher) {
        $qSql .= " AND q.created_by = ?";
        $params[] = $user['id'];
    }

    $qSql .= " ORDER BY q.created_at DESC";
    $stmt = $db->prepare($qSql);
    $stmt->execute($params);
    $quizzes = $stmt->fetchAll();
} catch(Exception $e) {}

$root = '../../';
require_once '../../includes/header.php';
?>
<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success" data-auto-dismiss><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg>
    <?= match($_GET['msg']) {'created'=>'Quiz created! Now add questions.','updated'=>'Status updated!','deleted'=>'Quiz deleted.',default=>'Done!'} ?>
</div>
<?php endif; ?>
<?php foreach ($errors as $e): ?>
<div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
    <?= $e ?>
</div>
<?php endforeach; ?>

<div class="page-header mb-24">
    <div class="page-header-left">
        <h1 class="align-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="6"></circle><circle cx="12" cy="12" r="2"></circle></svg> Quiz Manager</h1>
        <p>Create, assign and monitor MCQ quizzes - Regular, Weekly, and Monthly tests</p>
    </div>
    <div class="page-header-actions">
        <?php if ($canManage): ?>
        <button class="btn btn-secondary align-icon" onclick="quickCreate('weekly')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> Weekly Test</button>
        <button class="btn btn-secondary align-icon" onclick="quickCreate('monthly')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> Monthly Test</button>
        <a href="quiz_builder.php" class="btn btn-secondary align-icon"
            style="background:linear-gradient(135deg,#7c3aed,#4f46e5);color:#fff;border:none"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9.59 4.59A2 2 0 1 1 11 8H2m10.59 11.41A2 2 0 1 0 14 16H2m15.73-8.27A2.5 2.5 0 1 1 19.5 12H2"></path></svg> Smart Builder</a>
        <button class="btn btn-primary align-icon" onclick="openModal('addQuizModal')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Create Quiz</button>
        <?php endif; ?>
    </div>
</div>

<!-- ── QUIZ STATS BENTO GRID ─────────────────────────────── -->
<?php
$totalQ     = count($quizzes);
$published  = count(array_filter($quizzes, fn($q)=>$q['status']==='published'));
$drafts     = count(array_filter($quizzes, fn($q)=>$q['status']==='draft'));
$scheduled  = count(array_filter($quizzes, fn($q)=>in_array($q['quiz_type'],['weekly','monthly'])));
$pubPct     = $totalQ > 0 ? round($published / $totalQ * 100) : 0;
$draftPct   = $totalQ > 0 ? round($drafts    / $totalQ * 100) : 0;
$schedPct   = $totalQ > 0 ? round($scheduled / $totalQ * 100) : 0;
?>
<style>
/* ── Quiz Bento Stats ─── */
.quiz-bento {
    display: grid;
    grid-template-columns: 1.6fr 1fr 1fr 1fr;
    gap: 16px;
    margin-bottom: 28px;
}
@media (max-width: 1024px) { .quiz-bento { grid-template-columns: 1fr 1fr; } }
@media (max-width: 600px)  { .quiz-bento { grid-template-columns: 1fr; } }

/* Hero card */
.qb-hero {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    border-radius: 22px;
    padding: 28px 26px;
    color: #fff;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-shadow: 0 12px 40px rgba(79,70,229,0.3);
    transition: transform .25s ease, box-shadow .25s ease;
    position: relative;
    overflow: hidden;
}
.qb-hero::before {
    content: '';
    position: absolute;
    top: -40px; right: -40px;
    width: 160px; height: 160px;
    border-radius: 50%;
    background: rgba(255,255,255,0.07);
}
.qb-hero::after {
    content: '';
    position: absolute;
    bottom: -60px; left: -30px;
    width: 200px; height: 200px;
    border-radius: 50%;
    background: rgba(255,255,255,0.05);
}
.qb-hero:hover { transform: translateY(-4px); box-shadow: 0 20px 50px rgba(79,70,229,0.4); }

.qb-hero-label {
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    opacity: 0.75;
    margin-bottom: 6px;
}
.qb-hero-num {
    font-size: 56px;
    font-weight: 900;
    font-family: 'Courier New', monospace;
    line-height: 1;
    letter-spacing: -2px;
}
.qb-hero-sub {
    font-size: 12.5px;
    opacity: 0.7;
    margin-top: 4px;
}

/* Progress track */
.qb-progress-track {
    margin-top: 20px;
}
.qb-progress-label {
    display: flex;
    justify-content: space-between;
    font-size: 10px;
    font-weight: 700;
    opacity: 0.75;
    margin-bottom: 6px;
}
.qb-bar-wrap {
    display: flex;
    height: 7px;
    border-radius: 99px;
    overflow: hidden;
    background: rgba(255,255,255,0.15);
    gap: 2px;
}
.qb-bar-seg {
    height: 100%;
    border-radius: 99px;
    transition: width 0.6s ease;
}

/* Sub stat cards */
.qb-sub {
    background: var(--card);
    border-radius: 20px;
    padding: 22px 20px;
    border: 1.5px solid var(--border-light);
    box-shadow: 0 4px 20px rgba(0,0,0,0.04);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: transform .25s ease, box-shadow .25s ease;
    cursor: default;
}
.qb-sub:hover { transform: translateY(-4px); box-shadow: 0 12px 32px rgba(0,0,0,0.1); }

.qb-sub-icon {
    width: 46px; height: 46px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 16px;
}
.qb-sub-num {
    font-size: 38px;
    font-weight: 900;
    font-family: 'Courier New', monospace;
    line-height: 1;
    letter-spacing: -1px;
    transition: color 0.3s ease;
}
.qb-sub-name {
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--text-light);
    margin-top: 5px;
}
.qb-zero { color: #cbd5e1 !important; }
</style>

<div class="quiz-bento">

    <!-- Hero: Total Quizzes -->
    <div class="qb-hero">
        <div style="position:relative;z-index:1">
            <div class="qb-hero-label">📊 Quiz Command Center</div>
            <div class="qb-hero-num"><?= $totalQ ?></div>
            <div class="qb-hero-sub">Total Quizzes in system</div>
        </div>
        <!-- Workflow Progress Bar -->
        <div class="qb-progress-track" style="position:relative;z-index:1">
            <div class="qb-progress-label">
                <span>Draft <?= $draftPct ?>%</span>
                <span>Published <?= $pubPct ?>%</span>
                <span>Scheduled <?= $schedPct ?>%</span>
            </div>
            <div class="qb-bar-wrap">
                <div class="qb-bar-seg" style="width:<?= $draftPct ?>%;background:#38bdf8"></div>
                <div class="qb-bar-seg" style="width:<?= $pubPct ?>%;background:#34d399"></div>
                <div class="qb-bar-seg" style="width:<?= $schedPct ?>%;background:#a78bfa"></div>
            </div>
        </div>
        <?php if ($canManage): ?>
        <button onclick="openModal('addQuizModal')"
            style="position:absolute;top:20px;right:20px;z-index:2;width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,0.2);border:1px solid rgba(255,255,255,0.3);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;backdrop-filter:blur(4px);transition:all .2s"
            onmouseover="this.style.background='rgba(255,255,255,0.35)'"
            onmouseout="this.style.background='rgba(255,255,255,0.2)'"
            title="Create New Quiz">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        </button>
        <?php endif; ?>
    </div>

    <!-- Sub: Published -->
    <div class="qb-sub" style="<?= $published > 0 ? 'border-color:#bbf7d0;box-shadow:0 4px 20px rgba(16,185,129,0.12)' : '' ?>">
        <div>
            <div class="qb-sub-icon" style="background:#dcfce7">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke-width="0" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="12" cy="12" r="12" fill="#bbf7d0"/>
                    <polyline points="5,12 10,17 19,7" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                </svg>
            </div>
            <div class="qb-sub-num <?= $published === 0 ? 'qb-zero' : '' ?>" style="<?= $published > 0 ? 'color:#16a34a' : '' ?>"><?= $published ?></div>
            <div class="qb-sub-name">Published</div>
        </div>
        <div style="font-size:11px;color:var(--text-light);margin-top:12px;font-weight:600">
            <?= $published > 0 ? "Live &amp; visible to students" : "No published quizzes yet" ?>
        </div>
    </div>

    <!-- Sub: Draft -->
    <div class="qb-sub" style="<?= $drafts > 0 ? 'border-color:#fed7aa;box-shadow:0 4px 20px rgba(245,158,11,0.1)' : '' ?>">
        <div>
            <div class="qb-sub-icon" style="background:#fef3c7">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="1" y="1" width="22" height="22" rx="11" fill="#fde68a"/>
                    <path d="M8 12h8M12 8v8" stroke="#0284c7" stroke-width="2.5" stroke-linecap="round" fill="none"/>
                </svg>
            </div>
            <div class="qb-sub-num <?= $drafts === 0 ? 'qb-zero' : '' ?>" style="<?= $drafts > 0 ? 'color:#0284c7' : '' ?>"><?= $drafts ?></div>
            <div class="qb-sub-name">In Draft</div>
        </div>
        <div style="font-size:11px;color:var(--text-light);margin-top:12px;font-weight:600">
            <?= $drafts > 0 ? "Awaiting review &amp; publish" : "No drafts pending" ?>
        </div>
    </div>

    <!-- Sub: Scheduled Tests -->
    <div class="qb-sub" style="<?= $scheduled > 0 ? 'border-color:#ddd6fe;box-shadow:0 4px 20px rgba(139,92,246,0.1)' : '' ?>">
        <div>
            <div class="qb-sub-icon" style="background:#f5f3ff">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="1" y="1" width="22" height="22" rx="11" fill="#ede9fe"/>
                    <rect x="6" y="8" width="12" height="9" rx="2" stroke="#7c3aed" stroke-width="2" fill="none"/>
                    <line x1="9" y1="6" x2="9" y2="10" stroke="#7c3aed" stroke-width="2" stroke-linecap="round"/>
                    <line x1="15" y1="6" x2="15" y2="10" stroke="#7c3aed" stroke-width="2" stroke-linecap="round"/>
                    <line x1="6" y1="12" x2="18" y2="12" stroke="#7c3aed" stroke-width="1.5"/>
                </svg>
            </div>
            <div class="qb-sub-num <?= $scheduled === 0 ? 'qb-zero' : '' ?>" style="<?= $scheduled > 0 ? 'color:#7c3aed' : '' ?>"><?= $scheduled ?></div>
            <div class="qb-sub-name">Scheduled Tests</div>
        </div>
        <div style="font-size:11px;color:var(--text-light);margin-top:12px;font-weight:600">
            Weekly &amp; Monthly tests
        </div>
    </div>

</div>


<!-- Create Quiz Modal -->
<?php if ($canManage): ?>
<div class="modal-overlay" id="addQuizModal">
    <div class="modal" style="max-width:660px">
        <div class="modal-header">
            <div class="modal-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Create New Quiz</div><button class="modal-close"
                onclick="closeModal('addQuizModal')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
        </div>
        <div class="modal-body">
            <form method="POST" id="quizForm">
                <?= csrfField() ?>
                <input type="hidden" name="save_quiz" value="1">
                <input type="hidden" name="quiz_type" id="quizTypeField" value="regular">
                <div class="form-grid">
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Quiz Title *</label>
                        <input type="text" name="title" id="quizTitleInput"
                            placeholder="e.g. Maths Chapter 3 - Mid-Week Quiz" required>
                    </div>
                    <div class="form-group">
                        <label>Type</label>
                        <select name="quiz_type" id="quizTypeSelect"
                            onchange="document.getElementById('quizTypeField').value=this.value">
                            <option value="regular"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="2"></circle></svg> Regular Quiz</option>
                            <option value="weekly"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> Weekly Test (All Subjects)</option>
                            <option value="monthly"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> Monthly Test (All Subjects)</option>
                            <option value="dpp"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg> DPP (Daily Practice Paper)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Batch</label>
                        <select name="batch_id">
                            <option value="">- All / No Batch -</option>
                            <?php foreach ($batches as $b): ?>
                            <option value="<?= $b['id'] ?>">
                                <?= sanitize($b['name']) ?>
                                <?= $b['class']?' ('.$b['class'].')':'' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="subjectGroup">
                        <label>Subject <small>(for Regular quizzes)</small></label>
                        <select name="subject">
                            <option value="">- All Subjects / None -</option>
                            <?php foreach ($subjects as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>">
                                <?= htmlspecialchars($s) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Duration (minutes)</label>
                        <input type="number" name="duration_minutes" value="30" min="5" max="180">
                    </div>
                    <div class="form-group">
                        <label>Total Marks</label>
                        <input type="number" name="total_marks" value="10" min="1" max="200">
                    </div>
                    <div class="form-group">
                        <label>Deadline (optional)</label>
                        <input type="datetime-local" name="deadline">
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Description / Instructions</label>
                        <textarea name="desc" placeholder="Instructions for students..."></textarea>
                    </div>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addQuizModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create &amp; Add Questions <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-left:2px"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (empty($quizzes)): ?>
<div class="card">
    <div class="empty-state">
        <div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="6"></circle><circle cx="12" cy="12" r="2"></circle></svg></div>
        <h3>No quizzes yet</h3>
        <p>Create your first quiz and add MCQ questions</p>
        <?php if ($canManage): ?><button class="btn btn-primary btn-sm" onclick="openModal('addQuizModal')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Create Quiz</button>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:18px">
    <?php foreach ($quizzes as $q):
    $statusColor = match($q['status']) { 'published'=>'#16a34a','draft'=>'#0284c7',default=>'#64748b' };
    $statusBg    = match($q['status']) { 'published'=>'#dcfce7','draft'=>'#fef3c7',default=>'#f1f5f9' };
    $isOverdue   = $q['deadline'] && strtotime($q['deadline']) < time();
    $typeLabel   = match($q['quiz_type']??'regular') { 
        'weekly'=>['<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>','Weekly Test','#0ea5e9','#e0f2fe'], 
        'monthly'=>['<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>','Monthly Test','#7c3aed','#f5f3ff'], 
        'dpp'=>['<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>','DPP Test','#10b981','#dcfce7'],
        default=>['<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="2"></circle></svg>','Regular Quiz','#4f46e5','#eef2ff'] 
    };

?>
    <div class="card" style="display:flex;flex-direction:column">
        <div style="padding:18px 20px 14px;flex:1">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
                <div style="font-size:32px">
                    <?= $typeLabel[0] ?>
                </div>
                <div style="display:flex;gap:5px">
                    <span
                        style="background:<?= $typeLabel[3] ?>;color:<?= $typeLabel[2] ?>;padding:3px 9px;border-radius:99px;font-size:10px;font-weight:800">
                        <?= $typeLabel[1] ?>
                    </span>
                    <span
                        style="background:<?= $statusBg ?>;color:<?= $statusColor ?>;padding:3px 9px;border-radius:99px;font-size:10px;font-weight:800">
                        <?= ucfirst($q['status']) ?>
                    </span>
                </div>
            </div>
            <div style="font-weight:900;font-size:15px;margin-bottom:7px;line-height:1.3">
                <?= sanitize($q['title']) ?>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px">
                <?php if ($q['subject']): ?><span class="badge badge-blue">
                    <?= sanitize($q['subject']) ?>
                </span>
                <?php endif; ?>
                <?php if ($q['batch_name']): ?><span class="badge badge-amber"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>
                    <?= sanitize($q['batch_name']) ?>
                </span>
                <?php endif; ?>
                <span class="badge badge-gray"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:3px"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <?= $q['duration_minutes'] ?> min
                </span>
                <span class="badge badge-gray"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:3px"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                    <?= $q['total_marks'] ?> marks
                </span>
            </div>
            <div style="display:flex;gap:14px;font-size:13px;color:var(--text-mid);margin-bottom:12px">
                <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> <strong>
                        <?= $q['qcount'] ?>
                    </strong> Questions</span>
                <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg> <strong>
                        <?= $q['attempts'] ?>
                    </strong> Attempts</span>
            </div>
            <?php if ($q['deadline']): ?>
            <div
                style="font-size:12px;color:<?= $isOverdue?'#dc2626':'var(--text-light)' ?>;font-weight:700;margin-bottom:10px">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> Deadline:
                <?= date('d M Y, h:i A', strtotime($q['deadline'])) ?>
                <?= $isOverdue ? ' - EXPIRED' : '' ?>
            </div>
            <?php endif; ?>
            <div style="display:flex;gap:7px;flex-wrap:wrap">
                <a href="questions.php?quiz_id=<?= $q['id'] ?>" class="btn btn-primary btn-sm"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg> Edit Questions</a>
                <a href="preview.php?quiz_id=<?= $q['id'] ?>" class="btn btn-secondary btn-sm" target="_blank"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg> Preview</a>
                <a href="attempts.php?quiz_id=<?= $q['id'] ?>" class="btn btn-secondary btn-sm"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg> View Attempts</a>
                <?php if ($q['status'] === 'draft'): ?>
                <form method="POST" style="display:inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="toggle_status" value="1"><input
                        type="hidden" name="quiz_id" value="<?= $q['id'] ?>"><input type="hidden" name="new_status"
                        value="published"><button class="btn btn-sm"
                        style="background:#dcfce7;color:#16a34a;border:1.5px solid #86efac"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg> Publish</button></form>
                <?php else: ?>
                <form method="POST" style="display:inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="toggle_status" value="1"><input
                        type="hidden" name="quiz_id" value="<?= $q['id'] ?>"><input type="hidden" name="new_status"
                        value="draft"><button class="btn btn-secondary btn-sm"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg> Unpublish</button></form>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                    <form method="POST" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="delete_quiz"
                        value="<?= $q['id'] ?>"><button class="btn btn-danger btn-sm"
                        data-confirm="Delete this quiz and all its questions/attempts?"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></button></form>
                <?php endif; ?>
            </div>
        </div>
        <div
            style="padding:10px 20px;background:var(--bg);border-top:1.5px solid var(--border);font-size:11px;color:var(--text-light)">
            By
            <?= sanitize($q['creator']??'-') ?> ·
            <?= date('d M Y', strtotime($q['created_at'])) ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($canManage): ?>
<script>
    function quickCreate(type) {
        const titles = {
            weekly: 'Weekly Test - Week of <?= date('d M Y') ?>',
            monthly: 'Monthly Test - <?= date('F Y') ?>'
    };
        document.getElementById('quizTitleInput').value = titles[type] || '';
        document.getElementById('quizTypeSelect').value = type;
        document.getElementById('quizTypeField').value = type;
        // For weekly/monthly, clear subject (covers all)
        const sG = document.getElementById('subjectGroup');
        if (sG) sG.style.opacity = (type === 'regular') ? '1' : '0.4';
        openModal('addQuizModal');
    }
    document.getElementById('quizTypeSelect')?.addEventListener('change', function () {
        const sG = document.getElementById('subjectGroup');
        if (sG) sG.style.opacity = (this.value === 'regular') ? '1' : '0.4';
    });
</script>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
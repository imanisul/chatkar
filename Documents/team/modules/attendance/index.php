<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../../includes/db.php';
require_once '../../includes/auth.php';
requireLogin();

$pageTitle = 'Attendance';
$db   = getDB();
$user = currentUser();

// --- AUTO-MIGRATE MISSING COLUMNS ---
try {
    $db->exec("ALTER TABLE attendance ADD COLUMN `marked_by` int unsigned DEFAULT NULL");
} catch(Exception $e) {}
try {
    $db->exec("ALTER TABLE attendance ADD COLUMN `class` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL");
} catch(Exception $e) {}
// ------------------------------------

// Admin, mentor AND teacher can mark attendance
$canMark = in_array($user['role'], ['admin','mentor','teacher']);
$isTeacher = $user['role'] === 'teacher';

// ── Mark Holiday (admin/mentor only) ──────────────────────────────────────────
$canMarkHoliday = in_array($user['role'], ['admin','mentor']);
if ($canMarkHoliday && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_holiday'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        error_log("CSRF mismatch in attendance holiday mark");
        redirect("index.php?error=csrf");
    }
    $hDate  = $_POST['holiday_date'] ?? '';
    $hTitle = sanitize($_POST['holiday_title'] ?? '');
    if ($hDate && $hTitle) {
        try {
            $db->prepare("INSERT INTO holidays (`date`, title, description, created_by) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description)")
               ->execute([$hDate, $hTitle, sanitize($_POST['holiday_desc'] ?? ''), $user['id']]);
            logActivity($user['id'], "Marked holiday: $hTitle on $hDate", 'attendance');
        } catch(Exception $e) {}
    }
    redirect("index.php?date=$hDate&msg=holiday");
}

// ── Remove Holiday ────────────────────────────────────────
if ($canMarkHoliday && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_holiday'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        error_log("CSRF mismatch in attendance holiday remove");
        redirect("index.php?error=csrf");
    }
    $hDate = $_POST['holiday_date'] ?? '';
    $db->prepare("DELETE FROM holidays WHERE `date`=?")->execute([$hDate]);
    redirect("index.php?date=$hDate&msg=holiday_removed");
}

// ── Save Attendance ───────────────────────────────────────
if ($canMark && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $fallbackDate = trim($_POST['date'] ?? date('Y-m-d'));
        $fallbackClass = urlencode(trim($_POST['att_class'] ?? ''));
        $fallbackSubject = urlencode(trim($_POST['subject'] ?? ''));
        redirect("index.php?date=$fallbackDate&class=$fallbackClass&subject=$fallbackSubject&error=csrf");
    }

    $date     = trim($_POST['date'] ?? date('Y-m-d'));
    $class    = trim($_POST['att_class'] ?? '');
    $subject  = trim($_POST['subject'] ?? '');
    $statuses = $_POST['status'] ?? [];
    $saved    = 0;
    $emailQueue = [];

    if (!empty($statuses) && is_array($statuses)) {
        // Prepare statements ONCE outside the loop
        $insStmt = $db->prepare("INSERT INTO attendance (student_id, `class`, subject, `date`, status, marked_by) VALUES (?,?,?,?,?,?)");

        foreach ($statuses as $sid => $status) {
            $sid    = (int)$sid;
            $status = trim($status);

            // Always delete old record for this student+date+subject first
            try {
                $delStmt->execute([$sid, $date, $subject]);
            } catch(\Throwable $e) {
                error_log("Attendance delete failed sid=$sid: " . $e->getMessage());
            }

            // If status is empty (not marked), just delete and move on
            if ($status === '') continue;

            // Validate status value
            $status = in_array($status, ['Present','Absent','Late']) ? $status : 'Absent';

            try {
                $insStmt->execute([$sid, $class, $subject, $date, $status, $user['id']]);
                $saved++;
                $emailQueue[] = ['sid' => $sid, 'status' => $status];
            } catch(\Throwable $e) {
                error_log("Attendance insert failed sid=$sid: " . $e->getMessage());
            }
        }
    }

    logActivity($user['id'], "Marked attendance for $class - $subject on $date ($saved students)", 'attendance');

    // Teacher Class Log
    if ($isTeacher) {
        try {
            $topicTaught = sanitize($_POST['topic_taught'] ?? '');
            $chapterId   = (int)($_POST['chapter_id'] ?? 0);
            $dayName = date('l', strtotime($date));
            $ttStmt = $db->prepare("SELECT id FROM timetable WHERE teacher_id=? AND `class`=? AND subject=? AND day=? LIMIT 1");
            $ttStmt->execute([$user['id'], $class, $subject, $dayName]);
            $slot = $ttStmt->fetch();
            $ttId = $slot['id'] ?? null;

            $db->prepare("INSERT INTO teacher_class_log (teacher_id, timetable_id, `class`, subject, chapter_id, `date`, status, topic_taught)
                VALUES (?,?,?,?,?,?,'taken',?)
                ON DUPLICATE KEY UPDATE chapter_id=VALUES(chapter_id), topic_taught=VALUES(topic_taught), status='taken'")
               ->execute([$user['id'], $ttId, $class, $subject, $chapterId ?: null, $date, $topicTaught]);
        } catch(\Throwable $e) {
            error_log("Teacher class log failed: " . $e->getMessage());
        }
    }

    // Send emails after DB writes (only if PHPMailer is installed)
    if (!empty($emailQueue) && file_exists(__DIR__ . '/../../includes/PHPMailer/PHPMailer.php')) {
        try {
            require_once '../../includes/email.php';
            if (function_exists('sendAttendanceEmail')) {
                foreach ($emailQueue as $eq) {
                    try {
                        $stInfo = $db->prepare("SELECT name, email FROM students WHERE id=?");
                        $stInfo->execute([$eq['sid']]);
                        $stInfo = $stInfo->fetch();
                        if ($stInfo && !empty($stInfo['email'])) {
                            sendAttendanceEmail($stInfo['email'], $stInfo['name'], $eq['status'], $date, $subject);
                        }
                    } catch (\Throwable $ee) {
                        error_log("Attendance email failed sid={$eq['sid']}: " . $ee->getMessage());
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("Email include failed: " . $e->getMessage());
        }
    }

    // Regenerate CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    redirect("index.php?date=$date&class=".urlencode($class)."&subject=".urlencode($subject)."&msg=saved");
}

// ── Params ───────────────────────────────────────────────
$selDate    = trim($_GET['date']    ?? date('Y-m-d'));
$selClass   = trim($_GET['class']   ?? '');
$selSubject = trim($_GET['subject'] ?? '');

// Classes list - teachers see only their assigned classes
$classes = [];
if ($isTeacher) {
    try {
        // Get classes from batch_teachers->batches (primary) + timetable (fallback)
        $tcStmt = $db->prepare("
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
        $tcStmt->execute([$user['id'], $user['id']]);
        $classes = $tcStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch(Exception $e) {
        error_log('Attendance teacher classes error: ' . $e->getMessage());
    }
    
    // Auto-select first class if none selected
    if (!$selClass && !empty($classes)) $selClass = $classes[0];
} else {
    try {
        $classes = $db->query("SELECT DISTINCT `class` FROM students WHERE `class` IS NOT NULL AND `class`!='' ORDER BY `class` ")->fetchAll(PDO::FETCH_COLUMN);
    } catch(Exception $e) {}
}

// Subjects for selected class
$subjectList = [];
if ($selClass) {
    try {
        if ($isTeacher) {
            // Get subjects from batch_teachers/batch_subjects (primary) + timetable (fallback)
            $sjStmt = $db->prepare("
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
                ) AS teacher_subjects
                ORDER BY subject
            ");
            $sjStmt->execute([$user['id'], $selClass, $user['id'], $selClass, $user['id'], $selClass, $user['id']]);
            $subjectList = $sjStmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $sjStmt = $db->prepare("SELECT DISTINCT subject FROM syllabus WHERE `class`=? ORDER BY subject");
            $sjStmt->execute([$selClass]);
            $subjectList = $sjStmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch(Exception $e) {
        error_log('Attendance teacher subjects error: ' . $e->getMessage());
    }
    // Auto-select first subject if none selected
    if (!$selSubject && !empty($subjectList)) {
        try {
            $autoClassVars = getClassVariations($selClass);
            $autoPlaceholders = implode(',', array_fill(0, count($autoClassVars), '?'));
            $stmt = $db->prepare("SELECT subject FROM attendance a JOIN students s ON s.id=a.student_id WHERE a.`date`=? AND s.`class` IN ($autoPlaceholders) LIMIT 1");
            $stmt->execute(array_merge([$selDate], $autoClassVars));
            $existingSubj = $stmt->fetchColumn();
            if ($existingSubj && in_array($existingSubj, $subjectList)) {
                $selSubject = $existingSubj;
            } else {
                $selSubject = $subjectList[0];
            }
        } catch(Exception $e) {
            $selSubject = $subjectList[0];
        }
    }
}

// Students for selected class
$students = [];
$activeChapters = [];
$existingLog = null;
if ($selClass) {
    try {
        // Use class variations to handle different formats (e.g. "Class 10" vs "10th")
        $classVars = getClassVariations($selClass);
        $clsPlaceholders = implode(',', array_fill(0, count($classVars), '?'));
        $stmt = $db->prepare("SELECT * FROM students WHERE `class` IN ($clsPlaceholders) ORDER BY name");
        $stmt->execute($classVars);
        $students = $stmt->fetchAll();
    } catch(Exception $e) {
        error_log('Attendance student fetch error: ' . $e->getMessage());
    }

    // Phase 16: Chapters & Class Log Pre-fill
    if ($selSubject) {
        try {
            $cVariations = getClassVariations($selClass);
            $inPlaceholders = implode(',', array_fill(0, count($cVariations), '?'));
            
            // Try matching variations and subject
            $chStmt = $db->prepare("SELECT id, chapter_name FROM chapters WHERE `class` IN ($inPlaceholders) AND (LOWER(subject)=LOWER(?) OR subject IS NULL) ORDER BY chapter_order, id");
            $params = array_merge($cVariations, [$selSubject]);
            $chStmt->execute($params);
            $activeChapters = $chStmt->fetchAll();
            
            // Fallback: if no chapters defined, use syllabus topics as chapters
            if (empty($activeChapters)) {
                $syFallback = $db->prepare("SELECT id, topic as chapter_name FROM syllabus WHERE `class` IN ($inPlaceholders) AND (LOWER(subject)=LOWER(?) OR subject IS NULL) ORDER BY id");
                $syFallback->execute($params);
                $activeChapters = $syFallback->fetchAll();
            }
            
            // Fetch topics from syllabus for suggestions (datalist)
            $syStmt = $db->prepare("SELECT topic FROM syllabus WHERE `class` IN ($inPlaceholders) AND (LOWER(subject)=LOWER(?) OR subject IS NULL)");
            $syStmt->execute($params);
            $suggestedTopics = $syStmt->fetchAll(PDO::FETCH_COLUMN);
            
            if ($isTeacher) {
                $logStmt = $db->prepare("SELECT * FROM teacher_class_log WHERE teacher_id=? AND `class`=? AND subject=? AND `date`=? LIMIT 1");
                $logStmt->execute([$user['id'], $selClass, $selSubject, $selDate]);
                $existingLog = $logStmt->fetch();
            }
        } catch(Exception $e) {}
    }
}

// Existing attendance for selected date+class+subject
$attData = [];
if ($selClass) {
    try {
        $classVarsAtt = getClassVariations($selClass);
        $attPlaceholders = implode(',', array_fill(0, count($classVarsAtt), '?'));
        
        $stmt = $db->prepare("SELECT a.student_id, a.status FROM attendance a
            JOIN students s ON s.id=a.student_id 
            WHERE a.`date`=? AND s.`class` IN ($attPlaceholders) 
            AND (
                a.subject = ?
                OR (a.subject IS NULL AND ? = '')
                OR (a.subject = '' AND ? = '')
            )");
        $stmt->execute(array_merge([$selDate], $classVarsAtt, [$selSubject, $selSubject, $selSubject]));
        foreach ($stmt->fetchAll() as $r) $attData[$r['student_id']] = $r['status'];
    } catch(Exception $e) {
        error_log("Attendance Fetch Error: " . $e->getMessage());
    }
}

// Check holiday
$holiday = null;
try {
    $stmt = $db->prepare("SELECT * FROM holidays WHERE `date`=? ");
    $stmt->execute([$selDate]);
    $holiday = $stmt->fetch();
} catch(Exception $e) {}

// Stats for selected class/date
$presentCount = 0; $absentCount = 0; $lateCount = 0;
foreach ($students as $s) {
    $st = $attData[$s['id']] ?? '';
    if ($st === 'Present') $presentCount++;
    elseif ($st === 'Absent') $absentCount++;
    elseif ($st === 'Late') $lateCount++;
}
$marked = $presentCount + $absentCount + $lateCount;

$isEditMode = isset($_GET['action']) && $_GET['action'] === 'edit';
$isReadOnly = ($marked > 0) && !$isEditMode;

$markedAnySubject = 0;
if ($selClass) {
    try {
        $classVarsStats = getClassVariations($selClass);
        $statsPlaceholders = implode(',', array_fill(0, count($classVarsStats), '?'));
        $stmt = $db->prepare("SELECT COUNT(*) FROM attendance a JOIN students s ON s.id=a.student_id WHERE a.`date`=? AND s.`class` IN ($statsPlaceholders)");
        $stmt->execute(array_merge([$selDate], $classVarsStats));
        $markedAnySubject = (int)$stmt->fetchColumn();
    } catch(Exception $e) {}
}

// Calendar: get month attendance summary
$month = date('Y-m', strtotime($selDate));
$monthStats = [];
try {
    $stmt = $db->prepare("SELECT `date`, status, COUNT(*) as cnt FROM attendance
        WHERE `date` LIKE ? GROUP BY `date`, status");
    $stmt->execute(["$month%"]);
    foreach ($stmt->fetchAll() as $r) {
        $monthStats[$r['date']][$r['status']] = $r['cnt'];
    }
} catch(Exception $e) {}

// All holidays this month
$monthHolidays = [];
try {
    $stmt = $db->prepare("SELECT `date`, title FROM holidays WHERE `date` LIKE ?");
    $stmt->execute(["$month%"]);
    foreach ($stmt->fetchAll() as $r) $monthHolidays[$r['date']] = $r['title'];
} catch(Exception $e) {}

$root = '../../';
require_once '../../includes/header.php';
?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'csrf'): ?>
<div class="alert alert-danger" data-auto-dismiss>⚠️ Security token mismatch. Please try again.</div>
<?php endif; ?>
<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success" data-auto-dismiss>
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg>
    <?= match($_GET['msg']) {
        'saved' => 'Attendance saved for '.htmlspecialchars($selDate).'!',
        'holiday' => 'Holiday marked!',
        'holiday_removed' => 'Holiday removed.',
        default => 'Done!'
    } ?>
</div>
<?php endif; ?>

<div class="page-header">
    <div class="page-header-left">
        <h1><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg> Attendance</h1>
        <p>Day-wise attendance tracking with holiday management</p>
    </div>
    <div class="page-header-actions">
        <?php if ($canMark): ?>
        <a href="report.php" class="btn btn-secondary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg> Full Report</a>
        <?php if ($selClass): ?>
        <a href="class_records.php?class=<?= urlencode($selClass) ?>" class="btn btn-secondary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg> Class Report</a>
        <?php endif; ?>
        <?php if (!$holiday): ?>
        <button class="btn btn-danger" onclick="openModal('holidayModal')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> Mark Holiday</button>
        <?php else: ?>
        <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="holiday_date" value="<?= $selDate ?>">
            <button type="submit" name="remove_holiday" class="btn btn-secondary"
                data-confirm="Remove holiday mark for this date?"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg> Remove Holiday</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Holiday Modal -->
<?php if ($canMark): ?>
<div class="modal-overlay" id="holidayModal">
    <div class="modal" style="max-width:440px">
        <div class="modal-header">
            <div class="modal-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> Mark Holiday</div>
            <button class="modal-close" onclick="closeModal('holidayModal')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="mark_holiday" value="1">
                <div class="form-group">
                    <label>Date</label>
                    <input type="date" name="holiday_date" value="<?= $selDate ?>" required>
                </div>
                <div class="form-group">
                    <label>Holiday Name *</label>
                    <input type="text" name="holiday_title" placeholder="e.g. Diwali, Independence Day" required
                        autofocus>
                </div>
                <div class="form-group">
                    <label>Description <small>(optional)</small></label>
                    <textarea name="holiday_desc" rows="2" placeholder="Additional details..."></textarea>
                </div>
                <div class="modal-footer"
                    style="padding:0;border:none;margin-top:20px;display:flex;gap:10px;justify-content:flex-end">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('holidayModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg> Save Holiday</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══ TOP ROW: Date Picker + Class Picker ══ -->
<div class="card" style="margin-bottom:20px">
    <div class="filter-bar" style="flex-wrap:wrap;gap:12px">
        <form method="GET" id="filterForm" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;flex:1">
            <div style="display:flex;align-items:center;gap:8px">
                <span style="font-weight:700;font-size:13px;color:var(--text-mid)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> Date:</span>
                <input type="date" name="date" id="dateInput" value="<?= $selDate ?>" max="<?= date('Y-m-d') ?>"
                    style="padding:9px 12px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-size:13px;background:#fff;outline:none;cursor:pointer"
                    onchange="document.getElementById('filterForm').submit()">
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <span style="font-weight:700;font-size:13px;color:var(--text-mid)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg> Class:</span>
                <select name="class" onchange="document.getElementById('filterForm').submit()"
                    style="padding:9px 14px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-size:13px;background:#fff;outline:none;cursor:pointer;min-width:160px">
                    <option value="">- Select Class -</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>" <?=$selClass===$c?'selected':'' ?>>
                        <?= htmlspecialchars($c) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($selClass && !empty($subjectList)): ?>
            <div style="display:flex;align-items:center;gap:8px">
                <span style="font-weight:700;font-size:13px;color:var(--text-mid)">📚 Subject:</span>
                <select name="subject" onchange="document.getElementById('filterForm').submit()"
                    style="padding:9px 14px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-size:13px;background:#fff;outline:none;cursor:pointer;min-width:160px">
                    <?php foreach ($subjectList as $sj): ?>
                    <option value="<?= htmlspecialchars($sj) ?>" <?=$selSubject===$sj?'selected':'' ?>><?= htmlspecialchars($sj) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <!-- Nav arrows -->
            <a href="?date=<?= date('Y-m-d', strtotime($selDate.' -1 day')) ?>&class=<?= urlencode($selClass) ?>"
                class="btn btn-secondary btn-sm"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><polyline points="15 18 9 12 15 6"></polyline></svg> Prev Day</a>
            <a href="?date=<?= date('Y-m-d') ?>&class=<?= urlencode($selClass) ?>"
                class="btn btn-secondary btn-sm">Today</a>
            <a href="?date=<?= date('Y-m-d', strtotime($selDate.' +1 day')) ?>&class=<?= urlencode($selClass) ?>"
                class="btn btn-secondary btn-sm">Next Day <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-left:2px"><polyline points="9 18 15 12 9 6"></polyline></svg></a>
        </form>
        <!-- Day label -->
        <div style="display:flex;align-items:center;gap:10px;margin-left:auto">
            <div style="text-align:right">
                <div style="font-weight:800;font-size:16px;color:var(--text)">
                    <?= date('l', strtotime($selDate)) ?>
                </div>
                <div style="font-size:12px;color:var(--text-light)">
                    <?= date('d M Y', strtotime($selDate)) ?>
                </div>
            </div>
            <?php
            $dow = date('N', strtotime($selDate));
            if ($holiday):
            ?>
            <span class="badge badge-red" style="padding:6px 12px;font-size:13px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> Holiday</span>
            <?php elseif ($dow == 7): ?>
            <span class="badge badge-gray" style="padding:6px 12px;font-size:13px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> Sunday</span>
            <?php elseif ($markedAnySubject > 0): ?>
            <span class="badge badge-green" style="padding:6px 12px;font-size:13px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg> Attendance Marked</span>
            <?php else: ?>
            <span class="badge badge-blue-deep" style="padding:6px 12px;font-size:13px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> Not Marked</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($holiday): ?>
<!-- Holiday Banner -->
<div
    style="background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border:2px solid #bae6fd;border-radius:var(--r-lg);padding:35px;text-align:center;margin-bottom:25px">
    <div style="font-size:48px;margin-bottom:15px"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#0369a1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg></div>
    <h2 style="font-family:'Poppins',sans-serif;font-size:24px;color:#0369a1;margin-bottom:8px;font-weight:900">
        <?= sanitize($holiday['title']) ?>
    </h2>
    <p style="color:var(--text-mid);font-size:15px;font-weight:600">
        <?= $holiday['description'] ? sanitize($holiday['description']) : date('l, d F Y', strtotime($selDate)) ?>
    </p>
    <p style="color:var(--text-light);font-size:13px;margin-top:10px">No attendance tracking required</p>
</div>

<?php elseif ($dow == 7): ?>
<!-- Sunday Banner -->
<div
    style="background:#f1f5f9;border:2px dashed var(--border);border-radius:var(--r-lg);padding:28px;text-align:center;margin-bottom:20px">
    <div style="font-size:48px;margin-bottom:10px"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></div>
    <h2 style="font-family:'Poppins',sans-serif;font-size:20px;color:var(--text-mid)">Sunday - School Holiday</h2>
    <p style="color:var(--text-light);font-size:13px">No attendance on Sundays</p>
</div>

<?php elseif (!$selClass): ?>
<!-- Class selector prompt -->
<div
    style="background:var(--blue-light);border:2px dashed var(--blue-mid);border-radius:var(--r-lg);padding:36px;text-align:center;margin-bottom:20px">
    <div style="font-size:48px;margin-bottom:12px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg></div>
    <h2 style="font-family:'Poppins',sans-serif;font-size:18px;color:var(--blue-deep);margin-bottom:6px">Select a Class
        to Mark Attendance</h2>
    <p style="color:var(--text-mid);font-size:13.5px;margin-bottom:20px">Choose a class from the dropdown above to get
        started</p>
    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
        <?php foreach ($classes as $c): ?>
        <a href="?date=<?= $selDate ?>&class=<?= urlencode($c) ?>" class="btn btn-secondary">
            <?= htmlspecialchars($c) ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<?php else: ?>

<!-- Stats bar -->
<?php if ($marked > 0): ?>
<div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;align-items:center">

    <div
        style="background:var(--green-light);border:1.5px solid var(--green-mid);border-radius:var(--r);padding:14px 20px;display:flex;align-items:center;gap:10px;flex:1;min-width:120px">
        <span style="font-size:22px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg></span>
        <div>
            <div style="font-weight:800;font-size:22px;color:var(--green)">
                <?= $presentCount ?>
            </div>
            <div style="font-size:11px;color:var(--green);font-weight:700;text-transform:uppercase">Present</div>
        </div>
    </div>
    <div
        style="background:var(--red-light);border:1.5px solid var(--red-mid);border-radius:var(--r);padding:14px 20px;display:flex;align-items:center;gap:10px;flex:1;min-width:120px">
        <span style="font-size:22px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></span>
        <div>
            <div style="font-weight:800;font-size:22px;color:var(--red)">
                <?= $absentCount ?>
            </div>
            <div style="font-size:11px;color:var(--red);font-weight:700;text-transform:uppercase">Absent</div>
        </div>
    </div>
    <div
        style="background:var(--amber-light);border:1.5px solid var(--amber-mid);border-radius:var(--r);padding:14px 20px;display:flex;align-items:center;gap:10px;flex:1;min-width:120px">
        <span style="font-size:22px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></span>
        <div>
            <div style="font-weight:800;font-size:22px;color:var(--amber)">
                <?= $lateCount ?>
            </div>
            <div style="font-size:11px;color:var(--amber);font-weight:700;text-transform:uppercase">Late</div>
        </div>
    </div>
    <div
        style="background:var(--blue-light);border:1.5px solid var(--blue-mid);border-radius:var(--r);padding:14px 20px;display:flex;align-items:center;gap:10px;flex:1;min-width:120px">
        <span style="font-size:22px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></span>
        <div>
            <div style="font-weight:800;font-size:22px;color:var(--blue-deep)">
                <?= $marked > 0 ? round(($presentCount/$marked)*100) : 0 ?>%
            </div>
            <div style="font-size:11px;color:var(--blue-deep);font-weight:700;text-transform:uppercase">Attendance</div>
        </div>
    </div>
    
    <?php if ($canMark && !$isEditMode): ?>
    <div style="margin-left:auto">
        <a href="?date=<?= $selDate ?>&class=<?= urlencode($selClass) ?>&subject=<?= urlencode($selSubject) ?>&action=edit" class="btn btn-outline-secondary" style="border-width:2px;font-weight:700">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
            Edit Attendance
        </a>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Attendance Table -->
<?php if ($students): ?>
<form method="POST" id="attForm">
    <?= csrfField() ?>
    <input type="hidden" name="save_attendance" value="1">
    <input type="hidden" name="date" value="<?= $selDate ?>">
    <input type="hidden" name="att_class" value="<?= htmlspecialchars($selClass) ?>">
    <input type="hidden" name="subject" value="<?= htmlspecialchars($selSubject) ?>">

    <div class="card">
        <div class="card-header" style="flex-wrap:wrap;gap:15px">
            <div class="card-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>
                <?= htmlspecialchars($selClass) ?> -
                <?= date('l, d M Y', strtotime($selDate)) ?>
                <span class="badge badge-blue">
                    <?= count($students) ?> students
                </span>
            </div>

            <?php if ($isTeacher && $selSubject): ?>
            <div style="display:flex;gap:12px;flex:1;min-width:300px;align-items:flex-end">
                <div class="form-group" style="margin:0;flex:1">
                    <label
                        style="font-size:11px;font-weight:800;text-transform:uppercase;color:var(--text-light);margin-bottom:4px;display:block"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
                        Chapter</label>
                    <select name="chapter_id" style="padding:10px 12px;font-size:13.5px;width:100%;border-radius:var(--r);border:2px solid var(--border)">
                        <option value="">- Select Chapter -</option>
                        <?php foreach ($activeChapters as $ch): ?>
                        <option value="<?= $ch['id'] ?>" <?=($existingLog['chapter_id']??0)==$ch['id']?'selected':'' ?>>
                            <?= htmlspecialchars($ch['chapter_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0;flex:1.5">
                    <label
                        style="font-size:11px;font-weight:800;text-transform:uppercase;color:var(--text-light);margin-bottom:4px;display:block"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                        Topic Taught</label>
                    <input type="text" name="topic_taught" id="topicInput" list="topicList" autocomplete="off"
                        value="<?= htmlspecialchars($existingLog['topic_taught']??'') ?>"
                        placeholder="What did you teach today?" style="padding:10px 12px;font-size:13.5px;width:100%;border-radius:var(--r);border:2px solid var(--border)">
                    <datalist id="topicList">
                        <?php if(!empty($suggestedTopics)): foreach(array_unique($suggestedTopics) as $stTopic): ?>
                        <option value="<?= htmlspecialchars($stTopic) ?>">
                        <?php endforeach; endif; ?>
                    </datalist>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;margin-top:20px;padding:0 4px">
            <h3 style="font-size:15px;margin:0;font-weight:700;color:var(--text-mid)">Student List</h3>
            <?php if ($canMark && !$isReadOnly): ?>
            <div style="display:flex;gap:8px">
                <button type="button" class="btn btn-outline" style="border-color:var(--green);color:var(--green);font-size:12px;padding:6px 12px;border:2px solid;background:transparent" onclick="markAll('Present')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg> Mark All Present</button>
                <button type="button" class="btn btn-outline" style="border-color:var(--red);color:var(--red);font-size:12px;padding:6px 12px;border:2px solid;background:transparent" onclick="markAll('Absent')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg> Mark All Absent</button>
            </div>
            <?php endif; ?>
        </div>

        <div class="table-wrap">
        <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th>Student Name</th>
                        <?php if ($canMark && !$isTeacher): ?>
                        <th>Parent</th>
                        <th>Phone</th>
                        <?php endif; ?>
                        <th>Attendance</th>
                        <th style="width:80px">History</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $i => $s):
                $curSt = $attData[$s['id']] ?? '';
                $rowBg = '';
                if ($isReadOnly) {
                    if ($curSt === 'Present') $rowBg = 'background:var(--green-light);';
                    elseif ($curSt === 'Absent') $rowBg = 'background:var(--red-light);';
                    elseif ($curSt === 'Late') $rowBg = 'background:var(--amber-light);';
                }
            ?>
                    <tr id="row-<?= $s['id'] ?>" style="<?= $rowBg ?>">
                        <td class="font-mono text-muted">
                            <?= $i+1 ?>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px">
                                <div style="width:36px;height:36px;border-radius:50%;background:var(--blue-light);
                             display:flex;align-items:center;justify-content:center;font-size:14px;
                             font-weight:800;color:var(--blue-deep);border:2px solid var(--blue-mid);flex-shrink:0">
                                    <?= strtoupper(substr($s['name'],0,1)) ?>
                                </div>
                                <div>
                                    <strong>
                                        <?= sanitize($s['name']) ?>
                                    </strong>
                                    <div style="font-size:11.5px;color:var(--text-light)">
                                        <?= sanitize($s['batch']??'') ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <?php if ($canMark && !$isTeacher): ?>
                        <td style="font-size:13px">
                            <?= sanitize($s['parent_name']??'-') ?>
                        </td>
                        <td class="font-mono" style="font-size:13px">
                            <?= sanitize($s['phone']??'-') ?>
                        </td>
                        <?php endif; ?>
                        <td>
                            <?php if ($canMark && !$isReadOnly): ?>
                            <div class="att-toggle" data-sid="<?= $s['id'] ?>" data-current="<?= htmlspecialchars($curSt) ?>" style="display:inline-flex; border: 1.5px solid var(--border); border-radius: 99px; overflow: hidden; background: #f8fafc;">
                                <input type="hidden" name="status[<?= $s['id'] ?>]" id="hid_<?= $s['id'] ?>" value="<?= htmlspecialchars($curSt ?: '') ?>">
                                <?php foreach ([
                            'Present' => ['<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg>','var(--green)','var(--green-light)','transparent'],
                            'Absent'  => ['<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>','var(--red)',  'var(--red-light)',  'transparent'],
                            'Late'    => ['<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>','var(--amber)','var(--amber-light)','transparent'],
                        ] as $status => [$ico, $col, $bg, $bord]):
                            $active = $curSt === $status;
                        ?>
                                <button type="button"
                                    class="att-label <?= $active?'att-active':'' ?>"
                                    data-status="<?= $status ?>"
                                    data-color="<?= $col ?>" data-bg="<?= $bg ?>" data-border="<?= $bord ?>"
                                    style="<?= $active?"background:$bg;color:$col":'background:transparent;color:var(--text-light)' ?>;
                                    padding:6px 14px;font-size:12px;font-weight:700;
                                    cursor:pointer;border:none;transition:all .2s;display:inline-flex;align-items:center;gap:4px"
                                    onclick="toggleAtt(this)">
                                    <?= $ico ?>
                                    <?= $status ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <?php if ($curSt === 'Present'): ?><span class="badge badge-green" style="font-size:12px;padding:6px 14px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg> Present</span>
                            <?php elseif ($curSt === 'Absent'): ?><span class="badge badge-red" style="font-size:12px;padding:6px 14px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg> Absent</span>
                            <?php elseif ($curSt === 'Late'): ?><span class="badge badge-amber" style="font-size:12px;padding:6px 14px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> Late</span>
                            <?php else: ?><span class="badge badge-gray" style="font-size:12px;padding:6px 14px">- Not Marked</span>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="student_report.php?id=<?= $s['id'] ?>" class="btn btn-secondary btn-sm"
                                data-tooltip="View history"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($canMark && !$isReadOnly): ?>
        <div
            style="padding:18px 22px;border-top:1px solid var(--border-light);display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button type="submit" class="btn btn-primary"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg> Save Attendance for
                <?= htmlspecialchars($selClass) ?>
            </button>
            <span style="font-size:12.5px;color:var(--text-light)">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                <?= date('l, d M Y', strtotime($selDate)) ?>
            </span>
        </div>
        <?php endif; ?>
    </div>
</form>

<style>
    .att-label {
        user-select: none
    }

    .att-label:hover {
        opacity: 0.8
    }
</style>

<script>
    function toggleAtt(btn) {
        const toggle = btn.closest('.att-toggle');
        if (!toggle) return;
        const sid = toggle.dataset.sid;
        const hiddenInput = document.getElementById('hid_' + sid);
        const status = btn.dataset.status;

        if (hiddenInput.value === status) {
            // Do NOT deselect on re-click as per user feedback (preventing accidental removal)
            return;
        }

        // Reset all buttons in this toggle
        toggle.querySelectorAll('.att-label').forEach(b => {
            b.classList.remove('att-active');
            b.style.background = 'transparent';
            b.style.color = 'var(--text-light)';
        });

        // Always select the clicked status
        hiddenInput.value = status;
        btn.classList.add('att-active');
        btn.style.background = btn.dataset.bg;
        btn.style.borderColor = btn.dataset.border;
        btn.style.color = btn.dataset.color;

        // Visual row highlight
        const row = document.getElementById('row-' + sid);
        if (row) {
            row.style.background = status === 'Present' ? 'var(--green-light)' : 
                                   status === 'Absent'  ? 'var(--red-light)' : 
                                   status === 'Late'    ? 'var(--amber-light)' : '';
        }
    }

    function markAll(val) {
        document.querySelectorAll('.att-toggle').forEach(toggle => {
            const sid = toggle.dataset.sid;
            const hiddenInput = document.getElementById('hid_' + sid);

            // Reset all buttons
            toggle.querySelectorAll('.att-label').forEach(b => {
                b.classList.remove('att-active');
                b.style.background = 'transparent';
                b.style.color = 'var(--text-light)';
            });

            // Find and activate target button
            const btn = toggle.querySelector('[data-status="' + val + '"]');
            if (btn && hiddenInput) {
                btn.classList.add('att-active');
                btn.style.background = btn.dataset.bg;
                btn.style.color = btn.dataset.color;
                hiddenInput.value = val;
            }

            // Row highlight
            const row = document.getElementById('row-' + sid);
            if (row) {
                row.style.background = val === 'Present' ? 'var(--green-light)' : 
                                       val === 'Absent'  ? 'var(--red-light)' : 
                                       val === 'Late'    ? 'var(--amber-light)' : '';
            }
        });
    }
</script>

<?php else: ?>
<div class="card">
    <div class="empty-state">
        <div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></div>
        <h3>No students in <?= htmlspecialchars($selClass) ?></h3>
        <p>Add students to this class first</p>
        <?php if ($canMark): ?>
        <a href="../students/add.php" class="btn btn-primary btn-sm" style="border-radius:10px"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:5px"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Add Student</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Monthly mini-calendar -->
<div class="card" style="margin-top:24px">
    <div class="card-header">
        <div class="card-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
            <?= date('F Y', strtotime($selDate)) ?> - Attendance Overview
        </div>
        <div style="display:flex;gap:8px">
            <a href="?date=<?= date('Y-m-01', strtotime($selDate.' -1 month')) ?>&class=<?= urlencode($selClass) ?>"
                class="btn btn-secondary btn-sm"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><polyline points="15 18 9 12 15 6"></polyline></svg> Prev Month</a>
            <a href="?date=<?= date('Y-m-01', strtotime($selDate.' +1 month')) ?>&class=<?= urlencode($selClass) ?>"
                class="btn btn-secondary btn-sm">Next Month <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-left:2px"><polyline points="9 18 15 12 9 6"></polyline></svg></a>
        </div>
    </div>
    <div class="card-body">
        <?php
    $firstDay  = date('Y-m-01', strtotime($selDate));
    $lastDay   = date('Y-m-t',  strtotime($selDate));
    $startDow  = (int)date('N', strtotime($firstDay)); // 1=Mon
    $totalDays = (int)date('t', strtotime($selDate));
    ?>
        <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;text-align:center">
            <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?>
            <div style="font-size:11px;font-weight:700;color:var(--text-light);padding:4px">
                <?= $d ?>
            </div>
            <?php endforeach; ?>
            <?php for ($pad=1; $pad < $startDow; $pad++): ?>
            <div></div>
            <?php endfor; ?>
            <?php for ($d=1; $d<=$totalDays; $d++):
            $dStr   = date('Y-m', strtotime($selDate)).'-'.str_pad($d,2,'0',STR_PAD_LEFT);
            $dow    = (int)date('N', strtotime($dStr));
            $isToday= $dStr === date('Y-m-d');
            $isSel  = $dStr === $selDate;
            $isHol  = isset($monthHolidays[$dStr]);
            $hasStat= isset($monthStats[$dStr]);

            $bg='var(--bg2)'; $color='var(--text)'; $border='transparent'; $extra='';
            if ($isHol)    { $bg='var(--red-light)'; $color='var(--red)'; $border='var(--red-mid)'; }
            elseif ($dow==7){ $bg='var(--bg2)'; $color='var(--text-light)'; }
            elseif ($hasStat){ $bg='var(--green-light)'; $color='var(--green)'; $border='var(--green-mid)'; }
            if ($isSel) { $border='var(--blue)'; $extra='box-shadow:0 0 0 2px var(--blue);'; }
            if ($isToday && !$isSel) { $border='var(--blue-mid)'; }
        ?>
            <a href="?date=<?= $dStr ?>&class=<?= urlencode($selClass) ?>" style="display:flex;flex-direction:column;align-items:center;justify-content:center;
                  padding:8px 4px;border-radius:var(--r-sm);background:<?= $bg ?>;color:<?= $color ?>;
                  border:1.5px solid <?= $border ?>;text-decoration:none;min-height:44px;
                  font-size:13px;font-weight:<?= $isSel?'800':'600' ?>;transition:all .15s;<?= $extra ?>">
                <?= $d ?>
                <?php if ($isHol): ?><span style="margin-top:1px"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg></span>
                <?php elseif ($hasStat): ?>
                <?php $p=$monthStats[$dStr]['Present']??0; $a=$monthStats[$dStr]['Absent']??0; $tot=$p+$a; ?>
                <span style="font-size:9px;font-weight:600;margin-top:1px">
                    <?= $tot>0?round($p/$tot*100).'%':'' ?>
                </span>
                <?php endif; ?>
            </a>
            <?php endfor; ?>
        </div>
        <div style="display:flex;gap:16px;margin-top:16px;flex-wrap:wrap;font-size:12px;color:var(--text-light)">
            <span style="display:flex;align-items:center;gap:5px"><span
                    style="width:14px;height:14px;border-radius:4px;background:var(--green-light);border:1px solid var(--green-mid);display:inline-block"></span>
                Attendance Marked</span>
            <span style="display:flex;align-items:center;gap:5px"><span
                    style="width:14px;height:14px;border-radius:4px;background:var(--red-light);border:1px solid var(--red-mid);display:inline-block"></span>
                Holiday <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-left:2px"><path d="M12 2L15 8.5L22 9.5L17 14.2L18.2 21L12 17.8L5.8 21L7 14.2L2 9.5L9 8.5L12 2Z"></path></svg></span>
            <span style="display:flex;align-items:center;gap:5px"><span
                    style="width:14px;height:14px;border-radius:4px;background:var(--bg2);border:1.5px solid var(--blue);display:inline-block"></span>
                Selected</span>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const topicList = document.getElementById('topicList');
    const selClass = <?= json_encode($selClass) ?>;
    const selSubj = <?= json_encode($selSubject) ?>;
    
    if (topicList && selClass && selSubj) {
        fetch(`../syllabus/get_topics.php?class=${encodeURIComponent(selClass)}&subject=${encodeURIComponent(selSubj)}`)
            .then(r => r.json())
            .then(data => {
                data.forEach(t => {
                    const opt = document.createElement('option');
                    opt.value = t.topic;
                    topicList.appendChild(opt);
                });
            })
            .catch(e => console.error('Topics error:', e));
    }
});
</script>
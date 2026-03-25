<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
requireLogin();

$pageTitle = 'Manage Notes';
$db   = getDB();
$user = currentUser();
$isStaff = in_array($user['role'], ['admin', 'teacher', 'mentor']);

if (!$isStaff) {
    die("Unauthorized access.");
}

$isTeacher = $user['role'] === 'teacher';

// Handle Note Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_note'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        error_log("CSRF mismatch in note delete");
        redirect("index.php?error=csrf");
    }
    $noteId = (int)$_POST['note_id'];
    $stmt = $db->prepare("DELETE FROM notes WHERE id=?");
    $stmt->execute([$noteId]);
    logActivity($user['id'], "Deleted note ID: $noteId", 'notes');
    redirect("index.php?msg=deleted");
}

// Filters
$selClass   = $_GET['class'] ?? '';
$selSubject = $_GET['subject'] ?? '';

// Dynamically detect notes table columns for robust display
$noteColumns = [];
try {
    $colStmt = $db->query("SHOW COLUMNS FROM notes");
    $noteColumns = $colStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Notes column check failed: " . $e->getMessage());
}

// Build a safe SELECT list based on actual columns
$selectFields = ['n.id', 'n.created_at'];
$hasSubjectId    = in_array('subject_id', $noteColumns);
$hasClassName    = in_array('class_name', $noteColumns);
$hasTitle        = in_array('title', $noteColumns);
$hasDescription  = in_array('description', $noteColumns);
$hasGoogleLink   = in_array('google_drive_link', $noteColumns);
$hasFilePath     = in_array('file_path', $noteColumns);
$hasTopicName    = in_array('topic_name', $noteColumns);
$hasUploadedRole = in_array('uploaded_role', $noteColumns);
$hasStatus       = in_array('status', $noteColumns);
$hasChapterId    = in_array('chapter_id', $noteColumns);
$hasUploadedBy   = in_array('uploaded_by', $noteColumns);

if ($hasSubjectId)    $selectFields[] = 'n.subject_id';
if ($hasClassName)    $selectFields[] = 'n.class_name';
if ($hasTitle)        $selectFields[] = 'n.title';
if ($hasDescription)  $selectFields[] = 'n.description';
if ($hasGoogleLink)   $selectFields[] = 'n.google_drive_link';
if ($hasFilePath)     $selectFields[] = 'n.file_path';
if ($hasTopicName)    $selectFields[] = 'n.topic_name';
if ($hasUploadedRole) $selectFields[] = 'n.uploaded_role';
if ($hasStatus)       $selectFields[] = 'n.status';

// Fetch Notes
try {
    $sql = "SELECT " . implode(', ', $selectFields);
    if ($hasChapterId) $sql .= ", c.chapter_name";
    if ($hasUploadedBy) $sql .= ", u.name as teacher_name";
    $sql .= " FROM notes n";
    if ($hasChapterId) $sql .= " LEFT JOIN chapters c ON n.chapter_id = c.id";
    if ($hasUploadedBy) $sql .= " LEFT JOIN users u ON n.uploaded_by = u.id";
    $sql .= " WHERE 1=1";
    $params = [];

    if ($isTeacher && $hasUploadedBy) {
        $sql .= " AND n.uploaded_by = ?";
        $params[] = $user['id'];
    }

    if ($selClass && $hasClassName) {
        $sql .= " AND n.class_name = ?";
        $params[] = $selClass;
    }

    if ($selSubject && $hasSubjectId) {
        $sql .= " AND n.subject_id = ?";
        $params[] = $selSubject;
    }

    $sql .= " ORDER BY " . ($hasSubjectId ? "n.subject_id ASC, " : "") . "n.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $notes = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Notes fetch failed: " . $e->getMessage());
    $notes = [];
}

// Group notes by subject
$groupedNotes = [];
foreach ($notes as $n) {
    $subj = $n['subject_id'] ?? 'Uncategorized';
    $groupedNotes[$subj][] = $n;
}

// Fetch Classes for Filters
try {
    if ($isTeacher) {
        $tcStmt = $db->prepare("SELECT DISTINCT class FROM timetable WHERE teacher_id=? ORDER BY class");
        $tcStmt->execute([$user['id']]);
        $classes = $tcStmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $classes = $db->query("SELECT DISTINCT class FROM students WHERE class IS NOT NULL AND class!='' ORDER BY class")->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {
    $classes = [];
}

// Fetch Subjects for Filter
try {
    if ($hasSubjectId) {
        $subjs = $db->query("SELECT DISTINCT subject_id FROM notes WHERE subject_id IS NOT NULL AND subject_id!='' ORDER BY subject_id")->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $subjs = $db->query("SELECT DISTINCT subject FROM syllabus ORDER BY subject")->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {
    $subjs = [];
}

$root = '../../';
require_once '../../includes/header.php';
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success" data-auto-dismiss>
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
        stroke-linecap="round" stroke-linejoin="round"
        style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
        <polyline points="20 6 9 17 4 12"></polyline>
    </svg>
    <?= match($_GET['msg']) {
        'uploaded' => 'Notes published successfully!',
        'deleted' => 'Notes deleted.',
        default => 'Done!'
    } ?>
</div>
<?php endif; ?>

<?php if (isset($_GET['error'])): ?>
<div class="alert alert-danger">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"></polygon><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
    <?= match($_GET['error']) {
        'db' => 'Database error: ' . sanitize($_GET['details'] ?? 'Unknown'),
        'csrf' => 'Security validation failed.',
        default => 'An unknown error occurred.'
    } ?>
</div>
<?php endif; ?>

<style>
.notes-list-container { display: flex; flex-direction: column; gap: 12px; }
.note-row {
    background: rgba(255,255,255,0.85);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(255,255,255,0.9);
    border-radius: 14px;
    padding: 16px 22px;
    display: flex;
    align-items: center;
    gap: 18px;
    transition: all 0.25s ease;
    box-shadow: 0 2px 8px rgba(0,0,0,0.03);
}
.note-row:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(79,70,229,0.08);
    border-color: rgba(79,70,229,0.2);
}
.nr-icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    background: linear-gradient(135deg, #eef2ff, #e0e7ff);
    color: #4f46e5;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.nr-content { flex: 1; min-width: 0; }
.nr-title { font-weight: 800; font-size: 15px; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.nr-desc { font-size: 12px; color: #64748b; font-weight: 600; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.nr-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.nr-badge { font-size: 10px; font-weight: 800; padding: 3px 8px; border-radius: 6px; white-space: nowrap; }
.nr-actions { display: flex; gap: 8px; flex-shrink: 0; align-items: center; }
.subj-group-header {
    display: flex; align-items: center; gap: 12px;
    margin: 28px 0 14px 0;
    padding-bottom: 10px;
    border-bottom: 2px solid #e2e8f0;
}
.subj-group-header:first-child { margin-top: 0; }
.subj-group-icon {
    width: 40px; height: 40px; border-radius: 12px;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: #fff; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.subj-group-name { font-size: 18px; font-weight: 900; color: #1e293b; }
.subj-group-count { font-size: 12px; font-weight: 800; color: #64748b; background: #f1f5f9; padding: 4px 10px; border-radius: 99px; }
@media(max-width:768px) {
    .note-row { flex-direction: column; align-items: flex-start; gap: 12px; }
    .nr-actions { width: 100%; justify-content: flex-end; }
}
</style>

<div class="hero-glass mb-24">
    <div class="hero-glass-left">
        <h1 class="align-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
            </svg> Manage Notes</h1>
        <p>All uploaded study materials organized by subject · <?= count($notes) ?> total notes</p>
    </div>
    <div class="hero-glass-actions" style="display:flex; gap:12px;">
        <a href="upload.php" class="btn btn-primary align-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg> Upload Notes</a>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom: 24px; border:none; box-shadow:none; background:transparent;">
    <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
        <select name="class" onchange="this.form.submit()"
            style="padding: 10px 14px; border: 1.5px solid var(--border); border-radius: 12px; font-size: 13px; font-weight:600; min-width: 140px;">
            <option value="">All Classes</option>
            <?php foreach ($classes as $c): ?>
            <option value="<?= $c ?>" <?=$selClass===$c?'selected':'' ?>><?= $c ?></option>
            <?php endforeach; ?>
        </select>
        <select name="subject" onchange="this.form.submit()"
            style="padding: 10px 14px; border: 1.5px solid var(--border); border-radius: 12px; font-size: 13px; font-weight:600; min-width: 160px;">
            <option value="">All Subjects</option>
            <?php foreach ($subjs as $s): ?>
            <option value="<?= htmlspecialchars($s) ?>" <?=$selSubject===$s?'selected':'' ?>><?= htmlspecialchars($s) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($selClass || $selSubject): ?>
        <a href="index.php" style="font-size:13px; font-weight:700; color:var(--text-light); text-decoration:none; margin-left:8px;">Reset Filters</a>
        <?php endif; ?>
    </form>
</div>

<?php if (empty($notes)): ?>
<div style="max-width: 600px; margin: 40px auto; padding:60px 20px; text-align:center; background:rgba(255,255,255,0.6); backdrop-filter:blur(10px); border-radius:20px; border:1px dashed var(--border); display: flex; flex-direction: column; align-items: center;">
    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:16px;">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
        <polyline points="14 2 14 8 20 8"></polyline>
        <line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line>
    </svg>
    <h2 style="font-weight:800; color:var(--text); margin-bottom:8px;">No Notes Found</h2>
    <p style="color:var(--text-light); font-size:15px; margin-bottom:24px;">Upload study materials for your students.</p>
    <a href="upload.php" class="btn btn-primary" style="padding:12px 32px; border-radius:12px;">Upload First Note</a>
</div>
<?php else: ?>

<?php foreach ($groupedNotes as $subject => $subjectNotes): ?>
<div class="subj-group-header">
    <div class="subj-group-icon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
    </div>
    <div class="subj-group-name"><?= htmlspecialchars($subject) ?></div>
    <div class="subj-group-count"><?= count($subjectNotes) ?> note<?= count($subjectNotes) > 1 ? 's' : '' ?></div>
</div>

<div class="notes-list-container" style="margin-bottom: 8px;">
    <?php foreach ($subjectNotes as $n):
        $noteLink = $n['google_drive_link'] ?? ($n['file_path'] ?? '#');
        $chapterName = $n['chapter_name'] ?? '';
        $topicName = $n['topic_name'] ?? '';
        $teacherName = $n['teacher_name'] ?? '';
        $uploadedRole = $n['uploaded_role'] ?? '';
        $className = $n['class_name'] ?? '';
    ?>
    <div class="note-row">
        <div class="nr-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
            </svg>
        </div>

        <div class="nr-content">
            <div class="nr-title" title="<?= sanitize($n['title'] ?? 'Untitled') ?>"><?= sanitize($n['title'] ?? 'Untitled') ?></div>
            <div class="nr-desc">
                <?php if ($chapterName): ?>
                    <?= sanitize($chapterName) ?>
                    <?php if ($topicName): ?> · <?= sanitize($topicName) ?><?php endif; ?>
                <?php elseif ($topicName): ?>
                    <?= sanitize($topicName) ?>
                <?php else: ?>
                    <?= sanitize($n['description'] ?? 'No description') ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="nr-meta">
            <?php if ($className): ?>
            <span class="nr-badge" style="background:#e0e7ff; color:#4338ca;"><?= sanitize($className) ?></span>
            <?php endif; ?>
            <?php if ($teacherName): ?>
            <span style="font-size:11px; font-weight:700; color:#64748b;"><?= sanitize($teacherName) ?></span>
            <?php endif; ?>
            <span style="font-size:11px; font-weight:600; color:#94a3b8;"><?= date('d M Y', strtotime($n['created_at'])) ?></span>
        </div>

        <div class="nr-actions">
            <?php if ($noteLink && $noteLink !== '#'): ?>
            <a href="<?= htmlspecialchars($noteLink) ?>" target="_blank"
                class="btn btn-primary btn-sm" title="Open Note">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                    <polyline points="15 3 21 3 21 9"></polyline>
                    <line x1="10" y1="14" x2="21" y2="3"></line>
                </svg> Open
            </a>
            <?php endif; ?>
            <form method="POST" style="margin:0" onsubmit="return confirm('Delete this note permanently?')">
                <?= csrfField() ?>
                <input type="hidden" name="delete_note" value="1">
                <input type="hidden" name="note_id" value="<?= $n['id'] ?>">
                <button type="submit" class="btn border-red text-red btn-sm" style="padding:6px; background:#fff" title="Delete">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="3 6 5 6 21 6"></polyline>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                    </svg>
                </button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
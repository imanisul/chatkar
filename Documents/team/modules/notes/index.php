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

// Fetch Notes
try {
    $sql = "SELECT n.*, c.chapter_name, u.name as teacher_name 
            FROM notes n 
            LEFT JOIN chapters c ON n.chapter_id = c.id 
            LEFT JOIN users u ON n.uploaded_by = u.id 
            WHERE 1=1";
    $params = [];

    if ($isTeacher) {
        $sql .= " AND n.uploaded_by = ?";
        $params[] = $user['id'];
    }

    if ($selClass) {
        $sql .= " AND n.class_name = ?";
        $params[] = $selClass;
    }

    if ($selSubject) {
        $sql .= " AND n.subject_id = ?";
        $params[] = $selSubject;
    }

    $sql .= " ORDER BY n.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $notes = $stmt->fetchAll();
} catch (Exception $e) {
    $notes = [];
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
    $subjs = $db->query("SELECT DISTINCT subject FROM syllabus ORDER BY subject")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $subjs = [];
}

$root = '../../';
require_once '../../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                stroke-linecap="round" stroke-linejoin="round"
                style="display:inline-block;vertical-align:text-bottom;margin-right:8px">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
                <polyline points="10 9 9 9 8 9"></polyline>
            </svg> Manage Notes</h1>
        <p>List of all uploaded study materials and notes</p>
    </div>
    <div class="page-header-actions">
        <a href="upload.php" class="btn btn-primary"><svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                style="margin-right:2px">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg> Upload New Notes</a>
    </div>
</div>

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
    <strong>Error processing notes:</strong> 
    <?= match($_GET['error']) {
        'db' => 'Database error. Details: ' . sanitize($_GET['details'] ?? 'Unknown DB Error'),
        'csrf' => 'Security validation failed.',
        default => 'An unknown error occurred.'
    } ?>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 14px;">
        <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <select name="class" onchange="this.form.submit()"
                style="padding: 9px 12px; border: 1.5px solid var(--border); border-radius: var(--r-sm); font-size: 13px; min-width: 140px;">
                <option value="">All Classes</option>
                <?php foreach ($classes as $c): ?>
                <option value="<?= $c ?>" <?=$selClass===$c?'selected':'' ?>>
                    <?= $c ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="subject" onchange="this.form.submit()"
                style="padding: 9px 12px; border: 1.5px solid var(--border); border-radius: var(--r-sm); font-size: 13px; min-width: 160px;">
                <option value="">All Subjects</option>
                <?php foreach ($subjs as $s): ?>
                <option value="<?= $s ?>" <?=$selSubject===$s?'selected':'' ?>>
                    <?= $s ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($selClass || $selSubject): ?>
            <a href="index.php" class="btn btn-secondary btn-sm"><svg width="14" height="14" viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                    style="display:inline-block;vertical-align:text-bottom">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg> Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if (empty($notes)): ?>
<div class="card">
    <div class="empty-state" style="padding: 60px;">
        <div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
                <polyline points="10 9 9 9 8 9"></polyline>
            </svg></div>
        <h3>No notes found</h3>
        <p>You haven't uploaded any study materials yet.</p>
        <a href="upload.php" class="btn btn-primary"><svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                style="margin-right:2px">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg> Upload Your First Note</a>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Note Title</th>
                    <th>Class / Subject</th>
                    <th>Chapter / Topic</th>
                    <th>Uploaded By</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($notes as $n): ?>
                <tr>
                    <td>
                        <div style="font-weight: 700;">
                            <?= sanitize($n['title']) ?>
                        </div>
                        <div style="font-size: 11px; color: var(--text-light); text-wrap: balance;">
                            <?= sanitize($n['description']) ?>
                        </div>
                    </td>
                    <td>
                        <span class="badge badge-gray">
                            <?= sanitize($n['class_name']) ?>
                        </span>
                        <div style="font-size: 12px; margin-top: 4px; font-weight: 600; color: var(--text-mid);">
                            <?= sanitize($n['subject_id']) ?>
                        </div>
                    </td>
                    <td>
                        <div style="font-size: 13px; font-weight: 600;">
                            <?= sanitize($n['chapter_name'] ?: 'No Chapter') ?>
                        </div>
                        <div style="font-size: 11px; color: var(--text-light);">
                            <?= sanitize($n['topic_name'] ?: '-') ?>
                        </div>
                    </td>
                    <td>
                        <div style="font-size: 12px; font-weight: 600;">
                            <?= sanitize($n['teacher_name']) ?>
                        </div>
                        <span class="badge badge-blue" style="font-size: 10px; padding: 1px 5px;">
                            <?= ucfirst($n['uploaded_role']) ?>
                        </span>
                    </td>
                    <td style="font-size: 12px; color: var(--text-mid);">
                        <?= date('d M Y', strtotime($n['created_at'])) ?>
                    </td>
                    <td>
                        <div style="display: flex; gap: 8px;">
                            <a href="<?= htmlspecialchars($n['google_drive_link']) ?>" target="_blank"
                                class="btn btn-secondary btn-sm" title="Open Link"><svg width="14" height="14"
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="2" y1="12" x2="22" y2="12"></line>
                                    <path
                                        d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z">
                                    </path>
                                </svg></a>
                            <form method="POST" onsubmit="return confirm('Delete this note permanently?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="delete_note" value="1">
                                <input type="hidden" name="note_id" value="<?= $n['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" title="Delete"><svg width="14"
                                        height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="3 6 5 6 21 6"></polyline>
                                        <path
                                            d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                        </path>
                                        <line x1="10" y1="11" x2="10" y2="17"></line>
                                        <line x1="14" y1="11" x2="14" y2="17"></line>
                                    </svg></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
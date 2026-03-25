<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
requireLogin();

$pageTitle = 'Upload Notes';
$db = getDB();
$user = currentUser();
$isStaff = in_array($user['role'], ['admin', 'teacher', 'mentor']);

if (!$isStaff) {
    die("Unauthorized access.");
}

$isTeacher = $user['role'] === 'teacher';

// Handle Note Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_note'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        redirect("index.php?error=csrf");
    }
    $class_id = $_POST['class_id'] ?? null; 
    $class_name = sanitize($_POST['class_name'] ?? '');
    $subject_id = sanitize($_POST['subject_id'] ?? '');
    $chapter_id = !empty($_POST['chapter_id']) ? (int)$_POST['chapter_id'] : null;
    $topic = sanitize($_POST['topic_name'] ?? '');
    $title = sanitize($_POST['title'] ?? '');
    $link = $_POST['google_drive_link'] ?? '';
    $desc = sanitize($_POST['description'] ?? '');

    if ($class_name && $subject_id && $title && $link) {
        try {
            // Fetch physical table columns to handle schema drift automatically
            $stmtCols = $db->query("DESCRIBE notes");
            $physicalCols = $stmtCols->fetchAll(PDO::FETCH_COLUMN);

            $dataMap = [
                'class_name' => $class_name,
                'subject_id' => $subject_id,
                'chapter_id' => $chapter_id,
                'topic_name' => $topic,
                'title' => $title,
                'description' => $desc,
                'google_drive_link' => $link,
                'uploaded_by' => $user['id'],
                'uploaded_role' => $user['role'],
                'status' => 'published'
            ];

            $fields = [];
            $placeholders = [];
            $values = [];

            foreach ($dataMap as $col => $val) {
                if (in_array($col, $physicalCols)) {
                    $fields[] = "`$col`";
                    $placeholders[] = "?";
                    $values[] = $val;
                }
            }

            $sql = "INSERT INTO notes (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $db->prepare($sql);
            $stmt->execute($values);

            logActivity($user['id'], "Uploaded notes: $title", 'notes');
        } catch (Exception $e) {
            error_log("Notes DB Insert Exception: " . $e->getMessage());
            $errMsg = urlencode($e->getMessage());
            redirect("index.php?error=db&details={$errMsg}");
            exit;
        }

        // Phase 7: Send Notes email notification
        try {
            require_once '../../includes/student_notifications.php';
            sendClassMaterialNotification(
                $db, 
                $class_name, 
                'Notes', 
                $title, 
                $subject_id, 
                $user['name'] ?? 'Teacher', 
                'https://team.heyyguru.in/student/notes.php', 
                true
            );
        } catch (Exception $e) {
            error_log("Notes Notification Exception: " . $e->getMessage());
        }

        redirect("index.php?msg=uploaded");
    }
}

// Fetch Classes
if ($isTeacher) {
    $tcStmt = $db->prepare("SELECT DISTINCT class FROM timetable WHERE teacher_id=? ORDER BY class");
    $tcStmt->execute([$user['id']]);
    $classes = $tcStmt->fetchAll(PDO::FETCH_COLUMN);
}
else {
    $classes = $db->query("SELECT DISTINCT class FROM students WHERE class IS NOT NULL AND class!='' ORDER BY class")->fetchAll(PDO::FETCH_COLUMN);
}

$root = '../../';
require_once '../../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-left">
        <h1><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:8px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg> Upload Notes</h1>
        <p>Map your Google Drive notes to Class, Subject, and Chapter</p>
    </div>
    <div class="page-header-actions">
        <a href="index.php" class="btn btn-secondary"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg> View All Notes</a>
    </div>
</div>

<div class="card" style="max-width: 800px; margin: 0 auto;">
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="upload_note" value="1">

            <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label>Select Class *</label>
                    <select name="class_name" id="classSelect" required onchange="loadChapters()">
                        <option value="">- Select Class -</option>
                        <?php foreach ($classes as $c): ?>
                        <option value="<?= htmlspecialchars($c)?>">
                            <?= htmlspecialchars($c)?>
                        </option>
                        <?php
endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Select Subject *</label>
                    <select name="subject_id" id="subjectSelect" required onchange="loadChapters()">
                        <option value="">- Select Subject -</option>
                        <?php
// Simplified subject list; in real system fetch from syllabus or timetable
$subjs = $db->query("SELECT DISTINCT subject FROM syllabus ORDER BY subject")->fetchAll(PDO::FETCH_COLUMN);
if (empty($subjs))
    $subjs = ['Maths', 'Science', 'English', 'Social Science', 'Hindi'];
foreach ($subjs as $s): ?>
                        <option value="<?= htmlspecialchars($s)?>">
                            <?= htmlspecialchars($s)?>
                        </option>
                        <?php
endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Select Chapter</label>
                    <select name="chapter_id" id="chapterSelect">
                        <option value="">- Select Chapter -</option>
                    </select>
                    <small style="color: var(--text-light)">Chapters are loaded based on Class & Subject</small>
                </div>

                <div class="form-group">
                    <label>Topic Name <small>(Optional)</small></label>
                    <input type="text" name="topic_name" placeholder="e.g. Linear Equations">
                </div>

                <div class="form-group" style="grid-column: 1 / -1;">
                    <label>Note Title *</label>
                    <input type="text" name="title" placeholder="e.g. Chapter 1 Detailed Notes" required>
                </div>

                <div class="form-group" style="grid-column: 1 / -1;">
                    <label>Google Drive Link *</label>
                    <input type="url" name="google_drive_link" placeholder="https://drive.google.com/..." required>
                    <small style="color: var(--text-light)">Ensure the link sharing is set to "Anyone with the
                        link"</small>
                </div>

                <div class="form-group" style="grid-column: 1 / -1;">
                    <label>Description <small>(Short summary)</small></label>
                    <textarea name="description" rows="3" placeholder="What is covered in these notes?"></textarea>
                </div>
            </div>

            <div style="margin-top: 30px; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="reset" class="btn btn-secondary">Reset</button>
                <button type="submit" class="btn btn-primary"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:4px"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg> Publish Notes</button>
            </div>
        </form>
    </div>
</div>

<script>
    function loadChapters() {
        const cls = document.getElementById('classSelect').value;
        const sbj = document.getElementById('subjectSelect').value;
        const chSelect = document.getElementById('chapterSelect');

        if (!cls || !sbj) {
            chSelect.innerHTML = '<option value="">- Select Chapter -</option>';
            return;
        }

        chSelect.innerHTML = '<option value=""><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> Loading chapters...</option>';

        fetch(`ajax_get_chapters.php?class=${encodeURIComponent(cls)}&subject=${encodeURIComponent(sbj)}`)
            .then(res => res.json())
            .then(data => {
                chSelect.innerHTML = '<option value="">- Select Chapter -</option>';
                if (data.length === 0) {
                    chSelect.innerHTML += '<option value="0">No chapters found</option>';
                } else {
                    data.forEach(ch => {
                        const opt = document.createElement('option');
                        opt.value = ch.id;
                        opt.textContent = ch.chapter_name;
                        chSelect.appendChild(opt);
                    });
                }
            })
            .catch(err => {
                console.error(err);
                chSelect.innerHTML = '<option value=""><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg> Error loading</option>';
            });
    }
</script>

<?php require_once '../../includes/footer.php'; ?>
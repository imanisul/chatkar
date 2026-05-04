<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/email.php';
requireRole(['admin']);

$pageTitle = 'Edit Batch';
$db = getDB();
$user = currentUser();
$id = (int)($_GET['id'] ?? 0);

$batch = $db->prepare("SELECT * FROM batches WHERE id=?");
$batch->execute([$id]);
$batch = $batch->fetch();
if (!$batch)
    redirect('index.php');

$errors = [];

// Get subjects from syllabus
$subjects = [];
try {
    $subjects = $db->query("SELECT DISTINCT subject FROM syllabus WHERE subject IS NOT NULL AND subject!='' ORDER BY subject")->fetchAll(PDO::FETCH_COLUMN);
}
catch (Exception $e) {
}
if (empty($subjects))
    $subjects = ['English Grammar', 'EVS', 'Hindi', 'Maths', 'Science', 'Social Science', 'Computer', 'Sanskrit'];

// Get teachers
$teachers = $db->query("SELECT u.id, u.name, t.subject FROM users u LEFT JOIN teachers t ON t.user_id=u.id WHERE u.role='teacher' AND u.status='active' ORDER BY u.name")->fetchAll();

// Current assignments
$currentSubjects = $db->prepare("SELECT subject FROM batch_subjects WHERE batch_id=?");
$currentSubjects->execute([$id]);
$currentSubjects = $currentSubjects->fetchAll(PDO::FETCH_COLUMN);

$currentTeachers = $db->prepare("SELECT teacher_id FROM batch_teachers WHERE batch_id=?");
$currentTeachers->execute([$id]);
$currentTeachers = $currentTeachers->fetchAll(PDO::FETCH_COLUMN);

// Fetch batch_teachers with subject mapping (used for notification check in POST handler and JS rendering)
$currentBatchTeachers = $db->prepare("SELECT teacher_id, subject FROM batch_teachers WHERE batch_id=?");
$currentBatchTeachers->execute([$id]);
$currentBatchTeachers = $currentBatchTeachers->fetchAll(PDO::FETCH_ASSOC);

$currentStudents = $db->prepare("SELECT student_id FROM batch_students WHERE batch_id=?");
$currentStudents->execute([$id]);
$currentStudents = $currentStudents->fetchAll(PDO::FETCH_COLUMN);

// Students list
$filterClass = $_GET['class'] ?? $batch['class'] ?? '';
$studentStmt = $filterClass
    ? $db->prepare("SELECT * FROM students WHERE class=? ORDER BY name")
    : $db->prepare("SELECT * FROM students ORDER BY class, name");
if ($filterClass)
    $studentStmt->execute([$filterClass]);
else
    $studentStmt->execute();
$students = $studentStmt->fetchAll();

$classes = [];
try {
    $classes = $db->query("SELECT DISTINCT class FROM students WHERE class IS NOT NULL AND class!='' ORDER BY class")->fetchAll(PDO::FETCH_COLUMN);
}
catch (Exception $e) {
}
if (empty($classes))
    $classes = ['Class 1', 'Class 2', 'Class 3', 'Class 4', 'Class 5', 'Class 6', 'Class 7', 'Class 8', 'Class 9', 'Class 10', 'Class 11', 'Class 12'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_batch'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        redirect("edit.php?id=$id&err=csrf");
    }
    $name = sanitize($_POST['name'] ?? '');
    $class = sanitize($_POST['class'] ?? '');

    // Format timing
    $days_post = $_POST['days'] ?? [];
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $timing_str = '';
    if (!empty($days_post) && $start_time && $end_time) {
        $st_f = date('h:i A', strtotime($start_time));
        $et_f = date('h:i A', strtotime($end_time));
        $timing_str = implode(',', $days_post) . " | $st_f - $et_f";
    }
    $timing = sanitize($timing_str);

    $startDate = $_POST['start_date'] ?? null;
    if (!$startDate)
        $startDate = null;
    $mode = $_POST['mode'] ?? 'Live';
    $desc = sanitize($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $selStudents = $_POST['student_ids'] ?? [];
    $selSubjects = $_POST['subjects'] ?? [];

    // subject_teachers is an array: ['Maths' => [id1, id2], 'Science' => [id3]]
    $subjTeachers = $_POST['subject_teachers'] ?? [];

    if (!$name)
        $errors[] = 'Batch name is required.';

    if (!$errors) {
        $db->prepare("UPDATE batches SET name=?,class=?,timing=?,start_date=?,mode=?,description=?,status=? WHERE id=?")
            ->execute([$name, $class, $timing, $startDate, $mode, $desc, $status, $id]);

        // Refresh subjects
        $db->prepare("DELETE FROM batch_subjects WHERE batch_id=?")->execute([$id]);

        // Refresh teachers
        $db->prepare("DELETE FROM batch_teachers WHERE batch_id=?")->execute([$id]);

        foreach ($selSubjects as $s) {
            $subj = trim($s);
            if (!$subj)
                continue;
            try {
                $db->prepare("INSERT IGNORE INTO batch_subjects (batch_id,subject) VALUES (?,?)")->execute([$id, $subj]);
            }
            catch (Exception $e) {
            }

            if (isset($subjTeachers[$subj]) && is_array($subjTeachers[$subj])) {
                foreach ($subjTeachers[$subj] as $tid) {
                    try {
                        $db->prepare("INSERT IGNORE INTO batch_teachers (batch_id,teacher_id,subject) VALUES (?,?,?)")
                            ->execute([$id, (int)$tid, $subj]);

                        // Notify Teacher if newly assigned
                        $isNew = true;
                        foreach ($currentBatchTeachers as $cbt) {
                            if ($cbt['teacher_id'] == $tid && $cbt['subject'] == $subj) {
                                $isNew = false;
                                break;
                            }
                        }

                        if ($isNew) {
                            $tData = $db->prepare("SELECT name, email FROM users WHERE id=?");
                            $tData->execute([(int)$tid]);
                            $teacher = $tData->fetch();
                            if ($teacher && $teacher['email']) {
                                sendClassAllocationAlert($teacher['email'], $teacher['name'], $name, $subj);
                            }
                        }
                    }
                    catch (Exception $e) {
                    }
                }
            }
        }

        // Refresh students - remove old ones first
        $oldStudents = $db->prepare("SELECT student_id FROM batch_students WHERE batch_id=?");
        $oldStudents->execute([$id]);
        $oldStudentIds = $oldStudents->fetchAll(PDO::FETCH_COLUMN);
        foreach ($oldStudentIds as $sid) {
            if (!in_array($sid, array_map('intval', $selStudents))) {
                try {
                    $db->prepare("UPDATE students SET batch_id=NULL WHERE id=? AND batch_id=?")->execute([$sid, $id]);
                }
                catch (Exception $e) {
                }
            }
        }
        $db->prepare("DELETE FROM batch_students WHERE batch_id=?")->execute([$id]);
        foreach ($selStudents as $sid) {
            try {
                $db->prepare("INSERT IGNORE INTO batch_students (batch_id,student_id) VALUES (?,?)")->execute([$id, (int)$sid]);
            }
            catch (Exception $e) {
            }
            try {
                $db->prepare("UPDATE students SET batch_id=? WHERE id=?")->execute([$id, (int)$sid]);
            }
            catch (Exception $e) {
            }
        }

        logActivity($user['id'], "Updated batch: $name", 'batches');
        redirect('index.php?msg=updated');
    }

    // After POST, use posted values
    $currentSubjects = $selSubjects;
    $currentStudents = array_map('intval', $selStudents);
}

$root = '../../';
require_once '../../includes/header.php';
?>

<?php foreach ($errors as $e): ?>
<div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
    <?= $e?>
</div>
<?php
endforeach; ?>

<div class="breadcrumb">
    <a href="index.php"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg> Batches</a><span class="sep">/</span>
    <span>Edit -
        <?= sanitize($batch['name'])?>
    </span>
</div>

<form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="save_batch" value="1">
    <div style="display:grid;grid-template-columns:1fr 380px;gap:20px;align-items:start">
        <div>
            <div class="card" style="margin-bottom:18px">
                <div class="card-header">
                    <div class="card-title"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg> Batch Details</div><a href="index.php" class="btn btn-secondary btn-sm"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> Back</a>
                </div>
                <div class="card-body">
                    <div class="form-grid">
                        <div class="form-group" style="grid-column:1/-1">
                            <label>Batch Name *</label>
                            <input type="text" name="name"
                                value="<?= htmlspecialchars($_POST['name'] ?? $batch['name'])?>" required>
                        </div>
                        <div class="form-group">
                            <label>Class</label>
                            <select name="class" id="classSelect">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $c): ?>
                                <option value="<?= htmlspecialchars($c)?>" <?=($filterClass===$c) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c)?>
                                </option>
                                <?php
endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label>Schedule (Days & Time)</label>
                            <?php
// Parse existing timing: "Mon,Wed | 09:00 AM - 11:00 AM"
$exTiming = $_POST['timing'] ?? $batch['timing'] ?? '';
$selDays = [];
$st = '09:00';
$et = '11:00';
if (strpos($exTiming, '|') !== false) {
    [$dp, $tp] = explode('|', $exTiming);
    $selDays = array_map('trim', explode(',', $dp));
    if (strpos($tp, '-') !== false) {
        [$st_str, $et_str] = explode('-', $tp);
        try {
            $st = date('H:i', strtotime(trim($st_str)));
        }
        catch (Exception $e) {
        }
        try {
            $et = date('H:i', strtotime(trim($et_str)));
        }
        catch (Exception $e) {
        }
    }
}
?>
                            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px">
                                <?php $ds = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
foreach ($ds as $d): ?>
                                <label
                                    style="display:flex;align-items:center;gap:6px;padding:6px 12px;border:1.5px solid var(--border);border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;background:#fff">
                                    <input type="checkbox" name="days[]" value="<?= $d?>" <?=in_array($d, $selDays)
                                        ? 'checked' : '' ?> style="accent-color:var(--blue)">
                                    <?= $d?>
                                </label>
                                <?php
endforeach; ?>
                            </div>
                            <div style="display:flex;gap:12px;align-items:center">
                                <input type="time" name="start_time" required
                                    style="width:140px;padding:8px;border:1.5px solid var(--border);border-radius:6px;outline:none"
                                    value="<?= $st?>">
                                <span style="font-weight:700;color:var(--text-light)">to</span>
                                <input type="time" name="end_time" required
                                    style="width:140px;padding:8px;border:1.5px solid var(--border);border-radius:6px;outline:none"
                                    value="<?= $et?>">
                            </div>
                            <!-- Hidden input for backwards compatibility/fallback -->
                            <input type="hidden" name="timing" value="<?= htmlspecialchars($exTiming)?>">
                        </div>
                        <div class="form-group">
                            <label>Batch Start Date</label>
                            <input type="date" name="start_date"
                                style="width:100%;padding:8px 12px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-size:13px;outline:none"
                                value="<?= htmlspecialchars($_POST['start_date'] ?? $batch['start_date'] ?? '')?>">
                        </div>
                        <div class="form-group">
                            <label>Batch Mode</label>
                            <select name="mode">
                                <option value="Live" <?=($batch['mode']==='Live' ) ? 'selected' : '' ?>>Live</option>
                                <option value="Recorded + Live" <?=($batch['mode']==='Recorded + Live' ) ? 'selected'
                                    : '' ?>>Recorded + Live</option>
                            </select>
                        </div>
                        <div class="form-group" style="grid-column:1/-1">
                            <label>Description</label>
                            <textarea name="description"
                                rows="2"><?= htmlspecialchars($_POST['description'] ?? $batch['description'] ?? '')?></textarea>
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="active" <?=($batch['status']==='active' ) ? 'selected' : '' ?>><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg> Active
                                </option>
                                <option value="inactive" <?=($batch['status']==='inactive' ) ? 'selected' : '' ?>><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><circle cx="12" cy="12" r="10"></circle><line x1="10" y1="15" x2="10" y2="9"></line><line x1="14" y1="15" x2="14" y2="9"></line></svg> Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Subjects & Chapters (Dynamic) -->
            <div class="card" style="margin-bottom:18px">
                <div class="card-header">
                    <div class="card-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg> Assign Subjects & Syllabus</div>
                </div>
                <div class="card-body">
                    <div id="subjectPills" style="display:flex;flex-wrap:wrap;gap:9px;margin-bottom:15px">
                        <div class="empty-state"
                            style="padding:10px;font-size:13px;color:var(--text-light);width:100%;text-align:center">
                            Loading subjects...</div>
                    </div>

                    <div id="syllabusPreview"
                        style="display:none;background:var(--bg);border-radius:var(--r-sm);padding:12px;border:1px solid var(--border)">
                        <div
                            style="font-size:12px;font-weight:800;color:var(--text-mid);margin-bottom:8px;text-transform:uppercase;letter-spacing:0.5px">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg> Syllabus Preview for Selected Subjects</div>
                        <div id="chaptersList" style="display:flex;flex-direction:column;gap:6px"></div>
                    </div>
                </div>
            </div>

            <!-- Assign Teachers (Dynamic per subject) -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg> Assign Teachers</div>
                </div>
                <div class="card-body">
                    <div id="teacherAssignments">
                        <div class="empty-state"
                            style="padding:10px;font-size:13px;color:var(--text-light);width:100%;text-align:center">
                            Loading teachers...</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Students right column -->
        <div class="card" style="position:sticky;top:20px">
            <div class="card-header">
                <div class="card-title"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg> Students</div><span class="badge badge-blue" id="selectedCount">0
                    selected</span>
            </div>
            <div style="padding:10px 14px;border-bottom:1px solid var(--border-light)">
                <input type="text" placeholder="Search students..."
                    style="width:100%;padding:8px 12px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-size:13px;outline:none"
                    oninput="filterStudents(this.value)">
                <div style="display:flex;gap:8px;margin-top:8px">
                    <button type="button" class="btn btn-success btn-sm" style="flex:1" onclick="selectAllStudents()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        All</button>
                    <button type="button" class="btn btn-danger btn-sm" style="flex:1" onclick="clearAllStudents()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        None</button>
                </div>
            </div>
            <div style="max-height:460px;overflow-y:auto;padding:8px" id="studentList">
                <?php foreach ($students as $s):
    $checked = in_array($s['id'], array_map('intval', $currentStudents));
?>
                <label class="student-row"
                    style="display:flex;align-items:center;gap:9px;padding:9px 10px;border-radius:var(--r-sm);cursor:pointer;border:1.5px solid <?= $checked ? 'var(--blue-mid)' : 'transparent'?>;background:<?= $checked ? 'var(--blue-light)' : 'transparent'?>;transition:all .12s;margin-bottom:4px">
                    <input type="checkbox" name="student_ids[]" value="<?= $s['id']?>" <?=$checked ? 'checked' : '' ?>
                    style="display:none">
                    <div
                        style="width:32px;height:32px;border-radius:50%;background:var(--blue-light);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:var(--blue-deep);border:2px solid var(--blue-mid);flex-shrink:0">
                        <?= strtoupper(substr($s['name'], 0, 1))?>
                    </div>
                    <div style="flex:1;min-width:0">
                        <div
                            style="font-weight:700;font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                            <?= sanitize($s['name'])?>
                        </div>
                        <div style="font-size:11px;color:var(--text-light)">
                            <?= sanitize($s['class'] ?? '-')?>
                        </div>
                    </div>
                    <span class="check-icon">
                        <?= $checked ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ''?>
                    </span>
                </label>
                <?php
endforeach; ?>
            </div>
            <div style="padding:14px 16px;border-top:1px solid var(--border-light);display:flex;gap:8px">
                <a href="index.php" class="btn btn-secondary" style="flex:1;text-align:center">Cancel</a>
                <button type="submit" class="btn btn-primary" style="flex:2"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg> Save Changes</button>
                    <script>
                        // Data fetched from AJAX
                        let classData = { subjects: [], chapters: {}, students: [] };
                        const allTeachers = <?= json_encode($teachers) ?>;

                        // Existing assignments from DB or POST
                        const currentSubjects = <?= json_encode($currentSubjects) ?>;
// We need current batch_teachers mapping to pre-fill checkboxes
// ($currentBatchTeachers is fetched in PHP at the top of the file)
const currentBatchTeachers = <?= json_encode($currentBatchTeachers) ?>;
                        const selStudentIds = <?= json_encode($currentStudents) ?>.map(String);

                        const classSelect = document.getElementById('classSelect');

                        // Server-side data for initial render (avoids AJAX delay on page load)
                        const serverSubjects = <?= json_encode($subjects) ?>;

                        classSelect.addEventListener('change', async (e) => {
                            const cls = e.target.value;
                            if (!cls) {
                                document.getElementById('subjectPills').innerHTML = '<div class="empty-state" style="padding:10px;font-size:13px;color:var(--text-light);width:100%;text-align:center">Please select a Class above to load Subjects.</div>';
                                document.getElementById('teacherAssignments').innerHTML = '<div class="empty-state" style="padding:10px;font-size:13px;color:var(--text-light);width:100%;text-align:center">Select subjects to assign teachers to them.</div>';
                                document.getElementById('syllabusPreview').style.display = 'none';
                                return;
                            }

                            document.getElementById('subjectPills').innerHTML = '<div style="padding:10px;font-size:13px;color:var(--blue)">Loading subjects...</div>';
                            try {
                                const res = await fetch(`ajax_get_class_data.php?class=${encodeURIComponent(cls)}`);
                                classData = await res.json();
                            } catch (e) { console.error('Error fetching class data'); return; }

                            // 1. Render Subject Pills
                            const subjDiv = document.getElementById('subjectPills');
                            subjDiv.innerHTML = '';

                            // Merge DB subjects + currentSubjects (in case some were custom/deleted from syllabus but still linked)
                            let allSubjs = [...classData.subjects];
                            currentSubjects.forEach(s => { if (!allSubjs.includes(s)) allSubjs.push(s); });
                            allSubjs.sort();

                            if (allSubjs.length === 0) {
                                subjDiv.innerHTML = '<div class="empty-state" style="padding:10px;font-size:13px;color:var(--text-light);width:100%;text-align:center">No subjects found in syllabus.</div>';
                            } else {
                                allSubjs.forEach(s => {
                                    const isChecked = currentSubjects.includes(s);
                                    const lbl = document.createElement('label');
                                    lbl.className = 'subj-pill';
                                    lbl.style.cssText = `display:flex;align-items:center;gap:8px;padding:7px 14px;border:1.5px solid ${isChecked ? 'var(--blue)' : 'var(--border)'};border-radius:99px;cursor:pointer;background:${isChecked ? 'var(--blue-light)' : '#fff'};font-size:13px;font-weight:600;transition:all .15s`;
                                    lbl.innerHTML = `<input type="checkbox" name="subjects[]" value="${s}" ${isChecked ? 'checked' : ''} style="display:none">
                <span>${s}</span>
                <span class="subj-check" style="width:18px;height:18px;border-radius:50%;background:${isChecked ? 'var(--blue)' : 'var(--bg)'};color:#fff;display:flex;align-items:center;justify-content:center;font-size:10px;margin-left:4px;transition:all .15s">${isChecked ? '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ''}</span>`;
                                    subjDiv.appendChild(lbl);
                                });
                            }

                            // 2. Render Students
                            const stuDiv = document.getElementById('studentList');
                            stuDiv.innerHTML = '';
                            if (classData.students.length === 0) {
                                stuDiv.innerHTML = '<div class="empty-state" style="padding:20px"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg></div><p>No students found for this class.</p></div>';
                            } else {
                                classData.students.forEach(s => {
                                    const isChecked = selStudentIds.includes(String(s.id));
                                    const div = document.createElement('div');
                                    div.innerHTML = `<label class="student-row" style="display:flex;align-items:center;gap:9px;padding:9px 10px;border-radius:var(--r-sm);cursor:pointer;border:1.5px solid ${isChecked ? 'var(--blue-mid)' : 'transparent'};background:${isChecked ? 'var(--blue-light)' : 'transparent'};transition:all .12s;margin-bottom:4px">
                <input type="checkbox" name="student_ids[]" value="${s.id}" ${isChecked ? 'checked' : ''} style="display:none">
                <div style="width:32px;height:32px;border-radius:50%;background:var(--blue-light);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:var(--blue-deep);border:2px solid var(--blue-mid);flex-shrink:0">${s.name.substring(0, 1).toUpperCase()}</div>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:700;font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${s.name}</div>
                    <div style="font-size:11px;color:var(--text-light)">${s.class || ''}</div>
                </div>
                <span class="check-icon" style="font-size:14px">${isChecked ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ''}</span>
            </label>`;
                                    const r = div.firstElementChild;
                                    stuDiv.appendChild(r);
                                });
                            }
                            updateCount();
                            updateDynamicAreas();
                        });

                        function updateDynamicAreas() {
                            // --- 1. Save current UI states (manual changes) before clearing ---
                            const uiAssignments = {};
                            document.querySelectorAll('input[name^="subject_teachers["]').forEach(cb => {
                                const match = cb.name.match(/subject_teachers\[(.*?)\]/);
                                if (match && cb.checked) {
                                    const s = match[1];
                                    if (!uiAssignments[s]) uiAssignments[s] = [];
                                    uiAssignments[s].push(cb.value);
                                }
                            });

                            const sel = Array.from(document.querySelectorAll('input[name="subjects[]"]:checked')).map(cb => cb.value);

                            // Update Syllabus
                            const pbox = document.getElementById('syllabusPreview');
                            const cl = document.getElementById('chaptersList');
                            cl.innerHTML = '';
                            if (sel.length === 0) {
                                pbox.style.display = 'none';
                            } else {
                                pbox.style.display = 'block';
                                sel.forEach(s => {
                                    const chaps = classData.chapters[s] || [];
                                    cl.innerHTML += `<div style="font-size:12.5px;padding:6px 0;border-bottom:1px solid rgba(0,0,0,.05)">
                <span style="font-weight:800;color:var(--blue)">${s}</span> 
                <span style="color:var(--text-light)">- ${chaps.length} Chapters</span>
            </div>`;
                                });
                            }

                            // Update Teachers
                            const tbox = document.getElementById('teacherAssignments');
                            tbox.innerHTML = '';
                            if (sel.length === 0) {
                                tbox.innerHTML = '<div class="empty-state" style="padding:10px;font-size:13px;color:var(--text-light);width:100%;text-align:center">Select subjects to assign teachers.</div>';
                            } else {
                                sel.forEach(s => {
                                    const block = document.createElement('div');
                                    block.style.cssText = 'margin-bottom:14px;border:1.5px solid var(--border);border-radius:var(--r-sm);overflow:hidden';

                                    const header = document.createElement('div');
                                    header.style.cssText = 'background:var(--bg);padding:8px 12px;font-weight:800;font-size:13px;border-bottom:1px solid var(--border);color:var(--text)';
                                    header.textContent = `Assign teacher(s) for ${s}`;
                                    block.appendChild(header);

                                    const tBody = document.createElement('div');
                                    tBody.style.cssText = 'padding:10px 12px;display:flex;flex-wrap:wrap;gap:8px';

                                    allTeachers.forEach(t => {
                                        const tSubjs = (t.subject || '').toLowerCase().split(',').map(x => x.trim());
                                        const isMatch = tSubjs.includes(s.toLowerCase());

                                        // Priority: 1. Current UI state, 2. DB state (initial load)
                                        let isChecked = false;
                                        if (uiAssignments[s]) {
                                            isChecked = uiAssignments[s].includes(String(t.id));
                                        } else {
                                            isChecked = currentBatchTeachers.some(bt => bt.teacher_id == t.id && bt.subject === s);
                                        }

                                        const lbl = document.createElement('label');
                                        lbl.style.cssText = `display:flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid var(--border);border-radius:6px;cursor:pointer;font-size:12.5px;font-weight:600;background:#fff;transition:all .15s`;
                                        if (isMatch) lbl.style.borderColor = 'var(--blue-mid)';

                                        lbl.innerHTML = `<input type="checkbox" name="subject_teachers[${s}][]" value="${t.id}" ${isChecked ? 'checked' : ''} style="accent-color:var(--green)">
                    <div style="width:20px;height:20px;border-radius:50%;background:var(--amber-light);display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800;color:var(--amber)">${t.name.substring(0, 1).toUpperCase()}</div>
                    <span>${t.name} ${isMatch ? '<span style="color:var(--blue)"><svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg></span>' : ''}</span>`;
                                        tBody.appendChild(lbl);
                                    });

                                    block.appendChild(tBody);
                                    tbox.appendChild(block);
                                });
                            }
                        }

                        function updateCount() { const n = document.querySelectorAll('.student-row input:checked').length; document.getElementById('selectedCount').textContent = n + ' selected'; }
                        function selectAllStudents() { document.querySelectorAll('.student-row input').forEach(cb => { if (!cb.checked) { cb.checked = true; cb.dispatchEvent(new Event('change', { bubbles: true })); } }); }
                        function clearAllStudents() { document.querySelectorAll('.student-row input').forEach(cb => { if (cb.checked) { cb.checked = false; cb.dispatchEvent(new Event('change', { bubbles: true })); } }); }
                        function filterStudents(q) { q = q.toLowerCase(); document.querySelectorAll('.student-row').forEach(r => { r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none'; }); }

                        document.addEventListener('change', (e) => {
                            if (e.target.matches('input[name="subjects[]"]')) {
                                const lbl = e.target.closest('.subj-pill');
                                if (lbl) {
                                    lbl.style.borderColor = e.target.checked ? 'var(--blue)' : 'var(--border)';
                                    lbl.style.background = e.target.checked ? 'var(--blue-light)' : '#fff';
                                    const cv = lbl.querySelector('.subj-check');
                                    if (cv) {
                                        cv.style.background = e.target.checked ? 'var(--blue)' : 'var(--bg)';
                                        cv.innerHTML = e.target.checked ? '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>' : '';
                                    }
                                }
                                updateDynamicAreas();
                            }
                            if (e.target.matches('input[name="student_ids[]"]')) {
                                const lbl = e.target.closest('.student-row');
                                if (lbl) {
                                    lbl.style.borderColor = e.target.checked ? 'var(--blue)' : 'transparent';
                                    lbl.style.background = e.target.checked ? '#e0f2fe' : 'transparent';
                                    lbl.style.boxShadow = e.target.checked ? '0 0 0 1px var(--blue)' : 'none';
                                    const icon = lbl.querySelector('.check-icon');
                                    if (icon) icon.innerHTML = e.target.checked ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg>' : '';
                                }
                                updateCount();
                            }
                        });

                        // Initialize on page load — render subjects & teachers immediately from PHP data
                        // (no AJAX needed for the initial load)
                        function initializeFromServerData() {
                            // Build classData from server-side PHP values for instant rendering
                            let allSubjs = [...serverSubjects];
                            currentSubjects.forEach(s => { if (!allSubjs.includes(s)) allSubjs.push(s); });
                            allSubjs.sort();

                            // Render Subject Pills immediately
                            const subjDiv = document.getElementById('subjectPills');
                            subjDiv.innerHTML = '';
                            if (allSubjs.length === 0) {
                                subjDiv.innerHTML = '<div class="empty-state" style="padding:10px;font-size:13px;color:var(--text-light);width:100%;text-align:center">No subjects found.</div>';
                            } else {
                                allSubjs.forEach(s => {
                                    const isChecked = currentSubjects.includes(s);
                                    const lbl = document.createElement('label');
                                    lbl.className = 'subj-pill';
                                    lbl.style.cssText = `display:flex;align-items:center;gap:8px;padding:7px 14px;border:1.5px solid ${isChecked ? 'var(--blue)' : 'var(--border)'};border-radius:99px;cursor:pointer;background:${isChecked ? 'var(--blue-light)' : '#fff'};font-size:13px;font-weight:600;transition:all .15s`;
                                    lbl.innerHTML = `<input type="checkbox" name="subjects[]" value="${s}" ${isChecked ? 'checked' : ''} style="display:none">
                <span>${s}</span>
                <span class="subj-check" style="width:18px;height:18px;border-radius:50%;background:${isChecked ? 'var(--blue)' : 'var(--bg)'};color:#fff;display:flex;align-items:center;justify-content:center;font-size:10px;margin-left:4px;transition:all .15s">${isChecked ? '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ''}</span>`;
                                    subjDiv.appendChild(lbl);
                                });
                            }

                            // Render teacher assignments immediately
                            updateDynamicAreas();
                            updateCount();
                        }

                        // Run immediately — no need to wait for AJAX
                        initializeFromServerData();

                        // Also fire AJAX when class is changed to refresh data
                        if (classSelect.value) {
                            // Silently fetch class data in background to populate chapters for syllabus preview
                            fetch(`ajax_get_class_data.php?class=${encodeURIComponent(classSelect.value)}`)
                                .then(r => r.json())
                                .then(data => { classData = data; })
                                .catch(() => {});
                        }
                    </script>
                    <?php require_once '../../includes/footer.php'; ?>
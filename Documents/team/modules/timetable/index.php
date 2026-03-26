<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
requireLogin();

$pageTitle = 'Timetable';
$db   = getDB();
$user = currentUser();
$canManage = in_array($user['role'], ['admin','mentor']);
$isTeacher = $user['role'] === 'teacher';
$errors = [];

if ($canManage && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        error_log("CSRF mismatch in timetable delete");
        redirect("index.php?error=csrf");
    } else {
        $slotId = (int)$_POST['delete_id'];
        try {
            $old = $db->prepare("SELECT * FROM timetable WHERE id=?"); $old->execute([$slotId]);
            $oldData = $old->fetch();
            $db->prepare("DELETE FROM timetable WHERE id=?")->execute([$slotId]);
            if ($oldData && $oldData['teacher_id']) {
                try { $db->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,?)")->execute([$oldData['teacher_id'], 'Timetable Updated', "Your {$oldData['subject']} class on {$oldData['day']} has been removed.", 'timetable']); } catch(Exception $e) {}
            }
            redirect('index.php?msg=deleted');
        } catch(Exception $e) {
            $errors[] = "Error deleting slot: " . $e->getMessage();
        }
    }
}

if ($canManage && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_slot'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        error_log("CSRF mismatch in timetable add");
        redirect("index.php?error=csrf");
    } else {
        $class=$_POST['class']??''; $subject=$_POST['subject']??''; $teacherId=(int)($_POST['teacher_id']??0);
        $day=$_POST['day']??''; $startTime=$_POST['start_time']??''; $endTime=$_POST['end_time']??''; $room=sanitize($_POST['room']??'');
        $class=sanitize($class); $subject=sanitize($subject); $day=sanitize($day);
        if (!$class) $errors[]='Class required.'; if (!$subject) $errors[]='Subject required.';
        if (!$day) $errors[]='Day required.'; if (!$startTime||!$endTime) $errors[]='Times required.';
        
        // Check for scheduling conflicts
        if ($teacherId && empty($errors)) {
            try {
                // Backticked `class` to prevent any keyword conflicts and match standard SQL
                $overlapStmt = $db->prepare("SELECT `class`, subject, start_time, end_time FROM timetable WHERE teacher_id=? AND day=? AND start_time < ? AND end_time > ?");
                $overlapStmt->execute([$teacherId, $day, $endTime, $startTime]);
                if ($overlap = $overlapStmt->fetch()) {
                    $tStmt = $db->prepare("SELECT name FROM users WHERE id=?");
                    $tStmt->execute([$teacherId]);
                    $tName = $tStmt->fetchColumn() ?: 'The selected teacher';
                    $oStart = date('h:i A', strtotime($overlap['start_time']));
                    $oEnd = date('h:i A', strtotime($overlap['end_time']));
                    $errors[] = "Conflict: {$tName} is already teaching {$overlap['subject']} to {$overlap['class']} from {$oStart} to {$oEnd} on {$day}.";
                }
            } catch (Exception $e) { $errors[] = "Error checking conflicts: " . $e->getMessage(); }
        }

        if (empty($errors)) {
            try {
                $timeSlot=date('h:i A',strtotime($startTime)).' - '.date('h:i A',strtotime($endTime));
                $db->prepare("INSERT INTO timetable (`class`,subject,teacher_id,day,start_time,end_time,time_slot,room) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$class,$subject,$teacherId?:null,$day,$startTime,$endTime,$timeSlot,$room]);
                $newId=$db->lastInsertId();
                logActivity($user['id'],"Added timetable: $subject for $class on $day",'timetable');
                if ($teacherId) { try { $db->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,?)")->execute([$teacherId,'New Class Assigned',"You have been assigned $subject for $class on $day ($timeSlot).",'timetable']); } catch(Exception $e) {} }
                redirect('index.php?msg=added');
            } catch(Exception $e) {
                $errors[] = "Database Error: " . $e->getMessage();
            }
        }
    }
}

if ($canManage && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_slot'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        error_log("CSRF mismatch in timetable edit");
        redirect("index.php?error=csrf");
    } else {
        $id=(int)$_POST['slot_id']; $class=sanitize($_POST['class']??''); $subject=sanitize($_POST['subject']??'');
        $teacherId=(int)($_POST['teacher_id']??0); $day=sanitize($_POST['day']??'');
        $startTime=$_POST['start_time']??''; $endTime=$_POST['end_time']??''; $room=sanitize($_POST['room']??'');
        
        // Check for scheduling conflicts
        if ($teacherId && $id) {
            try {
                // Backticked `class` to prevent any keyword conflicts and match standard SQL
                $overlapStmt = $db->prepare("SELECT `class`, subject, start_time, end_time FROM timetable WHERE teacher_id=? AND day=? AND start_time < ? AND end_time > ? AND id != ?");
                $overlapStmt->execute([$teacherId, $day, $endTime, $startTime, $id]);
                if ($overlap = $overlapStmt->fetch()) {
                    $tStmt = $db->prepare("SELECT name FROM users WHERE id=?");
                    $tStmt->execute([$teacherId]);
                    $tName = $tStmt->fetchColumn() ?: 'The selected teacher';
                    $oStart = date('h:i A', strtotime($overlap['start_time']));
                    $oEnd = date('h:i A', strtotime($overlap['end_time']));
                    $errors[] = "Conflict: {$tName} is already teaching {$overlap['subject']} to {$overlap['class']} from {$oStart} to {$oEnd} on {$day}.";
                }
            } catch (Exception $e) { $errors[] = "Error checking conflicts: " . $e->getMessage(); }
        }

        if ($id && $class && $subject && $day && $startTime && $endTime && empty($errors)) {
            try {
                $timeSlot=date('h:i A',strtotime($startTime)).' - '.date('h:i A',strtotime($endTime));
                $db->prepare("UPDATE timetable SET `class`=?,subject=?,teacher_id=?,day=?,start_time=?,end_time=?,time_slot=?,room=? WHERE id=?")
                   ->execute([$class,$subject,$teacherId?:null,$day,$startTime,$endTime,$timeSlot,$room,$id]);
                if ($teacherId) { try { $db->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,?)")->execute([$teacherId,'Timetable Changed',"Your $subject class updated: $class on $day ($timeSlot).",'timetable']); } catch(Exception $e) {} }
                redirect('index.php?msg=updated');
            } catch(Exception $e) {
                $errors[] = "Database Error: " . $e->getMessage();
            }
        }
    }
}

if ($isTeacher && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_class'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        error_log("CSRF mismatch in timetable mark class");
        redirect("index.php?error=csrf");
    } else {
        $ttId=(int)$_POST['timetable_id']; $date=$_POST['log_date']??date('Y-m-d');
        $status=$_POST['class_status']??'taken'; $topic=sanitize($_POST['topic_taught']??''); $notes=sanitize($_POST['class_notes']??'');
        try {
            $tt=$db->prepare("SELECT * FROM timetable WHERE id=? AND teacher_id=?"); $tt->execute([$ttId,$user['id']]);
            $ttData=$tt->fetch();
            if ($ttData) {
                $db->prepare("INSERT INTO teacher_class_log (timetable_id,teacher_id,class,subject,date,status,topic_taught) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),topic_taught=VALUES(topic_taught),marked_at=NOW()")
                   ->execute([$ttId,$user['id'],$ttData['class'],$ttData['subject'],$date,$status,$topic]);
                logActivity($user['id'],"Marked class: {$ttData['subject']} on $date as $status",'timetable');

                // ── Auto-notify students when class is marked TAKEN ──────────────
                if ($status === 'taken') {
                    require_once '../../includes/email.php';
                    require_once '../../includes/student_notifications.php';

                    $timeLabel = date('h:i A', strtotime($ttData['start_time'])).' - '.date('h:i A', strtotime($ttData['end_time']));

                    // Fetch students in this class (by batch if available, else by class name)
                    try {
                        // Try to find by batch first
                        $batchId = null;
                        $bStmt = $db->prepare("SELECT id FROM batches WHERE class=? LIMIT 1");
                        $bStmt->execute([$ttData['class']]);
                        $bRow = $bStmt->fetch();
                        if ($bRow) $batchId = $bRow['id'];

                        if ($batchId) {
                            $sStmt = $db->prepare("SELECT id, name, email FROM students WHERE batch_id=? AND status='active'");
                            $sStmt->execute([$batchId]);
                        } else {
                            $sStmt = $db->prepare("SELECT id, name, email FROM students WHERE class=? AND status='active'");
                            $sStmt->execute([$ttData['class']]);
                        }
                        $classStudents = $sStmt->fetchAll();

                        $notifTitle   = "<svg width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round' style='display:inline-block;vertical-align:text-bottom;margin-right:4px'><path d='M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9'></path><path d='M13.73 21a2 2 0 0 1-3.46 0'></path></svg> {$ttData['subject']} class has started!";
                        $notifMessage = "Your {$ttData['subject']} class is live now. Teacher: {$user['name']}. Time: {$timeLabel}".($topic ? ". Topic: {$topic}" : '').".";

                        foreach ($classStudents as $stu) {
                            // In-app notification
                            try {
                                $db->prepare("INSERT INTO student_notifications (student_id,title,message,type,is_read,created_at) VALUES (?,?,?,?,0,NOW())")
                                   ->execute([$stu['id'], $notifTitle, $notifMessage, 'info']);
                            } catch(Exception $e) {}

                            // Email notification (non-blocking; will silently fail on localhost)
                            if (!empty($stu['email'])) {
                                @sendClassStartEmail($stu['email'], $stu['name'], $ttData['subject'], $user['name'], $timeLabel, $topic);
                            }
                        }
                        logActivity($user['id'], "Sent class-start notifications to ".count($classStudents)." students for {$ttData['subject']}", 'timetable');

                        // ── Update Syllabus Status ──
                        if (!empty($topic)) {
                            $upSyll = $db->prepare("UPDATE syllabus SET status='Completed' WHERE class=? AND subject=? AND topic=?");
                            $upSyll->execute([$ttData['class'], $ttData['subject'], $topic]);
                            if ($upSyll->rowCount() > 0) {
                                logActivity($user['id'], "Updated syllabus status to Completed for: {$ttData['subject']} - {$topic}", 'syllabus');
                            }
                        }
                    } catch(Exception $e) { }
                }
                redirect('index.php?msg=class_marked&date='.$date);
            }
        } catch(Exception $e) { $errors[] = "Error marking class: " . $e->getMessage(); }
    }
}


$days=['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$todayName=date('l');
$teachers=[];
try { $teachers=$db->query("SELECT u.id,u.name,t.subject FROM users u LEFT JOIN teachers t ON t.user_id=u.id WHERE u.role='teacher' AND u.status='active' ORDER BY u.name")->fetchAll(); } catch(Exception $e) {}

$classes=[];
try { $classes=$db->query("SELECT DISTINCT `class` FROM students WHERE `class` IS NOT NULL AND `class`!='' ORDER BY `class`")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e) {}
if (empty($classes)) $classes=['Class 8','Class 9','Class 10','Class 11','Class 12'];
$selDay=$_GET['day']??''; $selClass=$_GET['class']??''; $viewDate=$_GET['date']??date('Y-m-d');

$sql="SELECT t.*,u.name as teacher_name,u.id as teacher_uid FROM timetable t LEFT JOIN users u ON t.teacher_id=u.id WHERE 1=1";
$params=[];
if ($isTeacher) { $sql.=" AND t.teacher_id=?"; $params[]=$user['id']; }
if ($selDay)    { $sql.=" AND t.day=?"; $params[]=$selDay; }
if ($selClass)  { $sql.=" AND t.`class`=?"; $params[]=$selClass; }
$sql.=" ORDER BY FIELD(t.day,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'),t.start_time";
$slots=[];
try { $stmt=$db->prepare($sql); $stmt->execute($params); $slots=$stmt->fetchAll(); } catch(Exception $e) {}
$grouped=[]; foreach ($slots as $s) $grouped[$s['day']][]=$s;

$classLogs=[];
if ($isTeacher) {
    try {
        $logStmt=$db->prepare("SELECT * FROM teacher_class_log WHERE teacher_id=? AND date=?");
        $logStmt->execute([$user['id'],$viewDate]);
        foreach ($logStmt->fetchAll() as $log) $classLogs[$log['timetable_id']]=$log;
    } catch(Exception $e) {}
}

$notifCount=0;
if ($isTeacher) { try { $nS=$db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0"); $nS->execute([$user['id']]); $notifCount=(int)$nS->fetchColumn(); } catch(Exception $e) {} }

$root='../../'; require_once '../../includes/header.php'; ?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'csrf'): ?>
<div class="alert alert-danger" data-auto-dismiss>⚠️ Security token mismatch. Please try again.</div>
<?php endif; ?>
<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success" data-auto-dismiss><svg width="16" height="16" viewBox="0 0 24 24" fill="none"
        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
        style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
        <polyline points="20 6 9 17 4 12"></polyline>
    </svg>
    <?= match($_GET['msg']){'added'=>'Timetable slot added! Teacher notified.','updated'=>'Slot updated! Teacher notified.','deleted'=>'Slot deleted.','class_marked'=>'Class marked successfully!',default=>'Done!'} ?>
</div>
<?php endif; ?>
<?php foreach ($errors as $e): ?>
<div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
        stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
        style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
        <line x1="12" y1="9" x2="12" y2="13"></line>
        <line x1="12" y1="17" x2="12.01" y2="17"></line>
    </svg>
    <?= $e ?>
</div>
<?php endforeach; ?>

<div class="page-header mb-24">
    <div class="page-header-left">
        <h1 class="align-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="16" y1="2" x2="16" y2="6"></line>
                <line x1="8" y1="2" x2="8" y2="6"></line>
                <line x1="3" y1="10" x2="21" y2="10"></line>
            </svg> Timetable</h1>
        <p>
            <?= $isTeacher ? 'Your weekly schedule - mark each class after teaching' : 'Class-wise weekly timetable - changes notify teachers automatically' ?>
        </p>
    </div>
    <div class="page-header-actions">
        <?php if ($isTeacher && $notifCount > 0): ?>
        <button class="btn btn-secondary align-icon" style="position:relative"><svg width="14" height="14"
                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                stroke-linejoin="round">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
            </svg>
            <?= $notifCount ?> new
        </button>
        <?php endif; ?>
        <?php if ($canManage): ?><button class="btn btn-primary align-icon" onclick="openModal('addSlotModal')"><svg
                width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg> Add Slot</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($canManage): ?>
<!-- Teacher Class Count Summary -->
<div class="card mb-24">
    <div class="card-header">
        <div class="card-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg> Teacher Class Statistics</div>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Teacher</th>
                    <th>Weekly Slots</th>
                    <th>Total Classes Taken</th>
                    <th>This Week Taken</th>
                    <th>Completion</th>
                </tr>
            </thead>
            <tbody>
                <?php
        $allTS = [];
        try {
            $allTS=$db->query("SELECT u.id,u.name,
                COUNT(DISTINCT tt.id) as weekly_slots,
                (SELECT COUNT(*) FROM teacher_class_log tcl WHERE tcl.teacher_id=u.id AND tcl.status='taken' AND tcl.date >= DATE_SUB(CURDATE(),INTERVAL 7 DAY)) as week_taken,
                (SELECT COUNT(*) FROM teacher_class_log tcl WHERE tcl.teacher_id=u.id AND tcl.status='not_taken') as total_missed,
                (SELECT COUNT(*) FROM teacher_class_log tcl WHERE tcl.teacher_id=u.id AND tcl.status='taken') as total_taken
                FROM users u LEFT JOIN timetable tt ON tt.teacher_id=u.id
                WHERE u.role='teacher' AND u.status='active'
                GROUP BY u.id,u.name ORDER BY u.name")->fetchAll();
        } catch(Exception $e) {}
        foreach ($allTS as $ts):
        $weekPct=($ts['weekly_slots']>0)?round(($ts['week_taken']/$ts['weekly_slots'])*100):0;
        ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px">
                            <div
                                style="width:34px;height:34px;border-radius:50%;background:var(--amber-light);display:flex;align-items:center;justify-content:center;font-weight:800;color:var(--amber);font-size:13px;border:2px solid var(--amber-mid)">
                                <?= strtoupper(substr($ts['name'],0,1)) ?>
                            </div><strong>
                                <?= sanitize($ts['name']) ?>
                            </strong>
                        </div>
                    </td>
                    <td><span class="badge badge-blue">
                            <?= $ts['weekly_slots'] ?>/week
                        </span></td>
                    <td><span class="badge badge-green"><svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                                style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                            <?= $ts['total_taken'] ?>
                        </span>
                        <?= $ts['total_missed']>0?' <span class="badge badge-red"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg> '.$ts['total_missed'].' missed</span>':'' ?>
                    </td>
                    <td><span class="badge badge-amber">
                            <?= $ts['week_taken'] ?> taken
                        </span></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;min-width:120px">
                            <div class="progress" style="flex:1;height:6px">
                                <div class="progress-bar"
                                    style="width:<?= $weekPct ?>%;background:<?= $weekPct>=75?'var(--green)':($weekPct>=50?'var(--amber)':'var(--red)') ?>">
                                </div>
                            </div><span style="font-size:12px;font-weight:700">
                                <?= $weekPct ?>%
                            </span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-overlay" id="addSlotModal">
    <div class="modal" style="max-width:580px">
        <div class="modal-header">
            <div class="modal-title"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                    style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg> Add Timetable Slot</div><button class="modal-close" onclick="closeModal('addSlotModal')"><svg
                    width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round"
                    style="display:inline-block;vertical-align:text-bottom">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg></button>
        </div>
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="add_slot" value="1">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Class *</label>
                        <select name="class" id="addSlotClass" required
                            onchange="loadSubjectsForClass(this.value,'addSlotSubject')">
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>">
                                <?= htmlspecialchars($c) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Subject *</label>
                        <select name="subject" id="addSlotSubject" required>
                            <option value="">- Select Class First -</option>
                            <?php
                            // All distinct subjects from syllabus for JS preload
                            $allSyllSubjects = [];
                            try {
                                $sRows = $db->query("SELECT DISTINCT class, subject FROM syllabus WHERE subject!='' ORDER BY class, subject")->fetchAll();
                                foreach ($sRows as $r) $allSyllSubjects[$r['class']][] = $r['subject'];
                            } catch(Exception $e) {}
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Day *</label>
                        <select name="day" required>
                            <option value="">Select Day</option>
                            <?php foreach ($days as $d): ?>
                            <option value="<?= $d ?>">
                                <?= $d ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Teacher</label>
                        <select name="teacher_id" id="addSlotTeacher">
                            <option value="">- Select Subject First -</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Start Time *</label><input type="time" name="start_time" required>
                    </div>
                    <div class="form-group"><label>End Time *</label><input type="time" name="end_time" required></div>
                    <div class="form-group" style="grid-column:1/-1"><label>Room / Location</label><input type="text"
                            name="room" placeholder="e.g. Room 101, Science Lab"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addSlotModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><svg width="14" height="14" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                        stroke-linejoin="round"
                        style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                        <polyline points="17 21 17 13 7 13 7 21"></polyline>
                        <polyline points="7 3 7 8 15 8"></polyline>
                    </svg> Add Slot</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="editSlotModal">
    <div class="modal" style="max-width:580px">
        <div class="modal-header">
            <div class="modal-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                    style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                    <path d="M12 20h9"></path>
                    <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                </svg> Edit Timetable Slot</div><button class="modal-close" onclick="closeModal('editSlotModal')"><svg
                    width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round"
                    style="display:inline-block;vertical-align:text-bottom">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg></button>
        </div>
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="edit_slot" value="1"><input type="hidden" name="slot_id"
                id="editSlotId">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group"><label>Class *</label><select name="class" id="editClass" required
                            onchange="loadSubjectsForClass(this.value,'editSubject')">
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>">
                                <?= htmlspecialchars($c) ?>
                            </option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="form-group"><label>Subject *</label><select name="subject" id="editSubject" required>
                            <option value="">- Select Class First -</option>
                        </select></div>
                    <div class="form-group"><label>Day *</label><select name="day" id="editDay" required>
                            <option value="">Select Day</option>
                            <?php foreach ($days as $d): ?>
                            <option value="<?= $d ?>">
                                <?= $d ?>
                            </option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="form-group"><label>Teacher</label><select name="teacher_id" id="editTeacher">
                            <option value="">- Select Subject First -</option>
                        </select></div>
                    <div class="form-group"><label>Start Time *</label><input type="time" name="start_time"
                            id="editStart" required></div>
                    <div class="form-group"><label>End Time *</label><input type="time" name="end_time" id="editEnd"
                            required></div>
                    <div class="form-group" style="grid-column:1/-1"><label>Room</label><input type="text" name="room"
                            id="editRoom" placeholder="e.g. Room 101"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editSlotModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><svg width="14" height="14" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                        stroke-linejoin="round"
                        style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                        <polyline points="17 21 17 13 7 13 7 21"></polyline>
                        <polyline points="7 3 7 8 15 8"></polyline>
                    </svg> Update Slot</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($isTeacher): ?>
<div class="modal-overlay" id="markClassModal">
    <div class="modal" style="max-width:500px">
        <div class="modal-header">
            <div class="modal-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                    style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg> Mark Class</div><button class="modal-close" onclick="closeModal('markClassModal')"><svg
                    width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round"
                    style="display:inline-block;vertical-align:text-bottom">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg></button>
        </div>
        <div class="modal-body">
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="mark_class" value="1"><input type="hidden"
                name="timetable_id" id="markTtId"><input type="hidden" name="log_date" id="markDate"
                value="<?= $viewDate ?>">
            <div class="modal-body">
                <div id="markClassInfo"
                    style="background:var(--blue-light);border:1.5px solid var(--blue-mid);border-radius:var(--r-sm);padding:14px;margin-bottom:18px">
                    <strong id="markSubject" style="font-size:15px"></strong> - <span id="markClass"></span>
                    <div style="font-size:12px;color:var(--text-mid);margin-top:4px" id="markTime"></div>
                </div>
                <div class="form-group"><label>Class Status *</label>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px">
                        <label
                            style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:12px 16px;border:1.5px solid var(--border);border-radius:var(--r-sm);flex:1;transition:all .15s;background:var(--green-light);border-color:var(--green-mid)"
                            id="lbl-taken">
                            <input type="radio" name="class_status" value="taken" checked onchange="updateClassLabel()">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                                style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg> Class Taken
                        </label>
                        <label
                            style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:12px 16px;border:1.5px solid var(--border);border-radius:var(--r-sm);flex:1;transition:all .15s"
                            id="lbl-not_taken">
                            <input type="radio" name="class_status" value="not_taken" onchange="updateClassLabel()">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                                style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                            Not Taken
                        </label>
                    </div>
                </div>
                <div class="form-group" id="topicSection">
                    <label><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                            style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                            <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path>
                            <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>
                        </svg> Topic Taught</label>
                    <input type="text" name="topic_taught" id="topicTaught"
                        placeholder="e.g. Quadratic Equations - Factorization method">
                    <span class="form-hint">What topic did you cover in this class?</span>
                </div>
                <div class="form-group"><label>Notes <small>(optional)</small></label><textarea name="class_notes"
                        rows="2" placeholder="Any observations or homework assigned..."></textarea></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('markClassModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><svg width="14" height="14" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                        stroke-linejoin="round"
                        style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                        <polyline points="17 21 17 13 7 13 7 21"></polyline>
                        <polyline points="7 3 7 8 15 8"></polyline>
                    </svg> Save</button>
            </div>
        </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card" style="margin-bottom:20px">
    <div class="filter-bar" style="flex-wrap:wrap;gap:10px">
        <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;flex:1">
            <?php if ($isTeacher): ?>
            <div style="display:flex;align-items:center;gap:8px"><span
                    style="font-weight:700;font-size:13px;color:var(--text-mid)"><svg width="16" height="16"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                        stroke-linejoin="round"
                        style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg> Date:</span><input type="date" name="date" value="<?= $viewDate ?>"
                    max="<?= date('Y-m-d') ?>"
                    style="padding:9px 12px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-size:13px;background:#fff;outline:none;cursor:pointer"
                    onchange="this.form.submit()"></div>
            <?php else: ?>
            <div style="display:flex;align-items:center;gap:8px"><span
                    style="font-weight:700;font-size:13px;color:var(--text-mid)"><svg width="16" height="16"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                        stroke-linejoin="round"
                        style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg> Day:</span><select name="day" onchange="this.form.submit()"
                    style="padding:9px 14px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-size:13px;background:#fff;outline:none;min-width:140px">
                    <option value="">All Days</option>
                    <?php foreach ($days as $d): ?>
                    <option value="<?= $d ?>" <?=$selDay===$d?'selected':'' ?>
                        <?= $d===$todayName?'style="font-weight:700"':'' ?>>
                        <?= $d ?>
                        <?= $d===$todayName?' (Today)':'' ?>
                    </option>
                    <?php endforeach; ?>
                </select></div>
            <div style="display:flex;align-items:center;gap:8px"><span
                    style="font-weight:700;font-size:13px;color:var(--text-mid)"><svg width="16" height="16"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                        stroke-linejoin="round"
                        style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                        <polygon points="12 2 2 7 12 12 22 7 12 2"></polygon>
                        <polyline points="2 17 12 22 22 17"></polyline>
                        <polyline points="2 12 12 17 22 12"></polyline>
                    </svg> Class:</span><select name="class" onchange="this.form.submit()"
                    style="padding:9px 14px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-size:13px;background:#fff;outline:none;min-width:160px">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>" <?=$selClass===$c?'selected':'' ?>>
                        <?= htmlspecialchars($c) ?>
                    </option>
                    <?php endforeach; ?>
                </select></div>
            <?php endif; ?>
            <?php if ($selDay||$selClass): ?><a href="index.php" class="btn btn-secondary btn-sm"><svg width="14"
                    height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round"
                    style="display:inline-block;vertical-align:text-bottom">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg> Clear</a>
            <?php endif; ?>
        </form>
        <span style="font-size:12.5px;color:var(--text-light);font-weight:600;white-space:nowrap">Today: <strong>
                <?= date('D, d M Y') ?>
            </strong></span>
    </div>
</div>

<?php if ($isTeacher): ?>
<!-- Week strip for teacher -->
<div
    style="display:flex;gap:6px;margin-bottom:18px;overflow-x:auto;padding-bottom:4px;-webkit-overflow-scrolling:touch">
    <?php $weekStart=date('Y-m-d',strtotime('monday this week'));
for ($i=0;$i<6;$i++) {
    $d=date('Y-m-d',strtotime($weekStart.' +'.$i.' days'));
    $dn=date('l',strtotime($d)); $isSel=$d===$viewDate; $isT=$d===date('Y-m-d');
    $hasSlots=isset($grouped[$dn]); $daySlotCount=$hasSlots?count($grouped[$dn]):0;
    $dayTaken=0;
    if ($hasSlots) { try { $ds=$db->prepare("SELECT COUNT(*) FROM teacher_class_log WHERE teacher_id=? AND date=? AND status='taken'"); $ds->execute([$user['id'],$d]); $dayTaken=(int)$ds->fetchColumn(); } catch(Exception $e) {} }
    $allM=$hasSlots&&$dayTaken>=$daySlotCount&&$daySlotCount>0; $partM=$hasSlots&&$dayTaken>0&&$dayTaken<$daySlotCount;
?>
    <a href="?date=<?= $d ?>"
        style="flex-shrink:0;text-align:center;padding:10px 14px;border-radius:var(--r-sm);text-decoration:none;background:<?= $isSel?'var(--blue)':($allM?'var(--green-light)':($partM?'var(--amber-light)':'var(--card)')) ?>;border:2px solid <?= $isSel?'var(--blue)':($allM?'var(--green-mid)':($partM?'var(--amber-mid)':($isT?'var(--blue-mid)':'var(--border)'))) ?>;color:<?= $isSel?'#fff':($allM?'var(--green)':($partM?'var(--amber)':'var(--text-mid)')) ?>;min-width:60px;transition:all .15s;">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase">
            <?= substr($dn,0,3) ?>
        </div>
        <div style="font-size:15px;font-weight:800;margin:2px 0">
            <?= date('d',strtotime($d)) ?>
        </div>
        <?php if ($hasSlots): ?>
        <div style="font-size:10px;font-weight:600">
            <?= $dayTaken ?>/
            <?= $daySlotCount ?>
        </div>
        <?php if ($allM): ?>
        <div style="font-size:13px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg></div>
        <?php endif; ?>
        <?php endif; ?>
    </a>
    <?php } ?>
</div>
<?php endif; ?>

<?php if (empty($slots)): ?>
<div class="card">
    <div class="empty-state">
        <div class="empty-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="16" y1="2" x2="16" y2="6"></line>
                <line x1="8" y1="2" x2="8" y2="6"></line>
                <line x1="3" y1="10" x2="21" y2="10"></line>
            </svg></div>
        <h3>No timetable slots found</h3>
        <p>
            <?= $isTeacher?'No classes assigned yet':'Add your first timetable slot' ?>
        </p>
        <?php if ($canManage): ?><button class="btn btn-primary btn-sm" onclick="openModal('addSlotModal')"><svg
                width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg> Add Slot</button>
        <?php endif; ?>
    </div>
</div>
<?php else:
$displayDays=$selDay?[$selDay]:$days;
foreach ($displayDays as $day):
    if (!isset($grouped[$day])) continue;
    $daySlots=$grouped[$day]; $isToday=$day===$todayName;
    $markedCount=0;
    if ($isTeacher) foreach ($daySlots as $s) if (isset($classLogs[$s['id']])) $markedCount++;
?>
<div class="card" style="margin-bottom:18px;<?= $isToday?'border:2px solid var(--blue)':'' ?>">
    <div class="card-header" style="<?= $isToday?'background:linear-gradient(135deg,var(--blue-light),#f0f7ff)':'' ?>">
        <div class="card-title" style="<?= $isToday?'color:var(--blue-deep)':'' ?>">
            <?= $isToday?'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>':'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>' ?>
            <?= $day ?>
            <?php if ($isToday): ?><span class="badge badge-blue" style="font-size:11px">Today</span>
            <?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:10px">
            <?php if ($isTeacher&&$day===$todayName): ?><span
                class="badge <?= $markedCount>=count($daySlots)?'badge-green':($markedCount>0?'badge-amber':'badge-gray') ?>">
                <?= $markedCount ?>/
                <?= count($daySlots) ?> marked
            </span>
            <?php endif; ?>
            <span class="badge badge-gray">
                <?= count($daySlots) ?> period
                <?= count($daySlots)!=1?'s':'' ?>
            </span>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Subject</th>
                    <?php if ($canManage): ?>
                    <th>Class</th>
                    <th>Teacher</th>
                    <?php endif; ?>
                    <?php if ($isTeacher): ?>
                    <th>Class</th>
                    <th>Topic Taught</th>
                    <th>Status</th>
                    <?php endif; ?>
                    <?php if ($canManage): ?>
                    <th>Actions</th>
                    <?php endif; ?>
                    <?php if ($isTeacher): ?>
                    <th>Mark</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($daySlots as $slot):
            $isOngoing=$isToday&&date('H:i')>=date('H:i',strtotime($slot['start_time']))&&date('H:i')<=date('H:i',strtotime($slot['end_time']));
            $classLog=$classLogs[$slot['id']]??null; $isMarked=!empty($classLog); $wasTaken=$classLog&&$classLog['status']==='taken';
        ?>
                <tr style="<?= $isOngoing?'background:var(--blue-light)':($wasTaken?'background:#f0fdf4':'') ?>">
                    <td>
                        <div style="display:flex;align-items:center;gap:8px">
                            <?php if ($isOngoing): ?><span
                                style="width:8px;height:8px;background:var(--red);border-radius:50%;animation:pulse 1.5s infinite;flex-shrink:0"></span>
                            <?php endif; ?>
                            <div>
                                <div class="font-mono" style="font-size:13px;font-weight:700">
                                    <?= date('h:i A',strtotime($slot['start_time'])) ?>
                                </div>
                                <div style="font-size:11px;color:var(--text-light)">
                                    <?= date('h:i A',strtotime($slot['end_time'])) ?>
                                </div>
                                <?php if (!empty($slot['room'])): ?>
                                <div style="font-size:10.5px;color:var(--text-light)"><svg width="10" height="10"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                                        <polyline points="9 22 9 12 15 12 15 22"></polyline>
                                    </svg>
                                    <?= sanitize($slot['room']) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td><strong>
                            <?= sanitize($slot['subject']) ?>
                        </strong>
                        <?= $isOngoing?' <span class="badge badge-blue" style="font-size:10px;margin-left:6px"><span style="width:8px;height:8px;background:var(--red);border-radius:50%;display:inline-block;margin-right:4px;vertical-align:middle;animation:pulse 1s infinite"></span> Live</span>':'' ?>
                    </td>
                    <?php if ($canManage): ?>
                    <td><span class="badge badge-blue">
                            <?= sanitize($slot['class']) ?>
                        </span></td>
                    <td>
                        <?php if ($slot['teacher_name']): ?>
                        <div style="display:flex;align-items:center;gap:8px">
                            <div
                                style="width:28px;height:28px;border-radius:50%;background:var(--amber-light);display:flex;align-items:center;justify-content:center;font-weight:800;color:var(--amber);font-size:11px;border:2px solid var(--amber-mid)">
                                <?= strtoupper(substr($slot['teacher_name'],0,1)) ?>
                            </div>
                            <?= sanitize($slot['teacher_name']) ?>
                        </div>
                        <?php else: ?><span class="text-muted">- Unassigned</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <?php if ($isTeacher): ?>
                    <td><span class="badge badge-blue">
                            <?= sanitize($slot['class']) ?>
                        </span></td>
                    <td>
                        <?php if ($classLog&&$classLog['topic_taught']): ?><span style="font-size:12.5px"><svg
                                width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                                style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                                <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path>
                                <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>
                            </svg>
                            <?= sanitize($classLog['topic_taught']) ?>
                        </span>
                        <?php else: ?><span style="font-size:12px;color:var(--text-light)">- Not recorded</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isMarked&&$wasTaken): ?><span class="badge badge-green"><svg width="16" height="16"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                                stroke-linecap="round" stroke-linejoin="round"
                                style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg> Taken</span>
                        <?php elseif ($isMarked): ?><span class="badge badge-red"><svg width="16" height="16"
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                                stroke-linecap="round" stroke-linejoin="round"
                                style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg> Not Taken</span>
                        <?php else: ?><span class="badge badge-gray"><svg width="12" height="12" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                stroke-linejoin="round"
                                style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg> Pending</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <?php if ($canManage): ?>
                    <td>
                        <div style="display:flex;gap:6px"><button class="btn btn-secondary btn-sm"
                                onclick="editSlot(<?= $slot['id'] ?>,'<?= addslashes($slot['class']) ?>','<?= addslashes($slot['subject']) ?>','<?= $slot['day'] ?>','<?= $slot['teacher_id']??'' ?>','<?= $slot['start_time'] ?>','<?= $slot['end_time'] ?>','<?= addslashes($slot['room']??'') ?>')"><svg
                                    width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 20h9"></path>
                                    <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                                </svg></button>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?><input type="hidden" name="delete_id"
                                    value="<?= $slot['id'] ?>"><button type="submit" class="btn btn-danger btn-sm"
                                    data-confirm="Delete this slot?"><svg width="14" height="14" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                        stroke-linejoin="round">
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
                    <?php endif; ?>
                    <?php if ($isTeacher): ?>
                    <td><button class="btn <?= $isMarked&&$wasTaken?'btn-success':'btn-primary' ?> btn-sm"
                            style="white-space:nowrap"
                            onclick="openMarkModal(<?= $slot['id'] ?>,'<?= addslashes($slot['subject']) ?>','<?= addslashes($slot['class']) ?>','<?= date('h:i A',strtotime($slot['start_time'])) ?>','<?= date('h:i A',strtotime($slot['end_time'])) ?>','<?= addslashes($classLog['topic_taught']??'') ?>')">
                            <?= $isMarked&&$wasTaken?'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg> Edit':'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px"><polyline points="20 6 9 17 4 12"></polyline></svg> Mark' ?>
                        </button></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; endif; ?>

<?php if ($canManage && !$selDay): ?>
<div class="card" style="margin-top:24px">
    <div class="card-header">
        <div class="card-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                <polygon points="12 2 2 7 12 12 22 7 12 2"></polygon>
                <polyline points="2 17 12 22 22 17"></polyline>
                <polyline points="2 12 12 17 22 12"></polyline>
            </svg> Class-wise Timetable</div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;padding:20px">
        <?php foreach ($classes as $cls):
        $csData = [];
        try {
            $cs=$db->prepare("SELECT t.*,u.name as tname FROM timetable t LEFT JOIN users u ON t.teacher_id=u.id WHERE t.class=? ORDER BY FIELD(t.day,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'),t.start_time");
            $cs->execute([$cls]); $csData=$cs->fetchAll();
        } catch(Exception $e) {}
        if (empty($csData)) continue;
    ?>
        <a href="?class=<?= urlencode($cls) ?>" style="text-decoration:none">
            <div class="class-tt-card">
                <div class="class-tt-header"><span style="font-weight:800;color:var(--blue-deep)"><svg width="16"
                            height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                            stroke-linecap="round" stroke-linejoin="round"
                            style="display:inline-block;vertical-align:text-bottom;margin-right:2px">
                            <polygon points="12 2 2 7 12 12 22 7 12 2"></polygon>
                            <polyline points="2 17 12 22 22 17"></polyline>
                            <polyline points="2 12 12 17 22 12"></polyline>
                        </svg>
                        <?= sanitize($cls) ?>
                    </span><span class="badge badge-blue">
                        <?= count($csData) ?> slots
                    </span></div>
                <?php foreach (array_slice($csData,0,5) as $sd): ?>
                <div
                    style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border-light);font-size:12.5px">
                    <span style="color:var(--text-mid);font-weight:600;min-width:65px">
                        <?= substr($sd['day'],0,3) ?>
                    </span>
                    <span style="font-weight:700;flex:1">
                        <?= sanitize($sd['subject']) ?>
                    </span>
                    <span style="color:var(--text-light);font-size:11px">
                        <?= date('h:i A',strtotime($sd['start_time'])) ?>
                    </span>
                </div>
                <?php endforeach; ?>
                <?php if (count($csData)>5): ?>
                <div style="font-size:11px;color:var(--text-light);padding-top:6px">+
                    <?= count($csData)-5 ?> more
                </div>
                <?php endif; ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<style>
    @keyframes pulse {

        0%,
        100% {
            opacity: 1
        }

        50% {
            opacity: .5
        }
    }

    .class-tt-card {
        background: var(--card);
        border: 1.5px solid var(--border);
        border-radius: var(--r);
        padding: 16px;
        transition: all .2s;
        cursor: pointer
    }

    .class-tt-card:hover {
        border-color: var(--blue);
        box-shadow: var(--sh);
        transform: translateY(-2px)
    }

    .class-tt-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--blue-light)
    }
</style>

<?php if ($canManage): ?>
<script>
    // Subject data from syllabus keyed by class
    var syllabusSubjects = <?= json_encode($allSyllSubjects ?? []) ?>;
    var fallbackSubjects = ['English Grammar', 'EVS', 'Maths', 'Science', 'Social Science'];

    async function loadSubjectsForClass(cls, targetId, preselect) {
        var sel = document.getElementById(targetId);
        if (!sel) return;
        sel.innerHTML = '<option value="">- Select Subject -</option>';

        // Clear dependent teacher dropdown
        var teacherTarget = targetId === 'addSlotSubject' ? 'addSlotTeacher' : 'editTeacher';
        var tSel = document.getElementById(teacherTarget);
        if (tSel) tSel.innerHTML = '<option value="">- Select Subject First -</option>';

        if (!cls) return;

        var subjs = syllabusSubjects[cls] || fallbackSubjects;
        subjs.forEach(function (s) {
            var o = document.createElement('option');
            o.value = s; o.textContent = s;
            if (preselect && s === preselect) o.selected = true;
            sel.appendChild(o);
        });
        // If preselect not found, add it as option anyway
        if (preselect && !subjs.includes(preselect)) {
            var o = document.createElement('option');
            o.value = preselect; o.textContent = preselect; o.selected = true;
            sel.appendChild(o);
        }
    }

    async function loadTeachers(cls, subj, targetId, preselect = null) {
        var sel = document.getElementById(targetId);
        if (!sel) return;
        sel.innerHTML = '<option value="">Loading teachers...</option>';

        if (!cls || !subj) {
            sel.innerHTML = '<option value="">- Select Subject First -</option>';
            return;
        }

        try {
            const res = await fetch(`ajax_get_teachers.php?class=${encodeURIComponent(cls)}&subject=${encodeURIComponent(subj)}`);
            const teachers = await res.json();
            sel.innerHTML = '<option value="">- No Teacher -</option>';
            teachers.forEach(t => {
                var o = document.createElement('option');
                o.value = t.id; o.textContent = t.name;
                if (preselect && t.id == preselect) o.selected = true;
                sel.appendChild(o);
            });
            if (teachers.length === 0) {
                sel.innerHTML = '<option value="">- No assigned teachers found -</option>';
            }
        } catch (e) {
            console.error(e);
            sel.innerHTML = '<option value="">- Error loading teachers -</option>';
        }
    }

    // Bind to Add Modal Subject Change
    document.getElementById('addSlotSubject').addEventListener('change', function () {
        var cls = document.getElementById('addSlotClass').value;
        var subj = this.value;
        loadTeachers(cls, subj, 'addSlotTeacher');
    });

    // Bind to Edit Modal Subject Change
    document.getElementById('editSubject').addEventListener('change', function () {
        var cls = document.getElementById('editClass').value;
        var subj = this.value;
        loadTeachers(cls, subj, 'editTeacher');
    });

    function editSlot(id, cls, subject, day, teacherId, start, end, room) {
        document.getElementById('editSlotId').value = id;
        document.getElementById('editStart').value = start;
        document.getElementById('editEnd').value = end;
        document.getElementById('editRoom').value = room || '';
        for (let o of document.getElementById('editClass').options) if (o.value === cls) { o.selected = true; break; }
        for (let o of document.getElementById('editDay').options) if (o.value === day) { o.selected = true; break; }

        // Load subjects for this class then preselect
        loadSubjectsForClass(cls, 'editSubject', subject);
        // Load eligible teachers and preselect
        loadTeachers(cls, subject, 'editTeacher', teacherId);

        openModal('editSlotModal');
    }
</script>
<?php endif; ?>
<?php if ($isTeacher): ?>
<script>
    function openMarkModal(ttId, subject, cls, startT, endT, topic) {
        document.getElementById('markTtId').value = ttId;
        document.getElementById('markSubject').textContent = subject;
        document.getElementById('markClass').textContent = cls;
        document.getElementById('markTime').textContent = startT + ' - ' + endT;
        document.getElementById('topicTaught').value = topic || '';
        document.querySelector('input[name="class_status"][value="taken"]').checked = true;
        updateClassLabel();
        openModal('markClassModal');
    }
    function updateClassLabel() {
        const val = document.querySelector('input[name="class_status"]:checked')?.value;
        document.getElementById('lbl-taken').style.cssText += val === 'taken' ? ';background:var(--green-light);border-color:var(--green-mid)' : ';background:;border-color:var(--border)';
        document.getElementById('lbl-not_taken').style.cssText += val === 'not_taken' ? ';background:var(--red-light);border-color:var(--red-mid)' : ';background:;border-color:var(--border)';
        const ts = document.getElementById('topicSection');
        if (ts) ts.style.display = val === 'taken' ? '' : 'none';
    }
</script>
<?php endif; ?>
<?php if ($errors): ?>
<script>document.addEventListener('DOMContentLoaded', () => openModal('addSlotModal'))</script>
<?php endif; ?>
<?php require_once '../../includes/footer.php'; ?>
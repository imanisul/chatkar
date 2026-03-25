<?php
require_once '../student/auth.php';
requireStudentLogin();
require_once '../includes/student_notifications.php';

function getStudentIcon($type, $color = 'currentColor', $size = 20) {
    $svgo = '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="'.$color.'" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-4px">';
    return $svgo . match($type) {
        'book'      => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>',
        'clock'     => '<circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline>',
        'user'      => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>',
        'award'     => '<circle cx="12" cy="8" r="7"></circle><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"></polyline>',
        'edit'      => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>',
        'check'     => '<polyline points="20 6 9 17 4 12"></polyline>',
        'attendance'=> '<polyline points="20 6 9 17 4 12"></polyline>',
        'homework'  => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14l2 2 4-4"></path>',
        'quiz'      => '<circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line>',
        'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>',
        'note'      => '<path d="M2 12v10h10"></path><path d="M20 22h2V2h-2z"></path><path d="M20 22h-2v2h2v-2z"></path><path d="M14 2h-6a2 2 0 0 0-2 2v16h14V4a2 2 0 0 0-2-2z"></path>',
        'notes'     => '<path d="M2 12v10h10"></path><path d="M20 22h2V2h-2z"></path><path d="M20 22h-2v2h2v-2z"></path><path d="M14 2h-6a2 2 0 0 0-2 2v16h14V4a2 2 0 0 0-2-2z"></path>',
        'coins'     => '<circle cx="8" cy="8" r="6"></circle><path d="M18.09 10.37A6 6 0 1 1 10.34 18"></path><path d="M7 6h1v4"></path><path d="M17 14h.01"></path>',
        'list'      => '<line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line>',
        'lightning' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>',
        'fire'      => '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.5-4.5.5-6 .5 1.5 3 2.5 5 4.5s2.5 5.5 1 8c-1.5 2.5-4.5 3.5-7 3.5s-4.5-1.5-5.5-3.5c1 0 3 .5 4.5-1.5z"></path>',
        'syllabus'  => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>',
        'certificate'=> '<circle cx="12" cy="8" r="7"></circle><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"></polyline>',
        'progress'  => '<line x1="12" y1="20" x2="12" y2="10"></line><line x1="18" y1="20" x2="18" y2="4"></line><line x1="6" y1="20" x2="6" y2="16"></line>',
        'help'      => '<circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line>',
        default     => '<circle cx="12" cy="12" r="10"></circle>'
    } . '</svg>';
}

$db = getDB();
$student = currentStudent();
$sid = $student['id'];
$s = $db->prepare("SELECT * FROM students WHERE id=?");
$s->execute([$sid]);
$s = $s->fetch();
if (!$s) {
    // Fail gracefully if student not found
    $s = [
        'name' => 'Student',
        'gender' => 'male',
        'class' => '',
        'batch_id' => null,
        'dob' => null,
        'mentor_id' => null
    ];
}
$gender = strtolower($s['gender'] ?? 'male');
$todayName = date('H:i:s'); // Actual time for live detection
$currentDay = date('l'); 
$currentTime = date('H:i:s');

// Coins & Streak
$myCoins = getStudentCoins($db, $sid);
$streakData = updateStreak($db, $sid); // also updates streak on each login/visit
$myStreak = $streakData[0];
$longestStreak = $streakData[1];

// Today's timetable
$todaySlots = [];
try {
    $sql = "SELECT t.*, u.name as teacher_name FROM timetable t LEFT JOIN users u ON u.id=t.teacher_id WHERE t.day=?";
    $params = [$currentDay];
    if ($s['batch_id']) {
        $sql .= " AND (t.batch_id=? OR t.class=?)";
        $params[] = $s['batch_id'];
        $params[] = $s['class'];
    }
    elseif ($s['class']) {
        $sql .= " AND t.class=?";
        $params[] = $s['class'];
    }
    $sql .= " ORDER BY t.start_time";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $todaySlots = $stmt->fetchAll();
}
catch (Exception $e) {
}

// Pending Homework
$pendingHomework = [];
try {
    $hwSql = "SELECT h.*, c.chapter_name, hs.status as sub_status 
              FROM homework h 
              LEFT JOIN chapters c ON h.chapter_id = c.id 
              LEFT JOIN homework_submissions hs ON h.id = hs.homework_id AND hs.student_id = ?
              WHERE h.class_name = ? AND h.status = 'published' AND (hs.status IS NULL OR hs.status = 'Pending')
              ORDER BY h.due_date ASC LIMIT 5";
    $hws = $db->prepare($hwSql);
    $hws->execute([$sid, $s['class']]);
    $pendingHomework = $hws->fetchAll();
}
catch (Exception $e) {
}

// Latest Notes
$latestNotes = [];
try {
    $nSql = "SELECT n.*, c.chapter_name, u.name as teacher_name 
             FROM notes n 
             LEFT JOIN chapters c ON n.chapter_id = c.id 
             LEFT JOIN users u ON n.uploaded_by = u.id 
             WHERE n.class_name = ? AND n.status = 'published'
             ORDER BY n.created_at DESC LIMIT 5";
    $nts = $db->prepare($nSql);
    $nts->execute([$s['class']]);
    $latestNotes = $nts->fetchAll();
}
catch (Exception $e) {
}

// Attendance stats
$attStats = ['Present' => 0, 'Absent' => 0, 'Late' => 0];
try {
    $as = $db->prepare("SELECT status,COUNT(*) as c FROM attendance WHERE student_id=? GROUP BY status");
    $as->execute([$sid]);
    foreach ($as->fetchAll() as $r) {
        if (isset($attStats[$r['status']]))
            $attStats[$r['status']] = (int)$r['c'];
    }
}
catch (Exception $e) {
}
$attTotal = array_sum($attStats);
$attRate = $attTotal > 0 ? round($attStats['Present'] / $attTotal * 100, 1) : 0;

// Subject-wise chapter list (simplified)
$subjectChapters = [];
try {
        $scStmt = $db->prepare("SELECT subject, COUNT(*) as chapters FROM chapters WHERE class=? GROUP BY subject");
    $scStmt->execute([$s['class']]);
    $subjectChapters = $scStmt->fetchAll();
}
catch (Exception $e) {
}

// Pending quizzes
$pendingQuizzes = [];
try {
    $qsql = "SELECT q.* FROM quizzes q WHERE q.status='published' AND (q.deadline IS NULL OR q.deadline >= NOW()) AND q.id NOT IN (SELECT quiz_id FROM student_quiz_attempts WHERE student_id=?)";
    $qp = [$sid];
    if ($s['batch_id']) {
        $qsql .= " AND (q.batch_id=? OR q.class=?)";
        $qp[] = $s['batch_id'];
        $qp[] = $s['class'];
    }
    $qsql .= " ORDER BY q.deadline ASC LIMIT 3";
    $pqs = $db->prepare($qsql);
    $pqs->execute($qp);
    $pendingQuizzes = $pqs->fetchAll();
}
catch (Exception $e) {
}

// Total doubts
$totalDoubts = 0;
try {
    $dq = $db->prepare("SELECT COUNT(*) FROM doubt_sessions WHERE student_id=?");
    $dq->execute([$sid]);
    $totalDoubts = (int)$dq->fetchColumn();
}
catch (Exception $e) {
}

// Announcements (student_notifications)
$announcements = [];
try {
    $an = $db->prepare("SELECT * FROM student_notifications WHERE student_id=? ORDER BY created_at DESC LIMIT 4");
    $an->execute([$sid]);
    $announcements = $an->fetchAll();
}
catch (Exception $e) {
}

// Unread notification count
$unreadCount = 0;
try {
    $uc = $db->prepare("SELECT COUNT(*) FROM student_notifications WHERE student_id=? AND is_read=0");
    $uc->execute([$sid]);
    $unreadCount = (int)$uc->fetchColumn();
}
catch (Exception $e) {
}

// Batch info
$batchInfo = null;
try {
    if ($s['batch_id']) {
        $bi = $db->prepare("SELECT b.*, GROUP_CONCAT(bs.subject SEPARATOR ', ') as subjects FROM batches b LEFT JOIN batch_subjects bs ON bs.batch_id=b.id WHERE b.id=? GROUP BY b.id");
        $bi->execute([$s['batch_id']]);
        $batchInfo = $bi->fetch();
    }
}
catch (Exception $e) {
}

// Mini leaderboard (top 3 — CLASS ONLY)
$topStudents = [];
try {
    $tsql = $db->prepare("
        SELECT st.id, st.name, st.gender, COALESCE(SUM(a.score),0) as total_score
        FROM students st LEFT JOIN student_quiz_attempts a ON a.student_id=st.id
        WHERE st.class=?
        GROUP BY st.id ORDER BY total_score DESC LIMIT 3
    ");
    $tsql->execute([$s['class']]);
    $topStudents = $tsql->fetchAll();
}
catch (Exception $e) {
}

// My quiz rank (within same class)
$myRank = 0;
try {
    $rkStmt = $db->prepare("
        SELECT student_id, RANK() OVER (ORDER BY SUM(score) DESC) as rnk
        FROM student_quiz_attempts sqa
        JOIN students st ON st.id = sqa.student_id
        WHERE st.class=?
        GROUP BY student_id
    ");
    $rkStmt->execute([$s['class']]);
    foreach ($rkStmt->fetchAll() as $r) {
        if ($r['student_id'] == $sid) { $myRank = $r['rnk']; break; }
    }
}
catch (Exception $e) {
}

$todayStr = date('d M Y');
$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
$greetEmoji = $hour < 12 ? '🌅' : ($hour < 17 ? '☀️' : '🌙');
// Age-Adaptive Detection
$classNum = (int)filter_var($s['class'] ?? '', FILTER_SANITIZE_NUMBER_INT);
$isYounger = ($classNum >= 1 && $classNum <= 5);

// ── BIRTHDAY DETECTION ──
$isBirthday = false;
if (!empty($s['dob'])) {
    $dobMonthDay = date('m-d', strtotime($s['dob']));
    $todayMonthDay = date('m-d');
    if ($dobMonthDay === $todayMonthDay) {
        $isBirthday = true;
    }
}

// ── REAL DATA FETCH ──
// Initialize variables to prevent undefined warnings in templates
$chapTotal = 0;
$chapDone = 0;
$syllabusPct = 0;
$mentor = null;
$leaderboard = [];
$recentQuizzes = [];
$weeklyTimetable = [];
$liveClass = null;
$totalSessions = 0;
// NOTE: $totalDoubts is already fetched above (line ~161). Do NOT reset it here.
$answeredDoubts = 0;
$topSubject = 'Learning';
$subjectProgress = [];
$upcomingQuizzes = [];

try {
    // 1. Syllabus Stats (Syllabus Tracker Proper Fix)
    $class = $s['class'] ?? '';
    
    $baseClass = trim($class);
    if (empty($baseClass) && !empty($_SESSION['student_class'])) {
        $baseClass = trim($_SESSION['student_class']);
    }
    
    $syllabusClasses = [];
    if (!empty($baseClass)) $syllabusClasses[] = $baseClass;

    if (!empty($s['batch_id'])) {
        $bStmt = $db->prepare("SELECT class FROM batches WHERE id=?");
        $bStmt->execute([$s['batch_id']]);
        $bRow = $bStmt->fetch();
        if ($bRow && !empty(trim($bRow['class']))) $syllabusClasses[] = trim($bRow['class']);
    } else {
        $bsStmt = $db->prepare("SELECT b.class FROM batches b JOIN batch_students bs ON bs.batch_id = b.id WHERE bs.student_id = ? LIMIT 1");
        $bsStmt->execute([$sid]);
        $bsRow = $bsStmt->fetch();
        if ($bsRow && !empty(trim($bsRow['class']))) $syllabusClasses[] = trim($bsRow['class']);
    }

    $variations = [];
    foreach ($syllabusClasses as $cls) {
        $cls = trim($cls);
        if (empty($cls)) continue;
        $variations[] = $cls;
        
        $clean = trim(preg_replace('/class|grade|section|std|standard/i', '', $cls));
        $clean = trim(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $clean));
        $clean = preg_replace('/\s+/', ' ', $clean);
        
        // Roman numeral conversion
        $romanMap = ['i'=>'1','ii'=>'2','iii'=>'3','iv'=>'4','v'=>'5','vi'=>'6','vii'=>'7','viii'=>'8','ix'=>'9','x'=>'10','xi'=>'11','xii'=>'12'];
        $cleanLower = strtolower($clean);
        if (isset($romanMap[$cleanLower])) {
            $clean = $romanMap[$cleanLower];
        }
        
        if (!empty($clean)) {
            $variations[] = $clean;
            $variations[] = "Class " . $clean;
            $variations[] = "Class-" . $clean;
            $variations[] = "Class" . $clean;
            
            if (is_numeric($clean)) {
                $num = (int)$clean;
                $variations[] = (string)$num;
                $variations[] = "Class " . $num;
                $variations[] = "Class-" . $num;
                $variations[] = "Class" . $num;
                $suffix = match($num) { 1=>'st', 2=>'nd', 3=>'rd', default=>'th' };
                $variations[] = $num . $suffix;
                $variations[] = "Class " . $num . $suffix;
                $variations[] = "Class-" . $num . $suffix;
            }
        }
    }
    $syllabusClasses = array_unique(array_filter($variations));
    if (empty($syllabusClasses)) $syllabusClasses = ['_NO_CLASS_'];
    $sylPlaceholders = implode(',', array_fill(0, count($syllabusClasses), '?'));

    if (true) {
        // Calculate overall progress: try chapters first, fall back to syllabus topics
        $chTotalStmt = $db->prepare("SELECT COUNT(*) FROM chapters WHERE class IN ($sylPlaceholders)");
        $chTotalStmt->execute(array_values($syllabusClasses));
        $chapTotal = (int)$chTotalStmt->fetchColumn();

        if ($chapTotal > 0) {
            $chDoneStmt = $db->prepare("SELECT COUNT(*) FROM chapters WHERE class IN ($sylPlaceholders) AND LOWER(status)='completed'");
            $chDoneStmt->execute(array_values($syllabusClasses));
            $chapDone = (int)$chDoneStmt->fetchColumn();
        } else {
            // Fallback to syllabus topics table
            $stTotalStmt = $db->prepare("SELECT COUNT(*) FROM syllabus WHERE class IN ($sylPlaceholders)");
            $stTotalStmt->execute(array_values($syllabusClasses));
            $chapTotal = (int)$stTotalStmt->fetchColumn();

            $stDoneStmt = $db->prepare("SELECT COUNT(*) FROM syllabus WHERE class IN ($sylPlaceholders) AND LOWER(status)='completed'");
            $stDoneStmt->execute(array_values($syllabusClasses));
            $chapDone = (int)$stDoneStmt->fetchColumn();
        }

        $syllabusPct = $chapTotal > 0 ? min(100, round(($chapDone / $chapTotal) * 100)) : 0;
    }

    // 2. Mentor Details (Include phone)
    $mentor_id = $s['mentor_id'] ?? null;
    if ($mentor_id) {
        $mStmt = $db->prepare("SELECT id, name, email, phone FROM users WHERE id=? AND role='mentor'");
        $mStmt->execute([$mentor_id]);
        $mentor = $mStmt->fetch();
    }

    // 3. Quiz Leaderboard & Recent Quizzes
    $ldrStmt = $db->prepare("
        SELECT st.id, st.name, st.gender, COALESCE(SUM(sqa.score),0) as total_score
        FROM students st
        LEFT JOIN student_quiz_attempts sqa ON sqa.student_id = st.id
        WHERE st.class = ?
        GROUP BY st.id
        ORDER BY total_score DESC
        LIMIT 5
    ");
    $ldrStmt->execute([$class]);
    $leaderboard = $ldrStmt->fetchAll();

    // Recent Quiz Attempts
    $rqStmt = $db->prepare("
        SELECT sqa.*, q.title as quiz_title, q.total_marks
        FROM student_quiz_attempts sqa
        LEFT JOIN quizzes q ON sqa.quiz_id = q.id
        WHERE sqa.student_id = ?
        ORDER BY sqa.submitted_at DESC
        LIMIT 3
    ");
    $rqStmt->execute([$sid]);
    $recentQuizzes = $rqStmt->fetchAll();

    // 4. Weekly Timetable (Standard 7-day display)
    $dayList = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    foreach($dayList as $day) $weeklyTimetable[$day] = [];
    
    $ttSql = "SELECT t.*, u.name as teacher_name FROM timetable t LEFT JOIN users u ON u.id=t.teacher_id WHERE 1=1";
    $ttParams = [];
    if ($s['batch_id']) {
        $ttSql .= " AND (t.batch_id=? OR t.class=?)";
        $ttParams[] = $s['batch_id'];
        $ttParams[] = $s['class'];
    } elseif ($s['class']) {
        $ttSql .= " AND t.class=?";
        $ttParams[] = $s['class'];
    }
    $ttSql .= " ORDER BY start_time";
    $ttStmt = $db->prepare($ttSql);
    $ttStmt->execute($ttParams);
        foreach($ttStmt->fetchAll() as $slot) {
        $d = ucfirst(strtolower($slot['day']));
        if(isset($weeklyTimetable[$d])) $weeklyTimetable[$d][] = $slot;
    }

    // 5. Check for active Live Class
    if ($s['batch_id'] && $class) {
        // Query for classes scheduled today around current time
        $lvStmt = $db->prepare("SELECT lc.*, u.name as teacher_name 
                               FROM live_classes lc 
                               LEFT JOIN users u ON u.id = lc.created_by 
                               WHERE (lc.batch_id = ? OR lc.class = ?) AND lc.status = 'active' 
                               AND lc.scheduled_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                               AND lc.scheduled_at <= DATE_ADD(NOW(), INTERVAL 2 HOUR)
                               LIMIT 1");
        $lvStmt->execute([$s['batch_id'], $class]);
        $liveClass = $lvStmt->fetch();
    }

    // 6. "Proper Awesome" Metrics
    // Total Sessions (using user_sessions as a proxy for engagement)
    $sessStmt = $db->prepare("SELECT COUNT(*) FROM user_sessions WHERE user_id=? AND role='student'");
    $sessStmt->execute([$sid]);
    $totalSessions = (int)$sessStmt->fetchColumn();

    // Doubt Queries
    $totalDoubtStmt = $db->prepare("SELECT COUNT(*) FROM doubt_sessions WHERE student_id=?");
    $totalDoubtStmt->execute([$sid]);
    $totalDoubts = (int)$totalDoubtStmt->fetchColumn();

    $ansDoubtStmt = $db->prepare("SELECT COUNT(*) FROM doubt_sessions WHERE student_id=? AND status='Completed'");
    $ansDoubtStmt->execute([$sid]);
    $answeredDoubts = (int)$ansDoubtStmt->fetchColumn();

        // Top Subject (based on completion %): try chapters, then fall back to syllabus
    $topSubjStmt = $db->prepare("
        SELECT subject, ROUND((COUNT(CASE WHEN LOWER(status)='completed' THEN 1 END) * 100.0) / COUNT(*)) as pct
        FROM chapters 
        WHERE class IN ($sylPlaceholders)
        GROUP BY subject 
        ORDER BY pct DESC 
        LIMIT 1
    ");
    $topSubjStmt->execute(array_values($syllabusClasses));
    $topSubjectData = $topSubjStmt->fetch();
    if (!$topSubjectData) {
        // Fallback to syllabus topics
        $topSubjStmt2 = $db->prepare("
            SELECT subject, ROUND((COUNT(CASE WHEN LOWER(status)='completed' THEN 1 END) * 100.0) / COUNT(*)) as pct
            FROM syllabus 
            WHERE class IN ($sylPlaceholders)
            GROUP BY subject 
            ORDER BY pct DESC 
            LIMIT 1
        ");
        $topSubjStmt2->execute(array_values($syllabusClasses));
        $topSubjectData = $topSubjStmt2->fetch();
    }
    $topSubject = $topSubjectData ? $topSubjectData['subject'] : 'Learning';

    // Per-subject syllabus progress for Syllabus Tracker widget
    // Try chapters first, fall back to syllabus topics
    $spStmt = $db->prepare("
        SELECT subject, 
               COUNT(*) as total, 
               COUNT(CASE WHEN LOWER(status)='completed' THEN 1 END) as done
        FROM chapters 
        WHERE class IN ($sylPlaceholders) 
        GROUP BY subject 
        ORDER BY subject
    ");
    $spStmt->execute(array_values($syllabusClasses));
    $subjectProgress = $spStmt->fetchAll();

    // If chapters returned nothing, fall back to syllabus topics
    if (empty($subjectProgress)) {
        $spStmt2 = $db->prepare("
            SELECT subject, 
                   COUNT(*) as total, 
                   COUNT(CASE WHEN LOWER(status)='completed' THEN 1 END) as done
            FROM syllabus 
            WHERE class IN ($sylPlaceholders) 
            GROUP BY subject 
            ORDER BY subject
        ");
        $spStmt2->execute(array_values($syllabusClasses));
        $subjectProgress = $spStmt2->fetchAll();
    }

    // Add ongoing topic (latest from class logs)
    foreach ($subjectProgress as &$sp) {
        $logStmt = $db->prepare("SELECT topic_taught FROM teacher_class_log WHERE class IN ($sylPlaceholders) AND subject=? ORDER BY date DESC, id DESC LIMIT 1");
        $args = array_values($syllabusClasses);
        $args[] = $sp['subject'];
        $logStmt->execute($args);
        $log = $logStmt->fetch();
        $sp['ongoing_topic'] = $log ? $log['topic_taught'] : 'Not started yet';
    }
    unset($sp);

    // 7. Upcoming Quizzes
    $upcomingQuizzes = [];
    $aqStmt = $db->prepare("
        SELECT q.* FROM quizzes q 
        WHERE q.status='published' 
        AND q.id NOT IN (SELECT quiz_id FROM student_quiz_attempts WHERE student_id=?)
        ORDER BY q.created_at DESC LIMIT 3
    ");
    $aqStmt->execute([$sid]);
    $upcomingQuizzes = $aqStmt->fetchAll();

} catch (Exception $e) {
    // Fail gracefully
}


require_once '_dashboard_unified.php';
exit;
?>
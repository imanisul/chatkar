<?php
require_once '../student/auth.php';
requireStudentLogin();

$pageTitle = 'My Homework';
$db = getDB();
$student = currentStudent();
$sid = $student['id'];

// Get student class variations
$classesToSearch = getStudentClassVariations($sid, $db);
$inPlaceholders = implode(',', array_fill(0, count($classesToSearch), '?'));

// Handle Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_homework'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        redirect("homework.php?msg=csrf_error");
    }
    $hwId = (int)$_POST['hw_id'];
    $subText = sanitize($_POST['submission_text'] ?? '');
    $subLink = trim($_POST['submission_link'] ?? '');
    if ($subLink && !filter_var($subLink, FILTER_VALIDATE_URL)) {
        $subLink = ''; // reject invalid URLs
    }
    $subLink = $subLink ? htmlspecialchars($subLink, ENT_QUOTES, 'UTF-8') : '';

    // Check if homework belongs to this class
    $chk = $db->prepare("SELECT id FROM homework WHERE id=? AND class_name IN ($inPlaceholders)");
    $chkParams = [$hwId];
    foreach($classesToSearch as $cls) $chkParams[] = $cls;
    $chk->execute($chkParams);
    if ($chk->fetch()) {
        $stmt = $db->prepare("INSERT INTO homework_submissions (homework_id, student_id, submission_text, submission_link, submitted_at, status) 
                              VALUES (?,?,?,?,NOW(),'Pending')
                              ON DUPLICATE KEY UPDATE submission_text=VALUES(submission_text), submission_link=VALUES(submission_link), submitted_at=NOW(), status='Pending'");
        $stmt->execute([$hwId, $sid, $subText, $subLink]);
        redirect("homework.php?msg=submitted");
    }
}

// Fetch Homework with student's submission status
$sql = "SELECT h.*, c.chapter_name, hs.status as sub_status, hs.submitted_at, hs.feedback, hs.submission_text, hs.submission_link
        FROM homework h 
        LEFT JOIN chapters c ON h.chapter_id = c.id 
        LEFT JOIN homework_submissions hs ON h.id = hs.homework_id AND hs.student_id = ?
        WHERE h.class_name IN ($inPlaceholders) AND h.status = 'published'
        ORDER BY h.due_date ASC, h.created_at DESC";
$stmt = $db->prepare($sql);
$stmtParams = [$sid];
foreach($classesToSearch as $cls) $stmtParams[] = $cls;
$stmt->execute($stmtParams);
$homeworks = $stmt->fetchAll();

$navActive = 'homework';
require_once '_nav.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Homework | HeyyGuru</title>
    <link rel="icon" href="../assets/img/favicon_hg.png?v=3.0" type="image/png">
    <link rel="shortcut icon" href="../assets/img/favicon_hg.png?v=3.0" type="image/png">
    <link rel="apple-touch-icon" href="../assets/img/favicon_hg.png?v=3.0">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="student.css?v=1.2">
    <style>
        .hw-card { display: flex; flex-direction: column; gap: 24px; padding: 20px !important; border-radius: 20px; }
        .hw-info { flex: 1 1 100% !important; min-width: 0 !important; }
        .hw-info h3 { word-break: break-word !important; font-size: 20px !important; }
        .hw-status-box { flex: 1 1 100% !important; padding: 20px !important; border-radius: 16px !important; }
        @media (min-width: 768px) {
            .hw-card { flex-direction: row; padding: 32px !important; }
            .hw-info h3 { font-size: 24px !important; }
            .hw-status-box { flex: 0 0 280px !important; }
        }

        /* Premium Hero Banner */
        .hw-hero {
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 30%, #4338ca 60%, #6366f1 100%);
            padding: 40px 40px 36px;
            margin-bottom: 32px;
            border-radius: 24px;
            position: relative;
            overflow: hidden;
        }
        .hw-hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(99,102,241,0.3) 0%, transparent 70%);
            border-radius: 50%;
            animation: hwFloat 6s ease-in-out infinite;
        }
        .hw-hero::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: 10%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(139,92,246,0.25) 0%, transparent 70%);
            border-radius: 50%;
            animation: hwFloat 8s ease-in-out infinite reverse;
        }
        @keyframes hwFloat {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-20px) scale(1.05); }
        }
        .hw-hero h1 {
            font-size: clamp(26px, 5vw, 36px);
            font-weight: 900;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 14px;
            z-index: 1;
            position: relative;
            color: #fff;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        .hw-hero p {
            margin: 10px 0 0 0;
            font-size: 15px;
            font-weight: 600;
            opacity: 0.85;
            z-index: 1;
            position: relative;
            color: #c7d2fe;
        }
        .hw-hero .hw-stats-row {
            display: flex;
            gap: 16px;
            margin-top: 20px;
            flex-wrap: wrap;
            z-index: 1;
            position: relative;
        }
        .hw-stat-pill {
            background: rgba(255,255,255,0.12);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.18);
            padding: 10px 20px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #fff;
            font-weight: 800;
            font-size: 13px;
            transition: all 0.3s;
        }
        .hw-stat-pill:hover { background: rgba(255,255,255,0.2); transform: translateY(-2px); }
        .hw-stat-pill .pill-num { font-size: 20px; font-weight: 900; }

        /* Premium Empty State */
        .hw-empty {
            text-align: center;
            padding: 80px 40px;
            background: linear-gradient(135deg, #fafbff 0%, #f0f4ff 50%, #eef2ff 100%);
            border-radius: 24px;
            border: 2px dashed #c7d2fe;
            position: relative;
            overflow: hidden;
        }
        .hw-empty::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: radial-gradient(circle at 50% 30%, rgba(99,102,241,0.06) 0%, transparent 60%);
        }
        .hw-empty-icon {
            width: 90px;
            height: 90px;
            margin: 0 auto 24px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border-radius: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 12px 40px rgba(99,102,241,0.25);
            animation: hwPulse 3s ease-in-out infinite;
        }
        @keyframes hwPulse {
            0%, 100% { transform: scale(1); box-shadow: 0 12px 40px rgba(99,102,241,0.25); }
            50% { transform: scale(1.06); box-shadow: 0 16px 50px rgba(99,102,241,0.35); }
        }
        .hw-empty h3 {
            font-weight: 900;
            font-size: 22px;
            color: #1e1b4b;
            margin: 0 0 10px 0;
            position: relative;
        }
        .hw-empty p {
            color: #6b7280;
            font-weight: 600;
            font-size: 15px;
            max-width: 400px;
            margin: 0 auto;
            line-height: 1.6;
            position: relative;
        }
    </style>
</head>
<body>
    <div class="s-main">
        <div class="hw-hero">
            <h1>
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14l2 2 4-4"></path></svg> 
                Homework
            </h1>
            <p>📝 Assignment tracking and submission portal</p>
            <div class="hw-stats-row">
                <?php
                    $totalHw = count($homeworks);
                    $pendingCount = 0; $submittedCount = 0; $checkedCount = 0;
                    foreach ($homeworks as $hw) {
                        $subStatus = $hw['sub_status'] ?? null;
                        if ($subStatus === 'Checked') $checkedCount++;
                        elseif ($subStatus === 'Submitted') $submittedCount++;
                        else $pendingCount++;
                    }
                ?>
                <div class="hw-stat-pill"><span class="pill-num"><?= $totalHw ?></span> Total</div>
                <div class="hw-stat-pill"><span class="pill-num" style="color:#fbbf24;"><?= $pendingCount ?></span> Pending</div>
                <div class="hw-stat-pill"><span class="pill-num" style="color:#34d399;"><?= $submittedCount ?></span> Submitted</div>
                <div class="hw-stat-pill"><span class="pill-num" style="color:#60a5fa;"><?= $checkedCount ?></span> Checked</div>
            </div>
        </div>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'submitted'): ?>
        <div
            style="display:flex; align-items:center; gap:10px; background: #dcfce7; color: #166534; padding: 15px 20px; border-radius: 12px; margin-bottom: 24px; font-weight: 800; border: 1.5px solid #bbf7d0;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            Homework submitted successfully!
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'csrf_error'): ?>
        <div
            style="display:flex; align-items:center; gap:10px; background: #fee2e2; color: #dc2626; padding: 15px 20px; border-radius: 12px; margin-bottom: 24px; font-weight: 800; border: 1.5px solid #fecaca;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
            Security verification failed. Please refresh and try again.
        </div>
        <?php endif; ?>

        <?php if (empty($homeworks)): ?>
        <div class="hw-empty">
            <div class="hw-empty-icon">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14l2 2 4-4"></path></svg>
            </div>
            <h3>No homework yet 🎉</h3>
            <p>Relax! Your teachers haven't assigned anything recently. Enjoy your free time and keep learning!</p>
        </div>
        <?php
else: ?>
            <style>
                .hw-card .hw-meta-badge { font-family: 'Plus Jakarta Sans', sans-serif; font-weight:800; font-size:12px; padding:6px 14px; border-radius:99px; letter-spacing:0.5px; text-transform:uppercase; box-shadow:0 4px 10px rgba(0,0,0,0.05); }
            </style>
            <?php foreach ($homeworks as $h):
        $isSubmitted = !empty($h['sub_status']);
        $isChecked = $h['sub_status'] === 'Checked';
        $isOverdue = !$isSubmitted && $h['due_date'] && strtotime($h['due_date']) < strtotime(date('Y-m-d'));
        
        // Colors & Theme logic
        $themeColor = $isChecked ? '#10b981' : ($isSubmitted ? '#0ea5e9' : ($isOverdue ? '#ef4444' : '#6366f1'));
        $bgLight    = $isChecked ? '#dcfce7' : ($isSubmitted ? '#e0f2fe' : ($isOverdue ? '#fee2e2' : '#e0e7ff'));
        $bgLighter  = $isChecked ? 'rgba(16,185,129,0.05)' : ($isSubmitted ? 'rgba(14,165,233,0.05)' : ($isOverdue ? 'rgba(239,68,68,0.05)' : 'rgba(99,102,241,0.05)'));
        
        $iconSvg = $isChecked 
            ? '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="'.$themeColor.'" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>' 
            : ($isSubmitted 
                ? '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="'.$themeColor.'" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 22h14"></path><path d="M5 2h14"></path><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"></path><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"></path></svg>' 
                : ($isOverdue 
                    ? '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="'.$themeColor.'" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>' 
                    : '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="'.$themeColor.'" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>'));
                    
        $statusText = $isChecked ? 'CHECKED' : ($isSubmitted ? 'SUBMITTED' : ($isOverdue ? 'LATE / OVERDUE' : 'PENDING'));
?>
            <div class="premium-glass-card hover-lift-strong hw-card" style="display:flex; flex-wrap:wrap; gap:24px; transition: all 0.3s ease; position: relative; overflow: hidden; border-left: 6px solid <?= $themeColor ?>;">
                <!-- Decorative background blob -->
                <div style="position: absolute; top: -50px; right: -50px; width: 150px; height: 150px; background: <?= $bgLight ?>; border-radius: 50%; opacity: 0.5; filter: blur(30px); pointer-events: none; z-index:0;"></div>
                
                <div class="hw-info" style="flex: 1; z-index: 1;">
                    <div class="hw-meta" style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; align-items: center;">
                        <span class="hw-meta-badge" style="background: <?= $themeColor ?>; color: #fff; box-shadow:0 4px 10px <?= $themeColor ?>40;">
                            <?= htmlspecialchars($h['subject_id']) ?>
                        </span>
                        <span style="background: #f1f5f9; color: #475569; padding: 5px 14px; border-radius: 20px; font-size: 13px; font-weight: 700; border: 1px solid #cbd5e1;">
                            <?= htmlspecialchars($h['chapter_name'] ?: 'General') ?>
                        </span>
                        <?php if ($h['due_date']): ?>
                        <span style="display: flex; align-items: center; justify-content: center; background: <?= $isOverdue ? '#fee2e2' : '#fff' ?>; border: 1px solid <?= $isOverdue ? '#f87171' : '#e2e8f0' ?>; padding: 5px 14px; border-radius: 20px; gap: 4px; font-size: 13px; font-weight: 700; color: <?= $isOverdue ? '#ef4444' : '#64748b' ?>;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> 
                            Due: <?= date('d M Y', strtotime($h['due_date'])) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    
                    <h3 style="font-size: 24px; font-weight: 900; color: #1e1b4b; margin: 0 0 16px 0; line-height: 1.4;">
                        <?= htmlspecialchars($h['title']) ?>
                    </h3>
                    
                    <div style="font-size: 14.5px; color: #475569; line-height: 1.6; margin-bottom: 24px; background: rgba(255,255,255,0.7); padding: 20px; border-radius: 16px; border: 1px solid rgba(0,0,0,0.05); box-shadow:inset 0 2px 4px rgba(0,0,0,0.02);">
                        <?= nl2br(sanitize($h['description'])) ?>
                    </div>

                    <?php if ($h['attachment_link']): ?>
                    <a href="<?= htmlspecialchars($h['attachment_link']) ?>" target="_blank"
                       style="display: inline-flex; align-items: center; gap: 8px; color: #4f46e5; background: #e0e7ff; padding: 10px 18px; border-radius: 10px; font-weight: 800; font-size: 14px; text-decoration: none; border: 1px solid #c7d2fe; transition: all 0.2s;"
                       onmouseover="this.style.background='#c7d2fe'" onmouseout="this.style.background='#e0e7ff'">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        Download Material
                    </a>
                    <?php endif; ?>

                    <?php if ($isChecked && $h['feedback']): ?>
                    <div style="margin-top: 24px; background: rgba(220,252,231,0.6); border: 1.5px solid rgba(16,185,129,0.3); padding: 20px; border-radius: 16px; position: relative;">
                        <span style="position:absolute; top:-12px; left:20px; background:linear-gradient(135deg, #10b981, #059669); color:#fff; font-size:11px; font-weight:900; padding:6px 12px; border-radius:12px; text-transform:uppercase; letter-spacing:0.5px; box-shadow:0 4px 10px rgba(16,185,129,0.3);">Teacher's Feedback</span>
                        <div style="color: #166534; font-size: 15px; font-weight: 700; line-height: 1.6; margin-top: 6px;">
                            <?= nl2br(sanitize($h['feedback'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="hw-status-box" style="background: <?= $bgLighter ?>; border: 2px dashed rgba(0,0,0,0.1); border-radius: 20px; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; z-index: 1;">
                    <div style="margin-bottom: 16px; filter: drop-shadow(0 4px 10px rgba(0,0,0,0.15)); display:flex; justify-content:center;"><?= $iconSvg ?></div>
                    <div style="font-weight: 900; color: <?= $themeColor ?>; letter-spacing: 1.5px; margin-bottom: 6px; font-size: 18px;">
                        <?= $statusText ?>
                    </div>
                    
                    <?php if ($isChecked): ?>
                        <div style="font-size: 13px; color: #059669; font-weight: 700; margin-bottom: 20px;">Excellent work!</div>
                        <button class="btn-submit" disabled style="background: #10b981; opacity: 0.7; cursor: not-allowed; width: 100%; border-radius: 10px; padding: 12px; font-weight: 800; font-size: 15px;">Completed</button>
                    <?php elseif ($isSubmitted): ?>
                        <div style="font-size: 13px; color: #0284c7; font-weight: 700; margin-bottom: 20px;">Waiting for teacher</div>
                        <button class="btn-submit" style="background: #fff; color: #0ea5e9; border: 2px solid #0ea5e9; width: 100%; border-radius: 10px; padding: 12px; font-weight: 800; font-size: 15px; transition: all 0.2s; box-shadow: 0 4px 6px -1px #e0f2fe;" onclick="showSubmissionModal('<?= $h['id']?>', '<?= addslashes($h['title'])?>')" onmouseover="this.style.background='#0ea5e9'; this.style.color='#fff'" onmouseout="this.style.background='#fff'; this.style.color='#0ea5e9'">Edit Submission</button>
                    <?php else: ?>
                        <?php if ($isOverdue): ?>
                            <div style="font-size: 13px; color: #dc2626; font-weight: 700; margin-bottom: 20px;">Submission is late</div>
                            <button class="btn-submit" style="background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; border: none; width: 100%; border-radius: 10px; padding: 12px; font-weight: 800; font-size: 15px; box-shadow: 0 4px 10px rgba(239,68,68,0.3); transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'" onclick="showSubmissionModal('<?= $h['id']?>', '<?= addslashes($h['title'])?>')">Submit Late</button>
                        <?php else: ?>
                            <div style="font-size: 13px; color: #6366f1; font-weight: 700; margin-bottom: 20px;">Ready to submit?</div>
                            <button class="btn-submit" style="background: linear-gradient(135deg, #6366f1, #4f46e5); color: #fff; border: none; width: 100%; border-radius: 10px; padding: 12px; font-weight: 800; font-size: 15px; box-shadow: 0 4px 10px rgba(99,102,241,0.3); transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'" onclick="showSubmissionModal('<?= $h['id']?>', '<?= addslashes($h['title'])?>')">Submit Work</button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php
    endforeach; ?>
        </div>
        <?php
endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════
         PREMIUM HOMEWORK SUBMISSION MODAL
         ═══════════════════════════════════════════════════ -->
    <style>
        /* Modal Overlay */
        .hw-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 900;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .hw-modal-overlay.active {
            display: block;
            opacity: 1;
        }

        /* Modal Container */
        .hw-modal {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.92);
            width: min(94vw, 520px);
            max-height: 90vh;
            background: #ffffff;
            border-radius: 28px;
            z-index: 901;
            box-shadow: 
                0 32px 80px rgba(0, 0, 0, 0.2),
                0 0 0 1px rgba(255, 255, 255, 0.1);
            overflow: hidden;
            opacity: 0;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            flex-direction: column;
        }
        .hw-modal.active {
            display: flex;
            opacity: 1;
            transform: translate(-50%, -50%) scale(1);
        }

        /* Modal Header */
        .hw-modal-header {
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 50%, #7c3aed 100%);
            padding: 28px 32px 24px;
            position: relative;
            overflow: hidden;
        }
        .hw-modal-header::before {
            content: '';
            position: absolute;
            top: -40px;
            right: -40px;
            width: 120px;
            height: 120px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 50%;
            pointer-events: none;
        }
        .hw-modal-header::after {
            content: '';
            position: absolute;
            bottom: -30px;
            left: -30px;
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.06);
            border-radius: 50%;
            pointer-events: none;
        }
        .hw-modal-header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            z-index: 1;
        }
        .hw-modal-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .hw-modal-icon {
            width: 48px;
            height: 48px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .hw-modal-title {
            font-size: 20px;
            font-weight: 900;
            color: #fff;
            margin: 0;
            letter-spacing: -0.3px;
        }
        .hw-modal-subtitle {
            font-size: 13px;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.75);
            margin-top: 2px;
        }
        .hw-modal-close {
            width: 36px;
            height: 36px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .hw-modal-close:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: scale(1.08);
        }

        /* Modal Body */
        .hw-modal-body {
            padding: 28px 32px 32px;
            overflow-y: auto;
            flex: 1;
        }

        /* Form Group */
        .hw-form-group {
            margin-bottom: 24px;
        }
        .hw-form-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 800;
            font-size: 13px;
            color: #1e1b4b;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .hw-form-label svg {
            color: #6366f1;
        }

        /* Textarea */
        .hw-textarea {
            width: 100%;
            padding: 16px 18px;
            border-radius: 16px;
            border: 2px solid #e2e8f0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 14px;
            font-weight: 500;
            color: #1e293b;
            outline: none;
            resize: vertical;
            min-height: 120px;
            background: #fafbff;
            line-height: 1.6;
            transition: all 0.25s ease;
        }
        .hw-textarea::placeholder {
            color: #94a3b8;
            font-weight: 500;
        }
        .hw-textarea:focus {
            border-color: #6366f1;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }

        /* URL Input */
        .hw-url-wrapper {
            position: relative;
        }
        .hw-url-input {
            width: 100%;
            padding: 16px 18px 16px 48px;
            border-radius: 16px;
            border: 2px solid #e2e8f0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 14px;
            font-weight: 500;
            color: #1e293b;
            outline: none;
            background: #fafbff;
            transition: all 0.25s ease;
        }
        .hw-url-input::placeholder {
            color: #94a3b8;
            font-weight: 500;
        }
        .hw-url-input:focus {
            border-color: #6366f1;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }
        .hw-url-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            pointer-events: none;
            transition: color 0.2s;
        }
        .hw-url-wrapper:focus-within .hw-url-icon {
            color: #6366f1;
        }
        .hw-url-hint {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin-top: 10px;
            padding: 12px 16px;
            background: linear-gradient(135deg, #eef2ff, #f5f3ff);
            border-radius: 12px;
            border: 1px solid #e0e7ff;
        }
        .hw-url-hint-text {
            font-size: 12px;
            font-weight: 600;
            color: #4338ca;
            line-height: 1.5;
        }

        /* Button Group */
        .hw-btn-group {
            display: flex;
            gap: 12px;
            margin-top: 8px;
        }
        .hw-btn-cancel {
            flex: 1;
            padding: 14px 20px;
            border-radius: 16px;
            background: #f1f5f9;
            color: #475569;
            border: 2px solid #e2e8f0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }
        .hw-btn-cancel:hover {
            background: #e2e8f0;
            border-color: #cbd5e1;
            transform: translateY(-1px);
        }
        .hw-btn-submit-main {
            flex: 2;
            padding: 14px 20px;
            border-radius: 16px;
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 50%, #7c3aed 100%);
            color: #fff;
            border: none;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 15px;
            font-weight: 900;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.3);
            letter-spacing: 0.3px;
        }
        .hw-btn-submit-main:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(99, 102, 241, 0.45);
        }
        .hw-btn-submit-main:active {
            transform: translateY(0);
        }

        /* Entry Animation */
        @keyframes hwModalSlideUp {
            from { opacity: 0; transform: translate(-50%, -46%) scale(0.94); }
            to { opacity: 1; transform: translate(-50%, -50%) scale(1); }
        }
        .hw-modal.active {
            animation: hwModalSlideUp 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        /* Responsive */
        @media (max-width: 600px) {
            .hw-modal-header { padding: 22px 20px 18px; }
            .hw-modal-body { padding: 22px 20px 26px; }
            .hw-modal-icon { width: 40px; height: 40px; border-radius: 12px; }
            .hw-modal-title { font-size: 17px; }
            .hw-btn-group { flex-direction: column; }
            .hw-btn-cancel, .hw-btn-submit-main { flex: unset; }
        }
    </style>

    <!-- Overlay -->
    <div class="hw-modal-overlay" id="hwOverlay" onclick="hideModal()"></div>

    <!-- Modal -->
    <div class="hw-modal" id="subModal">
        <!-- Header -->
        <div class="hw-modal-header">
            <div class="hw-modal-header-content">
                <div class="hw-modal-header-left">
                    <div class="hw-modal-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="12" y1="18" x2="12" y2="12"></line>
                            <line x1="9" y1="15" x2="15" y2="15"></line>
                        </svg>
                    </div>
                    <div>
                        <h2 class="hw-modal-title" id="modalTitle">Submit Homework</h2>
                        <div class="hw-modal-subtitle">Complete your assignment submission</div>
                    </div>
                </div>
                <button class="hw-modal-close" onclick="hideModal()" type="button" aria-label="Close">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Body -->
        <div class="hw-modal-body">
            <form method="POST" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="submit_homework" value="1">
                <input type="hidden" name="hw_id" id="modalHwId">

                <!-- Answer Field -->
                <div class="hw-form-group">
                    <label class="hw-form-label">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                        </svg>
                        Your Answer / Notes
                    </label>
                    <textarea name="submission_text" class="hw-textarea" rows="5" placeholder="Write your answers, explanations, or notes here..."></textarea>
                </div>

                <!-- Drive Link Field -->
                <div class="hw-form-group">
                    <label class="hw-form-label">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                        </svg>
                        File / Drive Link
                    </label>
                    <div class="hw-url-wrapper">
                        <div class="hw-url-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="2" y1="12" x2="22" y2="12"></line>
                                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                            </svg>
                        </div>
                        <input type="url" name="submission_link" class="hw-url-input" placeholder="https://drive.google.com/file/d/...">
                    </div>
                    <div class="hw-url-hint">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0; margin-top:1px;">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="16" x2="12" y2="12"></line>
                            <line x1="12" y1="8" x2="12.01" y2="8"></line>
                        </svg>
                        <span class="hw-url-hint-text">Upload your assignment to Google Drive, then paste the shareable link above. Make sure 'General Access' is set to <strong>"Anyone with the link"</strong>.</span>
                    </div>
                </div>

                <!-- Local File Upload Field -->
                <div class="hw-form-group" style="margin-top:24px;">
                    <label class="hw-form-label" style="text-transform:none; font-size:12px; color:#64748b;">
                        Or upload a local file directly:
                    </label>
                    <div style="border:2px dashed #cbd5e1; border-radius:16px; padding:20px; text-align:center; background:#fafbff; transition:all 0.2s;" ondragover="this.style.borderColor='#6366f1';this.style.background='#f0f5ff';" ondragleave="this.style.borderColor='#cbd5e1';this.style.background='#fafbff';">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:8px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                        <div style="font-size:14px; font-weight:700; color:#1e1b4b; margin-bottom:4px;">Drag & Drop your file here</div>
                        <div style="font-size:12px; color:#64748b; font-weight:600; margin-bottom:12px;">(PDF, JPG, PNG up to 10MB)</div>
                        <input type="file" name="submission_file" style="display:none;" id="hwFileInput" accept=".pdf,.jpg,.jpeg,.png">
                        <button type="button" onclick="document.getElementById('hwFileInput').click()" style="background:#fff; border:1px solid #cbd5e1; padding:8px 16px; border-radius:10px; font-weight:800; font-size:12px; color:#475569; cursor:pointer;" onmouseover="this.style.borderColor='#6366f1';this.style.color='#6366f1'" onmouseout="this.style.borderColor='#cbd5e1';this.style.color='#475569'">Browse Files</button>
                    </div>
                </div>

                <!-- Buttons -->
                <div class="hw-btn-group">
                    <button type="button" class="hw-btn-cancel" onclick="hideModal()">Cancel</button>
                    <button type="submit" class="hw-btn-submit-main">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="22" y1="2" x2="11" y2="13"></line>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                        </svg>
                        Submit Now
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showSubmissionModal(id, title) {
            document.getElementById('modalHwId').value = id;
            document.getElementById('modalTitle').textContent = "Submit: " + title;
            const modal = document.getElementById('subModal');
            const overlay = document.getElementById('hwOverlay');
            overlay.classList.add('active');
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function hideModal() {
            const modal = document.getElementById('subModal');
            const overlay = document.getElementById('hwOverlay');
            modal.style.transition = 'all 0.25s ease';
            modal.style.opacity = '0';
            modal.style.transform = 'translate(-50%, -46%) scale(0.94)';
            overlay.style.transition = 'opacity 0.25s ease';
            overlay.style.opacity = '0';
            setTimeout(() => {
                modal.classList.remove('active');
                overlay.classList.remove('active');
                modal.style.transition = '';
                modal.style.opacity = '';
                modal.style.transform = '';
                overlay.style.transition = '';
                overlay.style.opacity = '';
                document.body.style.overflow = '';
            }, 250);
        }
        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') hideModal();
        });
    </script>

</body>

</html>
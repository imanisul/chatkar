<?php
require_once '../student/auth.php';
requireStudentLogin();
$_csrfToken = generateCsrfToken();

$db = getDB();
$student = currentStudent();
$sid = $student['id'];
$s = $db->prepare("SELECT * FROM students WHERE id=?");
$s->execute([$sid]);
$s = $s->fetch();
$gender = strtolower($s['gender'] ?? 'male');

$quizId = (int)($_GET['id'] ?? 0);

// ── Submit quiz ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quiz'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        redirect("quiz.php?csrf_error=1");
    }
    $quizId = (int)$_POST['quiz_id'];

    $questions = $db->prepare("SELECT * FROM quiz_questions WHERE quiz_id=? ORDER BY sort_order");
    $questions->execute([$quizId]);
    $questions = $questions->fetchAll();

    $score = 0;
    $correctCount = 0;
    $incorrectCount = 0;
    $timeTaken = max(0, (int)($_POST['time_taken'] ?? 0));

    foreach ($questions as $q) {
        $ans = strtolower($_POST['q' . $q['id']] ?? '');
        if ($ans === strtolower($q['correct_answer'])) {
            $score += (int)$q['marks'];
            $correctCount++;
        }
        else {
            $incorrectCount++;
        }
    }

    $quiz = $db->prepare("SELECT * FROM quizzes WHERE id=?");
    $quiz->execute([$quizId]);
    $quiz = $quiz->fetch();

    try {
        $db->prepare("INSERT INTO student_quiz_attempts
            (quiz_id,student_id,answers,score,total_marks,correct_count,incorrect_count,time_taken_seconds,submitted_at)
            VALUES (?,?,?,?,?,?,?,?,NOW())")
            ->execute([$quizId, $sid, json_encode($_POST), $score, $quiz['total_marks'], $correctCount, $incorrectCount, $timeTaken]);
    }
    catch (\Exception $e) {
        $db->prepare("INSERT INTO student_quiz_attempts (quiz_id,student_id,answers,score,total_marks,submitted_at) VALUES (?,?,?,?,?,NOW())")
            ->execute([$quizId, $sid, json_encode($_POST), $score, $quiz['total_marks']]);
    }

    // Award Coins based on Quiz Type
    require_once '../includes/student_notifications.php';
    $coinAmt = match($quiz['quiz_type']) {
        'dpp'     => 5,
        'weekly'  => 10,
        'monthly' => 20,
        default   => 0
    };
    if ($coinAmt > 0) {
        awardCoins($db, $sid, $coinAmt, "Completed " . strtoupper($quiz['quiz_type']) . ": " . $quiz['title']);
    }

    // Phase 7: Send Quiz Completion Notification (In-app + Email)
    sendStudentNotification(
        $db, 
        $sid, 
        "✅ " . strtoupper($quiz['quiz_type']) . " Submitted!", 
        "You have successfully submitted your " . strtoupper($quiz['quiz_type']) . ": " . $quiz['title'] . ". You earned $coinAmt coins!", 
        'success', 
        true
    );

    // Streak log (Calls the robust updateStreak from notifications helper)
    updateStreak($db, $sid);

    $att = $db->prepare("SELECT id FROM student_quiz_attempts WHERE quiz_id=? AND student_id=? ORDER BY submitted_at DESC LIMIT 1");
    $att->execute([$quizId, $sid]);
    $attId = $att->fetchColumn();
    redirect("quiz.php?result=$attId");
}

// ── Full Result Page ─────────────────────────────────────────────────────────
if (isset($_GET['result'])) {
    $attId = (int)$_GET['result'];

    $attempt = $db->prepare("SELECT sqa.*, q.title as quiz_title, q.subject, q.duration_minutes
        FROM student_quiz_attempts sqa
        JOIN quizzes q ON q.id=sqa.quiz_id
        WHERE sqa.id=? AND sqa.student_id=?");
    $attempt->execute([$attId, $sid]);
    $attempt = $attempt->fetch();
    if (!$attempt)
        redirect('quiz.php');

    $qs = $db->prepare("SELECT * FROM quiz_questions WHERE quiz_id=? ORDER BY sort_order");
    $qs->execute([$attempt['quiz_id']]);
    $qs = $qs->fetchAll();

    $answersRaw = json_decode($attempt['answers'] ?? '{}', true);
    $correctCount = (int)($attempt['correct_count'] ?? 0);
    $incorrectCount = (int)($attempt['incorrect_count'] ?? 0);
    if ($correctCount === 0 && $incorrectCount === 0) {
        foreach ($qs as $q) {
            $given = strtolower($answersRaw['q' . $q['id']] ?? '');
            if ($given === strtolower($q['correct_answer']))
                $correctCount++;
            else
                $incorrectCount++;
        }
    }

    $score = (int)$attempt['score'];
    $total = (int)$attempt['total_marks'];
    $pct = $total ? round($score / $total * 100) : 0;
    $grade = $pct >= 90 ? 'A+' : ($pct >= 80 ? 'A' : ($pct >= 70 ? 'B+' : ($pct >= 60 ? 'B' : ($pct >= 50 ? 'C' : 'F'))));
    $gradeGrad = $pct >= 70 ? '135deg,#16a34a,#15803d' : ($pct >= 50 ? '135deg,#0284c7,#b45309' : '135deg,#dc2626,#b91c1c');
    $gradeColor = $pct >= 70 ? '#16a34a' : ($pct >= 50 ? '#0284c7' : '#dc2626');
    $emoji = $pct >= 80 ? '<svg width="72" height="72" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="7"></circle><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"></polyline></svg>' : 
             ($pct >= 70 ? '<svg width="72" height="72" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>' : 
             ($pct >= 50 ? '<svg width="72" height="72" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"></path></svg>' : 
             '<svg width="72" height="72" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M16 16s-1.5-2-4-2-4 2-4 2"></path><line x1="9" y1="9" x2="9.01" y2="9"></line><line x1="15" y1="9" x2="15.01" y2="9"></line></svg>'));
    $timeSec = (int)($attempt['time_taken_seconds'] ?? 0);
    $timeStr = $timeSec > 0 ? floor($timeSec / 60) . 'm ' . ($timeSec % 60) . 's' : '—';

    $motivational = $pct >= 90
        ? ["Absolutely brilliant! You're on fire! 🔥", "Outstanding! Keep this momentum going! 🚀"]
        : ($pct >= 70
        ? ["Great work! Keep your streak alive. 💪", "Well done! Every class makes you sharper. 🌟"]
        : ($pct >= 50
        ? ["Good effort! Revise and come back stronger. 📚", "You're growing! Each attempt builds your skills. ⬆️"]
        : ["Don't give up! Practice makes perfect. 🎯", "Your best is yet to come. Keep studying! 📖"]));
    $motiveLine = $motivational[array_rand($motivational)];

    $streakCount = 0;
    try {
        $streakRow = $db->prepare("SELECT current_streak FROM student_streaks WHERE student_id=?");
        $streakRow->execute([$sid]);
        $streakCount = (int)($streakRow->fetchColumn() ?: 0);
    }
    catch (\Exception $e) {
    }

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Quiz Result – HeyyGuru</title>
    <link rel="icon" href="../assets/img/favicon_hg.png" type="image/png">
    <link rel="apple-touch-icon" href="../assets/img/favicon_hg.png">
    <link rel="stylesheet" href="student.css?v=1.2">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        /* BANNER */
        .result-banner {
            border-radius: 28px;
            overflow: hidden;
            margin-bottom: 24px;
            position: relative;
            background: linear-gradient(<?=$gradeGrad?>);
            color: #fff;
            padding: 40px 32px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .18)
        }

        .banner-emoji {
            font-size: 72px;
            margin-bottom: 12px;
            animation: floatUp 2s ease-in-out infinite;
            display: inline-block
        }

        @keyframes floatUp {

            0%,
            100% {
                transform: translateY(0)
            }

            50% {
                transform: translateY(-12px)
            }
        }

        .banner-grade {
            font-size: 80px;
            font-weight: 900;
            letter-spacing: -2px;
            line-height: 1;
            text-shadow: 0 4px 20px rgba(0, 0, 0, .2)
        }

        .banner-score {
            font-size: 24px;
            font-weight: 800;
            margin: 10px 0 4px;
            opacity: .95
        }

        .banner-pct {
            font-size: 15px;
            opacity: .8;
            font-weight: 700
        }

        .score-bar-wrap {
            background: rgba(255, 255, 255, .2);
            border-radius: 99px;
            height: 12px;
            margin: 16px auto 0;
            max-width: 340px;
            overflow: hidden
        }

        .score-bar-fill {
            height: 100%;
            border-radius: 99px;
            background: #fff;
            transition: width 1.5s cubic-bezier(.34, 1.56, .64, 1);
            width: 0
        }

        /* STATS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(155px, 1fr));
            gap: 14px;
            margin-bottom: 24px
        }

        .stat-card {
            background: #fff;
            border-radius: 20px;
            padding: 18px 16px;
            text-align: center;
            box-shadow: 0 4px 16px rgba(59, 150, 233, .07);
            border: 1.5px solid #e8eaf6;
            transition: transform .2s
        }

        .stat-card:hover {
            transform: translateY(-3px)
        }

        .stat-icon {
            font-size: 30px;
            margin-bottom: 8px
        }

        .stat-value {
            font-size: 22px;
            font-weight: 900;
            line-height: 1.1
        }

        .stat-label {
            font-size: 11.5px;
            font-weight: 700;
            color: #94a3b8;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: .5px
        }

        /* MOTIVATE */
        .motivate-band {
            background: linear-gradient(90deg, rgba(59, 150, 233, .07), rgba(124, 58, 237, .07));
            border: 1.5px solid #c7d2fe;
            border-radius: 16px;
            padding: 14px 20px;
            margin-bottom: 24px;
            text-align: center;
            font-size: 15px;
            font-weight: 800;
            color: #4338ca
        }

        /* ACTIONS */
        .actions-bar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 24px
        }

        .btn-result {
            flex: 1;
            min-width: 155px;
            padding: 16px;
            border-radius: 16px;
            font-size: 14.5px;
            font-weight: 900;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            border: none;
            font-family: 'Nunito', sans-serif;
            transition: all .25s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px
        }

        .btn-primary-r {
            background: linear-gradient(135deg, #4DA2FF, #7c3aed);
            color: #fff;
            box-shadow: 0 8px 24px rgba(59, 150, 233, .3)
        }

        .btn-primary-r:hover {
            transform: translateY(-3px);
            box-shadow: 0 14px 32px rgba(59, 150, 233, .4)
        }

        .btn-secondary-r {
            background: #fff;
            color: #4338ca;
            border: 2px solid #c7d2fe
        }

        .btn-secondary-r:hover {
            background: #eef2ff;
            transform: translateY(-3px)
        }

        .btn-green-r {
            background: linear-gradient(135deg, #16a34a, #15803d);
            color: #fff;
            box-shadow: 0 8px 24px rgba(22, 163, 74, .3)
        }

        .btn-green-r:hover {
            transform: translateY(-3px);
            box-shadow: 0 14px 28px rgba(22, 163, 74, .4)
        }

        /* REVIEW */
        .review-section {
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 4px 16px rgba(59, 150, 233, .07);
            border: 1.5px solid #e8eaf6;
            overflow: hidden;
            margin-bottom: 24px
        }

        .review-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1.5px solid #f1f5f9;
            cursor: pointer;
            user-select: none
        }

        .review-header h3 {
            font-size: 16px;
            font-weight: 900;
            display: flex;
            align-items: center;
            gap: 8px
        }

        .chevron {
            font-size: 18px;
            transition: transform .3s
        }

        .chevron.open {
            transform: rotate(180deg)
        }

        .review-body {
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 14px
        }

        .q-review-card {
            border: 1.5px solid #e8eaf6;
            border-radius: 16px;
            overflow: hidden
        }

        .q-review-head {
            padding: 12px 16px;
            font-weight: 800;
            font-size: 14px;
            line-height: 1.4;
            background: #f8fafc
        }

        .q-review-opts {
            padding: 10px 16px 12px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px
        }

        .opt-pill {
            padding: 8px 12px;
            border-radius: 10px;
            font-size: 12.5px;
            font-weight: 700;
            border: 1.5px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 6px
        }

        .opt-correct {
            background: #dcfce7;
            border-color: #86efac;
            color: #15803d
        }

        .opt-wrong {
            background: #fee2e2;
            border-color: #fca5a5;
            color: #b91c1c
        }

        .opt-neutral {
            background: #f1f5f9;
            color: #64748b
        }

        /* STREAK */
        .streak-block {
            background: linear-gradient(135deg, #1e1b4b, #312e81);
            border-radius: 24px;
            padding: 20px 24px;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 16px
        }

        .streak-fire {
            font-size: 48px
        }

        .streak-text h4 {
            font-size: 20px;
            font-weight: 900
        }

        .streak-text p {
            font-size: 13px;
            opacity: .75;
            font-weight: 700;
            margin-top: 2px
        }

        .streak-badge {
            margin-left: auto;
            background: rgba(255, 255, 255, .15);
            border-radius: 14px;
            padding: 10px 18px;
            text-align: center;
            flex-shrink: 0
        }

        .streak-badge span {
            display: block;
            font-size: 32px;
            font-weight: 900;
            line-height: 1
        }

        .streak-badge small {
            font-size: 11px;
            opacity: .75;
            font-weight: 700
        }

        @media(max-width:600px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr)
            }

            .q-review-opts {
                grid-template-columns: 1fr
            }

            .actions-bar {
                flex-direction: column
            }

            .btn-result {
                min-width: 100%
            }
        }
    </style>
</head>

<body>
    <?php $navActive = 'quiz'; require_once '_nav.php'; ?>
    <div class="s-main">
        <div class="result-wrap">

            <!-- BANNER -->
            <div class="result-banner">
                <div class="banner-emoji">
                    <?= $emoji?>
                </div>
                <div class="banner-grade">
                    <?= $grade?>
                </div>
                <div class="banner-score">
                    <?= $score?> /
                    <?= $total?> Marks
                </div>
                <div class="banner-pct">
                    <?= $pct?>% ·
                    <?= htmlspecialchars($attempt['quiz_title'])?>
                </div>
                <div class="score-bar-wrap">
                    <div class="score-bar-fill" id="scoreBar"></div>
                </div>
            </div>

            <!-- MOTIVATIONAL -->
            <div class="motivate-band"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-4px; margin-right:6px;"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"></path></svg>
                <?= htmlspecialchars($motiveLine)?>
            </div>

            <!-- STATS -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg></div>
                    <div class="stat-value" style="color:#16a34a">
                        <?= $correctCount?>
                    </div>
                    <div class="stat-label">Correct</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg></div>
                    <div class="stat-value" style="color:#dc2626">
                        <?= $incorrectCount?>
                    </div>
                    <div class="stat-label">Incorrect</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#eab308" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></div>
                    <div class="stat-value" style="font-size:18px">
                        <?= $timeStr?>
                    </div>
                    <div class="stat-label">Time Taken</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#4DA2FF" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg></div>
                    <div class="stat-value" style="font-size:16px">
                        <?= htmlspecialchars($attempt['subject'] ?: '—')?>
                    </div>
                    <div class="stat-label">Subject</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg></div>
                    <div class="stat-value" style="font-size:14px">
                        <?= date('d M Y', strtotime($attempt['submitted_at'] ?? 'now'))?>
                    </div>
                    <div class="stat-label">Attempted</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="<?= $gradeColor?>" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="7"></circle><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"></polyline></svg></div>
                    <div class="stat-value" style="color:<?= $gradeColor?>">
                        <?= $pct?>%
                    </div>
                    <div class="stat-label">Score %</div>
                </div>
            </div>

            <!-- ACTIONS -->
            <div class="actions-bar">
                <button class="btn-result btn-secondary-r" onclick="toggleReview()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg> Review Answers
                </button>
                <a href="dashboard.php" class="btn-result btn-primary-r">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg> Back to Dashboard
                </a>
                <a href="leaderboard.php" class="btn-result btn-green-r">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="7"></circle><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"></polyline></svg> Leaderboard
                </a>
            </div>

            <!-- ANSWER REVIEW ACCORDION -->
            <div class="review-section" id="reviewSection" style="display:none">
                <div class="review-header" onclick="toggleReview()">
                    <h3><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-4px;"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg> Answer Review
                        <span
                            style="background:#eef2ff;color:#4DA2FF;font-size:12px;padding:3px 10px;border-radius:99px;font-weight:800">
                            <?= count($qs)?> Questions
                        </span>
                    </h3>
                    <span class="chevron open" id="chevron">▾</span>
                </div>
                <div class="review-body">
                    <?php foreach ($qs as $i => $q):
        $given = strtolower($answersRaw['q' . $q['id']] ?? '');
        $correct = strtolower($q['correct_answer'] ?? 'a');
        $isRight = $given === $correct;
?>
                    <div class="q-review-card">
                        <div class="q-review-head" style="border-left:4px solid <?= $isRight ? '#16a34a' : '#dc2626'?>">
                            <span
                                style="font-size:11px;font-weight:800;padding:4px 10px;border-radius:99px; display:inline-flex; align-items:center; gap:4px;
                  background:<?= $isRight ? '#dcfce7' : '#fee2e2'?>;color:<?= $isRight ? '#15803d' : '#b91c1c'?>;margin-right:8px">
                                <?= $isRight ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Correct' : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg> Wrong'?>
                            </span>
                            <span style="color:#64748b; font-weight:900;">Q<?= $i + 1?>.</span>
                            <?= htmlspecialchars($q['question'])?>
                        </div>
                        <div class="q-review-opts">
                            <?php foreach (['a', 'b', 'c', 'd'] as $k):
            if (!$q['option_' . $k])
                continue;
            $isCorrectOpt = $k === $correct;
            $isStudentOpt = $k === $given;
            $cls = $isCorrectOpt ? 'opt-correct' : ($isStudentOpt && !$isCorrectOpt ? 'opt-wrong' : 'opt-neutral');
            $icon = $isCorrectOpt ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ($isStudentOpt && !$isCorrectOpt ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>' : '');
?>
                            <div class="opt-pill <?= $cls?>">
                                <?= $icon ? $icon . ' ' : ''?><strong>
                                    <?= strtoupper($k)?>.
                                </strong>
                                <?= htmlspecialchars($q['option_' . $k])?>
                            </div>
                            <?php
        endforeach; ?>
                        </div>
                    </div>
                    <?php
    endforeach; ?>
                </div>
            </div>

            <!-- STREAK BLOCK -->
            <div class="streak-block">
                <div class="streak-fire"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.292 1-3a2.5 2.5 0 0 0 2.5 2.5z"></path></svg></div>
                <div class="streak-text">
                    <h4>Keep Your Streak Alive!</h4>
                    <p>Study every day to build an unstoppable habit</p>
                </div>
                <div class="streak-badge">
                    <span>
                        <?= $streakCount?>
                    </span>
                    <small>DAY STREAK</small>
                </div>
            </div>

        </div><!-- end result-wrap -->

        <script>
            document.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => { const b = document.getElementById('scoreBar'); if (b) b.style.width = '<?= $pct?>%'; }, 300);
            });
            let reviewOpen = false;
            function toggleReview() {
                reviewOpen = !reviewOpen;
                const s = document.getElementById('reviewSection');
                const c = document.getElementById('chevron');
                s.style.display = reviewOpen ? 'block' : 'none';
                if (c) c.classList.toggle('open', reviewOpen);
                if (reviewOpen) s.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        </script>
</body>

</html>
<?php exit;
}

// ── Already attempted redirect ──────────────────────────────────────────────
if (isset($_GET['already'])) {
    header('Location: quiz.php?msg=already');
    exit;
}
if (isset($_GET['csrf_error'])) {
    header('Location: quiz.php?msg=csrf');
    exit;
}

// ── Load specific quiz for attempt ──────────────────────────────────────────
$activeQuiz = null;
$quizQuestions = [];
if ($quizId) {
    $aq = $db->prepare("SELECT * FROM quizzes WHERE id=? AND status='published'");
    $aq->execute([$quizId]);
    $activeQuiz = $aq->fetch();
    if ($activeQuiz) {
        $chk = $db->prepare("SELECT id,score,total_marks FROM student_quiz_attempts WHERE quiz_id=? AND student_id=?");
        $chk->execute([$quizId, $sid]);
        $prevAttempt = $chk->fetch();
        if (!$prevAttempt) {
            $qq = $db->prepare("SELECT * FROM quiz_questions WHERE quiz_id=? ORDER BY sort_order");
            $qq->execute([$quizId]);
            $quizQuestions = $qq->fetchAll();
        }
        else {
            $activeQuiz['prev_attempt'] = $prevAttempt;
        }
    }
}

// ── Quiz list ───────────────────────────────────────────────────────────────
$sql = "SELECT q.*, b.name as batch_name, (SELECT id FROM student_quiz_attempts WHERE quiz_id=q.id AND student_id=? ORDER BY submitted_at DESC LIMIT 1) as attempt_id
        FROM quizzes q LEFT JOIN batches b ON b.id=q.batch_id WHERE q.status='published'";
$params = [$sid];
if (!empty($s['batch_id'])) {
    $sql .= " AND (q.batch_id=? OR q.class=?)";
    $params[] = $s['batch_id'];
    $params[] = $s['class'];
} else if (!empty($s['class'])) {
    $sql .= " AND (q.class=?)";
    $params[] = $s['class'];
}
$sql .= " ORDER BY q.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$quizList = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Quizzes – HeyyGuru</title>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/img/favicon_hg.png" type="image/png">
    <link rel="apple-touch-icon" href="../assets/img/favicon_hg.png">
    <link rel="stylesheet" href="student.css?v=1.2">
</head>

<body>
    <?php $navActive = 'quiz'; require_once '_nav.php'; ?>
    <div class="s-main">
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'already'): ?>
        <div
            style="background:#fef3c7;border:1.5px solid #7dd3fc;border-radius:12px;padding:14px 20px;margin-bottom:20px;font-weight:700;color:#92400e">
            ⚠️ You have already attempted this quiz. Each quiz can only be attempted once.</div>
        <?php endif; ?>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'csrf'): ?>
        <div
            style="background:#fee2e2;border:1.5px solid #fca5a5;border-radius:12px;padding:14px 20px;margin-bottom:20px;font-weight:700;color:#dc2626">
            ⚠️ Security verification failed. Please refresh and try again.</div>
        <?php endif; ?>

        <?php if ($activeQuiz && empty($activeQuiz['prev_attempt'])): ?>
        <!-- ── Active Quiz Attempt ── -->
        <style>
        /* QUIZ UI REDESIGN */
        body { background: #f8fafc !important; } /* Soft background */
        
        .quiz-container { max-width: 900px; margin: 0 auto; padding-bottom: 60px; }
        
        .quiz-header-card {
            background: #fff; border-radius: 24px; padding: 28px 32px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.06); border: 1.5px solid #f1f5f9;
            margin-bottom: 32px;
            display: flex; justify-content: space-between; align-items: flex-start;
            position: sticky; top: 90px; z-index: 100;
        }
        
        .quiz-title-area { flex: 1; }
        .quiz-title-area h1 { font-size: 26px; font-weight: 900; margin: 0; color: #0f172a; letter-spacing: -0.5px; }
        .quiz-meta { font-size: 13.5px; font-weight: 700; color: #64748b; margin-top: 8px; display: flex; gap: 16px; flex-wrap: wrap; }
        
        .quiz-timer-badge {
            background: #fff0f2; color: #ef4444; border: 2px solid #ffe4e6;
            padding: 12px 24px; border-radius: 16px; font-size: 22px; font-weight: 900;
            display: flex; align-items: center; gap: 10px; box-shadow: 0 8px 20px rgba(239, 68, 68, 0.15);
            margin-left: 20px; transition: 0.3s;
        }
        .quiz-timer-badge.warning { background: #dc2626; color: #fff; border-color: #b91c1c; animation: pulseRed 1s infinite; }
        @keyframes pulseRed { 0% { opacity: 1; box-shadow: 0 0 20px rgba(220,38,38,0.5); } 50% { opacity: 0.6; box-shadow: 0 0 0px rgba(220,38,38,0); } 100% { opacity: 1; box-shadow: 0 0 20px rgba(220,38,38,0.5); } }
        
        .q-card-modern {
            background: #fff; border-radius: 20px; padding: 32px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1.5px solid #e2e8f0;
            margin-bottom: 28px; transition: transform 0.3s, border-color 0.3s;
        }
        .q-card-modern:hover { border-color: #cbd5e1; transform: translateY(-2px); }
        
        .q-card-head { display: flex; align-items: flex-start; gap: 14px; margin-bottom: 24px; }
        .q-num {
            background: #eef2ff; color: #4DA2FF; font-size: 18px; font-weight: 900;
            width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; border: 2px solid #e0e7ff;
        }
        .q-question-text { font-size: 19px; font-weight: 800; color: #1e293b; line-height: 1.5; padding-top: 8px; }
        
        .q-options-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 16px; padding-left: 62px;
        }
        @media (max-width: 600px) { 
            .q-options-grid { grid-template-columns: 1fr; padding-left: 0; } 
            .quiz-header-card { flex-direction: column; }
            .quiz-timer-badge { margin-left: 0; margin-top: 16px; width: 100%; justify-content: center; }
        }
        
        .opt-box {
            display: flex; align-items: center; gap: 14px; padding: 20px 24px;
            border: 2px solid #f1f5f9; border-radius: 16px; background: #fff;
            cursor: pointer; transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .opt-box:hover { border-color: #94a3b8; background: #f8fafc; }
        .opt-box input { display: none; }
        
        .opt-indicator {
            width: 26px; height: 26px; border-radius: 50%; border: 2.5px solid #cbd5e1;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
            background: #fff; transition: 0.2s;
        }
        .opt-indicator::after {
            content: ''; width: 12px; height: 12px; border-radius: 50%; background: transparent; transition: 0.2s;
        }
        .opt-text { font-size: 16px; font-weight: 700; color: #475569; line-height: 1.4; }
        .opt-letter { font-weight: 900; color: #94a3b8; margin-right: 8px; font-size: 14px; }
        
        /* Selection State */
        .opt-box.selected {
            border-color: #4DA2FF; background: #f0f7ff;
            box-shadow: 0 4px 20px rgba(77, 162, 255, 0.12);
        }
        .opt-box.selected .opt-indicator { border-color: #4DA2FF; background: #4DA2FF; }
        .opt-box.selected .opt-indicator::after { background: #fff; }
        .opt-box.selected .opt-text { color: #0f172a; font-weight: 800; }
        .opt-box.selected .opt-letter { color: #4DA2FF; }
        
        .submit-area {
            text-align: center; margin-top: 48px; margin-bottom: 20px;
        }
        .btn-huge-submit {
            background: linear-gradient(135deg, #10b981, #059669); color: #fff;
            border: none; padding: 22px 64px; border-radius: 99px;
            font-size: 22px; font-weight: 900; cursor: pointer;
            box-shadow: 0 10px 30px rgba(16, 185, 129, 0.3);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            display: inline-flex; align-items: center; gap: 12px;
        }
        .btn-huge-submit:hover { transform: translateY(-6px); box-shadow: 0 15px 40px rgba(16, 185, 129, 0.4); }
        
        /* Progress Indicator */
        .quiz-progress-wrap { height: 8px; background: #f1f5f9; border-radius: 99px; margin-top: 16px; overflow: hidden; }
        .quiz-progress-fill { height: 100%; background: linear-gradient(90deg, #6366f1, #4DA2FF); border-radius: 99px; width: 0%; transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .progress-text { margin-top: 16px; font-size: 15px; font-weight: 800; color: #4DA2FF; display: flex; align-items: center; gap: 8px; }
        </style>

        <div class="quiz-container">
            <div class="quiz-header-card">
                <div class="quiz-title-area">
                    <h1><?= htmlspecialchars($activeQuiz['title'])?></h1>
                    <div class="quiz-meta">
                        <span>📋 <?= count($quizQuestions)?> Questions</span>
                        <span>🎯 <?= $activeQuiz['total_marks']?> Marks</span>
                        <a href="quiz.php" style="color: #ef4444; text-decoration: none;">✕ Cancel</a>
                    </div>
                    <div class="progress-text">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        Progress: <span id="answeredCount">0</span> of <?= count($quizQuestions)?> Answered
                    </div>
                    <div class="quiz-progress-wrap">
                        <div class="quiz-progress-fill" id="quizProgressBar"></div>
                    </div>
                </div>
                <!-- Timer -->
                <div class="quiz-timer-badge" id="quizTimer">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <span id="timerDisplay"><?= $activeQuiz['duration_minutes']?>:00</span>
                </div>
            </div>

            <?php if ($activeQuiz['description']): ?>
            <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:16px;padding:18px 24px;margin-bottom:28px;font-weight:700;color:#1e40af;font-size:15px;display:flex;gap:12px;align-items:flex-start;">
                <span style="font-size:20px;">💡</span>
                <?= htmlspecialchars($activeQuiz['description'])?>
            </div>
            <?php endif; ?>

            <form method="POST" id="quizForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '')?>">
                <input type="hidden" name="submit_quiz" value="1">
                <input type="hidden" name="quiz_id" value="<?= $activeQuiz['id']?>">
                <input type="hidden" name="time_taken" id="timeTakenField" value="0">
                
                <?php foreach ($quizQuestions as $i => $q): ?>
                <div class="q-card-modern">
                    <div class="q-card-head">
                        <div class="q-num">Q<?= $i + 1?></div>
                        <div class="q-question-text"><?= nl2br(htmlspecialchars($q['question']))?></div>
                    </div>
                    <div class="q-options-grid">
                        <?php foreach (['a', 'b', 'c', 'd'] as $k):
                            if (!$q['option_' . $k]) continue; 
                        ?>
                        <label class="opt-box">
                            <input type="radio" name="q<?= $q['id']?>" value="<?= $k?>" required>
                            <div class="opt-indicator"></div>
                            <span class="opt-text">
                                <span class="opt-letter"><?= strtoupper($k)?>.</span>
                                <?= htmlspecialchars($q['option_' . $k])?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <div class="submit-area">
                    <button type="submit" class="btn-huge-submit" onclick="return confirm('Ready to submit? You cannot change answers after submission.')">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        Submit Quiz
                    </button>
                    <p style="color: #64748b; font-weight: 700; margin-top: 16px; font-size: 14px;">Please review all answers before submitting.</p>
                </div>
            </form>
        </div>

        <script>
            // Visual Interactions
            document.querySelectorAll('.opt-box').forEach(box => {
                box.addEventListener('click', function() {
                    const input = this.querySelector('input');
                    const groupName = input.name;
                    document.querySelectorAll(`input[name="${groupName}"]`).forEach(inp => {
                        inp.closest('.opt-box').classList.remove('selected');
                    });
                    this.classList.add('selected');
                    input.checked = true;

                    // Update Progress
                    const totalQ = <?= count($quizQuestions) ?>;
                    const answered = new Set();
                    document.querySelectorAll('input[type="radio"]:checked').forEach(inp => answered.add(inp.name));
                    document.getElementById('answeredCount').textContent = answered.size;
                    document.getElementById('quizProgressBar').style.width = (answered.size / totalQ * 100) + '%';
                });
            });

            // Timer Logic
            let totalSecs = <?= $activeQuiz['duration_minutes'] * 60 ?>;
            const maxSecs = totalSecs;
            const disp = document.getElementById('timerDisplay');
            const timer = document.getElementById('quizTimer');
            const timeTakenField = document.getElementById('timeTakenField');
            
            const iv = setInterval(() => {
                totalSecs--;
                const m = Math.floor(totalSecs / 60), s = totalSecs % 60;
                disp.textContent = m + ':' + (s < 10 ? '0' : '') + s;
                timeTakenField.value = maxSecs - totalSecs;
                
                if (totalSecs <= 60 && totalSecs > 0) {
                    timer.classList.add('warning');
                }
                if (totalSecs <= 0) { 
                    clearInterval(iv); 
                    disp.textContent = '0:00';
                    document.getElementById('quizForm').submit(); 
                }
            }, 1000);

            document.getElementById('quizForm').addEventListener('submit', () => {
                timeTakenField.value = maxSecs - totalSecs;
            });
        </script>

        <?php
elseif ($activeQuiz && $activeQuiz['prev_attempt']): ?>
        <!-- Already attempted -->
        <div
            style="background:#fff;border:1.5px solid var(--border);border-radius:var(--r);padding:30px;text-align:center">
            <div style="font-size:50px;margin-bottom:14px">✅</div>
            <h2 style="font-size:20px;font-weight:900">You already completed this quiz!</h2>
            <p style="color:#64748b;font-weight:700;margin:8px 0 16px">Score: <strong>
                    <?= $activeQuiz['prev_attempt']['score']?>/
                    <?= $activeQuiz['prev_attempt']['total_marks']?>
                </strong></p>
            <a href="quiz.php"
                style="background:var(--blue);color:#fff;padding:12px 28px;border-radius:12px;text-decoration:none;font-weight:800">←
                Back to Quizzes</a>
        </div>

        <?php
else: ?>
        <!-- ── Quiz List ── -->
        <div style="margin-bottom:24px">
            <h1 style="font-size:26px;font-weight:900;display:flex;align-items:center;gap:10px;"><svg width="28"
                    height="28" viewBox="0 0 24 24" fill="none" stroke="#4DA2FF" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <circle cx="12" cy="12" r="6"></circle>
                    <circle cx="12" cy="12" r="2"></circle>
                </svg> Quizzes</h1>
            <p style="color:#64748b;font-weight:700">Test your knowledge with timed MCQ quizzes</p>
        </div>

        <?php if (empty($quizList)): ?>
        <div
            style="text-align:center;padding:60px;background:#fff;border-radius:var(--r);border:1.5px solid var(--border)">
            <div style="margin-bottom:12px"><svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#9ca3af"
                    stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <circle cx="12" cy="12" r="6"></circle>
                    <circle cx="12" cy="12" r="2"></circle>
                </svg></div>
            <p style="font-weight:900;font-size:20px">No quizzes yet!</p>
            <p style="color:#9ca3af;font-weight:700">Your teacher will assign quizzes here soon.</p>
        </div>
        <?php
    else: ?>
        <div class="quiz-grid">
            <?php foreach ($quizList as $qz):
            $done = !empty($qz['attempt_id']);
            $isExpired = $qz['deadline'] && strtotime($qz['deadline']) < time();
?>
            <div class="quiz-card">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px">
                    <div style="font-size:36px; display:flex; align-items:center;">
                        <?= $done ? '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>' : '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#4DA2FF" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="6"></circle><circle cx="12" cy="12" r="2"></circle></svg>'?>
                    </div>
                    <span
                        style="padding:4px 12px;border-radius:99px;font-size:11px;font-weight:800;background:<?= $done ? '#dcfce7' : ($isExpired ? '#fee2e2' : '#eef2ff')?>;color:<?= $done ? '#16a34a' : ($isExpired ? '#dc2626' : '#4DA2FF')?>">
                        <?= $done ? 'Completed' : ($isExpired ? 'Expired' : 'Available')?>
                    </span>
                </div>
                <div style="font-weight:900;font-size:16px;margin-bottom:8px;line-height:1.3">
                    <?= htmlspecialchars($qz['title'])?>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
                    <?php if ($qz['subject']): ?><span
                        style="background:#eef2ff;color:#4DA2FF;font-size:11.5px;font-weight:800;padding:3px 10px;border-radius:8px">
                        <?= htmlspecialchars($qz['subject'])?>
                    </span>
                    <?php
            endif; ?>
                    <span
                        style="background:#f1f5f9;color:#64748b;font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:8px">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <?= $qz['duration_minutes']?> min
                    </span>
                    <span
                        style="background:#f1f5f9;color:#64748b;font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:8px">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                        <?= $qz['total_marks']?> marks
                    </span>
                    <?php if (in_array($qz['quiz_type'], ['weekly', 'monthly'])): ?>
                    <span
                        style="background:<?= $qz['quiz_type'] === 'weekly' ? '#fdf2f7' : '#f0fdf4' ?>;color:<?= $qz['quiz_type'] === 'weekly' ? '#9d174d' : '#166534' ?>;font-size:11.5px;font-weight:800;padding:3px 10px;border-radius:8px;text-transform:uppercase;border:1px solid currentColor">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"></path></svg>
                        <?= $qz['quiz_type'] ?>
                    </span>
                    <?php endif; ?>
                </div>
                <?php if ($qz['deadline']): ?>
                <p
                    style="font-size:12px;font-weight:700;color:<?= $isExpired ? '#dc2626' : '#64748b'?>;margin-bottom:12px">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <?= $isExpired ? 'Expired' : 'Due'?>:
                    <?= date('d M Y, h:i A', strtotime($qz['deadline']))?>
                </p>
                <?php
            endif; ?>
                <?php if ($done): ?>
                <a href="quiz.php?result=<?= $qz['attempt_id']?>"
                    style="display:block;background:#dcfce7;color:#16a34a;padding:12px;border-radius:12px;text-align:center;font-weight:900;text-decoration:none;transition:.2s; border: 1px solid #bbf7d0"
                    onmouseover="this.style.background='#bbf7d0'" onmouseout="this.style.background='#dcfce7'">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-4px"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg> View Results
                </a>
                <?php elseif (!$done && !$isExpired): ?>
                <a href="quiz.php?id=<?= $qz['id']?>"
                    style="display:block;background:linear-gradient(135deg,#4DA2FF,#7c3aed);color:#fff;padding:12px;border-radius:12px;text-align:center;font-weight:900;text-decoration:none;transition:.2s"
                    onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-4px"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg> Start Quiz
                </a>
                <?php
            else: ?>
                <div
                    style="background:#fee2e2;color:#dc2626;padding:12px;border-radius:12px;text-align:center;font-weight:900">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg> Deadline Passed</div>
                <?php
            endif; ?>
            </div>
            <?php
        endforeach; ?>
        </div>
        <?php
    endif; ?>
        <?php
endif; ?>
    </div>
</body>

</html>
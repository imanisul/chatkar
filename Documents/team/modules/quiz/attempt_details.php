<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
requireRole(['admin','mentor','teacher']);

$pageTitle = 'Attempt Review';
$db = getDB();
$attemptId = (int)($_GET['id'] ?? 0);
if (!$attemptId) redirect('index.php');

// Fetch attempt with defensive error handling
try {
    $stmt = $db->prepare("
        SELECT a.*, q.title as quiz_title, q.id as quiz_id, q.duration_minutes, q.total_marks,
               s.name as student_name, s.class as student_class
        FROM student_quiz_attempts a
        JOIN quizzes q ON q.id=a.quiz_id
        JOIN students s ON s.id=a.student_id
        WHERE a.id=?
    ");
    $stmt->execute([$attemptId]);
    $attempt = $stmt->fetch();
} catch (Exception $e) {
    $attempt = null;
}

if (!$attempt) redirect('index.php');

// Parse answers from JSON column (answers stored as JSON in student_quiz_attempts)
$answersRaw = $attempt['answers'] ?? null;
$studentAnswers = [];
if ($answersRaw) {
    $decoded = is_string($answersRaw) ? json_decode($answersRaw, true) : $answersRaw;
    if (is_array($decoded)) $studentAnswers = $decoded;
}

// Fetch all questions for this quiz (safe ORDER BY — no sort_order column dependency)
try {
    $qStmt = $db->prepare("
        SELECT id, question, option_a, option_b, option_c, option_d, correct_answer, marks
        FROM quiz_questions
        WHERE quiz_id = ?
        ORDER BY id ASC
    ");
    $qStmt->execute([$attempt['quiz_id']]);
    $questions = $qStmt->fetchAll();
} catch (Exception $e) {
    $questions = [];
}

// Build responses array by merging questions with student answers
$responses = [];
$correctCount = 0;
$wrongCount = 0;
$skippedCount = 0;
foreach ($questions as $q) {
    $qid = (string)$q['id'];
    $selected = $studentAnswers[$qid] ?? null;
    $isCorrect = $selected && strtolower(trim($selected)) === strtolower(trim($q['correct_answer'] ?? ''));
    $responses[] = array_merge($q, [
        'selected_option' => $selected,
        'is_correct'      => $isCorrect ? 1 : 0,
    ]);
    if (!$selected) $skippedCount++;
    elseif ($isCorrect) $correctCount++;
    else $wrongCount++;
}

$totalMarks = $attempt['total_marks'] ?? 0;
$score = $attempt['score'] ?? 0;
$pct = $totalMarks > 0 ? round($score / $totalMarks * 100) : 0;
$grade = $pct>=90?'A+':($pct>=80?'A':($pct>=70?'B+':($pct>=60?'B':($pct>=50?'C':'F'))));
$gradeColor = $pct>=60 ? '#10b981' : '#ef4444';
$submittedAt = $attempt['submitted_at'] ?? ($attempt['created_at'] ?? null);

$root = '../../';
require_once '../../includes/header.php';
?>

<div class="breadcrumb">
    <a href="index.php"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><circle cx="12" cy="12" r="10"></circle><path d="M12 8l4 4-4 4"></path><path d="M8 12h8"></path></svg> Quizzes</a>
    <span class="sep">/</span>
    <a href="attempts.php?quiz_id=<?= $attempt['quiz_id'] ?>">Attempts</a>
    <span class="sep">/</span>
    <span><?= sanitize($attempt['student_name']) ?></span>
</div>

<div class="page-header">
    <div class="page-header-left">
        <h1><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:8px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg> Attempt Review</h1>
        <p>Detailed question-by-question analysis</p>
    </div>
    <div class="page-header-actions">
        <a href="attempts.php?quiz_id=<?= $attempt['quiz_id'] ?>" class="btn btn-secondary"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> Back to Attempts</a>
    </div>
</div>

<style>
/* ── Premium Quiz Attempt Details ── */
.pq-container { max-width: 820px; margin: 0 auto; padding-bottom: 60px; }

/* ── Hero Header Card ── */
.pq-header {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    border-radius: 24px;
    padding: 32px;
    color: #fff;
    margin-bottom: 24px;
    box-shadow: 0 12px 40px rgba(0,0,0,0.18);
    position: relative;
    overflow: hidden;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
}
.pq-header::before {
    content: ''; position: absolute; right: -60px; top: -60px;
    width: 200px; height: 200px; background: rgba(99,102,241,0.08);
    border-radius: 50%; pointer-events: none;
}
.pq-header::after {
    content: ''; position: absolute; left: -40px; bottom: -40px;
    width: 160px; height: 160px; background: rgba(16,185,129,0.05);
    border-radius: 50%; pointer-events: none;
}
.pq-title { font-size: 24px; font-weight: 900; margin-bottom: 4px; letter-spacing: -0.5px; line-height: 1.2; }
.pq-sub { font-size: 12px; font-weight: 700; color: rgba(255,255,255,0.5); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; }
.pq-quiz-name { font-size: 14px; color: rgba(255,255,255,0.65); font-weight: 700; margin-bottom: 18px; }
.pq-meta { display: flex; flex-wrap: wrap; gap: 8px; font-size: 12px; font-weight: 700; color: rgba(255,255,255,0.8); }
.pq-meta-badge {
    display: flex; align-items: center; gap: 6px;
    background: rgba(255,255,255,0.08); padding: 6px 14px;
    border-radius: 99px; backdrop-filter: blur(4px);
    border: 1px solid rgba(255,255,255,0.06);
}

/* ── Score Circle ── */
.score-circle {
    width: 110px; height: 110px; flex-shrink: 0;
    border-radius: 50%;
    background: conic-gradient(<?= $gradeColor ?> <?= $pct ?>%, rgba(255,255,255,0.08) 0);
    display: flex; align-items: center; justify-content: center;
    position: relative;
    animation: scoreReveal 1s ease forwards;
}
@keyframes scoreReveal {
    from { opacity: 0; transform: scale(0.8) rotate(-90deg); }
    to { opacity: 1; transform: scale(1) rotate(0); }
}
.score-circle::before {
    content: ''; position: absolute; inset: 9px;
    border-radius: 50%; background: #0f172a;
}
.score-circle-val {
    position: relative; z-index: 1;
    font-size: 26px; font-weight: 900; font-family: 'Courier New', monospace;
}
.score-grade {
    position: absolute; bottom: -8px; left: 50%; transform: translateX(-50%);
    font-size: 11px; font-weight: 900; padding: 3px 12px;
    border-radius: 99px; z-index: 2;
    border: 2px solid #0f172a;
}

/* ── Summary Stats Bar ── */
.pq-stats {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 12px; margin-bottom: 24px;
}
.pq-stat {
    background: #fff; border-radius: 16px; padding: 18px 16px;
    text-align: center; border: 1.5px solid rgba(0,0,0,0.04);
    box-shadow: 0 4px 16px rgba(0,0,0,0.02);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.pq-stat:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.06); }
.pq-stat-num { font-size: 32px; font-weight: 900; font-family: 'Courier New', monospace; line-height: 1; }
.pq-stat-label { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-top: 4px; }

/* ── Legend ── */
.pq-legend {
    display: flex; gap: 18px; margin-bottom: 20px; flex-wrap: wrap;
    padding: 12px 16px; background: #f8fafc; border-radius: 14px;
    border: 1px solid #e2e8f0;
}
.pq-legend-item {
    display: flex; align-items: center; gap: 7px;
    font-size: 12px; font-weight: 800; color: #475569;
}
.pq-legend-dot {
    display: inline-block; width: 12px; height: 12px;
    border-radius: 4px;
}

/* ── Question Card ── */
.pq-card {
    background: #fff; border-radius: 20px;
    padding: 24px; margin-bottom: 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.02);
    border: 1.5px solid rgba(0,0,0,0.04);
    animation: cardSlideIn 0.4s ease forwards;
    opacity: 0; transform: translateY(10px);
    transition: transform 0.2s, box-shadow 0.2s;
}
.pq-card:hover { box-shadow: 0 8px 30px rgba(0,0,0,0.06); }
@keyframes cardSlideIn {
    to { opacity: 1; transform: translateY(0); }
}
.pq-card:nth-child(1) { animation-delay: 0.05s; }
.pq-card:nth-child(2) { animation-delay: 0.1s; }
.pq-card:nth-child(3) { animation-delay: 0.15s; }
.pq-card:nth-child(4) { animation-delay: 0.2s; }
.pq-card:nth-child(5) { animation-delay: 0.25s; }
.pq-card:nth-child(n+6) { animation-delay: 0.3s; }

.pq-card.correct-q { border-left: 5px solid #10b981; }
.pq-card.wrong-q { border-left: 5px solid #ef4444; }
.pq-card.skipped-q { border-left: 5px solid #94a3b8; }

.pq-q-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; gap: 12px; }
.pq-q-num {
    display: inline-flex; align-items: center; justify-content: center;
    width: 30px; height: 30px; border-radius: 10px; flex-shrink: 0;
    font-size: 13px; font-weight: 900; color: #fff;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
}
.pq-q-title { font-size: 15px; font-weight: 800; color: #1e293b; line-height: 1.6; flex: 1; }
.pq-q-marks {
    font-size: 11px; font-weight: 850;
    padding: 5px 12px; border-radius: 10px;
    white-space: nowrap; display: flex; align-items: center; gap: 4px;
    flex-shrink: 0;
}
.marks-earned { background: #dcfce7; color: #065f46; }
.marks-lost { background: #fee2e2; color: #991b1b; }
.marks-skipped { background: #f1f5f9; color: #64748b; }

/* ── Options Grid ── */
.pq-options { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
@media(max-width: 600px) { .pq-options { grid-template-columns: 1fr; } .pq-header { flex-direction: column; text-align: center; } .score-circle { margin: 0 auto; } .pq-stats { grid-template-columns: 1fr; } }
.pq-opt {
    padding: 14px 16px;
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    font-size: 14px; font-weight: 700;
    color: #475569;
    display: flex; align-items: center; gap: 12px;
    cursor: default;
    transition: all 0.25s ease;
}
.pq-opt.is-correct-ans {
    border-color: #10b981; background: #f0fdf4; color: #065f46;
}
.pq-opt.is-wrong-pick {
    border-color: #ef4444; background: #fef2f2; color: #991b1b;
}
.pq-opt-label {
    font-weight: 900; opacity: 0.5;
    width: 24px; height: 24px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    background: rgba(0,0,0,0.04); font-size: 12px;
}
.pq-opt.is-correct-ans .pq-opt-label { background: rgba(16,185,129,0.15); opacity: 1; }
.pq-opt.is-wrong-pick .pq-opt-label { background: rgba(239,68,68,0.15); opacity: 1; }

/* ── Empty State ── */
.pq-empty {
    text-align: center; padding: 60px 20px; color: #94a3b8;
    font-weight: 700; font-size: 15px;
}
.pq-empty-icon { font-size: 48px; margin-bottom: 12px; opacity: 0.4; }
</style>

<div class="pq-container">
    <!-- Hero Header -->
    <div class="pq-header">
        <div style="position:relative;z-index:1">
            <div class="pq-sub">Student Performance</div>
            <div class="pq-title">
                <?= sanitize($attempt['student_name']) ?>
                <span style="font-size:13px; opacity:0.45; font-weight:700">(<?= sanitize($attempt['student_class'] ?? '-') ?>)</span>
            </div>
            <div class="pq-quiz-name">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block;vertical-align:text-bottom;margin-right:4px"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="2"></circle></svg>
                <?= sanitize($attempt['quiz_title']) ?>
            </div>
            <div class="pq-meta">
                <div class="pq-meta-badge">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                    <?= $submittedAt ? date('d M Y, h:i A', strtotime($submittedAt)) : 'N/A' ?>
                </div>
                <div class="pq-meta-badge">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    <?= $score ?> / <?= $totalMarks ?> Scored
                </div>
                <div class="pq-meta-badge" style="background:<?= $pct>=60 ? 'rgba(16,185,129,0.15)' : 'rgba(239,68,68,0.15)' ?>; color:<?= $pct>=60 ? '#34d399' : '#f87171' ?>">
                    <?= $grade ?>
                </div>
            </div>
        </div>
        <div style="position:relative;z-index:1">
            <div class="score-circle">
                <div class="score-circle-val" style="color:<?= $pct>=60?'#34d399':'#f87171' ?>"><?= $pct ?>%</div>
            </div>
            <div class="score-grade" style="background:<?= $pct>=60?'#10b981':'#ef4444' ?>; color:#fff"><?= $grade ?></div>
        </div>
    </div>

    <!-- Summary Stats -->
    <?php if (!empty($responses)): ?>
    <div class="pq-stats">
        <div class="pq-stat" style="border-color: #bbf7d0">
            <div class="pq-stat-num" style="color:#10b981"><?= $correctCount ?></div>
            <div class="pq-stat-label">Correct</div>
        </div>
        <div class="pq-stat" style="border-color: #fecaca">
            <div class="pq-stat-num" style="color:#ef4444"><?= $wrongCount ?></div>
            <div class="pq-stat-label">Incorrect</div>
        </div>
        <div class="pq-stat" style="border-color: #cbd5e1">
            <div class="pq-stat-num" style="color:#94a3b8"><?= $skippedCount ?></div>
            <div class="pq-stat-label">Skipped</div>
        </div>
    </div>

    <!-- Legend -->
    <div class="pq-legend">
        <div class="pq-legend-item"><span class="pq-legend-dot" style="background:#10b981"></span> Correct Answer</div>
        <div class="pq-legend-item"><span class="pq-legend-dot" style="background:#ef4444"></span> Wrong Selection</div>
        <div class="pq-legend-item"><span class="pq-legend-dot" style="background:#94a3b8"></span> Not Attempted</div>
    </div>

    <!-- Question Cards -->
    <?php foreach ($responses as $idx => $q):
        $statusClass = 'skipped-q';
        if ($q['selected_option']) {
            $statusClass = $q['is_correct'] ? 'correct-q' : 'wrong-q';
        }
    ?>
        <div class="pq-card <?= $statusClass ?>">
            <div class="pq-q-header">
                <div class="pq-q-num"><?= $idx + 1 ?></div>
                <div class="pq-q-title">
                    <?= sanitize($q['question']) ?>
                </div>
                <?php if ($q['is_correct']): ?>
                    <div class="pq-q-marks marks-earned">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                        +<?= $q['marks'] ?? 1 ?>
                    </div>
                <?php elseif ($q['selected_option']): ?>
                    <div class="pq-q-marks marks-lost">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        0 / <?= $q['marks'] ?? 1 ?>
                    </div>
                <?php else: ?>
                    <div class="pq-q-marks marks-skipped">— Skipped</div>
                <?php endif; ?>
            </div>

            <div class="pq-options">
                <?php
                $opts = [
                    ['id'=>'a', 'label'=>'A', 'text'=>$q['option_a'] ?? ''],
                    ['id'=>'b', 'label'=>'B', 'text'=>$q['option_b'] ?? ''],
                    ['id'=>'c', 'label'=>'C', 'text'=>$q['option_c'] ?? ''],
                    ['id'=>'d', 'label'=>'D', 'text'=>$q['option_d'] ?? ''],
                ];
                foreach ($opts as $o):
                    if (empty($o['text'])) continue;
                    $correctAns = strtolower(trim($q['correct_answer'] ?? 'a'));
                    $isCorrectAns = ($correctAns === $o['id']);
                    $isStudentPick = ($q['selected_option'] && strtolower(trim($q['selected_option'])) === $o['id']);

                    $optClass = '';
                    if ($isCorrectAns) $optClass = 'is-correct-ans';
                    else if ($isStudentPick) $optClass = 'is-wrong-pick';
                ?>
                    <div class="pq-opt <?= $optClass ?>">
                        <span class="pq-opt-label"><?= $o['label'] ?></span>
                        <span style="flex:1"><?= sanitize($o['text']) ?></span>
                        <?php if ($isCorrectAns): ?>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="flex-shrink:0;color:#10b981"><polyline points="20 6 9 17 4 12"/></svg>
                        <?php elseif ($isStudentPick): ?>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="flex-shrink:0;color:#ef4444"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php else: ?>
        <div class="pq-card pq-empty">
            <div class="pq-empty-icon">
                <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
            </div>
            <p>No response data found for this attempt.</p>
            <p style="font-size:13px;color:#94a3b8;margin-top:8px">The student may not have answered any questions, or the quiz data is not yet available.</p>
        </div>
    <?php endif; ?>
</div>

<?php require_once '../../includes/footer.php'; ?>

<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
requireRole(['admin', 'mentor', 'teacher']);

$pageTitle = 'Smart Quiz Builder';
$db = getDB();
$user = currentUser();
$role = $user['role'];

// ── Fetch classes/subjects based on role ────────────────────────────────────
$batches = $db->query("SELECT id,name,class FROM batches WHERE status='active' ORDER BY name")->fetchAll();
$isTeacher = $role === 'teacher';

$teacherSubjects = [];
if ($isTeacher) {
    try {
        $ts2 = $db->prepare("
            SELECT DISTINCT subject FROM (
                SELECT bt.subject FROM batch_teachers bt
                WHERE bt.teacher_id = ? AND bt.subject IS NOT NULL AND bt.subject != ''
                UNION
                SELECT bs.subject FROM batch_subjects bs
                JOIN batch_teachers bt ON bt.batch_id = bs.batch_id
                WHERE bt.teacher_id = ? AND bs.subject IS NOT NULL AND bs.subject != ''
                UNION
                SELECT subject FROM timetable
                WHERE teacher_id = ? AND subject IS NOT NULL AND subject != ''
                UNION
                SELECT subject FROM teacher_subjects
                WHERE teacher_id = ? AND subject IS NOT NULL AND subject != ''
            ) AS teacher_subjs
            ORDER BY subject
        ");
        $ts2->execute([$user['id'], $user['id'], $user['id'], $user['id']]);
        $teacherSubjects = $ts2->fetchAll(PDO::FETCH_COLUMN);
    } catch(Exception $e) {}
}

$subjects = [];
if ($isTeacher && !empty($teacherSubjects)) {
    $subjects = $teacherSubjects;
} else {
    try {
        $subjects = $db->query("SELECT DISTINCT subject FROM syllabus WHERE subject IS NOT NULL ORDER BY subject")->fetchAll(PDO::FETCH_COLUMN);
    }
    catch (Exception $e) {
    }
    if (empty($subjects))
        $subjects = ['Maths', 'Science', 'English', 'Hindi', 'Social Science', 'Computer', 'Physics', 'Chemistry', 'Biology'];
}

// AJAX: Load topics for class+subject
if (isset($_GET['ajax_topics'])) {
    header('Content-Type: application/json');
    $class = $_GET['class'] ?? '';
    $subj = $_GET['subject'] ?? '';
    $topics = [];
    try {
        if ($subj === 'all' || !$subj) {
            $t = $db->prepare("SELECT MIN(id) as id, subject, topic FROM syllabus WHERE class=? AND topic IS NOT NULL GROUP BY subject, topic ORDER BY subject, topic");
            $t->execute([$class]);
            $rows = $t->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $topics[] = ['id' => $r['id'], 'text' => $r['subject'] . ' - ' . $r['topic']];
            }
        }
        else {
            $t = $db->prepare("SELECT MIN(id) as id, topic FROM syllabus WHERE class=? AND subject=? AND topic IS NOT NULL GROUP BY topic ORDER BY topic");
            $t->execute([$class, $subj]);
            $rows = $t->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $topics[] = ['id' => $r['id'], 'text' => $r['topic']];
            }
        }
    }
    catch (Exception $e) {
    }
    echo json_encode($topics);
    exit;
}

// AJAX: Parse pasted quiz text
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['parse_text'])) {
    @error_reporting(0);
    @ini_set('display_errors', 0);
    header('Content-Type: application/json');
    try {
        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Security token mismatch. Please refresh the page.');
        }
        $raw = $_POST['text'] ?? '';
        $questions = parseQuizText($raw);
        if (empty($questions)) {
             throw new Exception('No questions could be identified. Please check your format.');
        }
        echo json_encode($questions);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// AJAX: Extract text from uploaded PDF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_file'])) {
    header('Content-Type: application/json');
    try {
        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Security token mismatch.');
        }
        $tmp = $_FILES['pdf_file']['tmp_name'] ?? '';
        if (!$tmp) {
            throw new Exception('No file uploaded');
        }
        $text = extractPdfText($tmp);
        if ($text === false) {
            throw new Exception('Could not extract text from PDF. Please paste the questions manually.');
        }
        echo json_encode(['text' => $text, 'questions' => parseQuizText($text)]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Save quiz (full JSON payload from JS)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_quiz_builder'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        error_log("CSRF mismatch in quiz builder");
        header('Location: quiz_builder.php?err=csrf');
        exit;
    } else {
        $title = sanitize($_POST['title'] ?? '');
        $desc = sanitize($_POST['desc'] ?? '');
        $batchId = (int)($_POST['batch_id'] ?? 0);
        $class = sanitize($_POST['class'] ?? '');
        $subject = sanitize($_POST['subject'] ?? '');

        $topicIdRaw = $_POST['topic_id'] ?? 0;
        $topicId = 0;
        $subtopic = sanitize($_POST['subtopic'] ?? '');

        if (is_array($topicIdRaw) && !empty($topicIdRaw)) {
            // Fetch topic names for multiple topics
            $topicIds = array_map('intval', $topicIdRaw);
            $placeholders = implode(',', array_fill(0, count($topicIds), '?'));
            try {
                $tStmt = $db->prepare("SELECT topic FROM syllabus WHERE id IN ($placeholders)");
                $tStmt->execute($topicIds);
                $fetchedTopics = $tStmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($fetchedTopics)) {
                    $subtopic = trim($subtopic . ' [' . implode(', ', $fetchedTopics) . ']');
                }
            } catch (Exception $e) {
            }
            $topicId = 0; // 0 for multiple topics
        } else {
            $topicId = (int)$topicIdRaw;
        }

        $duration = max(1, (int)($_POST['duration_minutes'] ?? 30));
        $totalMks = max(1, (int)($_POST['total_marks'] ?? 10));
        $passingMks = (int)($_POST['passing_marks'] ?? 6);
        $status = in_array($_POST['pub_status'] ?? 'draft', ['draft', 'published']) ? $_POST['pub_status'] : 'draft';
        $questionsJS = $_POST['questions_json'] ?? '[]';
        $questions = json_decode($questionsJS, true) ?: [];

        $quizType = in_array($_POST['quiz_type'] ?? 'regular', ['regular', 'weekly', 'monthly', 'dpp']) ? $_POST['quiz_type'] : 'regular';
        $saveToBank = isset($_POST['save_to_bank']);

        if (!$title) {
            header('Location: quiz_builder.php?err=title');
            exit;
        }

        // Insert quiz
        try {
            $db->prepare("INSERT INTO quizzes (title,description,batch_id,class,subject,topic_id,subtopic,duration_minutes,total_marks,passing_marks,instructions,quiz_type,created_by,status,created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
                ->execute([$title, $desc, $batchId ?: null, $class, $subject, $topicId ?: null, $subtopic, $duration, $totalMks, $passingMks, $desc, $quizType, $user['id'], $status]);
        } catch (\Exception $e) {
            try {
                // Fallback without new columns
                $db->prepare("INSERT INTO quizzes (title,description,batch_id,class,subject,duration_minutes,total_marks,quiz_type,created_by,status,created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,NOW())")
                    ->execute([$title, $desc, $batchId ?: null, $class, $subject, $duration, $totalMks, $quizType, $user['id'], $status]);
            } catch (Exception $ex) {
                die("Critical Database Error: " . $ex->getMessage());
            }
        }
        $quizId = $db->lastInsertId();

        try {
            $sortOrder = 1;
            foreach ($questions as $q) {
                $qText = substr(strip_tags($q['question'] ?? ''), 0, 2000);
                $optA = substr(strip_tags($q['option_a'] ?? ''), 0, 500);
                $optB = substr(strip_tags($q['option_b'] ?? ''), 0, 500);
                $optC = substr(strip_tags($q['option_c'] ?? ''), 0, 500);
                $optD = substr(strip_tags($q['option_d'] ?? ''), 0, 500);
                $correct = in_array(strtolower($q['correct'] ?? 'a'), ['a', 'b', 'c', 'd']) ? strtolower($q['correct']) : 'a';
                $marks = max(1, (int)($q['marks'] ?? 1));
                if (!$qText)
                    continue;

                // Insert into specific quiz
                $db->prepare("INSERT INTO quiz_questions (quiz_id,question,option_a,option_b,option_c,option_d,correct_answer,marks,sort_order) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$quizId, $qText, $optA, $optB, $optC, $optD, $correct, $marks, $sortOrder++]);

                // Optional: Save to global Question Bank
                if ($saveToBank) {
                    try {
                        $db->prepare("INSERT INTO question_bank (class_name, subject_id, topic_id, topic_name, question, option_a, option_b, option_c, option_d, correct_answer, marks)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                            ->execute([$class, $subject, $topicId ?: null, $subtopic, $qText, $optA, $optB, $optC, $optD, $correct, $marks]);
                    } catch (Exception $bankEx) {
                    }
                }
            }
            logActivity($user['id'], "Built $quizType quiz via SmartBuilder: $title (" . ($sortOrder - 1) . " questions)", 'quiz');
            header("Location: index.php?msg=created");
            exit;
        } catch (Exception $e) {
            die("Error inserting questions: " . $e->getMessage());
        }
    }
}

// ── PARSER FUNCTIONS ─────────────────────────────────────────────────────────
function parseQuizText(string $raw): array
{
    $questions = [];
    
    // Normalize newlines and special unicode whitespace
    $raw = str_replace(["\r\n", "\r", "\xc2\xa0"], ["\n", "\n", " "], $raw);
    
    // ── PRE-PROCESS: SHATTER CRAMMED INLINE TEXT ──
    
    // 1. Fix missing spaces: "A.Text" -> "A. Text", "(A)Text" -> "(A) Text"
    $raw = preg_replace('/^([A-Da-d1-4])[\.\)]([^\s])/m', "$1. $2", $raw);
    $raw = preg_replace('/^\(([A-Da-d1-4])\)([^\s])/m', "$1. $2", $raw);
    $raw = preg_replace('/^\[([A-Da-d1-4])\]([^\s])/m', "$1. $2", $raw);

    // 2. Force inline options (A-D, 1-4) onto new lines if there's a space before them
    // Matches: " A)", " A.", " (A)", " [A]", " 1)", " 1.", " (1)", " [1]"
    $raw = preg_replace('/(?<=\s|^)([A-Da-d1-4])[\.\)](?=\s)/', "\n$1. ", $raw);
    $raw = preg_replace('/(?<=\s|^)\(([A-Da-d1-4])\)(?=\s)/', "\n$1. ", $raw);
    $raw = preg_replace('/(?<=\s|^)\[([A-Da-d1-4])\](?=\s)/', "\n$1. ", $raw);
    
    // 3. Force "Option 1" style to new lines
    $raw = preg_replace('/(?<=\s|^)(Option\s+[A-Da-d1-4])[\.\:\)]?(?=\s)/i', "\n$1. ", $raw);
    
    // 4. Force inline Answers to new line: "Ans: A", "Answer. (A)", "Correct - 1", "Ans->[A]"
    $raw = preg_replace('/(?<=\s|^)(Answer|Ans|Correct|Key|Correct Answer)\.?[\:\-\>\=\s]*[\(\[]?([A-Da-d1-4])[\)\]]?/i', "\nAnswer: $2", $raw);

    $allLines = explode("\n", $raw);
    $lines = [];
    foreach ($allLines as $line) {
        $lt = trim($line);
        if ($lt !== '') $lines[] = $lt;
    }

    $currentQ = '';
    $currentOpts = [];
    $currentCorrect = null;

    $flush = function() use (&$questions, &$currentQ, &$currentOpts, &$currentCorrect) {
        $qText = trim($currentQ);
        if ($qText && count($currentOpts) >= 2) {
            $questions[] = [
                'question' => $qText,
                'option_a' => $currentOpts['a'] ?? '',
                'option_b' => $currentOpts['b'] ?? '',
                'option_c' => $currentOpts['c'] ?? '',
                'option_d' => $currentOpts['d'] ?? '',
                'correct'  => $currentCorrect ?? 'a',
                'marks'    => 1, // Will be overridden by javascript recalculator
                'hasAnswer'=> ($currentCorrect !== null),
            ];
        }
        $currentQ = '';
        $currentOpts = [];
        $currentCorrect = null;
    };

    foreach ($lines as $line) {
        // Answer Key Extraction
        if (preg_match('/^(?:Answer|Ans|Correct|Key|Correct Answer)\.?[\:\-\>\=\s]*[\(\[]?([a-dA-D1-4])[\)\]]?/i', $line, $ma)) {
            $ans = strtolower($ma[1]);
            $map = ['1'=>'a', '2'=>'b', '3'=>'c', '4'=>'d'];
            $currentCorrect = $map[$ans] ?? $ans;
            continue;
        }

        // Option (A-D) Extraction
        if (preg_match('/^(?:Option\s+)?(?:[\(\[]?([A-Da-d])[\)\]]?)[.):\s]+(.*)/i', $line, $mo)) {
            $key = strtolower($mo[1]);
            $val = trim($mo[2]);
            if (!isset($currentOpts[$key])) {
                $currentOpts[$key] = $val;
                continue;
            }
        }

        // Numeric Option (1-4) mapped to A-D
        if (preg_match('/^(?:Option\s+)?(?:[\(\[]?([1-4])[\)\]]?)[.):\s]+(.*)/i', $line, $mo)) {
            $num = (int)$mo[1];
            $val = trim($mo[2]);
            $map = [1=>'a', 2=>'b', 3=>'c', 4=>'d'];
            $key = $map[$num];
            if ($currentQ && !isset($currentOpts[$key])) {
                $currentOpts[$key] = $val;
                continue;
            }
        }

        // Start Question
        // Matches "Q1.", "1.", "Q 1:", "Question 1)", "1-"
        if (preg_match('/^(?:Q(?:uestion)?\s*\d+|[Qq]\d+|^\d+)\s*[\.\)\:\-\>]*\s*(.*)/i', $line, $mq)) {
            if ($currentQ && count($currentOpts) >= 1) $flush();
            $currentQ = trim($mq[1]);
            continue;
        }

        // Append line to current element (either options are rolling or multi-line question)
        if (count($currentOpts) >= 2) {
            $flush();
            $currentQ = $line;
        } else {
            if ($currentQ === '') {
                $currentQ = $line;
            } else {
                $currentQ .= "\n" . $line; // Preserve multi-line text logic
            }
        }
    }
    $flush();

    return $questions;
}

function extractPdfText(string $tmpPath)
{
    // Try pdftotext (Poppler) first
    $escaped = escapeshellarg($tmpPath);
    // Check if pdftotext is available
    $hasPdfToText = shell_exec("which pdftotext");
    if ($hasPdfToText) {
        $out = shell_exec("pdftotext $escaped - 2>/dev/null");
        if ($out && strlen(trim($out)) > 10)
            return $out;
    }

    // Try strings as fallback (crude but works for simple PDFs)
    $out = shell_exec("strings $escaped 2>/dev/null");
    if ($out && strlen(trim($out)) > 10)
        return $out;

    return false;
}

$root = '../../';
require_once '../../includes/header.php';
?>

<?php if (isset($_GET['err'])): ?>
<div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> <?= $_GET['err'] === 'csrf' ? 'Security token mismatch. Please try again.' : 'Please fill in the quiz title first.' ?></div>
<?php
endif; ?>

<div class="page-header">
    <div class="page-header-left">
        <h1><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:8px"><circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4"></path><path d="M12 8h.01"></path></svg> Smart Quiz Builder</h1>
        <p>Create quizzes by typing, pasting or importing from PDF - then mark answers in the live preview</p>
    </div>
    <div class="page-header-actions">
        <a href="index.php" class="btn btn-secondary"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> Back to Quizzes</a>
    </div>
</div>

<form method="POST" id="builderForm" enctype="multipart/form-data">
    <?= csrfField() ?>
    <input type="hidden" name="save_quiz_builder" value="1">
    <input type="hidden" name="questions_json" id="questionsJson" value="[]">
    <input type="hidden" name="pub_status" id="pubStatus" value="draft">

    <div class="builder-layout">

        <!-- ════ LEFT: Quiz Info + Input ════ -->
        <div class="builder-left">
            <!-- 1. Quiz Info Accordion -->
            <div class="accordion-item" id="accordionInfo">
                <div class="accordion-header" onclick="toggleAccordion('Info')">
                    <div class="accordion-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        Quiz Fundamentals
                    </div>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" id="iconInfo"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
                <div class="accordion-body">
                    <div class="floating-group">
                        <input type="text" name="title" placeholder=" " required oninput="updatePreviewTitle(this.value)">
                        <label>Quiz Title</label>
                    </div>
                    
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <div class="floating-group">
                            <select name="batch_id" id="batchSel" onchange="onBatchChange()">
                                <option value="" selected disabled hidden> </option>
                                <option value="">- All / None -</option>
                                <?php foreach ($batches as $b): ?>
                                <option value="<?= $b['id']?>" data-class="<?= htmlspecialchars($b['class'])?>"><?= sanitize($b['name'])?></option>
                                <?php endforeach; ?>
                            </select>
                            <label>Target Batch</label>
                        </div>
                        <div class="floating-group">
                            <input type="text" name="class" id="classInp" placeholder=" " oninput="loadTopics()">
                            <label>Class</label>
                        </div>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <div class="floating-group">
                            <select name="subject" id="subjectSel" onchange="loadTopics()">
                                <option value="" selected disabled hidden> </option>
                                <option value="all" id="optAllSubjects">All Subjects</option>
                                <?php foreach ($subjects as $s): ?>
                                <option value="<?= htmlspecialchars($s)?>"><?= htmlspecialchars($s)?></option>
                                <?php endforeach; ?>
                            </select>
                            <label>Subject</label>
                        </div>
                        <div class="floating-group">
                            <select name="quiz_type" id="quizTypeSel" onchange="onQuizTypeChange()">
                                <option value="regular">Regular Quiz</option>
                                <option value="weekly">Weekly Test</option>
                                <option value="monthly">Monthly Test</option>
                                <option value="dpp">DPP</option>
                            </select>
                            <label>Quiz Type</label>
                        </div>
                    </div>

                    <div class="floating-group">
                        <select name="topic_id" id="topicSel">
                            <option value="">- Load topics -</option>
                        </select>
                        <label>Syllabus Topic</label>
                    </div>

                    <div class="floating-group">
                        <input type="text" name="subtopic" placeholder=" ">
                        <label>Subtopic / Custom Chapter</label>
                    </div>

                    <div style="background:#f8fafc; border:1px solid #f1f5f9; border-radius:18px; padding:20px; margin-bottom:24px; box-shadow:0 2px 10px rgba(0,0,0,0.02)">
                        <div style="font-size:11px; font-weight:850; color:#64748b; text-transform:uppercase; letter-spacing:1.5px; margin-bottom:16px; display:flex; align-items:center; gap:8px">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                            Performance Specs
                        </div>
                        <div style="display:flex; gap:12px">
                            <div class="floating-group" style="flex:1; margin-bottom:0">
                                <input type="number" name="duration_minutes" value="30" placeholder=" " oninput="updatePreviewDuration(this.value)">
                                <label>Duration</label>
                            </div>
                            <div class="floating-group" style="flex:1; margin-bottom:0">
                                <input type="number" name="total_marks" value="10" id="totalMarksInp" placeholder=" " oninput="recalcMarks()">
                                <label>Total Marks</label>
                            </div>
                            <div class="floating-group" style="flex:1; margin-bottom:0">
                                <input type="number" name="passing_marks" value="6" id="passingMarksInp" placeholder=" ">
                                <label>Passing</label>
                            </div>
                        </div>
                    </div>

                </div> <!-- end accordion-body -->
            </div> <!-- end accordion-item -->

            <!-- 2. Question Input Card -->
            <div class="accordion-item" id="accordionQuestions">
                <div class="accordion-header" onclick="toggleAccordion('Questions')">
                    <div class="accordion-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Builder Elements
                    </div>
                </div>
                <div class="accordion-body">
                    <div class="tab-strip">
                        <div class="tab-indicator" id="tabIndicator"></div>
                        <button type="button" class="tab-btn active" id="tab-manual" onclick="switchTab('manual', 0)">Manual</button>
                        <button type="button" class="tab-btn" id="tab-paste" onclick="switchTab('paste', 1)">Smart Paste</button>
                        <button type="button" class="tab-btn" id="tab-pdf" onclick="switchTab('pdf', 2)">PDF Import</button>
                    </div>

                    <!-- Manual -->
                    <div id="panel-manual">
                        <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:16px;padding:20px; box-shadow: 0 4px 6px -1px var(--primary-glow)">
                            <div class="floating-group">
                                <textarea id="manQ" placeholder=" " rows="3"></textarea>
                                <label>Question Content</label>
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
                                <div class="floating-group" style="margin-bottom:0"><input type="text" id="manA" placeholder=" "><label>A. Option</label></div>
                                <div class="floating-group" style="margin-bottom:0"><input type="text" id="manB" placeholder=" "><label>B. Option</label></div>
                                <div class="floating-group" style="margin-bottom:0"><input type="text" id="manC" placeholder=" "><label>C. Option</label></div>
                                <div class="floating-group" style="margin-bottom:0"><input type="text" id="manD" placeholder=" "><label>D. Option</label></div>
                            </div>
                            <div style="display:flex;gap:12px;align-items:center">
                                <div class="floating-group" style="flex:1; margin-bottom:0">
                                    <select id="manCorrect">
                                        <option value="a">Option A</option>
                                        <option value="b">Option B</option>
                                        <option value="c">Option C</option>
                                        <option value="d">Option D</option>
                                    </select>
                                    <label>Answer Key</label>
                                </div>
                                <div class="floating-group" style="width:80px; margin-bottom:0">
                                    <input type="number" id="manMarks" value="1" placeholder=" ">
                                    <label>Marks</label>
                                </div>
                                <button type="button" onclick="addManualQuestion()" style="background:var(--primary);color:#fff;border:none;height:48px;padding:0 24px;border-radius:12px;font-weight:900;cursor:pointer">
                                    Add Element
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Paste -->
                    <div id="panel-paste" style="display:none">
                        <div style="background:linear-gradient(145deg, #ffffff, #f8fafc); border:1.5px solid rgba(226, 232, 240, 0.8); border-radius:18px; padding:20px; margin-bottom:20px; font-size:13px; color:#475569; line-height:1.7; box-shadow:0 4px 15px rgba(0,0,0,0.02)">
                            <div style="font-weight:900; color:#0f172a; margin-bottom:8px; font-size:14px; display:flex; align-items:center; gap:6px;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--primary);"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                Smart Paste Engine
                            </div>
                            <div style="color:#64748b; margin-bottom:12px; font-size:12.5px;">Paste your questions in almost any structured format. The AI engine automatically detects questions, options, and answers.</div>
                            <div style="background:#f1f5f9; padding:12px 16px; border-radius:12px; font-family:'JetBrains Mono', monospace; font-size:11.5px; color:#334155; border:1px solid #e2e8f0; max-height:150px; overflow-y:auto; box-shadow:inset 0 2px 4px rgba(0,0,0,0.02)">
                                1. What is the powerhouse of the cell?<br>
                                A) Mitochondria<br>
                                B) Nucleus<br>
                                Answer: A<br><br>
                                What is 5 x 5? Option 1: 20 Option 2: 25 Key: 2
                            </div>
                        </div>
                        <div class="floating-group">
                            <textarea id="pasteArea" placeholder=" " rows="10" style="font-family:'JetBrains Mono', monospace; background:rgba(255,255,255,0.7); box-shadow:inset 0 2px 4px rgba(0,0,0,0.02); resize:vertical; border-radius:16px; padding:18px;"></textarea>
                            <label>Paste Complete Quiz Text Here</label>
                        </div>
                        <button type="button" onclick="parsePasted()" style="width:100%; height:52px; border-radius:14px; font-weight:850; font-size:15px; color:#fff; background:linear-gradient(135deg, var(--primary), #6366f1); border:none; box-shadow:0 8px 20px var(--primary-glow); display:flex; align-items:center; justify-content:center; gap:8px; transition:transform 0.2s, box-shadow 0.2s; cursor:pointer;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 12px 25px rgba(79, 70, 229, 0.3)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 8px 20px var(--primary-glow)';">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                            Analyze & Import Entities
                        </button>
                        <div id="parseStatus" style="margin-top:14px; text-align:center; min-height:36px"></div>
                    </div>

                    <!-- PDF -->
                    <div id="panel-pdf" style="display:none">
                        <div class="drop-zone" onclick="document.getElementById('pdfInput').click()" id="pdfDropZone">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                            <div style="font-weight:800; margin-top:12px">Drop PDF here</div>
                            <div style="font-size:12px; color:#94a3b8">or click to browse from local storage</div>
                            <input type="file" id="pdfInput" accept=".pdf" style="display:none" onchange="handlePDFUpload(this)">
                        </div>
                        <div id="pdfStatus" style="margin-top:10px; text-align:center"></div>
                        <div id="pdfExtractedText" style="display:none;margin-top:20px">
                            <textarea id="pdfTextArea" rows="8" style="width:100%;font-family:monospace;border-radius:12px;padding:12px;border:1.5px solid #e2e8f0"></textarea>
                            <button type="button" onclick="parsePDFText()" class="btn btn-primary" style="margin-top:12px;width:100%">Convert Extracted Text</button>
                        </div>
                    </div>
                </div>
            </div>
        </div><!-- end builder-left -->

        <!-- ════ RIGHT: Phone Mockup Preview ════ -->
        <div class="preview-section">
            <div class="phone-mockup">
                <div class="phone-screen">
                    <!-- Premium Status Bar -->
                    <div class="phone-status-bar">
                        <div style="flex:1">9:41</div>
                        <div class="phone-status-icons">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.55a11 11 0 0 1 14.08 0"></path><path d="M1.42 9a16 16 0 0 1 21.16 0"></path><path d="M8.53 16.11a6 6 0 0 1 6.95 0"></path><line x1="12" y1="20" x2="12.01" y2="20"></line></svg>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="16" height="10" rx="2" ry="2"></rect><line x1="22" y1="11" x2="22" y2="13"></line></svg>
                        </div>
                    </div>

                    <div class="phone-header">
                        <div id="previewTitle" style="font-size:18px;font-weight:900;color:#0f172a;letter-spacing:-0.5px">Untitled Quiz</div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:6px">
                            <div style="font-size:12px;font-weight:700;color:#64748b;display:flex;align-items:center;gap:6px">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                <span id="previewDur">30</span>m • <span id="previewQCount">0</span> Questions
                            </div>
                        </div>
                    </div>

                    <div id="questionCards" style="padding:16px 0 80px; flex:1">
                        <!-- Questions appear here as .p-card -->
                        <div id="emptyState" style="text-align:center;padding:100px 30px;color:#94a3b8">
                            <div style="background:#f1f5f9; width:64px; height:64px; border-radius:32px; display:flex; align-items:center; justify-content:center; margin:0 auto 20px">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </div>
                            <div style="font-weight:850; font-size:16px; color:#475569; margin-bottom:8px">Visual Builder</div>
                            <div style="font-size:13px; line-height:1.6">Questions added on the left will render here in real-time.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sticky Floating Action Bar -->
    <div class="sticky-bar">
        <div style="font-size:13px; font-weight:800; opacity:0.6; display:flex; align-items:center; gap:8px">
            <div class="pulse-dot"></div>
            Builder Active
        </div>
        <div style="width:1px; height:24px; background:rgba(255,255,255,0.1)"></div>
        <button type="button" onclick="previewStudentView()" class="tab-btn" style="color:#fff; padding:0 20px; font-size:13px">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right:6px"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
            Student Preview
        </button>
        <button type="button" onclick="saveQuiz('draft')" class="tab-btn" style="color:#cbd5e1; padding:0 20px; font-size:13px">
            Save Draft
        </button>
        <button type="button" onclick="saveQuiz('published')" style="background:var(--primary); color:#fff; border:none; padding:12px 28px; border-radius:100px; font-weight:900; font-size:14px; cursor:pointer; box-shadow: 0 10px 25px rgba(79, 70, 229, 0.4)">
            Publish Quiz
        </button>
    </div>
</form>

<!-- Student Preview Modal -->
<div class="modal-overlay" id="studentPreviewModal">
    <div class="modal" style="max-width:720px">
        <div class="modal-header">
            <div class="modal-title"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg> Student View Preview</div>
            <button class="modal-close" onclick="closeModal('studentPreviewModal')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
        </div>
        <div class="modal-body" id="studentPreviewBody"></div>
    </div>
</div>

<style>
/* ── Awesome Quiz Builder Styles ── */
:root {
    --primary: #4f46e5;
    --primary-glow: rgba(79, 70, 229, 0.15);
    --bg-soft: #f8fafc;
    --text-slate: #64748b;
    --glass: rgba(255, 255, 255, 0.7);
    --phone-w: 380px;
}

.builder-layout {
    display: grid;
    grid-template-columns: 1fr var(--phone-w);
    gap: 40px;
    align-items: flex-start;
    max-width: 1400px;
    margin: 0 auto;
    padding-bottom: 120px;
}

.builder-left {
    min-width: 0;
}

/* Progressive Disclosure: Accordions */
.accordion-item {
    background: #fff;
    border-radius: 20px;
    border: 1px solid rgba(0,0,0,0.06);
    margin-bottom: 24px;
    overflow: hidden;
    transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    box-shadow: 0 4px 20px rgba(0,0,0,0.02);
}
.accordion-item.collapsed .accordion-body {
    max-height: 0;
    padding-top: 0;
    padding-bottom: 0;
    opacity: 0;
    pointer-events: none;
}
.accordion-header {
    padding: 20px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    background: #fff;
    user-select: none;
}
.accordion-header:hover { background: #fafafa; }
.accordion-body {
    padding: 0 24px 24px;
    transition: all 0.3s ease;
}
.accordion-title {
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 800;
    font-size: 16px;
    color: #1e293b;
}

/* Floating Labels */
.floating-group {
    position: relative;
    margin-bottom: 24px;
}
.floating-group input, .floating-group select, .floating-group textarea {
    width: 100%;
    padding: 14px 16px;
    border: 1.5px solid #f1f5f9;
    border-radius: 14px;
    background: #fff;
    font-size: 14px;
    outline: none;
    transition: all 0.2s;
    color: #1e293b;
    font-weight: 500;
}
.floating-group label {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    background: #fff;
    padding: 0 6px;
    color: #94a3b8;
    font-size: 14px;
    pointer-events: none;
    transition: all 0.2s;
    font-weight: 700;
}
.floating-group input:focus, .floating-group select:focus, .floating-group textarea:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 6px var(--primary-glow);
    background: #fff;
}
.floating-group input:focus + label, 
.floating-group input:not(:placeholder-shown) + label,
.floating-group select:focus + label,
.floating-group select:not([value=""]) + label,
.floating-group textarea:focus + label,
.floating-group textarea:not(:placeholder-shown) + label {
    top: 0;
    font-size: 11px;
    color: var(--primary);
    font-weight: 850;
    letter-spacing: 0.5px;
}

/* Premium Phone Mockup (Right Side) */
.preview-section {
    width: var(--phone-w);
    position: sticky;
    top: 24px;
    flex-shrink: 0;
}
.phone-mockup {
    width: 100%;
    height: 760px;
    background: #0f172a;
    border-radius: 54px;
    padding: 12px;
    box-shadow: 
        0 50px 100px -20px rgba(0,0,0,0.3),
        0 0 0 2px #334155 inset;
    position: relative;
    border: 4px solid #1e293b;
}

/* Status Bar Content */
.phone-status-bar {
    height: 34px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 24px;
    font-size: 12px;
    font-weight: 700;
    color: #1e293b;
}
.phone-status-icons {
    display: flex;
    gap: 6px;
    align-items: center;
}

.phone-screen {
    width: 100%;
    height: 100%;
    background: #f8fafc;
    border-radius: 42px;
    overflow-y: auto;
    position: relative;
    scrollbar-width: none;
    display: flex;
    flex-direction: column;
}
.phone-screen::-webkit-scrollbar { display: none; }

.phone-header {
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(12px);
    padding: 12px 20px 16px;
    z-index: 10;
    border-bottom: 1px solid rgba(0,0,0,0.04);
}

/* Tab Strip with Animation */
.tab-strip {
    display: flex;
    gap: 4px;
    background: #f1f5f9;
    padding: 4px;
    border-radius: 14px;
    margin-bottom: 24px;
    position: relative;
    z-index: 1;
}
.tab-btn {
    flex: 1;
    padding: 12px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 850;
    color: #64748b;
    border: none;
    background: none;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
    z-index: 2;
}
.tab-btn.active {
    color: var(--primary);
}
.tab-indicator {
    position: absolute;
    top: 4px;
    left: 4px;
    width: calc(33.33% - 4px);
    height: calc(100% - 8px);
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    z-index: 1;
}

/* Question Cards in Preview */
.p-card {
    background: #fff;
    margin: 0 16px 16px;
    border-radius: 20px;
    padding: 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.02);
    border: 1px solid rgba(0,0,0,0.02);
}
.p-opt {
    display: block;
    width: 100%;
    padding: 12px 14px;
    border: 2px solid #f1f5f9;
    border-radius: 14px;
    margin-top: 10px;
    font-size: 13px;
    font-weight: 700;
    text-align: left;
    transition: all 0.2s ease;
    cursor: pointer;
    color: #475569;
}
.p-opt:hover { border-color: var(--primary-glow); }
.p-opt.selected {
    border-color: #10b981;
    background: #f0fdf4;
    color: #065f46;
}

/* Sticky Action Bar */
.sticky-bar {
    position: fixed;
    bottom: 32px;
    left: 50%;
    transform: translateX(-50%);
    background: #1e293b;
    padding: 10px 10px 10px 24px;
    border-radius: 100px;
    display: flex;
    align-items: center;
    gap: 20px;
    box-shadow: 0 30px 60px rgba(0,0,0,0.4);
    z-index: 1000;
    border: 1px solid rgba(255,255,255,0.1);
    color: #fff;
}

/* PDF Drop Zone */
.drop-zone {
    border: 2.5px dashed #e2e8f0;
    border-radius: 24px;
    padding: 50px 24px;
    text-align: center;
    background: #f8fafc;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    cursor: pointer;
}
.drop-zone:hover {
    border-color: var(--primary);
    background: #f5f3ff;
    transform: scale(0.99);
}
.preview-container {
    height: calc(100vh - 80px); 
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding-bottom: 20px;
}
.quiz-card {
    width: 100%;
    max-width: 600px;
    margin: 0 auto;
}
.modal-overlay {
    z-index: 1001;
}
.modal {
    z-index: 1002;
    max-height: 90vh;
}
</style>

<script>
    let qbQuestions = [];
    // Compatibility alias if needed
    window.allQuizQuestions = qbQuestions; 

    // ── Accordions ──────────────────────────────────────────────────────────────
    function toggleAccordion(id) {
        const item = document.getElementById('accordion' + id);
        const icon = document.getElementById('icon' + id);
        
        // Collapse all others if you want exclusive accordion
        // document.querySelectorAll('.accordion-item').forEach(el => {
        //     if(el !== item) el.classList.add('collapsed');
        // });

        item.classList.toggle('collapsed');
        if (icon) {
            icon.style.transform = item.classList.contains('collapsed') ? 'rotate(0deg)' : 'rotate(180deg)';
        }
    }

    // ── Tabs with Animation ─────────────────────────────────────────────────────
    function switchTab(name, index) {
        ['manual', 'paste', 'pdf'].forEach(t => {
            const btn = document.getElementById('tab-' + t);
            const pnl = document.getElementById('panel-' + t);
            if (btn) btn.classList.toggle('active', t === name);
            if (pnl) pnl.style.display = t === name ? 'block' : 'none';
        });
        const indicator = document.getElementById('tabIndicator');
        if (indicator) {
            indicator.style.transform = `translateX(${index * 100}%) translateX(${index * 4}px)`;
        }
    }

    // ── Real-time Preview Sync ──────────────────────────────────────────────────
    function updatePreviewTitle(val) {
        document.getElementById('previewTitle').textContent = val || 'Untitled Quiz';
    }
    function updatePreviewDuration(val) {
        document.getElementById('previewDur').textContent = val || '30';
    }

    // ── Quiz Type Change ────────────────────────────────────────────────────────
    function onQuizTypeChange() {
        const type = document.getElementById('quizTypeSel').value;
        const optAll = document.getElementById('optAllSubjects');
        const subjSel = document.getElementById('subjectSel');
        const topicSel = document.getElementById('topicSel');
        if (type === 'weekly' || type === 'monthly') {
            optAll.style.display = 'block';
            subjSel.value = 'all'; // Default to all for weekly/monthly
            topicSel.multiple = true;
            topicSel.name = 'topic_id[]';
            topicSel.style.height = '120px';
        } else {
            optAll.style.display = 'none';
            if (subjSel.value === 'all') subjSel.value = '';
            topicSel.multiple = false;
            topicSel.name = 'topic_id';
            topicSel.style.height = 'auto';
        }
        loadTopics();
    }

    // ── Batch auto-fill class ────────────────────────────────────────────────────
    function onBatchChange() {
        const sel = document.getElementById('batchSel');
        const cls = sel.options[sel.selectedIndex]?.dataset.class || '';
        document.getElementById('classInp').value = cls;
        loadTopics();
    }

    // ── Load topics via AJAX ─────────────────────────────────────────────────────
    function loadTopics() {
        const cls = document.getElementById('classInp').value.trim();
        const subj = document.getElementById('subjectSel').value;
        if (!cls || !subj) return;
        fetch(`quiz_builder.php?ajax_topics=1&class=${encodeURIComponent(cls)}&subject=${encodeURIComponent(subj)}`)
            .then(r => r.json()).then(topics => {
                const sel = document.getElementById('topicSel');
                sel.innerHTML = '<option value="">- Select Topic -</option>';
                topics.forEach(t => {
                    const o = document.createElement('option');
                    if (typeof t === 'object' && t !== null) {
                        o.value = t.id; o.textContent = t.text;
                    } else {
                        o.value = t; o.textContent = t;
                    }
                    sel.appendChild(o);
                });
            });
    }

    // ── Manual add ───────────────────────────────────────────────────────────────
    function addManualQuestion() {
        const q = document.getElementById('manQ').value.trim();
        const a = document.getElementById('manA').value.trim();
        const b = document.getElementById('manB').value.trim();
        if (!q || !a || !b) { alert('Question text and at least Options A & B are required.'); return; }
        qbQuestions.push({
            question: q,
            option_a: a,
            option_b: b,
            option_c: document.getElementById('manC').value.trim(),
            option_d: document.getElementById('manD').value.trim(),
            correct: document.getElementById('manCorrect').value,
            marks: parseInt(document.getElementById('manMarks').value) || 1,
            hasAnswer: true
        });
        // Clear form
        ['manQ', 'manA', 'manB', 'manC', 'manD'].forEach(id => document.getElementById(id).value = '');
        document.getElementById('manCorrect').value = 'a';
        document.getElementById('manMarks').value = '1';
        recalcMarks();
    }

    // ── Parse pasted text ────────────────────────────────────────────────────────
    function parsePasted() {
        const text = document.getElementById('pasteArea').value.trim();
        if (!text) { alert('Please paste some quiz text first.'); return; }
        document.getElementById('parseStatus').innerHTML = '<div style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px; background:#eff6ff; color:#1d4ed8; border-radius:20px; font-size:13px; font-weight:700; box-shadow:0 2px 10px rgba(29, 78, 216, 0.1)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="spin"><path d="M21 12a9 9 0 1 1-6.21-8.58"></path></svg> Extracting Intelligence...</div>';
        
        fetch('quiz_builder.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `parse_text=1&text=${encodeURIComponent(text)}&csrf_token=${encodeURIComponent(window.CSRF_TOKEN)}`
        }).then(r => {
            if (!r.ok) {
                return r.json().then(err => { throw new Error(err.error || 'Server error'); });
            }
            return r.json();
        }).then(qs => {
            if (!Array.isArray(qs) || !qs.length) {
                document.getElementById('parseStatus').innerHTML = '<div style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px; background:#fef2f2; color:#b91c1c; border-radius:20px; font-size:13px; font-weight:700; box-shadow:0 2px 10px rgba(185, 28, 28, 0.1)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> Format not recognized. Try pasting conventionally.</div>';
                return;
            }
            qbQuestions = qbQuestions.concat(qs);
            recalcMarks(); // Automatically distribute total marks
            renderPreview(true);
            document.getElementById('parseStatus').innerHTML = `<div style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px; background:#f0fdf4; color:#15803d; border-radius:20px; font-size:13px; font-weight:700; box-shadow:0 2px 10px rgba(21, 128, 61, 0.1)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg> Extracted and staged ${qs.length} question(s) successfully!</div>`;
            document.getElementById('pasteArea').value = '';
            setTimeout(() => { 
                document.getElementById('parseStatus').innerHTML = ''; 
                // Auto-preview removed to isolate rendering issues
            }, 1000);
        }).catch((err) => {
            console.error('Quiz Parsing Error:', err);
            document.getElementById('parseStatus').innerHTML = `<div style="display:inline-flex; align-items:center; gap:8px; padding:8px 16px; background:#fef2f2; color:#b91c1c; border-radius:20px; font-size:13px; font-weight:700; box-shadow:0 2px 10px rgba(185, 28, 28, 0.1)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg> Error: ${err.message || 'Engine failure'}. Try again.</div>`;
        });
    }

    // ── PDF upload ───────────────────────────────────────────────────────────────
    function handlePDFUpload(input) {
        if (!input.files[0]) return;
        document.getElementById('pdfStatus').innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="spin" style="display:inline-block;vertical-align:text-bottom;margin-right:4px"><path d="M21 12a9 9 0 1 1-6.21-8.58"></path></svg> Uploading and extracting text...';
        const fd = new FormData();
        fd.append('pdf_file', input.files[0]);
        fd.append('csrf_token', window.CSRF_TOKEN);
        fetch('quiz_builder.php', { method: 'POST', body: fd })
            .then(r => r.json()).then(data => {
                if (data.error) {
                    document.getElementById('pdfStatus').innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> ${data.error}`;
                    document.getElementById('pdfExtractedText').style.display = 'block';
                    return;
                }
                document.getElementById('pdfTextArea').value = data.text || '';
                document.getElementById('pdfExtractedText').style.display = 'block';
                if (data.questions && data.questions.length) {
                    qbQuestions = qbQuestions.concat(data.questions);
                    recalcMarks();
                    renderPreview(true);
                    document.getElementById('pdfStatus').innerHTML = `<span style="color:#16a34a"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg> Extracted and parsed ${data.questions.length} question(s). Review answers below.</span>`;
                } else {
                    document.getElementById('pdfStatus').innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> Text extracted but no questions detected. Edit the text above and click Parse.';
                }
            }).catch(() => {
                document.getElementById('pdfStatus').innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg> Upload failed. Please try pasting the text manually.';
            });
    }

    function parsePDFText() {
        const text = document.getElementById('pdfTextArea').value.trim();
        if (!text) { alert('No text to parse.'); return; }
        fetch('quiz_builder.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `parse_text=1&text=${encodeURIComponent(text)}&csrf_token=${encodeURIComponent(window.CSRF_TOKEN)}`
        }).then(r => r.json()).then(qs => {
            if (!qs.length) { alert('No questions detected. Try editing the extracted text.'); return; }
            qbQuestions = qbQuestions.concat(qs);
            recalcMarks();
            renderPreview();
            document.getElementById('pdfStatus').innerHTML = `<span style="color:#16a34a"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg> Parsed ${qs.length} question(s)!</span>`;
            // Auto-preview after successful analysis
            setTimeout(previewStudentView, 500);
        });
    }

    // ── Render preview ───────────────────────────────────────────────────────────
    function renderPreview(shouldScroll = false) {
        try {
            const container = document.getElementById('questionCards');
            const empty = document.getElementById('emptyState');
            if (!container) return;

            const qCountEl = document.getElementById('previewQCount');
            if (qCountEl) qCountEl.textContent = qbQuestions.length;
            if (empty) empty.style.display = qbQuestions.length ? 'none' : 'block';
            container.innerHTML = '';

            qbQuestions.forEach((q, idx) => {
                const opts = [
                    { k: 'a', label: 'A', text: q.option_a },
                    { k: 'b', label: 'B', text: q.option_b },
                    { k: 'c', label: 'C', text: q.option_c },
                    { k: 'd', label: 'D', text: q.option_d },
                ].filter(o => o.text);

                const card = document.createElement('div');
                card.className = 'p-card';
                if (!q.hasAnswer) card.style.borderLeft = '4px solid #0ea5e9';

                card.innerHTML = `
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px">
                        <div style="font-size:14px; font-weight:800; color:#1e293b; line-height:1.5">
                            <span style="color:var(--primary)">Q${idx + 1}.</span> ${escHtml(q.question)}
                        </div>
                    </div>
                    <div>
                        ${opts.map(o => `
                            <div class="p-opt ${q.correct === o.k ? 'selected' : ''}" onclick="setAnswer(${idx},'${o.k}')">
                                <span style="opacity:0.5; margin-right:8px">${o.label}.</span> ${escHtml(o.text)}
                                ${q.correct === o.k ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="float:right; margin-top:2px"><polyline points="20 6 9 17 4 12"/></svg>' : ''}
                            </div>
                        `).join('')}
                    </div>
                    <div style="display:flex; gap:10px; margin-top:16px; border-top:1px solid #f1f5f9; padding-top:12px">
                        <div style="font-size:11px; font-weight:800; color:#94a3b8; display:flex; align-items:center; gap:4px">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            ${q.marks} Marks
                        </div>
                        <div style="flex:1"></div>
                        <button type="button" onclick="editQuestion(${idx})" style="background:none;border:none;color:#64748b;font-weight:800;font-size:11px;cursor:pointer;display:flex;align-items:center;gap:4px">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            Edit
                        </button>
                        <button type="button" onclick="deleteQuestion(${idx})" style="background:none;border:none;color:#ef4444;font-weight:800;font-size:11px;cursor:pointer">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                        </button>
                    </div>
                `;
                container.appendChild(card);
            });
            syncJson();
            if (shouldScroll) {
                const screen = document.querySelector('.phone-screen');
                if (screen) {
                    setTimeout(() => {
                        screen.scrollTo({ top: screen.scrollHeight, behavior: 'smooth' });
                    }, 100);
                }
            }
        } catch (renderErr) {
            console.error('Render Preview Error:', renderErr);
            throw renderErr; // Re-throw to be caught by caller
        }
    }

    function setAnswer(idx, key) {
        qbQuestions[idx].correct = key;
        qbQuestions[idx].hasAnswer = true;
        renderPreview();
    }

    function deleteQuestion(idx) {
        qbQuestions.splice(idx, 1);
        recalcMarks();
    }

    function duplicateQuestion(idx) {
        qbQuestions.splice(idx + 1, 0, { ...qbQuestions[idx] });
        recalcMarks();
    }

    function editQuestion(idx) {
        const q = qbQuestions[idx];
        document.getElementById('manQ').value = q.question;
        document.getElementById('manA').value = q.option_a;
        document.getElementById('manB').value = q.option_b;
        document.getElementById('manC').value = q.option_c;
        document.getElementById('manD').value = q.option_d;
        document.getElementById('manCorrect').value = q.correct;
        document.getElementById('manMarks').value = q.marks;
        qbQuestions.splice(idx, 1);
        renderPreview();
        switchTab('manual', 0);
        document.getElementById('manQ').focus();
    }

    function clearAllQuestions() {
        if (!qbQuestions.length || confirm('Remove all questions?')) { qbQuestions = []; renderPreview(); }
    }

    function recalcMarks() {
        const totalMarks = parseInt(document.getElementById('totalMarksInp').value) || 0;
        const qCount = qbQuestions.length;
        if (qCount > 0 && totalMarks > 0) {
            // Distribute marks evenly (ignoring decimals for now, as DB requires INT)
            const marksPerQ = Math.max(1, Math.floor(totalMarks / qCount));
            qbQuestions.forEach(q => {
                q.marks = marksPerQ;
            });
        }
        renderPreview();
    }

    function syncJson() {
        const el = document.getElementById('questionsJson');
        if (el) el.value = JSON.stringify(qbQuestions);
    }

    // ── Student Preview Modal ────────────────────────────────────────────────────
    function previewStudentView() {
        if (!qbQuestions.length) { alert('Add some questions first.'); return; }
        const title = document.querySelector('[name="title"]').value || 'Quiz Preview';
        const dur = document.querySelector('[name="duration_minutes"]').value || '30';
        let html = `<div style="background:linear-gradient(135deg,#1e1b4b,#4f46e5,#7c3aed);color:#fff;border-radius:14px;padding:16px 20px;margin-bottom:20px">
        <div style="font-size:18px;font-weight:900">${escHtml(title)}</div>
        <div style="font-size:12px;opacity:.8;margin-top:4px"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> ${dur} min · ${qbQuestions.length} Questions · Student View</div>
    </div>`;
        qbQuestions.forEach((q, i) => {
            const opts = [
                { k: 'a', l: 'A', t: q.option_a }, { k: 'b', l: 'B', t: q.option_b },
                { k: 'c', l: 'C', t: q.option_c }, { k: 'd', l: 'D', t: q.option_d }
            ].filter(o => o.t);
            html += `<div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:18px;margin-bottom:12px">
            <div style="font-weight:800;margin-bottom:12px">Q${i + 1}. ${escHtml(q.question)}</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
            ${opts.map(o => `<div style="padding:10px 13px;border:2px solid #e2e8f0;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer" onmouseenter="this.style.borderColor='#4f46e5'" onmouseleave="this.style.borderColor='#e2e8f0'"><strong>${o.l}.</strong> ${escHtml(o.t)}</div>`).join('')}
            </div>
        </div>`;
        });
        html += `<button style="width:100%;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;border:none;padding:14px;border-radius:12px;font-weight:900;font-size:15px;cursor:pointer;font-family:'Nunito',sans-serif"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg> Submit Quiz</button>`;
        document.getElementById('studentPreviewBody').innerHTML = `<div class="preview-container"><div class="quiz-card">${html}</div></div>`;
        openModal('studentPreviewModal');
    }

    // ── Save quiz ─────────────────────────────────────────────────────────────────
    function saveQuiz(status) {
        if (!qbQuestions.length) { alert('Please add at least one question before saving.'); return; }
        if (!document.querySelector('[name="title"]').value.trim()) { 
            alert('Please enter a quiz title.'); 
            toggleAccordion('Info');
            return; 
        }
        const missing = qbQuestions.filter(q => !q.hasAnswer);
        if (missing.length) {
            if (!confirm(`${missing.length} question(s) have no correct answer selected. Save anyway?`)) return;
        }
        syncJson();
        document.getElementById('pubStatus').value = status;
        document.getElementById('builderForm').submit();
    }

    function escHtml(str) {
        if(!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Init
    renderPreview();
</script>

<?php require_once '../../includes/footer.php'; ?>
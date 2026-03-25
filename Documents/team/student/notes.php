<?php
require_once '../student/auth.php';
requireStudentLogin();

$pageTitle = 'Study Notes';
try {
    $db = getDB();
} catch (Exception $e) {
    error_log('student/notes.php DB connection failed: ' . $e->getMessage());
    die('<div style="font-family:sans-serif;padding:40px;text-align:center;color:#c00"><h2>⚠️ Database Error</h2><p>Unable to connect. Please try again later.</p></div>');
}
$student = currentStudent();
$sid = $student['id'];

// Get student class variations
try {
    $classesToSearch = getStudentClassVariations($sid, $db);
} catch (Exception $e) {
    error_log('student/notes.php class variations failed: ' . $e->getMessage());
    $classesToSearch = ['_NO_CLASS_'];
}

// Check if status column exists (do once)
$hasStatusCol = false;
try {
    $hasStatusCol = (bool)$db->query("SHOW COLUMNS FROM notes LIKE 'status'")->fetch();
} catch (Exception $e2) {}

// Build the status filter fragment once
$statusFilter = "";
if ($hasStatusCol) {
    $statusFilter = " AND (status='published' OR status IS NULL OR status='')";
}
$statusFilterN = "";
if ($hasStatusCol) {
    $statusFilterN = " AND (n.status='published' OR n.status IS NULL OR n.status='')";
}

// ── Build reusable class filter ────────────
$inPlaceholders = implode(',', array_fill(0, count($classesToSearch), '?'));

// Filters
$selSubject = $_GET['subject'] ?? '';
$selChapter = (int)($_GET['chapter'] ?? 0);

// ── Fetch ALL subjects that have notes (no class filtering to guarantee visibility) ──
$subjects = [];
try {
    $subjSql = "SELECT DISTINCT subject_id FROM notes WHERE subject_id IS NOT NULL AND subject_id!=''";
    $subjSql .= $statusFilter;
    $subjSql .= " ORDER BY subject_id";
    $subjects = $db->query($subjSql)->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log('student/notes.php subjects fetch failed: ' . $e->getMessage());
    $subjects = [];
}

// Fetch Chapters for selected subject (no class filtering — show ALL chapters for the subject)
$chapters = [];
if ($selSubject) {
    try {
        $chSql = "SELECT DISTINCT n.chapter_id, c.chapter_name, c.chapter_order 
                  FROM notes n 
                  JOIN chapters c ON n.chapter_id = c.id 
                  WHERE n.subject_id=?" . $statusFilterN . "
                  ORDER BY c.chapter_order ASC, c.chapter_name ASC";
        $chStmt = $db->prepare($chSql);
        $chStmt->execute([$selSubject]);
        $chapters = $chStmt->fetchAll();
    } catch (Exception $e) {
        error_log('student/notes.php chapters fetch failed: ' . $e->getMessage());
        $chapters = [];
    }
}

// ── Fetch Notes: When subject is selected, show ALL notes for that subject (no class filter) ──
$notes = [];
try {
    $sql = "SELECT n.*, c.chapter_name, u.name as teacher_name 
            FROM notes n 
            LEFT JOIN chapters c ON n.chapter_id = c.id 
            LEFT JOIN users u ON n.uploaded_by = u.id 
            WHERE 1=1";
    $params = [];

    if ($hasStatusCol) {
        $sql .= " AND (n.status='published' OR n.status IS NULL OR n.status='')";
    }
    
    if ($selSubject) {
        $sql .= " AND n.subject_id=?";
        $params[] = $selSubject;
    }
    if ($selChapter) {
        $sql .= " AND n.chapter_id=?";
        $params[] = $selChapter;
    }
    $sql .= " ORDER BY n.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $notes = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('student/notes.php notes fetch failed: ' . $e->getMessage());
    $notes = [];
}

$navActive = 'notes';
require_once '_nav.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notes | HeyyGuru</title>
    <link rel="icon" href="../assets/img/favicon_hg.png?v=2.0" type="image/png">
    <link rel="shortcut icon" href="../assets/img/favicon_hg.png?v=2.0" type="image/png">
    <link rel="apple-touch-icon" href="../assets/img/favicon_hg.png?v=2.0">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="student.css?v=1.2">
    <style>
        .s-grid-auto { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 24px; margin-bottom: 30px; }
        .premium-glass-card h3 { word-break: break-word !important; }
        .action-footer { display: flex; flex-direction: column; gap: 16px; margin-top: 20px; border-top: 1.5px solid rgba(0,0,0,0.05); padding-top: 20px; }
        @media (min-width: 500px) {
            .action-footer { flex-direction: row; justify-content: space-between; align-items: center; }
        }

        /* Premium Notes Hero */
        .notes-hero {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 30%, #0e7490 60%, #06b6d4 100%);
            padding: 40px 40px 36px;
            margin-bottom: 32px;
            border-radius: 24px;
            position: relative;
            overflow: hidden;
        }
        .notes-hero::before {
            content: '';
            position: absolute;
            top: -40%;
            right: -15%;
            width: 350px;
            height: 350px;
            background: radial-gradient(circle, rgba(6,182,212,0.3) 0%, transparent 70%);
            border-radius: 50%;
            animation: noteFloat 7s ease-in-out infinite;
        }
        .notes-hero::after {
            content: '';
            position: absolute;
            bottom: -25%;
            left: 5%;
            width: 280px;
            height: 280px;
            background: radial-gradient(circle, rgba(34,211,238,0.2) 0%, transparent 70%);
            border-radius: 50%;
            animation: noteFloat 9s ease-in-out infinite reverse;
        }
        @keyframes noteFloat {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-18px) scale(1.04); }
        }
        .notes-hero h1 {
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
        .notes-hero p {
            margin: 10px 0 0 0;
            font-size: 15px;
            font-weight: 600;
            z-index: 1;
            position: relative;
            color: #a5f3fc;
        }
        .notes-hero p a { color: #fff; text-decoration: underline; font-weight: 800; }

        /* Premium Subject Cards */
        .notes-subj-card {
            background: #fff;
            border-radius: 24px;
            padding: 40px 24px;
            text-align: center;
            border: 1.5px solid #f1f5f9;
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 20px rgba(0,0,0,0.04);
            position: relative;
            overflow: hidden;
        }
        .notes-subj-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #06b6d4, #8b5cf6);
            border-radius: 24px 24px 0 0;
            opacity: 0;
            transition: opacity 0.3s;
        }
        .notes-subj-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 50px rgba(6,182,212,0.15);
            border-color: #e0f2fe;
        }
        .notes-subj-card:hover::before { opacity: 1; }
        .notes-subj-icon {
            width: 76px;
            height: 76px;
            margin: 0 auto 18px;
            background: linear-gradient(135deg, #ecfeff, #e0f2fe);
            color: #0891b2;
            border-radius: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 25px rgba(6,182,212,0.12);
            transition: all 0.35s;
        }
        .notes-subj-card:hover .notes-subj-icon {
            transform: scale(1.08);
            box-shadow: 0 12px 35px rgba(6,182,212,0.2);
        }
        .notes-enter-btn {
            font-size: 13px;
            color: #0891b2;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 20px;
            background: rgba(6,182,212,0.08);
            border-radius: 99px;
            transition: all 0.3s;
        }
        .notes-subj-card:hover .notes-enter-btn {
            background: rgba(6,182,212,0.15);
            color: #0e7490;
        }

        /* Premium Empty State */
        .notes-empty {
            grid-column: 1 / -1;
            text-align: center;
            padding: 80px 40px;
            background: linear-gradient(135deg, #f8fdff 0%, #ecfeff 50%, #e0f2fe 100%);
            border-radius: 24px;
            border: 2px dashed #a5f3fc;
            position: relative;
            overflow: hidden;
        }
        .notes-empty::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: radial-gradient(circle at 50% 30%, rgba(6,182,212,0.06) 0%, transparent 60%);
        }
        .notes-empty-icon {
            width: 90px;
            height: 90px;
            margin: 0 auto 24px;
            background: linear-gradient(135deg, #06b6d4, #0891b2);
            border-radius: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 12px 40px rgba(6,182,212,0.25);
            animation: notePulse 3s ease-in-out infinite;
        }
        @keyframes notePulse {
            0%, 100% { transform: scale(1); box-shadow: 0 12px 40px rgba(6,182,212,0.25); }
            50% { transform: scale(1.06); box-shadow: 0 16px 50px rgba(6,182,212,0.35); }
        }
        .notes-empty h3 { font-weight: 900; font-size: 22px; color: #0f172a; margin: 0 0 10px 0; position: relative; }
        .notes-empty p { color: #6b7280; font-weight: 600; font-size: 15px; max-width: 400px; margin: 0 auto; line-height: 1.6; position: relative; }
    </style>
</head>
<body>
    <div class="s-main">
        <div class="notes-hero">
            <h1>
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg> 
                Study Notes
            </h1>
            <p>
                <?php if ($selSubject): ?>
                <a href="notes.php">📚 Subjects</a> /
                <strong style="color:#fff;">
                    <?= htmlspecialchars($selSubject)?>
                </strong>
                <?php else: ?>
                📖 Select a subject to view chapter-wise notes and resources.
                <?php endif; ?>
            </p>
        </div>

        <?php if (!$selSubject): ?>
        <!-- Subjects Grid -->
        <div class="s-grid-auto">
            <?php
    foreach ($subjects as $subj):
        $sLower = strtolower($subj);
        $svgIcon = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>';
        if (strpos($sLower, 'math') !== false) {
            $svgIcon = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect><line x1="9" y1="9" x2="15" y2="15"></line><line x1="15" y1="9" x2="9" y2="15"></line></svg>';
        } elseif (strpos($sLower, 'science') !== false || strpos($sLower, 'physics') !== false || strpos($sLower, 'chemistry') !== false) {
            $svgIcon = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 2v2"></path><path d="M15 2v2"></path><path d="M12 2v2"></path><path d="M10 18a2 2 0 1 0 4 0"></path><path d="M14 18v-4l3-3V6a2 2 0 0 0-2-2H9a2 2 0 0 0-2 2v5l3 3v4"></path></svg>';
        } elseif (strpos($sLower, 'english') !== false || strpos($sLower, 'hindi') !== false) {
            $svgIcon = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path><line x1="9" y1="9" x2="15" y2="9"></line><line x1="9" y1="13" x2="15" y2="13"></line></svg>';
        } elseif (strpos($sLower, 'computer') !== false) {
            $svgIcon = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>';
        } elseif (strpos($sLower, 'social') !== false || strpos($sLower, 'history') !== false) {
            $svgIcon = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>';
        }
?>
            <a href="notes.php?subject=<?= urlencode($subj)?>" style="text-decoration:none; color:inherit;">
                <div class="notes-subj-card">
                    <div class="notes-subj-icon">
                        <?= $svgIcon ?>
                    </div>
                    <div style="font-weight:900; font-size:20px; color:#1e1b4b; margin-bottom:14px;">
                        <?= htmlspecialchars($subj)?>
                    </div>
                    <div class="notes-enter-btn">
                        Enter Subject <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    </div>
                </div>
            </a>
            <?php
    endforeach; ?>

            <?php if (empty($subjects)): ?>
            <div class="notes-empty">
                <div class="notes-empty-icon">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                </div>
                <h3>No subjects found 📚</h3>
                <p>It looks like no notes have been uploaded for your class yet. Check back soon!</p>
            </div>
            <?php
    endif; ?>
        </div>

        <?php
else: ?>
        <!-- Chapters & Notes View -->
        <div style="display:flex; gap:12px; margin-bottom:12px;">
            <a href="notes.php" class="filter-select"
                style="text-decoration:none; background:#f1f5f9; color:#475569; display:inline-flex; align-items:center; gap:6px; font-weight:800; padding:12px 20px; border-radius:12px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> Back to Subjects
            </a>
            <form method="GET" style="display:flex; flex:1;">
                <input type="hidden" name="subject" value="<?= htmlspecialchars($selSubject)?>">
                <div style="position:relative; flex:1;">
                    <select name="chapter" class="filter-select" onchange="this.form.submit()" style="width:100%; border-radius:12px; padding-left:40px; font-weight:700; color:#1e1b4b; background:#fff; border:1.5px solid #cbd5e1; outline:none; height:46px;">
                        <option value="">📁 View All Chapters</option>
                        <?php foreach ($chapters as $ch): ?>
                        <option value="<?= $ch['chapter_id']?>" <?=$selChapter == $ch['chapter_id'] ? 'selected' : ''?>>
                            <?= htmlspecialchars($ch['chapter_name'])?>
                        </option>
                        <?php
        endforeach; ?>
                    </select>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="position:absolute; left:14px; top:14px; pointer-events:none;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                </div>
            </form>
        </div>

        <?php if (empty($notes)): ?>
        <div style="text-align: center; padding: 60px; background: #fff; border-radius: 20px;">
            <div style="margin-bottom: 20px; display:inline-flex; padding:20px; background:#f1f5f9; border-radius:50%;"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg></div>
            <h3 style="font-weight: 800; color: #1e1b4b;">No notes for this subject</h3>
            <p style="color: #64748b; font-weight:600;">Try selecting a different chapter or checking back later.</p>
        </div>
        <?php
    else: 
            // Group notes by Chapter
            $groupedNotes = [];
            foreach ($notes as $n) {
                $cname = $n['chapter_name'] ?: 'General Resources';
                $groupedNotes[$cname][] = $n;
            }
            
            foreach ($groupedNotes as $cname => $cNotes):
        ?>
        <h2 style="font-size: 20px; font-weight: 800; color: #1e1b4b; margin: 32px 0 16px 0; display:flex; align-items:center; gap:10px;">
            <span style="background:#eef2ff; color:#4DA2FF; padding: 6px 12px; border-radius: 8px; font-size: 14px; display:flex; align-items:center;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg> Chapter
            </span>
            <?= htmlspecialchars($cname) ?>
        </h2>
        <div class="s-grid-auto">
            <?php foreach ($cNotes as $n): 
                $fileUrl = !empty($n['google_drive_link']) ? $n['google_drive_link'] : '../uploads/' . $n['file_path'];
            ?>
            <div class="premium-glass-card hover-lift-strong" style="display:flex;flex-direction:column; padding: 28px;">
                <div style="flex:1;">
                    <h3 style="font-size:18px; font-weight:900; color:#1e1b4b; margin:0 0 12px 0; line-height:1.4;">
                        <?= htmlspecialchars($n['title'])?>
                    </h3>
                    <?php if (!empty($n['topic_name'])): ?>
                    <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px;">
                        <span style="font-size:12px; font-weight:800; background:#eef2ff; color:#4DA2FF; padding:4px 10px; border-radius:8px; display:inline-flex; align-items:center; gap:4px;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg> 
                            <?= htmlspecialchars($n['topic_name'])?>
                        </span>
                    </div>
                    <?php endif; ?>
                    <p style="font-size: 14px; color: #64748b; font-weight:500; line-height: 1.6; margin:0 0 20px 0;">
                        <?= nl2br(sanitize($n['description'] ?? ''))?>
                    </p>
                </div>
                <div class="action-footer">
                    <div style="font-size: 11px; font-weight: 700; color: #94a3b8; line-height:1.4; text-transform:uppercase; letter-spacing:0.5px;">
                        By <span style="font-weight:900; color:#1e1b4b;"><?= strtoupper(sanitize($n['teacher_name'] ?? 'Teacher'))?></span><br>
                        <?= date('d M Y', strtotime($n['created_at']))?>
                    </div>
                    <a href="<?= htmlspecialchars($fileUrl)?>" target="_blank" style="background:#4DA2FF; color:#fff; font-size:13px; font-weight:800; padding:10px 18px; border-radius:12px; box-shadow:0 8px 20px rgba(77,162,255,0.3); transition:all 0.2s; display:inline-flex; align-items:center; gap:6px; text-decoration:none;">
                        Open <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <?php
    endif; ?>
        <?php
endif; ?>
    </div>

</body>

</html>
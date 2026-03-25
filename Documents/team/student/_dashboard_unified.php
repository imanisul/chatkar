<?php
// Modern Clean LMS Dashboard
// All data is fetched from dashboard.php and passed here.
$liveClass = $liveClass ?? null; 
$weeklyTimetable = $weeklyTimetable ?? []; 
$isBirthday = $isBirthday ?? false;
$recentQuizzes = $recentQuizzes ?? [];
$myStreak = $myStreak ?? 0;
$syllabusPct = $syllabusPct ?? 0;
$chapDone = $chapDone ?? 0;
$chapTotal = $chapTotal ?? 10;
$mentor = $mentor ?? null;
$subjectProgress = $subjectProgress ?? [];
$myCoins = $myCoins ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Dashboard – HeyyGuru</title>
    <link rel="stylesheet" href="student.css?v=<?= time() ?>">
    <link rel="icon" href="../assets/img/favicon_hg.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body { background: #f8f9fb; color: #1a1a2e; padding-bottom: 80px; }
        .dash-container { max-width: 1100px; margin: 0 auto; padding: 32px 24px; }
        
        /* Clean Modern Cards */
        .dash-card { 
            background: #ffffff;
            border: 1px solid #e8ecf1;
            border-radius: 16px; 
            padding: 24px; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            transition: all 0.25s ease; 
        }
        .dash-card:hover { 
            box-shadow: 0 8px 25px rgba(0,0,0,0.06);
            transform: translateY(-2px);
        }
        
        .section-title { 
            font-size: 16px; 
            font-weight: 800; 
            color: #1a1a2e; 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            margin-bottom: 20px;
        }
        .section-title .st-icon {
            width: 36px; height: 36px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }

        /* Progress bars */
        .subj-progress { margin-bottom: 16px; }
        .subj-progress:last-child { margin-bottom: 0; }
        .subj-label { display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 13px; font-weight: 700; }
        .subj-bar { height: 8px; border-radius: 99px; background: #f1f3f8; overflow: hidden; }
        .subj-fill { height: 100%; border-radius: 99px; transition: width 1s cubic-bezier(0.4, 0, 0.2, 1); }

        /* Class items */
        .class-item {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 20px; border-radius: 14px;
            background: #ffffff; border: 1px solid #e8ecf1;
            margin-bottom: 12px; transition: all 0.25s;
        }
        .class-item:last-child { margin-bottom: 0; }
        .class-item:hover { border-color: #c7d2fe; box-shadow: 0 4px 12px rgba(79,70,229,0.08); }
        .class-item.is-live { border-color: #4DA2FF; background: #f5f3ff; }

        .live-dot { 
            width: 8px; height: 8px; 
            background: #ef4444; 
            border-radius: 50%; 
            display: inline-block;
            animation: livePulse 1.5s infinite;
        }
        @keyframes livePulse { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }

        /* Quiz/Notes items */
        .list-item {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 0;
            border-bottom: 1px solid #f1f3f8;
        }
        .list-item:last-child { border-bottom: none; }

        /* Note cards */
        .note-card {
            min-width: 200px; max-width: 240px;
            background: #f8f9fb; padding: 20px;
            border-radius: 14px; flex-shrink: 0;
            border: 1px solid #e8ecf1; transition: 0.25s;
        }
        .note-card:hover { box-shadow: 0 8px 20px rgba(0,0,0,0.06); transform: translateY(-2px); }

        /* Coin pill */
        .coin-pill {
            display: inline-flex; align-items: center; gap: 8px;
            background: #f0f0ff; color: #4DA2FF;
            padding: 8px 16px; border-radius: 12px;
            font-weight: 800; font-size: 15px;
            border: 1px solid #e0e0ff;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .dash-grid { grid-template-columns: 1fr !important; }
        }
        @media (max-width: 480px) {
            .dash-container { padding: 16px 12px; }
            .class-item { padding: 14px 16px; }
        }
    </style>
</head>
<body>
    <!-- First Login Popup -->
    <?php if (!isset($_SESSION['heyy_first_seen_2'])): $_SESSION['heyy_first_seen_2'] = true; ?>
    <div id="first-login-popup" style="position:fixed;inset:0;background:rgba(15,23,42,0.4);backdrop-filter:blur(8px);z-index:10000;display:flex;align-items:center;justify-content:center;">
        <div style="background:#fff;max-width:420px;width:90%;border-radius:20px;padding:40px;text-align:center;box-shadow:0 32px 64px rgba(0,0,0,0.12);">
            <div style="margin-bottom:16px;">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#4DA2FF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            </div>
            <h2 style="font-size:22px;font-weight:800;color:#1a1a2e;margin-bottom:8px;">Welcome to HeyyGuru</h2>
            <p style="color:#6b7280;line-height:1.6;margin-bottom:28px;font-weight:500;">Start exploring your courses, track progress, and achieve your learning goals.</p>
            <button onclick="document.getElementById('first-login-popup').remove()" style="width:100%;background:#4DA2FF;color:#fff;border:none;padding:14px;border-radius:12px;font-weight:700;cursor:pointer;font-size:15px;">Get Started</button>
        </div>
    </div>
    <?php endif; ?>

    <?php $navActive = 'dashboard'; require_once '_nav.php'; ?>

    <div class="dash-container">
        <!-- Greeting Header -->
        <div style="margin-bottom: 32px;">
            <h1 style="margin:0 0 4px 0;font-size:28px;font-weight:800;color:#1a1a2e;">
                <span style="display:flex;align-items:center;gap:12px;">Hello, <?= htmlspecialchars(explode(' ', (string)$s['name'])[0]) ?> <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="wave-icon"><path d="M18 11V6a2 2 0 0 0-2-2v0a2 2 0 0 0-2 2v0"></path><path d="M14 10V4a2 2 0 0 0-2-2v0a2 2 0 0 0-2 2v0"></path><path d="M10 10.5V6a2 2 0 0 0-2-2v0a2 2 0 0 0-2 2v0"></path><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"></path></svg></span>
            </h1>
            <p style="margin:0;color:#6b7280;font-weight:600;font-size:15px;">
                <?= date('l, d M Y') ?> · <?php 
                    $totalDay = count($todaySlots);
                    echo $totalDay > 0 ? "$totalDay classes today" : "No classes today — enjoy your free time!";
                ?>
            </p>
        </div>

        <!-- Main Grid -->
        <div class="dash-grid" style="display:grid;grid-template-columns:2fr 1fr;gap:28px;">
            <!-- Left Column -->
            <div style="display:flex;flex-direction:column;gap:24px;">
                
                <!-- Today's Classes -->
                <div class="dash-card">
                    <div class="section-title">
                        <span class="st-icon" style="background:#eef2ff;color:#4DA2FF;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        </span>
                        Today's Classes
                        <span style="margin-left:auto;font-size:13px;font-weight:700;color:#6b7280;"><?= date('l') ?></span>
                    </div>

                    <?php if(!empty($todaySlots)): ?>
                        <?php foreach($todaySlots as $row): 
                            $isLive = ($currentTime >= $row['start_time'] && $currentTime <= $row['end_time']);
                            $isFinished = ($currentTime > $row['end_time']);
                            $teacherName = $row['teacher_name'] ?? 'Teacher';
                            // Remove "Professor" prefix
                            $teacherName = preg_replace('/^(Prof\.?\s*|Professor\s*)/i', '', $teacherName);
                        ?>
                        <div class="class-item <?= $isLive ? 'is-live' : '' ?>">
                            <div style="display:flex;align-items:center;gap:16px;">
                                <div style="text-align:center;min-width:54px;">
                                    <div style="font-weight:800;color:#4DA2FF;font-size:14px;"><?= date('h:i', strtotime($row['start_time'])) ?></div>
                                    <div style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;"><?= date('A', strtotime($row['start_time'])) ?></div>
                                </div>
                                <div>
                                    <div style="font-weight:800;color:#1a1a2e;font-size:15px;margin-bottom:2px;">
                                        <?= htmlspecialchars($row['subject_name'] ?? $row['subject'] ?? 'Class') ?>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:#6b7280;font-weight:600;">
                                        <?php if($isLive): ?>
                                            <span class="live-dot"></span>
                                            <span style="color:#4DA2FF;font-weight:700;">Live Now</span>
                                        <?php elseif($isFinished): ?>
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                            <span style="color:#10b981;font-weight:700;">Completed</span>
                                        <?php else: ?>
                                            <span><?= htmlspecialchars($teacherName) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div style="text-align:right; min-width:120px;">
                                <?php if($isLive): ?>
                                    <a href="https://web.classplusapp.com/login" target="_blank" style="background:#4DA2FF;color:#fff;padding:10px 24px;border-radius:12px;font-size:14px;font-weight:800;text-decoration:none;display:inline-block;box-shadow:0 8px 16px -4px rgba(77,162,255,0.4);transition:all 0.2s;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 12px 20px -4px rgba(77,162,255,0.5)'" onmouseout="this.style.transform='none';this.style.boxShadow='0 8px 16px -4px rgba(77,162,255,0.4)'">Join Now</a>
                                    <div style="margin-top:8px;">
                                        <div onclick="copyOrgCode('MCDIDM', this)" style="display:inline-flex;align-items:center;gap:6px;background:#f0f7ff;color:#4DA2FF;padding:6px 10px;border-radius:10px;font-size:11px;font-weight:800;cursor:pointer;border:1px solid #e0efff;transition:all 0.2s;" title="Click to copy Org Code">
                                            <span style="color:#94a3b8;font-weight:600;">Code:</span> MCDIDM
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                        </div>
                                    </div>
                                <?php elseif(!$isFinished): ?>
                                    <a href="https://web.classplusapp.com/login" target="_blank" style="background:#f8f9fb;color:#64748b;padding:10px 20px;border-radius:12px;font-size:13px;font-weight:700;text-decoration:none;display:inline-block;border:1.5px solid #e2e8f0;transition:all 0.2s;" onmouseover="this.style.background='#f1f5f9';this.style.borderColor='#cbd5e1'" onmouseout="this.style.background='#f8f9fb';this.style.borderColor='#e2e8f0'">Join Soon</a>
                                    <div style="margin-top:8px;">
                                        <div onclick="copyOrgCode('MCDIDM', this)" style="display:inline-flex;align-items:center;gap:6px;background:#f0f7ff;color:#4DA2FF;padding:6px 10px;border-radius:10px;font-size:11px;font-weight:800;cursor:pointer;border:1px solid #e0efff;transition:all 0.2s;" title="Click to copy Org Code">
                                            <span style="color:#94a3b8;font-weight:600;">Code:</span> MCDIDM
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align:center;padding:40px;background:#f8f9fb;border-radius:14px;border:1.5px dashed #e8ecf1;">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:12px;"><path d="M18 11V6a2 2 0 0 0-2-2v0a2 2 0 0 0-2 2v0"></path><path d="M14 10V4a2 2 0 0 0-2-2v0a2 2 0 0 0-2 2v0"></path><path d="M10 10.5V6a2 2 0 0 0-2-2v0a2 2 0 0 0-2 2v0"></path><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"></path></svg>
                            <p style="font-weight:700;color:#1a1a2e;margin-bottom:4px;">No classes today</p>
                            <p style="font-size:13px;color:#6b7280;font-weight:600;">Use this time for self-study!</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Syllabus Tracker -->
                <div class="dash-card">
                    <div class="section-title">
                        <span class="st-icon" style="background:#f0fdf4;color:#16a34a;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="20" x2="12" y2="10"></line><line x1="18" y1="20" x2="18" y2="4"></line><line x1="6" y1="20" x2="6" y2="16"></line></svg>
                        </span>
                        Syllabus Tracker
                        <a href="syllabus.php" style="margin-left:auto;color:#4DA2FF;font-weight:700;text-decoration:none;font-size:13px;">View All</a>
                    </div>
                    
                    <?php if(!empty($subjectProgress)): ?>
                        <?php 
                        $progressColors = ['#4DA2FF','#16a34a','#0ea5e9','#7c3aed','#db2777','#0891b2','#ea580c','#4338ca'];
                        $pi = 0;
                        foreach($subjectProgress as $sp):
                            $spPct = $sp['total'] > 0 ? round(($sp['done'] / $sp['total']) * 100) : 0;
                            $clr = $progressColors[$pi % count($progressColors)];
                            $pi++;
                        ?>
                        <div class="subj-progress">
                            <div class="subj-label">
                                <div>
                                    <div style="color:#1a1a2e;font-weight:700;"><?= htmlspecialchars($sp['subject']) ?></div>
                                    <div style="font-size:11px;color:#64748b;font-weight:600;margin-top:2px;">
                                        Ongoing: <span style="color:#4DA2FF;"><?= htmlspecialchars($sp['ongoing_topic']) ?></span>
                                    </div>
                                    <div style="font-size:11px;color:#94a3b8;font-weight:700;margin-top:2px;">
                                        <?= (int)$sp['done'] ?> / <?= (int)$sp['total'] ?> <?= (int)$sp['total'] == 1 ? 'Chapter' : 'Chapters' ?> Completed
                                    </div>
                                </div>
                                <span style="color:<?= $clr ?>;font-weight:800;"><?= $spPct ?>%</span>
                            </div>
                            <div class="subj-bar">
                                <div class="subj-fill" style="width:<?= $spPct ?>%;background:<?= $clr ?>;"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align:center;padding:24px;color:#9ca3af;font-weight:600;font-size:14px;">
                            Syllabus data will appear here soon.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Quizzes -->
                <div class="dash-card">
                    <div class="section-title">
                        <span class="st-icon" style="background:#fef3c7;color:#b45309;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                        </span>
                        Recent Quizzes
                        <a href="quiz.php" style="margin-left:auto;color:#4DA2FF;font-weight:700;text-decoration:none;font-size:13px;">View All</a>
                    </div>
                    <?php if(empty($recentQuizzes)): ?>
                        <div style="text-align:center;padding:24px;color:#9ca3af;font-weight:600;font-size:14px;">
                            No quizzes attempted yet. Start one today!
                        </div>
                    <?php else: foreach($recentQuizzes as $q): ?>
                        <a href="quiz.php?result=<?= $q['id'] ?>" class="list-item" style="text-decoration:none; transition: background 0.2s; border-radius: 12px; padding-left: 10px; padding-right: 10px; margin-left: -10px; margin-right: -10px;">
                            <div style="display:flex;align-items:center;gap:12px;">
                                <div style="width:40px;height:40px;background:#f0fdf4;border-radius:10px;display:flex;align-items:center;justify-content:center;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                                </div>
                                <div>
                                    <div style="font-weight:700;color:#1a1a2e;font-size:14px;"><?= htmlspecialchars($q['quiz_title']) ?></div>
                                    <div style="font-size:12px;color:#9ca3af;font-weight:600;">View Full Result & Mistakes</div>
                                </div>
                            </div>
                            <div style="font-weight:900;color:#16a34a;font-size:16px; background:#dcfce7; padding:4px 10px; border-radius:8px;"><?= (int)$q['score'] ?>/<?= (int)$q['total_marks'] ?></div>
                        </a>
                    <?php endforeach; endif; ?>
                </div>

                <!-- Recent Notes -->
                <div class="dash-card">
                    <div class="section-title">
                        <span class="st-icon" style="background:#eef2ff;color:#4DA2FF;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                        </span>
                        Recent Notes
                        <a href="notes.php" style="margin-left:auto;color:#4DA2FF;font-weight:700;text-decoration:none;font-size:13px;">View All</a>
                    </div>
                    
                    <?php if(empty($latestNotes)): ?>
                        <div style="text-align:center;padding:24px;color:#9ca3af;font-weight:600;font-size:14px;">
                            Notes will appear here when uploaded.
                        </div>
                    <?php else: ?>
                        <div style="display:flex;overflow-x:auto;gap:14px;padding-bottom:4px;scrollbar-width:none;">
                            <?php foreach(array_slice($latestNotes, 0, 4) as $n): ?>
                            <div class="note-card">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                                    <div style="width:32px;height:32px;background:#eef2ff;border-radius:8px;display:flex;align-items:center;justify-content:center;">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#4DA2FF" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                                    </div>
                                    <span style="font-size:11px;font-weight:700;color:#6b7280;"><?= htmlspecialchars($n['chapter_name'] ?? 'General') ?></span>
                                </div>
                                <div style="font-weight:700;color:#1a1a2e;font-size:14px;margin-bottom:12px;line-height:1.4;">
                                    <?= htmlspecialchars($n['topic_name']) ?>
                                </div>
                                <a href="../uploads/<?= htmlspecialchars($n['file_path']) ?>" target="_blank" style="display:inline-flex;align-items:center;gap:6px;color:#4DA2FF;font-weight:700;font-size:12px;text-decoration:none;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                                    Open
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Right Column -->
            <div style="display:flex;flex-direction:column;gap:24px;">
                
                <!-- Coin Balance -->
                <div class="dash-card" style="text-align:center;padding:28px;">
                    <div style="margin-bottom:12px;">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#4DA2FF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v12M8 10h8M8 14h8"></path></svg>
                    </div>
                    <div style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">My Coins</div>
                    <div style="font-size:32px;font-weight:900;color:#4DA2FF;"><?= (int)$myCoins ?></div>
                </div>

                <!-- Streak -->
                <div class="dash-card" style="text-align:center;padding:28px;">
                    <div style="margin-bottom:12px;">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#4DA2FF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.292 1-3a2.5 2.5 0 0 0 2.5 2.5z"></path></svg>
                    </div>
                    <div style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Login Streak</div>
                    <div style="font-size:28px;font-weight:900;color:#4DA2FF;"><?= (int)$myStreak ?> <span style="font-size:14px;font-weight:700;color:#7cbaf1;">days</span></div>
                </div>

                <!-- Overall Progress -->
                <div class="dash-card" style="padding:28px;">
                    <div style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:1px;margin-bottom:16px;text-align:center;">Overall Progress</div>
                    <div style="position:relative;width:100px;height:100px;margin:0 auto 16px;">
                        <svg viewBox="0 0 36 36" style="width:100%;height:100%;transform:rotate(-90deg);">
                            <circle cx="18" cy="18" r="15.5" fill="none" stroke="#f1f3f8" stroke-width="3"></circle>
                            <circle cx="18" cy="18" r="15.5" fill="none" stroke="#4DA2FF" stroke-width="3" 
                                    stroke-dasharray="<?= $syllabusPct ?> <?= 100 - $syllabusPct ?>" 
                                    stroke-linecap="round"></circle>
                        </svg>
                        <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:900;color:#4DA2FF;"><?= $syllabusPct ?>%</div>
                    </div>
                    <div style="text-align:center;font-size:13px;color:#6b7280;font-weight:600;"><?= $chapDone ?> of <?= $chapTotal ?> chapters completed</div>
                </div>

                <!-- Pending Homework -->
                <?php if(!empty($pendingHomework)): ?>
                <div class="dash-card">
                    <div class="section-title">
                        <span class="st-icon" style="background:#fef2f2;color:#dc2626;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14l2 2 4-4"></path></svg>
                        </span>
                        Homework
                    </div>
                    <?php foreach($pendingHomework as $hw): ?>
                    <div class="list-item">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="width:36px;height:36px;background:#fef2f2;border-radius:8px;display:flex;align-items:center;justify-content:center;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                            </div>
                            <div>
                                <div style="font-weight:700;color:#1a1a2e;font-size:13px;"><?= htmlspecialchars($hw['title'] ?? $hw['chapter_name'] ?? 'Homework') ?></div>
                                <?php if(!empty($hw['due_date'])): ?>
                                <div style="font-size:11px;color:#dc2626;font-weight:600;">Due: <?= date('d M', strtotime($hw['due_date'])) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <a href="homework.php" style="display:block;text-align:center;color:#4DA2FF;font-weight:700;font-size:13px;text-decoration:none;margin-top:12px;">View All Homework</a>
                </div>
                <?php endif; ?>

                <!-- Mentor Card -->
                <?php if($mentor): ?>
                <div class="dash-card" style="text-align:center;padding:28px;">
                    <div style="font-size:11px;font-weight:800;color:#9ca3af;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:20px;">My Mentor</div>
                    
                    <div style="position:relative;margin-bottom:16px;display:inline-block;">
                        <?php $m_ava = (!empty($mentor['profile_pic'])) ? htmlspecialchars($mentor['profile_pic']) : '../assets/img/mentor_avatar.png'; ?>
                        <img src="<?= $m_ava ?>" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:3px solid #e8ecf1;" onerror="this.src='https://ui-avatars.com/api/?name=Mentor&background=4f46e5&color=fff'">
                    </div>
                    
                    <div style="font-size:16px;font-weight:800;color:#1a1a2e;margin-bottom:4px;">
                        <?= htmlspecialchars(preg_replace('/^(Prof\.?\s*|Professor\s*)/i', '', $mentor['name'])) ?>
                    </div>
                    <div style="font-size:12px;color:#6b7280;font-weight:600;margin-bottom:20px;"><?= htmlspecialchars($mentor['phone'] ?? 'No phone available') ?></div>
                    
                    <div style="display:flex;gap:10px;">
                        <a href="https://wa.me/<?= preg_replace('/[^0-9]/','',(string)($mentor['phone'] ?? '')) ?>" target="_blank" style="flex:1;text-decoration:none;background:#f0fdf4;color:#16a34a;padding:10px;border-radius:10px;font-weight:700;font-size:12px;border:1px solid #dcfce7;">WhatsApp</a>
                        <a href="tel:<?= preg_replace('/[^0-9+]/','',(string)($mentor['phone'] ?? '')) ?>" style="flex:1;text-decoration:none;background:#eef2ff;color:#4DA2FF;padding:10px;border-radius:10px;font-weight:700;font-size:12px;border:1px solid #e0e7ff;">Call</a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Doubt Sessions Card -->
                <div class="dash-card" style="padding:28px; position:relative; overflow:hidden; margin-top:20px;">
                    <div style="font-size:11px;font-weight:800;color:#9ca3af;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:20px;">My Doubts</div>
                    
                    <div style="display:flex; align-items:center; gap:16px; margin-bottom:24px;">
                        <div style="width:54px; height:54px; border-radius:16px; background:linear-gradient(135deg, #e0e7ff, #c7d2fe); display:flex; align-items:center; justify-content:center;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
                        </div>
                        <div>
                            <div style="font-size:32px; font-weight:900; color:#1e293b; line-height:1;"><?= (int)($totalDoubts ?? 0) ?></div>
                            <div style="font-size:13px; font-weight:700; color:#64748b; margin-top:4px;">Total Doubts</div>
                        </div>
                    </div>
                    
                    <a href="doubts.php" style="display:block; text-align:center; background:#4f46e5; color:white; padding:12px; border-radius:12px; font-size:14px; font-weight:700; text-decoration:none; transition:transform 0.2s;">
                        View All Doubts
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        if (typeof lucide !== 'undefined') lucide.createIcons();

        function copyOrgCode(code, el) {
            navigator.clipboard.writeText(code).then(() => {
                const originalContent = el.innerHTML;
                el.style.borderColor = '#4DA2FF';
                el.style.background = '#4DA2FF';
                el.style.color = '#ffffff';
                el.innerHTML = '<span style="color:white;font-weight:800;">Copied!</span>';
                setTimeout(() => {
                    el.style.borderColor = '#e0efff';
                    el.style.background = '#f0f7ff';
                    el.style.color = '#4DA2FF';
                    el.innerHTML = originalContent;
                }, 1500);
            });
        }
    </script>
</body>
</html>

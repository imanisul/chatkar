<?php
/**
 * HeyyGuru — One-Click Patch Deployer
 * 
 * HOW TO USE:
 * 1. Upload this file to your Hostinger server root (public_html/) via File Manager
 * 2. Visit: https://team.heyyguru.in/deploy_patch.php?token=hg_fix_2026
 * 3. DELETE this file immediately after
 */

if (($_GET['token'] ?? '') !== 'hg_fix_2026') {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/html; charset=utf-8');
echo '<pre style="font-family:monospace;background:#1a1a2e;color:#e0e0e0;padding:30px;font-size:14px;">';
echo "🔧 HeyyGuru Patch Deployer — " . date('d M Y H:i:s') . "\n";
echo str_repeat('─', 60) . "\n\n";

$results = [];

// ═══════════════════════════════════════════
// PATCH 1: includes/student_notifications.php
// ═══════════════════════════════════════════
$file1 = __DIR__ . '/includes/student_notifications.php';
$url1 = 'https://raw.githubusercontent.com/imanisul/chatkar/main/Documents/team/includes/student_notifications.php';

echo "📄 Patching includes/student_notifications.php...\n";
$content1 = @file_get_contents($url1);
if ($content1 && strlen($content1) > 1000) {
    if (file_put_contents($file1, $content1)) {
        echo "   ✅ SUCCESS — Streak fix applied (" . strlen($content1) . " bytes)\n\n";
    } else {
        echo "   ❌ FAILED — Could not write file\n\n";
    }
} else {
    echo "   ❌ FAILED — Could not download from GitHub\n";
    echo "   Trying alternate raw URL...\n";
    // Try without Documents/team prefix
    $url1b = 'https://raw.githubusercontent.com/imanisul/chatkar/main/includes/student_notifications.php';
    $content1 = @file_get_contents($url1b);
    if ($content1 && strlen($content1) > 1000) {
        if (file_put_contents($file1, $content1)) {
            echo "   ✅ SUCCESS (alt URL) — Streak fix applied (" . strlen($content1) . " bytes)\n\n";
        } else {
            echo "   ❌ FAILED — Could not write file\n\n";
        }
    } else {
        echo "   ❌ FAILED — Could not download. Will apply inline patch.\n";
        // Apply inline if download fails
        applyInlineStreakPatch($file1);
        echo "\n";
    }
}

// ═══════════════════════════════════════════
// PATCH 2: modules/batches/edit.php
// ═══════════════════════════════════════════
$file2 = __DIR__ . '/modules/batches/edit.php';
echo "📄 Patching modules/batches/edit.php...\n";

if (file_exists($file2)) {
    $content = file_get_contents($file2);
    $oldCode = '.then(data => { classData = data; })';
    
    if (strpos($content, $oldCode) !== false) {
        $newCode = ".then(data => { \n                                    classData = data; \n                                    // Re-render syllabus preview & teacher assignments now that chapters data is loaded\n                                    updateDynamicAreas(); \n                                })";
        $content = str_replace($oldCode, $newCode, $content);
        $content = str_replace(
            '// Silently fetch class data in background to populate chapters for syllabus preview',
            '// Fetch class data in background to populate chapters for syllabus preview',
            $content
        );
        if (file_put_contents($file2, $content)) {
            echo "   ✅ SUCCESS — Chapter re-render fix applied\n\n";
        } else {
            echo "   ❌ FAILED — Could not write file\n\n";
        }
    } elseif (strpos($content, 'updateDynamicAreas()') !== false && strpos($content, 'classData = data;') !== false) {
        echo "   ✅ ALREADY PATCHED — No changes needed\n\n";
    } else {
        echo "   ⚠️ Target code not found — file may have different format\n\n";
    }
} else {
    echo "   ❌ File not found: $file2\n\n";
}

// ═══════════════════════════════════════════
// PATCH 3: modules/batches/ajax_get_class_data.php
// ═══════════════════════════════════════════
$file3 = __DIR__ . '/modules/batches/ajax_get_class_data.php';
echo "📄 Patching modules/batches/ajax_get_class_data.php...\n";

$url3 = 'https://raw.githubusercontent.com/imanisul/chatkar/main/Documents/team/modules/batches/ajax_get_class_data.php';
$content3 = @file_get_contents($url3);
if (!$content3 || strlen($content3) < 500) {
    $url3b = 'https://raw.githubusercontent.com/imanisul/chatkar/main/modules/batches/ajax_get_class_data.php';
    $content3 = @file_get_contents($url3b);
}

if ($content3 && strlen($content3) > 500) {
    if (file_put_contents($file3, $content3)) {
        echo "   ✅ SUCCESS — Class variations fix applied (" . strlen($content3) . " bytes)\n\n";
    } else {
        echo "   ❌ FAILED — Could not write file\n\n";
    }
} else {
    echo "   ❌ Could not download from GitHub. Applying inline patch...\n";
    applyInlineAjaxPatch($file3);
    echo "\n";
}

// ═══════════════════════════════════════════
// PATCH 4: modules/syllabus/index.php (Teacher subject restriction)
// ═══════════════════════════════════════════
$file4 = __DIR__ . '/modules/syllabus/index.php';
echo "📄 Patching modules/syllabus/index.php...\n";

$url4 = 'https://raw.githubusercontent.com/imanisul/chatkar/main/Documents/team/modules/syllabus/index.php';
$content4 = @file_get_contents($url4);
if (!$content4 || strlen($content4) < 1000) {
    $url4b = 'https://raw.githubusercontent.com/imanisul/chatkar/main/modules/syllabus/index.php';
    $content4 = @file_get_contents($url4b);
}

if ($content4 && strlen($content4) > 1000) {
    if (file_put_contents($file4, $content4)) {
        echo "   ✅ SUCCESS — Teacher subject restriction applied (" . strlen($content4) . " bytes)\n\n";
    } else {
        echo "   ❌ FAILED — Could not write file\n\n";
    }
} else {
    // Inline fallback: check if the old single-source query exists
    if (file_exists($file4)) {
        $c4 = file_get_contents($file4);
        if (strpos($c4, 'batch_teachers') !== false) {
            echo "   ✅ ALREADY PATCHED — Multi-source teacher filtering exists\n\n";
        } else {
            echo "   ⚠️ Could not download from GitHub — manual patch needed\n\n";
        }
    } else {
        echo "   ❌ File not found: $file4\n\n";
    }
}

// ═══════════════════════════════════════════
// PATCH 5: modules/quiz/index.php (Teacher quiz restrictions)
// ═══════════════════════════════════════════
$file5 = __DIR__ . '/modules/quiz/index.php';
echo "📄 Patching modules/quiz/index.php...\n";

$url5 = 'https://raw.githubusercontent.com/imanisul/chatkar/main/Documents/team/modules/quiz/index.php';
$content5 = @file_get_contents($url5);
if (!$content5 || strlen($content5) < 1000) {
    $url5b = 'https://raw.githubusercontent.com/imanisul/chatkar/main/modules/quiz/index.php';
    $content5 = @file_get_contents($url5b);
}

if ($content5 && strlen($content5) > 1000) {
    if (file_put_contents($file5, $content5)) {
        echo "   ✅ SUCCESS — Teacher quiz restrictions applied (" . strlen($content5) . " bytes)\n\n";
    } else {
        echo "   ❌ FAILED — Could not write file\n\n";
    }
} else {
    // Inline fallback
    if (file_exists($file5)) {
        $c5 = file_get_contents($file5);
        if (strpos($c5, 'teacher_subjects') !== false) {
            echo "   ✅ ALREADY PATCHED — Multi-source teacher filtering exists\n\n";
        } else {
            echo "   ⚠️ Could not download from GitHub — manual patch needed\n\n";
        }
    } else {
        echo "   ❌ File not found: $file5\n\n";
    }
}

// ═══════════════════════════════════════════
// PATCH 6: modules/quiz/quiz_builder.php (Teacher subject restrictions in smart builder)
// ═══════════════════════════════════════════
$file6 = __DIR__ . '/modules/quiz/quiz_builder.php';
echo "📄 Patching modules/quiz/quiz_builder.php...\n";

$url6 = 'https://raw.githubusercontent.com/imanisul/chatkar/main/Documents/team/modules/quiz/quiz_builder.php';
$content6 = @file_get_contents($url6);
if (!$content6 || strlen($content6) < 1000) {
    $url6b = 'https://raw.githubusercontent.com/imanisul/chatkar/main/modules/quiz/quiz_builder.php';
    $content6 = @file_get_contents($url6b);
}

if ($content6 && strlen($content6) > 1000) {
    if (file_put_contents($file6, $content6)) {
        echo "   ✅ SUCCESS — Teacher quiz builder restrictions applied (" . strlen($content6) . " bytes)\n\n";
    } else {
        echo "   ❌ FAILED — Could not write file\n\n";
    }
} else {
    if (file_exists($file6)) {
        $c6 = file_get_contents($file6);
        if (strpos($c6, 'teacher_subjects') !== false) {
            echo "   ✅ ALREADY PATCHED — Multi-source teacher filtering exists\n\n";
        } else {
            echo "   ⚠️ Could not download from GitHub — manual patch needed\n\n";
        }
    } else {
        echo "   ❌ File not found: $file6\n\n";
    }
}

echo str_repeat('─', 60) . "\n";
echo "🏁 DONE — All patches attempted.\n";
echo "⚠️  DELETE THIS FILE (deploy_patch.php) NOW!\n";
echo '</pre>';

// ═══════════════════════════════════════════════════════
// INLINE PATCH FUNCTIONS (fallback if GitHub download fails)
// ═══════════════════════════════════════════════════════

function applyInlineStreakPatch($file) {
    if (!file_exists($file)) {
        echo "   ❌ File not found\n";
        return;
    }
    $content = file_get_contents($file);
    
    // Check if already patched
    if (strpos($content, '$consecutiveDaysBack') !== false) {
        echo "   ✅ ALREADY PATCHED\n";
        return;
    }

    // Replace the updateStreak function body
    $oldFunc = '/function updateStreak\(PDO \$db, int \$studentId\): array\s*\{.*?^    return \[0, 0\];\s*\}\s*\}/ms';
    
    $newFunc = <<<'FUNC'
function updateStreak(PDO $db, int $studentId): array
{
    try {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS student_streaks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                current_streak INT DEFAULT 1,
                longest_streak INT DEFAULT 1,
                last_activity_date DATE,
                streak_date DATE DEFAULT NULL,
                activity_type VARCHAR(50) DEFAULT NULL,
                UNIQUE KEY unique_student (student_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}

        $tz = new DateTimeZone('Asia/Kolkata');
        $todayDt = new DateTime('now', $tz);
        $today = $todayDt->format('Y-m-d');

        $q = $db->prepare("SELECT * FROM student_streaks WHERE student_id=?");
        $q->execute([$studentId]);
        $row = $q->fetch();

        if (!$row) {
            $db->prepare("INSERT INTO student_streaks (student_id, current_streak, longest_streak, last_activity_date) VALUES (?,1,1,?)")
                ->execute([$studentId, $today]);
            return [1, 1];
        }

        $last = $row['last_activity_date'];

        if ($last === $today) {
            return [(int)$row['current_streak'], (int)$row['longest_streak']];
        }

        $currentStoredStreak = (int)$row['current_streak'];
        $storedLongest = (int)$row['longest_streak'];

        $checkDate = clone $todayDt;
        $checkDate->modify('-1 day');
        $lastDate = new DateTime($last, $tz);
        $consecutiveDaysBack = 0;

        while ($checkDate >= $lastDate) {
            $dateStr = $checkDate->format('Y-m-d');

            if ($dateStr === $last) {
                $consecutiveDaysBack += $currentStoredStreak;
                break;
            }

            if (studentHadActivityOnDate($db, $studentId, $dateStr)) {
                $consecutiveDaysBack++;
                $checkDate->modify('-1 day');
            } else {
                break;
            }
        }

        if ($consecutiveDaysBack > 0) {
            $newCurrent = $consecutiveDaysBack + 1;
        } else {
            $newCurrent = 1;
        }

        $newLongest = max($newCurrent, $storedLongest);

        $db->prepare("UPDATE student_streaks SET current_streak=?, longest_streak=?, last_activity_date=? WHERE student_id=?")
            ->execute([$newCurrent, $newLongest, $today, $studentId]);

        return [$newCurrent, $newLongest];
    }
    catch (Exception $e) {
        error_log("updateStreak ERROR for student $studentId: " . $e->getMessage());
        return [0, 0];
    }
}
FUNC;

    $newContent = preg_replace($oldFunc, $newFunc, $content, 1, $count);
    if ($count > 0 && $newContent) {
        if (file_put_contents($file, $newContent)) {
            echo "   ✅ SUCCESS (inline regex patch)\n";
        } else {
            echo "   ❌ FAILED — Could not write\n";
        }
    } else {
        echo "   ⚠️ Regex pattern did not match — manual patch needed\n";
    }
}

function applyInlineAjaxPatch($file) {
    if (!file_exists($file)) {
        echo "   ❌ File not found\n";
        return;
    }
    $content = file_get_contents($file);
    
    if (strpos($content, 'getClassVariations') !== false) {
        echo "   ✅ ALREADY PATCHED\n";
        return;
    }

    // Replace exact match with class variations
    $old1 = "SELECT DISTINCT subject FROM syllabus WHERE class=?";
    $old2 = "SELECT topic FROM syllabus WHERE class=? AND subject=?";
    
    if (strpos($content, $old1) !== false) {
        // Full replacement approach
        $content = str_replace(
            "\$subjStmt = \$db->prepare(\"SELECT DISTINCT subject FROM syllabus WHERE class=? AND subject IS NOT NULL AND subject!='' ORDER BY subject\");\n    \$subjStmt->execute([\$class]);",
            "\$classVariations = getClassVariations([\$class]);\n    if (empty(\$classVariations)) \$classVariations = [\$class];\n    \$placeholders = implode(',', array_fill(0, count(\$classVariations), '?'));\n    \$classParams = array_values(\$classVariations);\n\n    \$subjStmt = \$db->prepare(\"SELECT DISTINCT subject FROM syllabus WHERE class IN (\$placeholders) AND subject IS NOT NULL AND subject!='' ORDER BY subject\");\n    \$subjStmt->execute(\$classParams);",
            $content
        );
        
        $content = str_replace(
            "\$chStmt = \$db->prepare(\"SELECT topic FROM syllabus WHERE class=? AND subject=? AND topic IS NOT NULL AND topic!='' ORDER BY topic\");\n        \$chStmt->execute([\$class, \$subj]);\n        \$response['chapters'][\$subj] = \$chStmt->fetchAll(PDO::FETCH_COLUMN);",
            "\$chStmt = \$db->prepare(\"SELECT topic FROM syllabus WHERE class IN (\$placeholders) AND subject=? AND topic IS NOT NULL AND topic!='' ORDER BY topic\");\n        \$chParams = \$classParams;\n        \$chParams[] = \$subj;\n        \$chStmt->execute(\$chParams);\n        \$topics = \$chStmt->fetchAll(PDO::FETCH_COLUMN);\n\n        if (empty(\$topics)) {\n            \$chStmt2 = \$db->prepare(\"SELECT chapter_name FROM chapters WHERE class IN (\$placeholders) AND subject=? AND chapter_name IS NOT NULL AND chapter_name!='' ORDER BY chapter_name\");\n            \$chStmt2->execute(\$chParams);\n            \$topics = \$chStmt2->fetchAll(PDO::FETCH_COLUMN);\n        }\n\n        \$response['chapters'][\$subj] = \$topics;",
            $content
        );
        
        if (file_put_contents($file, $content)) {
            echo "   ✅ SUCCESS (inline str_replace patch)\n";
        } else {
            echo "   ❌ FAILED — Could not write\n";
        }
    } else {
        echo "   ⚠️ Target SQL not found — manual patch needed\n";
    }
}

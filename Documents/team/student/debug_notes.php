<?php
/**
 * Diagnostic: Check notes table data for subject=EVS
 * Deploy this to the server and open in browser to see what's in the DB.
 * DELETE after use.
 */
require_once '../includes/db.php';
$db = getDB();

echo "<pre style='font-family:monospace;font-size:13px;background:#f8f9fa;padding:20px;'>";

// 1. Check notes table structure
echo "=== NOTES TABLE COLUMNS ===\n";
$cols = $db->query("SHOW COLUMNS FROM notes")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo sprintf("  %-25s %-15s %s\n", $c['Field'], $c['Type'], $c['Null']);
}

// 2. Show ALL distinct subject_ids
echo "\n=== ALL DISTINCT subject_id VALUES ===\n";
$subjects = $db->query("SELECT DISTINCT subject_id, COUNT(*) as cnt FROM notes GROUP BY subject_id ORDER BY subject_id")->fetchAll();
foreach ($subjects as $s) {
    echo sprintf("  [%s] => %d notes\n", var_export($s['subject_id'], true), $s['cnt']);
}

// 3. Show ALL distinct class_name values
echo "\n=== ALL DISTINCT class_name VALUES ===\n";
$classes = $db->query("SELECT DISTINCT class_name, COUNT(*) as cnt FROM notes GROUP BY class_name ORDER BY class_name")->fetchAll();
foreach ($classes as $c) {
    echo sprintf("  [%s] => %d notes\n", var_export($c['class_name'], true), $c['cnt']);
}

// 4. Show ALL distinct status values
echo "\n=== ALL DISTINCT status VALUES ===\n";
try {
    $statuses = $db->query("SELECT DISTINCT status, COUNT(*) as cnt FROM notes GROUP BY status ORDER BY status")->fetchAll();
    foreach ($statuses as $s) {
        echo sprintf("  [%s] => %d notes\n", var_export($s['status'], true), $s['cnt']);
    }
} catch (Exception $e) {
    echo "  No status column exists\n";
}

// 5. Show EVS notes specifically
echo "\n=== NOTES WHERE subject_id = 'EVS' ===\n";
$evs = $db->query("SELECT id, title, subject_id, class_name, status, created_at FROM notes WHERE subject_id = 'EVS'")->fetchAll();
if (empty($evs)) {
    echo "  NO ROWS FOUND\n";
    // Try case-insensitive
    echo "\n=== TRYING CASE-INSENSITIVE: subject_id LIKE '%evs%' ===\n";
    $evs2 = $db->query("SELECT id, title, subject_id, class_name, status, created_at FROM notes WHERE LOWER(subject_id) LIKE '%evs%'")->fetchAll();
    foreach ($evs2 as $n) {
        echo sprintf("  ID:%d | title:[%s] | subject:[%s] | class:[%s] | status:[%s] | date:%s\n", 
            $n['id'], $n['title'], $n['subject_id'], $n['class_name'], $n['status'] ?? 'NULL', $n['created_at']);
    }
    if (empty($evs2)) echo "  STILL NO ROWS\n";
} else {
    foreach ($evs as $n) {
        echo sprintf("  ID:%d | title:[%s] | subject:[%s] | class:[%s] | status:[%s] | date:%s\n", 
            $n['id'], $n['title'], $n['subject_id'], $n['class_name'], $n['status'] ?? 'NULL', $n['created_at']);
    }
}

// 6. Show student_streaks table
echo "\n=== STUDENT_STREAKS TABLE ===\n";
try {
    $streaks = $db->query("SELECT * FROM student_streaks ORDER BY student_id LIMIT 20")->fetchAll();
    if (empty($streaks)) {
        echo "  TABLE EMPTY\n";
    } else {
        foreach ($streaks as $s) {
            echo sprintf("  student:%d | current:%d | longest:%d | last_date:%s\n", 
                $s['student_id'], $s['current_streak'], $s['longest_streak'], $s['last_activity_date'] ?? 'NULL');
        }
    }
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// 7. Show student class info
echo "\n=== FIRST 5 STUDENTS (class info) ===\n";
$students = $db->query("SELECT id, name, class, batch_id FROM students LIMIT 5")->fetchAll();
foreach ($students as $s) {
    echo sprintf("  ID:%d | name:[%s] | class:[%s] | batch:%s\n", $s['id'], $s['name'], $s['class'], $s['batch_id'] ?? 'NULL');
}

echo "\n=== DONE ===\n";
echo "</pre>";

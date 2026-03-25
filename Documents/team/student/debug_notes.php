<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    require_once '../includes/db.php';
    $db = getDB();

    echo "<pre style='font-family:monospace;font-size:13px;background:#f8f9fa;padding:20px;'>";
    echo "=== SYSTEM CHECK ===\n";
    echo "PHP Version: " . phpversion() . "\n";
    
    // 1. Check notes table structure
    echo "\n=== NOTES TABLE COLUMNS ===\n";
    $cols = $db->query("SHOW COLUMNS FROM notes")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo sprintf("  %-25s %-15s %s\n", $c['Field'], $c['Type'], $c['Null']);
    }

    // 2. Show ALL distinct subject_ids
    echo "\n=== ALL DISTINCT subject_id VALUES ===\n";
    $subjects = $db->query("SELECT DISTINCT subject_id, COUNT(*) as cnt FROM notes GROUP BY subject_id ORDER BY subject_id")->fetchAll();
    foreach ($subjects as $s) {
        $sid = isset($s['subject_id']) ? $s['subject_id'] : 'NULL';
        echo sprintf("  [%s] => %d notes\n", $sid, $s['cnt']);
    }

    // 3. Show Maths notes specifically
    echo "\n=== NOTES WHERE subject_id LIKE '%Math%' ===\n";
    $maths = $db->query("SELECT id, title, subject_id, class_name, status, created_at FROM notes WHERE subject_id LIKE '%Math%'")->fetchAll();
    if (empty($maths)) {
        echo "  NO ROWS FOUND\n";
    } else {
        foreach ($maths as $n) {
            $st = isset($n['status']) ? $n['status'] : 'NULL';
            echo sprintf("  ID:%d | title:[%s] | subject:[%s] | class:[%s] | status:[%s] | date:%s\n", 
                $n['id'], $n['title'], $n['subject_id'], $n['class_name'], $st, $n['created_at']);
        }
    }

    echo "\n=== DONE ===\n";
    echo "</pre>";

} catch (Throwable $e) {
    echo "<h1>Critical Error</h1>";
    echo "<pre>" . $e->getMessage() . "\n" . $e->getTraceAsString() . "</pre>";
}

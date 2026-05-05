<?php
require_once '../../includes/db.php';

// Always respond with JSON - must set header BEFORE any auth check
// so that if the session has expired and requireRole() redirects, we
// can catch it cleanly. We override the redirect for AJAX calls.
header('Content-Type: application/json');

// Check login manually so we can return JSON instead of a redirect
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'session_expired', 'message' => 'Your session has expired. Please refresh the page and log in again.']);
    exit;
}

// Refresh the idle-timeout clock (same as checkIdleTimeout does)
$_SESSION['last_activity'] = time();

// Role check
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'mentor'])) {
    echo json_encode(['success' => false, 'error' => 'unauthorized', 'message' => 'You do not have permission to access this data.']);
    exit;
}

$class = trim($_GET['class'] ?? '');

$response = [
    'success' => false,
    'subjects' => [],
    'chapters' => [], // Format: ['Subject' => ['Chapter 1', 'Chapter 2']]
    'students' => []
];

if (!$class) {
    echo json_encode($response);
    exit;
}

$db = getDB();

try {
    // Build class variations for fuzzy matching (e.g., "Class 10" also matches "10", "10th", etc.)
    $classVariations = getClassVariations([$class]);
    if (empty($classVariations)) $classVariations = [$class];
    $placeholders = implode(',', array_fill(0, count($classVariations), '?'));
    $classParams = array_values($classVariations);

    // 1. Fetch distinct subjects for this class from syllabus (using variations)
    $subjStmt = $db->prepare("SELECT DISTINCT subject FROM syllabus WHERE class IN ($placeholders) AND subject IS NOT NULL AND subject!='' ORDER BY subject");
    $subjStmt->execute($classParams);
    $subjects = $subjStmt->fetchAll(PDO::FETCH_COLUMN);

    // Also try chapters table if syllabus has nothing
    if (empty($subjects)) {
        $subjStmt2 = $db->prepare("SELECT DISTINCT subject FROM chapters WHERE class IN ($placeholders) AND subject IS NOT NULL AND subject!='' ORDER BY subject");
        $subjStmt2->execute($classParams);
        $subjects = $subjStmt2->fetchAll(PDO::FETCH_COLUMN);
    }

    if (empty($subjects)) {
        $subjects = ['English Grammar', 'EVS', 'Hindi', 'Maths', 'Science', 'Social Science', 'Computer', 'Sanskrit'];
    }

    $response['subjects'] = $subjects;

    // 2. Fetch chapters per subject (try syllabus first, then chapters table)
    foreach ($subjects as $subj) {
        $chStmt = $db->prepare("SELECT topic FROM syllabus WHERE class IN ($placeholders) AND subject=? AND topic IS NOT NULL AND topic!='' ORDER BY topic");
        $chParams = $classParams;
        $chParams[] = $subj;
        $chStmt->execute($chParams);
        $topics = $chStmt->fetchAll(PDO::FETCH_COLUMN);

        // Fallback: also check chapters table
        if (empty($topics)) {
            $chStmt2 = $db->prepare("SELECT chapter_name FROM chapters WHERE class IN ($placeholders) AND subject=? AND chapter_name IS NOT NULL AND chapter_name!='' ORDER BY chapter_name");
            $chStmt2->execute($chParams);
            $topics = $chStmt2->fetchAll(PDO::FETCH_COLUMN);
        }

        $response['chapters'][$subj] = $topics;
    }

    // 3. Fetch students for this class (exact match is fine for students)
    $stuStmt = $db->prepare("SELECT id, name, class, gender FROM students WHERE class=? ORDER BY name");
    $stuStmt->execute([$class]);
    $response['students'] = $stuStmt->fetchAll(PDO::FETCH_ASSOC);

    $response['success'] = true;
}
catch (Exception $e) {
    $response['error'] = 'db_error';
    $response['message'] = 'An unexpected error occurred. Please try again.';
}

echo json_encode($response);
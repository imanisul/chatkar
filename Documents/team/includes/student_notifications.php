<?php
/**
 * HeyyGuru — Student Notification + Coins + Streak Helper
 * Allows any part of the app to send in-app notifications, award coins, and manage streaks.
 */

require_once __DIR__ . '/email.php';

// ─────────────────────────────────────────────
// IN-APP NOTIFICATIONS
// ─────────────────────────────────────────────

function sendStudentNotification(PDO $db, int $studentId, string $title, string $message, string $type = 'info', bool $sendEmail = false): bool
{
    try {
        $db->prepare("INSERT INTO student_notifications (student_id, title, message, type, is_read, created_at) VALUES (?,?,?,?,0,NOW())")
            ->execute([$studentId, $title, $message, $type]);

        if ($sendEmail) {
            try {
                $row = $db->prepare("SELECT name, email FROM students WHERE id=?");
                $row->execute([$studentId]);
                $row = $row->fetch();
                if ($row && !empty($row['email'])) {
                    sendNotificationEmail($row['email'], $row['name'], $title, $message, $type);
                }
            }
            catch (Exception $e) {
            }
        }
        return true;
    }
    catch (Exception $e) {
        return false;
    }
}

function sendBatchNotification(PDO $db, int $batchId, string $title, string $message, string $type = 'info', bool $sendEmail = false): int
{
    try {
        // Use batch_students if it exists, else get via students.batch_id
        try {
            $students = $db->prepare("SELECT s.id, s.name, s.email FROM students s WHERE s.batch_id=?");
            $students->execute([$batchId]);
        }
        catch (Exception $e) {
            $students = $db->prepare("SELECT s.id, s.name, s.email FROM students s JOIN batch_students bs ON bs.student_id=s.id WHERE bs.batch_id=?");
            $students->execute([$batchId]);
        }
        $count = 0;
        $ins = $db->prepare("INSERT INTO student_notifications (student_id, title, message, type, is_read, created_at) VALUES (?,?,?,?,0,NOW())");
        foreach ($students->fetchAll() as $row) {
            $ins->execute([$row['id'], $title, $message, $type]);
            $count++;
            if ($sendEmail && !empty($row['email'])) {
                sendNotificationEmail($row['email'], $row['name'], $title, $message, $type);
            }
        }
        return $count;
    }
    catch (Exception $e) {
        return 0;
    }
}

function sendClassNotification(PDO $db, string $class, string $title, string $message, string $type = 'info', bool $sendEmail = false): int
{
    try {
        $students = $db->prepare("SELECT id, name, email FROM students WHERE class=?");
        $students->execute([$class]);
        $count = 0;
        $ins = $db->prepare("INSERT INTO student_notifications (student_id, title, message, type, is_read, created_at) VALUES (?,?,?,?,0,NOW())");
        foreach ($students->fetchAll() as $row) {
            $ins->execute([$row['id'], $title, $message, $type]);
            $count++;
            if ($sendEmail && !empty($row['email'])) {
                sendNotificationEmail($row['email'], $row['name'], $title, $message, $type);
            }
        }
        return $count;
    }
    catch (Exception $e) {
        return 0;
    }
}

/**
 * Specifically sends a Material Upload notification (in-app + premium HTML email)
 * to all students in a given class.
 */
function sendClassMaterialNotification(
    PDO $db,
    string $class,
    string $materialType,
    string $materialTitle,
    string $subject,
    string $teacherName,
    string $actionUrl,
    bool $sendEmail = true
): int {
    try {
        $students = $db->prepare("SELECT id, name, email FROM students WHERE class=?");
        $students->execute([$class]);
        $count = 0;
        
        $notiTitle = "New $materialType: $materialTitle";
        $notiMsg   = "New $materialType has been uploaded for $subject. Check it out!";
        
        $ins = $db->prepare("INSERT INTO student_notifications (student_id, title, message, type, is_read, created_at) VALUES (?,?,?,?,0,NOW())");
        
        foreach ($students->fetchAll() as $row) {
            // In-app notification
            $ins->execute([$row['id'], $notiTitle, $notiMsg, 'info']);
            $count++;
            
            // Premium email notification
            if ($sendEmail && !empty($row['email'])) {
                sendMaterialUploadEmail(
                    $row['email'], 
                    $row['name'], 
                    $materialType, 
                    $materialTitle, 
                    $subject, 
                    $teacherName, 
                    $actionUrl
                );
            }
        }
        return $count;
    }
    catch (Exception $e) {
        error_log("Material Notification Error: " . $e->getMessage());
        return 0;
    }
}

function sendAllStudentsNotification(PDO $db, string $title, string $message, string $type = 'info', bool $sendEmail = false): int
{
    try {
        $students = $db->query("SELECT id, name, email FROM students")->fetchAll();
        $count = 0;
        $ins = $db->prepare("INSERT INTO student_notifications (student_id, title, message, type, is_read, created_at) VALUES (?,?,?,?,0,NOW())");
        foreach ($students as $row) {
            $ins->execute([$row['id'], $title, $message, $type]);
            $count++;
            if ($sendEmail && !empty($row['email'])) {
                sendNotificationEmail($row['email'], $row['name'], $title, $message, $type);
            }
        }
        return $count;
    }
    catch (Exception $e) {
        return 0;
    }
}

// ─────────────────────────────────────────────
// COINS SYSTEM
// ─────────────────────────────────────────────

/**
 * Award coins to a student.
 * Returns the new total coin balance, or -1 on failure.
 */
function awardCoins(PDO $db, int $studentId, int $amount, string $reason): int
{
    try {
        $db->prepare("INSERT INTO student_coins (student_id, amount, reason, earned_at) VALUES (?,?,?,NOW())")
            ->execute([$studentId, $amount, $reason]);
        return getStudentCoins($db, $studentId);
    }
    catch (Exception $e) {
        return -1;
    }
}

/**
 * Get total coins balance for a student.
 */
function getStudentCoins(PDO $db, int $studentId): int
{
    try {
        $q = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM student_coins WHERE student_id=?");
        $q->execute([$studentId]);
        return (int)$q->fetchColumn();
    }
    catch (Exception $e) {
        return 0;
    }
}

// ─────────────────────────────────────────────
// STREAK SYSTEM
// ─────────────────────────────────────────────

/**
 * Update streak for a student on any daily activity (login, joining class, etc.)
 * Returns [current_streak, longest_streak].
 */
function updateStreak(PDO $db, int $studentId): array
{
    try {
        // Auto-create table if it doesn't exist (self-healing for production)
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
        } catch (Exception $e) {
            // Ignore if restricted privileges, we assume table exists
        }

        // Force IST Timezone for accurate daily streak detection
        $tz = new DateTimeZone('Asia/Kolkata');
        $todayDt = new DateTime('now', $tz);
        $today = $todayDt->format('Y-m-d');
        
        $yesterdayDt = new DateTime('yesterday', $tz);
        $yesterday = $yesterdayDt->format('Y-m-d');

        // Upsert into student_streaks
        $q = $db->prepare("SELECT * FROM student_streaks WHERE student_id=?");
        $q->execute([$studentId]);
        $row = $q->fetch();

        if (!$row) {
            // New record
            $db->prepare("INSERT INTO student_streaks (student_id, current_streak, longest_streak, last_activity_date) VALUES (?,1,1,?)")
                ->execute([$studentId, $today]);
            return [1, 1];
        }

        $last = $row['last_activity_date'];

        if ($last === $today) {
            // Already updated today
            return [(int)$row['current_streak'], (int)$row['longest_streak']];
        }

        if ($last === $yesterday) {
            // Consecutive day — increment
            $newCurrent = (int)$row['current_streak'] + 1;
            $newLongest = max($newCurrent, (int)$row['longest_streak']);
        }
        else {
            // Streak broken — reset
            $newCurrent = 1;
            $newLongest = (int)$row['longest_streak'];
        }

        $db->prepare("UPDATE student_streaks SET current_streak=?, longest_streak=?, last_activity_date=? WHERE student_id=?")
            ->execute([$newCurrent, $newLongest, $today, $studentId]);

        return [$newCurrent, $newLongest];
    }
    catch (Exception $e) {
        error_log("updateStreak ERROR for student $studentId: " . $e->getMessage());
        return [0, 0];
    }
}

/**
 * Get streak info for a student.
 * Returns ['current' => X, 'longest' => Y].
 */
function getStudentStreak(PDO $db, int $studentId): array
{
    try {
        $q = $db->prepare("SELECT current_streak, longest_streak FROM student_streaks WHERE student_id=?");
        $q->execute([$studentId]);
        $row = $q->fetch();
        if (!$row)
            return ['current' => 0, 'longest' => 0];
        return ['current' => (int)$row['current_streak'], 'longest' => (int)$row['longest_streak']];
    }
    catch (Exception $e) {
        return ['current' => 0, 'longest' => 0];
    }
}
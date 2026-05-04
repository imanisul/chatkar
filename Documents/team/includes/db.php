<?php
// ============================================
date_default_timezone_set('Asia/Kolkata');
// HEYYGURU — Database & Core Config
// Domain: team.HeyyGuru
// ============================================

// ── Determine Environment ──
define('DB_HOST', 'localhost');
define('DB_USER', 'u889137813_teamheyyguru');
define('DB_PASS', 'Heyyguru@team@hostinger@6002813464');
define('DB_NAME', 'u889137813_Teamheyyguru');

define('SITE_NAME', 'HeyyGuru');
define('BASE_URL', 'https://team.heyyguru.in'); // Production URL

// ── Secure Session ──────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);

    if ($isHttps) {
        ini_set('session.cookie_secure', 1);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_PERSISTENT => true,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            die('<div style="font-family:sans-serif;padding:40px;text-align:center;color:#c00"><h2>⚠️ DB Error</h2><p>Update credentials in <code>includes/db.php</code></p><pre>' . htmlspecialchars($e->getMessage()) . '</pre></div>');
        }
        
        // --- GLOBAL SCHEMA AUTO-PATCHER ---
        // Silently ensures all newly added columns and tables exist so the app never crashes
        try {
            // 1. Check if warning_count exists, if not, add it
            try {
                $pdo->query("SELECT warning_count FROM users LIMIT 1");
            } catch (PDOException $e) {
                $pdo->exec("ALTER TABLE users ADD COLUMN warning_count INT DEFAULT 0 AFTER status");
            }
            
            // 2. Ensure teacher_irregularities exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_irregularities (
                id INT AUTO_INCREMENT PRIMARY KEY,
                teacher_id INT NOT NULL,
                marked_by INT DEFAULT NULL,
                type VARCHAR(50) DEFAULT 'Late',
                date DATE NOT NULL,
                timetable_id INT DEFAULT NULL,
                description TEXT,
                severity ENUM('Low','Medium','High') DEFAULT 'Low',
                status ENUM('Open','Resolved') DEFAULT 'Open',
                resolved_by INT DEFAULT NULL,
                resolved_at DATETIME DEFAULT NULL,
                resolve_note TEXT,
                is_lop TINYINT(1) DEFAULT 0,
                lop_status ENUM('pending_reason','reason_submitted','confirmed','warning_issued','revoked') DEFAULT NULL,
                lop_auto_generated TINYINT(1) DEFAULT 0,
                login_hours_logged DECIMAL(5,2) DEFAULT NULL,
                teacher_reason TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB");
            
            // 3. Ensure lop_status exists in teacher_irregularities
            try {
                $pdo->query("SELECT lop_status FROM teacher_irregularities LIMIT 1");
            } catch (PDOException $e) {
                $pdo->exec("ALTER TABLE teacher_irregularities 
                    ADD COLUMN lop_status ENUM('pending_reason','reason_submitted','confirmed','warning_issued','revoked') DEFAULT NULL AFTER is_lop,
                    ADD COLUMN lop_auto_generated TINYINT(1) DEFAULT 0 AFTER lop_status,
                    ADD COLUMN login_hours_logged DECIMAL(5,2) DEFAULT NULL AFTER lop_auto_generated");
            }
        } catch (Exception $e) {
            // Ignore patch errors, let the app run
        }
        // ----------------------------------
    }
    return $pdo;
}

function logActivity(int $userId, string $action, string $module = ''): void
{
    try {
        getDB()->prepare("INSERT INTO activity_log (user_id,action,module) VALUES (?,?,?)")->execute([$userId, $action, $module]);
    } catch (Exception $e) {
    }
}

function sanitize(string $input): string
{
    return htmlspecialchars(strip_tags(trim($input ?? '')), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate variations for class names to handle mismatches (e.g. "10th-A" matching "Class 10")
 */
function getClassVariations($classesToSearch): array
{
    if (empty($classesToSearch))
        return [];
    if (is_string($classesToSearch))
        $classesToSearch = [$classesToSearch];

    $variations = [];
    $romanMap = ['i' => '1', 'ii' => '2', 'iii' => '3', 'iv' => '4', 'v' => '5', 'vi' => '6', 'vii' => '7', 'viii' => '8', 'ix' => '9', 'x' => '10', 'xi' => '11', 'xii' => '12'];
    $inverseRomanMap = ['1' => 'I', '2' => 'II', '3' => 'III', '4' => 'IV', '5' => 'V', '6' => 'VI', '7' => 'VII', '8' => 'VIII', '9' => 'IX', '10' => 'X', '11' => 'XI', '12' => 'XII'];

    foreach ($classesToSearch as $cls) {
        $cls = trim($cls ?? '');
        if (empty($cls))
            continue;
        $variations[] = $cls;

        $clean = trim(preg_replace('/class|grade|section|std|standard/i', '', $cls));
        $clean = trim(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $clean));
        $clean = preg_replace('/\s+/', ' ', $clean);

        if (empty($clean))
            continue;
        $variations[] = $clean;
        $variations[] = "Class " . $clean;

        $firstPart = explode(' ', $clean)[0];
        $numericPart = preg_replace('/[^0-9]/', '', $firstPart);
        $toProcess = array_unique([$clean, $firstPart, $numericPart]);

        foreach ($toProcess as $item) {
            $item = trim($item);
            if (empty($item))
                continue;
            $itemLower = strtolower($item);
            $val = $item;
            if (isset($romanMap[$itemLower]))
                $val = $romanMap[$itemLower];

            $variations[] = $val;
            $variations[] = "Class " . $val;
            $variations[] = "Class-" . $val;

            if (isset($inverseRomanMap[$val])) {
                $rom = $inverseRomanMap[$val];
                $variations[] = $rom;
                $variations[] = "Class " . $rom;
                $variations[] = "Class-" . $rom;
            }
            if (is_numeric($val)) {
                $num = (int) $val;
                $suffix = match ($num) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th'};
                $variations[] = $num . $suffix;
                $variations[] = "Class " . $num . $suffix;
                $variations[] = "Class-" . $num . $suffix;
            }
        }
    }
    return array_values(array_unique(array_filter($variations)));
}

function redirect(string $url): void
{
    header("Location: $url");
    exit;
}

if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verifyCsrfToken')) {
    function verifyCsrfToken(?string $token): bool
    {
        if (empty($_SESSION['csrf_token']) || empty($token))
            return false;
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin(): void
{
    if (!isLoggedIn())
        redirect(BASE_URL . '/login.php');
    checkIdleTimeout(); // auto-logout after 10 min of inactivity
}

function requireRole(array $roles): void
{
    requireLogin();
    if (!in_array($_SESSION['role'] ?? '', $roles))
        redirect(BASE_URL . '/login.php?error=unauthorized');
}

function currentUser(): array
{
    return ['id' => $_SESSION['user_id'] ?? 0, 'name' => $_SESSION['name'] ?? '', 'role' => $_SESSION['role'] ?? '', 'email' => $_SESSION['email'] ?? ''];
}

function roleColor(string $role): string
{
    return match ($role) { 'admin' => '#4DA3FF', 'mentor' => '#22c55e', 'teacher' => '#0ea5e9', 'marketing' => '#8b5cf6', default => '#94a3b8'};
}

function roleIcon(string $role): string
{
    $svgo = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px;">';
    return match ($role) {
        'admin' => $svgo . '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>',
        'mentor' => $svgo . '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><polyline points="17 11 19 13 23 9"></polyline></svg>',
        'teacher' => $svgo . '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>',
        'marketing' => $svgo . '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>',
        default => $svgo . '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>'
    };
}

function timeAgo(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60)
        return 'Just now';
    if ($diff < 3600)
        return round($diff / 60) . 'm ago';
    if ($diff < 86400)
        return round($diff / 3600) . 'h ago';
    return round($diff / 86400) . 'd ago';
}
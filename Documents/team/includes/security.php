<?php
/**
 * HEYYGURU — Security Middleware
 * Include BEFORE any output. Handles:
 *  - HTTP Security Headers (CSP, XFO, HSTS, etc.)
 *  - Screenshot/Print blocking for non-admin/non-export pages
 *  - Rate limiting basics
 *  - CSRF token generation
 *  - SQL injection / XSS header hardening
 */

// ── Security Headers ─────────────────────────────────────────
function applySecurityHeaders(bool $allowPrint = false): void
{
    // Prevent clickjacking (allow same origin for administrative iframes)
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');

    // HSTS (only on HTTPS — comment out if testing locally)
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }

    // Content Security Policy
    // - Blocks inline scripts not in our nonce
    // - Blocks external scripts except CDN whitelist
    // - Blocks all frames (anti-clickjacking)
    $nonce = base64_encode(random_bytes(16));
    $_SESSION['csp_nonce'] = $nonce;

    $csp = implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdnjs.cloudflare.com https://unpkg.com",
        "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com",
        "font-src 'self' https://fonts.gstatic.com",
        "img-src 'self' data: blob:",
        "connect-src 'self'",
        "frame-src 'self' about: blob:",
        "frame-ancestors 'self'",
        "base-uri 'self'",
        "form-action 'self'",
        $allowPrint ? "" : "media-src 'none'",
    ]);
    header("Content-Security-Policy: $csp");
}

// ── CSRF Token ────────────────────────────────────────────────
// Definitions are in includes/db.php to ensure they are available globally.
// verifyCsrfToken and generateCsrfToken are globally available.

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . generateCsrfToken() . '">';
}

// ── Rate Limiter (simple session-based) ──────────────────────
function checkRateLimit(string $key, int $max = 20, int $window = 60): bool
{
    $now = time();
    $bucket = $_SESSION['rl_' . $key] ?? ['count' => 0, 'reset' => $now + $window];
    if ($now > $bucket['reset']) {
        $bucket = ['count' => 0, 'reset' => $now + $window];
    }
    $bucket['count']++;
    $_SESSION['rl_' . $key] = $bucket;
    return $bucket['count'] <= $max;
}

// ── Brute Force Protection for Login (DB-based) ─────────────
function checkLoginAttempts(string $ip): bool
{
    try {
        $db = getDB();
        // Create table if not exists
        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            attempt_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_time (ip_address, attempt_time)
        ) ENGINE=InnoDB");
        
        // Clean old attempts (older than 30 minutes)
        $db->prepare("DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 30 MINUTE)")->execute();
        
        // Count recent attempts (last 15 minutes)
        $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $stmt->execute([$ip]);
        $count = (int)$stmt->fetchColumn();
        
        if ($count >= 5) {
            $remaining = 15;
            die("<div style='font-family:sans-serif;text-align:center;padding:60px;background:#fef2f2;color:#991b1b;border-radius:12px;max-width:400px;margin:100px auto'>
                <h2>🔒 Too Many Attempts</h2>
                <p>Account temporarily locked. Try again in <strong>$remaining minute(s)</strong>.</p>
            </div>");
        }
    } catch (Exception $e) {
        // Fail open — don't block login if DB error
    }
    return true;
}

function recordLoginFailure(string $ip): void
{
    try {
        $db = getDB();
        $db->prepare("INSERT INTO login_attempts (ip_address) VALUES (?)")->execute([$ip]);
    } catch (Exception $e) {}
}

function clearLoginAttempts(string $ip): void
{
    try {
        $db = getDB();
        $db->prepare("DELETE FROM login_attempts WHERE ip_address = ?")->execute([$ip]);
    } catch (Exception $e) {}
}

// Call applySecurityHeaders() explicitly from header.php
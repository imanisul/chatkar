<?php
session_start();

if (isset($_SESSION['student_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once '../includes/db.php';
require_once '../includes/security.php';

$error = '';
$db = getDB();
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$csrfToken = generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security check failed. Please refresh and try again.';
    }
    else {
        checkLoginAttempts($ip);
        $login = trim($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($login && $password) {
            $stmt = $db->prepare("
                SELECT s.*, su.password as portal_password, su.status as portal_status
                FROM students s
                JOIN student_users su ON su.student_id=s.id
                WHERE (s.email=? OR s.student_number=? OR s.phone=?) AND su.status='active'
                LIMIT 1
            ");
            $stmt->execute([$login, strtoupper($login), $login]);
            $student = $stmt->fetch();

            if ($student && password_verify($password, $student['portal_password'])) {
                clearLoginAttempts($ip);
                session_regenerate_id(true);
                $_SESSION['student_id'] = $student['id'];
                $_SESSION['student_name'] = $student['name'];
                $_SESSION['student_class'] = $student['class'];
                $_SESSION['student_batch_id'] = $student['batch_id'];
                $_SESSION['student_number'] = $student['student_number'];
                $_SESSION['student_gender'] = $student['gender'] ?? 'Male';
                $db->prepare("UPDATE student_users SET last_login=NOW() WHERE student_id=?")->execute([$student['id']]);
                
                // Update daily streak on login
                require_once '../includes/student_notifications.php';
                updateStreak($db, $student['id']);
                
                header('Location: dashboard.php');
                exit;
            }
            else {
                recordLoginFailure($ip);
                $error = 'Invalid credentials. Please check your ID/Phone and password.';
            }
        }
        else {
            $error = 'Please enter your credentials.';
        }
    }
}

$hour = (int)date('H');
$greeting = "Good Morning";
if ($hour >= 12 && $hour < 17)
    $greeting = "Good Afternoon";
elseif ($hour >= 17)
    $greeting = "Good Evening";
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login – HeyyGuru</title>
    <link rel="icon" href="../assets/img/favicon_hg.png" type="image/png">
    <link rel="stylesheet" href="student.css?v=1.2">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            height: 100vh;
            overflow: hidden;
            background: var(--bg);
            color: var(--text);
            transition: background 0.3s, color 0.3s;
        }

        /* ── Particle Canvas ── */
        #particles {
            position: fixed;
            inset: 0;
            z-index: 0
        }

        /* ── Floating 3D Assets ── */
        .floaters {
            position: fixed;
            inset: 0;
            z-index: 1;
            pointer-events: none
        }

        .fl {
            position: absolute;
            font-size: 40px;
            filter: drop-shadow(0 0 18px rgba(99, 102, 241, .4));
            animation: bob 6s ease-in-out infinite;
        }

        .fl:nth-child(1) {
            top: 12%;
            left: 8%;
            animation-delay: 0s;
            font-size: 48px
        }

        .fl:nth-child(2) {
            top: 55%;
            left: 18%;
            animation-delay: 1.5s;
            font-size: 36px
        }

        .fl:nth-child(3) {
            top: 30%;
            left: 38%;
            animation-delay: 3s;
            font-size: 44px
        }

        .fl:nth-child(4) {
            top: 72%;
            left: 5%;
            animation-delay: 2s;
            font-size: 32px
        }

        .fl:nth-child(5) {
            top: 18%;
            left: 48%;
            animation-delay: 4s;
            font-size: 30px
        }

        .fl:nth-child(6) {
            top: 80%;
            left: 35%;
            animation-delay: 1s;
            font-size: 38px
        }

        @keyframes bob {

            0%,
            100% {
                transform: translateY(0) rotate(0deg)
            }

            33% {
                transform: translateY(-18px) rotate(5deg)
            }

            66% {
                transform: translateY(10px) rotate(-3deg)
            }
        }

        /* ── Layout ── */
        .page {
            display: flex;
            height: 100vh;
            position: relative;
            z-index: 5
        }

        /* ── Left Pane (60%) ── */
        .left {
            flex: 0 0 58%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 60px 64px;
            position: relative;
        }

        .logo-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 48px;
            opacity: 0;
            animation: slideIn .6s .2s ease forwards;
        }

        .logo-bar img {
            width: 34px;
            height: 34px;
            filter: drop-shadow(0 4px 8px var(--blue-glow));
        }

        .logo-bar span {
            font-size: 20px;
            font-weight: 900;
            letter-spacing: -.8px;
            color: var(--blue);
        }

        /* Word-by-word animated headline */
        .headline {
            font-size: 48px;
            font-weight: 900;
            line-height: 1.1;
            letter-spacing: -2px;
            margin-bottom: 16px;
        }

        .headline .word {
            display: inline-block;
            opacity: 0;
            animation: wordIn .5s ease forwards;
        }

        .subtitle {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-mid);
            line-height: 1.7;
            max-width: 440px;
            margin-bottom: 40px;
            opacity: 0;
            animation: slideIn .6s .9s ease forwards;
        }

        /* Glass pills */
        .pills {
            display: flex;
            flex-direction: column;
            gap: 12px;
            opacity: 0;
            animation: slideIn .6s 1.1s ease forwards;
        }

        .pill {
            display: flex;
            align-items: center;
            gap: 14px;
            background: var(--card);
            border: 1px solid var(--border);
            backdrop-filter: blur(8px);
            border-radius: 14px;
            padding: 12px 18px;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-mid);
            box-shadow: var(--sh);
            transition: 0.3s;
        }

        .pill:hover {
            transform: translateX(5px);
            border-color: var(--blue);
            color: var(--blue);
        }


        .pill-ic {
            font-size: 20px;
            flex-shrink: 0
        }

        .copy {
            position: absolute;
            bottom: 28px;
            left: 64px;
            font-size: 11px;
            font-weight: 600;
            color: rgba(255, 255, 255, .2);
        }

        /* ── Right Pane (40%) ── */
        .right {
            flex: 0 0 42%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
        }

        .glass {
            width: 100%;
            max-width: 380px;
            background: var(--card);
            backdrop-filter: blur(24px);
            border: 1px solid var(--border);
            border-radius: 28px;
            padding: 40px 36px 32px;
            box-shadow: var(--sh-premium);
            opacity: 0;
            animation: cardIn .7s .4s cubic-bezier(.175, .885, .32, 1.275) forwards;
        }

        /* Secure badge */
        .secure {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 24px;
            font-size: 11px;
            font-weight: 700;
            color: #34d399;
            text-transform: uppercase;
            letter-spacing: .8px;
        }

        .secure-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #34d399;
            animation: pulse-g 1.5s infinite;
        }

        @keyframes pulse-g {

            0%,
            100% {
                box-shadow: 0 0 0 0 rgba(52, 211, 153, .4)
            }

            50% {
                box-shadow: 0 0 0 6px rgba(52, 211, 153, 0)
            }
        }

        /* Gradient greeting */
        .greet {
            margin-bottom: 28px
        }

        .greet h2 {
            font-size: 24px;
            font-weight: 800;
            background: linear-gradient(135deg, #60a5fa, #a78bfa, #fff);
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 4px;
        }

        .greet h2 .wave {
            display: inline-block;
            -webkit-text-fill-color: initial;
            animation: wave-anim .6s ease-in-out infinite alternate;
        }

        @keyframes wave-anim {
            0% {
                transform: rotate(0deg)
            }

            100% {
                transform: rotate(20deg)
            }
        }

        .greet p {
            font-size: 13px;
            color: rgba(255, 255, 255, .4);
            font-weight: 500
        }

        /* Fields */
        .field {
            margin-bottom: 16px
        }

        .field label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: rgba(255, 255, 255, .35);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: .7px;
        }

        .inp-w {
            position: relative
        }

        .inp-w .ic {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 16px;
            color: rgba(255, 255, 255, .25);
            pointer-events: none;
        }

        .inp-w input {
            width: 100%;
            padding: 13px 42px 13px 42px;
            background: var(--bg);
            border: 1.5px solid var(--border);
            border-radius: 14px;
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
            outline: none;
            font-family: inherit;
            transition: all .25s;
        }

        .inp-w input:focus {
            border-color: var(--blue);
            background: var(--bg);
            box-shadow: 0 0 0 3px var(--blue-glow);
        }

        .inp-w input::placeholder {
            color: var(--text-light);
            font-weight: 500
        }

        .eye-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            color: rgba(255, 255, 255, .25);
            padding: 4px;
            display: flex;
        }

        .eye-btn:hover {
            color: rgba(255, 255, 255, .5)
        }

        /* Button */
        .btn-go {
            width: 100%;
            padding: 14px;
            margin-top: 8px;
            background: linear-gradient(135deg, #4DA2FF, #6366f1, #818cf8);
            color: #fff;
            border: none;
            border-radius: 14px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            position: relative;
            overflow: hidden;
            box-shadow: 0 6px 24px rgba(99, 102, 241, .35);
            transition: all .2s;
        }

        .btn-go:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 32px rgba(99, 102, 241, .5);
        }

        .btn-go:hover .btn-arrow {
            opacity: 1;
            transform: translateX(0)
        }

        .btn-go:active {
            transform: translateY(0)
        }

        .btn-text {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: transform .2s;
        }

        .btn-go:hover .btn-text {
            transform: translateX(-6px)
        }

        .btn-arrow {
            opacity: 0;
            transform: translateX(-10px);
            transition: all .2s;
            font-size: 18px;
        }

        /* Staff link */
        .staff {
            margin-top: 24px;
            text-align: center;
            font-size: 12px;
            font-weight: 600;
            color: rgba(255, 255, 255, .2);
        }

        .staff a {
            color: rgba(255, 255, 255, .4);
            text-decoration: none;
            font-weight: 700;
            transition: color .15s;
        }

        .staff a:hover {
            color: #818cf8
        }

        /* Error */
        .err {
            background: rgba(239, 68, 68, .12);
            color: #fca5a5;
            padding: 10px 14px;
            border-radius: 12px;
            border: 1px solid rgba(239, 68, 68, .2);
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 16px;
            text-align: center;
        }

        /* ── Animations ── */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(16px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        @keyframes wordIn {
            from {
                opacity: 0;
                transform: translateY(20px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        @keyframes cardIn {
            from {
                opacity: 0;
                transform: scale(.92) translateY(24px)
            }

            to {
                opacity: 1;
                transform: scale(1) translateY(0)
            }
        }

        /* ── Responsive ── */
        @media(max-width:900px) {
            body {
                overflow-y: auto;
                min-height: 100vh;
            }

            .page {
                flex-direction: column;
                min-height: 100vh;
                height: auto;
            }

            .left {
                flex: none;
                padding: 50px 32px 30px;
                text-align: center;
                align-items: center;
            }

            .logo-bar {
                justify-content: center;
                margin-bottom: 30px;
            }

            .headline {
                font-size: 32px;
                letter-spacing: -1px;
                margin-bottom: 12px;
            }

            .subtitle {
                margin: 0 auto 30px;
                font-size: 14px;
            }

            .pills {
                display: none
            }

            .copy {
                display: none
            }

            .right {
                flex: none;
                padding: 0 24px 60px;
                width: 100%;
            }

            .glass {
                padding: 36px 30px 30px;
                max-width: 440px;
                margin: 0 auto;
            }

            .floaters {
                display: none;
            }

            /* Performance & Clutter */
        }

        @media(max-width:480px) {
            .left {
                padding: 40px 20px 20px;
            }

            .headline {
                font-size: 26px;
            }

            .greet h2 {
                font-size: 22px;
            }

            .glass {
                padding: 30px 20px;
            }

            #particles {
                opacity: 0.5;
            }

            /* Save battery/GPU */
        }
    </style>
</head>

<body class="dark-mode">

    <!-- Interactive Particle Network -->
    <canvas id="particles"></canvas>

    <!-- Floating 3D Assets -->
    <div class="floaters">
        <div class="fl">💡</div>
        <div class="fl">⚛️</div>
        <div class="fl">📘</div>
        <div class="fl">🔭</div>
        <div class="fl">✨</div>
        <div class="fl">🧬</div>
    </div>

    <div class="page">

        <!-- ═══ LEFT: Brand Story ═══ -->
        <div class="left">
            <div class="logo-bar">
                <img src="../assets/img/favicon.png" alt="HeyyGuru">
                <span>HeyyGuru</span>
            </div>

            <h1 class="headline" id="headline"></h1>
            <p class="subtitle">Through dedication, teamwork, and innovation, we are building a brighter future for
                thousands of learners.</p>

            <div class="pills">
                <div class="pill">
                    <span class="pill-ic">🛡️</span>
                    Build confidence and strong foundations.
                </div>
                <div class="pill">
                    <span class="pill-ic">💡</span>
                    Inspire curiosity to learn and grow.
                </div>
                <div class="pill">
                    <span class="pill-ic">🧭</span>
                    Guide career choices and life decisions.
                </div>
                <div class="pill">
                    <span class="pill-ic">👨‍👩‍👧</span>
                    Connect with families for right guidance.
                </div>
            </div>

            <div class="copy">&copy; 2026 HeyyGuru Pvt. Ltd. All Rights Reserved.</div>
        </div>

        <div class="right">
            <div class="glass">
                <div class="secure">
                    <div class="secure-dot"></div>
                    Secure Connection
                </div>

                <div class="greet">
                    <h2 style="display:flex;align-items:center;gap:10px;">
                        <?= $greeting?> <span class="wave"><svg width="32" height="32" viewBox="0 0 24 24" fill="none"
                                stroke="#8b5cf6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M18 11V6a2 2 0 0 0-2-2v0a2 2 0 0 0-2 2v0"></path>
                                <path d="M14 10V4a2 2 0 0 0-2-2v0a2 2 0 0 0-2 2v0"></path>
                                <path d="M10 10.5V6a2 2 0 0 0-2-2v0a2 2 0 0 0-2 2v0"></path>
                                <path
                                    d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15">
                                </path>
                            </svg></span>
                    </h2>
                    <p>Sign in to your learning portal</p>
                </div>

                <?php if ($error): ?>
                <div class="err">
                    <?= htmlspecialchars($error)?>
                </div>
                <?php
endif; ?>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken)?>">

                    <div class="field">
                        <label>Phone / Student ID / Email</label>
                        <div class="inp-w">
                            <span class="ic"><i data-lucide="smartphone" style="width:18px;height:18px"></i></span>
                            <input type="text" name="login" value="<?= htmlspecialchars($_POST['login'] ?? '')?>"
                                placeholder="9876543210 or Student ID" required autofocus autocomplete="username">
                        </div>
                    </div>

                    <div class="field">
                        <label>Password</label>
                        <div class="inp-w">
                            <span class="ic"><i data-lucide="lock" style="width:18px;height:18px"></i></span>
                            <input type="password" name="password" id="pw" placeholder="Your Password" required
                                autocomplete="current-password">
                            <button type="button" class="eye-btn" onclick="togglePw()">
                                <i data-lucide="eye" style="width:18px;height:18px"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-go">
                        <span class="btn-text">
                            Sign In
                            <span class="btn-arrow"><i data-lucide="arrow-right"
                                    style="width:18px;height:18px"></i></span>
                        </span>
                    </button>
                </form>

                <div class="staff">
                    <a href="../login.php">Staff Login →</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initial icon state (Forced Dark)
        lucide.createIcons();

        /* ── Interactive Particle Network ── */
        (function () {
            const c = document.getElementById('particles');
            const ctx = c.getContext('2d');
            let w, h, mouse = { x: null, y: null };
            const particles = [];
            const isMobile = window.innerWidth < 768;
            const N = isMobile ? 40 : 80;
            const LINK_DIST = isMobile ? 100 : 140;

            function resize() { w = c.width = innerWidth; h = c.height = innerHeight; }
            resize();
            addEventListener('resize', resize);
            addEventListener('mousemove', e => { mouse.x = e.clientX; mouse.y = e.clientY; });
            addEventListener('mouseout', () => { mouse.x = null; mouse.y = null; });

            for (let i = 0; i < N; i++) {
                particles.push({
                    x: Math.random() * w,
                    y: Math.random() * h,
                    vx: (Math.random() - .5) * .4,
                    vy: (Math.random() - .5) * .4,
                    r: Math.random() * 2 + 1
                });
            }

            function draw() {
                ctx.clearRect(0, 0, w, h);

                for (let i = 0; i < N; i++) {
                    const p = particles[i];
                    p.x += p.vx; p.y += p.vy;
                    if (p.x < 0 || p.x > w) p.vx *= -1;
                    if (p.y < 0 || p.y > h) p.vy *= -1;

                    // Mouse repulsion
                    if (mouse.x !== null) {
                        const dx = p.x - mouse.x, dy = p.y - mouse.y;
                        const dist = Math.sqrt(dx * dx + dy * dy);
                        if (dist < 120) {
                            p.x += dx / dist * 1.5;
                            p.y += dy / dist * 1.5;
                        }
                    }

                    ctx.beginPath();
                    ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
                    ctx.fillStyle = 'rgba(99,102,241,.5)';
                    ctx.fill();

                    // Links
                    for (let j = i + 1; j < N; j++) {
                        const q = particles[j];
                        const dx = p.x - q.x, dy = p.y - q.y;
                        const d = Math.sqrt(dx * dx + dy * dy);
                        if (d < LINK_DIST) {
                            ctx.beginPath();
                            ctx.moveTo(p.x, p.y);
                            ctx.lineTo(q.x, q.y);
                            ctx.strokeStyle = `rgba(99,102,241,${.15 * (1 - d / LINK_DIST)})`;
                            ctx.lineWidth = .6;
                            ctx.stroke();
                        }
                    }

                    // Mouse links
                    if (mouse.x !== null) {
                        const dx = p.x - mouse.x, dy = p.y - mouse.y;
                        const d = Math.sqrt(dx * dx + dy * dy);
                        if (d < 160) {
                            ctx.beginPath();
                            ctx.moveTo(p.x, p.y);
                            ctx.lineTo(mouse.x, mouse.y);
                            ctx.strokeStyle = `rgba(129,140,248,${.2 * (1 - d / 160)})`;
                            ctx.lineWidth = .8;
                            ctx.stroke();
                        }
                    }
                }
                requestAnimationFrame(draw);
            }
            draw();
        })();

        /* ── Word-by-word headline ── */
        (function () {
            const words = "Every Student Matters. Every Effort Counts.".split(' ');
            const el = document.getElementById('headline');
            words.forEach((w, i) => {
                const span = document.createElement('span');
                span.className = 'word';
                span.innerHTML = w + '&nbsp;';
                span.style.animationDelay = (0.3 + i * 0.12) + 's';
                el.appendChild(span);
                // Line break after "Matters."
                if (w === 'Matters.') el.appendChild(document.createElement('br'));
            });
        })();

        /* ── Password toggle ── */
        function togglePw() {
            const p = document.getElementById('pw');
            p.type = p.type === 'password' ? 'text' : 'password';
        }
    </script>
</body>

</html>
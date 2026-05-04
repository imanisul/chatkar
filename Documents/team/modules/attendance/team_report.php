<?php
/**
 * HeyyGuru — Team Report (All Roles)
 * Shows leaves, LOPs, warnings, login hours, and marketing sales for all team members.
 */
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
requireRole(['admin']);

$pageTitle = 'Team Report';
$db   = getDB();
$user = currentUser();

// Filters
$filterRole = $_GET['role'] ?? '';
$filterMonth = $_GET['month'] ?? date('Y-m');
$filterUser = (int)($_GET['user_id'] ?? 0);

$monthStart = $filterMonth . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$monthName  = date('F Y', strtotime($monthStart));

// Get all team members safely
$teamParams = [];
try {
    $teamSql = "SELECT id, name, email, role, status, phone, warning_count, created_at FROM users WHERE role IN ('teacher','mentor','marketing') AND status='active'";
    if ($filterRole) { $teamSql .= " AND role=?"; $teamParams[] = $filterRole; }
    if ($filterUser) { $teamSql .= " AND id=?"; $teamParams[] = $filterUser; }
    $teamSql .= " ORDER BY role, name";
    $teamStmt = $db->prepare($teamSql);
    $teamStmt->execute($teamParams);
    $teamMembers = $teamStmt->fetchAll();
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Unknown column') !== false) {
        $teamSql = "SELECT id, name, email, role, status, phone, 0 as warning_count, created_at FROM users WHERE role IN ('teacher','mentor','marketing') AND status='active'";
        $teamParams = [];
        if ($filterRole) { $teamSql .= " AND role=?"; $teamParams[] = $filterRole; }
        if ($filterUser) { $teamSql .= " AND id=?"; $teamParams[] = $filterUser; }
        $teamSql .= " ORDER BY role, name";
        $teamStmt = $db->prepare($teamSql);
        $teamStmt->execute($teamParams);
        $teamMembers = $teamStmt->fetchAll();
    } else {
        die("Database Error on Team Query: " . $e->getMessage());
    }
}

// Aggregate data for each member
$report = [];
foreach ($teamMembers as $m) {
    $mid = $m['id'];
    $data = [
        'user' => $m,
        'leaves_taken' => 0,
        'leaves_pending' => 0,
        'leaves_detail' => [],
        'lop_count' => 0,
        'lop_this_month' => 0,
        'warnings' => 0,
        'login_hours' => 0,
        'login_days' => 0,
        'leads_assigned' => 0,
        'leads_converted' => 0,
        'conversion_rate' => 0,
    ];

    // Leaves
    try {
        $lStmt = $db->prepare("SELECT COUNT(*) FROM leave_requests WHERE user_id=? AND status='Approved' AND from_date >= ? AND to_date <= ?");
        $lStmt->execute([$mid, $monthStart, $monthEnd]);
        $data['leaves_taken'] = (int)$lStmt->fetchColumn();

        $lpStmt = $db->prepare("SELECT COUNT(*) FROM leave_requests WHERE user_id=? AND status='Pending'");
        $lpStmt->execute([$mid]);
        $data['leaves_pending'] = (int)$lpStmt->fetchColumn();

        $ldStmt = $db->prepare("SELECT from_date, to_date, reason, status FROM leave_requests WHERE user_id=? AND from_date >= ? AND to_date <= ? ORDER BY from_date DESC LIMIT 5");
        $ldStmt->execute([$mid, $monthStart, $monthEnd]);
        $data['leaves_detail'] = $ldStmt->fetchAll();
    } catch (Exception $e) {}

    // LOPs
    try {
        $lopStmt = $db->prepare("SELECT COUNT(*) FROM teacher_irregularities WHERE teacher_id=? AND is_lop=1");
        $lopStmt->execute([$mid]);
        $data['lop_count'] = (int)$lopStmt->fetchColumn();

        $lopMStmt = $db->prepare("SELECT COUNT(*) FROM teacher_irregularities WHERE teacher_id=? AND is_lop=1 AND date >= ? AND date <= ?");
        $lopMStmt->execute([$mid, $monthStart, $monthEnd]);
        $data['lop_this_month'] = (int)$lopMStmt->fetchColumn();
    } catch (Exception $e) {}

    // Warnings
    try { $data['warnings'] = (int)($m['warning_count'] ?? 0); } catch (Exception $e) {}

    // Login hours from user_sessions
    try {
        $sessStmt = $db->prepare("
            SELECT 
                COUNT(DISTINCT DATE(login_time)) as login_days,
                COALESCE(SUM(TIMESTAMPDIFF(SECOND, login_time, COALESCE(logout_time, last_active_time, login_time))), 0) as total_seconds
            FROM user_sessions 
            WHERE user_id=? AND DATE(login_time) >= ? AND DATE(login_time) <= ?
        ");
        $sessStmt->execute([$mid, $monthStart, $monthEnd]);
        $sess = $sessStmt->fetch();
        $data['login_hours'] = round(($sess['total_seconds'] ?? 0) / 3600, 1);
        $data['login_days'] = (int)($sess['login_days'] ?? 0);
    } catch (Exception $e) {}

    // Marketing-specific: Leads & Conversions
    if ($m['role'] === 'marketing') {
        try {
            $laStmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE assigned_to=?");
            $laStmt->execute([$mid]);
            $data['leads_assigned'] = (int)$laStmt->fetchColumn();

            $lcStmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE assigned_to=? AND status='Converted'");
            $lcStmt->execute([$mid]);
            $data['leads_converted'] = (int)$lcStmt->fetchColumn();

            $data['conversion_rate'] = $data['leads_assigned'] > 0
                ? round(($data['leads_converted'] / $data['leads_assigned']) * 100, 1)
                : 0;
        } catch (Exception $e) {}
    }

    $report[] = $data;
}

// Summary stats
$totalTeam = count($teamMembers);
$totalLeaves = array_sum(array_column($report, 'leaves_taken'));
$totalLops = array_sum(array_column($report, 'lop_this_month'));
$totalWarnings = array_sum(array_column($report, 'warnings'));

// All users for filter dropdown
$allUsers = $db->query("SELECT id, name, role FROM users WHERE role IN ('teacher','mentor','marketing') AND status='active' ORDER BY name")->fetchAll();

$root = '../../';
require_once '../../includes/header.php';
?>

<div class="page-header mb-24">
    <div class="page-header-left">
        <h1 class="align-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg> Team Report</h1>
        <p>Leaves, LOPs, warnings & performance for all team members — <?= $monthName ?></p>
    </div>
    <div class="page-header-actions">
        <a href="../irregularities/index.php" class="btn btn-secondary align-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> Irregularities</a>
        <a href="../leave/index.php" class="btn btn-secondary align-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg> Leave Requests</a>
    </div>
</div>

<!-- Summary Cards -->
<style>
.tr-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 28px; }
.tr-card { background: #fff; border-radius: 20px; padding: 24px; border: 1px solid #e8ecf1; position: relative; overflow: hidden; }
.tr-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; }
.tr-c1::before { background: linear-gradient(90deg, #4DA2FF, #0ea5e9); }
.tr-c2::before { background: linear-gradient(90deg, #10b981, #059669); }
.tr-c3::before { background: linear-gradient(90deg, #ef4444, #dc2626); }
.tr-c4::before { background: linear-gradient(90deg, #f59e0b, #d97706); }
.tr-val { font-size: 36px; font-weight: 900; line-height: 1; letter-spacing: -1px; }
.tr-lbl { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; }

.tr-member-card { background: #fff; border: 1px solid #e8ecf1; border-radius: 20px; padding: 24px; margin-bottom: 20px; transition: all 0.2s; }
.tr-member-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,0.06); transform: translateY(-2px); }
.tr-member-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #f1f5f9; }
.tr-member-info { display: flex; align-items: center; gap: 14px; }
.tr-avatar { width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 18px; color: #fff; flex-shrink: 0; }
.tr-metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 16px; }
.tr-metric { background: #f8fafc; border-radius: 12px; padding: 16px; text-align: center; border: 1px solid #f1f5f9; }
.tr-metric-val { font-size: 24px; font-weight: 900; line-height: 1; }
.tr-metric-lbl { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 6px; }

.role-badge { padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
.role-teacher { background: #e0f2fe; color: #0369a1; }
.role-mentor { background: #dcfce7; color: #15803d; }
.role-marketing { background: #f3e8ff; color: #7e22ce; }

@media (max-width: 900px) { .tr-stats { grid-template-columns: 1fr 1fr; } }
@media (max-width: 600px) { .tr-stats { grid-template-columns: 1fr; } .tr-metrics { grid-template-columns: 1fr 1fr; } }
</style>

<div class="tr-stats">
    <div class="tr-card tr-c1">
        <div class="tr-lbl">Team Members</div>
        <div class="tr-val" style="color:#0ea5e9;"><?= $totalTeam ?></div>
    </div>
    <div class="tr-card tr-c2">
        <div class="tr-lbl">Leaves This Month</div>
        <div class="tr-val" style="color:#10b981;"><?= $totalLeaves ?></div>
    </div>
    <div class="tr-card tr-c3">
        <div class="tr-lbl">LOPs This Month</div>
        <div class="tr-val" style="color:#ef4444;"><?= $totalLops ?></div>
    </div>
    <div class="tr-card tr-c4">
        <div class="tr-lbl">Total Warnings</div>
        <div class="tr-val" style="color:#f59e0b;"><?= $totalWarnings ?></div>
    </div>
</div>

<!-- Filters -->
<div class="section-card" style="padding:16px;margin-bottom:24px;">
    <form method="GET" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <select name="role" class="input" style="max-width:180px;">
            <option value="">All Roles</option>
            <option value="teacher" <?= $filterRole==='teacher'?'selected':'' ?>>Teachers</option>
            <option value="mentor" <?= $filterRole==='mentor'?'selected':'' ?>>Mentors</option>
            <option value="marketing" <?= $filterRole==='marketing'?'selected':'' ?>>Marketing</option>
        </select>
        <select name="user_id" class="input" style="max-width:220px;">
            <option value="0">All Members</option>
            <?php foreach ($allUsers as $au): ?>
            <option value="<?= $au['id'] ?>" <?= $filterUser==$au['id']?'selected':'' ?>><?= sanitize($au['name']) ?> (<?= ucfirst($au['role']) ?>)</option>
            <?php endforeach; ?>
        </select>
        <input type="month" name="month" class="input" style="max-width:180px;" value="<?= $filterMonth ?>">
        <button type="submit" class="btn btn-primary btn-sm">Apply</button>
        <a href="team_report.php" class="btn btn-secondary btn-sm">Reset</a>
    </form>
</div>

<!-- Member Cards -->
<?php if (empty($report)): ?>
<div class="section-card">
    <div class="empty-state">
        <h3>No team members found</h3>
        <p>Adjust your filters to see team report data.</p>
    </div>
</div>
<?php else: ?>
<?php foreach ($report as $r):
    $m = $r['user'];
    $roleClass = 'role-' . $m['role'];
    $avatarColors = ['teacher' => '#0ea5e9', 'mentor' => '#10b981', 'marketing' => '#8b5cf6'];
    $avatarBg = $avatarColors[$m['role']] ?? '#64748b';
?>
<div class="tr-member-card">
    <div class="tr-member-header">
        <div class="tr-member-info">
            <div class="tr-avatar" style="background:<?= $avatarBg ?>;">
                <?= strtoupper(substr($m['name'], 0, 2)) ?>
            </div>
            <div>
                <div style="font-weight:800;font-size:16px;color:#0f172a;margin-bottom:4px;"><?= sanitize($m['name']) ?></div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span class="role-badge <?= $roleClass ?>"><?= ucfirst($m['role']) ?></span>
                    <span style="font-size:12px;color:#64748b;font-weight:600;"><?= sanitize($m['email']) ?></span>
                </div>
            </div>
        </div>
        <div style="text-align:right;">
            <?php if ($r['warnings'] > 0): ?>
            <div style="display:inline-flex;align-items:center;gap:4px;background:#fef3c7;color:#92400e;padding:4px 10px;border-radius:8px;font-size:12px;font-weight:800;">
                ⚠️ <?= $r['warnings'] ?> Warning<?= $r['warnings'] > 1 ? 's' : '' ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="tr-metrics">
        <div class="tr-metric">
            <div class="tr-metric-val" style="color:#10b981;"><?= $r['leaves_taken'] ?></div>
            <div class="tr-metric-lbl">Leaves Taken</div>
        </div>
        <div class="tr-metric">
            <div class="tr-metric-val" style="color:#f59e0b;"><?= $r['leaves_pending'] ?></div>
            <div class="tr-metric-lbl">Leaves Pending</div>
        </div>
        <div class="tr-metric" style="<?= $r['lop_this_month'] > 0 ? 'background:#fef2f2;border-color:#fecaca;' : '' ?>">
            <div class="tr-metric-val" style="color:#ef4444;"><?= $r['lop_this_month'] ?></div>
            <div class="tr-metric-lbl">LOPs (Month)</div>
        </div>
        <div class="tr-metric">
            <div class="tr-metric-val" style="color:#ef4444;"><?= $r['lop_count'] ?></div>
            <div class="tr-metric-lbl">LOPs (Total)</div>
        </div>
        <div class="tr-metric">
            <div class="tr-metric-val" style="color:#0ea5e9;"><?= $r['login_hours'] ?>h</div>
            <div class="tr-metric-lbl">Login Hours</div>
        </div>
        <div class="tr-metric">
            <div class="tr-metric-val" style="color:#4f46e5;"><?= $r['login_days'] ?></div>
            <div class="tr-metric-lbl">Active Days</div>
        </div>
        
        <?php if ($m['role'] === 'marketing'): ?>
        <div class="tr-metric" style="background:#f0fdf4;border-color:#bbf7d0;">
            <div class="tr-metric-val" style="color:#16a34a;"><?= $r['leads_converted'] ?></div>
            <div class="tr-metric-lbl">Sales (Converted)</div>
        </div>
        <div class="tr-metric" style="background:#eef2ff;border-color:#c7d2fe;">
            <div class="tr-metric-val" style="color:#4f46e5;"><?= $r['conversion_rate'] ?>%</div>
            <div class="tr-metric-lbl">Conversion Rate</div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($r['leaves_detail'])): ?>
    <div style="margin-top:16px;padding-top:16px;border-top:1px solid #f1f5f9;">
        <div style="font-size:12px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;">Recent Leaves</div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
            <?php foreach($r['leaves_detail'] as $ld):
                $statusColor = match($ld['status']){'Approved'=>'#10b981','Pending'=>'#f59e0b','Rejected'=>'#ef4444',default=>'#64748b'};
                $days = (strtotime($ld['to_date'])-strtotime($ld['from_date']))/86400+1;
            ?>
            <div style="background:#f8fafc;border:1px solid #e8ecf1;border-radius:10px;padding:8px 12px;font-size:12px;">
                <span style="font-weight:700;color:<?= $statusColor ?>;"><?= $ld['status'] ?></span> ·
                <?= date('d M', strtotime($ld['from_date'])) ?> → <?= date('d M', strtotime($ld['to_date'])) ?>
                (<?= $days ?> day<?= $days > 1 ? 's' : '' ?>)
                <?php if($ld['reason']): ?> · <span style="color:#64748b;"><?= sanitize(substr($ld['reason'], 0, 40)) ?></span><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>

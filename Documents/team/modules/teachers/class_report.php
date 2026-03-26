<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
requireRole(['admin','mentor']);

$pageTitle = 'Teacher Class Report';
$db = getDB();
$user = currentUser();

$selTeacher = (int)($_GET['teacher_id'] ?? 0);
$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate = $_GET['to'] ?? date('Y-m-d');

$teachers = $db->query("SELECT u.id,u.name,t.subject FROM users u LEFT JOIN teachers t ON t.user_id=u.id WHERE u.role='teacher' AND u.status='active' ORDER BY u.name")->fetchAll();

$report = [];
$exportAll = isset($_GET['export_all']) && $_GET['export_all'] == 1;

if ($selTeacher) {
    if ($exportAll) {
        $stmt = $db->prepare("SELECT tcl.*,u.name as teacher_name, tt.start_time, tt.end_time FROM teacher_class_log tcl JOIN users u ON tcl.teacher_id=u.id LEFT JOIN timetable tt ON tcl.timetable_id=tt.id WHERE tcl.teacher_id=? ORDER BY tcl.date DESC,tt.start_time");
        $stmt->execute([$selTeacher]);
    } else {
        $stmt = $db->prepare("SELECT tcl.*,u.name as teacher_name, tt.start_time, tt.end_time FROM teacher_class_log tcl JOIN users u ON tcl.teacher_id=u.id LEFT JOIN timetable tt ON tcl.timetable_id=tt.id WHERE tcl.teacher_id=? AND tcl.date BETWEEN ? AND ? ORDER BY tcl.date DESC,tt.start_time");
        $stmt->execute([$selTeacher,$fromDate,$toDate]);
    }
    $report = $stmt->fetchAll();
}

// Summary stats per teacher
$summaryStmt = $db->query("SELECT u.id,u.name,
    COUNT(DISTINCT tt.id) as weekly_slots,
    (SELECT COUNT(*) FROM teacher_class_log tcl WHERE tcl.teacher_id=u.id AND tcl.status='taken') as total_taken,
    (SELECT COUNT(*) FROM teacher_class_log tcl WHERE tcl.teacher_id=u.id AND tcl.status='not_taken') as total_missed,
    (SELECT COUNT(DISTINCT date) FROM teacher_class_log tcl WHERE tcl.teacher_id=u.id) as total_days,
    (SELECT MAX(date) FROM teacher_class_log tcl WHERE tcl.teacher_id=u.id AND tcl.status='taken') as last_class_date,
    (SELECT COUNT(*) FROM teacher_irregularities ir WHERE ir.teacher_id=u.id AND ir.is_lop=1) as total_lops
    FROM users u LEFT JOIN timetable tt ON tt.teacher_id=u.id
    WHERE u.role='teacher' AND u.status='active' GROUP BY u.id,u.name ORDER BY u.name");
$summary = $summaryStmt->fetchAll();

$root = '../../'; require_once '../../includes/header.php'; ?>

<!-- Wrap for PDF Capture -->
<div id="report-content" style="padding: 10px;">

<div class="page-header">
    <div class="page-header-left">
        <h1><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:8px"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg> Teacher Class Report</h1>
        <p>Detailed class attendance records for all teachers</p>
    </div>
    <div class="page-header-actions" data-html2canvas-ignore="true">
        <button onclick="exportToPDF()" class="btn btn-secondary"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><polyline points="9 15 12 18 15 15"></polyline></svg> Export PDF</button>
        <button class="btn btn-secondary" onclick="window.print()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg> Print</button>
    </div>
</div>

<!-- Summary Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-bottom:24px">
<?php foreach ($summary as $t):
    $totalSlots = $t['total_taken'] + $t['total_missed'];
    $pct = $totalSlots > 0 ? round($t['total_taken']/$totalSlots*100) : 0;
?>
<a href="?teacher_id=<?= $t['id'] ?>&from=<?= $fromDate ?>&to=<?= $toDate ?>" style="text-decoration:none">
<div class="card" style="<?= $selTeacher==$t['id']?'border:2px solid var(--blue)':'' ?>;transition:all .2s" onmouseover="this.style.borderColor='var(--blue)'" onmouseout="this.style.borderColor='<?= $selTeacher==$t['id']?'var(--blue)':'var(--border)' ?>'">
    <div class="card-body" style="padding:16px">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
            <div style="width:44px;height:44px;border-radius:50%;background:var(--amber-light);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;color:var(--amber);border:2px solid var(--amber-mid)"><?= strtoupper(substr($t['name'],0,1)) ?></div>
            <div><strong style="font-size:14px"><?= sanitize($t['name']) ?></strong><div style="font-size:11.5px;color:var(--text-light)"><?= $t['weekly_slots'] ?> slots/week</div></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:8px;margin-bottom:10px">
            <div style="text-align:center;background:var(--green-light);border-radius:var(--r-sm);padding:8px"><div style="font-weight:800;font-size:18px;color:var(--green)"><?= $t['total_taken'] ?></div><div style="font-size:10px;color:var(--green);font-weight:700">TAKEN</div></div>
            <div style="text-align:center;background:var(--red-light);border-radius:var(--r-sm);padding:8px"><div style="font-weight:800;font-size:18px;color:var(--red)"><?= $t['total_missed'] ?></div><div style="font-size:10px;color:var(--red);font-weight:700">MISSED</div></div>
            <div style="text-align:center;background:var(--blue-light);border-radius:var(--r-sm);padding:8px"><div style="font-weight:800;font-size:18px;color:var(--blue-deep)"><?= $pct ?>%</div><div style="font-size:10px;color:var(--blue-deep);font-weight:700">RATE</div></div>
            <div style="text-align:center;background:#fee2e2;border-radius:var(--r-sm);padding:8px;border:1px solid #fca5a5;"><div style="font-weight:800;font-size:18px;color:#b91c1c;"><?= $t['total_lops'] ?></div><div style="font-size:10px;color:#b91c1c;font-weight:800">LOPs</div></div>
        </div>
        <div class="progress"><div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $pct>=75?'var(--green)':($pct>=50?'var(--amber)':'var(--red)') ?>"></div></div>
        <?php if ($t['last_class_date']): ?><div style="font-size:11px;color:var(--text-light);margin-top:6px">Last class: <?= date('d M Y',strtotime($t['last_class_date'])) ?></div><?php endif; ?>
    </div>
</div>
</a>
<?php endforeach; ?>
</div>

<!-- Detailed Log -->
<div class="card" id="detailed-log-card">
    <div class="card-header" style="justify-content: space-between; align-items: flex-end;">
        <div>
            <div class="card-title"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg> Detailed Class Log</div>
            <?php if ($selTeacher): 
                $activeTeacher = array_filter($teachers, fn($t) => $t['id'] == $selTeacher);
                $activeTeacher = reset($activeTeacher);
            ?>
            <div style="font-size:14px; color:var(--text-mid); margin-top:4px;">
                <strong><?= sanitize($activeTeacher['name']) ?></strong> • 
                <span style="color:var(--blue-deep)"><?= sanitize($activeTeacher['subject'] ?: 'General Subject') ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($selTeacher && $report && in_array($user['role'], ['admin', 'mentor'])): ?>
        <button onclick="exportLogToPDF()" class="btn btn-secondary btn-sm" data-html2canvas-ignore="true">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><polyline points="9 15 12 18 15 15"></polyline></svg> Export Log
        </button>
        <?php endif; ?>
    </div>
    <div class="filter-bar" style="flex-wrap:wrap;gap:10px" data-html2canvas-ignore="true">
        <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;flex:1">
            <select name="teacher_id" onchange="this.form.submit()" style="padding:9px 14px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-size:13px;background:#fff;outline:none;min-width:180px">
                <option value="">- Select Teacher -</option>
                <?php foreach ($teachers as $t): ?><option value="<?= $t['id'] ?>" <?= $selTeacher==$t['id']?'selected':'' ?>><?= sanitize($t['name']) ?></option><?php endforeach; ?>
            </select>
            <input type="date" name="from" value="<?= $fromDate ?>" style="padding:9px 12px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-size:13px;background:#fff;outline:none" onchange="this.form.submit()">
            <span style="color:var(--text-light)">to</span>
            <input type="date" name="to" value="<?= $toDate ?>" max="<?= date('Y-m-d') ?>" style="padding:9px 12px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-size:13px;background:#fff;outline:none" onchange="this.form.submit()">
        </form>
    </div>
    <?php if ($selTeacher && $report): ?>
    <div class="table-wrap">
        <table class="data-table">
        <thead><tr><th>Date</th><th>Subject</th><th>Class</th><th>Time</th><th>Status</th><th>Topic Taught</th><th>Notes</th></tr></thead>
        <tbody>
        <?php foreach ($report as $r): ?>
        <tr style="<?= $r['status']==='taken'?'background:#f0fdf4':'' ?>">
            <td><span class="font-mono"><?= date('d M Y',strtotime($r['date'])) ?></span><div style="font-size:10.5px;color:var(--text-light)"><?= date('l',strtotime($r['date'])) ?></div></td>
            <td><strong><?= sanitize($r['subject']) ?></strong></td>
            <td><span class="badge badge-blue"><?= sanitize($r['class']) ?></span></td>
            <td class="font-mono" style="font-size:12px"><?= !empty($r['start_time']) ? date('h:i A',strtotime($r['start_time'])) . ' - ' . date('h:i A',strtotime($r['end_time'])) : '<span style="color:var(--text-light)">-</span>' ?></td>
            <td><?= $r['status']==='taken'?'<span class="badge badge-green"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><polyline points="20 6 9 17 4 12"></polyline></svg> Taken</span>':'<span class="badge badge-red"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg> Not Taken</span>' ?></td>
            <td style="font-size:12.5px"><?= $r['topic_taught']?sanitize($r['topic_taught']):'<span style="color:var(--text-light)">-</span>' ?></td>
            <td style="font-size:12px;color:var(--text-mid)"><?= $r['notes']?sanitize($r['notes']):'-' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php elseif ($selTeacher): ?>
    <div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg></div><h3>No records found</h3><p>No class logs for the selected period</p></div>
    <?php else: ?>
    <div class="empty-state"><div class="empty-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></div><h3>Select a teacher to view detailed log</h3></div>
    <?php endif; ?>
</div>

</div> <!-- End #report-content -->
<?php require_once '../../includes/footer.php'; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function exportToPDF() {
    const element = document.getElementById('report-content');
    const opt = {
        margin:       [0.5, 0.5, 0.5, 0.5],
        filename:     'Teacher_Class_Report_<?= $fromDate ?>_to_<?= $toDate ?>.pdf',
        image:        { type: 'jpeg', quality: 1.0 },
        html2canvas:  { scale: 4, useCORS: true, letterRendering: true, dpi: 300 },
        jsPDF:        { unit: 'in', format: 'letter', orientation: 'landscape' }
    };
    
    const originalBg = element.style.background;
    element.style.background = '#f8f9fb';
    
    html2pdf().set(opt).from(element).save().then(() => {
        element.style.background = originalBg;
    });
}

function exportLogToPDF() {
    const urlParams = new URLSearchParams(window.location.search);
    if (!urlParams.has('export_all')) {
        urlParams.set('export_all', '1');
        window.location.search = urlParams.toString();
        return; // Page will reload
    }
    
    // We are on the export_all page, so trigger the download
    const element = document.getElementById('detailed-log-card');
    const opt = {
        margin:       [0.5, 0.5, 0.5, 0.5],
        filename:     'Class_Log_<?= addslashes(preg_replace("/[^a-zA-Z0-9_\- ]/", "", $teachers[array_search($selTeacher, array_column($teachers, "id"))]["name"] ?? "Teacher")) ?>_<?= addslashes(preg_replace("/[^a-zA-Z0-9_\- ]/", "", $teachers[array_search($selTeacher, array_column($teachers, "id"))]["subject"] ?? "Undefined_Subject")) ?>.pdf',
        image:        { type: 'jpeg', quality: 1.0 },
        html2canvas:  { scale: 4, useCORS: true, letterRendering: true, dpi: 300 },
        jsPDF:        { unit: 'in', format: 'letter', orientation: 'landscape' }
    };
    
    const originalBg = element.style.background;
    element.style.background = '#ffffff';
    
    // Hide the 'Export Log' button itself in the PDF
    const exportBtn = element.querySelector('button[onclick="exportLogToPDF()"]');
    if (exportBtn) exportBtn.style.display = 'none';
    
    html2pdf().set(opt).from(element).save().then(() => {
        element.style.background = originalBg;
        if (exportBtn) exportBtn.style.display = '';
        
        // Remove the export_all param after downloading
        urlParams.delete('export_all');
        const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
        window.history.replaceState({}, document.title, newUrl);
    });
}

// Auto-trigger if the page was loaded specifically for export
window.addEventListener('load', () => {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('export_all') === '1') {
        setTimeout(exportLogToPDF, 500); // Give fonts a moment to process
    }
});
</script>

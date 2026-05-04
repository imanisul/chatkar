<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
requireRole(['admin','mentor']);

$pageTitle = 'Edit Student';
$db   = getDB();
$user = currentUser();
$id   = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM students WHERE id=?");
$stmt->execute([$id]);
$student = $stmt->fetch();
if (!$student) redirect('index.php');

$mentors  = $db->query("SELECT u.id,u.name FROM users u WHERE u.role='mentor' AND u.status='active' ORDER BY u.name")->fetchAll();
$classes  = [];
try { $classes = $db->query("SELECT DISTINCT class FROM students WHERE class IS NOT NULL AND class!='' ORDER BY class")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e) {}
if (empty($classes)) { for ($i = 1; $i <= 10; $i++) $classes[] = "Class $i"; }
for ($i = 1; $i <= 10; $i++) { if (!in_array("Class $i", $classes)) $classes[] = "Class $i"; }
sort($classes, SORT_NATURAL);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        redirect("index.php?err=csrf");
    }

    $name      = sanitize($_POST['name']          ?? '');
    $email     = sanitize($_POST['email']         ?? '');
    $phone     = sanitize($_POST['phone']         ?? '');
    $altPhone  = sanitize($_POST['alt_phone']     ?? '');
    $parent    = sanitize($_POST['parent_name']   ?? '');
    $class     = sanitize($_POST['class']         ?? '');
    $batch_id  = (int)($_POST['batch_id']         ?? 0);
    $mentor_id = (int)($_POST['mentor_id']        ?? 0);
    $address   = sanitize($_POST['address']       ?? '');
    $dob       = $_POST['dob']                    ?? '';
    $gender    = sanitize($_POST['gender']        ?? '');
    $admDate   = $_POST['admission_date']         ?? '';
    $feeStatus = $_POST['fee_status']             ?? 'Pending';
    $feeAmount = (float)($_POST['fee_amount']     ?? 0);
    $notes     = sanitize($_POST['notes']         ?? '');
    $password  = trim($_POST['portal_password']   ?? '');

    if (!$name) $errors[] = 'Student name is required.';

    if (!$errors) {
        // Fetch batch name for redundancy if storage uses it
        $batch_name = '';
        if ($batch_id) {
            $b = $db->prepare("SELECT name FROM batches WHERE id=?");
            $b->execute([$batch_id]);
            $batch_name = $b->fetchColumn() ?: '';
        }

        $db->prepare("UPDATE students SET name=?, email=?, phone=?, alt_phone=?, parent_name=?, class=?, batch=?, batch_id=?, mentor_id=?, address=?, dob=?, gender=?, admission_date=?, fee_status=?, fee_amount=?, notes=? WHERE id=?")
           ->execute([$name, $email, $phone, $altPhone, $parent, $class, $batch_name, $batch_id ?: null, $mentor_id ?: null, $address, $dob ?: null, $gender, $admDate ?: null, $feeStatus, $feeAmount, $notes, $id]);
        
        // Update batch assignment link
        if ($batch_id) {
            try { $db->prepare("INSERT IGNORE INTO batch_students (batch_id,student_id) VALUES (?,?)")->execute([$batch_id,$id]); } catch(Exception $e) {}
        }
           
        // Update password if provided
        if ($password && strlen($password) >= 4) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            try {
                $db->prepare("INSERT INTO student_users (student_id,password,status) VALUES (?,?,'active')
                    ON DUPLICATE KEY UPDATE password=VALUES(password)")->execute([$id,$hash]);
            } catch(Exception $e) {}
        }
        
        logActivity($user['id'], "Updated student: $name", 'students');
        redirect('index.php?msg=updated');
    }
}

$v = fn($f) => htmlspecialchars($_POST[$f] ?? $student[$f] ?? '');

$root = '../../';
require_once '../../includes/header.php';
?>

<?php foreach ($errors as $e): ?>
<div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> <?= $e ?></div>
<?php endforeach; ?>

<style>
/* Stepper UI */
.stepper-nav { display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px; position: relative; }
.stepper-nav::before {
    content: ''; position: absolute; left: 0; right: 0; top: 16px; height: 2px;
    background: #e2e8f0; z-index: 1;
}
.step-item {
    position: relative; z-index: 2; display: flex; flex-direction: column; align-items: center; gap: 8px; flex: 1;
}
.step-circle {
    width: 32px; height: 32px; border-radius: 50%; background: #fff; border: 2px solid #cbd5e1;
    display: flex; align-items: center; justify-content: center; font-weight: 700; color: #94a3b8;
    transition: all 0.3s;
}
.step-label { font-size: 13px; font-weight: 600; color: #64748b; transition: all 0.3s; }

.step-item.active .step-circle { border-color: var(--primary); background: var(--primary); color: #fff; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15); }
.step-item.active .step-label { color: var(--primary); }
.step-item.completed .step-circle { border-color: var(--primary); background: #fff; color: var(--primary); color: white; border-color: var(--primary); background: var(--primary); }

/* Form Wizard Content */
.wizard-step { display: none; animation: fadeIn 0.3s ease; }
.wizard-step.active { display: block; }

@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

/* Enhanced Inputs */
.input-with-icon { position: relative; }
.input-with-icon svg { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-light); }
.input-with-icon input, .input-with-icon select { padding-left: 36px; }

.phone-wrapper { display: flex; align-items: stretch; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
.phone-prefix { background: #f8fafc; padding: 0 12px; display: flex; align-items: center; color: var(--text-mid); font-weight: 600; font-size: 14px; border-right: 1px solid var(--border); }
.phone-wrapper input { border: none; border-radius: 0; flex: 1; outline: none; box-shadow: none; }
.phone-wrapper input:focus { box-shadow: none; outline: none; }
.phone-wrapper:focus-within { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }

.form-tooltip { color: #94a3b8; cursor: help; margin-left: 4px; display: inline-flex; }
.form-tooltip:hover { color: var(--primary); }

.textarea-wrap { position: relative; }
.char-count { position: absolute; bottom: 8px; right: 12px; font-size: 11px; color: #94a3b8; font-weight: 600; pointer-events: none; }
</style>

<div class="breadcrumb">
    <a href="index.php"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:text-bottom;margin-right:2px"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg> Students</a>
    <span class="sep">/</span>
    <span>Edit Student</span>
</div>

<div class="card">
    <div class="card-header" style="flex-wrap: wrap; gap: 16px;">
        <div class="card-title" style="display:flex; align-items:center; gap:12px;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
            Edit Student: <?= sanitize($student['name']) ?>
            <span class="badge" style="background:#f8fafc; color:var(--text-mid); font-family:monospace; font-size:14px; letter-spacing:1px; padding:6px 12px; border:1px solid var(--border);">ID: <?= htmlspecialchars($student['student_number']) ?></span>
        </div>
        <a href="index.php" class="btn btn-secondary btn-sm"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> Back</a>
    </div>
    <div class="card-body">
        
        <!-- Stepper UI -->
        <div class="stepper-nav">
            <div class="step-item active" id="st-1">
                <div class="step-circle">1</div>
                <div class="step-label">Identity & Info</div>
            </div>
            <div class="step-item" id="st-2">
                <div class="step-circle">2</div>
                <div class="step-label">Family & Address</div>
            </div>
            <div class="step-item" id="st-3">
                <div class="step-circle">3</div>
                <div class="step-label">Setup & Fees</div>
            </div>
        </div>

        <form method="POST" id="mainForm">
            <?= csrfField() ?>
            <p style="font-size: 13px; color: #64748b; margin-bottom: 24px;"><span style="color: #ef4444; font-weight:800;">*</span> indicates a required field.</p>

<?php
$batches  = $db->query("SELECT id,name,class FROM batches WHERE status='active' ORDER BY name")->fetchAll();
?>
            <!-- STEP 1: IDENTITY & INFO -->
            <div class="wizard-step active" id="step-1">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Student Name <span style="color:#ef4444">*</span></label>
                        <div class="input-with-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            <input type="text" name="name" id="nameInput" value="<?= $v('name') ?>" placeholder="Full legal name" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Class <span style="color:#ef4444">*</span></label>
                        <select name="class" id="classSelect" required>
                            <option value="">Choose a class...</option>
                            <?php foreach ($classes as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= $v('class')===$c?'selected':'' ?>><?= htmlspecialchars($c) ?></option>
                            <?php endforeach; ?>
                            <option value="other">Other (type below)</option>
                        </select>
                        <input type="text" id="customClass" name="custom_class" placeholder="Custom class name" style="margin-top:6px;display:none">
                    </div>

                    <div class="form-group">
                        <label>Assign Batch</label>
                        <select name="batch_id">
                            <option value="">- No Batch Assigned -</option>
                            <?php foreach ($batches as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= ($student['batch_id']??0)==$b['id']?'selected':'' ?>><?= sanitize($b['name']) ?><?= $b['class']?' ('.$b['class'].')':'' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Assign Mentor</label>
                        <select name="mentor_id">
                            <option value="">- No Mentor Assigned -</option>
                            <?php foreach ($mentors as $m): ?>
                            <option value="<?= $m['id'] ?>" <?= ($student['mentor_id']??0)==$m['id']?'selected':'' ?>><?= sanitize($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Gender</label>
                        <select name="gender">
                            <option value="Male"   <?= ($student['gender']??'')==='Male'  ?'selected':'' ?>>Male</option>
                            <option value="Female" <?= ($student['gender']??'')==='Female'?'selected':'' ?>>Female</option>
                            <option value="Other"  <?= ($student['gender']??'')==='Other' ?'selected':'' ?>>Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Date of Birth</label>
                        <div class="input-with-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            <input type="date" name="dob" value="<?= $v('dob') ?>">
                        </div>
                    </div>
                </div>

                <div class="form-actions" style="margin-top: 24px; justify-content: flex-end;">
                    <button type="button" class="btn btn-primary" onclick="nextStep(2)">Next: Family & Address <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-left:4px"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></button>
                </div>
            </div>

            <!-- STEP 2: FAMILY & ADDRESS -->
            <div class="wizard-step" id="step-2">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Parent / Guardian Name</label>
                        <div class="input-with-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            <input type="text" name="parent_name" value="<?= $v('parent_name') ?>" placeholder="Legal guardian name">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Primary Phone</label>
                        <div class="phone-wrapper">
                            <div class="phone-prefix">+91</div>
                            <input type="tel" name="phone" value="<?= $v('phone') ?>" placeholder="98765 43210" pattern="[0-9]{10}">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Alternate Phone</label>
                        <div class="phone-wrapper">
                            <div class="phone-prefix">+91</div>
                            <input type="tel" name="alt_phone" value="<?= $v('alt_phone') ?>" placeholder="Optional second number" pattern="[0-9]*">
                        </div>
                    </div>
                    
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Full Residential Address</label>
                        <textarea name="address" placeholder="e.g., Flat 4B, Example Apartments, Street Name, City" rows="3" style="resize:vertical; min-height:80px; padding:12px; border-radius:8px; border:1px solid var(--border); width:100%; box-sizing:border-box; font-family:inherit;"><?= $v('address') ?></textarea>
                    </div>
                </div>

                <div class="form-actions" style="margin-top: 24px; justify-content: space-between;">
                    <button type="button" class="btn btn-secondary" onclick="prevStep(1)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> Back</button>
                    <button type="button" class="btn btn-primary" onclick="nextStep(3)">Next: Setup & Fees <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-left:4px"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg></button>
                </div>
            </div>

            <!-- STEP 3: SETUP & FEES -->
            <div class="wizard-step" id="step-3">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Email Address</label>
                        <div class="input-with-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                            <input type="email" name="email" value="<?= $v('email') ?>" placeholder="student@email.com">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Reset Portal Password <span class="form-tooltip" title="Leave blank to keep current password. Minimum 4 characters."><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg></span></label>
                        <div style="display:flex;gap:8px;align-items:center">
                            <div class="input-with-icon" style="flex:1">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                <input type="text" name="portal_password" id="portalPass" value="<?= htmlspecialchars($_POST['portal_password'] ?? '') ?>" placeholder="New password (optional)">
                            </div>
                            <button type="button" onclick="autoPass()" class="btn btn-secondary" title="Auto-generate"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg></button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Admission Date</label>
                        <div class="input-with-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            <input type="date" name="admission_date" value="<?= $v('admission_date') ?: date('Y-m-d') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Fee Status</label>
                        <select name="fee_status">
                            <option value="Pending" <?= ($student['fee_status']??'Pending')==='Pending'?'selected':'' ?>>Pending</option>
                            <option value="Paid"    <?= ($student['fee_status']??'')==='Paid'   ?'selected':'' ?>>Paid</option>
                            <option value="Partial" <?= ($student['fee_status']??'')==='Partial'?'selected':'' ?>>Partial</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Agreed Fee Amount (₹)</label>
                        <div class="input-with-icon">
                            <span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-light); font-weight:700;">₹</span>
                            <input type="number" name="fee_amount" value="<?= $v('fee_amount') ?>" placeholder="0.00" min="0" step="any">
                        </div>
                    </div>

                    <div class="form-group" style="grid-column:1/-1">
                        <label>Internal Notes</label>
                        <div class="textarea-wrap">
                            <textarea name="notes" id="notesField" oninput="updateCharCount()" placeholder="Any private remarks or notes regarding this student..." rows="3" style="resize:vertical; min-height:80px; padding:12px; border-radius:8px; border:1px solid var(--border); width:100%; box-sizing:border-box; font-family:inherit;" maxlength="500"><?= $v('notes') ?></textarea>
                            <div class="char-count" id="notesCount">0/500</div>
                        </div>
                    </div>
                </div>

                <div class="form-actions" style="margin-top: 24px; justify-content: space-between; border-top: 1px solid var(--border); padding-top: 24px;">
                    <button type="button" class="btn btn-secondary" onclick="prevStep(2)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> Back</button>
                    <button type="submit" class="btn btn-primary" style="background:#10b981; border-color:#10b981; box-shadow: 0 4px 12px rgba(16,185,129,0.25);"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg> Update Student</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Wizard Navigation
function validateStep(stepNum) {
    if (stepNum === 1) {
        const nameInput = document.getElementById('nameInput');
        const classSelect = document.getElementById('classSelect');
        const customClass = document.getElementById('customClass');
        
        if (!nameInput.value.trim()) { alert("Please enter the student's name."); nameInput.focus(); return false; }
        if (!classSelect.value) { alert("Please select a class."); classSelect.focus(); return false; }
        if (classSelect.value === 'other' && !customClass.value.trim()) { alert("Please specify the custom class."); customClass.focus(); return false; }
    }
    return true;
}

function nextStep(toStep) {
    if (validateStep(toStep - 1)) {
        document.querySelectorAll('.wizard-step').forEach(el => el.classList.remove('active'));
        document.getElementById('step-' + toStep).classList.add('active');
        
        for (let i = 1; i <= 3; i++) {
            const stItem = document.getElementById('st-' + i);
            stItem.classList.remove('active', 'completed');
            if (i < toStep) stItem.classList.add('completed');
            else if (i === toStep) stItem.classList.add('active');
        }
        window.scrollTo({top: document.querySelector('.stepper-nav').offsetTop - 20, behavior: 'smooth'});
    }
}

function prevStep(toStep) {
    document.querySelectorAll('.wizard-step').forEach(el => el.classList.remove('active'));
    document.getElementById('step-' + toStep).classList.add('active');
    
    for (let i = 1; i <= 3; i++) {
        const stItem = document.getElementById('st-' + i);
        stItem.classList.remove('active', 'completed');
        if (i < toStep) stItem.classList.add('completed');
        else if (i === toStep) stItem.classList.add('active');
    }
    window.scrollTo({top: document.querySelector('.stepper-nav').offsetTop - 20, behavior: 'smooth'});
}

// Handle custom class input
document.getElementById('classSelect').addEventListener('change', function() {
    const custom = document.getElementById('customClass');
    if (this.value === 'other') {
        custom.style.display = 'block';
        custom.required = true;
        custom.focus();
    } else {
        custom.style.display = 'none';
        custom.required = false;
    }
});

// If custom class submitted, override class select
document.getElementById('mainForm').addEventListener('submit', function() {
    const sel = document.getElementById('classSelect');
    const custom = document.getElementById('customClass');
    if (sel.value === 'other' && custom.value.trim()) {
        const opt = document.createElement('option');
        opt.value = custom.value.trim();
        opt.selected = true;
        sel.appendChild(opt);
    }
});

// Update character count
function updateCharCount() {
    const field = document.getElementById('notesField');
    document.getElementById('notesCount').textContent = field.value.length + '/500';
}
// Init char count on load if there's old input
updateCharCount();

function autoPass() {
    const chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    let pass = '';
    for (let i = 0; i < 8; i++) pass += chars[Math.floor(Math.random() * chars.length)];
    const inp = document.getElementById('portalPass');
    inp.value = pass;
    inp.type = 'text';
    // Copy to clipboard
    if (navigator.clipboard) {
        navigator.clipboard.writeText(pass).catch(()=>{});
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>

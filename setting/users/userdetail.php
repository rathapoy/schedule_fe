<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
checkLogin();

$token = isset($_COOKIE["token"]) ? $_COOKIE["token"] : null;
$userlogin = isset($_SESSION["user_data"]) ? $_SESSION["user_data"] : null;

if (!$userlogin || !$token) {
    echo '<script>window.top.location.replace("/auth/logon.php?error=Session Expired !");</script>';
    exit();
}

// ==========================================
// AJAX Handler: สำหรับ Reset Password ในหน้าเดียวกัน
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    if (!hasPermission("user.reset_password")){
        echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
        exit();
    }
    header('Content-Type: application/json');
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
        exit();
    }

    $reset_user_id = $_POST['user_id'] ?? null;
    $new_password = $_POST['password'] ?? null;

    if (!$reset_user_id || !$new_password) {
        echo json_encode(['status' => 'error', 'message' => 'Missing User ID or Password.']);
        exit();
    }

    // ส่ง Payload ไปหา Python
    $payload = [
        "user_id" => $reset_user_id,
        "password" => $new_password
    ];

    $result = callApi('/user/update', 'PUT', $payload);

    if (isset($result['status']) && $result['status'] === 'success') {
        echo json_encode(['status' => 'success', 'message' => 'Password has been reset successfully!']);
    } else {
        // ดึง Error Message จาก API มาแสดง
        $msg = $result['detail'] ?? ($result['message'] ?? 'Failed to update password.');
        echo json_encode(['status' => 'error', 'message' => $msg]);
    }
    $api_url = "/user/clear-login?user_id=" . urlencode($reset_user_id);
    $result = callApi($api_url, 'POST');
    exit();
}
// ==========================================

// CSRF Protection สำหรับ Form ธรรมดา (เช่น กด SAVE CHANGES)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        exit('<div style="display:flex; justify-content:center; align-items:center; height:100vh; font-family:Sarabun;"><h1><b>Invalid CSRF token</b></h1></div>');
    }
}
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

$notification = null;
if (isset($_GET['status']) && $_GET['status'] === 'update_success') {
    $notification = ['type' => 'success', 'message' => 'Detail updated successfully!'];
}

// Data Fetching
$employee_id = $_POST['employee_id'] ?? $_SESSION['employee_id'] ?? null;
if (!$employee_id) {
    die("Access Denied: Missing Employee ID.");
}

$api_url = "/data/".$employee_id;
$result = callApi($api_url);
$u_data = $result['data'][0] ?? null;

if (!$u_data) {
    die("User not found.");
}

// Permission Gate
if (!hasPermission("user.update")){
    http_response_code(403);
    exit('<div style="display:flex; justify-content:center; align-items:center; height:100vh; font-family:Sarabun;"><h1><b>Access denied.</b></h1></div>');
}

// Fetch Options
$roles = callApi("/get/role")['data'] ?? [];
$shifts = callApi("/schedule/shift?action=get")['data'] ?? [];
$sups = callApi("/data/sup")['data'] ?? [];
$teams = callApi("/get/team")['data'] ?? []; // Fetch dynamic teams
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="/static/font/Sarabun.css" rel="stylesheet" />
    <link rel="stylesheet" href="/static/fontawesome/css/all.css">
    <link rel="stylesheet" href="/static/bootstrap/5.3.1/css/bootstrap.min.css">
    <script src="/static/bootstrap/5.3.1/js/bootstrap.bundle.min.js"></script>
    <script src="/static/jquery/jquery-3.6.0.min.js"></script>
    
    <title>User Detail | <?= htmlspecialchars($u_data['eng_firstname'] ?? 'Profile') ?></title>

    <style>
        body, .modal, .form-control, .form-select, .btn, .toast { font-family: "Sarabun", sans-serif; }
        body { font-size: 13px; background-color: #f0f2f5; }
        .header-bar { background: #fff; border-bottom: 1px solid #ddd; padding: 15px 25px; position: sticky; top: 0; z-index: 1030; }
        .header-title { 
            font-family: "Sarabun", "Arial", "Helvetica", sans-serif; 
            font-weight: bold; font-size: 20px; color: rgb(30, 133, 64); 
            text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.50); margin: 0;
        }
        .main-container { padding: 20px; max-width: 1000px; margin: 0 auto; }
        .card-profile { border: none; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); background: #fff; overflow: hidden; }
        .section-title { font-weight: 700; color: #0d6efd; font-size: 14px; margin-bottom: 15px; display: flex; align-items: center; border-bottom: 1px solid #f1f1f1; padding-bottom: 8px; }
        .section-title i { margin-right: 10px; width: 20px; text-align: center; }
        .form-label { font-weight: 600; color: #555; margin-bottom: 4px; font-size: 12px; }
        .readonly-field { background-color: #f8f9fa !important; border-color: #e9ecef !important; color: #6c757d; }
        .radio-group-box { background: #f8f9fa; padding: 8px 12px; border-radius: 6px; border: 1px solid #dee2e6; }
        .btn-action { font-weight: 600; padding: 6px 20px; border-radius: 8px; transition: 0.2s; }
        .footer-actions { border-top: 1px solid #f1f1f1; padding-top: 20px; margin-top: 10px; }
    </style>
</head>

<body>
<div class="header-bar d-flex justify-content-between align-items-center">
    <span class="header-title">
        <i class="fa-solid fa-users-gear me-2" style="text-shadow: none;"></i>Users Management 
        <i class="fa-solid fa-angle-right mx-2 small text-muted" style="text-shadow: none;"></i> 
        <span style="font-weight: 500; color: #f08c00;">User Detail</span>
    </span>
    
    <div class="d-flex align-items-center gap-2">
        <div class="btn-group" role="group">
            <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-bold me-1 shadow-sm" data-bs-toggle="modal" data-bs-target="#resetPasswordModal">
                <i class="fa fa-key me-1"></i>Reset Password
            </button>
            <!-- <a href="user_logs.php?id=<?= urlencode($employee_id) ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-bold me-1 shadow-sm"><i class="fa fa-file-alt me-1"></i> Logs</a>
            <a href="user_stats.php?id=<?= urlencode($employee_id) ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-bold me-2 shadow-sm"><i class="fa fa-chart-bar me-1"></i> Stats</a> -->
        </div>
        <div class="vr mx-1" style="height: 30px; width: 1.5px; background-color: #ddd; opacity: 1;"></div>
        <a href="user.php" class="btn btn-success btn-sm rounded-pill px-3 fw-bold ms-2 shadow-sm"><i class="fa fa-arrow-left me-1"></i> Back</a>
    </div>
</div>

<div class="main-container">
    <div class="card card-profile p-4 p-md-5">
        <form action="userupdate.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="user_id" value="<?= $u_data['user_id'] ?? '' ?>">

            <!-- SECTION: Basic Info -->
            <div class="section-title"><i class="fa-solid fa-address-card"></i> Basic Employment Information</div>
            <div class="row g-3 mb-5">
                <div class="col-md-3">
                    <label class="form-label">Employee ID</label>
                    <input type="text" class="form-control form-control-sm readonly-field fw-bold" name="employee_id" value="<?= htmlspecialchars($u_data['employee_id'] ?? '') ?>" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Department</label>
                    <input type="text" class="form-control form-control-sm" name="department" value="<?= htmlspecialchars($u_data['department'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Division</label>
                    <input type="text" class="form-control form-control-sm" name="division" value="<?= htmlspecialchars($u_data['division'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">System Role</label>
                    <select class="form-select form-select-sm" name="role_id" required>
                        <option value="">-- Select Role --</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= htmlspecialchars($role['role_id']) ?>" <?= ($u_data['role_name'] ?? '') == $role['role_name'] ? 'selected' : '' ?>><?= htmlspecialchars($role['role_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- SECTION: Thai Name -->
            <div class="section-title text-success"><i class="fa-solid fa-id-badge"></i> ข้อมูลชื่อภาษาไทย (Thai Name)</div>
            <div class="row align-items-end g-3 mb-5">
                <div class="col-md-auto">
                    <label class="form-label d-block">คำนำหน้า</label>
                    <div class="radio-group-box d-flex gap-3">
                        <?php foreach (['นาย', 'นาง', 'น.ส.'] as $opt): ?>
                            <div class="form-check m-0">
                                <input class="form-check-input" type="radio" name="thai_initialname" id="thai_<?= $opt ?>" value="<?= $opt ?>" <?= ($u_data['thai_initialname'] ?? '') === $opt ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="thai_<?= $opt ?>"><?= $opt ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-md"><label class="form-label">ชื่อ (Thai First Name)</label><input type="text" class="form-control form-control-sm" name="thai_firstname" value="<?= htmlspecialchars($u_data['thai_firstname'] ?? '') ?>"></div>
                <div class="col-md"><label class="form-label">นามสกุล (Thai Last Name)</label><input type="text" class="form-control form-control-sm" name="thai_lastname" value="<?= htmlspecialchars($u_data['thai_lastname'] ?? '') ?>"></div>
            </div>

            <!-- SECTION: English Name -->
            <div class="section-title text-secondary"><i class="fa-solid fa-language"></i> English Name Profile</div>
            <div class="row align-items-end g-3 mb-5">
                <div class="col-md-auto">
                    <label class="form-label d-block">Initial</label>
                    <div class="radio-group-box d-flex gap-3">
                        <?php foreach (['Mr.', 'Mrs.', 'Ms.'] as $opt): ?>
                            <div class="form-check m-0">
                                <input class="form-check-input" type="radio" name="eng_initialname" id="eng_<?= $opt ?>" value="<?= $opt ?>" <?= ($u_data['eng_initialname'] ?? '') === $opt ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="eng_<?= $opt ?>"><?= $opt ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-md"><label class="form-label">First Name (English)</label><input type="text" class="form-control form-control-sm" name="eng_firstname" value="<?= htmlspecialchars($u_data['eng_firstname'] ?? '') ?>"></div>
                <div class="col-md"><label class="form-label">Last Name (English)</label><input type="text" class="form-control form-control-sm" name="eng_lastname" value="<?= htmlspecialchars($u_data['eng_lastname'] ?? '') ?>"></div>
            </div>

            <!-- SECTION: Contact -->
            <div class="section-title text-dark"><i class="fa-solid fa-envelope-open-text"></i> Contact & Login</div>
            <div class="row g-3 mb-5">
                <div class="col-md-6">
                    <label class="form-label">Company Email</label>
                    <div class="input-group input-group-sm"><span class="input-group-text"><i class="fa-solid fa-at"></i></span><input type="email" class="form-control" name="email" value="<?= htmlspecialchars($u_data['email'] ?? '') ?>"></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Login Username</label>
                    <input type="text" class="form-control form-control-sm readonly-field" value="<?= htmlspecialchars(strstr($u_data['email'] ?? '', '@', true)) ?>" readonly>
                </div>
            </div>

            <!-- SECTION: Manager -->
            <div class="section-title text-warning-emphasis"><i class="fa-solid fa-sitemap"></i> Management Hierarchy</div>
            <div class="row g-3 mb-5">
                <div class="col-md-6">
                    <label class="form-label">Direct Manager</label>
                    <select class="form-select form-select-sm" name="manager_id" required>
                        <option value="">-- Select Manager --</option>
                        <?php foreach ($sups as $sup): ?>
                            <option value="<?= htmlspecialchars($sup['user_id']) ?>" <?= ($u_data['manager_id'] ?? '') == $sup['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($sup['thai_firstname']) ." ".htmlspecialchars($sup['thai_lastname']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Default Approver</label>
                    <select class="form-select form-select-sm" name="approver_id" required>
                        <option value="">-- Select Approver --</option>
                        <?php foreach ($sups as $sup): ?>
                            <option value="<?= htmlspecialchars($sup['user_id']) ?>" <?= ($u_data['approver_id'] ?? '') == $sup['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($sup['thai_firstname']) ." ".htmlspecialchars($sup['thai_lastname']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- SECTION: Status -->
            <div class="section-title text-danger-emphasis"><i class="fa-solid fa-toggle-on"></i> Account Status & Schedule</div>
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label class="form-label">Team Access</label>
                    <select name="team" class="form-select form-select-sm">
                        <option value="">-- No Team --</option>
                        <?php foreach ($teams as $t): ?>
                            <option value="<?= htmlspecialchars($t['team']) ?>" <?= ($u_data['team'] ?? '') == $t['team'] ? 'selected' : '' ?>><?= htmlspecialchars($t['team']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Default Shift</label>
                    <select class="form-select form-select-sm" name="shift_id">
                        <option value="">-- No Shift --</option>
                        <?php foreach ($shifts as $shift): ?>
                            <option value="<?= htmlspecialchars($shift['shift_id']) ?>" <?= ($u_data['shift_name'] ?? '') == $shift['shift_name'] ? 'selected' : '' ?>><?= htmlspecialchars($shift['shift_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Login Permission</label>
                    <select name="is_active" class="form-select form-select-sm">
                        <option value="1" <?= ($u_data['is_active'] ?? 0) == 1 ? 'selected' : '' ?>>Active</option>
                        <option value="0" <?= ($u_data['is_active'] ?? 0) == 0 ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Scheduled Enable</label>
                    <select name="scheduled" class="form-select form-select-sm">
                        <option value="1" <?= ($u_data['scheduled'] ?? 0) == 1 ? 'selected' : '' ?>>Yes</option>
                        <option value="0" <?= ($u_data['scheduled'] ?? 0) == 0 ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
            </div>

            <!-- FOOTER ACTIONS -->
            <div class="footer-actions d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <span class="text-muted small fw-bold me-2">Last Update:</span>
                    <span class="badge bg-light text-dark border fw-normal" style="font-size: 11px;"><?= !empty($u_data['last_update']) ? (new DateTime($u_data['last_update']))->format('d/m/Y H:i') : 'Never' ?></span>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success btn-action shadow-sm"><i class="fa-solid fa-floppy-disk me-1"></i> SAVE CHANGES</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="resetPasswordModalLabel" style="font-weight: bold; color: #333;">
                    <i class="fa-solid fa-key text-warning me-2"></i>Reset Password
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="resetPasswordForm">
                    <!-- ซ่อน user_id ไว้สำหรับส่งค่า -->
                    <input type="hidden" id="reset_user_id" value="<?= htmlspecialchars($u_data['user_id'] ?? '') ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" class="form-control" id="new_password" placeholder="Enter new password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm Password</label>
                        <input type="password" class="form-control" id="confirm_password" placeholder="Confirm new password" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm fw-bold" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm fw-bold" id="btnSavePassword">Reset Password</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1060;">
    <div id="systemToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="toastMessage" style="font-weight: 500;">
                <!-- Message goes here -->
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

<script>
function confirmDelete() {
    if (confirm("⚠️ Warning: คุณแน่ใจหรือไม่ว่าต้องการลบผู้ใช้งานรายนี้ออกจากระบบ? ข้อมูลที่เกี่ยวข้องอาจได้รับผลกระทบ")) {
        window.location.href = "delete_user.php?id=<?= urlencode($u_data['employee_id'] ?? '') ?>";
    }
}

function showToast(message, type = 'success') {
    const toastEl = document.getElementById('systemToast');
    const toastMessage = document.getElementById('toastMessage');
    const bsToast = new bootstrap.Toast(toastEl, { delay: 3500 });
    
    // ตั้งค่าสีพื้นหลังและตัวหนังสือตามสถานะ
    toastEl.className = 'toast align-items-center border-0 text-white ' + (type === 'success' ? 'text-bg-success' : 'text-bg-danger');
    toastMessage.innerHTML = message;
    
    bsToast.show();
}

$(document).ready(function() {
    <?php if ($notification): ?>
    // แสดง Toast เมื่อกลับมาจากการบันทึกหน้า Profile 
    showToast('<i class="fa-solid fa-circle-check me-2"></i> <?= $notification['message'] ?>', '<?= $notification['type'] ?>');
    <?php endif; ?>

    // Script สำหรับจัดการ Reset Password ผ่าน Modal ภายในหน้าเดียวกัน
    $('#btnSavePassword').click(function(e) {
        e.preventDefault();
        let userId = $('#reset_user_id').val();
        let newPassword = $('#new_password').val();
        let confirmPassword = $('#confirm_password').val();

        // Validate ข้อมูล
        if (!userId) {
            showToast('<i class="fa-solid fa-circle-exclamation me-1"></i> ไม่พบ User ID ไม่สามารถดำเนินการได้', 'danger');
            return;
        }
        if (newPassword === '' || newPassword.length < 4) {
            showToast('<i class="fa-solid fa-circle-exclamation me-1"></i> รหัสผ่านต้องมีความยาวอย่างน้อย 4 ตัวอักษร', 'danger');
            return;
        }
        if (newPassword !== confirmPassword) {
            showToast('<i class="fa-solid fa-circle-exclamation me-1"></i> รหัสผ่านและการยืนยันรหัสผ่านไม่ตรงกัน', 'danger');
            return;
        }

        // เปลี่ยนปุ่มเป็นสถานะกำลังโหลด
        let btn = $(this);
        let originalText = btn.html();
        btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Processing...');

        // ยิง AJAX ไปยังหน้าตัวเอง
        $.ajax({
            url: window.location.pathname, 
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'reset_password', // แนบ action ไปให้ PHP จับว่านี่คือการรีเซ็ตรหัส
                user_id: userId,
                password: newPassword,
                csrf_token: '<?= $csrf ?>'
            },
            success: function(response) {
                if(response.status === 'success') {
                    // แสดงแจ้งเตือนสำเร็จแบบ Toast
                    showToast('<i class="fa-solid fa-circle-check me-1"></i> ' + response.message, 'success');
                    $('#new_password').val('');
                    $('#confirm_password').val('');
                    
                    // ปิด Modal ทันที
                    $('#resetPasswordModal').modal('hide');
                } else {
                    showToast('<i class="fa-solid fa-circle-xmark me-1"></i> ' + (response.message || 'Failed to update password.'), 'danger');
                }
            },
            error: function(xhr, status, error) {
                console.error("AJAX Error:", xhr.responseText);
                showToast('<i class="fa-solid fa-triangle-exclamation me-1"></i> เกิดข้อผิดพลาดในการเชื่อมต่อระบบ (System error)', 'danger');
            },
            complete: function() {
                btn.prop('disabled', false).html(originalText);
            }
        });
    });

    // ล้างค่าใน Modal เวลาที่ปิดมันออก
    $('#resetPasswordModal').on('hidden.bs.modal', function () {
        $('#new_password').val('');
        $('#confirm_password').val('');
    });
});
</script>
</body>
</html>
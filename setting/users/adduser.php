<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
checkLogin();

$token = isset($_COOKIE["token"]) ? $_COOKIE["token"] : null;
$userlogin = isset($_SESSION["user_data"]) ? $_SESSION["user_data"] : null;

if (!$userlogin || !$token) {
    echo '<script>window.top.location.replace("/auth/logon.php?error=Session Expired !");</script>';
    exit();
}

if(!hasPermission('user.user_manage')){
    http_response_code(403);
    exit('<div style="display:flex; justify-content:center; align-items:center; height:100vh; font-family:Sarabun;"><h1><b>Access Denied !</b></h1></div>');
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

// Fetch Dropdown Data
$roles = callApi("/get/role")['data'] ?? [];
$shifts = callApi("/schedule/shift?action=get")['data'] ?? [];
$sups = callApi("/data/sup")['data'] ?? [];
$teams = callApi("/get/team")['data'] ?? [];
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
    <title>Add New User</title>
    <style>
        body, .form-control, .form-select, .btn { font-family: "Sarabun", sans-serif; }
        body { font-size: 13px; background-color: #f0f2f5; }
        .header-bar { background: #fff; border-bottom: 1px solid #ddd; padding: 12px 25px; position: sticky; top: 0; z-index: 1030; }
        .header-title { font-family: "Sarabun", "Arial", "Helvetica", sans-serif; font-weight: bold; font-size: 20px; color: rgb(30, 133, 64); text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.50); margin: 0; }
        .main-container { padding: 20px; max-width: 1000px; margin: 0 auto; }
        .card-profile { border: none; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); background: #fff; overflow: hidden; }
        .section-title { font-weight: 700; color: #0d6efd; font-size: 14px; margin-bottom: 15px; display: flex; align-items: center; border-bottom: 1px solid #f1f1f1; padding-bottom: 8px; }
        .section-title i { margin-right: 10px; width: 20px; text-align: center; }
        .form-label { font-weight: 600; color: #555; margin-bottom: 4px; font-size: 12px; }
        .radio-group-box { background: #f8f9fa; padding: 8px 12px; border-radius: 6px; border: 1px solid #dee2e6; }
        .btn-action { font-weight: 600; padding: 8px 30px; border-radius: 8px; transition: 0.2s; }
        .footer-actions { border-top: 1px solid #f1f1f1; padding-top: 20px; margin-top: 10px; }
    </style>
</head>
<body>

<div class="header-bar d-flex justify-content-between align-items-center shadow-sm">
    <span class="header-title">
        <i class="fa-solid fa-users-gear me-2" style="text-shadow: none;"></i>Users Management 
        <i class="fa-solid fa-angle-right mx-2 small text-muted" style="text-shadow: none;"></i> 
        <span style="font-weight: 500; color: #198754;">Add New User</span>
    </span>
    <a href="user.php" class="btn btn-success btn-sm rounded-pill px-3 fw-bold ms-2 shadow-sm"><i class="fa fa-arrow-left me-1"></i> Back</a>
</div>

<div class="main-container">
    <div class="card card-profile p-4 p-md-5">
        <form action="userupdate.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="user_id" value="">

            <!-- SECTION: Basic Info -->
            <div class="section-title"><i class="fa-solid fa-address-card"></i> Basic Employment Information</div>
            <div class="row g-3 mb-5">
                <div class="col-md-3">
                    <label class="form-label">Employee ID <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" name="employee_id" placeholder="e.g. 001234" required>
                </div>
                <div class="col-md-3"><label class="form-label">Department</label><input type="text" class="form-control form-control-sm" name="department"></div>
                <div class="col-md-3"><label class="form-label">Division</label><input type="text" class="form-control form-control-sm" name="division"></div>
                <div class="col-md-3">
                    <label class="form-label">System Role <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm" name="role_id" required>
                        <option value="">-- Select Role --</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= htmlspecialchars($role['role_id']) ?>"><?= htmlspecialchars($role['role_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- SECTION: Names -->
            <div class="section-title text-success"><i class="fa-solid fa-id-badge"></i> ข้อมูลชื่อภาษาไทย (Thai Name)</div>
            <div class="row align-items-end g-3 mb-5">
                <div class="col-md-auto"><label class="form-label d-block">คำนำหน้า</label>
                    <div class="radio-group-box d-flex gap-3">
                        <?php foreach (['นาย', 'นาง', 'น.ส.'] as $opt): ?>
                            <div class="form-check m-0"><input class="form-check-input" type="radio" name="thai_initialname" value="<?= $opt ?>"><label class="form-check-label small"><?= $opt ?></label></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-md"><label class="form-label">ชื่อ</label><input type="text" class="form-control form-control-sm" name="thai_firstname"></div>
                <div class="col-md"><label class="form-label">นามสกุล</label><input type="text" class="form-control form-control-sm" name="thai_lastname"></div>
            </div>

            <div class="section-title text-secondary"><i class="fa-solid fa-language"></i> Name Profile (English)</div>
            <div class="row align-items-end g-3 mb-5">
                <div class="col-md-auto"><label class="form-label d-block">Initial</label>
                    <div class="radio-group-box d-flex gap-3">
                        <?php foreach (['Mr.', 'Mrs.', 'Ms.'] as $opt): ?>
                            <div class="form-check m-0"><input class="form-check-input" type="radio" name="eng_initialname" value="<?= $opt ?>"><label class="form-check-label small"><?= $opt ?></label></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-md"><label class="form-label">First Name</label><input type="text" class="form-control form-control-sm" name="eng_firstname"></div>
                <div class="col-md"><label class="form-label">Last Name</label><input type="text" class="form-control form-control-sm" name="eng_lastname"></div>
            </div>

            <div class="section-title text-dark"><i class="fa-solid fa-envelope-open-text"></i> Contact Information</div>
            <div class="row g-3 mb-5">
                <div class="col-md-6"><label class="form-label">Company Email <span class="text-danger">*</span></label><input type="email" class="form-control form-control-sm" name="email" placeholder="example@company.com" required></div>
                <div class="col-md-6"><label class="form-label">Initial Password</label><input type="text" class="form-control form-control-sm bg-light" value="Default from DB" readonly></div>
            </div>

            <!-- SECTION: Hierarchy -->
            <div class="section-title text-warning-emphasis"><i class="fa-solid fa-sitemap"></i> Management Hierarchy</div>
            <div class="row g-3 mb-5">
                <div class="col-md-6">
                    <label class="form-label">Direct Manager <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm" name="manager_id" required>
                        <option value="">-- Select Manager --</option>
                        <?php foreach ($sups as $sup): ?>
                            <option value="<?= htmlspecialchars($sup['user_id']) ?>"><?= htmlspecialchars($sup['thai_firstname']) ." ".htmlspecialchars($sup['thai_lastname']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Default Approver <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm" name="approver_id" required>
                        <option value="">-- Select Approver --</option>
                        <?php foreach ($sups as $sup): ?>
                            <option value="<?= htmlspecialchars($sup['user_id']) ?>"><?= htmlspecialchars($sup['thai_firstname']) ." ".htmlspecialchars($sup['thai_lastname']) ?></option>
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
                            <option value="<?= htmlspecialchars($t['team']) ?>"><?= htmlspecialchars($t['team']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Default Shift</label>
                    <select class="form-select form-select-sm" name="shift_id">
                        <option value="">--No Shift--</option>
                        <?php foreach ($shifts as $shift): ?>
                            <option value="<?= htmlspecialchars($shift['shift_id']) ?>"><?= htmlspecialchars($shift['shift_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3"><label class="form-label">Login Permission</label><select name="is_active" class="form-select form-select-sm"><option value="1">Active</option><option value="0">Inactive</option></select></div>
                <div class="col-md-3"><label class="form-label">Scheduled Enable</label><select name="scheduled" class="form-select form-select-sm"><option value="1">Yes</option><option value="0" selected>No</option></select></div>
            </div>

            <div class="footer-actions d-flex justify-content-center gap-3">
                <button type="submit" class="btn btn-success btn-action shadow-sm"><i class="fa-solid fa-user-plus me-1"></i> CREATE USER</button>
                <a href="user.php" class="btn btn-light btn-action border shadow-sm"><i class="fa-solid fa-xmark me-1"></i> CANCEL</a>
            </div>
        </form>
    </div>
</div>

</body>
</html>
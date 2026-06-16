<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
checkLogin();

$manage_mode = $_POST['manage_mode'] ?? '0';
if ($manage_mode === '1' && !hasPermission('schedule.management')) {
    echo "Error: Access denied.\n"; exit;
}
// --- 1. Init & Auth Check ---
$token = isset($_COOKIE["token"]) ? $_COOKIE["token"] : null;
$userlogin = isset($_SESSION["user_data"]) ? $_SESSION["user_data"] : null;
if (!$userlogin || !$token) {
    header("Location: /auth/logon.php");
    exit();
}

$userMap = [];
$userTeam = [];
$userApiUrl = "/data/schedule"; 
$userApiData = callApi($userApiUrl);
if (isset($userApiData['data'])) {
    foreach ($userApiData['data'] as $u) {
        $fname = $u['thai_firstname'] ?? $u['eng_firstname'] ?? '-';
        $lname = $u['thai_lastname'] ?? $u['eng_lastname'] ?? '-';
        $userMap[$u['user_id']] = trim("$fname")." ".trim("$lname");
        $userTeam[$u['user_id']] = $u['team'] ?? '';
    }
}

function getUserName($id, $map) {
    return $map[$id] ?? '-';
}

// --- 2. Data Validation ---
$req_mode = $_POST['req'] ?? '';
$schedule_id = $_POST['schedule_id'] ?? '';
$user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
$date_val = $_POST['date'] ?? '';
$manage_mode = $_POST['manage_mode'] ?? '0';
$csrf_token = $_SESSION['csrf_token'] ?? '';

$error_message = '';
if (empty($user_id) || empty($date_val)) {
    $error_message = "Error: Missing required data (User ID or Date). Please go back to Schedule and try again.";
}

// --- 3. Save Process ---
if (isset($_POST['action_save']) && $_POST['action_save'] === 'true') {
    if (
        !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        die("Error: Invalid CSRF token!");
    }

    $target_date = $_POST['schedule_date'];
    $redirect_year  = date('Y', strtotime($target_date));
    $redirect_month = date('n', strtotime($target_date));
    $manage_mode_save = $_POST['manage_mode'] ?? '0';
    $payload = [
        'user_id'          => $user_id,
        'work_group_id'    => !empty($_POST['work_group_id']) ? $_POST['work_group_id'] : null,
        'work_schedule_id' => !empty($_POST['work_schedule_id']) ? $_POST['work_schedule_id'] : null,
        'schedule_date'    => $target_date,
        'status'           => $_POST['status'],
        'remark'           => $_POST['remark']
    ];
    if (!empty($_POST['schedule_id']) && $_POST['schedule_id'] !== 'new') {
        $payload['schedule_id'] = (int)$_POST['schedule_id'];
    }
    $apiUrl = "/api/update/schedule";
    callApi($apiUrl, "POST", [$payload]);
    $redirectUrl = "schedule.php?month=$redirect_month&year=$redirect_year&manage_mode=$manage_mode_save";
    header("Location: " . $redirectUrl);
    exit();
}

// --- 4. Fetch Display Data ---
if (empty($error_message)) {
    $shifts = callApi("/schedule/workschedule?action=get")['data'] ?? [];
    $targetTeam = $userTeam[$user_id] ?? '';
    $workgroups = callApi("/schedule/workgroup?action=get&team=".$targetTeam)['data'] ?? []; 
    $userName = getUserName($user_id, $userMap);

    $currentData = [];
    if ($req_mode === 'edit' && $schedule_id !== 'new') {
        $res = callApi("/schedule/monthschedule?schedule_id=" . $schedule_id);
        if (isset($res['data'][0])) {
            $currentData = $res['data'][0];
        }
    }

    $val_shift_id = $currentData['work_schedule_id'] ?? '';
    $val_group_id = $currentData['work_group_id'] ?? '';
    $val_status = $currentData['status'] ?? 'Normal';
    $val_remark = $currentData['remark'] ?? '';
}
if (hasPermission('schedule.edit_more_status')) {
    $status_options = ['Normal','Pending','Standby','Accept','Requested','OT','OT1.5','OT3','Note-Swap','Normal-Locked'];
}else {$status_options = ['Normal','OT'];}


$pageTitleIcon = ($req_mode === 'edit') ? "fa-regular fa-pen-to-square" : "fa-regular fa-calendar-plus";
$pageTitleText = ($req_mode === 'edit') ? "Edit Schedule" : "Add Schedule";
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="/static/font/Sarabun.css" rel="stylesheet" />
    <link rel="stylesheet" href="/static/fontawesome/css/all.css">
    <link rel="stylesheet" href="/static/bootstrap/5.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="/static/css/schedule.css">
    <script src="/static/bootstrap/5.3.1/js/bootstrap.bundle.min.js"></script>
    <script src="/static/jquery/jquery-3.6.0.min.js"></script>
    <title><?= $pageTitleText ?></title>
</head>
<body>

<div class="header-bar d-flex justify-content-between align-items-center">
    <span class="header-title">
        <i class="fa-regular fa-calendar me-2" style="text-shadow: none;"></i>Schedule 
        <i class="fa-solid fa-angle-right mx-2 small text-muted" style="text-shadow: none;"></i> 
        <span style="font-weight: 500;"><?= $pageTitleText ?></span>
    </span>
    
    <a href="schedule.php?manage_mode=<?= $manage_mode ?>&month=<?= date('n', strtotime($date_val ?: date('Y-m-d'))) ?>&year=<?= date('Y', strtotime($date_val ?: date('Y-m-d'))) ?>" 
       class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-bold">
        <i class="fa-solid fa-arrow-left me-1"></i> Back
    </a>
</div>

<div class="main-container">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-xl-7">
            
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger shadow-sm border-0 rounded-4 p-4 text-center">
                    <i class="fa-solid fa-circle-exclamation fa-3x mb-3 opacity-50"></i>
                    <h4 class="fw-bold">Something went wrong!</h4>
                    <p class="mb-4 text-secondary"><?= htmlspecialchars($error_message) ?></p>
                    <a href="schedule.php" class="btn btn-danger px-4 rounded-pill fw-bold">Return to Schedule</a>
                </div>
            <?php else: ?>

                <div class="card card-form">
                    <div class="card-header-custom d-flex align-items-center">
                        <i class="<?= $pageTitleIcon ?> me-2 text-success"></i> Information
                    </div>
                    <div class="card-body p-4 p-md-5">
                        <form method="POST" action="request_edit.php">
                            <input type="hidden" name="action_save" value="true">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
                            <input type="hidden" name="user_id" value="<?= $user_id ?>">
                            <input type="hidden" name="manage_mode" value="<?= $manage_mode ?>">

                            <!-- Info Section -->
                            <div class="row g-4 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">Employee Name</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-user text-muted"></i></span>
                                        <input type="text" class="form-control bg-light border-start-0" value="<?= htmlspecialchars($userName) ?>" readonly>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Target Date</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0"><i class="fa-regular fa-calendar-check text-muted"></i></span>
                                        <input type="text" name="schedule_date_display" class="form-control bg-light border-start-0 fw-bold text-primary" value="<?= date('d M Y', strtotime($date_val)) ?>" readonly>
                                        <input type="hidden" name="schedule_date" value="<?= $date_val ?>">
                                    </div>
                                </div>
                            </div>

                            <hr class="my-4 opacity-25">

                            <!-- Configuration Section -->
                            <div class="row g-4 mb-4">
                                <div class="col-md-6">
                                    <label for="work_schedule_id" class="form-label">Work Schedule <span class="text-danger">*</span></label>
                                    <select name="work_schedule_id" id="work_schedule_id" class="form-select shadow-sm" required>
                                        <option value="">-- Select Schedule --</option>
                                        <?php foreach ($shifts as $s): ?>
                                            <option value="<?= $s['type_id'] ?>" <?= ($val_shift_id == $s['type_id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($s['type_name']) ?> (<?= secondsToTime($s['start_time']) ?> - <?= secondsToTime($s['end_time']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="work_group_id" class="form-label">Work Group</label>
                                    <select name="work_group_id" id="work_group_id" class="form-select shadow-sm">
                                        <option value="">-- No Work Group --</option>
                                        <?php foreach ($workgroups as $wg): ?>
                                            <option value="<?= $wg['work_group_id'] ?>" <?= ($val_group_id == $wg['work_group_id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($wg['work_group'])." ".htmlspecialchars($wg['description'] ?? '') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="status" class="form-label">Status Type</label>
                                    <select name="status" id="status" class="form-select shadow-sm">
                                        <?php foreach ($status_options as $st): ?>
                                            <option value="<?= $st ?>" <?= ($val_status == $st) ? 'selected' : '' ?>><?= $st ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label for="remark" class="form-label">Remark / Notes</label>
                                    <textarea name="remark" id="remark" class="form-control shadow-sm" rows="3" placeholder="Enter additional details..."><?= htmlspecialchars($val_remark) ?></textarea>
                                </div>
                            </div>

                            <!-- Footer Actions -->
                            <div class="d-flex justify-content-center gap-3 mt-5">
                                <button type="submit" class="btn btn-save btn-success text-white shadow-sm">
                                    <i class="fa-solid fa-floppy-disk me-2"></i> SAVE CHANGES
                                </button>
                                <a href="schedule.php?manage_mode=<?= $manage_mode ?>&month=<?= date('n', strtotime($date_val)) ?>&year=<?= date('Y', strtotime($date_val)) ?>" 
                                   class="btn btn-cancel shadow-sm">
                                    <i class="fa-solid fa-xmark me-2"></i> CANCEL
                                </a>
                            </div>

                        </form>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

</body>
</html>
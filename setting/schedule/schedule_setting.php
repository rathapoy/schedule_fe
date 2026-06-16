<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';

// --- Authentication Check ---
$token = isset($_COOKIE["token"]) ? $_COOKIE["token"] : null;
$user = isset($_SESSION["user_data"]) ? $_SESSION["user_data"] : null;
if (!checkLogin() || !$user || !$token) {
    echo '<script>window.top.location.replace("/auth/logon.php?error=Session Expired !");</script>';
    exit();
}
if(!hasPermission('setting.schedule')){
    http_response_code(403);
    exit('
        <div style="display:flex; justify-content:center; align-items:center; height:100vh; font-family:Sarabun, Arial;">
            <h1 style="color:#d9534f;"><b>Access Denied !</b></h1>
        </div>
    ');
}
// บังคับการส่งข้อมูลเป็น UTF-8 เพื่อป้องกันตัวอักษรเพี้ยน
header('Content-Type: text/html; charset=utf-8');

// --- Parameter Handling ---
$alert_status  = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_SPECIAL_CHARS) ?? $_GET['alert_status'] ?? '';
$alert_message = filter_input(INPUT_GET, 'msg', FILTER_SANITIZE_SPECIAL_CHARS) ?? $_GET['alert_message'] ?? '';

$currentMonth = date('n');
$currentYear = date('Y');
$target_year = $_POST['year'] ?? $_GET['year'] ?? $currentYear;
$startYear = $target_year - 1;
$endYear = $target_year + 1;

// --- API Calls for Mapping & Global Use ---
$userapi_url = "/data/schedule";
$result_user = callApi($userapi_url);
$users = (isset($result_user['status']) && $result_user['status'] === 'success' && isset($result_user['data'])) ? $result_user['data'] : [];
$userCount = count($users);

// --- API: Holiday Action (Add/Delete/Config) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['holidayDate']) && isset($_POST['holidayDescription'])) {
    $api_data = ['date' => $_POST['holidayDate'], 'description' => $_POST['holidayDescription']];
    $update_result = callApi('/schedule/holiday?action=add', 'PUT', $api_data);
    $alert_status = ($update_result && $update_result['status'] === 'success') ? "Success" : "Error";
    $alert_message = $update_result['message'] ?? "API Error";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_holiday']) && $_POST['delete_holiday'] === 'delete') {
    $api_data = ["holiday_id" => intval($_POST['holiday_id'])];
    $delete_result = callApi('/schedule/holiday?action=delete','DELETE', $api_data);
    $alert_status = ($delete_result && $delete_result['status'] === 'success') ? "Success" : "Error";
    $alert_message = $delete_result['message'] ?? "API Error";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_config') {
    $add_month = filter_input(INPUT_POST, 'add_month', FILTER_VALIDATE_INT);
    $add_year  = filter_input(INPUT_POST, 'add_year', FILTER_VALIDATE_INT);
    $add_team  = filter_input(INPUT_POST, 'add_team', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''; 
    $modifier = $user['englishname'] ?? $user['username'] ?? 'PHP_Server_User';

    if ($add_month && $add_year && $add_team) {
        $api_data = ['month_year' => sprintf("%d-%02d", $add_year, $add_month), 'team_config' => $add_team, 'is_enabled' => 0, 'is_locked'  => 0, 'modify_by'  => $modifier];
        $add_result = callApi('/schedule/month/config?action=add', 'PUT', $api_data);
        $redirect_team_param = "&team=" . urlencode($add_team);
        $st = (!empty($add_result['status']) && $add_result['status'] === 'success') ? "Success" : "Error";
        header("Location: ".$_SERVER['PHP_SELF']."?year={$target_year}{$redirect_team_param}&status={$st}&msg=".urlencode($add_result['message'] ?? 'API Error'));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $config_id  = filter_input(INPUT_POST, 'config_id', FILTER_VALIDATE_INT);
    $is_enabled = filter_input(INPUT_POST, 'is_enabled', FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
    $is_locked  = filter_input(INPUT_POST, 'is_locked', FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
    $team_param = filter_input(INPUT_POST, 'team_param', FILTER_SANITIZE_SPECIAL_CHARS) ?? '';
    $modifier = $user['englishname'] ?? $user['username'] ?? 'PHP_Server_User';

    if ($config_id !== false && $is_enabled !== null && $is_locked !== null) {
        $api_data = ['config_id' => (int)$config_id, 'is_enabled' => (int)$is_enabled, 'is_locked' => (int)$is_locked, 'modify_by' => $modifier];
        $update_result = callApi('/schedule/month/config?action=update', 'PUT', $api_data);
        $redirect_team_param = $team_param ? "&team=" . urlencode($team_param) : "";
        $st = (!empty($update_result['status']) && $update_result['status'] === 'success') ? "Success" : "Error";
        header("Location: ".$_SERVER['PHP_SELF']."?year={$target_year}{$redirect_team_param}&status={$st}&msg=".urlencode($update_result['message'] ?? 'API Error'));
        exit;
    }
}

// --- Fetch Data for UI ---
$TeamConfigUrl = "/schedule/month/config?action=get_teams";
$TeamConfig = callApi($TeamConfigUrl);
$master_teams = [];
if ($TeamConfig['status'] === 'success' && is_array($TeamConfig['data'])) {
    foreach ($TeamConfig['data'] as $t) { $master_teams[] = $t['team']; }
}
$filter_team = $_GET['team'] ?? ($master_teams[0] ?? '');

$MonthConfigUrl = "/schedule/month/config?action=get";
$MonthConfig = callApi($MonthConfigUrl);
$month_data_map = []; 
if ($MonthConfig['status'] === 'success' && is_array($MonthConfig['data'])) {
    foreach ($MonthConfig['data'] as $item) {
        $parts = explode('-', $item['month_year']);
        if ($parts[0] == $target_year) { $month_data_map[(int)$parts[1]][] = $item; }
    }
}

$holiday_url = "/schedule/holiday?action=get&year=".$target_year;
$holiday_data = callApi($holiday_url);
$holidays = $holiday_data['data'] ?? [];
$holidayCount = count($holidays);

$monthNamesEnglish = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

function get_status_info($is_flag, $true_status, $false_status) {
    if ($is_flag == 1) return ['status' => $true_status, 'color' => 'warning'];
    return ['status' => $false_status, 'color' => 'success']; 
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    
    <!-- Font & Custom Styles -->
    <link href="/static/font/Sarabun.css" rel="stylesheet" />
    <link rel="stylesheet" href="/static/css/content.css">

    <link rel="stylesheet" href="/static/fontawesome/css/all.css">
    <link rel="stylesheet" href="/static/bootstrap/5.3.1/css/bootstrap.min.css">
    <script src="/static/bootstrap/5.3.1/js/bootstrap.bundle.min.js"></script>
    <script src="/static/jquery/jquery-3.6.0.min.js"></script>
    <title>Setting | Schedule Overview</title>
</head>
<body class="p-0">

<!-- Header Bar -->
<div class="sticky-top bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center" style="z-index: 1030;">
    <h5 class="m-0 fw-bold text-theme-green" style="text-shadow: 1px 1px 3px rgba(0,0,0,0.1); font-size: 20px;">
        <i class="fa-solid fa-gear me-2"></i>Schedule Settings <i class="fa fa-angle-right mx-2 text-secondary" style="font-size: 0.8em;"></i>
        <span class="text-dark"><i class="fa-solid fa-chart-pie me-1"></i> Overview</span>
    </h5>
    
    <div class="d-flex align-items-center gap-3">
        <!-- Year Navigation -->
        <div class="d-flex align-items-center gap-2 year-control-group shadow-sm">
            <span class="small fw-bold text-muted ms-2 me-1">YEAR:</span>
            <form method="GET" class="m-0">
                <input type="hidden" name="year" value="<?= $target_year - 1 ?>">
                <?php if($filter_team): ?><input type="hidden" name="team" value="<?= htmlspecialchars($filter_team) ?>"><?php endif; ?>
                <button type="submit" class="btn-nav-year shadow-sm"><i class="fa-solid fa-chevron-left" style="font-size: 10px;"></i></button>
            </form>
            <div class="fw-bold text-success px-2 fs-6"><?= $target_year ?></div>
            <form method="GET" class="m-0 me-1">
                <input type="hidden" name="year" value="<?= $target_year + 1 ?>">
                <?php if($filter_team): ?><input type="hidden" name="team" value="<?= htmlspecialchars($filter_team) ?>"><?php endif; ?>
                <button type="submit" class="btn-nav-year shadow-sm"><i class="fa-solid fa-chevron-right" style="font-size: 10px;"></i></button>
            </form>
        </div>
        
        <div class="vr mx-1" style="height: 38px; width: 1.5px; background-color: #ddd;"></div>
        
        <span class="badge bg-success bg-opacity-10 text-success border border-success-subtle px-3 py-2 rounded-pill shadow-sm" style="font-size: 11px;">
            <i class="fa-solid fa-circle-check me-1"></i> System Ready
        </span>
    </div>
</div>

<div class="container-fluid px-4 my-4">
    <div class="row g-4 mb-4">
        <!-- Top Cards -->
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                <div class="card-header bg-success bg-opacity-10 border-0 py-3">
                    <h6 class="mb-0 fw-bold text-success"><i class="fa-solid fa-file-import me-2"></i> Schedule Import</h6>
                </div>
                <form method="POST" class="d-flex flex-column h-100">    
                    <div class="card-body p-4 d-flex flex-column"> 
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <select name="month" class="form-select border-success shadow-none bg-light" required>
                                <?php foreach ($monthNamesEnglish as $num => $name): ?>
                                    <option value="<?= $num ?>" <?= $num == $currentMonth ? 'selected' : '' ?>><?= $name ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="year" class="form-select border-success shadow-none bg-light" required style="width: 100px;">
                                <?php for ($year = $endYear; $year >= $startYear; $year--): ?>
                                    <option value="<?= $year ?>" <?= $year == $target_year ? 'selected' : '' ?>><?= $year ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="mt-auto">
                            <button type="submit" formaction="download_template.php" class="btn btn-dark w-100 rounded-pill shadow-sm fw-bold">
                                <i class="fa-solid fa-download me-1"></i> Template & Import
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-bold text-secondary"><i class="fa-solid fa-clock me-2"></i> Work Schedule</h6>
                </div>
                <div class="card-body p-4 d-flex flex-column">
                    <p class="text-muted small mb-3">Define timetable types and colors. (Ex: Day Shift, Night Shift, D, N)</p>
                    <div class="mt-auto">
                        <a href="workschedule_setting.php" class="btn btn-outline-success w-100 rounded-pill fw-bold border-2">Configure Types</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-bold text-secondary"><i class="fa-solid fa-users-viewfinder me-2"></i> Work Group</h6>
                </div>
                <div class="card-body p-4 d-flex flex-column">
                    <p class="text-muted small mb-3">Manage groups or staff seats associated with teams. (Ex: D1, D2)</p>
                    <div class="mt-auto">
                        <a href="workgroup_setting.php" class="btn btn-outline-success w-100 rounded-pill fw-bold border-2">Manage Groups</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-bold text-secondary"><i class="fa-solid fa-calendar-week me-2"></i> Shift Setup</h6>
                </div>
                <div class="card-body p-4 d-flex flex-column">
                    <p class="text-muted small mb-3">Set up rotating shifts and patterns for staff members. (Ex: A, B, C)</p>
                    <div class="mt-auto">
                        <a href="shift_setting.php" class="btn btn-outline-success w-100 rounded-pill fw-bold border-2">Shift Setup</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bottom Row -->
    <div class="row g-4">
        
        <!-- Activation Control: แสดงความสูงพอดี 12 เดือน (ลบ h-100 ออก เพื่อไม่ให้โดนบังคับสูงเกินไป) -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100">
                <div class="card-header bg-warning bg-opacity-10 border-0 py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-1 fw-bold text-warning-emphasis"><i class="fa-solid fa-toggle-on me-2"></i> Activation Control</h6>
                        <small class="text-muted fw-bold" style="font-size: 11px;">YEAR: <?= $target_year ?></small>
                    </div>
                    <select id="filterTeam" class="form-select form-select-sm shadow-none border-warning bg-white fw-bold text-dark rounded-pill px-3" style="width: 140px; cursor: pointer;" onchange="window.location.href='?year=<?= $target_year ?>&team=' + this.value">
                        <?php foreach ($master_teams as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>" <?= $filter_team === $t ? 'selected' : '' ?>>Team: <?= htmlspecialchars($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="card-body p-0"> <!-- ไม่กำหนด max-height หรือ overflow -->
                    <ul class="list-group list-group-flush" id="configList">
                        <?php for ($m = 1; $m <= 12; $m++): 
                            $items = $month_data_map[$m] ?? [];
                            $data_item = null;
                            foreach ($items as $item) { if (($item['team_config'] ?? '') === $filter_team) { $data_item = $item; break; } }
                        ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center py-3 border-light">
                                <div class="fw-bold text-dark fs-6"><?= $monthNamesEnglish[$m] ?></div>
                                <?php if ($data_item): 
                                    $is_locked = (int)$data_item['is_locked']; 
                                    $is_enabled = (int)$data_item['is_enabled'];
                                ?>
                                    <div class="d-flex gap-2">
                                        <form method="POST" class="m-0">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="config_id" value="<?= $data_item['config_id'] ?>">
                                            <input type="hidden" name="is_enabled" value="<?= $is_enabled ?>">
                                            <input type="hidden" name="is_locked" value="<?= $is_locked == 1 ? 0 : 1 ?>">
                                            <input type="hidden" name="team_param" value="<?= htmlspecialchars($filter_team) ?>">
                                            <button type="submit" class="btn <?= $is_locked ? 'btn-warning text-dark' : 'btn-light border text-secondary' ?> fw-bold shadow-sm btn-status-action">
                                                <?= $is_locked ? '<i class="fa-solid fa-lock me-1"></i> LOCKED' : '<i class="fa-solid fa-lock-open me-1"></i> UNLOCKED' ?>
                                            </button>
                                        </form>
                                        <form method="POST" class="m-0">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="config_id" value="<?= $data_item['config_id'] ?>">
                                            <input type="hidden" name="is_enabled" value="<?= $is_enabled == 1 ? 0 : 1 ?>">
                                            <input type="hidden" name="is_locked" value="<?= $is_locked ?>">
                                            <input type="hidden" name="team_param" value="<?= htmlspecialchars($filter_team) ?>">
                                            <button type="submit" class="btn <?= $is_enabled ? 'btn-success text-white' : 'btn-outline-danger' ?> fw-bold shadow-sm btn-status-action">
                                                <?= $is_enabled ? '<i class="fa-solid fa-eye me-1"></i> ENABLED' : '<i class="fa-solid fa-eye-slash me-1"></i> DISABLED' ?>
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <button class="btn btn-outline-success btn-add-config shadow-sm" onclick="openAddConfigModal(<?= $m ?>, '<?= $monthNamesEnglish[$m] ?>', <?= $target_year ?>, '<?= htmlspecialchars($filter_team) ?>')" title="Initialize Config">
                                        <i class="fa-solid fa-plus"></i>
                                    </button>
                                <?php endif; ?>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Public Holidays: ล็อกความสูงและบังคับให้มี Scroll bar (คล้าย h-50) -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-secondary"><i class="fa-solid fa-umbrella-beach me-2"></i> Public Holidays</h6>
                    <button class="btn btn-dark btn-sm rounded-pill px-3 shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#addHolidayModal" style="font-size: 11px;">
                        <i class="fa fa-plus me-1"></i> ADD
                    </button>
                </div>
                <div class="card-body p-0" style="height: 645px; overflow-y: auto; scrollbar-width: thin;">
                    <ul class="list-group list-group-flush">
                        <?php if (!empty($holidays)): ?>
                            <?php foreach ($holidays as $h): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-2 border-light">
                                    <div>
                                        <div class="fw-bold text-primary mb-1"><i class="fa-regular fa-calendar me-1"></i> <?= date('d M Y', strtotime($h['date'])) ?></div>
                                        <div class="small text-secondary text-truncate" style="max-width: 300px;"><?= htmlspecialchars($h['description']) ?></div>
                                    </div>
                                    <form method="POST" onsubmit="return confirm('Delete holiday?');" class="m-0">
                                        <input type="hidden" name="delete_holiday" value="delete">
                                        <input type="hidden" name="holiday_id" value="<?= $h['holiday_id'] ?>">
                                        <button class="btn btn-light text-danger rounded-circle shadow-sm" style="width: 32px; height: 32px; padding: 0;">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li class="list-group-item text-center p-5 text-muted border-0">
                                <i class="fa-regular fa-calendar-xmark fs-2 mb-3 text-light-emphasis"></i>
                                <p class="mb-0">No holidays listed for this year.</p>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Quick Summary: ปล่อยให้สั้นกะทัดรัด (คล้าย h-20) -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-bold text-secondary"><i class="fa-solid fa-chart-pie me-2"></i> Quick Summary</h6>
                </div>
                <div class="card-body d-flex flex-column justify-content-center p-4">
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="p-3 border-0 rounded-4 bg-warning bg-opacity-10 h-100 d-flex flex-column justify-content-center text-center shadow-sm">
                                <span class="text-warning-emphasis d-block text-uppercase fw-bold mb-1" style="letter-spacing: 0.5px; font-size: 10px;">Holidays (<?= $target_year ?>)</span>
                                <span class="fs-1 fw-bold text-warning"><?= $holidayCount ?></span>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 border-0 rounded-4 bg-success bg-opacity-10 h-100 d-flex flex-column justify-content-center text-center shadow-sm">
                                <span class="text-success-emphasis d-block text-uppercase fw-bold mb-1" style="letter-spacing: 0.5px; font-size: 10px;">Configs Active</span>
                                <span class="fs-1 fw-bold text-success"><?= count($month_data_map) ?></span>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="p-4 border-0 rounded-4 bg-primary bg-opacity-10 d-flex align-items-center justify-content-between shadow-sm mt-2">
                                <div>
                                    <span class="text-primary-emphasis d-block text-uppercase fw-bold mb-1" style="letter-spacing: 1px; font-size: 10px;">Total Scheduled Users</span>
                                    <span class="fs-1 fw-bold text-primary"><?= $userCount ?></span>
                                </div>
                                <div class="fs-1 text-primary opacity-50">
                                    <i class="fa-solid fa-users-gear"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 pt-3 text-center border-top border-light">
                        <p class="text-muted mb-0 small" style="line-height: 1.6;">
                            System is currently monitoring <strong class="text-dark"><?= $userCount ?></strong> active staff members.<br>
                            Monthly configurations are global settings.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Add Holiday -->
<div class="modal fade" id="addHolidayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white border-0 pb-0">
                <h6 class="modal-title fw-bold">New Holiday Entry</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary">Holiday Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control form-control-lg border-0 shadow-sm" name="holidayDate" required>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-bold text-secondary">Description <span class="text-danger">*</span></label>
                    <textarea class="form-control border-0 shadow-sm" rows="3" name="holidayDescription" required placeholder="e.g., Songkran Festival"></textarea>
                </div>
            </div>
            <div class="modal-footer bg-white border-0 pt-0 pb-4 px-4">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">Save Holiday</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Add Schedule Config -->
<div class="modal fade" id="addConfigModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-success text-white border-0 pb-0">
                <h6 class="modal-title fw-bold">Initialize Config</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="add_config">
                <input type="hidden" name="add_month" id="add_month">
                <input type="hidden" name="add_year" id="add_year">
                <input type="hidden" name="add_team" id="add_team">
                <div class="modal-body text-center p-4 bg-light">
                    <p class="mb-3 small fw-bold text-secondary" id="addConfigTitle">Month: ...</p>
                    <div class="alert alert-success border-0 small mb-0 rounded-3 shadow-sm">
                        Confirming creation for Team:<br>
                        <strong id="display_add_team" class="fs-6">...</strong>
                    </div>
                </div>
                <div class="modal-footer bg-white border-0 pt-0 pb-4 px-4">
                    <button type="submit" class="btn btn-success w-100 fw-bold rounded-pill shadow-sm">Confirm & Create</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if (!empty($alert_status) && !empty($alert_message)): ?>
    <div class="toast-container-custom">
        <div class="toast-custom <?= strtolower($alert_status) === 'success' ? 'success' : 'error' ?>">
            <div class="toast-icon"><?= strtolower($alert_status) === 'success' ? '✓' : '✕' ?></div>
            <div class="toast-content">
                <strong><?= strtoupper($alert_status) ?>:</strong> <?= htmlspecialchars($alert_message) ?>
            </div>
            <button class="toast-close" onclick="this.parentElement.remove(); document.querySelector('.page-dim-overlay').remove();">&times;</button>
            <div class="toast-progress"></div>
        </div>
    </div>
    <div class="page-dim-overlay" onclick="this.remove(); document.querySelector('.toast-container-custom').remove();"></div>
    <script>
        setTimeout(() => { $('.toast-custom').fadeOut(); $('.page-dim-overlay').fadeOut(); }, 2400);
    </script>
<?php endif; ?>

<script>
    function openAddConfigModal(month, monthName, year, teamName) {
        document.getElementById('add_month').value = month;
        document.getElementById('add_year').value = year;
        document.getElementById('add_team').value = teamName;
        document.getElementById('addConfigTitle').innerHTML = `<i class="fa-regular fa-calendar me-1"></i> ${monthName} ${year}`;
        document.getElementById('display_add_team').innerText = teamName;
        new bootstrap.Modal(document.getElementById('addConfigModal')).show();
    }
</script>
</body>
</html>
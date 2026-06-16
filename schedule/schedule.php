<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
checkLogin();
$token = isset($_COOKIE["token"]) ? $_COOKIE["token"] : null;
$userlogin = isset($_SESSION["user_data"]) ? $_SESSION["user_data"] : null;
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
if (!$userlogin || !$token) {
    header("Location: /auth/logon.php");
    exit();
}

// --- CONFIGURATION ---
// กำหนดจำนวนวันที่อนุญาตให้กดลาย้อนหลังได้ (สำหรับการลาป่วย) 
$config_retro_leave_days = 7; 

// --- 1. Role Definition & Permissions ---
$user_team = $userlogin['team'] ?? '';

// Permission Check (Secure Logic)
$can_manage_schedule = hasPermission('schedule.management');

// Manage Mode Condition
$allow_manage_mode = $can_manage_schedule;

// --- 2. Parameters, State Retention & Team Scoping ---
$currentMonth = date("n");
$currentYear = date("Y");
$actual_today_date = date("Y-m-d");

// โครงสร้าง Session สำหรับเก็บค่า Filter
if (!isset($_SESSION['sch_filters'])) {
    $_SESSION['sch_filters'] = [
        'month' => $currentMonth,
        'year' => $currentYear,
        'team' => $user_team, 
        'shift' => 'all',     
        'display_type' => 'schedule',
        'emp_filter' => 'all' 
    ];
}

// ตรวจสอบว่ามีการ Submit ฟอร์มเปลี่ยน Filter หรือไม่
$is_filtering = isset($_GET['month']) || isset($_GET['year']) || isset($_GET['team']) || isset($_GET['shift']) || isset($_GET['display_type']) || isset($_GET['emp_filter']);

if ($is_filtering) {
    if (isset($_GET['month'])) $_SESSION['sch_filters']['month'] = intval($_GET['month']);
    if (isset($_GET['year']))  $_SESSION['sch_filters']['year'] = intval($_GET['year']);
    if (isset($_GET['team']))  $_SESSION['sch_filters']['team'] = htmlspecialchars($_GET['team'], ENT_QUOTES, 'UTF-8');
    if (isset($_GET['shift'])) $_SESSION['sch_filters']['shift'] = htmlspecialchars($_GET['shift'], ENT_QUOTES, 'UTF-8');
    if (isset($_GET['display_type'])) $_SESSION['sch_filters']['display_type'] = htmlspecialchars($_GET['display_type'], ENT_QUOTES, 'UTF-8');
    if (isset($_GET['emp_filter'])) $_SESSION['sch_filters']['emp_filter'] = htmlspecialchars($_GET['emp_filter'], ENT_QUOTES, 'UTF-8');
}

// นำค่ามาใช้งาน
$selected_month = $_SESSION['sch_filters']['month'];
$selected_year  = $_SESSION['sch_filters']['year'];
$selected_team  = $_SESSION['sch_filters']['team'];
$selected_shift = $_SESSION['sch_filters']['shift'];
$display_type   = $_SESSION['sch_filters']['display_type'];
$selected_emp   = $_SESSION['sch_filters']['emp_filter']; 

// แปลง selected_emp เป็น Array
$selected_emp_array = ($selected_emp != '' && $selected_emp != 'all') ? explode(',', $selected_emp) : [];

// Retain Manage Mode State
if ($allow_manage_mode) {
    if (isset($_GET['manage_mode'])) {
        $_SESSION['sch_manage_mode'] = ($_GET['manage_mode'] == '1');
    }
    $is_manage_mode = $_SESSION['sch_manage_mode'] ?? false;
} else {
    $is_manage_mode = false;
    $_SESSION['sch_manage_mode'] = false; 
}

$timestamp = mktime(0, 0, 0, $selected_month, 1, $selected_year);
$days_in_month = date('t', $timestamp);
$YearMonth = $selected_year."-".str_pad($selected_month, 2, '0', STR_PAD_LEFT);

// --- 3. API Calls ---
// 3.1 Get Month Config and Map by Team
$monthConfigUrl = "/schedule/month/config?action=get&monthyear=" . $YearMonth;
$monthConfig = callApi($monthConfigUrl);
$team_configs = []; 

if(isset($monthConfig['status']) && $monthConfig['status'] === 'success' && isset($monthConfig['data'])){
    foreach ($monthConfig['data'] as $cfg) {
        $t_name = $cfg['team_config'] ?? '';
        if ($t_name) {
            $team_configs[$t_name] = $cfg;
        }
    }
}

// --- VISIBILITY CONTROL LOGIC ---
$show_schedule_content = true;
$block_reason = "";

if ($selected_team && $selected_team != 'all') {
    $current_team_config = $team_configs[$selected_team] ?? null;
    $has_config = ($current_team_config && !empty($current_team_config['config_id']));
    $is_enabled = ($current_team_config && $current_team_config['is_enabled'] == 1);

    if (!$can_manage_schedule) {
        if (!$has_config) {
            $show_schedule_content = false;
            $block_reason = "Schedule for {$selected_team} has not been initialized yet.";
        } elseif (!$is_enabled) {
            $show_schedule_content = false;
            $block_reason = "Schedule for {$selected_team} is currently in draft mode.";
        }
    }
} 

$schedule_data = [];
if ($show_schedule_content) {
    $scheduleapi_url = "/schedule/monthschedule?action=get&month=" . $YearMonth;
    $result_schedule = callApi($scheduleapi_url);

    if(isset($result_schedule['status']) && $result_schedule['status'] === 'success' && isset($result_schedule['data'])){
        $schedule_data = $result_schedule['data'];
    }
}

$userapi_url = "/data/schedule?year_month=" . $YearMonth;
$result_user = callApi($userapi_url);
$users = [];
if(isset($result_user['status']) && $result_user['status'] === 'success' && isset($result_user['data'])){
    $users = $result_user['data'];
}
$available_teams = [];
foreach ($users as $u) {
    if (!empty($u['team'])) {
        $available_teams[] = $u['team'];
    }
}
$available_teams = array_unique($available_teams);
sort($available_teams); 

// Group Schedules
$grouped_schedule = [];
foreach ($schedule_data as $item) {
    $schedule_date_year = (new DateTime($item['schedule_date']))->format('Y');
    if ($schedule_date_year != $selected_year) continue;
    $date = new DateTime($item['schedule_date']);
    $day = intval($date->format('j')); 
    $user_id = $item['user_id'];
    $grouped_schedule[$user_id][$day][] = $item;
}

// Filter Users
$dropdown_users = [];
$user_list = [];
foreach ($users as $user_item) {
    $user_id = $user_item['user_id'];
    $user_name = ($user_item['thai_initialname'] ?? '') . ($user_item['thai_firstname'] ?? '') . ' ' . ($user_item['thai_lastname'] ?? '');
    $team_name = $user_item['team'] ?? 'N/A';
    $shift_name = $user_item['shift_name'] ?? 'N/A';
    $shift_id = $user_item['shift_id'] ?? 'N/A';
    $employee_id = $user_item['employee_id'] ?? 'N/A';

    if ($selected_team && $selected_team != 'all' && $team_name != $selected_team) continue;
    if ($selected_shift && $selected_shift != 'all' && $shift_name != $selected_shift) continue;
    
    // ** SECURITY CHECK: Team Visibility **
    if (!$can_manage_schedule) {
        $u_cfg = $team_configs[$team_name] ?? null;
        if (!$u_cfg || $u_cfg['is_enabled'] == 0) continue; 
    }

    $dropdown_users[$user_id] = $user_name;

    if (!empty($selected_emp_array) && !in_array($user_id, $selected_emp_array)) continue;

    $user_list[$user_id] = [
        'name' => $user_name,
        'team' => $team_name,
        'shift' => $shift_name,
        'shift_id' => $shift_id,
        'employee_id' => $employee_id,
        'approver_id' => $user_item['approver_id'] ?? null
    ];
}
asort($dropdown_users); 

$api_url = "/schedule/shift?action=get";
$result = callApi($api_url);
$shifts = $result['data'] ?? [];

$holiday_url = "/schedule/holiday?action=get";
$holiday_data = callApi($holiday_url);
$holiday_map = [];
foreach (($holiday_data['data'] ?? []) as $holiday) {
    $holiday_map[$holiday['date']] = $holiday['description'];
}

// Navigation URLs
$prev_month = ($selected_month == 1) ? 12 : $selected_month - 1;
$prev_year = ($selected_month == 1) ? $selected_year - 1 : $selected_year;
$next_month = ($selected_month == 12) ? 1 : $selected_month + 1;
$next_year = ($selected_month == 12) ? $selected_year + 1 : $selected_year;

function getNavUrl($m, $y, $team, $shift, $dtype, $mmode, $emp) {
    $params = [ 
        'month' => $m, 
        'year' => $y, 
        'team' => $team, 
        'shift' => $shift, 
        'display_type' => $dtype, 
        'manage_mode' => $mmode ? '1' : '0',
        'emp_filter' => $emp
    ];
    return "?" . http_build_query($params);
}

$prev_url = getNavUrl($prev_month, $prev_year, $selected_team, $selected_shift, $display_type, $is_manage_mode, $selected_emp);
$next_url = getNavUrl($next_month, $next_year, $selected_team, $selected_shift, $display_type, $is_manage_mode, $selected_emp);
$toggle_mode_params = $_GET;
$toggle_mode_params['manage_mode'] = $is_manage_mode ? '0' : '1';
$toggle_mode_url = "?" . http_build_query($toggle_mode_params);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <link href="/static/font/Sarabun.css" rel="stylesheet" />
    <link rel="stylesheet" href="/static/fontawesome/css/all.css">
    <link rel="stylesheet" href="/static/bootstrap/5.3.1/css/bootstrap.min.css">
    <script src="/static/bootstrap/5.3.1/js/bootstrap.bundle.min.js"></script>
    <script src="/static/jquery/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="/static/datatable/dataTables.bootstrap5.min.css">
    <script src="/static/datatable/jquery.dataTables.min.js"></script>
    <script src="/static/datatable/dataTables.bootstrap5.min.js"></script>
    <title>Schedule</title>

    <style>
        html, body { 
            height: 100%; 
            margin: 0; 
            padding: 0;
            overflow: hidden; 
        }
        body { 
            font-family: "Sarabun", "TH Sarabun New", system-ui, "Segoe UI", Tahoma, sans-serif; 
            font-size: 12px; 
            background-color: rgb(240, 242, 245);  
            display: flex;
            flex-direction: column;
        }
        
        .container { 
            max-width: 100%; 
            height: 100%;
            display: flex;
            flex-direction: column;
            padding-bottom: 5px;
            overflow: hidden;
            padding-top:10px;
        }

        .page-header {
            flex-shrink: 0;
            background-color: rgb(233, 233, 233);
            z-index: 30;
        }

        .table-responsive {
            overflow: auto; 
            border: 1px solid #ccc;
            background-color: white;
            position: relative; 
            -ms-overflow-style: none;  
            scrollbar-width: none;  
        }
        
        .table-responsive::-webkit-scrollbar {
            display: none;
        }
        
        .schedule-table thead th { 
            font-size: 10px; 
            padding: 5px; 
            width: 30px;
            position: sticky;
            top: 0; 
            z-index: 10;
            background-color:rgb(243, 243, 243);
            box-shadow: inset 0 0 0 1px #c2c2c2;
            text-align: center;
            vertical-align: middle; 
            color: #000;
        }

        .schedule-table thead th:first-child { 
            position: sticky; 
            left: 0; 
            top: 0;
            z-index: 20; 
        }
        
        <?php if ($is_manage_mode): ?>
        .schedule-table thead tr:first-child td {
            position: sticky;
            top: 0;
            z-index: 30; 
            background-color: rgb(255, 241, 204) !important;
            box-shadow: inset 0 0 0 1px #e0c46c; 
        }
        .schedule-table thead tr:nth-child(2) th {
            top: 20px; 
        }
        <?php endif; ?>

        .schedule-table tbody td:first-child {
            position: sticky;
            left: 0;
            z-index: 5;
            background-color: #fff;
            border-right: 1px solid #dee2e6;
        }
        
        .schedule-table tbody td.table-success:first-child {
            background-color: #d1e7dd;
        }

        .is-today-header {
            background-color: #e7f1ff !important;
            color: #0d6efd !important;
            font-size: 11px !important;
            box-shadow: inset 0 4px 0 #0d6efd, inset 0 0 0 1px #acacac !important;
            z-index: 15 !important;
        }
        
        .is-today-header small {
            font-weight: bold;
        }
        
        .is-today-col {
            background-color: #f4f9ff !important; 
            box-shadow: inset 1px 0 0 #a3c7fa, inset -1px 0 0 #a3c7fa !important;
        }

        .schedule-table td { 
            font-size: 11px; 
            width: 30px; 
            transition: all 0.2s; 
            position: relative;
            vertical-align: top !important; 
            text-align: center;
            padding: 2px !important; 
            height: 100%; 
        }
        
        .schedule-table .user-info { text-align: left; font-weight: 500; white-space: nowrap; vertical-align: middle !important;}
        
        .schedule-block {
            display: block;
            margin-bottom: 2px;
            padding: 2px 1px;
            border-radius: 3px;
            font-size: 10px;
            line-height: 1.1;
            font-weight: bold;
            color: #333;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            text-align: center;
        }
        .schedule-block:hover { opacity: 0.9; transform: scale(1.02); }

        .add-schedule-btn {
            background-color: #f8f9fa;
            border: 1px dashed #adb5bd;
            color: #6c757d;
            text-align: center;
            font-size: 10px; 
            padding: 0;      
            line-height: 14px;
            cursor: pointer;
            display: block;
            border-radius: 3px;
            transition: all 0.2s;
            margin-bottom: 3px; 
        }
        .add-schedule-btn:hover {
            background-color: #e9ecef;
            border-color: #198754;
            color: #198754;
            font-weight: bold;
        }

        .modal-auto { width: auto; max-width: none; display: flex; justify-content: center; }
        .modal-auto .modal-content { width: fit-content; }
        .modal-buttons-container { display: flex; flex-direction: row; justify-content: center; flex-wrap: wrap; gap: 10px;}
        .modal-buttons-container .action-btn { width: 140px; padding: 12px 5px; height: auto !important; margin: 0; }

        .nav-controls {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .nav-controls select, .nav-controls .btn-sm {
            font-size: 0.8rem;
        }
        .nav-controls select {
            border-color: #198754;
            font-weight: bold;
            padding-top: 0.2rem;
            padding-bottom: 0.2rem;
            box-shadow: none; 
        }
        .nav-controls label {
            font-size: 0.75rem; 
            font-weight: 600;
            color: #666;
            margin-right: 0.2rem;
        }
        .nav-controls .btn-nav {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #198754;
            color: #198754;
            background-color: white;
            transition: all 0.2s;
        }
        .nav-controls .btn-nav:hover {
            background-color: #198754;
            color: white;
        }
        
        .header-bar { background: #fff; border-bottom: 1px solid #ddd; padding: 15px 25px; position: sticky; top: 0; z-index: 1050; }
        .header-title { font-weight: bold; font-size: 20px; color: #1e8540; }
        
        .emp-chk-container { max-height: 250px; overflow-y: auto; margin-bottom: 8px; }
        .emp-chk-item, .form-check-label { cursor: pointer; }
    </style>
</head>

<body class="p-0">
    
        <div class="page-header">
            <div class="header-bar d-flex justify-content-between align-items-center">
                <span class="header-title d-none d-xl-block">
                    <i class="fa-solid fa-calendar"></i> Schedule
                </span>
                
                <form id="mainFilterForm" class="d-flex align-items-center flex-wrap gap-2 m-0 nav-controls" method="GET" action="">
                    
                    <input type="hidden" name="emp_filter" id="hidden_emp_filter" value="<?= htmlspecialchars($selected_emp) ?>">

                    <div class="d-flex align-items-center gap-1">
                        <a href="<?= $prev_url ?>" class="btn btn-sm btn-nav"><i class="fa-solid fa-chevron-left" style="font-size:10px;"></i></a>
                        <select id="month" name="month" class="form-select form-select-sm shadow-none" style="width:auto; min-width: 80px;" onchange="this.form.submit()">
                            <?php 
                            $months = [1 => "Jan", 2 => "Feb", 3 => "Mar", 4 => "Apr", 5 => "May", 6 => "Jun", 7 => "Jul", 8 => "Aug", 9 => "Sep", 10 => "Oct", 11 => "Nov", 12 => "Dec"];
                            foreach ($months as $num => $name) {
                                $selected = ($num == $selected_month) ? "selected" : "";
                                echo "<option value='$num' $selected>$name</option>";
                            } 
                            ?>
                        </select>
                        <select id="year" name="year" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                            <?php for ($i = $currentYear - 1; $i <= $currentYear + 1; $i++) {
                                $selected = ($i == $selected_year) ? "selected" : "";
                                echo "<option value='$i' $selected>$i</option>";
                            } ?>
                        </select>
                        <a href="<?= $next_url ?>" class="btn btn-sm btn-nav"><i class="fa-solid fa-chevron-right" style="font-size:10px;"></i></a>
                    </div>

                    <div class="vr mx-1 d-none d-md-block" style="height: 20px; align-self: center;"></div>

                    <div class="d-flex align-items-center">
                        <label class="d-none d-lg-block mb-0 me-1">Team:</label>
                        <select id="team" name="team" class="form-select form-select-sm" style="width:auto;" onchange="document.getElementById('hidden_emp_filter').value='all'; this.form.submit()">
                            <option value="all" <?= ($selected_team == 'all' || $selected_team == '') ? 'selected' : ''; ?>>All Teams</option>
                            <?php foreach ($available_teams as $t_name): ?>
                                <option value="<?= htmlspecialchars($t_name) ?>" <?= ($selected_team == $t_name) ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($t_name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="d-flex align-items-center">
                        <label for="shift" class="d-none d-lg-block mb-0">Shift:</label>
                        <select id="shift" name="shift" class="form-select form-select-sm" style="width:auto;" onchange="document.getElementById('hidden_emp_filter').value='all'; this.form.submit()">
                            <option value="all" <?= ($selected_shift == 'all' || $selected_shift == '') ? 'selected' : ''; ?>>All Shifts</option>
                            <?php foreach ($shifts as $s): ?>
                                <option value="<?= htmlspecialchars($s['shift_name']); ?>" <?= ($selected_shift == $s['shift_name']) ? 'selected' : ''; ?>><?= htmlspecialchars($s['shift_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="d-flex align-items-center">
                        <label class="d-none d-lg-block mb-0 me-1">Emp:</label>
                        <div class="dropdown">
                          <button class="btn btn-sm btn-outline-secondary dropdown-toggle text-start form-select-sm" 
                                  type="button" 
                                  data-bs-toggle="dropdown" 
                                  data-bs-auto-close="outside" 
                                  aria-expanded="false" 
                                  style="font-size: 0.8rem; min-width: 120px; max-width: 150px; background: white; border: 1px solid #198754; color: #333; overflow: hidden; text-overflow: ellipsis;">
                            <?= (empty($selected_emp_array) || $selected_emp == 'all') ? '- All Employees -' : count($selected_emp_array) . ' Selected' ?>
                          </button>
                          <div class="dropdown-menu shadow p-2" style="font-size: 12px; min-width: 220px;">
                            <div class="emp-chk-container">
                                <div class="form-check mb-1">
                                  <input class="form-check-input" type="checkbox" id="emp_all" <?= (empty($selected_emp_array) || $selected_emp == 'all') ? 'checked' : '' ?> onclick="toggleEmpAll()">
                                  <label class="form-check-label" for="emp_all"><strong>- All Employees -</strong></label>
                                </div>
                                <hr class="my-1">
                                <?php foreach ($dropdown_users as $uid => $uname): ?>
                                <div class="form-check mb-1">
                                  <input class="form-check-input emp-chk-item" type="checkbox" value="<?= htmlspecialchars($uid) ?>" id="emp_<?= htmlspecialchars($uid) ?>" <?= in_array($uid, $selected_emp_array) ? 'checked' : '' ?> onclick="uncheckEmpAll()">
                                  <label class="form-check-label text-truncate" style="max-width: 170px;" for="emp_<?= htmlspecialchars($uid) ?>" title="<?= htmlspecialchars($uname) ?>">
                                      <?= htmlspecialchars($uname) ?>
                                  </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-sm btn-primary w-100 mt-1" onclick="applyEmpFilter()">Apply Filter</button>
                          </div>
                        </div>
                    </div>

                    <div class="d-flex align-items-center">
                        <label for="display_type" class="d-none d-lg-block mb-0">View:</label>
                        <select id="display_type" name="display_type" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                            <option value="schedule" <?= ($display_type == 'schedule') ? 'selected' : ''; ?>>Work assign</option>
                            <option value="group" <?= ($display_type == 'group') ? 'selected' : ''; ?>>Work group</option>
                        </select>
                    </div>
                    
                    <input type="hidden" name="manage_mode" value="<?= $is_manage_mode ? '1' : '0' ?>">

                    <?php if ($show_schedule_content): ?>
                    <a href="export_preview.php" 
                       class="btn btn-sm btn-outline-success" 
                       title="Preview & Export"
                       style="width: 100px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 6px; border: 1.5px solid #157347; text-decoration: none;">
                       <i class="fa-solid fa-download me-2"></i> Download
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($show_schedule_content && $can_manage_schedule): ?>
                    <a href="export_workdi.php" 
                       class="btn btn-sm btn-outline-success" 
                       title="Preview & Export WorkDi"
                       style="width: 150px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 6px; border: 1.5px solid #157347; text-decoration: none;">
                       <img src="/static/img/workdi.svg" alt="logo">&nbsp;&nbsp;Export WorkDi
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($allow_manage_mode): ?>
                        <a href="<?= $toggle_mode_url ?>" 
                           class="btn btn-sm <?= $is_manage_mode ? 'btn-warning text-dark' : 'btn-outline-secondary' ?>" 
                           title="<?= $is_manage_mode ? 'Exit Manage Mode' : 'Enter Manage Mode' ?>"
                           style="width: 70px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 6px; border: 1.5px solid <?= $is_manage_mode ? '#ffc107' : '#6c757d' ?>;">
                           <i class="fa-solid <?= $is_manage_mode ? 'fa-pen-to-square me-2' : 'fa-calendar-plus me-2' ?>" style="color: #0112f9;"></i><?= $is_manage_mode ? 'Exit' : 'Edit' ?>
                        </a>
                    <?php endif; ?>
                </form>
            </div> 
        </div>
    <div class="container">
        <?php if ($show_schedule_content): ?>
        <div class="table-responsive">
            <table class="table table-bordered table-sm schedule-table table-hover mb-0 py-0">
                <thead>
                    <?php if ($is_manage_mode): ?>
                        <tr><td class="manage-mode-cell" colspan="<?= 3 + $days_in_month ?>" style="text-align:center; background-color:rgb(255, 241, 204); color: #856404; font-weight:bold; padding:5px;">
                            <i class="fa-solid fa-file-pen"></i> Manage Mode 
                        </td></tr>
                    <?php endif; ?>
                    <tr>
                        <th style="width:30px; vertical-align: middle;">No.</th>
                        <th style="text-align: left; vertical-align: middle; width: 140px; padding-left: 10px;">Name</th>
                        <th style="text-align: left; vertical-align: middle;">Emp ID</th>
                        
                        <?php 
                        for ($d = 1; $d <= $days_in_month; $d++): 
                            $date_str = $selected_year . '-' . str_pad($selected_month, 2, '0', STR_PAD_LEFT) . '-' . str_pad($d, 2, '0', STR_PAD_LEFT);
                            $day_of_week = date('D', strtotime($date_str));
                            $is_weekend = ($day_of_week == 'Sat' || $day_of_week == 'Sun');
                            $is_holiday = isset($holiday_map[$date_str]);
                            $header_style = ($is_weekend || $is_holiday) ? 'background-color:rgb(247, 151, 151);' : '';
                            
                            $is_today_flag = ($date_str === $actual_today_date);
                            $today_header_class = $is_today_flag ? 'is-today-header' : '';
                        ?>
                            <th class="<?= $today_header_class ?>" style="vertical-align: top; <?php echo $header_style; ?>" title="<?php echo $is_holiday ? htmlspecialchars($holiday_map[$date_str]) : $day_of_week; ?>">
                                <?php echo $d; ?><br><small><?php echo $day_of_week; ?></small> 
                            </th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                <?php
                $users_by_shift = [];
                foreach ($user_list as $user_id => $user) { 
                    $shift_id = $user['shift_id'] ?? 'N/A'; 
                    $users_by_shift[$shift_id][$user_id] = $user; 
                }
                
                $ordered_shifts = [];
                foreach ($shifts as $shift) {
                    $ordered_shifts[$shift['shift_id']] = $shift['shift_name'];
                }
                foreach (array_keys($users_by_shift) as $sid) { 
                    if (!isset($ordered_shifts[$sid])) $ordered_shifts[$sid] = 'Unknown'; 
                }
                ksort($ordered_shifts);

                foreach ($ordered_shifts as $shift_id => $shift_name):
                    if (!isset($users_by_shift[$shift_id]) || empty($users_by_shift[$shift_id])) continue;
                    
                    $shift_users = $users_by_shift[$shift_id];
                    uasort($shift_users, function($a, $b) {
                        return strcmp($a['employee_id'], $b['employee_id']);
                    });
                ?>
                    <tr><td colspan="<?= 3 + $days_in_month ?>" style="background-color:rgb(231, 231, 231);"></td></tr>
                    
                    <?php $no = 1; foreach ($shift_users as $user_id => $user): ?>
                        <?php 
                            $match = ($user['employee_id'] == $userlogin['emp_id']); 
                            $hl = $match ? 'fw-bold text-dark table-success' : 'background-color:rgba(223, 223, 223, 0.32);'; 
                            $user_team_name = $user['team'];
                            
                            $can_modify_row = false;
                            if ($is_manage_mode) {
                                $can_modify_row = true;
                            }
                            
                            $team_cfg = $team_configs[$user_team_name] ?? null;
                            $is_team_locked = ($team_cfg && $team_cfg['is_locked'] == 1);
                        ?>
                        <tr>
                            <td class="<?= $hl ?>" style="vertical-align: middle !important;"><?= $no++; ?></td>
                            <td style="width:140px;" class="user-info <?= $hl ?>">&nbsp;&nbsp;<?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['team']) ?>)</td>
                            <td style="width:60px;" class="user-info <?= $hl ?>">&nbsp;&nbsp;<?= htmlspecialchars($user['employee_id']) ?></td>
                            
                            <?php for ($d = 1; $d <= $days_in_month; $d++):
                                $daily_schedules = $grouped_schedule[$user_id][$d] ?? [];
                                
                                usort($daily_schedules, function($a, $b) {
                                    return (int)($a['sequence'] ?? 9999) <=> (int)($b['sequence'] ?? 9999);
                                });

                                $date_full = $selected_year . '-' . str_pad($selected_month, 2, '0', STR_PAD_LEFT) . '-' . str_pad($d, 2, '0', STR_PAD_LEFT);
                                $day_of_week = date('D', strtotime($date_full));
                                $is_weekend = ($day_of_week == 'Sat' || $day_of_week == 'Sun');
                                $is_holiday = isset($holiday_map[$date_full]);
                                
                                $cell_bg = ($is_weekend || $is_holiday) ? 'background-color:rgba(223, 223, 223, 0.32);' : 'background-color:rgba(223, 223, 223, 0.32);';
                                
                                $is_today_flag = ($date_full === $actual_today_date);
                                $today_cell_class = $is_today_flag ? 'is-today-col' : '';

                                $cell_onclick = "";
                                $can_add = false;
                                if ($is_manage_mode && $can_modify_row) { 
                                    $can_add = true;
                                    $cell_onclick = "handleScheduleClick(this)";
                                } else {
                                    if ($is_team_locked) $can_add = false;
                                }
                            ?> 
                                <td style="<?= $cell_bg ?>" 
                                    class="schedule-cell <?= $today_cell_class ?>"
                                    <?php if($can_add): ?>
                                        onclick="<?= $cell_onclick ?>"
                                        data-user-id="<?= htmlspecialchars($user_id) ?>" 
                                        data-date="<?= htmlspecialchars($date_full) ?>"
                                        data-schedule-id="new"
                                        data-user-name="<?= htmlspecialchars($user['name']) ?>"
                                        title="Click to Add Schedule"
                                    <?php endif; ?>
                                >
                                    <?php 
                                    if($is_manage_mode && $can_modify_row): 
                                    ?>
                                        <div class="schedule-block add-schedule-btn" 
                                             onclick="handleScheduleClick(this); event.stopPropagation();"
                                             data-user-id="<?= htmlspecialchars($user_id) ?>" 
                                             data-date="<?= htmlspecialchars($date_full) ?>"
                                             data-schedule-id="new"
                                             data-user-name="<?= htmlspecialchars($user['name']) ?>"
                                             title="Add Schedule">
                                             <i class="fa-solid fa-plus"></i>
                                        </div>
                                    <?php endif; ?>

                                    <?php 
                                    foreach ($daily_schedules as $schedule_info): 
                                        $shift_type = htmlspecialchars($schedule_info['type_name'] ?? 'N/A');
                                        
                                        $raw_group = $schedule_info['work_group'] ?? null;
                                        $group_name = ($raw_group === null || trim($raw_group) === '') ? '❓' : htmlspecialchars($raw_group);
                                        
                                        $priority = (int)($schedule_info['priority'] ?? 0);
                                        $remark = htmlspecialchars($schedule_info['remark'] ?? '');
                                        $status = htmlspecialchars($schedule_info['status'] ?? ''); 
                                        $color_value = htmlspecialchars($schedule_info['color'] ?? '#a7a7a7');
                                        
                                        $block_content = ($display_type == 'group') ? $group_name : $shift_type;
                                        $block_style = "border: 1px solid #e2e1e1; background-color: {$color_value}; color: ".($color_value == '#34495e' ? '#b8b8b8' : '#333').";";
                                        
                                        if ($priority >= 1 && $status === 'Requested') {
                                            $block_style = "background-color: #f8d7da; color: #721c24; border-bottom: 2px solid #dc3545;";
                                        } elseif ($status === 'Pending') {
                                            $block_content = '[P]';
                                            $block_style = "background-color: #cce5ff; color: #004085;";
                                        } elseif ($status === 'Standby') {
                                            $block_content = '[S]';
                                            $block_style = "background-color: #cce5ff; color: #004085;";
                                        } elseif ($status === 'Accept') {
                                            $block_content = '[A]';
                                            $block_style = "background-color: #cce5ff; color: #004085;";
                                        } elseif ($status === 'OT') {
                                            $block_content = 'O'.($display_type == 'group' ? $group_name : $shift_type);
                                            $block_style = "background-color:rgb(247, 255, 204); color:rgb(0, 0, 0); border: 1px solid #ccc;";
                                        } elseif ($status === 'OT15') {
                                            $block_content = 'O'.($display_type == 'group' ? $group_name : $shift_type);
                                            $block_style = "background-color:rgb(236, 247, 89); color:rgb(0, 0, 0); border: 1px solid #ccc;";
                                        } elseif ($status === 'OT3') {
                                            $block_content = 'O'.($display_type == 'group' ? $group_name : $shift_type);
                                            $block_style = "background-color:rgb(238, 241, 12); color:rgb(0, 0, 0); border: 1px solid #ccc;";
                                        } elseif ($status === 'Requested') {
                                              // Skip requested if not priority 1?
                                        } elseif ($status === 'Note-Swap') {
                                            $block_content = ($display_type == 'group') ? $group_name : $shift_type;
                                            $block_style = "border: 1.5px solid rgb(4, 124, 44) !important; background-color: {$color_value}; color: ".($color_value == '#34495e' ? '#b8b8b8' : '#333')."; opacity: 0.9;";
                                        } elseif ($status === 'Normal-Locked') {
                                            $block_content = ($display_type == 'group') ? $group_name : $shift_type;
                                            $block_style = "border: 1.5px dashed #dc3545 !important; background-color: {$color_value}; color: ".($color_value == '#34495e' ? '#b8b8b8' : '#333')."; opacity: 0.45;";
                                        }
                                        
                                        $tooltip = "Schedule : {$shift_type}\nGroup : {$group_name}";
                                        if ($status != '') $tooltip .= "\nStatus : ".$status;
                                        if ($remark != '') $tooltip .= "\n".$remark;

                                        $item_clickable = false;
                                        if ($is_manage_mode) {
                                            $item_clickable = ($can_modify_row && $status !== 'Accept');
                                        } else {
                                            if ($match) {
                                                if ($priority < 1) {
                                                    $item_clickable = false;
                                                } else {
                                                    $past_leave_limit = date('Y-m-d', strtotime("-{$config_retro_leave_days} days"));
                                                    if (($status == 'Normal' && $date_full >= $past_leave_limit) || 
                                                        $status == 'Pending' || $status == 'Standby') {
                                                        $item_clickable = true;
                                                    }
                                                }
                                                
                                                if ($is_team_locked) $item_clickable = false;
                                            }
                                        }
                                        
                                        if ($status === 'Accept') $item_clickable = false;

                                    ?>
                                        <div class="schedule-block"
                                             style="<?= $block_style ?> <?= $item_clickable ? 'cursor:pointer;' : 'cursor:default;' ?>"
                                             title="<?= $tooltip ?>"
                                             data-user-name="<?= htmlspecialchars($user['name']) ?>"
                                             data-work-group="<?= htmlspecialchars($group_name) ?>"
                                             data-status="<?= $status ?>"
                                             data-priority="<?= $priority ?>"
                                             data-date="<?= htmlspecialchars($date_full) ?>"
                                             <?php if($item_clickable): ?>
                                                 onclick="handleScheduleClick(this); event.stopPropagation();"
                                                 data-user-id="<?= htmlspecialchars($user_id) ?>" 
                                                 data-date="<?= htmlspecialchars($date_full) ?>"
                                                 data-schedule-id="<?= htmlspecialchars($schedule_info['schedule_id']) ?>"
                                                 data-is-scheduled="true"
                                             <?php endif; ?>
                                        >
                                            <?= $block_content ?>
                                        </div>
                                    <?php endforeach; ?>
                                </td>
                            <?php endfor; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="d-flex flex-column align-items-center justify-content-center h-100 text-muted">
                <i class="fa-regular fa-calendar-xmark fa-3x mb-3 text-secondary"></i>
                <h5 class="text-secondary"><?= htmlspecialchars($block_reason) ?></h5>
                <p class="small text-muted">Please check back later or contact your administrator.</p>
                <?php if ($can_manage_schedule): ?>
                    <div class="mt-2 text-center">
                        <span class="badge bg-warning text-dark mb-2">Manager Access</span>
                        <small class="d-block text-muted">You have permission to manage the schedule.</small>
                        <a href="/schedule/setting.php?year=<?= $selected_year ?>" class="btn btn-sm btn-primary mt-2"><i class="fa-solid fa-cog"></i> Go to Settings</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal -->
    <div class="modal fade" id="RequestModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-auto">
            <div class="modal-content text-center">
                <div class="modal-header position-relative"> 
                    <h6 class="modal-title mx-auto">Actions</h6>
                    <button type="button" class="btn-close position-absolute end-0 me-3" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-3">
                    <div id="modalScheduleInfo" class="alert alert-light text-start small mb-3 border"></div>
                    <div class="modal-buttons-container">
                        
                        <!-- 1. ปุ่ม Swap ปกติ -->
                        <button id="btnSwap" onclick="submitRequest('swap')" type="button" class="btn btn-info action-btn"><i class="fa-solid fa-retweet"></i><br><b>Swap</b></button>
                        <span id="btnSwapDisabled" class="btn btn-secondary action-btn" style="display:none; cursor:not-allowed; opacity:0.65;"><i class="fa-solid fa-retweet"></i><br><b>Swap</b></span>

                        <!-- 2. ปุ่ม Note Swap -->
                        <button id="btnNoteSwap" onclick="submitRequest('note_swap')" type="button" class="btn btn-primary action-btn" style="background-color: #6f42c1; border-color: #6f42c1;"><i class="fa-solid fa-handshake"></i><br><b>Note Swap</b></button>
                        <span id="btnNoteSwapDisabled" class="btn btn-secondary action-btn" style="display:none; cursor:not-allowed; opacity:0.65;"><i class="fa-solid fa-handshake"></i><br><b>Note Swap</b></span>

                        <!-- 3. ปุ่ม Leave ปกติ (ลาเต็มกะ) -->
                        <button id="btnLeave" onclick="submitRequest('leave')" type="button" class="btn btn-warning action-btn"><i class="fa-regular fa-circle-up"></i><br><b>Full Leave</b></button>
                        
                        <!-- 4. ปุ่ม Half Leave ใหม่ (ลาครึ่งกะ) -->
                        <button id="btnHalfLeave" onclick="submitRequest('half_leave')" type="button" class="btn btn-outline-warning action-btn"><i class="fa-solid fa-circle-half-stroke"></i><br><b>Half Leave</b></button>
                        
                        <?php if ($can_manage_schedule): ?>
                            <!-- แสดงปุ่มเพิ่มและแก้ไขเฉพาะคนที่มีสิทธิ์ -->
                            <button id="btnAdd" onclick="submitRequest('add')" type="button" class="btn btn-primary action-btn"><i class="fa-solid fa-calendar-plus"></i><br><b>Add</b></button>
                            <button id="btnEdit" onclick="submitRequest('edit')" type="button" class="btn btn-success action-btn"><i class="fa-solid fa-pen"></i><br><b>Edit</b></button>
                        <?php endif; ?>

                        <!-- New Buttons for Pending/Standby Actions -->
                        <button id="btnCancel" onclick="submitRequest('cancel')" type="button" class="btn btn-secondary action-btn"><i class="fa-solid fa-ban"></i><br><b>Cancel Request</b></button>
                        <button id="btnConfirm" onclick="submitRequest('confirm')" type="button" class="btn btn-success action-btn"><i class="fa-regular fa-calendar-check"></i><br><b>Confirm</b></button>
                        <button id="btnReject" onclick="submitRequest('reject')" type="button" class="btn btn-danger action-btn"><i class="fa-regular fa-calendar-xmark"></i><br><b>Reject</b></button>
                        
                        <?php if ($can_manage_schedule): ?>
                            <!-- แสดงปุ่มลบเฉพาะคนที่มีสิทธิ์ และย้ายมาไว้หลังสุด -->
                            <button id="btnDelete" onclick="submitRequest('delete')" type="button" class="btn btn-danger action-btn"><i class="fa-solid fa-trash-can"></i><br><b>Delete</b></button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    const loggedInUserId = '<?= (int)$userlogin['user_id'] ?>';
    const isManageModeActive = <?= $is_manage_mode ? 'true' : 'false' ?>;
    const retroLeaveDays = <?= (int)$config_retro_leave_days ?>;

    function escapeHtml(text) {
        if (!text) return text;
        return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    function toggleEmpAll() {
        let isAllChecked = document.getElementById('emp_all').checked;
        let checkboxes = document.querySelectorAll('.emp-chk-item');
        if (isAllChecked) {
            checkboxes.forEach(cb => cb.checked = false);
        }
    }

    function uncheckEmpAll() {
        document.getElementById('emp_all').checked = false;
    }

    function applyEmpFilter() {
        if (document.getElementById('emp_all').checked) {
            document.getElementById('hidden_emp_filter').value = 'all'; 
        } else {
            let selected = [];
            document.querySelectorAll('.emp-chk-item:checked').forEach(cb => {
                selected.push(cb.value);
            });
            document.getElementById('hidden_emp_filter').value = selected.length > 0 ? selected.join(',') : 'all';
        }
        document.getElementById('mainFilterForm').submit();
    }

    function handleScheduleClick(cell) {
        const userId = cell.getAttribute('data-user-id');
        const scheduleId = cell.getAttribute('data-schedule-id');
        const dateStr = cell.getAttribute('data-date');
        
        if (scheduleId === 'new') {
             window.selectedCellData = { userId, dateStr, scheduleId };
             const userName = cell.getAttribute('data-user-name') || '-';
             
             const infoHtml = `<ul class="list-unstyled mb-0 ps-2" style="font-size: 0.9rem;"><li class="mb-1"><strong>ID:</strong> New Schedule</li><li class="mb-1"><strong>Name:</strong> ${escapeHtml(userName)}</li><li class="mb-1"><strong>Date:</strong> ${escapeHtml(dateStr)}</li><li><strong>Status:</strong> <span class="badge bg-success">New</span></li></ul>`;
             const infoContainer = document.getElementById('modalScheduleInfo');
             if (infoContainer) infoContainer.innerHTML = infoHtml;
             
             const btns = {
                 swap: document.getElementById('btnSwap'), swapDisabled: document.getElementById('btnSwapDisabled'),
                 noteSwap: document.getElementById('btnNoteSwap'), noteSwapDisabled: document.getElementById('btnNoteSwapDisabled'),
                 leave: document.getElementById('btnLeave'), halfLeave: document.getElementById('btnHalfLeave'),
                 add: document.getElementById('btnAdd'), edit: document.getElementById('btnEdit'), del: document.getElementById('btnDelete'),
                 cancel: document.getElementById('btnCancel'), confirm: document.getElementById('btnConfirm'), reject: document.getElementById('btnReject')
             };
             Object.values(btns).forEach(b => { if(b){ b.style.display = 'none'; if(b.tagName === 'BUTTON') b.disabled = true; } });
             
             if (btns.add) { btns.add.style.display = 'block'; btns.add.disabled = false; }
             
             new bootstrap.Modal(document.getElementById('RequestModal')).show();
             return;
        }

        const isScheduled = cell.getAttribute('data-is-scheduled') === 'true';
        const status = cell.getAttribute('data-status') || 'New';
        const userName = cell.getAttribute('data-user-name') || '-';
        const workGroup = cell.getAttribute('data-work-group') || '-';
        
        const infoHtml = `<ul class="list-unstyled mb-0 ps-2" style="font-size: 0.9rem;"><li class="mb-1"><strong>ID:</strong> ${escapeHtml(scheduleId)}</li><li class="mb-1"><strong>Name:</strong> ${escapeHtml(userName)}</li><li class="mb-1"><strong>Group:</strong> ${escapeHtml(workGroup)}</li><li class="mb-1"><strong>Date:</strong> ${escapeHtml(dateStr)}</li><li><strong>Status:</strong> <span class="badge bg-secondary">${escapeHtml(status)}</span></li></ul>`;
        const infoContainer = document.getElementById('modalScheduleInfo');
        if (infoContainer) infoContainer.innerHTML = infoHtml;

        const today = new Date(); today.setHours(0,0,0,0);
        const scheduleDate = new Date(dateStr); scheduleDate.setHours(0,0,0,0);
        const isOwnShift = userId == loggedInUserId;

        const pastLeaveLimit = new Date(today);
        pastLeaveLimit.setDate(today.getDate() - retroLeaveDays);

        const btns = {
            swap: document.getElementById('btnSwap'),
            swapDisabled: document.getElementById('btnSwapDisabled'),
            noteSwap: document.getElementById('btnNoteSwap'),
            noteSwapDisabled: document.getElementById('btnNoteSwapDisabled'),
            leave: document.getElementById('btnLeave'),
            halfLeave: document.getElementById('btnHalfLeave'),
            add: document.getElementById('btnAdd'),
            edit: document.getElementById('btnEdit'),
            del: document.getElementById('btnDelete'),
            cancel: document.getElementById('btnCancel'),
            confirm: document.getElementById('btnConfirm'),
            reject: document.getElementById('btnReject')
        };

        Object.values(btns).forEach(b => { 
            if (b) { b.style.display = 'none'; if (b.tagName === 'BUTTON') b.disabled = true; } 
        });

        if (isManageModeActive) {
            // Manage Mode Logic
            if (btns.del) { btns.del.style.display = 'block'; btns.del.disabled = false; }

            if (status === 'Pending') {
                 btns.cancel.style.display = 'block'; btns.cancel.disabled = false;
            } else if (status === 'Standby') {
                 btns.confirm.style.display = 'block'; btns.confirm.disabled = false;
                 btns.reject.style.display = 'block'; btns.reject.disabled = false;
            } else if (status === 'Accept') {
                 // Accept -> Delete only
            } else { 
                 if (btns.edit) { btns.edit.style.display = 'block'; btns.edit.disabled = false; }
                 if (status == 'OT') {
                    btns.swap.style.display = 'block'; btns.swap.disabled = false;
                    btns.noteSwap.style.display = 'block'; btns.noteSwap.disabled = false;
                 }
                 if (status !== 'OT' && status !== 'Requested' && status !== 'Note-Swap' && status !== 'Normal-Locked') {
                    btns.swap.style.display = 'block'; btns.swap.disabled = false;
                    btns.noteSwap.style.display = 'block'; btns.noteSwap.disabled = false;
                    btns.leave.style.display = 'block'; btns.leave.disabled = false;
                    btns.halfLeave.style.display = 'block'; btns.halfLeave.disabled = false;
                 }
            }
        } else {
            // User Mode Logic
            if (isScheduled && isOwnShift) {
                if (status == 'Normal') {
                    const priority = cell.getAttribute('data-priority'); 

                    if (scheduleDate >= today) {
                        if (priority == 0) {
                            if (btns.swapDisabled) btns.swapDisabled.style.display = 'block';
                            if (btns.noteSwapDisabled) btns.noteSwapDisabled.style.display = 'block';
                        } else {
                            btns.swap.style.display = 'block'; btns.swap.disabled = false;
                            btns.noteSwap.style.display = 'block'; btns.noteSwap.disabled = false;
                        }
                        btns.leave.style.display = 'block'; btns.leave.disabled = false;
                        btns.halfLeave.style.display = 'block'; btns.halfLeave.disabled = false;
                    } else if (scheduleDate >= pastLeaveLimit) {
                        if (btns.swapDisabled) btns.swapDisabled.style.display = 'block';
                        if (btns.noteSwapDisabled) btns.noteSwapDisabled.style.display = 'block';
                        btns.leave.style.display = 'block'; btns.leave.disabled = false;
                        btns.halfLeave.style.display = 'block'; btns.halfLeave.disabled = false;
                    }
                } else if (status === 'Pending') {
                    btns.cancel.style.display = 'block'; btns.cancel.disabled = false;
                } else if (status === 'Standby') {
                    btns.confirm.style.display = 'block'; btns.confirm.disabled = false;
                }
            }
        }
        window.selectedCellData = { userId, dateStr, scheduleId };
        new bootstrap.Modal(document.getElementById('RequestModal')).show();
    }
    
    function submitRequest(req) {
        const { userId, dateStr, scheduleId } = window.selectedCellData;
        document.getElementById('user_id').value = userId;
        document.getElementById('date').value = dateStr;
        document.getElementById('schedule_id').value = scheduleId;
        document.getElementById('req').value = req;
        document.getElementById('form_manage_mode').value = isManageModeActive ? '1' : '0';
        const form = document.getElementById('requestForm');
        
        if (req === 'swap') form.action = 'request_swap.php';
        else if (req === 'note_swap') form.action = 'request_note_swap.php'; 
        else if (req === 'leave') form.action = 'request_leave.php'; 
        else if (req === 'half_leave') form.action = 'request_half_leave.php'; 
        else if (req === 'add' || req === 'edit') form.action = 'request_edit.php';
        else if (req === 'delete' || req === 'cancel' || req === 'confirm' || req === 'reject') {
            if (req === 'delete' && !confirm('Are you sure you want to delete this schedule?')) return;
            if (req === 'cancel' && !confirm('Are you sure you want to cancel this request?')) return;
            form.action = 'request_process.php'; 
        }
        else return;
        
        form.submit();
    }
    </script>

    <form id="requestForm" method="POST" style="display:none;">
        <input type="hidden" name="user_id" id="user_id">
        <input type="hidden" name="date" id="date">
        <input type="hidden" name="schedule_id" id="schedule_id">
        <input type="hidden" name="req" id="req">
        <input type="hidden" name="manage_mode" id="form_manage_mode">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    </form>
</body>
</html>
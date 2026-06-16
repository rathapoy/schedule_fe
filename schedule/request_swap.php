<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
checkLogin();
$token = isset($_COOKIE["token"]) ? $_COOKIE["token"] : null;
$userlogin = isset($_SESSION["user_data"]) ? $_SESSION["user_data"] : null;

// =========================================================================
// --- ตั้งค่าเงื่อนไขการตรวจสอบ (Configurations) ---
// =========================================================================
$CONF_CHECK_CONSECUTIVE = true;      // เปิด/ปิด เช็คการทำงานต่อเนื่อง
$CONF_MAX_CONSECUTIVE_DAYS = 6;      // จำนวนวันทำงานต่อเนื่องสูงสุดที่อนุญาต

$CONF_CHECK_OVERLAP = true;          // เปิด/ปิด เช็คเวลาทับซ้อน (กะชนกัน)

$CONF_CHECK_MIN_REST = true;         // เปิด/ปิด เช็คเวลาพักผ่อนขั้นต่ำ (เวลาเผื่อระหว่างกะ)
$CONF_MIN_REST_HOURS = 8;            // เวลาพักผ่อนขั้นต่ำ (ชั่วโมง) ระหว่าง 2 กะ
// =========================================================================

if (!isset($_POST['csrf_token'], $_SESSION['csrf_token'])) {
    die("CSRF token missing");
}
if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die("Invalid CSRF token");
}

$is_manage_mode = ($_POST['manage_mode'] ?? '0') === '1';
$post_date = $_POST['date'] ?? null;
$is_past_date = ($post_date < date('Y-m-d'));

// ตรวจสอบสิทธิ์ด้วย hasPermission แทน role_pr
$has_manage_permission = hasPermission('schedule.management');

if (($is_manage_mode || $is_past_date) && !$has_manage_permission) {
    http_response_code(403);
    exit('Permission denied: schedule.management is required.');
}

$req = $_POST['req'];
$req = ucfirst(strtolower($req)); 
if ($req !== 'Swap') {
    http_response_code(403);
    exit('Invalid request');
}

$user_id = $_POST['user_id'] ?? null;
$schedule_id = $_POST['schedule_id'] ?? null;

// ดึงข้อมูลกะงานตั้งต้นของผู้ที่ต้องการสลับ (Requester)
$requester_url = "/schedule/monthschedule?action=get&schedule_id=" . $schedule_id;
$requester_schedule = callApi($requester_url);
$requester_schedule = $requester_schedule["data"];
$schdule_priority = $requester_schedule[0]['priority'];
$schdule_status = $requester_schedule[0]['status'];

$swap_from_date = '';
$swap_from_dmy = '';
$requester_team = '';
$requester_emp_id = null;
$requester_info = null; 

if (!empty($requester_schedule[0])) {
    $requester_info = $requester_schedule[0];
    $swap_from_date = $requester_info["schedule_date"] ?? '';
    $requester_emp_id = $requester_info['employee_id'] ?? null; 
    if ($swap_from_date) {
        $dt_from = new DateTime($swap_from_date);
        $swap_from_dmy = $dt_from->format('d M Y');
    }
    $requester_team = $requester_info["team"] ?? '';
    $requester_type_name = $requester_info['type_name'] ?? 'N/A'; 
    $requester_work_group = $requester_info['work_group'] ?? 'N/A'; 
    $requester_start_time = secondsToTime($requester_info['start_time'] ?? 0);
    $requester_end_time = secondsToTime($requester_info['end_time'] ?? 0);
    $requester_time_range = $requester_start_time . ' - ' . $requester_end_time;
    $requester_fullname = ($requester_info['thai_firstname'] ?? '') . ' ' . ($requester_info['thai_lastname'] ?? '');
} else {
    $requester_type_name = 'N/A'; $requester_work_group = 'N/A'; $requester_time_range = 'N/A - N/A'; $requester_fullname = 'N/A';
}

// รับค่า Team Filter (ถ้าไม่มีให้ Default เป็นทีมของผู้ขอสลับ)
$selected_team_filter = $_POST['team_filter'] ?? $requester_team;

$filter_date = $post_date ?? $swap_from_date;
$filter_dmy = ''; 
if ($filter_date) {
    $currentDate = new DateTime($filter_date);
    $filter_dmy = $currentDate->format('d M Y');
    $currentMonth = $currentDate->format('Y-m');
} else {
    $currentDate = new DateTime(); $filter_date = $currentDate->format('Y-m-d'); $currentMonth = $currentDate->format('Y-m');
}

$api_month_year = $currentDate->format('Y-m');

// ดึงรายชื่อทีมทั้งหมดที่มีเพื่อแสดงใน Dropdown
$available_teams = [];
$userapi_url = "/data/schedule";
$result_user = callApi($userapi_url);
if(isset($result_user['status']) && $result_user['status'] === 'success' && isset($result_user['data'])){
    foreach ($result_user['data'] as $u) {
        $t = $u['team'] ?? '';
        if ($t !== '' && !in_array($t, $available_teams)) {
            $available_teams[] = $t;
        }
    }
    sort($available_teams); 
}

// --- Fetch Schedule (Requester Team) ---
// ตัด &schedule_status=Normal ออก เพื่อให้ดึงทุกสถานะมาตรวจสอบ
$scheduleapi_url = "/schedule/monthschedule?action=get&month_year=" . $api_month_year ."&team=" . urlencode($requester_team);
$result_schedule = callApi($scheduleapi_url);
$schedule_data = $result_schedule['data'] ?? [];

// --- Fetch Schedule (Selected Team - If different) ---
if ($selected_team_filter !== $requester_team) {
    $other_team_url = "/schedule/monthschedule?action=get&month_year=" . $api_month_year ."&team=" . urlencode($selected_team_filter);
    $other_team_result = callApi($other_team_url);
    if (!empty($other_team_result['data'])) {
        $schedule_data = array_merge($schedule_data, $other_team_result['data']);
    }
}

// $$$ Check Cross-Month Data (ดึงข้อมูลข้ามเดือนสำหรับเช็ค Overlap) $$$
$extra_dates_to_fetch = [];
$dates_to_check = [$filter_date, $swap_from_date];
foreach ($dates_to_check as $d) {
    if (!$d) continue;
    $check_obj = new DateTime($d);
    $prev_d = (clone $check_obj)->modify('-1 day')->format('Y-m-d');
    $next_d = (clone $check_obj)->modify('+1 day')->format('Y-m-d');
    
    if (substr($prev_d, 0, 7) !== $currentMonth) $extra_dates_to_fetch[$prev_d] = true;
    if (substr($d, 0, 7) !== $currentMonth) $extra_dates_to_fetch[$d] = true;
    if (substr($next_d, 0, 7) !== $currentMonth) $extra_dates_to_fetch[$next_d] = true;
}

foreach (array_keys($extra_dates_to_fetch) as $edate) {
    $extra_url = "/schedule/monthschedule?action=get&schedule_date=" . $edate ."&team=" . urlencode($requester_team);
    $extra_res = callApi($extra_url);
    if (!empty($extra_res['data'])) $schedule_data = array_merge($schedule_data, $extra_res['data']);
    
    if ($selected_team_filter !== $requester_team) {
        $extra_url2 = "/schedule/monthschedule?action=get&schedule_date=" . $edate ."&team=" . urlencode($selected_team_filter);
        $extra_res2 = callApi($extra_url2);
        if (!empty($extra_res2['data'])) $schedule_data = array_merge($schedule_data, $extra_res2['data']);
    }
}
// $$$ End Cross-Month Check $$$

$team_schedule_map_full = []; 
$requester_work_map_for_month = []; 
$non_working_types_full = ['OFF', 'LEAVE', 'HOLIDAY', 'N/A', '']; 

foreach ($schedule_data as $entry) {
    $date_key = $entry['schedule_date'] ?? null;
    $employee_id = $entry['employee_id'] ?? null;
    $type = strtoupper($entry['type_name'] ?? 'N/A');
    if (!$date_key || !$employee_id) continue;
    
    $is_work_shift = !in_array($type, $non_working_types_full) && ($entry['work_schedule_id'] ?? null) !== null;
    
    if ((string)$employee_id === (string)$requester_emp_id) {
        $requester_work_map_for_month[$date_key] = ($requester_work_map_for_month[$date_key] ?? false) || $is_work_shift;
    }
    
    if (!isset($team_schedule_map_full[(string)$employee_id])) {
        $team_schedule_map_full[(string)$employee_id] = [];
    }
    
    $team_schedule_map_full[(string)$employee_id][$date_key] = [
        'status' => $is_work_shift ? 'WORKING' : 'AVAILABLE',
        'schedule_status' => $entry['schedule_status'] ?? $entry['status'] ?? 'Normal',
        'type_name' => $entry['type_name'] ?? 'No Shift',
        'priority' => $entry['priority'] ?? 0,
        'start_time' => $entry['start_time'] ?? 0,
        'end_time' => $entry['end_time'] ?? 0,
    ];
}

// Function 1: Check Consecutive Work
function check_consecutive_work($emp_id, $filter_date, $schedule_map_full, $max_consecutive_days = 6) {
    if (!isset($schedule_map_full[(string)$emp_id])) return false;
    $employee_schedule = $schedule_map_full[(string)$emp_id];
    $backward_count = 0; $date_obj = new DateTime($filter_date);
    for ($i = 0; $i < $max_consecutive_days; $i++) {
        $date_obj->modify('-1 day'); $date_key = $date_obj->format('Y-m-d');
        if (isset($employee_schedule[$date_key]) && $employee_schedule[$date_key]['status'] === 'WORKING') $backward_count++;
        else break;
    }
    $forward_count = 0; $date_obj = new DateTime($filter_date);
    for ($i = 0; $i < $max_consecutive_days; $i++) {
        $date_obj->modify('+1 day'); $date_key = $date_obj->format('Y-m-d');
        if (isset($employee_schedule[$date_key]) && $employee_schedule[$date_key]['status'] === 'WORKING') $forward_count++;
        else break;
    }
    return ($backward_count + 1 + $forward_count) > $max_consecutive_days; 
}

// Function 2: Check Time Overlap AND Rest Time (Adjacent Days)
function check_time_overlap_and_rest($emp_id, $target_date, $new_start_sec, $new_end_sec, $schedule_map, $check_overlap, $check_rest, $min_rest_hours) {
    if (!isset($schedule_map[(string)$emp_id])) return false;
    
    $target_ts = strtotime($target_date);
    $min_rest_sec = $check_rest ? ($min_rest_hours * 3600) : 0;
    
    // Calculate Absolute Times for the New Shift
    $new_start_abs = $target_ts + $new_start_sec;
    $new_end_abs = $target_ts + $new_end_sec;
    if ($new_end_sec < $new_start_sec) { 
        $new_end_abs += 86400; // Crosses midnight
    }

    // --- 1. เช็ควันก่อนหน้า (Previous Day) ---
    $prev_date = date('Y-m-d', strtotime('-1 day', $target_ts));
    if (isset($schedule_map[(string)$emp_id][$prev_date]) && $schedule_map[(string)$emp_id][$prev_date]['status'] === 'WORKING') {
        $prev_shift = $schedule_map[(string)$emp_id][$prev_date];
        
        $sc_status = $prev_shift['schedule_status'] ?? 'Normal';
        if (!in_array($sc_status, ['Pending', 'Standby', 'Requested'])) {
            $prev_start = $prev_shift['start_time'];
            $prev_end = $prev_shift['end_time'];
            
            $prev_ts = strtotime($prev_date);
            $prev_end_abs = $prev_ts + $prev_end;
            if ($prev_end < $prev_start) $prev_end_abs += 86400;
            
            if ($check_overlap && $prev_end_abs > $new_start_abs) return true;
            if ($check_rest && $prev_end_abs <= $new_start_abs && ($new_start_abs - $prev_end_abs) < $min_rest_sec) return true;
        }
    }

    // --- 2. เช็ควันถัดไป (Next Day) ---
    $next_date = date('Y-m-d', strtotime('+1 day', $target_ts));
    if (isset($schedule_map[(string)$emp_id][$next_date]) && $schedule_map[(string)$emp_id][$next_date]['status'] === 'WORKING') {
        $next_shift = $schedule_map[(string)$emp_id][$next_date];
        
        $sc_status = $next_shift['schedule_status'] ?? 'Normal';
        if (!in_array($sc_status, ['Pending', 'Standby', 'Requested'])) {
            $next_start = $next_shift['start_time'];
            $next_end = $next_shift['end_time'];
            $next_ts = strtotime($next_date);
            $next_start_abs = $next_ts + $next_start;
            
            if ($check_overlap && $new_end_abs > $next_start_abs) return true;
            if ($check_rest && $new_end_abs <= $next_start_abs && ($next_start_abs - $new_end_abs) < $min_rest_sec) return true;
        }
    }

    return false;
}

// Function 3: Check Time Overlap AND Rest Time (SAME Day)
function check_same_day_overlap_and_rest($req_start, $req_end, $sub_start, $sub_end, $check_overlap, $check_rest, $min_rest_hours) {
    if ($sub_start == 0 && $sub_end == 0) return false;
    $req_e = $req_end < $req_start ? $req_end + 86400 : $req_end;
    $sub_e = $sub_end < $sub_start ? $sub_end + 86400 : $sub_end;
    $min_rest_sec = $check_rest ? ($min_rest_hours * 3600) : 0;

    if ($check_overlap && max($req_start, $sub_start) < min($req_e, $sub_e)) return true;
    
    if ($check_rest) {
        if ($req_e <= $sub_start && ($sub_start - $req_e) < $min_rest_sec) return true;
        if ($sub_e <= $req_start && ($req_start - $sub_e) < $min_rest_sec) return true;
    }
    return false;
}

$today = new DateTime(); $today->setTime(0, 0, 0);
$daysInMonth = $currentDate->format('t');
$datesInMonth = [];
for ($i = 1; $i <= $daysInMonth; $i++) {
    $dateObj = new DateTime($currentMonth . '-' . str_pad($i, 2, '0', STR_PAD_LEFT));
    $date_key = $dateObj->format('Y-m-d');
    $isPast = ($dateObj < $today);
    $isDisabled = ($is_manage_mode) ? false : $isPast; 
    $isDisabledByRequesterSchedule = $requester_work_map_for_month[$date_key] ?? false;
    if ($date_key === $swap_from_date) $isDisabledByRequesterSchedule = false; 
    $datesInMonth[] = [ 'full' => $date_key, 'dayNum' => $dateObj->format('d'), 'dayName' => $dateObj->format('D'), 'isDisabled' => $isDisabled, 'isDisabledByRequesterSchedule' => $isDisabledByRequesterSchedule ];
}

// Global variable definitions
$global_swap_is_blocked = false;
$is_requester_busy_on_target = false; 

if (!$is_manage_mode && $filter_date !== $swap_from_date) {
    if ($requester_emp_id) {
        // หาก Requester มีตารางงานอยู่แล้วในวันที่เลือก (Double Shift) ให้ถือว่า Busy
        if ($requester_work_map_for_month[$filter_date] ?? false) {
            $global_swap_is_blocked = true;
            $is_requester_busy_on_target = true;
        }
    }
}

// Fetch Candidates
// ยังคง &schedule_status=Normal สำหรับการเลือก Candidate ตามเงื่อนไข
$DateSchedule_url = "/schedule/monthschedule?action=get&schedule_date=" . $filter_date . "&team=" . urlencode($selected_team_filter) . "&schedule_status=".$schdule_status."&priority=".$schdule_priority;
$Date_schedule_result = callApi($DateSchedule_url);
$Date_schedule = $Date_schedule_result["data"] ?? [];
$filtered_date_schedule = [];
foreach ($Date_schedule as $schedule) {
    if (($schedule['priority'] ?? 0) === 1) $filtered_date_schedule[] = $schedule;
}
$Date_schedule = $filtered_date_schedule;
$is_schedule_empty = empty($Date_schedule);
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
    <title>Request Swap</title>
</head>
<body>
    <div id="loading-overlay"><div class="spinner"></div><p class="ms-3 text-secondary">Loading...</p></div>
    
    <!-- Header Bar -->
    <div class="header-bar d-flex justify-content-between align-items-center flex-wrap gap-3">
        <span class="header-title">
            <i class="fa-solid fa-file-invoice me-2" style="text-shadow: none;"></i> Swap Request
            <span class="text-muted fs-6 ms-2">| Date: <?php echo htmlspecialchars($filter_dmy); ?></span>
            <?php if ($is_manage_mode): ?>
                <span class="badge bg-warning text-dark ms-2" style="font-size: 0.6em; vertical-align: middle;">
                    <i class="fa fa-cog"></i> Manage Mode
                </span>
            <?php endif; ?>
        </span>
        <div class="btn-group mt-2 mt-md-0" role="group">
            <a href="schedule.php" class="btn btn-success btn-sm"><i class="fa fa-arrow-left"></i> Back</a>
        </div>
    </div>

    <div class="container-fluid my-3 px-4">
        <form id="dateForm" method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="mb-4 bg-white p-3 rounded shadow-sm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="req" value="<?= htmlspecialchars($req); ?>">
            <input type="hidden" name="user_id" value="<?= htmlspecialchars($user_id); ?>">
            <input type="hidden" name="schedule_id" value="<?= htmlspecialchars($schedule_id); ?>">
            <input type="hidden" name="manage_mode" value="<?= $is_manage_mode ? '1' : '0' ?>">
            <input type="hidden" name="date" id="dateHidden" value="<?= htmlspecialchars($filter_date); ?>">
            
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
                <div class="w-100 mb-3 pb-2 border-bottom d-flex align-items-center flex-wrap gap-2 text-dark" style="font-size: 0.9rem;">
                    <span class="fw-bold"><i class="fa fa-user me-1"></i> <?= htmlspecialchars((string)$requester_fullname); ?></span>
                    <span class="text-muted">|</span>
                    <span class="badge bg-primary"><i class="fa fa-briefcase me-1"></i> <?= htmlspecialchars((string)$requester_type_name); ?></span>
                    <span class="badge bg-info text-dark"><i class="fa fa-users me-1"></i> <?= htmlspecialchars((string)$requester_work_group); ?></span>
                    <span class="badge bg-secondary"><i class="fa fa-clock me-1"></i> <?= htmlspecialchars((string)$requester_time_range); ?></span>
                    <span class="badge bg-info"><i class="fa-solid fa-bookmark me-1"></i> <?= htmlspecialchars((string)$schdule_status); ?></span>
                </div>

                <div class="d-flex align-items-center flex-wrap date-display-info">
                    <span class="fw-bold text-danger me-2"><i class="fa fa-calendar-times me-1"></i> <?= htmlspecialchars((string)$swap_from_dmy); ?></span>
                    <i class="fa fa-exchange-alt mx-2 text-primary" style="font-size: 1.2rem;"></i>
                    <span class="fw-bold text-success"><i class="fa fa-calendar-check me-1"></i> <?= htmlspecialchars((string)$filter_dmy); ?></span>
                </div>
                <label class="col-form-label fw-bold text-success text-end mt-2 mt-sm-0" style="font-size: 0.9rem;">เลือกวันที่สลับเข้า (<?= $currentDate->format('M Y'); ?>)</label>
            </div>
            
            <?php if ($global_swap_is_blocked): ?>
                <div class="alert alert-danger p-2 small mt-2">
                    <i class="fa fa-ban me-1"></i> ไม่สามารถขอสลับงานไปยังวันที่ <?= htmlspecialchars((string)$filter_dmy); ?> ได้ เนื่องจากคุณติดตารางงาน
                </div>
            <?php endif; ?>
            
            <div id="dayPillContainer" class="row g-1 scroll-container mb-4">
                <?php foreach ($datesInMonth as $d): ?>
                    <div class="col"><span class="day-pill w-100 <?= ($d['full'] === $filter_date) ? 'active bg-success text-white' : 'bg-light'; ?> <?= ($d['isDisabled']) ? 'disabled-past' : ''; ?> <?= ($d['isDisabledByRequesterSchedule']) ? 'disabled-schedule' : ''; ?>" data-date="<?= htmlspecialchars($d['full']); ?>"><?= $d['dayName']; ?><br><span class="fs-6"><?= $d['dayNum']; ?></span></span></div>
                <?php endforeach; ?>
            </div>

            <hr>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-secondary mb-0"><i class="fa fa-list me-1"></i> เลือกตารางงานสำหรับสลับวันที่ <?php echo htmlspecialchars($filter_dmy); ?></h5>
                
                <!-- Team Filter Dropdown -->
                <?php if ($has_manage_permission && $is_manage_mode): ?>
                    <div class="d-flex align-items-center">
                        <label for="teamFilter" class="me-2 fw-bold small text-muted"><i class="fa fa-users"></i> เลือกทีม:</label>
                        <select name="team_filter" id="teamFilter" class="form-select form-select-sm" style="width: auto; display: inline-block;">
                            <?php foreach ($available_teams as $t): ?>
                                <option value="<?php echo htmlspecialchars($t); ?>" <?php echo ($selected_team_filter === $t) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($t); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <input type="hidden" id="teamFilter" name="team_filter" value="<?= htmlspecialchars($selected_team_filter); ?>">
                <?php endif; ?>
            </div>

        </form>

        <div class="row">
            <?php 
            if (!$is_schedule_empty): 
                foreach ($Date_schedule as $schedule):
                    $candidate_emp_id = $schedule['employee_id'] ?? null;
                    $candidate_fullname = ($schedule['thai_firstname'] ?? '') . ' ' . ($schedule['thai_lastname'] ?? '');
                    $is_own_schedule = (string)($schedule['schedule_id'] ?? '0') === (string)$schedule_id; 

                    // 1. Check Consecutive
                    $has_consecutive_violation = false;
                    if ($CONF_CHECK_CONSECUTIVE && $candidate_emp_id) {
                        $has_consecutive_violation = check_consecutive_work($candidate_emp_id, $filter_date, $team_schedule_map_full, $CONF_MAX_CONSECUTIVE_DAYS);
                    }
                    
                    // 2. Check Target Busy & Overlap
                    $is_target_busy_on_requester_date = false;
                    $target_busy_msg = '';
                    if ($swap_from_date && $filter_date !== $swap_from_date && $candidate_emp_id) {
                         $target_status_on_from_date = $team_schedule_map_full[(string)$candidate_emp_id][$swap_from_date] ?? null;
                         if ($target_status_on_from_date && $target_status_on_from_date['status'] === 'WORKING') {
                             
                             $sc_status = $target_status_on_from_date['schedule_status'] ?? 'Normal';
                             if (!in_array($sc_status, ['Pending', 'Standby', 'Requested'])) {
                                 $is_target_busy_on_requester_date = true;
                                 $target_busy_msg = 'ติดงานวันที่ ' . $swap_from_dmy;
                             }
                         }
                    }

                    // 3. Check Time Overlap AND Rest Time
                    $has_overlap = false;
                    $overlap_msg = "";

                    if ($CONF_CHECK_OVERLAP || $CONF_CHECK_MIN_REST) {
                        if ($requester_emp_id && !$is_own_schedule) {
                            if (check_time_overlap_and_rest($requester_emp_id, $filter_date, $schedule['start_time'], $schedule['end_time'], $team_schedule_map_full, $CONF_CHECK_OVERLAP, $CONF_CHECK_MIN_REST, $CONF_MIN_REST_HOURS)) {
                                $has_overlap = true;
                                $overlap_msg = "เวลาทับซ้อน ({$requester_fullname})";
                            }
                        }

                        if (!$has_overlap && $candidate_emp_id && $requester_info && !$is_own_schedule) {
                            $cand_start_ignore = null;
                            $cand_end_ignore = null;
                            if (isset($team_schedule_map_full[(string)$candidate_emp_id][$swap_from_date])) {
                                $c_shift = $team_schedule_map_full[(string)$candidate_emp_id][$swap_from_date];
                                if ($c_shift['status'] === 'WORKING') {
                                    $cand_start_ignore = $c_shift['start_time'];
                                    $cand_end_ignore = $c_shift['end_time'];
                                }
                            }
                            
                            if (check_time_overlap_and_rest($candidate_emp_id, $swap_from_date, $requester_info['start_time'], $requester_info['end_time'], $team_schedule_map_full, $CONF_CHECK_OVERLAP, $CONF_CHECK_MIN_REST, $CONF_MIN_REST_HOURS)) {
                                 $has_overlap = true;
                                 $overlap_msg = "เวลาทับซ้อน ({$candidate_fullname})";
                            }
                        }
                    }

                    // 4. Combine blocks and format them dynamically as (A)(B)
                    $violation_reasons = [];
                    if ($has_consecutive_violation) $violation_reasons[] = "ทำงานต่อเนื่องเกิน " . $CONF_MAX_CONSECUTIVE_DAYS . " วัน";
                    if ($is_target_busy_on_requester_date) $violation_reasons[] = $target_busy_msg;
                    if ($has_overlap) $violation_reasons[] = $overlap_msg;
                    if (isset($is_requester_busy_on_target) && $is_requester_busy_on_target && !$has_overlap && !$is_target_busy_on_requester_date) $violation_reasons[] = "คุณมีตารางงานในวันที่เลือกอยู่แล้ว";

                    $has_violation = !empty($violation_reasons);
                    // ปรับ format ให้เป็น (เงื่อนไข 1)(เงื่อนไข 2)
                    $violation_text = '';
                    if (!empty($violation_reasons)) {
                        $violation_text = '(' . implode(')(', $violation_reasons) . ')';
                    }

                    // --- Final Decision for Button State ---
                    $is_button_disabled_final = $is_own_schedule;

                    // 1. Global Block: ตัวเราเองมีตารางงานในวันที่เลือก (Filter Date) อยู่แล้ว
                    if ($global_swap_is_blocked && !$is_manage_mode) {
                        $is_button_disabled_final = true;
                    }

                    // 2. Target Busy: เพื่อนมีตารางงานในวันที่เราจะโยนให้ (Swap Date) -> ล็อคทันที
                    if ($is_target_busy_on_requester_date) {
                        // ถ้าไม่ใช่ Manager Mode -> ล็อค
                        if (!($has_manage_permission && $is_manage_mode)) {
                            $is_button_disabled_final = true;
                        }
                    }
                    
                    $cardColor = $schedule['color'] ?? '#0d6efd';
                    $timeRange = secondsToTime($schedule['start_time'] ?? 0) . ' - ' . secondsToTime($schedule['end_time'] ?? 0);
            ?>
                <div class="col-6 col-sm-4 col-md-3 col-lg-2 mb-4">
                    <div class="card h-100 shadow-sm card-shift <?= ($has_violation) ? 'card-warning' : ''; ?> <?= ($is_button_disabled_final && !$is_own_schedule) ? 'card-error' : ''; ?>" style="border-left-color: <?= htmlspecialchars((string)$cardColor); ?>;">
                        
                        <div class="card-header bg-transparent border-bottom-0 pb-0 pt-2 px-2">
                            <h6 class="card-title fw-bold text-dark text-truncate mb-0" style="font-size: 0.85rem;" title="<?= htmlspecialchars((string)($schedule['thai_firstname'].' '.$schedule['thai_lastname'])); ?>">
                                <i class="fa fa-user me-1"></i> <?= htmlspecialchars((string)($schedule['thai_firstname'].' '.$schedule['thai_lastname'])); ?>
                            </h6>
                        </div>

                        <div class="card-body p-2 d-flex flex-column">
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-start justify-content-between mb-2">
                                    <div style="font-size: 0.7rem; line-height: 1.3;">
                                        <div><strong>Group:</strong> <span class="badge text-bg-info" style="font-size: 0.65rem;"><?= htmlspecialchars((string)($schedule['work_group'] ?? 'N/A')); ?></span></div>
                                        <div class="mt-1"><strong>Team:</strong> <span class="badge text-bg-secondary" style="font-size: 0.65rem;"><?= htmlspecialchars((string)($schedule['team'] ?? '-')); ?></span></div>
                                        <div class="text-muted mt-1" style="white-space: nowrap;"><i class="fa fa-clock me-1"></i><?= htmlspecialchars((string)$timeRange); ?></div>
                                    </div>
                                    <div class="ms-1">
                                        <span class="badge d-flex align-items-center justify-content-center shadow-sm" style="border: 2px solid <?= htmlspecialchars((string)$cardColor); ?>; background-color: #ffffff; color: #000000; font-size: 1rem; min-width: 45px; height: 40px; border-radius: 6px;">
                                            <?php if ($schedule['status'] == 'OT'): ?>
                                                <?= htmlspecialchars((string)("O" . ($schedule['type_name'] ?? 'N/A'))); ?>
                                            <?php else: ?>
                                                <?= htmlspecialchars((string)($schedule['type_name'] ?? 'N/A')); ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="d-flex flex-wrap justify-content-center gap-1 mt-1 mb-2" style="min-height: 22px;">
                                    <?php if ($is_target_busy_on_requester_date): ?>
                                        <span class="badge bg-danger" style="font-size: 0.6rem;" title="<?= $target_busy_msg ?>"><i class="fa fa-calendar-xmark"></i> Busy</span>
                                        <?php if ($has_manage_permission && $is_manage_mode) echo '<span class="badge bg-warning text-dark" style="font-size: 0.6rem;">Bypass</span>'; ?>
                                    <?php endif; ?>

                                    <?php if ($has_overlap): ?>
                                        <span class="badge bg-danger" style="font-size: 0.6rem;" title="<?= $overlap_msg ?>"><i class="fa fa-business-time"></i> Overlap</span>
                                        <?php if ($has_manage_permission && $is_manage_mode && !$is_target_busy_on_requester_date) echo '<span class="badge bg-warning text-dark" style="font-size: 0.6rem;">Bypass</span>'; ?>
                                    <?php endif; ?>

                                    <?php if ($has_consecutive_violation && !$is_target_busy_on_requester_date && !$has_overlap): ?>
                                        <span class="badge bg-danger" style="font-size: 0.6rem;" title="เกิน <?= $CONF_MAX_CONSECUTIVE_DAYS; ?> วัน"><i class="fa fa-exclamation-triangle"></i> > <?= $CONF_MAX_CONSECUTIVE_DAYS; ?> Days</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($is_requester_busy_on_target && !$has_overlap && !$is_target_busy_on_requester_date): ?>
                                        <span class="badge bg-danger" style="font-size: 0.6rem;" title="คุณมีตารางงานวันนี้อยู่แล้ว"><i class="fa fa-calendar-plus"></i> Double Shift</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($is_button_disabled_final): ?>
                                <span class="btn btn-sm w-100 mt-auto btn-secondary disabled" style="font-size: 0.75rem; padding: 2px 5px; opacity: 0.65; cursor: not-allowed;">
                                    <?php 
                                        if ($is_own_schedule) {
                                            echo 'ตารางงานของคุณ';
                                        } elseif ($is_target_busy_on_requester_date) {
                                            echo 'Busy (เลือกไม่ได้)';
                                        } elseif ($is_requester_busy_on_target) {
                                            echo 'คุณไม่ว่าง';
                                        } else {
                                            echo 'Unavailable';
                                        }
                                    ?>
                                </span>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm w-100 mt-auto btn-light border border-primary" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#confirmSwapModal" 
                                    data-target-schedule-id="<?= htmlspecialchars($schedule['schedule_id'] ?? ''); ?>" 
                                    data-target-name="<?= htmlspecialchars(($schedule['thai_firstname'] ?? '').' '.($schedule['thai_lastname'] ?? '')); ?>" 
                                    data-target-date="<?= (new DateTime($schedule['schedule_date']))->format('d M Y'); ?>" 
                                    data-target-type="<?= htmlspecialchars($schedule['type_name'] ?? 'N/A'); ?>" 
                                    data-target-workgroup="<?= htmlspecialchars($schedule['work_group'] ?? 'N/A'); ?>" 
                                    data-target-timerange="<?= htmlspecialchars($timeRange); ?>" 
                                    data-has-violation="<?= $has_violation ? 'true' : 'false' ?>"
                                    data-violation-msg="<?= htmlspecialchars($violation_text) ?>"
                                    style="font-size: 0.75rem; padding: 2px 5px;">
                                    Select
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; else: ?><div class="col-12"><div class="alert alert-warning">ไม่พบตารางเวลาสำหรับทีมนี้ในวันที่ระบุ</div></div><?php endif; ?>
        </div>
    </div>
    
    <!-- Modal -->
    <div class="modal fade" id="confirmSwapModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <form id="swapConfirmForm" method="POST" action="request_process.php">
                    <div class="modal-header bg-warning text-white">
                        <h5 class="modal-title">ยืนยันการสลับงาน</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-primary fw-bold text-center mb-3">กรุณาตรวจสอบข้อมูลก่อนยืนยันการสลับตารางงาน</p>
                        
                        <!-- Alert สำหรับแจ้งเตือนเงื่อนไขไม่ผ่าน -->
                        <div id="violationAlert" class="alert alert-warning small d-none">
                            <i class="fa fa-exclamation-triangle me-1"></i> <strong>เงื่อนไขไม่ผ่าน:</strong> <span id="violationText"></span>
                            <br>การสลับนี้จะต้องได้รับการอนุมัติจากผู้อนุมัติ (Approver) ก่อนจึงจะมีผล
                        </div>

                        <div class="container-fluid px-0">
                            <div class="row g-2"> 
                                <div class="col-5">
                                    <div class="card border-danger h-100">
                                        <div class="card-header bg-danger text-white py-1 text-center small fw-bold">ตารางเดิมของคุณ</div>
                                        <div class="card-body p-2 text-center small d-flex flex-column justify-content-center">
                                            <div class="fw-bold text-danger mb-1" style="font-size: 1rem;"><?= htmlspecialchars((string)$swap_from_dmy); ?></div>
                                            <div class="fw-bold text-dark mb-1" style="font-size: 0.9rem;"><?= htmlspecialchars((string)$requester_fullname); ?></div>
                                            <div class="text-muted mb-1" style="font-size: 0.8rem;">ID: <?= htmlspecialchars((string)$schedule_id); ?></div>
                                            <div class="mb-1"><span class="badge bg-secondary" style="font-size: 0.8rem;"><?= htmlspecialchars((string)$requester_type_name); ?></span></div>
                                            <div class="text-muted" style="font-size: 0.8rem;"><?= htmlspecialchars((string)$requester_time_range); ?></div>
                                            <div class="text-muted" style="font-size: 0.8rem;">กลุ่ม: <?= htmlspecialchars((string)$requester_work_group); ?></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-2 d-flex align-items-center justify-content-center">
                                    <i class="fa-solid fa-right-left fa-2x text-warning"></i>
                                </div>

                                <div class="col-5">
                                    <div class="card border-success h-100">
                                        <div class="card-header bg-success text-white py-1 text-center small fw-bold">ตารางใหม่ที่ต้องการ</div>
                                        <div class="card-body p-2 text-center small d-flex flex-column justify-content-center">
                                            <div class="fw-bold text-success mb-1" style="font-size: 1rem;"><span id="modalTargetDate"></span></div>
                                            <div class="fw-bold text-dark mb-1" style="font-size: 0.9rem;"><span id="modalTargetName"></span></div>
                                            <div class="text-muted mb-1" style="font-size: 0.8rem;">ID: <span id="modalTargetIdDisplay"></span></div>
                                            <div class="mb-1"><span class="badge bg-success" style="font-size: 0.8rem;"><span id="modalTargetType"></span></span></div>
                                            <div class="text-muted" style="font-size: 0.8rem;"><span id="modalTargetTime"></span></div>
                                            <div class="text-muted" style="font-size: 0.8rem;">กลุ่ม: <span id="modalTargetGroup"></span></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- เพิ่มช่องกรอกเหตุผลแบบ Textarea และบังคับ (required) -->
                        <div class="col-12 mt-3">
                            <label for="commentInput" class="form-label fw-bold text-danger">เหตุผลการสลับ (จำเป็นต้องระบุ):</label>
                            <textarea class="form-control border-danger" name="comment" id="commentInput" rows="2" placeholder="ระบุเหตุผล..." required></textarea>
                        </div>
                        
                        <br>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="swap">
                        <input type="hidden" name="requester_schedule_id" value="<?= htmlspecialchars($schedule_id); ?>">
                        <input type="hidden" name="requester_schedule_status" value="<?= htmlspecialchars($schdule_status); ?>">
                        <input type="hidden" name="request_type" value="<?= htmlspecialchars($req); ?>">
                        <input type="hidden" name="target_schedule_id" id="formTargetScheduleId" value="">
                        <input type="hidden" name="manage_mode" value="<?= $is_manage_mode ? '1' : '0' ?>">
                    </div>
                    <div class="modal-footer justify-content-center">
                        <button type="button" class="btn btn-secondary btn-sm px-4" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-success btn-sm px-4">ยืนยัน</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    $(document).ready(function() {
        $('#loading-overlay').fadeOut('fast');
        
        function submitFormWithUpdates(newDate = null) {
            const currentFilterDate = (newDate) ? newDate : $('#dateHidden').val();
            $('#loading-overlay').fadeIn('fast');
            const tempForm = $('<form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>"></form>');
            
            const inputs = ['csrf_token', 'req', 'user_id', 'schedule_id', 'manage_mode'];
            inputs.forEach(name => {
                $('#dateForm').find('input[name="'+name+'"]').each(function() { tempForm.append($(this).clone()); });
            });
            
            tempForm.append('<input type="hidden" name="date" value="' + currentFilterDate + '">');
            tempForm.append('<input type="hidden" name="team_filter" value="' + $('#teamFilter').val() + '">');
            
            $('body').append(tempForm);
            tempForm.submit();
        }

        $('.day-pill').on('click', function() {
            if ($('#dateHidden').val() !== $(this).data('date')) {
                submitFormWithUpdates($(this).data('date'));
            }
        });

        $('#teamFilter').on('change', function() {
            submitFormWithUpdates();
        });
        
        $('#confirmSwapModal').on('show.bs.modal', function (e) {
            const b = $(e.relatedTarget);
            $('#modalTargetDate').text(b.data('target-date'));
            $('#modalTargetName').text(b.data('target-name'));
            $('#modalTargetIdDisplay').text(b.data('target-schedule-id'));
            $('#modalTargetType').text(b.data('target-type'));
            $('#modalTargetTime').text(b.data('target-timerange'));
            $('#modalTargetGroup').text(b.data('target-workgroup'));
            $('#formTargetScheduleId').val(b.data('target-schedule-id'));
            

            const hasViolation = b.data('has-violation') === true;
            const violationMsg = b.data('violation-msg');
            
            if (hasViolation) {
                $('#violationAlert').removeClass('d-none');
                $('#violationText').text(violationMsg);
            } else {
                $('#violationAlert').addClass('d-none');
            }


            $('#commentInput').val('');
        });


        $('#swapConfirmForm').on('submit', function(e) {
            let commentInput = $('#commentInput');
            let comment = commentInput.val().trim();
            
            if (comment === "") {
                e.preventDefault();
                alert("กรุณาระบุเหตุผลการสลับตารางงาน");
                commentInput.focus();
                return false;
            }


            const fromDate = "<?= $swap_from_dmy; ?>";
            const targetDate = $('#modalTargetDate').text().trim();
            let additionalInfo = "";


            if (fromDate === targetDate) {
                additionalInfo = " (สลับภายในวันเดียวกัน)";
            } else {
                additionalInfo = " (สลับกับวันที่ " + targetDate + ")";
            }


            if (!$('#violationAlert').hasClass('d-none')) {
                const violationMsg = $('#violationText').text();
                if (violationMsg) {
                    additionalInfo += " " + violationMsg; 
                }
            }

  
            commentInput.val(comment + additionalInfo);
        });
    });
    </script>
</body>
</html>
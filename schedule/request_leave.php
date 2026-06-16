<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
checkLogin();
$token = isset($_COOKIE["token"]) ? $_COOKIE["token"] : null;
$userlogin = isset($_SESSION["user_data"]) ? $_SESSION["user_data"] : null;

// =========================================================================
// --- ตั้งค่าเงื่อนไขการตรวจสอบ (Configurations) ---
// คุณสามารถปรับ True/False หรือแก้ไขตัวเลขได้ตามต้องการ
// =========================================================================
$CONF_CHECK_CONSECUTIVE = true;      // เปิด/ปิด เช็คการทำงานต่อเนื่อง
$CONF_MAX_CONSECUTIVE_DAYS = 6;      // จำนวนวันทำงานต่อเนื่องสูงสุดที่อนุญาต

$CONF_CHECK_OVERLAP = true;          // เปิด/ปิด เช็คเวลาทับซ้อน (กะชนกัน)

$CONF_CHECK_MIN_REST = true;         // เปิด/ปิด เช็คเวลาพักผ่อนขั้นต่ำ (เวลาเผื่อระหว่างกะ)
$CONF_MIN_REST_HOURS = 8;            // เวลาพักผ่อนขั้นต่ำ (ชั่วโมง) ระหว่าง 2 กะ
// =========================================================================

// ตรวจสอบ CSRF Token
if (!isset($_POST['csrf_token'], $_SESSION['csrf_token'])) {
    die("CSRF token missing");
}

if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die("Invalid CSRF token");
}

$is_manage_mode = ($_POST['manage_mode'] ?? '0') === '1';
$post_date = $_POST['date'] ?? null;

$is_past_date = ($post_date < date('Y-m-d'));
$has_manage_permission = hasPermission('schedule.management');

// ตรวจสอบสิทธิ์: ปลดล็อกวันย้อนหลังให้ User เข้าถึงได้ (เอา $is_past_date ออก)
// บล็อกเฉพาะการเปิดโหมดจัดการ (Manage Mode) โดยไม่มีสิทธิ์
if ($is_manage_mode && !$has_manage_permission) {
    http_response_code(403);
    exit('Permission denied: schedule.management is required.');
}

// รับค่าจาก POST
$req = $_POST['req'];
$req = ucfirst(strtolower($req)); 

if ($req !== 'Leave') {
    http_response_code(403);
    exit('Invalid request');
}

$user_id = $_POST['user_id'] ?? null;
$schedule_id = $_POST['schedule_id'] ?? null; 
$post_date = $_POST['date'] ?? null; 
$selected_leave_type = $_POST['leave_type'] ?? ''; 
$selected_substitute_emp_id = $_POST['substitute_emp_id'] ?? null; 

// --- 1. Fetch Leave Types from API and Determine Retroactive Days ---
$leaveTypes_url = "/api/request_type?action=get";
$apiResult = callApi($leaveTypes_url);
$rawLeaveTypes = $apiResult["data"] ?? [];

$leaveTypes = [];
$max_retro_days = 0; 

foreach ($rawLeaveTypes as $type) {
    $id = $type['request_type_id'];
    $name = $type['request_type_name'];
    $retro_days = isset($type['retro_req_day']) ? (int)$type['retro_req_day'] : 0;
    
    $leaveTypes[$id] = [
        'name' => $name,
        'retro_req_day' => $retro_days
    ];
    
    // หาค่ามากที่สุดเพื่อใช้เป็น Limit เริ่มต้นในกรณีที่ยังไม่ได้เลือกประเภทการลา
    if ($retro_days > $max_retro_days) {
        $max_retro_days = $retro_days;
    }
}

// --- ดึงข้อมูลตารางงานต้นทาง ---
$requester_url = "/schedule/monthschedule?action=get&schedule_id=" . $schedule_id;
$requester_schedule = callApi($requester_url);
$requester_schedule = $requester_schedule["data"];

$leave_from_date = date('Y-m-d'); 
$leave_from_dmy = date('d M Y');
$requester_emp_id = $userlogin['emp_id'] ?? null; 
$requester_fullname = $userlogin['name'] ?? 'N/A'; 
$requester_team = $userlogin['team'] ?? ''; 

if (!empty($requester_schedule[0])) {
    $requester_info = $requester_schedule[0];
    $requester_emp_id = $requester_info['employee_id'] ?? $requester_emp_id; 
    $requester_fullname = ($requester_info['thai_firstname'] ?? '') . ' ' . ($requester_info['thai_lastname'] ?? '');
    $requester_team = $requester_info["team"] ?? $requester_team;
    
    $leave_from_date = $requester_info["schedule_date"] ?? date('Y-m-d');
    
    if ($leave_from_date) {
        $dt_from = new DateTime($leave_from_date);
        $leave_from_dmy = $dt_from->format('d M Y');
    }
}

// รับค่า Team Filter (ถ้าไม่มีให้ Default เป็นทีมของ User)
$selected_team_filter = $_POST['team_filter'] ?? $requester_team;

// กำหนดวันที่ที่ใช้กรอง/เลือก (Filter Date)
$filter_date = $post_date ?? $leave_from_date;
$filter_dmy = ''; 

if ($filter_date) {
    $currentDate = new DateTime($filter_date);
    $filter_dmy = $currentDate->format('d M Y');
    $currentMonth = $currentDate->format('Y-m');
} else {
    $currentDate = new DateTime();
    $filter_date = $currentDate->format('Y-m-d');
    $currentMonth = $currentDate->format('Y-m');
}

// --- Fetch Schedule (Requester Team - Always needed for calendar & validation) ---
$api_month_year = $currentDate->format('Y-m');
$scheduleapi_url = "/schedule/monthschedule?action=get&month_year=" . $api_month_year . "&team=" . urlencode($requester_team);
$result_schedule = callApi($scheduleapi_url);
$schedule_data = $result_schedule['data'] ?? [];

// --- Fetch Schedule (Selected Team - If different from Requester Team) ---
if ($selected_team_filter !== $requester_team) {
    $other_team_url = "/schedule/monthschedule?action=get&month_year=" . $api_month_year . "&team=" . urlencode($selected_team_filter);
    $other_team_result = callApi($other_team_url);
    if (!empty($other_team_result['data'])) {
        // Merge schedules
        $schedule_data = array_merge($schedule_data, $other_team_result['data']);
    }
}

$team_schedule_map_full = []; 
$requester_work_map_for_month = []; 
$non_working_types_full = ['N/A', '']; 

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
    // เก็บ Start/End Time เพิ่มเติมเพื่อเช็ค Overlap
    $team_schedule_map_full[(string)$employee_id][$date_key] = [
        'status' => $is_work_shift ? 'WORKING' : 'AVAILABLE',
        'type_name' => $entry['type_name'] ?? 'No Shift',
        'start_time' => $entry['start_time'] ?? 0,
        'end_time' => $entry['end_time'] ?? 0,
    ];
}

$today = new DateTime();
$today->setTime(0, 0, 0);

// คำนวณวันที่ย้อนหลังตามประเภทการลาที่ถูกเลือก เพื่อใช้กับปฏิทิน Calendar Pills
$calendar_past_limit = clone $today;
if (!empty($selected_leave_type) && isset($leaveTypes[$selected_leave_type])) {
    $calendar_past_limit->modify('-' . $leaveTypes[$selected_leave_type]['retro_req_day'] . ' days');
} else {
    // ถ้ายังไม่ได้เลือกให้ใช้ limit สูงสุดไปก่อนเพื่อไม่ให้ Calendar ปิดกั้นวันที่เร็วเกินไป
    $calendar_past_limit->modify('-' . $max_retro_days . ' days');
}

$daysInMonth = $currentDate->format('t'); 
$datesInMonth = [];

for ($i = 1; $i <= $daysInMonth; $i++) {
    $dateObj = new DateTime($currentMonth . '-' . str_pad($i, 2, '0', STR_PAD_LEFT));
    $date_key = $dateObj->format('Y-m-d');

    $isPastDate = ($dateObj < $today); 
    $is_scheduled_to_work_on_this_day = $requester_work_map_for_month[$date_key] ?? false;
    
    // บล็อกวันที่ย้อนหลังเกินที่กำหนดบนปฏิทิน (ยกเว้นเข้ามาด้วย Manage Mode)
    $is_beyond_limit = (!$is_manage_mode && $dateObj < $calendar_past_limit);
    $isDisabledBySchedule = !$is_scheduled_to_work_on_this_day || $is_beyond_limit; 

    $requester_day_info = $team_schedule_map_full[(string)$requester_emp_id][$date_key] ?? null;
    if ($requester_day_info && $is_scheduled_to_work_on_this_day) {
        $schedule_type_display = $requester_day_info['type_name'] ?? 'Work';
    } else {
        $schedule_type_display = 'ว่าง'; 
    }

    $datesInMonth[] = [
        'full' => $date_key, 
        'dayNum' => $dateObj->format('d'),    
        'dayName' => $dateObj->format('D'),   
        'isPastDate' => $isPastDate,
        'isDisabledBySchedule' => $isDisabledBySchedule,
        'scheduleType' => $schedule_type_display
    ];
}

function check_consecutive_work($emp_id, $filter_date, $schedule_map_full, $max_consecutive_days = 6) {
    if (!isset($schedule_map_full[(string)$emp_id])) {
        return false;
    }
    $employee_schedule = $schedule_map_full[(string)$emp_id];
    
    $backward_count = 0;
    $date_obj = new DateTime($filter_date);
    
    for ($i = 0; $i < $max_consecutive_days; $i++) {
        $date_obj->modify('-1 day');
        $date_key = $date_obj->format('Y-m-d');
        
        $schedule_data = $employee_schedule[$date_key] ?? null;

        if ($schedule_data && $schedule_data['status'] === 'WORKING') {
            $backward_count++;
        } else {
            break;
        }
    }

    $forward_count = 0;
    $date_obj = new DateTime($filter_date);
    
    for ($i = 0; $i < $max_consecutive_days; $i++) {
        $date_obj->modify('+1 day');
        $date_key = $date_obj->format('Y-m-d');
        
        $schedule_data = $employee_schedule[$date_key] ?? null;

        if ($schedule_data && $schedule_data['status'] === 'WORKING') {
            $forward_count++;
        } else {
            break;
        }
    }
    
    $total_consecutive_days = $backward_count + 1 + $forward_count;
    return $total_consecutive_days > $max_consecutive_days; 
}

// --- FUNCTION 1: Check Time Overlap AND Rest Time (Adjacent Days) ---
function check_time_overlap_and_rest($emp_id, $target_date, $new_start_sec, $new_end_sec, $schedule_map, $check_overlap, $check_rest, $min_rest_hours) {
    if (!isset($schedule_map[(string)$emp_id])) return false;
    
    $target_ts = strtotime($target_date);
    $min_rest_sec = $check_rest ? ($min_rest_hours * 3600) : 0;
    
    // Calculate Absolute Times for the Shift being taken over
    $new_start_abs = $target_ts + $new_start_sec;
    $new_end_abs = $target_ts + $new_end_sec;
    if ($new_end_sec < $new_start_sec) { 
        $new_end_abs += 86400; // Crosses midnight
    }

    // 1. Check Overlap with PREVIOUS Day
    $prev_date = date('Y-m-d', strtotime('-1 day', $target_ts));
    if (isset($schedule_map[(string)$emp_id][$prev_date]) && $schedule_map[(string)$emp_id][$prev_date]['status'] === 'WORKING') {
        $prev_shift = $schedule_map[(string)$emp_id][$prev_date];
        $prev_start = $prev_shift['start_time'];
        $prev_end = $prev_shift['end_time'];
        
        $prev_ts = strtotime($prev_date);
        $prev_end_abs = $prev_ts + $prev_end;
        if ($prev_end < $prev_start) { // Crosses midnight
            $prev_end_abs += 86400;
        }
        
        // Strict Overlap
        if ($check_overlap && $prev_end_abs > $new_start_abs) return true;
        // Rest Time Check (If gap is smaller than required)
        if ($check_rest && $prev_end_abs <= $new_start_abs && ($new_start_abs - $prev_end_abs) < $min_rest_sec) return true;
    }

    // 2. Check Overlap with NEXT Day
    $next_date = date('Y-m-d', strtotime('+1 day', $target_ts));
    if (isset($schedule_map[(string)$emp_id][$next_date]) && $schedule_map[(string)$emp_id][$next_date]['status'] === 'WORKING') {
        $next_shift = $schedule_map[(string)$emp_id][$next_date];
        $next_start = $next_shift['start_time'];
        
        $next_ts = strtotime($next_date);
        $next_start_abs = $next_ts + $next_start;
        
        // Strict Overlap
        if ($check_overlap && $new_end_abs > $next_start_abs) return true;
        // Rest Time Check
        if ($check_rest && $new_end_abs <= $next_start_abs && ($next_start_abs - $new_end_abs) < $min_rest_sec) return true;
    }

    return false;
}

// --- FUNCTION 2: Check Time Overlap AND Rest Time (SAME Day) ---
function check_same_day_overlap_and_rest($req_start, $req_end, $sub_start, $sub_end, $check_overlap, $check_rest, $min_rest_hours) {
    if ($sub_start == 0 && $sub_end == 0) return false;

    // Normalize endpoints for midnight crossers
    $req_e = $req_end < $req_start ? $req_end + 86400 : $req_end;
    $sub_e = $sub_end < $sub_start ? $sub_end + 86400 : $sub_end;
    $min_rest_sec = $check_rest ? ($min_rest_hours * 3600) : 0;

    // 1. Strict Overlap check
    if ($check_overlap) {
        if (max($req_start, $sub_start) < min($req_e, $sub_e)) {
            return true;
        }
    }

    // 2. Rest Time Check (Gap between shifts on the same day)
    if ($check_rest) {
        // Gap when Requester shift ends before Substitute shift starts
        if ($req_e <= $sub_start && ($sub_start - $req_e) < $min_rest_sec) return true;
        // Gap when Substitute shift ends before Requester shift starts
        if ($sub_e <= $req_start && ($req_start - $sub_e) < $min_rest_sec) return true;
    }

    return false;
}

// --- LEAVE LOGIC CHECK ---
$global_leave_is_blocked = false;
$block_message = "";
$current_schedule_details = []; 

$is_requester_scheduled_to_work = $requester_work_map_for_month[$filter_date] ?? false; 

if (!$is_requester_scheduled_to_work) {
    $global_leave_is_blocked = true;
    $block_message = "คุณไม่สามารถยื่นคำขอลาได้ เนื่องจากไม่มีตารางงาน (Work Schedule) ในวันที่ " . htmlspecialchars($filter_dmy);
} 

$filter_date_obj = new DateTime($filter_date);
$is_past_date_selection = ($filter_date_obj < $today); 

if (!$global_leave_is_blocked && $is_past_date_selection) {
    // ถ้าเป็นวันที่ผ่านมาแล้ว และ "ไม่ได้เปิดโหมดจัดการ" จะถูกตรวจสอบเงื่อนไขความลึกของวันตามประเภทการลา
    if (!$is_manage_mode) {
        if (!empty($selected_leave_type) && isset($leaveTypes[$selected_leave_type])) {
            $type_retro_days = $leaveTypes[$selected_leave_type]['retro_req_day'];
            $type_limit = clone $today;
            $type_limit->modify("-{$type_retro_days} days");
            
            if ($filter_date_obj < $type_limit) {
                $global_leave_is_blocked = true;
                $block_message = "การ" . $leaveTypes[$selected_leave_type]['name'] . " ยื่นขอลาย้อนหลังได้ไม่เกิน {$type_retro_days} วัน";
            }
        } else {
            // กรณีที่ยังไม่ได้เลือกประเภทการลา ให้ใช้ limit สูงสุดไปก่อน
            // แต่ถ้าคลิกวันที่ย้อนหลังไปไกลกว่า limit สูงสุด ก็ block ได้เลย
            $max_limit = clone $today;
            $max_limit->modify("-{$max_retro_days} days");
            
            if ($filter_date_obj < $max_limit) {
                $global_leave_is_blocked = true;
                $block_message = "สามารถยื่นคำขอลาย้อนหลังได้ไม่เกิน {$max_retro_days} วันเท่านั้น กรุณาเลือกประเภทการลาเพื่อตรวจสอบสิทธิ์";
            }
        }
    }
} 

if ($is_requester_scheduled_to_work) {
    foreach ($schedule_data as $entry) {
        if (($entry['schedule_date'] ?? null) === $filter_date && 
            (string)($entry['employee_id'] ?? null) === (string)$requester_emp_id &&
            !in_array(strtoupper($entry['type_name'] ?? 'N/A'), $non_working_types_full)) {
            
            $current_schedule_details = $entry;
            break;
        }
    }
}

// Get the schedule ID for the currently SELECTED date
$current_date_schedule_id = $current_schedule_details['schedule_id'] ?? null;

$current_schedule_work_group = $current_schedule_details['work_group'] ?? 'N/A';
$current_schedule_type_name = $current_schedule_details['type_name'] ?? 'N/A'; 
$current_start_time = secondsToTime($current_schedule_details['start_time'] ?? 0);
$current_end_time = secondsToTime($current_schedule_details['end_time'] ?? 0);
$current_schedule_time_range = $current_start_time . ' - ' . $current_end_time;

// --- Fetch Substitutes and Teams ---
$all_users = [];
$available_teams = []; 
$userapi_url = "/data/schedule";
$result_user = callApi($userapi_url);
if(isset($result_user['status']) && $result_user['status'] === 'success' && isset($result_user['data'])){
    $all_users = $result_user['data'];
    
    // Extract unique teams
    foreach ($all_users as $u) {
        $t = $u['team'] ?? '';
        if ($t !== '' && !in_array($t, $available_teams)) {
            $available_teams[] = $t;
        }
    }
    sort($available_teams); // Alphabetical sort
}

$available_substitutes = [];

if ($is_requester_scheduled_to_work) { 
    if (!empty($all_users) && is_array($all_users)) {
        foreach ($all_users as $user) {
            $employee_id = $user['employee_id'] ?? null;
            
            if ((string)$employee_id === (string)$requester_emp_id) continue;
            
            // Filter by Selected Team
            if (($user['team'] ?? '') !== $selected_team_filter) continue; 

            $emp_id_str = (string)$employee_id;
            $schedule_data_for_day = $team_schedule_map_full[$emp_id_str][$filter_date] ?? null;

            $is_substitute_available = false;
            
            if (!$schedule_data_for_day) {
                // ไม่มีข้อมูลตารางงาน = ว่าง
                $is_substitute_available = true;
            } else {
                $sub_status = $schedule_data_for_day['status'];
                $sub_shift_type = $schedule_data_for_day['type_name'] ?? '';

                if ($sub_status === 'AVAILABLE') {
                    // กรณี: วันหยุด / ว่าง
                    $is_substitute_available = true;
                } elseif ($sub_status === 'WORKING') {
                    // กรณี: ทำงาน (Double Shift) - อนุญาตให้เลือกได้ ถ้า "กะงานไม่เหมือนกัน"
                    if (trim($sub_shift_type) !== trim($current_schedule_type_name)) {
                        $is_substitute_available = true;
                    }
                }
            }

            if ($is_substitute_available) {
                // 1. เช็คการทำงานต่อเนื่อง (Consecutive Work)
                $has_consecutive_violation = false;
                if ($CONF_CHECK_CONSECUTIVE) {
                    $has_consecutive_violation = check_consecutive_work(
                        $employee_id, 
                        $filter_date, 
                        $team_schedule_map_full, 
                        $CONF_MAX_CONSECUTIVE_DAYS
                    );
                }

                // 2. เช็คเวลาทับซ้อนและพักผ่อน (Overlap & Rest - Adjacent Days)
                $has_adjacent_overlap = check_time_overlap_and_rest(
                    $employee_id,
                    $filter_date,
                    $current_schedule_details['start_time'] ?? 0,
                    $current_schedule_details['end_time'] ?? 0,
                    $team_schedule_map_full,
                    $CONF_CHECK_OVERLAP,
                    $CONF_CHECK_MIN_REST,
                    $CONF_MIN_REST_HOURS
                );

                // 3. เช็คเวลาทับซ้อนและพักผ่อน (Overlap & Rest - Same Day)
                $has_same_day_overlap = false;
                if ($schedule_data_for_day && $schedule_data_for_day['status'] === 'WORKING') {
                     $has_same_day_overlap = check_same_day_overlap_and_rest(
                        $current_schedule_details['start_time'] ?? 0,
                        $current_schedule_details['end_time'] ?? 0,
                        $schedule_data_for_day['start_time'] ?? 0,
                        $schedule_data_for_day['end_time'] ?? 0,
                        $CONF_CHECK_OVERLAP,
                        $CONF_CHECK_MIN_REST,
                        $CONF_MIN_REST_HOURS
                     );
                }

                // รวมผลการตรวจสอบ Overlap และการพักผ่อนไม่เพียงพอ
                $has_any_overlap = $has_adjacent_overlap || $has_same_day_overlap;

                // เตรียมข้อมูลเวลาทำงานของคนแทน (Work Schedule Display)
                $sub_time_display = "";
                if ($schedule_data_for_day && $schedule_data_for_day['status'] === 'WORKING') {
                     $st = secondsToTime($schedule_data_for_day['start_time'] ?? 0);
                     $et = secondsToTime($schedule_data_for_day['end_time'] ?? 0);
                     $sub_time_display = "$st - $et";
                }

                $available_substitutes[] = [
                    'employee_id' => $employee_id,
                    'fullname' => ($user['thai_firstname'] ?? '') . ' ' . ($user['thai_lastname'] ?? ''),
                    'current_status' => $schedule_data_for_day['type_name'] ?? 'No Shift', 
                    'time_range' => $sub_time_display, 
                    'email' => $user['email'] ?? 'N/A', 
                    'consecutive_warning' => $has_consecutive_violation,
                    'overlap_warning' => $has_any_overlap
                ];
            }
        }
    }
}

$selected_substitute_name = 'ยังไม่เลือก';
if ($selected_substitute_emp_id) {
    foreach ($available_substitutes as $sub) {
        if ((string)$sub['employee_id'] === (string)$selected_substitute_emp_id) {
            $selected_substitute_name = $sub['fullname'];
            break;
        }
    }
}
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
    <title>Request Leave</title>
</head>
<body>
    <div id="loading-overlay"><div class="spinner"></div><p class="ms-3 text-secondary">กำลังโหลดข้อมูล...</p></div>

    <!-- Header Bar แบบเดียวกับ Swap -->
    <div class="header-bar d-flex justify-content-between align-items-center flex-wrap gap-3">
        <span class="header-title">
            <i class="fa-solid fa-file-invoice me-2" style="text-shadow: none;"></i> <?php echo htmlspecialchars($req); ?> Request
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
        
        <form id="leaveForm" method="POST" action="" class="mb-4 bg-white p-3 rounded shadow-sm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="req" value="<?php echo htmlspecialchars($req); ?>">
            <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user_id); ?>">
            <input type="hidden" name="schedule_id" value="<?php echo htmlspecialchars($schedule_id); ?>">
            <input type="hidden" name="manage_mode" value="<?php echo $is_manage_mode ? '1' : '0'; ?>">
            <input type="hidden" name="date" id="dateHidden" value="<?php echo htmlspecialchars($filter_date); ?>">
            <input type="hidden" name="substitute_emp_id" id="substituteEmpId" value="<?php echo htmlspecialchars($selected_substitute_emp_id); ?>">

            <div class="d-flex justify-content-between align-items-baseline mb-3 flex-wrap">
                <div class="d-flex align-items-center flex-wrap date-display-info me-3">
                    <span class="fw-bold text-primary mb-0 me-2" style="font-size: 1rem;">
                        <i class="fa fa-calendar-alt me-1"></i> วันที่ลา: <?php echo htmlspecialchars($filter_dmy); ?>
                    </span>
                    
                    <span class="schedule-info-block text-muted" style="font-size: 0.8rem;">
                        <i class="fa fa-clipboard-list me-1"></i> 
                        <?php if ($is_requester_scheduled_to_work): ?>
                            <span class="badge bg-primary me-1"><?php echo htmlspecialchars($current_schedule_type_name); ?></span>
                            <span class="badge bg-secondary me-1"><?php echo htmlspecialchars($current_schedule_time_range); ?></span>
                            (กลุ่ม: <?php echo htmlspecialchars($current_schedule_work_group); ?>)
                        <?php else: ?>
                            <span class="text-danger fw-bold">(ไม่มีตารางงาน)</span>
                        <?php endif; ?>
                    </span>
                </div>
                <label class="col-form-label fw-bold text-success text-end mt-2 mt-sm-0" style="font-size: 0.9rem;">
                    เลือกวันลา (<?php echo $currentDate->format('M Y'); ?>)
                </label>
            </div>

            <?php if ($global_leave_is_blocked): ?>
                <div class="alert alert-danger p-2 small mt-2">
                    <i class="fa fa-ban me-1"></i> <?php echo htmlspecialchars($block_message); ?>
                </div>
            <?php endif; ?>

            <div id="dayPillContainer" class="row g-1 scroll-container mb-4">
                <?php foreach ($datesInMonth as $d): ?>
                    <div class="col">
                        <span class="day-pill w-100 
                              <?php echo ($d['full'] === $filter_date) ? 'active bg-success text-white' : 'bg-light'; ?>
                              <?php echo ($d['isDisabledBySchedule']) ? 'disabled-schedule' : ''; ?>"
                              data-date="<?php echo htmlspecialchars($d['full']); ?>">
                            <?php echo $d['dayName']; ?><br><span class="fs-6"><?php echo $d['dayNum']; ?></span>
                            <span class="d-block mt-1 small"><?php echo htmlspecialchars($d['scheduleType']); ?></span>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <hr>

            <div class="row mb-4 align-items-center">
                <div class="col-12 col-md-4 mb-2 mb-md-0">
                    <label for="leaveTypeSelect" class="col-form-label fw-bold text-primary">
                        <i class="fa fa-briefcase me-1"></i> ประเภทการลา:
                    </label>
                    <select class="form-select form-select-sm" id="leaveTypeSelect" name="leave_type" required>
                        <option value="">-- เลือกประเภทการลา --</option>
                        <?php foreach ($leaveTypes as $id => $info): ?>
                            <option value="<?php echo htmlspecialchars($id); ?>"
                                <?php echo ((string)$id === (string)$selected_leave_type) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($info['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-12 col-md-8">
                     <label class="col-form-label fw-bold text-success">
                        <i class="fa fa-user-check me-1"></i> ผู้มาทำงานแทนที่เลือก (ไม่บังคับ):
                    </label>
                    <div id="substituteDisplay" class="border p-2 rounded bg-light fw-bold text-dark" style="font-size: 1rem;">
                        <?php echo htmlspecialchars($selected_substitute_name); ?>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-secondary mb-0"><i class="fa fa-list me-1"></i> เลือกผู้มาทำงานแทนสำหรับวันที่ <?php echo htmlspecialchars($filter_dmy); ?></h5>
                
                <!-- Team Filter Dropdown -->
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
            </div>

            <div class="row g-1 mb-4"> 
                <?php if (!$is_requester_scheduled_to_work): ?>
                    <div class="col-12">
                        <div class="alert alert-success small" role="alert">
                            <i class="fa fa-info-circle me-1"></i> ไม่จำเป็นต้องเลือกผู้มาทำงานแทน เนื่องจากคุณไม่มีตารางงานในวันนี้
                        </div>
                    </div>
                <?php elseif (!empty($available_substitutes)): ?>
                    <?php foreach ($available_substitutes as $sub): ?>
                        <?php 
                            $is_selected = (string)($sub['employee_id'] ?? null) === (string)$selected_substitute_emp_id;
                            $is_consecutive = $sub['consecutive_warning'] ?? false;
                            $is_overlap = $sub['overlap_warning'] ?? false;
                            
                            // ถ้า Overlap จะถือว่าเป็น Disabled "ยกเว้น" ผู้ใช้อยู่ในโหมด Manage
                            $is_disabled = (!$is_manage_mode && $is_overlap); 
                            
                            // Warning สีส้ม แสดงกรณี consecutive หรือมี Overlap แต่ถูก Bypass ด้วย Manage Mode
                            $is_warning = ($is_consecutive || ($is_manage_mode && $is_overlap)) && !$is_disabled;

                            // สรุปข้อความ Violation แบบ Format (A)(B) เพื่อให้ JS ดึงไปใช้
                            $violation_reasons = [];
                            if ($is_consecutive) $violation_reasons[] = "ทำงานต่อเนื่องเกิน " . $CONF_MAX_CONSECUTIVE_DAYS . " วัน";
                            if ($is_overlap) $violation_reasons[] = "เวลาทับซ้อน / พักไม่พอ";
                            $violation_text = '';
                            if (!empty($violation_reasons)) {
                                $violation_text = '(' . implode(')(', $violation_reasons) . ')';
                            }
                        ?>
                        <div class="col-3 col-sm-3 col-md-2 col-lg-2 substitute-col"> 
                            <div class="card h-100 card-substitute p-1 
                                        <?php echo $is_selected ? 'selected' : ''; ?> 
                                        <?php echo $is_warning ? 'card-warning' : ''; ?>
                                        <?php echo $is_disabled ? 'disabled' : ''; ?>" 
                                 data-emp-id="<?php echo htmlspecialchars($sub['employee_id']); ?>"
                                 data-emp-name="<?php echo htmlspecialchars($sub['fullname']); ?>"
                                 data-violation-msg="<?php echo htmlspecialchars($violation_text); ?>">
                                <div class="card-body p-1 text-center d-flex flex-column justify-content-start">
                                    <h6 class="card-title mb-0 fw-bold text-dark text-truncate" style="font-size: 0.75rem;">
                                        <?php echo htmlspecialchars($sub['fullname']); ?>
                                    </h6>
                                    
                                    <div class="mt-1 d-flex flex-wrap justify-content-center gap-1">
                                        <?php if ($is_overlap): ?>
                                            <span class="badge bg-danger" style="font-size: 0.6rem;" title="เวลาทับซ้อน / พักไม่พอ"><i class="fa fa-business-time"></i> Overlap</span>
                                            <?php if ($is_manage_mode) echo '<span class="badge bg-warning text-dark" style="font-size: 0.6rem;">Bypass</span>'; ?>
                                        <?php elseif ($is_consecutive): ?>
                                            <span class="badge bg-danger" style="font-size: 0.6rem;" title="ทำงานต่อเนื่องเกินกำหนด"><i class="fa fa-exclamation-triangle"></i> > 6 Days</span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- ส่วนแสดงสถานะและเวลาทำงาน -->
                                    <div class="mt-1">
                                        <?php if ($sub['current_status'] === 'No Shift' || $sub['current_status'] === 'No Shift'): ?>
                                            <span class="text-muted text-truncate" style="font-size: 0.6rem;">
                                                <?php echo htmlspecialchars($sub['email'] ?? 'N/A'); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge <?php echo ($sub['current_status'] === 'OFF') ? 'bg-info' : 'bg-warning text-dark'; ?>" style="font-size: 0.6rem;">
                                                <?php echo htmlspecialchars($sub['current_status']); ?>
                                            </span>
                                            <?php if (!empty($sub['time_range'])): ?>
                                                <div class="text-muted" style="font-size: 0.6rem;">
                                                    <?php echo htmlspecialchars($sub['time_range']); ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>

                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-12">
                        <div class="alert alert-info small" role="alert">
                            <i class="fa fa-info-circle me-1"></i> ไม่พบผู้ที่ว่างงาน (OFF/LEAVE) หรือผู้ที่ทำงานคนละกะกับคุณในทีม <?php echo htmlspecialchars($selected_team_filter); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <button type="button" 
               class="btn btn-lg w-100 mt-4" 
               id="openLeaveModalButton"
               data-bs-target="#confirmLeaveModal"
               <?php echo $global_leave_is_blocked ? 'disabled btn-danger' : 'btn-success'; ?>>
                <i class="fa fa-paper-plane me-1"></i> ยื่นคำขอลา
            </button>
            
        </form>
    </div>

    <!-- Confirmation Modal HTML -->
    <div class="modal fade" id="confirmLeaveModal" tabindex="-1" aria-labelledby="confirmLeaveModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <form id="leaveConfirmForm" method="POST" action="request_process.php">
            <div class="modal-header bg-success text-white">
              <h5 class="modal-title" id="confirmLeaveModalLabel"><i class="fa fa-info-circle me-2"></i> ยืนยันคำขอลา</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-primary fw-bold text-center">กรุณาตรวจสอบข้อมูลก่อนยืนยัน</p>

                <div class="row pb-2 mb-2">
                    <div class="col-12 mb-2">
                        <strong>วันที่ลา:</strong> <span class="text-danger fw-bold ms-2"><?php echo htmlspecialchars($filter_dmy); ?></span>
                    </div>
                    <div class="col-12 mb-2">
                        <strong>ประเภท:</strong> <span id="modalLeaveTypeDisplay" class="text-success fw-bold ms-2"></span>
                    </div>
                    <div class="col-12 mb-2">
                        <strong>ผู้แทน:</strong> <span id="modalSubstituteName" class="text-info fw-bold ms-2"></span>
                    </div>
                    <!-- Warning ถ้าไม่มีคนแทน -->
                    <div id="noSubstituteWarning" class="col-12 mb-2 d-none">
                        <div class="alert alert-warning small mb-0 py-1">
                            <i class="fa fa-exclamation-circle me-1"></i> <strong>แจ้งเตือน:</strong> คุณไม่ได้เลือกผู้มาทำงานแทน ระบบจะระบุว่า "(ไม่มีคนแทน)" ในเหตุผลการลาอัตโนมัติ
                        </div>
                    </div>

                    <!-- Added Comment Field -->
                    <div class="col-12 mt-2">
                        <label for="commentInput" class="form-label fw-bold text-danger">เหตุผลการลา (จำเป็นต้องระบุ):</label>
                        <textarea class="form-control border-danger" name="comment" id="commentInput" rows="2" placeholder="ระบุเหตุผล..." required></textarea>
                    </div>
                </div>
                
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="leave">
                <input type="hidden" name="manage_mode" value="<?php echo $is_manage_mode ? '1' : '0'; ?>">
                <input type="hidden" name="date" id="formLeaveDate" value="<?php echo htmlspecialchars($filter_date); ?>">
                <input type="hidden" name="leave_type" id="formLeaveType" value="">
                <input type="hidden" name="substitute_emp_id" id="formSubstituteEmpId" value="">
                
                <input type="hidden" name="schedule_id" value="<?php echo htmlspecialchars($current_date_schedule_id ?? ''); ?>">
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
              <button type="submit" class="btn btn-success btn-sm"><i class="fa fa-check-circle me-1"></i> ยืนยันการลา</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script>
    $(document).ready(function() {
        const $dateHidden = $('#dateHidden');
        const $dateForm = $('#leaveForm');
        const $dayPills = $('.day-pill');
        const $leaveTypeSelect = $('#leaveTypeSelect');
        const $teamFilterSelect = $('#teamFilter');
        const $openLeaveModalButton = $('#openLeaveModalButton');
        const $substituteEmpId = $('#substituteEmpId');
        const $substituteCards = $('.card-substitute');

        $('#loading-overlay').fadeOut('fast');

        function updateSubmitButtonState() {
            const isLeaveTypeSelected = $leaveTypeSelect.val() !== "";
            const isBlocked = <?php echo $global_leave_is_blocked ? 'true' : 'false'; ?>;
            
            const canSubmit = isLeaveTypeSelected && !isBlocked;
            $openLeaveModalButton.prop('disabled', !canSubmit);
            
            if (canSubmit) {
                $openLeaveModalButton.removeClass('btn-secondary btn-danger').addClass('btn-success').text('ยื่นคำขอลา').attr('data-bs-toggle', 'modal');
            } else {
                $openLeaveModalButton.removeClass('btn-success btn-danger').addClass('btn-secondary').attr('data-bs-toggle', '');
                
                if (isBlocked) {
                    $openLeaveModalButton.removeClass('btn-secondary').addClass('btn-danger').text('Blocked');
                } else if (!isLeaveTypeSelected) {
                    $openLeaveModalButton.text('กรุณาเลือกประเภทการลา');
                }
            }
        }
        
        function submitFormWithUpdates(newDate = null) {
            const currentFilterDate = (newDate) ? newDate : $dateHidden.val();
            
            $('#loading-overlay').fadeIn('fast');
            const tempForm = $('<form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>"></form>');
            
            // Basic Inputs
            $dateForm.find('input[name="csrf_token"]').each(function() { tempForm.append($(this).clone()); });
            $dateForm.find('input[name="req"]').each(function() { tempForm.append($(this).clone()); });
            $dateForm.find('input[name="user_id"]').each(function() { tempForm.append($(this).clone()); });
            $dateForm.find('input[name="schedule_id"]').each(function() { tempForm.append($(this).clone()); });
            $dateForm.find('input[name="manage_mode"]').each(function() { tempForm.append($(this).clone()); });
            
            // Dynamic Values
            tempForm.append('<input type="hidden" name="date" value="' + currentFilterDate + '">');
            tempForm.append('<input type="hidden" name="leave_type" value="' + $leaveTypeSelect.val() + '">');
            tempForm.append('<input type="hidden" name="team_filter" value="' + $teamFilterSelect.val() + '">');
            
            // Substitute 
            $dateForm.find('input[name="substitute_emp_id"]').clone().appendTo(tempForm);
            
            $('body').append(tempForm);
            tempForm.submit();
        }

        $dayPills.on('click', function() {
            const newDate = $(this).data('date');
            if ($dateHidden.val() !== newDate) {
                // When changing date, we generally reset substitute because availability changes
                $substituteEmpId.val(''); 
                submitFormWithUpdates(newDate);
            }
        });
        
        $leaveTypeSelect.on('change', function() {
            submitFormWithUpdates();
        });

        $teamFilterSelect.on('change', function() {
            // Reset substitute when changing teams to avoid confusion
            $substituteEmpId.val(''); 
            submitFormWithUpdates();
        });

        $substituteCards.on('click', function() {
            if ($(this).hasClass('disabled')) return; // ป้องกันการกดเลือกถ้า Disabled

            const empId = $(this).data('emp-id');
            const empName = $(this).data('emp-name');
            
            if ($(this).hasClass('selected')) {
                $substituteCards.removeClass('selected');
                $substituteEmpId.val('');
                $('#substituteDisplay').text('ยังไม่เลือก');
            } else {
                $substituteCards.removeClass('selected');
                $(this).addClass('selected');
                $substituteEmpId.val(empId);
                $('#substituteDisplay').text(empName);
            }
            updateSubmitButtonState();
        });
        
        $('#confirmLeaveModal').on('show.bs.modal', function () {
            const selectedTypeText = $leaveTypeSelect.find('option:selected').text();
            let selectedSubstituteName = $('#substituteDisplay').text().trim();
            const subId = $substituteEmpId.val();

            // Check if no substitute is selected
            if (!subId || selectedSubstituteName === 'ยังไม่เลือก') {
                 selectedSubstituteName = 'ไม่มี (ไม่บังคับ)';
                 $('#noSubstituteWarning').removeClass('d-none'); // Show Warning
            } else {
                 $('#noSubstituteWarning').addClass('d-none'); // Hide Warning
            }
            
            $('#modalLeaveTypeDisplay').text(selectedTypeText);
            $('#modalSubstituteName').text(selectedSubstituteName);
            
            $('#formLeaveType').val($leaveTypeSelect.val());
            $('#formSubstituteEmpId').val(subId);
            $('#commentInput').val(''); // Clear previous comment
        });

        // Form Submission Handler (Auto-Append Reason & Violations)
        $('#leaveConfirmForm').on('submit', function(e) {
            const comment = $('#commentInput').val().trim();
            if (comment === "") {
                e.preventDefault();
                alert("กรุณาระบุเหตุผลการลา");
                $('#commentInput').focus();
                return;
            }

            const subId = $('#formSubstituteEmpId').val();
            let appendedText = "";

            if (!subId) {
                // ไม่มีคนแทน
                appendedText = " (ไม่มีคนแทน)";
            } else {
                // มีคนแทน ให้ดึงข้อความแจ้งเตือนเงื่อนไขของการ์ดที่ถูกเลือกมาด้วย
                const selectedViolation = $('.card-substitute.selected').data('violation-msg');
                if (selectedViolation) {
                    appendedText = " " + selectedViolation; // ข้อความถูกจัด format เป็น (A)(B) มาจาก PHP แล้ว
                }
            }
            
            // รวมเหตุผลที่กรอกเองกับข้อความระบบอัตโนมัติ
            $('#commentInput').val(comment + appendedText);
        });
        
        updateSubmitButtonState();
    });
    </script>
</body>
</html>
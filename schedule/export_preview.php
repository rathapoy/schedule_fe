<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
checkLogin();

$userlogin = isset($_SESSION["user_data"]) ? $_SESSION["user_data"] : null;
if (!$userlogin) {
    header("Location: /auth/logon.php");
    exit();
}

// โหลดค่า Filter จาก Session ที่หน้า schedule.php บันทึกไว้
if (!isset($_SESSION['sch_filters'])) {
    header("Location: schedule.php"); // ถ้าไม่มี session ให้กลับไปหน้าหลัก
    exit();
}

$selected_month = $_SESSION['sch_filters']['month'];
$selected_year  = $_SESSION['sch_filters']['year'];
$selected_team  = $_SESSION['sch_filters']['team'];
$selected_shift = $_SESSION['sch_filters']['shift'];
$display_type   = $_SESSION['sch_filters']['display_type'];
$selected_emp   = $_SESSION['sch_filters']['emp_filter'];

$selected_emp_array = ($selected_emp != '' && $selected_emp != 'all') ? explode(',', $selected_emp) : [];
$can_manage_schedule = hasPermission('schedule.management');

$timestamp = mktime(0, 0, 0, $selected_month, 1, $selected_year);
$days_in_month = date('t', $timestamp);
$YearMonth = $selected_year."-".str_pad($selected_month, 2, '0', STR_PAD_LEFT);

// --- API Calls ---
$monthConfigUrl = "/schedule/month/config?action=get&monthyear=" . $YearMonth;
$monthConfig = callApi($monthConfigUrl);
$team_configs = [];
if(isset($monthConfig['status']) && $monthConfig['status'] === 'success' && isset($monthConfig['data'])){
    foreach ($monthConfig['data'] as $cfg) {
        $t_name = $cfg['team_config'] ?? '';
        if ($t_name) $team_configs[$t_name] = $cfg;
    }
}

$show_schedule_content = true;
$block_reason = "";
if ($selected_team && $selected_team != 'all') {
    $current_team_config = $team_configs[$selected_team] ?? null;
    if (!$can_manage_schedule) {
        if (!$current_team_config || empty($current_team_config['config_id'])) {
            $show_schedule_content = false;
            $block_reason = "Schedule for {$selected_team} has not been initialized yet.";
        } elseif ($current_team_config['is_enabled'] == 0) {
            $show_schedule_content = false;
            $block_reason = "Schedule for {$selected_team} is currently in draft mode.";
        }
    }
}

if (!$show_schedule_content) {
    // ถ้าไม่มีสิทธิ์เข้าถึง ให้เด้ง Alert แจ้งเตือนแล้วกลับไปหน้า schedule
    echo "<script>alert('Access Denied: {$block_reason}'); window.location.href='schedule.php';</script>";
    exit();
}

$schedule_data = [];
$scheduleapi_url = "/schedule/monthschedule?action=get&month=" . $YearMonth;
$result_schedule = callApi($scheduleapi_url);
if(isset($result_schedule['status']) && $result_schedule['status'] === 'success' && isset($result_schedule['data'])){
    $schedule_data = $result_schedule['data'];
}

$userapi_url = "/data/schedule?year_month=" . $YearMonth;
$result_user = callApi($userapi_url);
$users = isset($result_user['data']) ? $result_user['data'] : [];

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
$user_list = [];
foreach ($users as $user_item) {
    $user_id = $user_item['user_id'];
    $user_name = ($user_item['thai_initialname'] ?? '') . ($user_item['thai_firstname'] ?? '') . ' ' . ($user_item['thai_lastname'] ?? '');
    $team_name = $user_item['team'] ?? 'N/A';
    $shift_name = $user_item['shift_name'] ?? 'N/A';

    if ($selected_team && $selected_team != 'all' && $team_name != $selected_team) continue;
    if ($selected_shift && $selected_shift != 'all' && $shift_name != $selected_shift) continue;
    
    if (!$can_manage_schedule) {
        $u_cfg = $team_configs[$team_name] ?? null;
        if (!$u_cfg || $u_cfg['is_enabled'] == 0) continue; 
    }

    if (!empty($selected_emp_array) && !in_array($user_id, $selected_emp_array)) continue;

    $user_list[$user_id] = [
        'name' => $user_name,
        'team' => $team_name,
        'employee_id' => $user_item['employee_id'] ?? 'N/A',
        'shift_id' => $user_item['shift_id'] ?? 'N/A'
    ];
}

$api_url = "/schedule/shift?action=get";
$result = callApi($api_url);
$shifts = $result['data'] ?? [];

$holiday_url = "/schedule/holiday?action=get";
$holiday_data = callApi($holiday_url);
$holiday_map = [];
foreach (($holiday_data['data'] ?? []) as $holiday) {
    $holiday_map[$holiday['date']] = $holiday['description'];
}

// จัดกลุ่ม User ตามกะการทำงาน
$users_by_shift = [];
foreach ($user_list as $user_id => $user) { 
    $shift_id = $user['shift_id'] ?? 'N/A'; 
    $users_by_shift[$shift_id][$user_id] = $user; 
}
$ordered_shifts = [];
foreach ($shifts as $shift) $ordered_shifts[$shift['shift_id']] = $shift['shift_name'];
foreach (array_keys($users_by_shift) as $sid) { if (!isset($ordered_shifts[$sid])) $ordered_shifts[$sid] = 'Unknown'; }
ksort($ordered_shifts);


// ==========================================
// บังคับดาวน์โหลดไฟล์ Excel ทันที
// ==========================================
if (ob_get_length()) ob_clean();

$filename = "schedule_{$selected_year}_{$selected_month}.xls";
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
echo '<head><meta http-equiv="content-type" content="text/html; charset=UTF-8" /></head><body>';

echo '<table border="1" style="border-collapse: collapse; font-family: Arial, sans-serif; font-size: 12px; width:100%;">';
echo '<thead>';

// เพิ่มแถวแรก สำหรับแสดงชื่อเดือนและปี
$total_columns = 4 + $days_in_month;
$full_month_year = date("F Y", $timestamp); // เช่น "March 2024"
echo '<tr>';
echo '<th colspan="' . $total_columns . '" style="font-size: 16px; font-weight: bold; text-align: center; padding: 10px; background-color: #e2e3e5;">Schedule : ' . $full_month_year . '</th>';
echo '</tr>';

echo '<tr>';
echo '<th style="background-color:rgb(221, 218, 218); color: #000000; width: 40px; font-weight: bold; text-align:center; vertical-align:top;">No.</th>';
echo '<th style="background-color:rgb(221, 218, 218); color: #000000; width: 200px; font-weight: bold; text-align:left; vertical-align:top;">Name</th>';
echo '<th style="background-color:rgb(221, 218, 218); color: #000000; width: 80px; font-weight: bold; text-align:center; vertical-align:top;">Team</th>';
echo '<th style="background-color:rgb(221, 218, 218); color: #000000; width: 80px; font-weight: bold; text-align:left; vertical-align:top;">Emp ID</th>';

for ($d = 1; $d <= $days_in_month; $d++) {
    $date_str = $selected_year . '-' . str_pad($selected_month, 2, '0', STR_PAD_LEFT) . '-' . str_pad($d, 2, '0', STR_PAD_LEFT);
    $day_of_week = date('D', strtotime($date_str));
    $is_weekend = ($day_of_week == 'Sat' || $day_of_week == 'Sun');
    $is_holiday = isset($holiday_map[$date_str]);
    
    // สีพื้นหลังส่วนหัวของวันที่
    $bg_color_inline = ($is_weekend || $is_holiday) ? 'background-color: #F79797;' : 'background-color:rgb(115, 151, 105);';
    
    echo "<th style=\"$bg_color_inline color: #000000; text-align: center; vertical-align:top; width: 40px;\">{$d}<br><small>{$day_of_week}</small></th>";
}
echo '</tr></thead><tbody>';

$no = 1;
foreach ($ordered_shifts as $shift_id => $shift_name) {
    if (!isset($users_by_shift[$shift_id])) continue;
    
    // Group Header
    echo "<tr><td colspan=\"" . (4 + $days_in_month) . "\" style=\"background-color: #EEEEEE; font-weight: bold; text-align: left; padding: 5px;\">Shift: " . htmlspecialchars($shift_name) . "</td></tr>";

    foreach ($users_by_shift[$shift_id] as $user_id => $user) {
        echo '<tr>';
        echo "<td style=\"text-align: center; vertical-align: middle;\">" . $no++ . "</td>";
        echo "<td style=\"vertical-align: middle;\">" . htmlspecialchars($user['name']) . "</td>";
        echo "<td style=\"text-align: center; vertical-align: middle;\">" . htmlspecialchars($user['team']) . "</td>";
        echo "<td style=\"text-align: center; vertical-align: middle; mso-number-format:'@';\">" . htmlspecialchars($user['employee_id']) . "</td>";
        
        for ($d = 1; $d <= $days_in_month; $d++) {
            $daily_schedules = $grouped_schedule[$user_id][$d] ?? [];
            
            $date_full = $selected_year . '-' . str_pad($selected_month, 2, '0', STR_PAD_LEFT) . '-' . str_pad($d, 2, '0', STR_PAD_LEFT);
            $day_of_week = date('D', strtotime($date_full));
            $is_weekend = ($day_of_week == 'Sat' || $day_of_week == 'Sun');
            $is_holiday = isset($holiday_map[$date_full]);
            
            // กำหนดสีพื้นหลังเริ่มต้น (สำหรับวันหยุด/เสาร์อาทิตย์)
            $td_bg_color = ($is_weekend || $is_holiday) ? '#ffffff' : '';
            $td_text_color = '#000000';
            $contents = [];

            if (count($daily_schedules) > 0) {
                // ดึงข้อมูลกะแรกในวันนั้นมากำหนดสีพื้นหลังให้กับ Cell ของ Excel
                $first_sch = $daily_schedules[0];
                $priority = $first_sch['priority'] ?? 0;
                $status = $first_sch['status'] ?? '';
                $color_value = htmlspecialchars($first_sch['color'] ?? '#cccccc');

                $td_bg_color = $color_value;
                $td_text_color = (strtolower($color_value) == '#34495e') ? '#ffffff' : '#333333';

                if ($priority >= 1 && $status === 'Requested') {
                    $td_bg_color = '#f8d7da';
                    $td_text_color = '#721c24';
                } elseif ($status === 'Pending' || $status === 'Standby' || $status === 'Accept') {
                    $td_bg_color = '#cce5ff';
                    $td_text_color = '#004085';
                } elseif ($status === 'OT') {
                    $td_bg_color = '#F7FFCC';
                    $td_text_color = '#000000';
                }

                // จัดการข้อความของทุกกะในวันนั้น ให้อยู่ใน Cell เดียวกัน
                foreach ($daily_schedules as $sch) {
                    $shift_type = htmlspecialchars($sch['type_name'] ?? 'N/A');
                    $group_name = htmlspecialchars($sch['work_group'] ?? $shift_type);
                    $sch_priority = $sch['priority'] ?? 0;
                    $sch_status = $sch['status'] ?? '';
                    
                    $block_content = ($display_type == 'group') ? $group_name : $shift_type;

                    if ($sch_priority >= 1 && $sch_status === 'Requested') {
                        // คงข้อความเดิม
                    } elseif ($sch_status === 'Pending') {
                        $block_content = '[P]';
                    } elseif ($sch_status === 'Standby') {
                        $block_content = '[S]';
                    } elseif ($sch_status === 'Accept') {
                        $block_content = '[A]';
                    } elseif ($sch_status === 'OT') {
                        $block_content = 'O'.($display_type == 'group' ? $group_name : $shift_type);
                    }
                    
                    $contents[] = "<strong>{$block_content}</strong>";
                }
            }
            
            // สร้าง CSS Inline Style สำหรับ <td> โดยตรง
            $style_str = "text-align: center; vertical-align: middle; padding: 2px;";
            if ($td_bg_color !== '') {
                $style_str .= " background-color: {$td_bg_color}; color: {$td_text_color};";
            }

            echo "<td style=\"{$style_str}\">";
            echo implode("<br>", $contents);
            echo "</td>";
        }
        echo '</tr>';
    }
}
echo '</tbody></table>';

echo '</body></html>';
exit();
?>
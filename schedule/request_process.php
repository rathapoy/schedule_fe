<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
checkLogin();
$token = isset($_COOKIE["token"]) ? $_COOKIE["token"] : null;
$userlogin = isset($_SESSION["user_data"]) ? $_SESSION["user_data"] : null;
$redirectUrl = 'schedule.php';

// =========================================================================
// --- CONFIGURATIONS (ต้องตรงกับหน้า Swap) ---
// =========================================================================
$CONF_CHECK_OVERLAP = true;
$CONF_CHECK_MIN_REST = true;
$CONF_MIN_REST_HOURS = 8;
// =========================================================================

// CSRF Check
if (!isset($_POST['csrf_token'], $_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die("Error: Invalid CSRF token!");
}

$noprocess = true;
$redirect_message = true;
$manage_mode = $_POST['manage_mode'] ?? '0';
if ($manage_mode === '1' && !hasPermission('schedule.management')) {
    echo "Error: Access denied.\n"; exit;
}

$url = "";
$action = $_POST['action'] ?? null;
$req = $_POST['req'] ?? null; 
$requester_schedule_status = $_POST['requester_schedule_status'] ?? null;

$userMap = [];
$userApiUrl = "/data/schedule"; 
$userApiData = callApi($userApiUrl);
if (isset($userApiData['data'])) {
    foreach ($userApiData['data'] as $u) {
        $fname = $u['thai_firstname'] ?? $u['eng_firstname'] ?? '-';
        $lname = $u['thai_lastname'] ?? $u['eng_lastname'] ?? '-';
        $userMap[$u['user_id']] = trim("$fname");
    }
}

function getUserName($id, $map) {
    return $map[$id] ?? '-';
}

function getScheduleInfoFromPost(string $postKey): ?array
{
    $schedule_id = filter_input(INPUT_POST, $postKey, FILTER_VALIDATE_INT);
    if (!$schedule_id) return null;

    $requestUrl  = "/schedule/monthschedule?schedule_id=" . $schedule_id;
    $response    = callApi($requestUrl);

    if (isset($response['status'], $response['data']) && $response['status'] === 'success' && !empty($response['data'][0])) {
        return $response['data'][0];
    }
    return null;
}

// --- Helper Function: Check Overlap & Rest ---
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

    // 1. Check Overlap with PREVIOUS Day
    $prev_date = date('Y-m-d', strtotime('-1 day', $target_ts));
    if (isset($schedule_map[(string)$emp_id][$prev_date]) && $schedule_map[(string)$emp_id][$prev_date]['status'] === 'WORKING') {
        $prev_shift = $schedule_map[(string)$emp_id][$prev_date];
        $prev_start = $prev_shift['start_time'];
        $prev_end = $prev_shift['end_time'];
        
        $prev_ts = strtotime($prev_date);
        $prev_end_abs = $prev_ts + $prev_end;
        if ($prev_end < $prev_start) $prev_end_abs += 86400;
        
        if ($check_overlap && $prev_end_abs > $new_start_abs) return true;
        if ($check_rest && $prev_end_abs <= $new_start_abs && ($new_start_abs - $prev_end_abs) < $min_rest_sec) return true;
    }

    // 2. Check Overlap with NEXT Day
    $next_date = date('Y-m-d', strtotime('+1 day', $target_ts));
    if (isset($schedule_map[(string)$emp_id][$next_date]) && $schedule_map[(string)$emp_id][$next_date]['status'] === 'WORKING') {
        $next_shift = $schedule_map[(string)$emp_id][$next_date];
        
        $next_start = $next_shift['start_time'];
        $next_ts = strtotime($next_date);
        $next_start_abs = $next_ts + $next_start;
        
        if ($check_overlap && $new_end_abs > $next_start_abs) return true;
        if ($check_rest && $new_end_abs <= $next_start_abs && ($next_start_abs - $new_end_abs) < $min_rest_sec) return true;
    }

    return false;
}

$insertData = [];

// ==========================================================================================
// 1. ACTION: SWAP (Create Request)
// ==========================================================================================
if (isset($action) && $action === "swap") {
    $noprocess = false;
    $requester = getScheduleInfoFromPost('requester_schedule_id');
    $replace   = getScheduleInfoFromPost('target_schedule_id');

    if (!$requester || !$replace) {
        $noprocess = true; $status = 'error'; $message = 'Invalid schedule data';
    } else {
        $requesterSID = $requester['schedule_id'];
        $requesterUID = $requester['user_id'];
        $replaceSID   = $replace['schedule_id'];
        $replaceUID   = $replace['user_id'];
        
        // รับ comment ที่อาจมีชื่อคนติดเงื่อนไขต่อท้ายมาจากหน้า JS
        $comment = $_POST['comment'] ?? " ";
        $remark = $requester["thai_firstname"]." สลับกะ ".$replace["thai_firstname"]." | ".$comment ;

        if ($requester_schedule_status == 'OT' && $manage_mode){
            callApi("/schedule/monthschedule?action=confirm_swap", "POST", [ "schedule_id" => $replaceSID , "user_id" => 1, "status" => 'OT' ]);
            callApi("/schedule/monthschedule?action=confirm_swap", "POST", [ "schedule_id" => $requesterSID, "user_id" => $replaceUID, "status" => 'OT' ]);
            callApi("/schedule/monthschedule?action=confirm_swap", "POST", [ "schedule_id" => $replaceSID , "user_id" => $requesterUID, "status" => 'OT' ]);
            $status = 'success'; $message = 'OT Swap success. id : '.$requesterSID.' and '.$replaceSID;
        } else {
            // Update Requester -> Pending
            callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $requesterSID, "status" => "Pending", "remark" => $remark  ]);

            // Update Target -> Standby
            callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $replaceSID, "status" => "Standby", "remark" => $remark ]);

            // Create Request
            $url = "/api/request?action=add";
            $insertData = [
                "schedule_id"        => $requesterSID,
                "target_schedule_id" => $replaceSID ?? null,
                "request_type_id"    => 1, // Swap
                "request_user_id"    => $requesterUID,
                "user_replace_id"    => $replaceUID ?? null,
                "request_reason"     => $comment,
                "approver_user_id"   => $requester['approver_id'] ?? null, 
                "request_status"     => "Pending"
            ];
        }
    }
}

// ==========================================================================================
// 2. ACTION: LEAVE (Create Request)
// ==========================================================================================
elseif (isset($action) && $action === "leave"){
    $noprocess = false;
    $schInfo = getScheduleInfoFromPost('schedule_id');
    $reqTInfoUrl = "/api/request_type?reqid=".$_POST["leave_type"];
    $reqTname = callApi($reqTInfoUrl);
    $reqTname = $reqTname["data"] ?? [];

    if ($schInfo) {
        $comment = $_POST['comment'] ?? "Leave";
        // Update schedule to Pending
        callApi("/schedule/monthschedule?action=change_status", "POST", [
            "schedule_id" => $schInfo["schedule_id"],
            "status"      => 'Pending',
            "remark"      => ($reqTname["request_type_name"] ?? 'Leave') . " - รออนุมัติ | ".$comment
        ]);
        // echo "<pre>";
        // print_r($_POST);
        // print_r($subEmpId);
        // echo "</pre>";
        // exit;


        // Create schedule for replace (if any)
        $subEmpId = $_POST['substitute_emp_id'] ?? '';
        $replaceUID = null;
        $target_schedule_id = null;
        // echo "<pre>";
        //     print_r($_POST);
        //     print_r($subEmpId);
        //     echo "</pre>";
        //     exit;
        if (!empty($subEmpId)) {
            $userurl = "/data/".$_POST["substitute_emp_id"];
            $replaceInfo =  callApi($userurl);
            $replaceUID = (int) ($replaceInfo['data'][0]['user_id'] ?? 0);
            $replaceName = $replaceInfo["data"][0]["thai_firstname"] ?? 'Unknown';
            
            $remark = $replaceName." Standby แทน".$schInfo["thai_firstname"]." ".($reqTname["request_type_name"] ?? '');
            
            // Create Standby Schedule for Substitute
            $insertDataRep = [
                'user_id'          => $replaceUID,
                'work_group_id'    => $schInfo["work_group_id"] ?? NULL,
                'work_schedule_id' => $schInfo["work_schedule_id"] ?? NULL, 
                'schedule_date'    => $schInfo["schedule_date"],
                'status'           => 'Standby',
                'remark'           => $remark                                                
            ];
            callApi("/api/update/schedule", "POST", [$insertDataRep]);
            
            // Get the newly created schedule ID
            $scheduleUrl = "/schedule/monthschedule?user_id=" . (int)$replaceUID . "&schedule_date=" . urlencode($schInfo["schedule_date"]) . "&schedule_status=Standby";
            $response    = callApi($scheduleUrl);
            $target_schedule_id = $response["data"][0]["schedule_id"] ?? null;
            
        }

        // Add Request
        
        $url = "/api/request?action=add";
        $insertData = [
            "schedule_id"        => $schInfo["schedule_id"],
            "target_schedule_id" => $target_schedule_id, 
            "request_type_id"    => $_POST["leave_type"],
            "request_user_id"    => $schInfo["user_id"],
            "user_replace_id"    => !empty($replaceUID) ? $replaceUID : null,
            "request_reason"     => $comment,
            "approver_user_id"   => $schInfo["approver_id"] ?? null,
            "request_status"     => "Pending"
        ];
    }
}

// ==========================================================================================
// 3. REQ: CONFIRM (From Schedule Modal - by Target User/Substitute)
// ==========================================================================================
elseif (isset($req) && $req === "confirm"){
    $noprocess = false;
    $TargetSID = filter_input(INPUT_POST, "schedule_id", FILTER_VALIDATE_INT); // My ID (Standby)
    
    $dataInput = [ "schedule_id" => $TargetSID ];
    $requestInfo = callApi("/api/request?action=gettarget", "POST", $dataInput);
    
    if (isset($requestInfo["data"][0])) {
        $reqData = $requestInfo["data"][0];
        $requesterSID = $reqData["schedule_id"];
        $targetSID = $reqData["target_schedule_id"]; 
        $requesterUID = $reqData["request_user_id"];
        $replaceUID   = $reqData["user_replace_id"];
        $requestType  = $reqData["request_type_id"]; 
        $requestUser = getUserName($requesterUID, $userMap);
        $targetUser = getUserName($replaceUID, $userMap);

        if ($requestType == 1) { // SWAP
            
            // --- SIMULATE OVERLAP & REST ---
            $needApproval = false;
            
            // 1. ดึงข้อมูลกะงานที่จะสลับ
            $reqSchApi = callApi("/schedule/monthschedule?schedule_id=" . $requesterSID);
            $tarSchApi = callApi("/schedule/monthschedule?schedule_id=" . $targetSID);
            
            if (isset($reqSchApi['data'][0], $tarSchApi['data'][0])) {
                $rS = $reqSchApi['data'][0]; // Requester's Shift
                $tS = $tarSchApi['data'][0]; // Target's Shift
                
                // 2. เช็คเงื่อนไขวันเดียวกัน (Same Day)
                if ($rS['schedule_date'] == $tS['schedule_date']) {
                    $needApproval = true;
                }

                // 3. ถ้ายังไม่ติดเงื่อนไข -> เช็ค Overlap / Rest Time
                if (!$needApproval) {
                     $sim_map = [];
                     
                     $dates_to_fetch = [
                         $rS['schedule_date'], 
                         date('Y-m-d', strtotime($rS['schedule_date'] . ' -1 day')),
                         date('Y-m-d', strtotime($rS['schedule_date'] . ' +1 day')),
                         $tS['schedule_date'], 
                         date('Y-m-d', strtotime($tS['schedule_date'] . ' -1 day')),
                         date('Y-m-d', strtotime($tS['schedule_date'] . ' +1 day'))
                     ];
                     $dates_to_fetch = array_unique($dates_to_fetch);

                     foreach($dates_to_fetch as $d) {
                         $u1_res = callApi("/schedule/monthschedule?action=get&schedule_date=$d&user_id=$requesterUID");
                         if (!empty($u1_res['data'])) {
                             foreach($u1_res['data'] as $item) {
                                 if ($item['schedule_id'] == $requesterSID) continue;
                                 $sim_map[(string)$item['employee_id']][$item['schedule_date']] = [
                                     'status' => 'WORKING',
                                     'start_time' => $item['start_time'],
                                     'end_time' => $item['end_time']
                                 ];
                             }
                         }
                         $u2_res = callApi("/schedule/monthschedule?action=get&schedule_date=$d&user_id=$replaceUID");
                         if (!empty($u2_res['data'])) {
                             foreach($u2_res['data'] as $item) {
                                 if ($item['schedule_id'] == $targetSID) continue; 
                                 $sim_map[(string)$item['employee_id']][$item['schedule_date']] = [
                                     'status' => 'WORKING',
                                     'start_time' => $item['start_time'],
                                     'end_time' => $item['end_time']
                                 ];
                             }
                         }
                     }

                     if (check_time_overlap_and_rest($rS['employee_id'], $tS['schedule_date'], $tS['start_time'], $tS['end_time'], $sim_map, $CONF_CHECK_OVERLAP, $CONF_CHECK_MIN_REST, $CONF_MIN_REST_HOURS)) {
                         $needApproval = true;
                     }

                     if (!$needApproval) {
                         if (check_time_overlap_and_rest($tS['employee_id'], $rS['schedule_date'], $rS['start_time'], $rS['end_time'], $sim_map, $CONF_CHECK_OVERLAP, $CONF_CHECK_MIN_REST, $CONF_MIN_REST_HOURS)) {
                             $needApproval = true;
                         }
                     }
                }
            } else {
                $needApproval = true;
            }

            if ($needApproval) {
                // กรณี Overlap / Same Day 
                // 1. อัปเดตตารางของ Target User เป็น Accept (ยินยอม)
                callApi("/schedule/monthschedule?action=change_status", "POST", [ 
                    "schedule_id" => $TargetSID, 
                    "status" => "Accept", 
                    "remark" => $reqData["request_reason"] // ใช้เหตุผลเดิม
                ]);

                // 2. อัปเดต Request เป็น Pending (รอหัวหน้าอนุมัติ)
                $url = "/api/request?action=update";
                $insertData = [
                    "request_id"           => $reqData["request_id"],
                    "user_replace_confirm" => 1,
                    "date_confirm"         => date("Y-m-d H:i:s"),
                    "request_status"       => "Pending" 
                ];
            } else {
                // กรณีปกติ (Clean Swap) -> สลับเลย
                callApi("/schedule/monthschedule?action=confirm_swap", "POST", [ "schedule_id" => $TargetSID, "user_id" => 1, "status" => 'Normal' ]);
                callApi("/schedule/monthschedule?action=confirm_swap", "POST", [ "schedule_id" => $requesterSID, "user_id" => $replaceUID, "status" => 'Normal' ]);
                callApi("/schedule/monthschedule?action=confirm_swap", "POST", [ "schedule_id" => $TargetSID, "user_id" => $requesterUID, "status" => 'Normal' ]);
                
                $url = "/api/request?action=update";
                $insertData = [
                    "request_id"           => $reqData["request_id"],
                    "user_replace_confirm" => 1,
                    "date_confirm"         => date("Y-m-d H:i:s"),
                    "request_status"       => "Approved"
                ];
            }

        } else { // LEAVE (Substitute Confirm)
            $remark = $targetUser." ทำ OT แทน ".$requestUser." ".$reqData['request_type_name']." (Accepted)";
            callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $targetSID, "status" => "Accept", "remark" => $remark]);

            $url = "/api/request?action=update";
            $insertData = [
                "request_id"           => $reqData["request_id"],
                "user_replace_confirm" => 1,
                "date_confirm"         => date("Y-m-d H:i:s")
            ];
        }
    }
}

// ==========================================================================================
// 4. REQ: REJECT (From Schedule Modal - by Target User/Substitute)
// ==========================================================================================
elseif (isset($req) && $req === "reject"){
    $noprocess = false;
    $TargetSID = filter_input(INPUT_POST, "schedule_id", FILTER_VALIDATE_INT);
    $dataInput = [ "schedule_id" => $TargetSID ];
    $requestInfo = callApi("/api/request?action=gettarget", "POST", $dataInput);
    if (isset($requestInfo["data"][0])) {
        $reqData = $requestInfo["data"][0];
        $requesterSID = $reqData["schedule_id"];
        $requestType  = $reqData["request_type_id"];
        callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $requesterSID, "status" => 'Normal', "remark" => '' ]);
        if ($requestType == 1) { 
            callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $TargetSID, "status" => 'Normal', "remark" => '' ]);
        } else { 
            callApi("/schedule/monthschedule?action=delete&schedule_id=" . $TargetSID, "DELETE");
        }
        $url = "/api/request?action=update";
        $insertData = [ "request_id" => $reqData["request_id"], "request_status" => "Rejected" ];
    }
}
// ==========================================================================================
// 5. REQ: CANCEL (From Schedule Modal - by Requester)
// ==========================================================================================
elseif (isset($req) && $req === "cancel"){
    $noprocess = false;
    $schedule_id = filter_input(INPUT_POST, "schedule_id", FILTER_VALIDATE_INT); 
    $dataInput = [ "schedule_id" => $schedule_id ];
    $requestInfo = callApi("/api/request?action=getinfo", "POST", $dataInput);
    if (isset($requestInfo["data"][0])) {
        $reqData = $requestInfo["data"][0];
        $target_schedule_id = $reqData["target_schedule_id"] ?? null;
        $requestType = $reqData["request_type_id"];
        callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $schedule_id, "status" => 'Normal', "remark" => '' ]);
        if (!empty($target_schedule_id)){
            if ($requestType == 1) { 
                callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $target_schedule_id, "status" => 'Normal', "remark" => '' ]);
            } else { 
                callApi("/schedule/monthschedule?action=delete&schedule_id=" . $target_schedule_id, "DELETE");
            }
        }       
        $url = "/api/request?action=update";
        $insertData = [ "request_id" => $reqData["request_id"], "request_status" => "Canceled" ];  
    }
}
// ==========================================================================================
// 6. ACTION: API ACTION (From Request Board / Datatable / API calls)
// ==========================================================================================
elseif (isset($action) && $action === "api_action") {
    $noprocess = false;
    $reqID = filter_input(INPUT_POST, "request_id", FILTER_VALIDATE_INT);
    $reqAction = $_POST['request_action'] ?? '';
    $reqInfoApi = callApi("/api/request?action=getinfo&request_id=" . $reqID); 
    $reqData = $reqInfoApi['data'][0] ?? null;
    
    if ($reqData) {
        $requesterSID = $reqData['schedule_id'];
        $targetSID    = $reqData['target_schedule_id'];
        $requestType  = $reqData['request_type_id'];
        $requestUser = getUserName($reqData['request_user_id'], $userMap);
        $targetUser = getUserName($reqData['user_replace_id'], $userMap);
        $comment = $reqData['request_reason'];
        
        if ($reqAction === 'approve') { 
            $url = "/api/request?action=update";
            $insertData = [ "request_id" => $reqID, "request_status" => "Approved", "date_approve" => date("Y-m-d H:i:s"), "approver_user_id" => $reqData['approver_user_id'] ];
            if ($requestType == 1) { 
                $replaceUID = $reqData["user_replace_id"];
                $requesterUID = $reqData["request_user_id"];
                callApi("/schedule/monthschedule?action=confirm_swap", "POST", [ "schedule_id" => $requesterSID, "user_id" => $replaceUID, "status" => 'Normal' ]);
                callApi("/schedule/monthschedule?action=confirm_swap", "POST", [ "schedule_id" => $targetSID, "user_id" => $requesterUID, "status" => 'Normal' ]);
            } else { 
                $remark = $requestUser . " " . $reqData['request_type_name'].($targetSID ? " {$targetUser}ทำ OT แทน | ".$comment  : "");
                callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $requesterSID, "status" => 'Requested', "remark" => $remark]);
                if ($targetSID) {
                    callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $targetSID, "status" => 'OT', "remark" => "{$targetUser} ทำ OT แทน {$requestUser} {$reqData['request_type_name']}" ]);
                }
            }
            $redirectUrl = 'request_board.php';
            
        } elseif ($reqAction === 'reject' || $reqAction === 'cancel') { 
            callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $requesterSID, "status" => 'Normal', "remark" => '' ]);
            if ($targetSID) {
                if ($requestType == 1) { callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $targetSID, "status" => 'Normal', "remark" => '' ]);
                } else { callApi("/schedule/monthschedule?action=delete&schedule_id=" . $targetSID, "DELETE"); }
            }
            $statusStr = ($reqAction === 'cancel') ? 'Canceled' : 'Rejected';
            $url = "/api/request?action=update";
            $insertData = [ "request_id" => $reqID, "request_status" => $statusStr ];
            if ($reqAction === 'reject') { $insertData['approver_user_id'] = $userlogin['user_id']; $insertData['date_approve'] = date("Y-m-d H:i:s"); }
            $redirectUrl = 'request_board.php';
            
        } elseif ($reqAction === 'confirm_replace') {
            // Logic เดียวกับ $req === "confirm"
            if ($requestType == 1) { // SWAP
                $needApproval = false;
                
                // 1. ดึงข้อมูลกะงานที่จะสลับ
                $reqSchApi = callApi("/schedule/monthschedule?schedule_id=" . $requesterSID);
                $tarSchApi = callApi("/schedule/monthschedule?schedule_id=" . $targetSID);
                
                if (isset($reqSchApi['data'][0], $tarSchApi['data'][0])) {
                    $rS = $reqSchApi['data'][0];
                    $tS = $tarSchApi['data'][0];
                    $requesterUID = $reqData["request_user_id"];
                    $replaceUID   = $reqData["user_replace_id"];
                    
                    // 2. เช็คเงื่อนไขวันเดียวกัน (Same Day)
                    if ($rS['schedule_date'] == $tS['schedule_date']) {
                        $needApproval = true;
                    }

                    // 3. ถ้ายังไม่ติดเงื่อนไข -> เช็ค Overlap / Rest Time
                    if (!$needApproval) {
                         $sim_map = [];
                         $dates_to_fetch = [
                             $rS['schedule_date'], 
                             date('Y-m-d', strtotime($rS['schedule_date'] . ' -1 day')),
                             date('Y-m-d', strtotime($rS['schedule_date'] . ' +1 day')),
                             $tS['schedule_date'], 
                             date('Y-m-d', strtotime($tS['schedule_date'] . ' -1 day')),
                             date('Y-m-d', strtotime($tS['schedule_date'] . ' +1 day'))
                         ];
                         $dates_to_fetch = array_unique($dates_to_fetch);

                         foreach($dates_to_fetch as $d) {
                             $u1_res = callApi("/schedule/monthschedule?action=get&schedule_date=$d&user_id=$requesterUID");
                             if (!empty($u1_res['data'])) {
                                 foreach($u1_res['data'] as $item) {
                                     if ($item['schedule_id'] == $requesterSID) continue;
                                     $sim_map[(string)$item['employee_id']][$item['schedule_date']] = [
                                         'status' => 'WORKING',
                                         'start_time' => $item['start_time'],
                                         'end_time' => $item['end_time']
                                     ];
                                 }
                             }
                             $u2_res = callApi("/schedule/monthschedule?action=get&schedule_date=$d&user_id=$replaceUID");
                             if (!empty($u2_res['data'])) {
                                 foreach($u2_res['data'] as $item) {
                                     if ($item['schedule_id'] == $targetSID) continue; 
                                     $sim_map[(string)$item['employee_id']][$item['schedule_date']] = [
                                         'status' => 'WORKING',
                                         'start_time' => $item['start_time'],
                                         'end_time' => $item['end_time']
                                     ];
                                 }
                             }
                         }

                         if (check_time_overlap_and_rest($rS['employee_id'], $tS['schedule_date'], $tS['start_time'], $tS['end_time'], $sim_map, $CONF_CHECK_OVERLAP, $CONF_CHECK_MIN_REST, $CONF_MIN_REST_HOURS)) {
                             $needApproval = true;
                         }

                         if (!$needApproval) {
                             if (check_time_overlap_and_rest($tS['employee_id'], $rS['schedule_date'], $rS['start_time'], $rS['end_time'], $sim_map, $CONF_CHECK_OVERLAP, $CONF_CHECK_MIN_REST, $CONF_MIN_REST_HOURS)) {
                                 $needApproval = true;
                             }
                         }
                    }
                } else {
                    $needApproval = true;
                }

                if ($needApproval) {
                    callApi("/schedule/monthschedule?action=change_status", "POST", [ 
                        "schedule_id" => $targetSID, 
                        "status" => "Accept", 
                        "remark" => $reqData["request_reason"] 
                    ]);

                    $url = "/api/request?action=update";
                    $insertData = [
                        "request_id"           => $reqID,
                        "user_replace_confirm" => 1,
                        "date_confirm"         => date("Y-m-d H:i:s"),
                        "request_status"       => "Pending" 
                    ];
                } else {
                    $replaceUID = $reqData["user_replace_id"];
                    $requesterUID = $reqData["request_user_id"];

                    callApi("/schedule/monthschedule?action=confirm_swap", "POST", [ "schedule_id" => $targetSID, "user_id" => 1, "status" => 'Normal' ]);
                    callApi("/schedule/monthschedule?action=confirm_swap", "POST", [ "schedule_id" => $requesterSID, "user_id" => $replaceUID, "status" => 'Normal' ]);
                    callApi("/schedule/monthschedule?action=confirm_swap", "POST", [ "schedule_id" => $targetSID, "user_id" => $requesterUID, "status" => 'Normal' ]);
                    
                    $url = "/api/request?action=update";
                    $insertData = [
                        "request_id"           => $reqID,
                        "user_replace_confirm" => 1,
                        "date_confirm"         => date("Y-m-d H:i:s"),
                        "request_status"       => "Approved"
                    ];
                }

            } else { // LEAVE
                $remark = $targetUser." ทำ OT แทน ".$requestUser." ".$reqData['request_type_name']." (Accepted)";
                callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $targetSID, "status" => "Accept", "remark" => $remark]);

                $url = "/api/request?action=update";
                $insertData = [
                    "request_id"           => $reqID,
                    "user_replace_confirm" => 1,
                    "date_confirm"         => date("Y-m-d H:i:s")
                ];
            }
            $redirectUrl = 'request_board.php';
            
        } elseif ($reqAction === 'reject_replace') {
            // Logic เดียวกับ $req === "reject"
            callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $requesterSID, "status" => 'Normal', "remark" => '' ]);
            if ($requestType == 1) { 
                callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $targetSID, "status" => 'Normal', "remark" => '' ]);
            } else { 
                callApi("/schedule/monthschedule?action=delete&schedule_id=" . $targetSID, "DELETE");
            }
            $url = "/api/request?action=update";
            $insertData = [ "request_id" => $reqID, "request_status" => "Rejected" ];
            $redirectUrl = 'request_board.php';
            
        } else {
            $status = 'error';
            $message = 'Something went wrong';
            $redirectUrl = 'request_board.php';
        }
    }
}
elseif ($_POST['req'] === 'note_swap' && $_POST['action'] === 'note_swap_submit') {
    // 1. รับค่า
    $req_user_id = $_POST['req_user_id'];
    $req_schedule_id = $_POST['req_schedule_id'];
    
    $target_user_id = $_POST['target_user_id'];
    $target_schedule_id = $_POST['target_schedule_id'];
    $remark = $_POST['swap_remark'];

    // 2. Insert ลงตาราง Requests
    // หมายเหตุ: ใช้ target_user_id เก็บลงช่อง user_replace_id ได้เพื่อความสะดวกในการให้ B กดยืนยัน
    $payload = [
        'request_user_id' => $req_user_id,
        'schedule_id' => $req_schedule_id,
        'request_type_id' => 99, // สมมติ 99 คือ Note Swap
        'request_reason' => $remark,
        'request_status' => 'Pending',
        'user_replace_id' => $target_user_id, 
        'target_schedule_id' => $target_schedule_id // ถ้าตารางไม่มีช่องนี้ ให้แพ็คใส่ JSON ไว้ใน remark
    ];
    
    callApi('/api/request?action=add', 'POST', $payload);
    
    header("Location: request_board.php");
    exit;
}
elseif (isset($req) && $req === "delete" && $manage_mode == 1){
    $noprocess = false;
    $target_schedule_id = filter_input(INPUT_POST, "schedule_id", FILTER_VALIDATE_INT);
    if ($target_schedule_id !== null){ callApi("/schedule/monthschedule?action=delete&schedule_id=" . $target_schedule_id, "DELETE"); }
    $redirect_message = false; $status = 'success'; $message = 'Successful. Deleted schedule ID : '.$target_schedule_id;
}

if($noprocess){
    echo "<pre>"; echo "Error: No valid action processed.\n"; print_r($_POST); echo "</pre>"; exit;
} else {
    if ($redirect_message && !empty($url) && !empty($insertData)){
        $response = callApi($url, "POST", $insertData);
        $status = $response['status'] ?? 'error';
        $message = $response['message'] ?? 'Something went wrong';
    }   
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Processing...</title>
    <style>
        body { font-family: "Sarabun", sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; height: 100vh; }
        .alert { width: 40%; padding: 20px 30px; border-radius: 8px; font-size: 18px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); text-align: center; }
        .success { background: #e6fffa; color: #065f46; border: 1px solid #34d399; }
        .error { background: #fee2e2; color: #7f1d1d; border: 1px solid #f87171; }
    </style>
</head>
<body>
<div class="alert <?= $status === 'success' ? 'success' : 'error' ?>">
    <?= htmlspecialchars($message ?? 'Processing complete') ?><br><small>Redirecting...</small>
</div>
<script> setTimeout(function () { window.location.href = "<?= $redirectUrl ?>"; }, 2000); </script>
</body>
</html>
<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
checkLogin();

$token = isset($_COOKIE["token"]) ? $_COOKIE["token"] : null;
$userlogin = isset($_SESSION["user_data"]) ? $_SESSION["user_data"] : null;
$redirectUrl = 'schedule.php'; // Default fallback

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

// [Security Fix] Input Validation & Sanitization
$manage_mode = filter_input(INPUT_POST, 'manage_mode', FILTER_SANITIZE_NUMBER_INT) ?? '0';
if ($manage_mode === '1' && !hasPermission('schedule.management')) {
    echo "Error: Access denied.\n"; exit;
}

$noprocess = true;
$redirect_message = true;
$status = '';
$message = '';

// [Security Fix] Sanitize strings
$action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$req = filter_input(INPUT_POST, 'req', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$requester_schedule_status = filter_input(INPUT_POST, 'requester_schedule_status', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

$url = "";

$userMap = [];
$userApiUrl = "/data/schedule"; 
$userApiData = callApi($userApiUrl);
if (isset($userApiData['data'])) {
    foreach ($userApiData['data'] as $u) {
        $fname = $u['thai_firstname'] ?? $u['eng_firstname'] ?? '-';
        $lname = $u['thai_lastname'] ?? $u['eng_lastname'] ?? '-';
        // [Security Fix] Secure output map
        $userMap[$u['user_id']] = trim(htmlspecialchars($fname, ENT_QUOTES, 'UTF-8'));
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
    
    $new_start_abs = $target_ts + $new_start_sec;
    $new_end_abs = $target_ts + $new_end_sec;
    if ($new_end_sec < $new_start_sec) { 
        $new_end_abs += 86400; 
    }

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
if ($action === "swap") {
    $noprocess = false;
    $requester = getScheduleInfoFromPost('requester_schedule_id');
    $replace   = getScheduleInfoFromPost('target_schedule_id');

    if (!$requester || !$replace) {
        $noprocess = true; $status = 'error'; $message = 'Invalid schedule data';
    } else {
        $requesterSID = (int)$requester['schedule_id'];
        $requesterUID = (int)$requester['user_id'];
        $replaceSID   = (int)$replace['schedule_id'];
        $replaceUID   = (int)$replace['user_id'];
        
        // [Security Fix] Sanitize comment
        $raw_comment = $_POST['comment'] ?? " ";
        $comment = htmlspecialchars(trim($raw_comment), ENT_QUOTES, 'UTF-8');
        
        $safe_req_fname = htmlspecialchars($requester["thai_firstname"] ?? '', ENT_QUOTES, 'UTF-8');
        $safe_rep_fname = htmlspecialchars($replace["thai_firstname"] ?? '', ENT_QUOTES, 'UTF-8');
        $remark = "{$safe_req_fname} สลับกะ {$safe_rep_fname} | {$comment}";

        if ($requester_schedule_status === 'OT' && $manage_mode == 1){
            callApi("/schedule/monthschedule?action=confirm_swap", "POST", [ "schedule_id" => $replaceSID , "user_id" => 1, "status" => 'OT' ]);
            callApi("/schedule/monthschedule?action=confirm_swap", "POST", [ "schedule_id" => $requesterSID, "user_id" => $replaceUID, "status" => 'OT' ]);
            callApi("/schedule/monthschedule?action=confirm_swap", "POST", [ "schedule_id" => $replaceSID , "user_id" => $requesterUID, "status" => 'OT' ]);
            $status = 'success'; $message = 'OT Swap success. id : '.$requesterSID.' and '.$replaceSID;
        } else {
            callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $requesterSID, "status" => "Pending", "remark" => $remark  ]);
            callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $replaceSID, "status" => "Standby", "remark" => $remark ]);

            $url = "/api/request?action=add";
            $insertData = [
                "schedule_id"        => $requesterSID,
                "target_schedule_id" => $replaceSID,
                "request_type_id"    => 1, 
                "request_user_id"    => $requesterUID,
                "user_replace_id"    => $replaceUID,
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
elseif ($action === "leave"){
    $noprocess = false;
    $schInfo = getScheduleInfoFromPost('schedule_id');
    
    // [Security Fix] Integer casting
    $leave_type = filter_input(INPUT_POST, 'leave_type', FILTER_VALIDATE_INT);
    
    $reqTInfoUrl = "/api/request_type?reqid=" . $leave_type;
    $reqTname = callApi($reqTInfoUrl);
    $reqTname = $reqTname["data"] ?? [];

    if ($schInfo) {
        // [Security Fix] XSS protection
        $comment = isset($_POST['comment']) ? htmlspecialchars(trim($_POST['comment']), ENT_QUOTES, 'UTF-8') : "Leave";
        $safe_req_type_name = htmlspecialchars($reqTname["request_type_name"] ?? 'Leave', ENT_QUOTES, 'UTF-8');
        
        callApi("/schedule/monthschedule?action=change_status", "POST", [
            "schedule_id" => (int)$schInfo["schedule_id"],
            "status"      => 'Pending',
            "remark"      => "{$safe_req_type_name} - รออนุมัติ | {$comment}"
        ]);

        $subEmpId = filter_input(INPUT_POST, 'substitute_emp_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
        $replaceUID = null;
        $target_schedule_id = null;
        
        if (!empty($subEmpId)) {
            $userurl = "/data/" . urlencode($subEmpId);
            $replaceInfo = callApi($userurl);
            $replaceUID = (int) ($replaceInfo['data'][0]['user_id'] ?? 0);
            
            $safe_replaceName = htmlspecialchars($replaceInfo["data"][0]["thai_firstname"] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
            $safe_sch_fname = htmlspecialchars($schInfo["thai_firstname"] ?? '', ENT_QUOTES, 'UTF-8');
            
            $remark = "{$safe_replaceName} Standby แทน{$safe_sch_fname} {$safe_req_type_name}";
            
            $insertDataRep = [
                'user_id'          => $replaceUID,
                'work_group_id'    => (int)($schInfo["work_group_id"] ?? 0),
                'work_schedule_id' => (int)($schInfo["work_schedule_id"] ?? 0), 
                'schedule_date'    => $schInfo["schedule_date"],
                'status'           => 'Standby',
                'remark'           => $remark                                                
            ];
            callApi("/api/update/schedule", "POST", [$insertDataRep]);
            
            $scheduleUrl = "/schedule/monthschedule?user_id=" . $replaceUID . "&schedule_date=" . urlencode($schInfo["schedule_date"]) . "&schedule_status=Standby";
            $response    = callApi($scheduleUrl);
            $target_schedule_id = $response["data"][0]["schedule_id"] ?? null;
        }

        $url = "/api/request?action=add";
        $insertData = [
            "schedule_id"        => (int)$schInfo["schedule_id"],
            "target_schedule_id" => $target_schedule_id, 
            "request_type_id"    => $leave_type,
            "request_user_id"    => (int)$schInfo["user_id"],
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
elseif ($req === "confirm"){
    $noprocess = false;
    $TargetSID = filter_input(INPUT_POST, "schedule_id", FILTER_VALIDATE_INT);
    
    $dataInput = [ "schedule_id" => $TargetSID ];
    $requestInfo = callApi("/api/request?action=gettarget", "POST", $dataInput);
    
    if (isset($requestInfo["data"][0])) {
        $reqData = $requestInfo["data"][0];
        $requesterSID = (int)$reqData["schedule_id"];
        $targetSID = (int)$reqData["target_schedule_id"]; 
        $requesterUID = (int)$reqData["request_user_id"];
        $replaceUID   = (int)$reqData["user_replace_id"];
        $requestType  = (int)$reqData["request_type_id"]; 
        $requestUser = getUserName($requesterUID, $userMap);
        $targetUser = getUserName($replaceUID, $userMap);

        if ($requestType === 1) { 
            $needApproval = false;
            $reqSchApi = callApi("/schedule/monthschedule?schedule_id=" . $requesterSID);
            $tarSchApi = callApi("/schedule/monthschedule?schedule_id=" . $targetSID);
            
            if (isset($reqSchApi['data'][0], $tarSchApi['data'][0])) {
                $rS = $reqSchApi['data'][0];
                $tS = $tarSchApi['data'][0];
                
                if ($rS['schedule_date'] == $tS['schedule_date']) {
                    $needApproval = true;
                }

                if (!$needApproval) {
                     $sim_map = [];
                     $dates_to_fetch = array_unique([
                         $rS['schedule_date'], 
                         date('Y-m-d', strtotime($rS['schedule_date'] . ' -1 day')),
                         date('Y-m-d', strtotime($rS['schedule_date'] . ' +1 day')),
                         $tS['schedule_date'], 
                         date('Y-m-d', strtotime($tS['schedule_date'] . ' -1 day')),
                         date('Y-m-d', strtotime($tS['schedule_date'] . ' +1 day'))
                     ]);

                     foreach($dates_to_fetch as $d) {
                         $safe_d = urlencode($d);
                         $u1_res = callApi("/schedule/monthschedule?action=get&schedule_date=$safe_d&user_id=$requesterUID");
                         if (!empty($u1_res['data'])) {
                             foreach($u1_res['data'] as $item) {
                                 if ($item['schedule_id'] == $requesterSID) continue;
                                 $sim_map[(string)$item['employee_id']][$item['schedule_date']] = [
                                     'status' => 'WORKING', 'start_time' => $item['start_time'], 'end_time' => $item['end_time']
                                 ];
                             }
                         }
                         $u2_res = callApi("/schedule/monthschedule?action=get&schedule_date=$safe_d&user_id=$replaceUID");
                         if (!empty($u2_res['data'])) {
                             foreach($u2_res['data'] as $item) {
                                 if ($item['schedule_id'] == $targetSID) continue; 
                                 $sim_map[(string)$item['employee_id']][$item['schedule_date']] = [
                                     'status' => 'WORKING', 'start_time' => $item['start_time'], 'end_time' => $item['end_time']
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
                    "schedule_id" => $TargetSID, 
                    "status" => "Accept", 
                    "remark" => $reqData["request_reason"] 
                ]);

                $url = "/api/request?action=update";
                $insertData = [
                    "request_id"           => (int)$reqData["request_id"],
                    "user_replace_confirm" => 1,
                    "date_confirm"         => date("Y-m-d H:i:s"),
                    "request_status"       => "Pending" 
                ];
            } else {
                callApi("/schedule/monthschedule?action=confirm_swap", "POST", [ "schedule_id" => $TargetSID, "user_id" => 1, "status" => 'Normal' ]);
                callApi("/schedule/monthschedule?action=confirm_swap", "POST", [ "schedule_id" => $requesterSID, "user_id" => $replaceUID, "status" => 'Normal' ]);
                callApi("/schedule/monthschedule?action=confirm_swap", "POST", [ "schedule_id" => $TargetSID, "user_id" => $requesterUID, "status" => 'Normal' ]);
                
                $url = "/api/request?action=update";
                $insertData = [
                    "request_id"           => (int)$reqData["request_id"],
                    "user_replace_confirm" => 1,
                    "date_confirm"         => date("Y-m-d H:i:s"),
                    "request_status"       => "Approved"
                ];
            }

        } else { 
            // [Security Fix] Encode before concat
            $safe_reqTypeName = htmlspecialchars($reqData['request_type_name'] ?? '', ENT_QUOTES, 'UTF-8');
            $remark = "{$targetUser} ทำ OT แทน {$requestUser} {$safe_reqTypeName} (Accepted)";
            callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $targetSID, "status" => "Accept", "remark" => $remark]);

            $url = "/api/request?action=update";
            $insertData = [
                "request_id"           => (int)$reqData["request_id"],
                "user_replace_confirm" => 1,
                "date_confirm"         => date("Y-m-d H:i:s")
            ];
        }
    }
}

// ==========================================================================================
// 4. REQ: REJECT (From Schedule Modal - by Target User/Substitute)
// ==========================================================================================
elseif ($req === "reject"){
    $noprocess = false;
    $TargetSID = filter_input(INPUT_POST, "schedule_id", FILTER_VALIDATE_INT);
    $dataInput = [ "schedule_id" => $TargetSID ];
    $requestInfo = callApi("/api/request?action=gettarget", "POST", $dataInput);
    if (isset($requestInfo["data"][0])) {
        $reqData = $requestInfo["data"][0];
        $requesterSID = (int)$reqData["schedule_id"];
        $requestType  = (int)$reqData["request_type_id"];
        callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $requesterSID, "status" => 'Normal', "remark" => '' ]);
        if ($requestType === 1) { 
            callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $TargetSID, "status" => 'Normal', "remark" => '' ]);
        } else { 
            callApi("/schedule/monthschedule?action=delete&schedule_id=" . $TargetSID, "DELETE");
        }
        $url = "/api/request?action=update";
        $insertData = [ "request_id" => (int)$reqData["request_id"], "request_status" => "Rejected" ];
    }
}

// ==========================================================================================
// 5. REQ: CANCEL (From Schedule Modal - by Requester)
// ==========================================================================================
elseif ($req === "cancel"){
    $noprocess = false;
    $schedule_id = filter_input(INPUT_POST, "schedule_id", FILTER_VALIDATE_INT); 
    $dataInput = [ "schedule_id" => $schedule_id ];
    $requestInfo = callApi("/api/request?action=getinfo", "POST", $dataInput);
    if (isset($requestInfo["data"][0])) {
        $reqData = $requestInfo["data"][0];
        $target_schedule_id = (int)($reqData["target_schedule_id"] ?? 0);
        $requestType = (int)$reqData["request_type_id"];
        callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $schedule_id, "status" => 'Normal', "remark" => '' ]);
        if (!empty($target_schedule_id)){
            if ($requestType === 1) { 
                callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $target_schedule_id, "status" => 'Normal', "remark" => '' ]);
            } else { 
                callApi("/schedule/monthschedule?action=delete&schedule_id=" . $target_schedule_id, "DELETE");
            }
        }       
        $url = "/api/request?action=update";
        $insertData = [ "request_id" => (int)$reqData["request_id"], "request_status" => "Canceled" ];  
    }
}

// ==========================================================================================
// 6. ACTION: API ACTION (From Request Board / Datatable / API calls)
// ==========================================================================================
elseif ($action === "api_action") {
    $noprocess = false;
    $reqID = filter_input(INPUT_POST, "request_id", FILTER_VALIDATE_INT);
    $reqAction = filter_input(INPUT_POST, 'request_action', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '';
    $reqInfoApi = callApi("/api/request?action=getinfo&request_id=" . $reqID); 
    $reqData = $reqInfoApi['data'][0] ?? null;
    
    if ($reqData) {
        $requesterSID = (int)$reqData['schedule_id'];
        $targetSID    = (int)($reqData['target_schedule_id'] ?? 0);
        $requestType  = (int)$reqData['request_type_id'];
        $requestUser  = getUserName($reqData['request_user_id'], $userMap);
        $targetUser   = getUserName($reqData['user_replace_id'], $userMap);
        $comment      = htmlspecialchars($reqData['request_reason'] ?? '', ENT_QUOTES, 'UTF-8');
        $safe_reqTypeName = htmlspecialchars($reqData['request_type_name'] ?? '', ENT_QUOTES, 'UTF-8');
        
        if ($reqAction === 'approve') { 
            $url = "/api/request?action=update";
            $insertData = [ "request_id" => $reqID, "request_status" => "Approved", "date_approve" => date("Y-m-d H:i:s"), "approver_user_id" => (int)($reqData['approver_user_id'] ?? 0) ];
            if ($requestType === 1) { 
                $replaceUID = (int)$reqData["user_replace_id"];
                $requesterUID = (int)$reqData["request_user_id"];
                callApi("/schedule/monthschedule?action=confirm_swap", "POST", [ "schedule_id" => $requesterSID, "user_id" => $replaceUID, "status" => 'Normal' ]);
                callApi("/schedule/monthschedule?action=confirm_swap", "POST", [ "schedule_id" => $targetSID, "user_id" => $requesterUID, "status" => 'Normal' ]);
            } else { 
                $remark = "{$requestUser} {$safe_reqTypeName}" . ($targetSID ? " {$targetUser}ทำ OT แทน | {$comment}" : "");
                callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $requesterSID, "status" => 'Requested', "remark" => $remark]);
                if ($targetSID) {
                    callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $targetSID, "status" => 'OT', "remark" => "{$targetUser} ทำ OT แทน {$requestUser} {$safe_reqTypeName}" ]);
                }
            }
            $redirectUrl = 'request_board.php';
            
        } elseif ($reqAction === 'reject' || $reqAction === 'cancel') { 
            callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $requesterSID, "status" => 'Normal', "remark" => '' ]);
            if ($targetSID) {
                if ($requestType === 1) { callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $targetSID, "status" => 'Normal', "remark" => '' ]);
                } else { callApi("/schedule/monthschedule?action=delete&schedule_id=" . $targetSID, "DELETE"); }
            }
            $statusStr = ($reqAction === 'cancel') ? 'Canceled' : 'Rejected';
            $url = "/api/request?action=update";
            $insertData = [ "request_id" => $reqID, "request_status" => $statusStr ];
            if ($reqAction === 'reject') { $insertData['approver_user_id'] = (int)$userlogin['user_id']; $insertData['date_approve'] = date("Y-m-d H:i:s"); }
            $redirectUrl = 'request_board.php';
            
        } elseif ($reqAction === 'confirm_replace') {
            if ($requestType === 1) { 
                $needApproval = false;
                
                $reqSchApi = callApi("/schedule/monthschedule?schedule_id=" . $requesterSID);
                $tarSchApi = callApi("/schedule/monthschedule?schedule_id=" . $targetSID);
                
                if (isset($reqSchApi['data'][0], $tarSchApi['data'][0])) {
                    $rS = $reqSchApi['data'][0];
                    $tS = $tarSchApi['data'][0];
                    $requesterUID = (int)$reqData["request_user_id"];
                    $replaceUID   = (int)$reqData["user_replace_id"];
                    
                    if ($rS['schedule_date'] == $tS['schedule_date']) {
                        $needApproval = true;
                    }

                    if (!$needApproval) {
                         $sim_map = [];
                         $dates_to_fetch = array_unique([
                             $rS['schedule_date'], 
                             date('Y-m-d', strtotime($rS['schedule_date'] . ' -1 day')),
                             date('Y-m-d', strtotime($rS['schedule_date'] . ' +1 day')),
                             $tS['schedule_date'], 
                             date('Y-m-d', strtotime($tS['schedule_date'] . ' -1 day')),
                             date('Y-m-d', strtotime($tS['schedule_date'] . ' +1 day'))
                         ]);

                         foreach($dates_to_fetch as $d) {
                             $safe_d = urlencode($d);
                             $u1_res = callApi("/schedule/monthschedule?action=get&schedule_date=$safe_d&user_id=$requesterUID");
                             if (!empty($u1_res['data'])) {
                                 foreach($u1_res['data'] as $item) {
                                     if ($item['schedule_id'] == $requesterSID) continue;
                                     $sim_map[(string)$item['employee_id']][$item['schedule_date']] = [
                                         'status' => 'WORKING', 'start_time' => $item['start_time'], 'end_time' => $item['end_time']
                                     ];
                                 }
                             }
                             $u2_res = callApi("/schedule/monthschedule?action=get&schedule_date=$safe_d&user_id=$replaceUID");
                             if (!empty($u2_res['data'])) {
                                 foreach($u2_res['data'] as $item) {
                                     if ($item['schedule_id'] == $targetSID) continue; 
                                     $sim_map[(string)$item['employee_id']][$item['schedule_date']] = [
                                         'status' => 'WORKING', 'start_time' => $item['start_time'], 'end_time' => $item['end_time']
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
                        "remark" => $comment 
                    ]);

                    $url = "/api/request?action=update";
                    $insertData = [
                        "request_id"           => $reqID,
                        "user_replace_confirm" => 1,
                        "date_confirm"         => date("Y-m-d H:i:s"),
                        "request_status"       => "Pending" 
                    ];
                } else {
                    $replaceUID = (int)$reqData["user_replace_id"];
                    $requesterUID = (int)$reqData["request_user_id"];

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

            } else { 
                $remark = "{$targetUser} ทำ OT แทน {$requestUser} {$safe_reqTypeName} (Accepted)";
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
            callApi("/schedule/monthschedule?action=change_status", "POST", [ "schedule_id" => $requesterSID, "status" => 'Normal', "remark" => '' ]);
            if ($requestType === 1) { 
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
elseif ($req === 'note_swap' && $action === 'note_swap_submit') {
    // [Security Fix] Input validation and sanitization
    $req_user_id = filter_input(INPUT_POST, 'req_user_id', FILTER_VALIDATE_INT);
    $req_schedule_id = filter_input(INPUT_POST, 'req_schedule_id', FILTER_VALIDATE_INT);
    $target_user_id = filter_input(INPUT_POST, 'target_user_id', FILTER_VALIDATE_INT);
    $target_schedule_id = filter_input(INPUT_POST, 'target_schedule_id', FILTER_VALIDATE_INT);
    
    $raw_remark = $_POST['swap_remark'] ?? '';
    $safe_remark = htmlspecialchars(trim($raw_remark), ENT_QUOTES, 'UTF-8');

    if ($req_user_id && $req_schedule_id && $target_user_id && $target_schedule_id) {
        $payload = [
            'request_user_id' => $req_user_id,
            'schedule_id' => $req_schedule_id,
            'request_type_id' => 99, 
            'request_reason' => $safe_remark,
            'request_status' => 'Pending',
            'user_replace_id' => $target_user_id, 
            'target_schedule_id' => $target_schedule_id 
        ];
        
        callApi('/api/request?action=add', 'POST', $payload);
    }
    
    header("Location: request_board.php");
    exit;
}
elseif ($req === "delete" && $manage_mode == 1){
    $noprocess = false;
    $target_schedule_id = filter_input(INPUT_POST, "schedule_id", FILTER_VALIDATE_INT);
    if ($target_schedule_id !== null && $target_schedule_id !== false){ 
        callApi("/schedule/monthschedule?action=delete&schedule_id=" . $target_schedule_id, "DELETE"); 
        $status = 'success'; 
        $message = 'Successful. Deleted schedule ID : '.$target_schedule_id;
    } else {
        $status = 'error'; 
        $message = 'Invalid Schedule ID';
    }
    $redirect_message = false; 
}

if($noprocess){
    echo "<pre>"; echo "Error: No valid action processed.\n"; echo "</pre>"; exit;
} else {
    if ($redirect_message && !empty($url) && !empty($insertData)){
        $response = callApi($url, "POST", $insertData);
        $status = $response['status'] ?? 'error';
        $message = $response['message'] ?? 'Something went wrong';
    }   
}

// [Security Fix] Whitelist allowed Redirects & Encode output to prevent Open Redirect / XSS
$allowed_redirects = ['schedule.php', 'request_board.php'];
if (!in_array($redirectUrl, $allowed_redirects)) {
    $redirectUrl = 'schedule.php';
}
$safe_redirect = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');
$safe_status = ($status === 'success') ? 'success' : 'error';
$safe_message = htmlspecialchars($message ?? 'Processing complete', ENT_QUOTES, 'UTF-8');
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
<div class="alert <?= $safe_status ?>">
    <?= $safe_message ?><br><small>Redirecting...</small>
</div>
<script> setTimeout(function () { window.location.href = "<?= $safe_redirect ?>"; }, 2000); </script>
</body>
</html>

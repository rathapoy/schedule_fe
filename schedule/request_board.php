<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
checkLogin();
$userlogin = $_SESSION["user_data"];
$user_id = $userlogin['user_id'];
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// --- 0. Fetch All Users for Name Mapping ---
$userMap = [];
$userApiUrl = "/data/schedule"; 
$userApiData = callApi($userApiUrl);
if (isset($userApiData['data'])) {
    foreach ($userApiData['data'] as $u) {
        $fname = $u['thai_firstname'] ?? $u['eng_firstname'] ?? '-';
        $lname = $u['thai_lastname'] ?? $u['eng_lastname'] ?? '-';
        $userMap[$u['user_id']] = trim("$fname $lname");
    }
}

function getUserName($id, $map) {
    return $map[$id] ?? '-';
}

// --- 1. Fetch ALL Requests ---
$allReqUrl = "/api/request?action=get"; 
$all_requests_data = callApi($allReqUrl); 
$all_requests = $all_requests_data['data'] ?? [];

// Initialize Data Arrays
$my_requests_list = []; // Tab 1: My Requests
$pending_tasks = [];    // Tab 2.1: Incoming Tasks
$history_tasks = [];    // Tab 2.2: Task History
$stats_data = [];       // Tab 3: Stats Table

// เช็คสิทธิ์ Admin
$is_admin = function_exists('hasPermission') ? hasPermission('request.management') : false;
$admin_pending_list = [];
$admin_history_list = [];

// --- 2. Process Data ---
foreach ($all_requests as $req) {
    
    // Safety check for ID key
    $req_id = $req['request_id'] ?? $req['id'] ?? null;
    if (!$req_id) continue;
    $req['final_id'] = $req_id;

    // --- Logic Tab 1: My Requests (ฉันเป็นคนขอ) ---
    if ($req['request_user_id'] == $user_id) {
        $my_requests_list[] = $req;

        // Calculate Stats for Tab 3
        $type = $req['request_type_name'] ?? 'Unknown';
        $status = $req['request_status'] ?? 'Unknown';
        
        if (!isset($stats_data[$type])) {
            $stats_data[$type] = ['Total' => 0, 'Pending' => 0, 'Approved' => 0, 'Rejected' => 0, 'Canceled' => 0, 'Other' => 0];
        }
        $stats_data[$type]['Total']++;
        
        if (isset($stats_data[$type][$status])) {
            $stats_data[$type][$status]++;
        } else {
            $stats_data[$type]['Other']++;
        }
    }

    // --- Logic Tab 2: Tasks 
    $is_pending = ($req['request_status'] === 'Pending');
    $is_replace_user = ($req['user_replace_id'] == $user_id);
    $is_approver = ($req['approver_user_id'] == $user_id);

    // 2.1 Incoming Tasks (Pending)
    
    // Case A: รอคนแทน Confirm 
    if ($is_pending && $is_replace_user && (empty($req['user_replace_confirm']) || $req['user_replace_confirm'] == 0)) {
        $req['task_role'] = 'Substitute';
        $req['task_action_label'] = 'Are you confirm ?';
        $req['badge_color'] = 'warning';
        $pending_tasks[] = $req;
    }
    // Case B: รอหัวหน้า Approve 
    elseif ($is_pending && $is_approver && empty($req['date_approve'])) {
        $req['task_role'] = 'Approver';
        
        // เช็คว่าติดสถานะคนแทนหรือไม่
        $has_substitute = !empty($req['user_replace_id']);
        $substitute_not_confirmed = ($has_substitute && ($req['user_replace_confirm'] != 1));

        if ($substitute_not_confirmed) {
            // ติดคนแทน -> ปุ่ม Disable
            $req['approver_can_act'] = false;
            $req['task_action_label'] = 'Waiting Confirm';
            $req['badge_color'] = 'info';
        } else {
            // พร้อมอนุมัติ -> ปุ่ม Enable
            $req['approver_can_act'] = true;
            $req['task_action_label'] = 'Are you approve ?';
            $req['badge_color'] = 'primary'; 
        }
        
        $pending_tasks[] = $req;
    }

    // 2.2 Task History (Completed Actions)
    if ($is_replace_user && $req['user_replace_confirm'] == 1) {
        $req['history_role'] = 'Substitute';
        $req['history_action'] = 'Confirmed';
        $req['history_date'] = $req['date_confirm'];
        $history_tasks[] = $req;
    }
    if ($is_approver && !empty($req['date_approve'])) {
        $req['history_role'] = 'Approver';
        $req['history_action'] = $req['request_status']; // Approved or Rejected
        $req['history_date'] = $req['date_approve'];
        $history_tasks[] = $req;
    }

    // เก็บข้อมูลทั้งหมดสำหรับ Admin
    if ($is_admin) {
        if (in_array($req['request_status'], ['Pending', 'Standby'])) {
            $admin_pending_list[] = $req;
        } else {
            $admin_history_list[] = $req;
        }
    }
}

// Sort Arrays (Newest First)
usort($my_requests_list, function($a, $b) { return strtotime($b['date_time_created']) - strtotime($a['date_time_created']); });
usort($pending_tasks, function($a, $b) { return strtotime($b['date_time_created']) - strtotime($a['date_time_created']); });
usort($history_tasks, function($a, $b) { return strtotime($b['date_time_created']) - strtotime($a['date_time_created']); });
if ($is_admin) {
    usort($admin_pending_list, function($a, $b) { return strtotime($b['date_time_created']) - strtotime($a['date_time_created']); });
    usort($admin_history_list, function($a, $b) { return strtotime($b['date_time_created']) - strtotime($a['date_time_created']); });
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
    <link rel="stylesheet" href="/static/datatable/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/static/css/schedule.css">
    
    <script src="/static/jquery/jquery-3.6.0.min.js"></script>
    <script src="/static/bootstrap/5.3.1/js/bootstrap.bundle.min.js"></script>
    <script src="/static/datatable/jquery.dataTables.min.js"></script>
    <script src="/static/datatable/dataTables.bootstrap5.min.js"></script>
    <title>Request Board</title>
    
    <style>
        /* FIX: บังคับทับ Style เดิมของ Bootstrap ที่ทำให้ขอบ Tab เพี้ยนเป็นสีขาว/สี่เหลี่ยม */
        .nav-tabs .nav-link {
            border: none !important;
            background-color: #e9ecef !important;
            border-radius: 30px !important;
            color: #6c757d !important;
            margin-bottom: 0 !important; /* ป้องกันการเหลื่อมลงมาทับเส้นขอบล่าง */
            transition: all 0.3s ease;
        }
        .nav-tabs .nav-link:hover {
            background-color: #dde2e6 !important;
        }
        .nav-tabs .nav-link.active {
            color: #fff !important;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1) !important;
            border: none !important;
        }
        #myreq-tab.active { background-color: rgb(30, 133, 64) !important; }
        #tasks-tab.active { background-color: #0d6efd !important; }
        #stats-tab.active { background-color: #6610f2 !important; }
        #admin-tab.active { background-color: #212529 !important; }
    </style>
</head>
<body>
    <div class="header-bar d-flex justify-content-between align-items-center flex-wrap gap-3">
        <span class="header-title">
            <i class="fa-regular fa-calendar-check" style="text-shadow: none;"></i> Requests and Tasks
        </span>
    </div> 
    
    <div class="main-container">
        
        <!-- Nav Tabs -->
        <ul class="nav nav-tabs" id="requestTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="myreq-tab" data-bs-toggle="tab" data-bs-target="#myreq-tab-pane" type="button" role="tab">
                    <i class="fa-solid fa-file-signature me-1"></i> My Requests
                    <span class="badge bg-light text-dark rounded-pill ms-1 shadow-sm"><?= count($my_requests_list) ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tasks-tab" data-bs-toggle="tab" data-bs-target="#tasks-tab-pane" type="button" role="tab">
                    <i class="fa-solid fa-list-check me-1"></i> Tasks
                    <?php if(!empty($pending_tasks)): ?>
                        <span class="badge bg-danger rounded-pill ms-1 shadow-sm"><?= count($pending_tasks) ?></span>
                    <?php endif; ?>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="stats-tab" data-bs-toggle="tab" data-bs-target="#stats-tab-pane" type="button" role="tab">
                    <i class="fa-solid fa-chart-pie me-1"></i> Statistics
                </button>
            </li>
            <?php if ($is_admin): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="admin-tab" data-bs-toggle="tab" data-bs-target="#admin-tab-pane" type="button" role="tab">
                    <i class="fa-solid fa-layer-group me-1"></i> All Requests
                </button>
            </li>
            <?php endif; ?>
        </ul>

        <div class="tab-content" id="requestTabsContent">
            
            <!-- TAB 1: My Requests (ประวัติคำขอของฉัน) -->
            <div class="tab-pane fade show active" id="myreq-tab-pane" role="tabpanel" tabindex="0">
                <div class="card card-table">
                    <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-white">
                        <span class="fw-bold text-success small"><i class="fa-solid fa-clock-rotate-left me-1"></i>Your Request List</span>
                    </div>
                    <?php if (empty($my_requests_list)): ?>
                        <div class="text-center text-muted py-5">Request not found.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table id="myReqTable" class="table table-hover table-custom w-100 fixed display">
                                <thead>
                                    <tr>
                                        <th width="150px" class="text-left pl-10">Request Date/Time</th>
                                        <th width="150px" class="text-left pl-10">Schedule Date</th>
                                        <th width="150px" class="text-left pl-10">Request Type</th>
                                        <th width="300px">Replace staff</th>
                                        <th width="300px">Reason</th>
                                        <th width="50px" class="text-center">Status</th>
                                        <th width="50px" class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($my_requests_list as $req): ?>
                                        <tr>
                                            <td class="text-left fw-bold pl-10" data-sort="<?= strtotime($req['date_time_created']) ?>">
                                                <?= date('d M Y | H:i', strtotime($req['date_time_created'])); ?>
                                            </td>
                                            <td class="text-left fw-bold pl-10" data-sort="<?= strtotime($req['schedule_date']) ?>">
                                                <?= date('D d M Y', strtotime($req['schedule_date'])); ?>
                                            </td>
                                            <td class="text-left">
                                                <span class="badge bg-light text-dark border fw-normal" style="font-size: 11px;"><?= htmlspecialchars($req['request_type_name']); ?></span>
                                            </td>
                                            <td>
                                                <?php if (!empty($req['user_replace_id'])): ?>
                                                    <div class="text-dark fw-bold" style="font-size: 12px;">
                                                        <i class="fa-solid fa-user-friends me-1"></i> แทนโดย : 
                                                        <?= htmlspecialchars(getUserName($req['user_replace_id'], $userMap)); ?>
                                                        <?php if($req['user_replace_confirm'] == 1): ?>
                                                            <i class="fa-solid fa-check-circle text-success ms-1" title="Confirmed"></i>
                                                            <span class="text-success fw-bold">Accept</span>
                                                        <?php else: ?>
                                                            <i class="fa-solid fa-clock text-warning ms-1" title="Waiting Confirm"></i>
                                                            <span class="text-warning fw-bold">Waiting</span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-danger"><i class="fa-solid fa-user-xmark me-2"></i><b>No replacer (ไม่มีผู้ปฎิบัติงานแทน)</b></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="text-dark small"><?= nl2br(htmlspecialchars($req['request_reason'] ?? '-')); ?></div>
                                            </td>
                                            <td class="text-center">
                                                <span class="status-badge status-<?= $req['request_status']; ?>"><?= $req['request_status']; ?></span>
                                            </td>
                                            <td class="text-center">
                                                <?php if (in_array($req['request_status'], ['Pending', 'Standby'])): ?>
                                                    <button onclick="processAction('<?= $req['final_id'] ?>', 'cancel')" class="btn btn-action" title="ยกเลิกคำขอ">
                                                        <i class="fa-solid fa-circle-xmark fa-xl" style="color: rgb(214, 88, 88);"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted"><i class="fa-solid fa-ban"></i></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TAB 2: Tasks (งานและการดำเนินการ) -->
            <div class="tab-pane fade" id="tasks-tab-pane" role="tabpanel" tabindex="0">
                
                <!-- 2.1 Incoming Tasks -->
                <div class="card card-table border-0 shadow-sm" style="border-top: 3px solid #0d6efd;">
                    <div class="p-3 border-bottom bg-white fw-bold text-primary small">
                        <i class="fa-solid fa-inbox me-2"></i>Pending Tasks
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($pending_tasks)): ?>
                            <div class="text-center text-muted py-4">No Task</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <!-- เพิ่ม class table-fixed เพื่อบังคับความกว้างให้คงที่ -->
                                <table class="table table-hover table-custom w-100 fixed display">
                                    <thead>
                                        <tr>
                                            <!-- กำหนด % ให้รวมกันเป็น 100% พอดีเพื่อให้ทั้ง 2 ตารางเท่ากัน -->
                                            <th width="10%" class="text-left fw-bold">Create Date-Time</th>
                                            <th width="10%" class="text-left fw-bold">Schedule Date</th>
                                            <th width="15%" class="text-left fw-bold">Requester</th>
                                            <th width="12%" class="text-left fw-bold">Type</th>
                                            <th width="15%">Detail</th>
                                            <th width="10%" class="text-center">Status</th>
                                            <th width="20%" class="text-center">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_tasks as $task): ?>
                                            <tr>
                                                <td class="text-left fw-bold text-secondary"><?= date('d M Y | H:i', strtotime($task['date_time_created'])); ?></td>
                                                <td class="text-left fw-bold text-secondary"><?= date('D | d M Y', strtotime($task['schedule_date'])); ?></td>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars(getUserName($task['request_user_id'], $userMap)); ?></div>
                                                    <?php if(!empty($task['user_replace_id'])): ?>
                                                        <div class="text-muted" style="font-size: 10px;">
                                                            (แทนโดย: <?= htmlspecialchars(getUserName($task['user_replace_id'], $userMap)) ?>)
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-danger" style="font-size: 10px;"><b>No replacer (ไม่มีผู้ปฎิบัติงานแทน)</b></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-left pl-10">
                                                    <span class="badge bg-light text-secondary border fw-normal" style="font-size: 10px;"><?= htmlspecialchars($task['request_type_name']); ?></span>
                                                </td>
                                                <td><div class="small text-secondary"><?= nl2br(htmlspecialchars($task['request_reason'] ?? '-')); ?></div></td>
                                                <td class="text-center pl-10">
                                                    <span class="badge bg-<?= $task['badge_color'] ?? 'warning'; ?> bg-opacity-10 text-<?= $task['badge_color'] ?? 'warning'; ?> border border-<?= $task['badge_color'] ?? 'warning'; ?>-subtle rounded-pill px-2 py-1" style="font-size: 10px;">
                                                        <i class="fa-solid fa-hourglass-half me-2"></i> <?= $task['task_action_label']; ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <div class="d-flex justify-content-center">
                                                        <?php if ($task['task_role'] == 'Substitute'): ?>
                                                            <button onclick="processAction('<?= $task['final_id'] ?>', 'confirm_replace')" class="btn btn-action" title="Confirm">
                                                                <i class="fa-solid fa-circle-check fa-xl" style="color: rgb(19, 120, 21);"></i>
                                                            </button>|
                                                            <button onclick="processAction('<?= $task['final_id'] ?>', 'reject_replace')" class="btn  btn-action" title="Reject">
                                                                <i class="fa-solid fa-circle-xmark fa-xl" style="color: rgb(214, 88, 88);"></i>
                                                            </button>
                                                            
                                                        <?php elseif ($task['task_role'] == 'Approver'): ?>
                                                            
                                                            <?php if (!empty($task['approver_can_act'])): ?>
                                                                
                                                                <button onclick="processAction('<?= $task['final_id'] ?>', 'approve')" class="btn btn-action" title="Approve">
                                                                    <i class="fa-solid fa-circle-check fa-xl" style="color: rgb(19, 120, 21);"></i>
                                                                </button>|
                                                                <button onclick="processAction('<?= $task['final_id'] ?>', 'reject')" class="btn  btn-action" title="Reject">
                                                                    <i class="fa-solid fa-circle-xmark fa-xl" style="color: rgb(214, 88, 88);"></i>
                                                                </button>
                                                            <?php else: ?>
                                                                
                                                                <span class="badge rounded-pill text-bg-secondary" title="ต้องรอให้คนแทนกดยืนยันก่อน">
                                                                    <i class="fa-solid fa-clock"></i> Waiting Confirm
                                                                </span>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 2.2 Task History -->
                <div class="card card-table">
                    <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-white">
                        <span class="fw-bold text-muted small"><i class="fa-solid fa-history me-1"></i>History</span>
                    </div>
                    <?php if (empty($history_tasks)): ?>
                        <div class="text-center text-muted py-4">ไม่มีประวัติการดำเนินการ</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <!-- เพิ่ม class table-fixed เหมือนกัน -->
                            <table id="taskHistoryTable" class="table table-hover table-custom w-100 display">
                                <thead>
                                    <tr>
                                        <th width="15%" class="text-left fw-bold">Create Date-Time</th>
                                        <th width="15%" class="text-left fw-bold">Schedule Date</th>
                                        <th width="15%" class="text-left fw-bold">Requester</th>
                                        <th width="15%" class="text-left fw-bold">Type</th>
                                        <th width="15%">Detail</th>
                                        <th width="10%" class="text-center">Status</th>
                                        <th width="10%" class="text-center">Role Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($history_tasks as $htask): ?>
                                        <tr>
                                            <td class="text-left fw-bold text-secondary" data-sort="<?= strtotime($htask['history_date']) ?>">
                                                <?= date('d M Y | H:i', strtotime($htask['history_date'])); ?>
                                            </td>
                                            <td class="text-left fw-bold text-secondary" data-sort="<?= strtotime($htask['schedule_date']) ?>">
                                                <?= date('D | d M Y', strtotime($htask['schedule_date'])); ?>
                                            </td>
                                            <td class="fw-bold"><?= htmlspecialchars(getUserName($htask['request_user_id'], $userMap)); ?>
                                                    <?php if(!empty($htask['user_replace_id'])): ?>
                                                        <div class="text-muted" style="font-size: 10px;">
                                                            (แทนโดย: <?= htmlspecialchars(getUserName($htask['user_replace_id'], $userMap)) ?>)
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="text-muted" style="font-size: 10px;">
                                                            <span class="text-danger" style="font-size: 10px;"><b>No replacer (ไม่มีผู้ปฎิบัติงานแทน)</b></span>   
                                                        </div>
                                                    <?php endif; ?>
                                            </td>
                                            <td class="text-left small"><span class="badge bg-light text-secondary border fw-normal" style="font-size: 10px;"><?= htmlspecialchars($htask['request_type_name']); ?></span></td>
                                            <td class="text-left small"><?= htmlspecialchars($htask['request_reason']); ?></td>
                                            <td class="text-center">
                                                <?php if($htask['history_action'] == 'Confirmed'): ?>
                                                    <span class="status-badge status-Accept">Confirmed</span>
                                                <?php elseif($htask['history_action'] == 'Approved'): ?>
                                                    <span class="status-badge status-Approved">Approved</span>
                                                <?php elseif($htask['history_action'] == 'Rejected'): ?>
                                                    <span class="status-badge status-Rejected">Rejected</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary rounded-pill px-3 py-1" style="font-size: 10px;"><?= $htask['history_action']; ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center"><span class="badge bg-light text-dark border fw-normal" style="font-size: 10px;"><?= $htask['history_role']; ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TAB 3: Statistics (Table View) -->
            <div class="tab-pane fade" id="stats-tab-pane" role="tabpanel" tabindex="0">
                <div class="card card-table">
                    <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-white">
                        <span class="fw-bold text-primary small"><i class="fa-solid fa-chart-pie me-1"></i> สรุปสถิติคำขอของฉัน (My Request Summary)</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-custom w-100 m-0">
                            <thead>
                                <tr>
                                    <th class="text-start">ประเภทคำขอ (Request Type)</th>
                                    <th class="text-primary text-center">ทั้งหมด (Total)</th>
                                    <th class="text-success text-center">อนุมัติแล้ว (Approved)</th>
                                    <th class="text-secondary text-center">รออนุมัติ (Pending)</th>
                                    <th class="text-danger text-center">ปฏิเสธ (Rejected)</th>
                                    <th class="text-muted text-center">ยกเลิก (Canceled)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats_data as $type => $stat): ?>
                                    <tr class="text-center">
                                        <td class="text-start fw-bold text-dark"><?= htmlspecialchars($type) ?></td>
                                        <td class="text-primary fs-6 fw-bold"><?= number_format($stat['Total']) ?></td>
                                        <td class="text-success fw-bold"><?= number_format($stat['Approved']) ?></td>
                                        <td class="text-secondary fw-bold"><?= number_format($stat['Pending']) ?></td>
                                        <td class="text-danger fw-bold"><?= number_format($stat['Rejected']) ?></td>
                                        <td class="text-muted fw-bold"><?= number_format($stat['Canceled']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($stats_data)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">ไม่มีข้อมูลสถิติ</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 4: Admin (จัดการทุกคำขอ) -->
            <?php if ($is_admin): ?>
            <div class="tab-pane fade" id="admin-tab-pane" role="tabpanel" tabindex="0">
                
                <!-- 4.1 Admin Pending Tasks -->
                <div class="card card-table border-0 shadow-sm" style="border-top: 3px solid #212529;">
                    <div class="p-3 border-bottom bg-white fw-bold text-dark small">
                        <i class="fa-solid fa-layer-group me-2"></i> All Pending Requests (คำขอที่รอการดำเนินการทั้งหมด)
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($admin_pending_list)): ?>
                            <div class="text-center text-muted py-4">No Pending Requests</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table id="adminPendingTable" class="table table-hover table-custom w-100 m-0 display">
                                    <thead>
                                        <tr>
                                            <th width="150px" class="text-left">Req. Date</th>
                                            <th width="150px">Requester</th>
                                            <th width="80px">Type</th>
                                            <th width="300px">Detail & Replace</th>
                                            <th width="100px" class="text-center">Approver</th>
                                            <th width="80px" class="text-center">Status</th>
                                            <th width="200px" class="text-center">Action |
                                                <i class="fa-solid fa-circle-check" style="color: rgb(45, 145, 47);"></i> Confirm | 
                                                <i class="fa-solid fa-circle-check" style="color: rgb(44, 86, 221);"></i> Approve
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($admin_pending_list as $req): ?>
                                            <tr>
                                                <td class="text-left fw-bold text-secondary" data-sort="<?= strtotime($req['schedule_date']) ?>">
                                                    <?= date('D d M Y', strtotime($req['schedule_date'])); ?>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars(getUserName($req['request_user_id'], $userMap)); ?></div>
                                                </td>
                                                <td class="text-left">
                                                    <span class="badge bg-light text-secondary border fw-normal" style="font-size: 11px;"><?= htmlspecialchars($req['request_type_name']); ?></span>
                                                </td>
                                                <td>
                                                    <div class="small text-secondary mb-1"><?= nl2br(htmlspecialchars($req['request_reason'] ?? '-')); ?></div>
                                                    <?php if(!empty($req['user_replace_id'])): ?>
                                                        <div class="text-muted" style="font-size: 10px;">
                                                            <i class="fa-solid fa-user-friends me-1"></i> แทนโดย: <?= htmlspecialchars(getUserName($req['user_replace_id'], $userMap)) ?>
                                                            <?php if($req['user_replace_confirm'] == 1): ?>
                                                                <i class="fa-solid fa-check-circle text-success ms-1" title="Confirmed"></i>
                                                            <?php else: ?>
                                                                <i class="fa-solid fa-clock text-warning ms-1" title="Waiting Confirm"></i>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center small">
                                                    <?= htmlspecialchars(getUserName($req['approver_user_id'] ?? 0, $userMap)); ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="status-badge status-<?= $req['request_status']; ?>"><?= $req['request_status']; ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <div class="d-flex justify-content-center gap-1">
                                                        <?php
                                                        // เช็คว่าติดรอคนแทนยืนยันหรือไม่ (มีคนแทน แต่ยังไม่ confirm)
                                                        $is_waiting_substitute = !empty($req['user_replace_id']) && $req['user_replace_confirm'] != 1;
                                                        ?>

                                                        <?php if ($is_waiting_substitute): ?>
                                                            <!-- ขั้นตอนรอ Confirm: ให้ Admin กดยืนยันแทนคนแทนได้ -->
                                                            <button onclick="processAction('<?= $req['final_id'] ?>', 'confirm_replace')" class="btn btn-action" title="Confirm">
                                                                <i class="fa-solid fa-circle-check fa-xl" style="color: rgb(45, 145, 47);"></i>
                                                            </button>
                                                            |
                                                            <button onclick="processAction('<?= $task['final_id'] ?>', 'reject_replace')" class="btn  btn-action" title="Reject">
                                                                <i class="fa-solid fa-circle-xmark fa-xl" style="color: rgb(214, 88, 88);"></i>
                                                            </button>
                                                        <?php else: ?>
                                                            <!-- ขั้นตอน Approve: ให้ Approve, Reject ได้ -->
                                                            <button onclick="processAction('<?= $req['final_id'] ?>', 'approve')" class="btn btn-action" title="Approve">
                                                                <i class="fa-solid fa-circle-check fa-xl" style="color: rgb(44, 86, 221);"></i>
                                                            </button>
                                                            |
                                                            <button onclick="processAction('<?= $req['final_id'] ?>', 'reject')" class="btn  btn-action" title="Reject">
                                                                <i class="fa-solid fa-circle-xmark fa-xl" style="color: rgb(214, 88, 88);"></i>
                                                            </button>
                                                        <?php endif; ?>

                                                        <!-- ยกเลิก (Cancel) สามารถทำได้ตลอด -->
                                                        |<button onclick="processAction('<?= $req['final_id'] ?>', 'cancel')" class="btn btn-action" title="Cancel"><i class="fa-solid fa-trash-can fa-xl" style="color: rgb(238, 218, 36);"></i></button>
                                                    </div>
                                                    
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 4.2 Admin History Tasks -->
                <div class="card card-table">
                    <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-white">
                        <span class="fw-bold text-muted small"><i class="fa-solid fa-history me-1"></i> All Requests History (ประวัติการดำเนินการทั้งหมด)</span>
                    </div>
                    <?php if (empty($admin_history_list)): ?>
                        <div class="text-center text-muted py-4">ไม่มีประวัติการดำเนินการ</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table id="adminHistoryTable" class="table table-hover table-custom w-100 display m-0">
                                <thead>
                                    <tr>
                                        <th width="12%" class="text-center">Req. Date</th>
                                        <th width="15%">Requester</th>
                                        <th width="15%">Type</th>
                                        <th width="25%">Detail & Replace</th>
                                        <th width="15%" class="text-center">Approver</th>
                                        <th width="10%" class="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($admin_history_list as $req): ?>
                                        <tr>
                                            <td class="text-center fw-bold text-secondary" data-sort="<?= strtotime($req['schedule_date']) ?>">
                                                <?= date('D d M Y', strtotime($req['schedule_date'])); ?>
                                            </td>
                                            <td>
                                                <div class="fw-bold text-dark"><?= htmlspecialchars(getUserName($req['request_user_id'], $userMap)); ?></div>
                                            </td>
                                            <td class="text-left">
                                                <span class="badge bg-light text-secondary border fw-normal" style="font-size: 11px;"><?= htmlspecialchars($req['request_type_name']); ?></span>
                                            </td>
                                            <td>
                                                <div class="small text-secondary mb-1"><?= nl2br(htmlspecialchars($req['request_reason'] ?? '-')); ?></div>
                                                <?php if(!empty($req['user_replace_id'])): ?>
                                                    <div class="text-muted" style="font-size: 10px;">
                                                        <i class="fa-solid fa-user-friends me-1"></i> แทนโดย: <?= htmlspecialchars(getUserName($req['user_replace_id'], $userMap)) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center small">
                                                <?= htmlspecialchars(getUserName($req['approver_user_id'] ?? 0, $userMap)); ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="status-badge status-<?= $req['request_status']; ?>"><?= $req['request_status']; ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- MAIN ACTION FORM (Submit to request_process.php) -->
    <form id="actionForm" method="POST" action="request_process.php" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
        <input type="hidden" name="user_id" value="<?= $user_id ?>">
        <input type="hidden" name="action" value="api_action">
        <input type="hidden" name="request_action" id="formRequestAction" value="">
        <input type="hidden" name="request_id" id="formRequestId" value="">
    </form>

    <script>
    $(document).ready(function() {
        // ใช้ dom โครงสร้างเพื่อสร้าง footer ให้ DataTables ดูคล้ายๆโค้ดแบบใหม่
        const tableConfig = {
            "order": [[ 0, "desc" ]],
            "pageLength": 10,
            "dom": "<'p-3 border-bottom d-flex justify-content-between align-items-center'<'d-none d-md-block'l><'d-flex'f>>" + 
                   "t" + 
                   "<'card-footer-flex'ip>",
            "language": {
                "emptyTable": "ไม่พบข้อมูล",
                "search": "",
                "searchPlaceholder": "Search...",
                "paginate": { "first": "«", "last": "»", "next": "›", "previous": "‹" },
                "info": "Showing _START_ to _END_ of _TOTAL_ entries",
                "lengthMenu": "Show _MENU_"
            }
        };

        if ($('#myReqTable').length) $('#myReqTable').DataTable(tableConfig);
        if ($('#taskHistoryTable').length) {
            $('#taskHistoryTable').DataTable($.extend({}, tableConfig, { ordering: false }));
        }
        if ($('#adminPendingTable').length) $('#adminPendingTable').DataTable(tableConfig);
        if ($('#adminHistoryTable').length) $('#adminHistoryTable').DataTable(tableConfig);
        
        // --- ส่วนที่เพิ่มใหม่: ตรวจสอบและเปิด Tab ที่จำไว้ ---
        const activeTabId = sessionStorage.getItem('activeRequestTab');
        if (activeTabId) {
            const tabElement = document.getElementById(activeTabId);
            if (tabElement) {
                const tab = new bootstrap.Tab(tabElement);
                tab.show(); // สั่งเปิด Tab ล่าสุด
            }
        }

        $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
            // --- ส่วนที่เพิ่มใหม่: บันทึก ID ของ Tab เมื่อมีการเปลี่ยน Tab ---
            sessionStorage.setItem('activeRequestTab', e.target.id);
            
            $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
        });
    });

    // --- Action Processing Function ---
    function processAction(id, actionType) {
        if (!id) {
            alert('Error: Missing Request ID');
            return;
        }

        let confirmMsg = 'ยืนยันการทำรายการ?';
        
        switch(actionType) {
            case 'cancel': confirmMsg = 'คุณต้องการยกเลิกคำขอนี้ใช่หรือไม่?'; break;
            case 'approve': confirmMsg = 'คุณต้องการอนุมัติคำขอนี้ใช่หรือไม่?'; break;
            case 'reject': confirmMsg = 'คุณต้องการไม่อนุมัติ/ปฏิเสธ คำขอนี้ใช่หรือไม่?'; break;
            case 'confirm_replace': confirmMsg = 'คุณต้องการยืนยันรับงานแทนใช่หรือไม่?'; break;
            case 'reject_replace': confirmMsg = 'คุณต้องการปฏิเสธการรับงานแทนใช่หรือไม่?'; break;
        }

        if (!confirm(confirmMsg)) return;
        
        // Populate Hidden Inputs
        document.getElementById('formRequestId').value = id;
        document.getElementById('formRequestAction').value = actionType;
        
        // Submit Form
        document.getElementById('actionForm').submit();
    }
    </script>
</body>
</html>
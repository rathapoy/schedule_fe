<?php
session_start();
header('Content-Type: text/html; charset=utf-8');
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
checkLogin();
$userlogin = $_SESSION["user_data"];

// --- 0. Fetch All Users for Data Mapping ---
$all_users = [];
$userMap = [];
$userTeamMap = [];
$userShiftMap = [];
$unique_teams = [];
$unique_shifts = [];

$userApiUrl = "/data/schedule"; 
$userApiData = callApi($userApiUrl);
if (isset($userApiData['data'])) {
    $all_users = $userApiData['data'];
    foreach ($all_users as $u) {
        $uid = $u['user_id'] ?? 0;
        $fname = $u['thai_firstname'] ?? $u['eng_firstname'] ?? '-';
        $lname = $u['thai_lastname'] ?? $u['eng_lastname'] ?? '-';
        
        $userMap[$uid] = trim("$fname $lname");
        $userTeamMap[$uid] = $u['team'] ?? '-';
        $userShiftMap[$uid] = $u['shift_name'] ?? '-';
        
        if (!empty($u['team']) && !in_array($u['team'], $unique_teams)) $unique_teams[] = $u['team'];
        if (!empty($u['shift_name']) && !in_array($u['shift_name'], $unique_shifts)) $unique_shifts[] = $u['shift_name'];
    }
}

function getUserName($id, $map) {
    return $map[$id] ?? '-';
}

// --- 1. Date Logic ---
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');

// รูปแบบ YYYY-MM สำหรับเปรียบเทียบกับ schedule_date ใน DB
$selectedDateStr = sprintf("%04d-%02d", $selected_year, $selected_month);

// --- 2. Fetch & Filter Data ---
$allReqUrl = "/api/request?action=get&month=" . $selectedDateStr; 
$all_requests_data = callApi($allReqUrl); 
$raw_requests = $all_requests_data['data'] ?? [];

// กรองข้อมูลให้ตรงกับ Sch. Date (Schedule Date) ในเดือนที่เลือกเท่านั้น
$all_requests = array_filter($raw_requests, function($item) use ($selectedDateStr) {
    // ตรวจสอบว่า schedule_date ขึ้นต้นด้วย YYYY-MM ที่เลือกหรือไม่
    return isset($item['schedule_date']) && strpos($item['schedule_date'], $selectedDateStr) === 0;
});

// Reset keys ของ array หลังกรอง เพื่อให้ json_encode ทำงานได้ถูกต้อง
$all_requests = array_values($all_requests);

$request_types = []; 
foreach ($all_requests as $r) {
    $t_name = $r['request_type_name'] ?? '';
    if (!empty($t_name) && !in_array($t_name, $request_types)) $request_types[] = $t_name;
}

sort($unique_teams);
sort($unique_shifts);
sort($request_types);

$monthNames = [1 => "Jan", 2 => "Feb", 3 => "Mar", 4 => "Apr", 5 => "May", 6 => "Jun", 7 => "Jul", 8 => "Aug", 9 => "Sep", 10 => "Oct", 11 => "Nov", 12 => "Dec"];

// เรียงลำดับตามวันที่สร้าง ล่าสุดขึ้นก่อน
usort($all_requests, function($a, $b) { 
    return strtotime($b['date_time_created'] ?? 'now') - strtotime($a['date_time_created'] ?? 'now'); 
});
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <link href="/static/font/Sarabun.css" rel="stylesheet" />
    <link rel="stylesheet" href="/static/fontawesome/css/all.css">
    <link rel="stylesheet" href="/static/bootstrap/5.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="/static/datatable/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/static/datatable/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="/static/css/schedule.css">
</head>
<body>

<div class="header-bar d-flex justify-content-between align-items-center py-2">
    <span class="header-title">
        <i class="fa-regular fa-clipboard me-2" style="text-shadow: none;"></i>Request Statistical Report
    </span>

    <div class="nav-controls">
        
        <form id="dateForm" method="GET" action="" class="d-flex align-items-center gap-1 m-0">
            <label class="mb-0">MONTH:</label>
            <select name="month" onchange="this.form.submit()">
                <?php foreach ($monthNames as $num => $name): ?>
                    <option value="<?= $num ?>" <?= $selected_month == $num ? 'selected' : '' ?>><?= $name ?></option>
                <?php endforeach; ?>
            </select>

            <label class="mb-0 ms-2">YEAR:</label>
            <select name="year" onchange="this.form.submit()">
                <?php $cY = (int)date('Y'); for ($y = $cY - 2; $y <= $cY + 1; $y++): ?>
                    <option value="<?= $y ?>" <?= $selected_year == $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </form>

        <div class="vr mx-2 d-none d-md-block" style="height: 31px; width: 1.5px; background-color: #ddd; opacity: 1;"></div>

        <button id="btnExcel" class="btn btn-outline-success btn-sm fw-bold rounded-pill px-3 shadow-sm" style="height: 31px;">
            <i class="fa-solid fa-file-excel me-1"></i> EXPORT EXCEL
        </button>

    </div>
</div>

<div class="main-container">
    <div class="card card-table">
<!-- ... existing code ... -->
        <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-white">
            <span class="fw-bold text-muted small"><i class="fas fa-table me-1"></i> Data List: <?= $monthNames[$selected_month] ?> <?= $selected_year ?></span>
            <span id="showingBadge" class="badge bg-light text-success border fw-normal" style="font-size: 11px;">
                Showing <?php echo count($all_requests); ?> records
            </span>
        </div>
        <div class="p-3">
            <table id="reqTable" class="table table-hover table-custom w-100">
                <thead>
                    <tr>
                        <th width="110">Req. Date</th>
                        <th width="90">Sch. Date</th>
                        <th>Requester</th>
                        <th width="100">Team</th>
                        <th width="100">Shift</th>
                        <th width="90">Type</th>
                        <th>Detail</th>
                        <th>Approver</th>
                        <th width="100">Status</th>
                    </tr>
                    <tr class="filter-row">
                        <th><input type="text" placeholder="Date"></th>
                        <th><input type="text" placeholder="Date"></th>
                        <th><input type="text" placeholder="Name"></th>
                        <th>
                            <select class="column-filter shadow-none">
                                <option value="">All Teams</option>
                                <?php foreach($unique_teams as $t): ?><option value="<?= htmlspecialchars($t ?? '') ?>"><?= htmlspecialchars($t ?? '') ?></option><?php endforeach; ?>
                            </select>
                        </th>
                        <th>
                            <select class="column-filter shadow-none">
                                <option value="">All Shifts</option>
                                <?php foreach($unique_shifts as $s): ?><option value="<?= htmlspecialchars($s ?? '') ?>"><?= htmlspecialchars($s ?? '') ?></option><?php endforeach; ?>
                            </select>
                        </th>
                        <th>
                            <select class="column-filter shadow-none">
                                <option value="">All Types</option>
                                <?php foreach($request_types as $type): ?><option value="<?= htmlspecialchars($type ?? '') ?>"><?= htmlspecialchars($type ?? '') ?></option><?php endforeach; ?>
                            </select>
                        </th>
                        <th><input type="text" placeholder="Reason"></th>
                        <th><input type="text" placeholder="Approver"></th>
                        <th>
                            <select class="column-filter shadow-none">
                                <option value="">All Status</option>
                                <option value="Pending">Pending</option>
                                <option value="Approved">Approved</option>
                                <option value="Rejected">Rejected</option>
                                <option value="Canceled">Canceled</option>
                            </select>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_requests as $req): 
                        $uid = $req['request_user_id'] ?? 0;
                        $r_team = $userTeamMap[$uid] ?? '-';
                        $r_shift = $userShiftMap[$uid] ?? '-';
                        $status = $req['request_status'] ?? 'Pending';
                    ?>
                    <tr>
                        <td class="text-center small"><?= date('d/m/y H:i', strtotime($req['date_time_created'] ?? 'now')); ?></td>
                        <td class="text-center fw-bold text-primary"><?= date('d/m/y', strtotime($req['schedule_date'] ?? 'now')); ?></td>
                        <td class="fw-bold"><?= htmlspecialchars(getUserName($uid, $userMap)); ?></td>
                        <td class="text-center small"><?= htmlspecialchars($r_team) ?></td>
                        <td class="text-center small"><?= htmlspecialchars($r_shift) ?></td>
                        <td class="text-center"><span class="badge bg-info text-dark" style="font-size: 10px;"><?= htmlspecialchars($req['request_type_name'] ?? ''); ?></span></td>
                        <td>
                            <div class="small text-dark fw-bold"><?= htmlspecialchars($req['request_reason'] ?? '-'); ?></div>
                            <?php if (!empty($req['user_replace_id'])): ?>
                                <div class="text-muted" style="font-size: 10px;"><i class="fa-solid fa-user-friends me-1"></i> Replace by: <?= htmlspecialchars(getUserName($req['user_replace_id'], $userMap)); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center small"><?= htmlspecialchars(getUserName($req['approver_user_id'] ?? 0, $userMap)); ?></td>
                        <td class="text-center">
                            <span class="status-badge status-<?= htmlspecialchars($status); ?>"><?= htmlspecialchars($status); ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="/static/jquery/jquery-3.6.0.min.js"></script>
<script src="/static/bootstrap/5.3.1/js/bootstrap.bundle.min.js"></script>
<script src="/static/datatable/jquery.dataTables.min.js"></script>
<script src="/static/datatable/dataTables.bootstrap5.min.js"></script>
<script src="/static/datatable/dataTables.buttons.min.js"></script>
<script src="/static/datatable/buttons.bootstrap5.min.js"></script>
<script src="/static/datatable/jszip.min.js"></script>
<script src="/static/datatable/buttons.html5.min.js"></script>

<script>
    let table;
    $(document).ready(function() {
        table = $('#reqTable').DataTable({
            "dom": "t<'card-footer-flex'il>p", 
            "pageLength": 25,
            "ordering": false,
            "scrollX": true,
            "language": { 
                "emptyTable": "ไม่พบข้อมูลรายการในเดือนนี้",
                "lengthMenu": "Show _MENU_ entries"
            },
            "buttons": [
                {
                    extend: 'excelHtml5',
                    title: 'Request_Report_<?= $monthNames[$selected_month] ?>_<?= $selected_year ?>',
                    exportOptions: { 
                        columns: [ 0, 1, 2, 3, 4, 5, 6, 7, 8 ],
                        format: {
                            body: function (data, row, column, node) {
                                return node.innerText || data;
                            }
                        }
                    }
                }
            ]
        });

        $('.filter-row input').on('keyup change', function() {
            const idx = $(this).parent().index();
            table.column(idx).search(this.value).draw();
        });

        $('.column-filter').on('change', function() {
            const idx = $(this).parent().index();
            const val = $(this).val();
            
            if (val === "") {
                table.column(idx).search('').draw();
            } else {
                table.column(idx).search('^' + val + '$', true, false).draw();
            }
        });

        $('#btnExcel').on('click', function() {
            table.button(0).trigger();
        });
    });
</script>
</body>
</html>
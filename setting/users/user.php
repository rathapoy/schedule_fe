<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
checkLogin();

$token = isset($_COOKIE["token"]) ? $_COOKIE["token"] : null;
$user = isset($_SESSION["user_data"]) ? $_SESSION["user_data"] : null;

if (!$user || !$token) {
    echo '<script>window.top.location.replace("/auth/logon.php?error=Session Expired !");</script>';
    exit();
}

if(!hasPermission('user.user_manage')){
    http_response_code(403);
    exit('
        <div style="display:flex; justify-content:center; align-items:center; height:100vh; font-family:Sarabun, Arial;">
            <h1 style="color:#d9534f;"><b>Access Denied !</b></h1>
        </div>
    ');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$api_url = "/data/all";
$result = callApi($api_url);

$users = [];
if(isset($result['status']) && $result['status'] === 'success' && isset($result['data'])){
    $users = $result['data'];
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
    
    <style>
        body { 
            font-family: "Sarabun", sans-serif; 
            font-size: 13px; 
            background-color: #f0f2f5; 
        }
        
        .header-bar { 
            background: #fff; 
            border-bottom: 1px solid #ddd; 
            padding: 11px 25px; 
            position: sticky;
            top: 0;
            z-index: 1030;
        }
        .header-title { 
            font-family: "Sarabun", "Arial", "Helvetica", sans-serif; 
            font-weight: bold; font-size: 20px; color: rgb(30, 133, 64); 
            text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.50);
            margin: 0;
        }

        .main-container { padding: 20px; }

        .card-table { border: none; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.05); background: #fff; overflow: hidden; }
        .table-custom thead th { 
            background-color: #f1f3f5; color: #495057; font-size: 11px; 
            text-transform: uppercase; padding: 12px; border-bottom: 2px solid #dee2e6; text-align: center;
        }
        .table-custom td { padding: 10px 12px; vertical-align: middle; border-bottom: 1px solid #f1f3f5; }
        
        .filter-row input, .filter-row select { 
            font-size: 11px; padding: 4px 8px; border-radius: 4px; border: 1px solid #ddd; width: 100%; height: 28px; 
            background-color: #fff; outline: none;
        }

        .badge-emp { font-size: 11px; background: #eef2f7; color: #333; border: 1px solid #dee2e6; font-weight: 600; }
        .status-active { background-color: #e6fffa; color: #087f5b; border: 1px solid #96f2d7; }
        .status-inactive { background-color: #fff5f5; color: #c92a2a; border: 1px solid #ffc9c9; }
        
        .card-footer-flex { 
            display: flex; justify-content: space-between; align-items: center; 
            padding: 12px 20px; background: #fafafa; border-top: 1px solid #eee;
        }
        .dataTables_info, .dataTables_length { font-size: 11px; color: #777; }
        .dataTables_length select { display: inline-block; width: auto; margin: 0 5px; padding: 2px 25px 2px 8px; font-size: 11px; border-radius: 4px; }
        .pagination { margin: 15px 20px !important; justify-content: flex-end; }
        .pagination .page-link { font-size: 0.75rem; padding: 4px 10px; color: #333; }
        .pagination .page-item.active .page-link { background-color: rgb(30, 133, 64); border-color: rgb(30, 133, 64); color: #fff; }

        .btn-detail { color: #198754; transition: 0.2s; padding: 0; border: none; background: none; }
        .btn-detail:hover { color: #157347; transform: scale(1.2); }
    </style>
</head>

<body>

<div class="header-bar d-flex justify-content-between align-items-center">
    <span class="header-title"><i class="fa-solid fa-users-gear me-2" style="text-shadow:none;"></i>Users Management</span>
    
    <div class="d-flex align-items-center gap-3">
        <div class="btn-group" role="group">
            <a href="adduser.php" class="btn btn-success btn-sm rounded-pill px-3 fw-bold shadow-sm me-2">
                <i class="fa fa-user-plus me-1"></i> Add User
            </a>
        </div>

        <div class="vr mx-1" style="height: 38px; width: 1.5px; background-color: #ddd; opacity: 1;"></div>
        
        <span class="badge bg-light text-success border px-3 py-2 fw-normal" style="font-size: 11px;">
            <i class="fa-solid fa-user-check me-1"></i> Total: <?= count($users) ?>
        </span>
    </div>
</div>

<div class="main-container">
    <div class="card card-table">
        <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-white">
            <span class="fw-bold text-muted small"><i class="fas fa-table me-1"></i> User Accounts List</span>
        </div>
        <div class="p-3">
            <table id="userTable" class="table table-hover table-custom w-100">
                <thead>
                    <tr>
                        <th width="40">ID</th>
                        <th width="80">Emp ID</th>
                        <th width="180">Thai Name</th>
                        <th width="180">English Name</th>
                        <th>Email</th>
                        <th>Department</th>
                        <th>Division</th>
                        <th>Role</th>
                        <th>Team</th>
                        <th>Shift</th>
                        <th width="80">Status</th>
                        <th width="50">Detail</th>
                    </tr>
                    <tr class="filter-row">
                        <th></th>
                        <th><input type="text" placeholder="Emp ID"></th>
                        <th><input type="text" placeholder="Thai Name"></th>
                        <th><input type="text" placeholder="English Name"></th>
                        <th><input type="text" placeholder="Email"></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): 
                        $statusText = $u['is_active'] ? 'Active' : 'Inactive';
                        $roleName = $u['role_name'] ?? '-';
                    ?>
                        <tr>
                            <td class="text-center text-muted small"><?= htmlspecialchars($u['user_id'] ?? '') ?></td>
                            <td class="text-center" data-search="<?= htmlspecialchars($u['employee_id'] ?? '') ?>">
                                <span class="badge badge-emp"><?= htmlspecialchars($u['employee_id'] ?? '') ?></span>
                            </td>
                            <td class="fw-bold"><?= htmlspecialchars(($u['thai_initialname'] ?? '') . $u['thai_firstname'] . ' ' . $u['thai_lastname']) ?></td>
                            <td class="text-muted small"><?= htmlspecialchars(($u['eng_initialname'] ?? '') . $u['eng_firstname'] . ' ' . $u['eng_lastname']) ?></td>
                            <td class="small"><?= htmlspecialchars($u['email'] ?? '') ?></td>
                            <td class="small"><?= htmlspecialchars($u['department'] ?? '-') ?></td>
                            <td class="small"><?= htmlspecialchars($u['division'] ?? '-') ?></td>
                            <td class="text-center" data-search="<?= htmlspecialchars($roleName) ?>">
                                <span class="badge bg-light text-dark border fw-normal" style="font-size: 10px;"><?= htmlspecialchars($roleName) ?></span>
                            </td>
                            <td class="text-center small"><?= htmlspecialchars($u['team'] ?? '-') ?></td>
                            <td class="text-center small"><?= htmlspecialchars($u['shift_name'] ?? '-') ?></td>
                            <td class="text-center" data-search="<?= $statusText ?>">
                                <span class="badge rounded-pill <?= $u['is_active'] ? 'status-active' : 'status-inactive' ?>" style="font-size: 10px; min-width: 60px;">
                                    <?= $statusText ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <form action="userdetail.php" method="post" class="m-0">
                                    <input type="hidden" name="employee_id" value="<?= htmlspecialchars($u['employee_id']) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                    <button type="submit" class="btn-detail" title="View Profile">
                                        <i class="fa-solid fa-circle-user fa-xl"></i>
                                    </button>
                                </form>
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

<script>
$(document).ready(function() {
    const table = $('#userTable').DataTable({
        "dom": "t<'card-footer-flex'il>p", 
        "pageLength": 20,
        "lengthMenu": [[10, 20, 50, 100], [10, 20, 50, 100]],
        "ordering": false,
        "scrollX": true,
        "language": {
            "search": "Search:",
            "lengthMenu": "Show _MENU_ entries",
            "info": "Showing _START_ to _END_ of _TOTAL_ entries",
            "paginate": { "next": "<i class='fa-solid fa-chevron-right'></i>", "previous": "<i class='fa-solid fa-chevron-left'></i>" }
        },
        initComplete: function () {
            const api = this.api();
            const selectIndices = [5, 6, 7, 8, 9, 10]; 

            api.columns().every(function (index) {
                const column = this;
                const headerCell = $('.filter-row th').eq(index);

                if (selectIndices.includes(index)) {
                    const select = $('<select><option value="">All</option></select>')
                        .appendTo(headerCell.empty())
                        .on('change', function () {
                            const val = $.fn.dataTable.util.escapeRegex($(this).val());
                            column.search(val ? '^' + val + '$' : '', true, false).draw();
                        });

                    // ใช้ filter เฉพาะค่าที่ไม่ว่าง
                    column.data().unique().sort().each(function (d) {
                        if (d) {
                            // ทำความสะอาด HTML เพื่อดึงแค่ตัวหนังสือมาเป็น option
                            const cleanText = d.replace(/<[^>]*>?/gm, '').trim();
                            if (cleanText) select.append(`<option value="${cleanText}">${cleanText}</option>`);
                        }
                    });

                    // ตั้งค่า Default เป็น Active สำหรับคอลัมน์ Status (Index 10)
                    if (index === 10) {
                        select.val('Active');
                        // บังคับการค้นหาแบบ Case Sensitive และตรงตัวเป๊ะๆ
                        column.search('^Active$', true, false).draw();
                    }
                } else if (index !== 0 && index !== 11 && index !== 12) {
                    $('input', headerCell).on('keyup change', function() {
                        if (column.search() !== this.value) {
                            column.search(this.value).draw();
                        }
                    });
                }
            });
        }
    });
});
</script>

</body>
</html>
<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
$token = isset($_COOKIE["token"]) ? $_COOKIE["token"] : null;
$user = isset($_SESSION["user_data"]) ? $_SESSION["user_data"] : null;
if (!checkLogin() || !$user || !$token) {
    echo '<script>window.top.location.replace("/auth/logon.php?error=Session Expired !");</script>';
    exit();
}

$notification = null;

// --- Mockup Team Options ---
$team_url = "/schedule/team?action=get";
$team_result = callApi($team_url);
$team_options = array_column($team_result["data"] ?? [], 'team');

$team_workgroup = trim($_GET['team_filter'] ?? ($_POST['team_workgroup'] ?? $user["team"]));

// --- Fetch Workgroups ---
$api_url = "/schedule/workgroup?action=get&team=".$team_workgroup;
$result = callApi($api_url);

$workgroups = [];
if(isset($result['status']) && $result['status'] === 'success' && isset($result['data'])){
    $workgroups = $result['data'];
}

// --- Fetch Break Slots ---
// ดึงข้อมูล Break slot ทั้งหมดมาเตรียมไว้ทำ Dropdown
$break_slots_url = "/api/all-break-slots";
$break_slots_result = callApi($break_slots_url);
$break_slots = [];
if(isset($break_slots_result['status']) && $break_slots_result['status'] === 'success'){
    $break_slots = $break_slots_result['data'];
}

// --- Handle POST (Add/Update) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $work_group = trim($_POST['work_group'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $work_group_id_posted = isset($_POST['work_group_id_to_update']) ? (int)$_POST['work_group_id_to_update'] : null;
    $team_to_save = trim($_POST['team_workgroup'] ?? $team_workgroup);
    
    // รับค่า Break slot จาก form (ถ้าเลือกว่างๆ จะเป็น null)
    $default_break_slot_id = !empty($_POST['default_break_slot_id']) ? (int)$_POST['default_break_slot_id'] : null;

    if (!empty($work_group)) {
        $post_data = [
            'work_group' => $work_group,
            'team_workgroup' => $team_to_save,
            'description' => $description,
            'default_break_slot_id' => $default_break_slot_id, // ส่งค่าไปยัง API
            'modify_by' => $user['emp_id']."-".$user['name']
        ];

        if (!empty($work_group_id_posted)) {
            $api_url = "/schedule/workgroup?action=update";
            $post_data['work_group_id'] = $work_group_id_posted;
            $op_result = callApi($api_url, 'POST', $post_data);
            $status_param = 'update_success';
        } else {
            $api_url = "/schedule/workgroup?action=add";
            $op_result = callApi($api_url, 'POST', $post_data);
            $status_param = 'add_success';
        }

        if (isset($op_result['status']) && $op_result['status'] === 'success') {
            header("Location: workgroup_setting.php?status={$status_param}&team_filter={$team_to_save}");
            exit();
        } else {
            $op_message = $op_result['message'] ?? 'Failed to process Workgroup.';
            if (isset($op_result['data']['detail'])) {
                 $detail = $op_result['data']['detail'];
                 $op_message .= " (Detail: " . (is_string($detail) ? $detail : json_encode($detail)) . ")";
            }
            $notification = ['type' => 'danger', 'message' => 'Error: ' . htmlspecialchars($op_message)];
        }
    } else {
        $notification = ['type' => 'warning', 'message' => 'Workgroup Name cannot be empty.'];
    }
}

if (isset($_GET['status'])) {
    if ($_GET['status'] === 'add_success') $notification = ['type' => 'success', 'message' => 'New Workgroup added successfully!'];
    elseif ($_GET['status'] === 'update_success') $notification = ['type' => 'success', 'message' => 'Workgroup updated successfully!'];
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
    
    <link rel="stylesheet" href="/static/datatable/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/static/datatable/responsive.dataTables.min.css"> 
    <script src="/static/datatable/jquery.dataTables.min.js"></script>
    <script src="/static/datatable/dataTables.bootstrap5.min.js"></script>
    <script src="/static/datatable/dataTables.responsive.min.js"></script> 
    
    <title>Setting | Work Group</title>
</head>
<body class="p-0">
    <!-- Header Bar -->
    <div class="sticky-top bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center" style="z-index: 1030;">
        <h5 class="m-0 fw-bold text-theme-green" style="text-shadow: 1px 1px 3px rgba(0,0,0,0.1); font-size: 20px;">
            <i class="fa-solid fa-gear me-2"></i>Schedule Settings <i class="fa fa-angle-right mx-2 text-secondary" style="font-size: 0.8em;"></i>
            <span class="text-dark"><i class="fa-solid fa-users-viewfinder me-1"></i> Work Group</span>
        </h5>
        
        <div class="d-flex align-items-center gap-3">
            <!-- Team Filter Form -->
            <form method="GET" action="" class="m-0 d-flex align-items-center bg-light rounded-pill px-3 py-1 border shadow-sm">
                <span class="fw-bold text-secondary me-2 small">Team:</span>
                <select name="team_filter" class="form-select form-select-sm border-0 bg-transparent shadow-none fw-bold text-dark" onchange="this.form.submit()" style="min-width: 100px; cursor: pointer;">
                    <?php foreach ($team_options as $t_opt): ?>
                        <option value="<?= htmlspecialchars($t_opt) ?>" <?= ($team_workgroup == $t_opt) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t_opt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <button class="btn btn-success btn-sm fw-bold px-3 rounded-pill shadow-sm" onclick="openAddModal()">
                <i class="fa-solid fa-plus me-1"></i> Add Workgroup
            </button>
            <div class="vr mx-1" style="height: 38px; width: 1.5px; background-color: #ddd;"></div>
            <a href="schedule_setting.php" class="btn btn-light btn-sm px-3 rounded-pill border shadow-sm text-dark">
                <i class="fa fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>
    
    <div class="container-fluid px-4 my-4">
        <?php if ($notification): ?>
            <div class="alert alert-<?= $notification['type'] ?> alert-dismissible fade show mb-4 shadow-sm border-0 rounded-3" role="alert">
                <?= $notification['message'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Content Table - Modern Style -->
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="card-body p-0">
                <div class="table-responsive px-3 py-2">
                    <table id="userTable" class="table table-hover align-middle mb-0 w-100">
                        <thead class="table-light">
                            <tr>
                                <th class="text-center" style="width: 5%;">ID</th>
                                <th class="text-center" style="width: 15%;">Workgroup</th>
                                <th style="width: 30%;">Description</th>
                                <th class="text-center" style="width: 15%;">Default Break</th>
                                <th class="text-center" style="width: 15%;">Last Update</th>
                                <th class="text-center" style="width: 15%;">Updated By</th>
                                <th class="text-center" style="width: 5%;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($workgroups as $workgroup): ?>
                            <tr>
                                <td class="text-center text-muted"><?= htmlspecialchars($workgroup['work_group_id'] ?? '') ?></td>
                                <td class="text-center fw-bold text-primary fs-6"><?= htmlspecialchars($workgroup['work_group'] ?? '') ?></td>
                                <td class="text-secondary"><?= htmlspecialchars($workgroup['description'] ?? '') ?></td>
                                
                                <!-- แสดงชื่อ Break Slot -->
                                <td class="text-center">
                                    <?php if(!empty($workgroup['default_break_slot_name'])): ?>
                                        <span class="badge bg-info text-dark rounded-pill px-3 py-2 shadow-sm">
                                            <i class="fa-regular fa-clock me-1"></i> <?= htmlspecialchars($workgroup['default_break_slot_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>

                                <?php
                                    $lastUpdateRaw = $workgroup['last_modified'] ?? '';
                                    $lastUpdateFormatted = !empty($lastUpdateRaw) ? (new DateTime($lastUpdateRaw))->format('d/m/Y H:i') : '';
                                ?>
                                <td class="text-center text-muted small"><?= htmlspecialchars($lastUpdateFormatted) ?></td>
                                <td class="text-center text-muted small"><?= htmlspecialchars($workgroup['modify_by'] ?? '-') ?></td>
                                <td class="text-center">
                                    <?php if (!empty($workgroup['work_group_id'])): ?>
                                        <!-- เพิ่ม data-break-id -->
                                        <button class="btn btn-outline-secondary btn-action-icon edit-btn border-0 shadow-sm" 
                                            data-id="<?= htmlspecialchars($workgroup['work_group_id']) ?>"
                                            data-name="<?= htmlspecialchars($workgroup['work_group']) ?>"
                                            data-des="<?= htmlspecialchars($workgroup['description']) ?>"
                                            data-break-id="<?= htmlspecialchars($workgroup['default_break_slot_id'] ?? '') ?>"
                                            title="Edit Workgroup">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Add/Edit Modal (Modern Look) -->
        <div class="modal fade" id="workgroupModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                    <div class="modal-header border-bottom-0 pb-0" id="modalHeader">
                        <h5 class="modal-title fw-bold" id="modalTitle"></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST" action="">
                        <div class="modal-body p-4">
                            <input type="hidden" name="team_workgroup" value="<?= htmlspecialchars($team_workgroup) ?>">
                            <input type="hidden" name="work_group_id_to_update" id="work_group_id_to_update" value="">

                            <div class="mb-3">
                                <label for="work_group" class="form-label fw-bold text-dark">Workgroup Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-lg bg-light border-0" id="work_group" name="work_group" required maxlength="50" placeholder="e.g. A, B, C...">
                            </div>

                            <!-- เพิ่ม Dropdown สำหรับเลือก Break Slot -->
                            <div class="mb-3">
                                <label for="default_break_slot_id" class="form-label fw-bold text-dark">Default Break Slot</label>
                                <select class="form-select form-select-lg bg-light border-0" id="default_break_slot_id" name="default_break_slot_id">
                                    <option value="">-- ไม่ระบุ --</option>
                                    <?php foreach ($break_slots as $slot): ?>
                                        <option value="<?= htmlspecialchars($slot['slot_id']) ?>">
                                            <?= htmlspecialchars($slot['slot_name']) ?> 
                                            (<?= htmlspecialchars(substr($slot['start_time'], 0, 5)) ?> - <?= htmlspecialchars(substr($slot['end_time'], 0, 5)) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-2">
                                <label for="description" class="form-label fw-bold text-dark">Description</label>
                                <textarea class="form-control bg-light border-0" id="description" name="description" rows="3" maxlength="255" placeholder="Add some details..."></textarea>
                            </div>
                        </div>
                        <div class="modal-footer border-top-0 pt-0 pb-4 px-4">
                            <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm" id="btnSubmit">
                                <i class="fa-solid fa-save me-1"></i> Save
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <script>
            const workgroupModal = new bootstrap.Modal(document.getElementById('workgroupModal'));
            const currentTeam = "<?= htmlspecialchars($team_workgroup) ?>";

            function openAddModal() {
                $('#work_group_id_to_update').val('');
                $('#work_group').val('');
                $('#description').val('');
                $('#default_break_slot_id').val(''); // เคลียร์ค่า Break Slot
                
                $('#btnSubmit').removeClass('btn-warning').addClass('btn-success').html('<i class="fa-solid fa-check me-1"></i> Add Workgroup');
                $('#modalTitle').html('<i class="fa-solid fa-plus-circle text-success me-2"></i> Add New Workgroup <span class="text-muted small fw-normal ms-2">(Team: ' + currentTeam + ')</span>');
                workgroupModal.show();
            }

            $(document).ready(function() {
                $('.edit-btn').on('click', function() {
                    const id = $(this).data('id');
                    $('#work_group_id_to_update').val(id);
                    $('#work_group').val($(this).data('name'));
                    $('#description').val($(this).data('des'));
                    $('#default_break_slot_id').val($(this).data('break-id')); // เซ็ตค่า Break Slot ตอน Edit
                    
                    $('#btnSubmit').removeClass('btn-success').addClass('btn-warning text-dark').html('<i class="fa-solid fa-save me-1"></i> Save Changes');
                    $('#modalTitle').html('<i class="fa-solid fa-pen-to-square text-warning me-2"></i> Edit Workgroup');
                    workgroupModal.show();
                });

                setTimeout(function() {
                    $('#userTable').DataTable({
                        "paging": false,
                        "ordering": false,
                        "info": false,
                        "searching": false,
                        "responsive": true
                    });
                }, 200);

                const alertBox = $('.alert');
                if (alertBox.length) setTimeout(function() { alertBox.slideUp(400, function() { $(this).remove(); }); }, 3000);
            });
        </script>
    </div>
</body>
</html>
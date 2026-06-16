<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
$token = isset($_COOKIE["token"]) ? $_COOKIE["token"] : null;
$user = isset($_SESSION["user_data"]) ? $_SESSION["user_data"] : null;
if (!checkLogin() || !$user || !$token) {
    echo '<script>window.top.location.replace("/auth/logon.php?error=Session Expired !");</script>';
    exit();
}

$notification = null;

// --- Fetch all shifts ---
$api_url = "/schedule/shift?action=get";
$result = callApi($api_url);

$shifts = [];
if(isset($result['status']) && $result['status'] === 'success' && isset($result['data'])){
    $shifts = $result['data'];
}

// --- Handle POST request (Add/Update) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shift_name = trim($_POST['shift_name'] ?? '');
    $shift_des = trim($_POST['shift_des'] ?? '');
    $shift_id_posted = $_POST['shift_id_to_update'] ?? null;

    if (!empty($shift_name)) {
        if (!empty($shift_id_posted)) {
            // UPDATE LOGIC
            $api_url = "/schedule/shift?action=update";
            $post_data = [
                'shift_id' => (int)$shift_id_posted,
                'shift_name' => $shift_name,
                'shift_des' => $shift_des,
                'modify_by'=> $user['emp_id']."-".$user['name']
            ];
            $op_result = callApi($api_url, 'PUT', $post_data);
            $status_param = 'update_success';
        } else {
            // ADD LOGIC
            $api_url = "/schedule/shift?action=add";
            $post_data = [
                'shift_name' => $shift_name,
                'shift_des' => $shift_des,
                'modify_by'=> $user['emp_id']."-".$user['name']
            ];
            $op_result = callApi($api_url, 'POST', $post_data);
            $status_param = 'add_success';
        }

        if (isset($op_result['status']) && $op_result['status'] === 'success') {
            header("Location: shift_setting.php?status={$status_param}");
            exit();
        } else {
            $op_message = $op_result['message'] ?? 'Failed to process shift.';
            if (isset($op_result['data']['detail']) && is_string($op_result['data']['detail'])) {
                 $op_message = $op_result['data']['detail'];
            }
            $notification = ['type' => 'danger', 'message' => 'Error: ' . htmlspecialchars($op_message)];
        }
    } else {
        $notification = ['type' => 'warning', 'message' => 'Shift Name cannot be empty.'];
    }
}

// Handle GET status
if (isset($_GET['status'])) {
    if ($_GET['status'] === 'add_success') $notification = ['type' => 'success', 'message' => 'New shift added successfully!'];
    elseif ($_GET['status'] === 'update_success') $notification = ['type' => 'success', 'message' => 'Shift updated successfully!'];
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
    
    <!-- DataTables -->
    <link rel="stylesheet" href="/static/datatable/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/static/datatable/responsive.dataTables.min.css"> 
    <script src="/static/datatable/jquery.dataTables.min.js"></script>
    <script src="/static/datatable/dataTables.bootstrap5.min.js"></script>
    <script src="/static/datatable/dataTables.responsive.min.js"></script> 
    
    <title>Setting | Shift Setup</title>
</head>
<body class="p-0">
    <!-- Header Bar -->
    <div class="sticky-top bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center" style="z-index: 1030;">
        <h5 class="m-0 fw-bold text-theme-green" style="text-shadow: 1px 1px 3px rgba(0,0,0,0.1); font-size: 20px;">
            <i class="fa-solid fa-gear me-2"></i>Schedule Settings <i class="fa fa-angle-right mx-2 text-secondary" style="font-size: 0.8em;"></i>
            <span class="text-dark"><i class="fa-solid fa-calendar-week me-1"></i> Shift Setup</span>
        </h5>
        
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-success btn-sm fw-bold px-3 rounded-pill shadow-sm" onclick="openAddModal()">
                <i class="fa-solid fa-plus me-1"></i> Add Shift
            </button>
            <div class="vr mx-1" style="height: 38px; width: 1.5px; background-color: #ddd;"></div>
            <a href="schedule_setting.php" class="btn btn-light btn-sm px-3 rounded-pill border shadow-sm text-dark">
                <i class="fa fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>
    
    <div class="container-fluid px-4 my-4">
        <!-- Notification Alert -->
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
                                <th class="text-center" style="width: 20%;">Shift Name</th>
                                <th style="width: 35%;">Description</th>
                                <th class="text-center" style="width: 10%;">Staff</th>
                                <th class="text-center" style="width: 15%;">Last Update</th>
                                <th class="text-center" style="width: 10%;">Updated By</th>
                                <th class="text-center" style="width: 5%;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($shifts as $shift): ?>
                            <tr>
                                <td class="text-center text-muted"><?= htmlspecialchars($shift['shift_id'] ?? '') ?></td>
                                <td class="text-center fw-bold text-dark fs-6"><?= htmlspecialchars($shift['shift_name'] ?? '') ?></td>
                                <td class="text-secondary"><?= htmlspecialchars($shift['shift_des'] ?? '') ?></td>
                                <td class="text-center">
                                    <span class="badge bg-light text-dark border px-2 py-1 rounded-pill">
                                        <i class="fa-solid fa-users text-secondary me-1"></i> <?= htmlspecialchars($shift['user_count'] ?? 0) ?>
                                    </span>
                                </td>
                                <?php
                                    $lastUpdateRaw = $shift['last_modified'] ?? '';
                                    $lastUpdateFormatted = !empty($lastUpdateRaw) ? (new DateTime($lastUpdateRaw))->format('d/m/Y H:i') : '';
                                ?>
                                <td class="text-center text-muted small"><?= htmlspecialchars($lastUpdateFormatted) ?></td>
                                <td class="text-center text-muted small"><?= htmlspecialchars($shift['modify_by'] ?? '-') ?></td>
                                <td class="text-center">
                                    <?php if (!empty($shift['shift_id'])): ?>
                                        <button class="btn btn-outline-secondary btn-action-icon edit-btn border-0 shadow-sm" 
                                            data-id="<?= htmlspecialchars($shift['shift_id']) ?>"
                                            data-name="<?= htmlspecialchars($shift['shift_name']) ?>"
                                            data-des="<?= htmlspecialchars($shift['shift_des']) ?>"
                                            title="Edit Shift">
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
        <div class="modal fade" id="shiftModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                    <div class="modal-header border-bottom-0 pb-0" id="modalHeader">
                        <h5 class="modal-title fw-bold" id="modalTitle"></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST" action="">
                        <div class="modal-body p-4">
                            <input type="hidden" name="shift_id_to_update" id="shift_id_to_update" value="">

                            <div class="mb-4">
                                <label for="shift_name" class="form-label fw-bold text-dark">Shift Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-lg bg-light border-0" id="shift_name" name="shift_name" required maxlength="50" placeholder="e.g. Morning, A, B...">
                            </div>
                            <div class="mb-2">
                                <label for="shift_des" class="form-label fw-bold text-dark">Description</label>
                                <textarea class="form-control bg-light border-0" id="shift_des" name="shift_des" rows="3" maxlength="255" placeholder="Add some details..."></textarea>
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
            const shiftModal = new bootstrap.Modal(document.getElementById('shiftModal'));

            function openAddModal() {
                $('#shift_id_to_update').val('');
                $('#shift_name').val('');
                $('#shift_des').val('');
                
                $('#btnSubmit').removeClass('btn-warning').addClass('btn-success').html('<i class="fa-solid fa-check me-1"></i> Add Shift');
                $('#modalTitle').html('<i class="fa-solid fa-plus-circle text-success me-2"></i> Add New Shift');
                shiftModal.show();
            }

            $(document).ready(function() {
                $('.edit-btn').on('click', function() {
                    const id = $(this).data('id');
                    $('#shift_id_to_update').val(id);
                    $('#shift_name').val($(this).data('name'));
                    $('#shift_des').val($(this).data('des'));
                    
                    $('#btnSubmit').removeClass('btn-success').addClass('btn-warning text-dark').html('<i class="fa-solid fa-save me-1"></i> Save Changes');
                    $('#modalTitle').html('<i class="fa-solid fa-pen-to-square text-warning me-2"></i> Edit Shift');
                    shiftModal.show();
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
                if (alertBox.length) {
                    setTimeout(function() { alertBox.slideUp(400, function() { $(this).remove(); }); }, 3000);
                }
            });
        </script>
    </div>
</body>
</html>
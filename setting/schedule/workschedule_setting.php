<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
$token = isset($_COOKIE["token"]) ? $_COOKIE["token"] : null;
$user = isset($_SESSION["user_data"]) ? $_SESSION["user_data"] : null;
if (!checkLogin() || !$user || !$token) {
    echo '<script>window.top.location.replace("/auth/logon.php?error=Session Expired !");</script>';
    exit();
}

$notification = null;

// --- Fetch all Work Schedules ---
$api_url = "/schedule/workschedule?action=get"; 
$result = callApi($api_url);

$work_schedules = [];
if(isset($result['status']) && $result['status'] === 'success' && isset($result['data'])){
    $work_schedules = $result['data'];
}

// --- Handle POST request (Add/Update) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type_name = trim($_POST['type_name'] ?? ''); 
    $start_time = trim($_POST['start_time'] ?? '');
    $end_time = trim($_POST['end_time'] ?? '');
    $color = trim($_POST['color'] ?? ''); 
    $description = trim($_POST['description'] ?? '');
    
    // New fields
    $priority = isset($_POST['priority']) && $_POST['priority'] !== '' ? (int)$_POST['priority'] : 0;
    $sequence = isset($_POST['sequence']) && $_POST['sequence'] !== '' ? (int)$_POST['sequence'] : 0;
    $ot_color = trim($_POST['ot_color'] ?? '');
    $ot_hour = isset($_POST['ot_hour']) && $_POST['ot_hour'] !== '' ? (int)$_POST['ot_hour'] : null;
    $workdi_code = trim($_POST['workdi_code'] ?? '');

    $type_id_posted = !empty($_POST['type_id_to_update']) ? (int)$_POST['type_id_to_update'] : null;

    if (!empty($type_name) && !empty($start_time) && !empty($end_time)) {
        $post_data = [
            'type_name' => $type_name,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'color' => $color,
            'priority' => $priority,
            'sequence' => $sequence,
            'ot_color' => $ot_color,
            'ot_hour' => $ot_hour,
            'workdi_code' => $workdi_code,
            'description' => $description,
            'modify_by'=> $user['emp_id'] . "-" . $user['name'] 
        ];

        if (!empty($type_id_posted)) {
            $api_url = "/schedule/workschedule?action=update";
            $post_data['type_id'] = $type_id_posted;
            $op_result = callApi($api_url, 'PUT', $post_data);
            $status_param = 'update_success';
        } else {
            $api_url = "/schedule/workschedule?action=add"; 
            $op_result = callApi($api_url, 'POST', $post_data);
            $status_param = 'add_success';
        }

        if (isset($op_result['status']) && $op_result['status'] === 'success') {
            header("Location: workschedule_setting.php?status={$status_param}");
            exit();
        } else {
            $op_message = $op_result['message'] ?? 'Failed to process Work Schedule.';
            if (isset($op_result['data']['detail']) && is_string($op_result['data']['detail'])) {
                   $op_message = $op_result['data']['detail'];
            }
            $notification = ['type' => 'danger', 'message' => 'Error: ' . htmlspecialchars($op_message)];
        }
    } else {
        $notification = ['type' => 'warning', 'message' => 'Work Schedule Name, Start Time, and End Time cannot be empty.'];
    }
}

if (isset($_GET['status'])) {
    if ($_GET['status'] === 'add_success') $notification = ['type' => 'success', 'message' => 'New Work Schedule added successfully!'];
    elseif ($_GET['status'] === 'update_success') $notification = ['type' => 'success', 'message' => 'Work Schedule updated successfully!'];
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
    
    <title>Setting | Work Schedule</title>
</head>
<body class="p-0">
    <!-- Header Bar -->
    <div class="sticky-top bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center" style="z-index: 1030;">
        <h5 class="m-0 fw-bold text-theme-green" style="text-shadow: 1px 1px 3px rgba(0,0,0,0.1); font-size: 20px;">
            <i class="fa-solid fa-gear me-2"></i>Schedule Settings <i class="fa fa-angle-right mx-2 text-secondary" style="font-size: 0.8em;"></i>
            <span class="text-dark"><i class="fa-solid fa-clock me-1"></i> Work Schedule</span>
        </h5>
        
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-success btn-sm fw-bold px-3 rounded-pill shadow-sm" onclick="openAddModal()">
                <i class="fa-solid fa-plus me-1"></i> Add Schedule
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
                    <table id="userTable" class="table table-hover align-middle mb-0 w-100" style="white-space: nowrap;">
                        <thead class="table-light">
                            <tr>
                                <th class="text-center">ID</th>
                                <th class="text-center">Schedule Name</th>
                                <th class="text-center">Time</th>
                                <th class="text-center">Color</th>
                                <th class="text-center">WorkDI Code</th>
                                <th class="text-center">Priority</th>
                                <th class="text-center">Seq</th>
                                <th class="text-center">OT Hr</th>
                                <th class="text-center">OT Color</th>
                                <th>Description</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($work_schedules as $schedule): ?>
                            <tr>
                                <td class="text-center text-muted"><?= htmlspecialchars($schedule['type_id'] ?? '') ?></td>
                                <td class="text-center fw-bold text-dark fs-6"><?= htmlspecialchars($schedule['type_name'] ?? '') ?></td>
                                <td class="text-center">
                                    <div class="d-inline-flex align-items-center bg-light border rounded-pill px-3 py-1 text-secondary font-monospace" style="font-size: 0.9em;">
                                        <i class="fa-regular fa-clock me-2 text-muted"></i>
                                        <?= secondsToTime($schedule['start_time']) ?> <span class="mx-1">-</span> <?= secondsToTime($schedule['end_time']) ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="d-inline-block rounded-circle border border-2 shadow-sm" 
                                         style="width: 24px; height: 24px; background-color: <?= htmlspecialchars($schedule['color'] ?? '#ffffff') ?>;">
                                    </div>
                                </td>
                                
                                <td class="text-center"><span class="badge bg-secondary"><?= htmlspecialchars($schedule['workdi_code'] ?? '-') ?></span></td>
                                <td class="text-center"><?= htmlspecialchars($schedule['priority'] ?? '0') ?></td>
                                <td class="text-center"><?= htmlspecialchars($schedule['sequence'] ?? '0') ?></td>
                                <td class="text-center"><?= htmlspecialchars($schedule['ot_hour'] ?? '-') ?></td>
                                <td class="text-center">
                                    <?php if(!empty($schedule['ot_color'])): ?>
                                        <div class="d-inline-block rounded-circle border border-2 shadow-sm" 
                                             style="width: 20px; height: 20px; background-color: <?= htmlspecialchars($schedule['ot_color']) ?>;">
                                        </div>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>

                                <td class="text-secondary text-truncate" style="max-width: 150px;" title="<?= htmlspecialchars($schedule['description'] ?? '') ?>">
                                    <?= htmlspecialchars($schedule['description'] ?? '') ?>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($schedule['type_id'])): ?>
                                        <button class="btn btn-outline-secondary btn-action-icon edit-btn border-0 shadow-sm" 
                                            data-id="<?= htmlspecialchars($schedule['type_id']) ?>"
                                            data-name="<?= htmlspecialchars($schedule['type_name']) ?>"
                                            data-start="<?= secondsToTime($schedule['start_time']) ?>"
                                            data-end="<?= secondsToTime($schedule['end_time']) ?>"
                                            data-color="<?= htmlspecialchars($schedule['color']) ?>"
                                            data-priority="<?= htmlspecialchars($schedule['priority'] ?? '0') ?>"
                                            data-sequence="<?= htmlspecialchars($schedule['sequence'] ?? '0') ?>"
                                            data-otcolor="<?= htmlspecialchars($schedule['ot_color'] ?? '#ffffff') ?>"
                                            data-othour="<?= htmlspecialchars($schedule['ot_hour'] ?? '') ?>"
                                            data-workdicode="<?= htmlspecialchars($schedule['workdi_code'] ?? '') ?>"
                                            data-des="<?= htmlspecialchars($schedule['description']) ?>"
                                            title="Edit Work Schedule">
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
        <div class="modal fade" id="scheduleModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                    <div class="modal-header border-bottom-0 pb-0" id="modalHeader">
                        <h5 class="modal-title fw-bold" id="modalTitle"></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST" action="">
                        <div class="modal-body p-4">
                            <input type="hidden" name="type_id_to_update" id="type_id_to_update" value="">

                            <!-- Row 1: Schedule Name | WorkDI Code -->
                            <div class="row mb-3 gx-3">
                                <div class="col-md-8">
                                    <label for="type_name" class="form-label fw-bold text-dark">Schedule Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control bg-light border-0" id="type_name" name="type_name" required maxlength="100" placeholder="e.g. Day Shift, Night Shift...">
                                </div>
                                <div class="col-md-4">
                                    <label for="workdi_code" class="form-label fw-bold text-dark">WorkDI Code</label>
                                    <input type="text" class="form-control bg-light border-0" id="workdi_code" name="workdi_code" maxlength="20" placeholder="Code...">
                                </div>
                            </div>
                            
                            <!-- Row 2: Start Time | End Time -->
                            <div class="row mb-3 gx-3">
                                <div class="col-md-6">
                                    <label for="start_time" class="form-label fw-bold text-dark">Start Time <span class="text-danger">*</span></label>
                                    <input type="time" class="form-control bg-light border-0" id="start_time" name="start_time" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="end_time" class="form-label fw-bold text-dark">End Time <span class="text-danger">*</span></label>
                                    <input type="time" class="form-control bg-light border-0" id="end_time" name="end_time" required>
                                </div>
                            </div>

                            <!-- Row 3: Main Color | OT Color -->
                            <div class="row mb-3 gx-3">
                                <div class="col-md-6">
                                    <label for="color" class="form-label fw-bold text-dark">Main Color</label>
                                    <input type="color" class="form-control form-control-color border-0 p-1 shadow-sm rounded-3 w-100 bg-light" id="color" name="color" value="#3399FF" style="height: 38px; cursor: pointer;">
                                </div>
                                <div class="col-md-6">
                                    <label for="ot_color" class="form-label fw-bold text-dark">OT Color</label>
                                    <input type="color" class="form-control form-control-color border-0 p-1 shadow-sm rounded-3 w-100 bg-light" id="ot_color" name="ot_color" value="#ff9900" style="height: 38px; cursor: pointer;">
                                </div>
                            </div>

                            <!-- Row 4: Priority | Sequence | OT Hour -->
                            <div class="row mb-3 gx-3">
                                <div class="col-md-4">
                                    <label for="priority" class="form-label fw-bold text-dark">Priority(Tier)</label>
                                    <input type="number" class="form-control bg-light border-0" id="priority" name="priority" value="0">
                                </div>
                                <div class="col-md-4">
                                    <label for="sequence" class="form-label fw-bold text-dark">Sequence(ลำดับเวลา)</label>
                                    <input type="number" class="form-control bg-light border-0" id="sequence" name="sequence" value="0">
                                </div>
                                <div class="col-md-4">
                                    <label for="ot_hour" class="form-label fw-bold text-dark">OT Hour(ชั่วโมง OT)</label>
                                    <input type="number" class="form-control bg-light border-0" id="ot_hour" name="ot_hour" placeholder="Hours...">
                                </div>
                            </div>
                            
                            <!-- Row 5: Description -->
                            <div class="mb-2">
                                <label for="description" class="form-label fw-bold text-dark">Description</label>
                                <textarea class="form-control bg-light border-0" id="description" name="description" rows="2" maxlength="255" placeholder="Schedule Description ....."></textarea>
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
            const scheduleModal = new bootstrap.Modal(document.getElementById('scheduleModal'));

            function openAddModal() {
                $('#type_id_to_update').val('');
                $('#type_name').val('');
                $('#start_time').val('');
                $('#end_time').val('');
                $('#color').val('#3399FF');
                $('#priority').val('0');
                $('#sequence').val('0');
                $('#ot_hour').val('');
                $('#ot_color').val('#ff9900');
                $('#workdi_code').val('');
                $('#description').val('');
                
                $('#btnSubmit').removeClass('btn-warning').addClass('btn-success').html('<i class="fa-solid fa-check me-1"></i> Add Schedule');
                $('#modalTitle').html('<i class="fa-solid fa-plus-circle text-success me-2"></i> Add New Schedule');
                scheduleModal.show();
            }

            $(document).ready(function() {
                $('.edit-btn').on('click', function() {
                    const id = $(this).data('id');
                    $('#type_id_to_update').val(id);
                    $('#type_name').val($(this).data('name'));
                    $('#start_time').val($(this).data('start'));
                    $('#end_time').val($(this).data('end'));
                    $('#color').val($(this).data('color') || '#ffffff');
                    
                    $('#priority').val($(this).data('priority'));
                    $('#sequence').val($(this).data('sequence'));
                    $('#ot_hour').val($(this).data('othour'));
                    $('#ot_color').val($(this).data('otcolor') || '#ff9900');
                    $('#workdi_code').val($(this).data('workdicode'));
                    
                    $('#description').val($(this).data('des'));
                    
                    $('#btnSubmit').removeClass('btn-success').addClass('btn-warning text-dark').html('<i class="fa-solid fa-save me-1"></i> Save Changes');
                    $('#modalTitle').html('<i class="fa-solid fa-pen-to-square text-warning me-2"></i> Edit Schedule');
                    scheduleModal.show();
                });

                setTimeout(function() {
                    $('#userTable').DataTable({
                        "paging": true,
                        "ordering": false,
                        "info": true,
                        "searching": true,
                        "responsive": true,
                        "pageLength": 25,
                        "lengthChange": false
                    });
                }, 200);

                const alertBox = $('.alert');
                if (alertBox.length) setTimeout(function() { alertBox.slideUp(400, function() { $(this).remove(); }); }, 3000);
            });
        </script>
    </div>
</body>
</html>
<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';

// ตรวจสอบการเข้าสู่ระบบ
$userlogin = $_SESSION["user_data"] ?? null;
$user_id = $userlogin['user_id'] ?? null;

if (!checkLogin() || !$userlogin) {
    echo '<script>window.top.location.replace("/auth/logon.php?error=Session Expired !");</script>';
    exit();
}

// สร้าง CSRF Token สำหรับความปลอดภัยของ AJAX
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// --- 1. PHP API PROXY HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['proxy_action'])) {
    header('Content-Type: application/json');
    
    // ตรวจสอบ CSRF Token จาก Header
    $headers = getallheaders();
    $request_csrf = $headers['X-CSRF-TOKEN'] ?? $headers['x-csrf-token'] ?? '';
    
    if ($request_csrf !== $_SESSION['csrf_token']) {
        echo json_encode(['status' => 'error', 'detail' => 'Security Validation Failed (CSRF)']);
        exit;
    }

    $action = $_GET['proxy_action'];
    $input = json_decode(file_get_contents('php://input'), true);
    $api_path = "";
    $method = "POST";

    switch ($action) {
        case 'update_mapping': $api_path = "/api/update-permissions"; break;
        case 'save_role':
            $id = $_GET['id'] ?? '';
            $api_path = $id ? "/api/roles/$id" : "/api/roles";
            $method = $id ? "PUT" : "POST";
            break;
        case 'delete_role':
            $id = $_GET['id'] ?? '';
            $api_path = "/api/roles/$id";
            $method = "DELETE";
            break;
        case 'save_perm':
            $id = $_GET['id'] ?? '';
            $api_path = $id ? "/api/permissions/$id" : "/api/permissions";
            $method = $id ? "PUT" : "POST";
            break;
        case 'delete_perm':
            $id = $_GET['id'] ?? '';
            $api_path = "/api/permissions/$id";
            $method = "DELETE";
            break;
    }

    if ($api_path) {
        $response = callApi($api_path, $method, $input);
        echo json_encode($response);
    } else {
        echo json_encode(['status' => 'error', 'detail' => 'Invalid Action']);
    }
    exit;
}

// --- 2. INITIAL DATA FETCH ---
$apiResponse = callApi('/api/role-matrix');
$resData = isset($apiResponse['data']) ? $apiResponse['data'] : [];
$roles = $resData['roles'] ?? [];
$permissions = $resData['permissions'] ?? [];
$mapping = $resData['mapping'] ?? [];

$current_map = [];
foreach ($mapping as $m) {
    $current_map[$m['role_id']][] = (int)$m['permission_id'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setting | Role & Permission</title>
    
    <!-- Fonts & Core CSS -->
    <link href="/static/font/Sarabun.css" rel="stylesheet" />
    <link rel="stylesheet" href="/static/css/content.css"> 
    
    <link rel="stylesheet" href="/static/fontawesome/css/all.css">
    <link rel="stylesheet" href="/static/bootstrap/5.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="/static/datatable/dataTables.bootstrap5.min.css">
    
    <!-- New Modern Specific CSS -->
    <link rel="stylesheet" href="/static/css/role.css">
</head>
<body class="p-0">

<!-- Modern Header Bar -->
<div class="sticky-top bg-white border-bottom py-4 px-4 d-flex justify-content-between align-items-center" style="z-index: 1030;">
    <h5 class="m-0 fw-bold text-theme-green" style="text-shadow: 1px 1px 3px rgba(0,0,0,0.1); font-size: 20px;">
        <i class="fa-solid fa-gear me-2"></i>Role and Permission
    </h5>
</div>

<div class="container-fluid px-4 my-4">
    <!-- Modern Pills Header & Action Buttons -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        
        <!-- Toggle Pills -->
        <ul class="nav nav-pills nav-pills-custom" id="managementTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="config-tab" data-bs-toggle="pill" data-bs-target="#tab-config" type="button">
                    <i class="fa-solid fa-key me-1"></i> Access Config
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="roles-tab" data-bs-toggle="pill" data-bs-target="#tab-roles" type="button">
                    <i class="fa-solid fa-user-tag me-1"></i> Roles List
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="perms-tab" data-bs-toggle="pill" data-bs-target="#tab-perms" type="button">
                    <i class="fa-solid fa-list-check me-1"></i> Permissions
                </button>
            </li>
        </ul>
        
        <div class="d-flex gap-2">
            <button class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" onclick="openRoleModal()">
                <i class="fas fa-plus me-1"></i> New Role
            </button>
            <button class="btn btn-success rounded-pill px-4 fw-bold shadow-sm" onclick="openPermModal()">
                <i class="fas fa-plus me-1"></i> New Permission
            </button>
        </div>
    </div>

    <!-- Tab Content Area -->
    <div class="tab-content tab-content-modern" id="managementTabsContent">
        
        <!-- TAB 1: Configurator -->
        <div class="tab-pane fade show active" id="tab-config">
            <div class="row g-4">
                <!-- Role Selector -->
                <div class="col-md-3">
                    <div class="bg-white border-0 rounded-4 p-4 shadow-sm">
                        <div class="fw-bold text-success mb-3 small text-uppercase" style="letter-spacing: 0.5px;">Select Role to Configure</div>
                        <input type="text" id="role-filter-input" class="form-control bg-light border-0 shadow-sm rounded-pill px-3 mb-4" placeholder="Filter roles...">
                        <div id="role-selection-list" style="max-height: calc(100vh - 260px); overflow-y: auto; scrollbar-width: thin; padding-right: 5px;">
                            <?php foreach ($roles as $r): ?>
                            <div class="role-list-item" onclick='selectRole(<?php echo htmlspecialchars(json_encode($r), ENT_QUOTES, "UTF-8"); ?>)' data-role-id="<?= $r['role_id'] ?>" data-name="<?= htmlspecialchars($r['role_name']) ?>">
                                <i class="<?= $r['icon'] ?: 'fa-solid fa-circle-user' ?> me-3 fs-5" style="color: <?= $r['font_color'] ?>"></i>
                                <span><?= htmlspecialchars($r['role_name']) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Workspace -->
                <div class="col-md-9">
                    <!-- Empty State -->
                    <div id="config-empty-state" class="text-center py-5 border-0 rounded-4 bg-white shadow-sm d-flex flex-column justify-content-center align-items-center" style="min-height: calc(100vh - 180px);">
                        <div class="bg-light rounded-circle d-flex justify-content-center align-items-center mb-4" style="width: 100px; height: 100px;">
                            <i class="fa-solid fa-mouse-pointer fa-2x text-secondary opacity-50"></i>
                        </div>
                        <h5 class="fw-bold text-dark">No Role Selected</h5>
                        <p class="text-muted">Please select a role from the left panel to configure its access permissions.</p>
                    </div>
                    
                    <!-- Active State -->
                    <div id="config-workspace" class="d-none d-flex flex-column">
                        <div class="bg-white border-0 shadow-sm rounded-4 p-4 mb-4 d-flex justify-content-between align-items-center flex-shrink-0">
                            <div class="d-flex align-items-center gap-3">
                                <div class="bg-success bg-opacity-10 text-success rounded-circle d-flex justify-content-center align-items-center" style="width: 48px; height: 48px;">
                                    <i class="fa-solid fa-user-shield fs-4"></i>
                                </div>
                                <div>
                                    <h4 class="fw-bold m-0 text-dark" id="workspace-role-name">Role Name</h4>
                                    <div class="text-muted small mt-1" id="workspace-role-id">Role ID: #</div>
                                </div>
                            </div>
                            <button class="btn btn-success rounded-pill fw-bold px-4 py-2 shadow-sm" onclick="savePermissions()">
                                <i class="fa-solid fa-save me-1"></i> SAVE CHANGES
                            </button>
                        </div>
                        <div id="permission-groups-container" class="pe-2 flex-grow-1" style="overflow-y: auto; scrollbar-width: thin;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 2: Roles List -->
        <div class="tab-pane fade" id="tab-roles">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-body p-0">
                    <div class="table-responsive px-3 py-2">
                        <table class="table table-hover align-middle mb-0 w-100 data-table" id="rolesTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center" style="width: 10%;">ID</th>
                                    <th style="width: 40%;">Role Name</th>
                                    <th class="text-center" style="width: 25%;">Priority</th>
                                    <th class="text-center" style="width: 25%;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($roles as $r): ?>
                                <tr>
                                    <td class="text-center text-muted"><?= $r['role_id'] ?></td>
                                    <td class="fw-bold fs-6 text-dark">
                                        <i class="<?= $r['icon'] ?: 'fa-solid fa-circle-user' ?> me-2 text-secondary opacity-75" style="color: <?= $r['font_color'] ?> !important;"></i><?= htmlspecialchars($r['role_name']) ?>
                                    </td>
                                    <td class="text-center"><span class="badge bg-light text-dark border rounded-pill px-3 py-1"><?= $r['role_priority'] ?></span></td>
                                    <td class="text-center">
                                        <?php if($r['role_id'] != 0): ?>
                                        <button class="btn btn-outline-secondary btn-action-icon border-0 shadow-sm" onclick='editRole(<?php echo htmlspecialchars(json_encode($r), ENT_QUOTES, "UTF-8"); ?>)' title="Edit Role">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>
                                        <button class="btn btn-outline-danger btn-action-icon border-0 shadow-sm ms-1" onclick="deleteItem('role', <?= $r['role_id'] ?>)" title="Delete Role">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                        <?php else: ?>
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary border px-3 py-1 rounded-pill">System Default</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 3: Permissions -->
        <div class="tab-pane fade" id="tab-perms">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-body p-0">
                    <div class="table-responsive px-3 py-2">
                        <table class="table table-hover align-middle mb-0 w-100 data-table" id="permsTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center" style="width: 10%;">ID</th>
                                    <th style="width: 30%;">System Key</th>
                                    <th style="width: 40%;">Description</th>
                                    <th class="text-center" style="width: 20%;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($permissions as $p): ?>
                                <tr>
                                    <td class="text-center text-muted"><?= $p['permission_id'] ?></td>
                                    <td><span class="badge bg-light text-dark border px-3 py-1 rounded-pill font-monospace" style="font-size: 0.85em;"><i class="fa-solid fa-code text-primary me-1"></i> <?= htmlspecialchars($p['permission_name']) ?></span></td>
                                    <td class="text-secondary"><?= htmlspecialchars($p['description']) ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-outline-secondary btn-action-icon border-0 shadow-sm" onclick='editPerm(<?php echo htmlspecialchars(json_encode($p), ENT_QUOTES, "UTF-8"); ?>)' title="Edit Permission">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>
                                        <button class="btn btn-outline-danger btn-action-icon border-0 shadow-sm ms-1" onclick="deleteItem('permission', <?= $p['permission_id'] ?>)" title="Delete Permission">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modals -->
<div class="modal fade" id="roleModal" tabindex="-1" aria-labelledby="roleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form id="roleForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="roleModalLabel"><i class="fa-solid fa-user-tag me-1"></i> Role Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="role_id_input">
                <div class="mb-3">
                    <label for="role_name_input" class="form-label fw-bold">Role Name <span class="text-danger">*</span></label>
                    <input type="text" id="role_name_input" class="form-control" required placeholder="e.g. Manager">
                </div>
                <div class="row gx-3">
                    <div class="col-6 mb-3">
                        <label for="role_priority_input" class="form-label fw-bold">Priority</label>
                        <input type="number" id="role_priority_input" class="form-control" placeholder="0">
                    </div>
                    <div class="col-6 mb-3">
                        <label for="role_color_input" class="form-label fw-bold">Color</label>
                        <input type="color" id="role_color_input" class="form-control form-control-color w-100" title="Choose a color">
                    </div>
                </div>
                <div class="mb-3">
                    <label for="role_icon_input" class="form-label fw-bold">Icon (FontAwesome)</label>
                    <div class="input-group">
                        <span class="input-group-text text-center bg-light" id="icon-preview" style="width: 45px;">
                            <i class="fa-solid fa-user text-secondary"></i>
                        </span>
                        <input type="text" id="role_icon_input" class="form-control" placeholder="fa-solid fa-user">
                        <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Choose
                        </button>
                        <!-- Icon Picker Dropdown -->
                        <div class="dropdown-menu dropdown-menu-end p-3 shadow" style="width: 320px;">
                            <div class="small fw-bold text-muted mb-2">Select Icon</div>
                            <div class="d-flex flex-wrap gap-2" id="icon-picker-grid" style="max-height: 220px; overflow-y: auto; scrollbar-width: thin;">
                                <!-- Icons injected via JS -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save me-1"></i> Save Role</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="permModal" tabindex="-1" aria-labelledby="permModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form id="permForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="permModalLabel"><i class="fa-solid fa-key me-1"></i> Permission Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="perm_id_input">
                <div class="mb-3">
                    <label for="perm_name_input" class="form-label fw-bold">System Key <span class="text-danger">*</span></label>
                    <input type="text" id="perm_name_input" class="form-control font-monospace" required placeholder="module.action">
                </div>
                <div class="mb-3">
                    <label for="perm_desc_input" class="form-label fw-bold">Description</label>
                    <textarea id="perm_desc_input" class="form-control" rows="3" placeholder="What does this allow?"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success"><i class="fa-solid fa-save me-1"></i> Save Permission</button>
            </div>
        </form>
    </div>
</div>

<!-- Scripts -->
<script src="/static/jquery/jquery-3.6.0.min.js"></script>
<script src="/static/bootstrap/5.3.1/js/bootstrap.bundle.min.js"></script>
<script src="/static/datatable/jquery.dataTables.min.js"></script>
<script src="/static/datatable/dataTables.bootstrap5.min.js"></script>

<script>
    const CSRF_TOKEN = "<?= $csrf ?>";
    const permsData = <?= json_encode($permissions) ?>;
    const currentMapping = <?= json_encode($current_map) ?>;
    let activeRoleId = null;
    let localMapping = JSON.parse(JSON.stringify(currentMapping));

    const Notify = {
        success: (msg) => alert('SUCCESS: ' + msg),
        error: (msg) => alert('ERROR: ' + msg),
        confirm: async (title, text) => { return { isConfirmed: confirm(title + "\n" + text) }; },
        loading: () => document.body.style.cursor = 'wait',
        closeLoading: () => document.body.style.cursor = 'default'
    };

    $(document).ready(function() {
        if ($.fn.DataTable) {
            $('.data-table').DataTable({ 
                "pageLength": 25, 
                "language": { "search": "Search:", "emptyTable": "No data found" },
                "responsive": true
            });
        }
        $('#role-filter-input').on('keyup', function() {
            let v = $(this).val().toLowerCase();
            $('.role-list-item').each(function() { $(this).toggle($(this).data('name').toLowerCase().indexOf(v) > -1); });
        });

        // --- Icon Picker Setup ---
        const commonIcons = [
            'fa-solid fa-user', 'fa-solid fa-user-tie', 'fa-solid fa-user-shield', 'fa-solid fa-user-gear',
            'fa-solid fa-users', 'fa-solid fa-users-gear', 'fa-solid fa-crown', 'fa-solid fa-star',
            'fa-solid fa-key', 'fa-solid fa-shield', 'fa-solid fa-lock', 'fa-solid fa-unlock',
            'fa-solid fa-gear', 'fa-solid fa-sliders', 'fa-solid fa-wrench', 'fa-solid fa-briefcase',
            'fa-solid fa-building', 'fa-solid fa-building-user', 'fa-solid fa-clipboard', 'fa-solid fa-check-circle',
            'fa-solid fa-envelope', 'fa-solid fa-file-alt', 'fa-solid fa-folder', 'fa-solid fa-globe',
            'fa-solid fa-laptop', 'fa-solid fa-mobile-alt', 'fa-solid fa-network-wired', 'fa-solid fa-server',
            'fa-solid fa-database', 'fa-solid fa-cloud', 'fa-solid fa-chart-pie', 'fa-solid fa-chart-line'
        ];
        
        const iconGrid = $('#icon-picker-grid');
        commonIcons.forEach(icon => {
            const btn = $(`<button type="button" class="btn btn-light border-0 shadow-sm d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;" title="${icon}"><i class="${icon} fs-5 text-secondary"></i></button>`);
            btn.on('click', function() {
                $('#role_icon_input').val(icon).trigger('input'); // เปลี่ยนค่าและอัปเดตพรีวิว
            });
            iconGrid.append(btn);
        });

        // Update preview dynamically when input changes
        $('#role_icon_input').on('input', function() {
            const val = $(this).val() || 'fa-solid fa-user';
            $('#icon-preview').html(`<i class="${val} text-secondary"></i>`);
        });
    });

    async function callProxy(action, payload = {}, id = '') {
        Notify.loading();
        const currentUrl = window.location.href.split('?')[0];
        const url = `${currentUrl}?proxy_action=${action}${id ? '&id=' + id : ''}`;
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                body: JSON.stringify(payload)
            });
            const text = await res.text();
            let data;
            try { data = JSON.parse(text); } 
            catch(e) { throw new Error('Invalid Server Response: ' + text.substring(0, 50)); }
            Notify.closeLoading();
            if (data.status === 'success' || (data.message && !data.detail)) return data;
            throw new Error(data.detail || data.message || 'Operation Failed');
        } catch (err) {
            Notify.closeLoading();
            Notify.error(err.message);
            return null;
        }
    }

    function selectRole(role) {
        activeRoleId = role.role_id;
        $('.role-list-item').removeClass('active');
        $(`.role-list-item[data-role-id="${role.role_id}"]`).addClass('active');
        $('#config-empty-state').addClass('d-none');
        $('#config-workspace').removeClass('d-none');
        $('#workspace-role-name').text(role.role_name).css('color', role.font_color || '#212529');
        $('#workspace-role-id').text('Role ID: #' + role.role_id);
        renderPermissionGroups();
    }

    function renderPermissionGroups() {
        const container = $('#permission-groups-container').empty();
        const groups = {};
        permsData.forEach(p => {
            const prefix = p.permission_name.split('.')[0] || 'other';
            if (!groups[prefix]) groups[prefix] = [];
            groups[prefix].push(p);
        });

        Object.keys(groups).sort().forEach(groupName => {
            const card = $('<div class="perm-group-card"></div>');
            card.append(`<div class="perm-group-header"><i class="fa-solid fa-cube me-2 opacity-50"></i> ${groupName.toUpperCase()} MODULE</div>`);
            const list = $('<div class="bg-white"></div>');
            groups[groupName].forEach(p => {
                const isChecked = localMapping[activeRoleId]?.includes(parseInt(p.permission_id));
                const item = $(`
                    <div class="perm-item-row d-flex align-items-center justify-content-between">
                        <div>
                            <div class="fw-bold text-dark fs-6">${p.permission_name}</div>
                            <div class="text-muted small mt-1">${p.description||'No description provided.'}</div>
                        </div>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" ${isChecked?'checked':''} ${activeRoleId==0?'disabled':''} onchange="toggleLocalPerm(${p.permission_id}, this.checked)">
                        </div>
                    </div>
                `);
                list.append(item);
            });
            container.append(card.append(list));
        });
    }

    function toggleLocalPerm(pid, checked) {
        if (!localMapping[activeRoleId]) localMapping[activeRoleId] = [];
        if (checked) { if (!localMapping[activeRoleId].includes(pid)) localMapping[activeRoleId].push(pid); }
        else { localMapping[activeRoleId] = localMapping[activeRoleId].filter(id => id !== pid); }
    }

    async function savePermissions() {
        if (!activeRoleId) return;
        const confirm = await Notify.confirm('Confirm Save?', 'Update permissions for this role?');
        if (confirm.isConfirmed) {
            const res = await callProxy('update_mapping', { role_id: parseInt(activeRoleId), permission_ids: localMapping[activeRoleId] || [] });
            if (res) Notify.success('Permissions updated successfully.');
        }
    }

    function openRoleModal() { 
        $('#roleForm')[0].reset(); 
        $('#role_id_input').val(''); 
        $('#role_icon_input').trigger('input'); // รีเซ็ต Preview
        new bootstrap.Modal('#roleModal').show(); 
    }
    
    function editRole(r) { 
        $('#role_id_input').val(r.role_id); 
        $('#role_name_input').val(r.role_name); 
        $('#role_priority_input').val(r.role_priority); 
        $('#role_color_input').val(r.font_color); 
        $('#role_icon_input').val(r.icon).trigger('input'); // โหลดค่าเก่าใส่ Preview
        new bootstrap.Modal('#roleModal').show(); 
    }
    
    $('#roleForm').on('submit', async e => {
        e.preventDefault();
        const res = await callProxy('save_role', { role_name:$('#role_name_input').val(), role_priority:parseInt($('#role_priority_input').val()), font_color:$('#role_color_input').val(), icon:$('#role_icon_input').val() }, $('#role_id_input').val());
        if (res) location.reload();
    });

    function openPermModal() { $('#permForm')[0].reset(); $('#perm_id_input').val(''); new bootstrap.Modal('#permModal').show(); }
    function editPerm(p) { $('#perm_id_input').val(p.permission_id); $('#perm_name_input').val(p.permission_name); $('#perm_desc_input').val(p.description); new bootstrap.Modal('#permModal').show(); }
    $('#permForm').on('submit', async e => {
        e.preventDefault();
        const res = await callProxy('save_perm', { permission_name:$('#perm_name_input').val(), description:$('#perm_desc_input').val() }, $('#perm_id_input').val());
        if (res) location.reload();
    });

    async function deleteItem(type, id) {
        const confirm = await Notify.confirm('Delete?', 'This action cannot be undone!');
        if (confirm.isConfirmed) {
            const res = await callProxy(type === 'role' ? 'delete_role' : 'delete_perm', {}, id);
            if (res) location.reload();
        }
    }
</script>
</body>
</html>
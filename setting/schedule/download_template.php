<?php
ob_start(); 
set_time_limit(0); 
ini_set('memory_limit', '512M'); 
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
$token = isset($_COOKIE["token"]) ? $_COOKIE["token"] : null;
$user = isset($_SESSION["user_data"]) ? $_SESSION["user_data"] : null;
if (!checkLogin() || !$user || !$token) {
    echo '<script>window.top.location.replace("/auth/logon.php?error=Session Expired !");</script>';
    exit();
}
if(!hasPermission('setting.schedule_template')){
    http_response_code(403);
    exit('
        <div style="display:flex; justify-content:center; align-items:center; height:100vh; font-family:Sarabun, Arial;">
            <h1 style="color:#d9534f;"><b>Access Denied !</b></h1>
        </div>
    ');
}
/* =================================================================================
   PART 1: MASTER DATA PREPARATION
   ================================================================================= */
$month  = (int)($_POST['month'] ?? date('n'));
$year   = (int)($_POST['year']  ?? date('Y'));
$currentYearMonth = sprintf('%04d-%02d', $year, $month);
$daysInMonth      = (int)date('t', strtotime("$currentYearMonth-01"));
$teamFilter       = $_POST['team'] ?? '';
$action           = $_POST['action'] ?? '';
$msg              = "";

// --- 1. Fetch Users (API) ---
$result = callApi("/data/schedule?year_month=".$currentYearMonth, "GET");
$userData = (isset($result['status']) && $result['status'] === 'success') ? ($result['data'] ?? []) : [];

$user_lookup = [];
$team_lookup = [];
$name_lookup = []; 
$teamList    = []; 

foreach ($userData as $u) {
    $eid = $u['employee_id'] ?? '';
    if (empty($eid)) continue;
    
    $user_lookup[$eid] = $u['id'] ?? $u['user_id'] ?? 0;
    $team_lookup[$eid] = $u['team'] ?? '';
    
    $fname = $u['thai_firstname'] ?? '';
    $lname = $u['thai_lastname'] ?? '';
    $name_lookup[$eid] = trim("$fname $lname");

    if (!empty($u['team'])) $teamList[$u['team']] = $u['team'];
}
ksort($teamList);

// --- 2. Work Groups ---
$resWg = callApi("/schedule/workgroup?action=get", "GET");
$wgData = ($resWg['status'] ?? '') === 'success' ? ($resWg['data'] ?? []) : [];
$workgroup_lookup = [];
foreach ($wgData as $wg) {
    $workgroup_lookup[$wg['team_workgroup']][$wg['work_group']] = $wg['work_group_id'];
}

// --- 3. Work Schedules ---
$resWs = callApi("/schedule/workschedule?action=get", "GET"); 
$wsData = ($resWs['status'] ?? '') === 'success' ? ($resWs['data'] ?? []) : [];
$workschedule_lookup = [];
foreach ($wsData as $ws) {
    $workschedule_lookup[$ws['type_name']] = $ws['type_id'];
}

/* =================================================================================
   PART 2: ACTIONS
   ================================================================================= */

// --- DOWNLOAD TEMPLATE ---
// --- DOWNLOAD TEMPLATE (รองรับหลาย slot ต่อวัน) ---
if ($action === 'download') {

    // ดึง schedule ที่มีอยู่แล้ว
    $resSched = callApi(
        "/schedule/monthschedule?action=get&month=" . $currentYearMonth,
        "GET"
    );

    $existingSched = [];

    if (($resSched['status'] ?? '') === 'success' && !empty($resSched['data'])) {

        foreach ($resSched['data'] as $row) {

            $eid = $row['employee_id'] ?? '';
            if ($eid === '') continue;

            $dayNum = (int)date('j', strtotime($row['schedule_date']));

            // สร้างค่า schedule
            $val = $row['type_name'] ?? '';

            if (($row['status'] ?? '') === 'OT') {
                $val = 'O' . $val;
            }

            if (!empty($row['work_group'])) {
                $val .= ',' . $row['work_group'];
            }

            // เก็บหลาย slot ต่อวัน
            $existingSched[$eid][$dayNum][] = $val;
        }
    }

    // จัด user ตาม shift
    $exportUsers = [];
    foreach ($userData as $u) {
        if ($teamFilter && ($u['team'] ?? '') !== $teamFilter) continue;

        $shift = $u['shift_name'] ?? 'No Shift';
        $exportUsers[$shift][] = $u;
    }
    ksort($exportUsers);

    // header Excel
    $filename = "Schedule_Template_{$currentYearMonth}.xls";
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");
    ?>

    <html xmlns:o="urn:schemas-microsoft-com:office:office"
          xmlns:x="urn:schemas-microsoft-com:office:excel"
          xmlns="http://www.w3.org/TR/REC-html40">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
        <style>
            .meta  { background:#fff2cc; border:1px solid #000; }
            .head  { background:#f1f1f1; font-weight:bold; text-align:center; border:1px solid #000; }
            .cell  { border:1px solid #ccc; text-align:center; mso-number-format:"\@"; }
            .name  { border:1px solid #ccc; text-align:left; padding-left:5px; }
            .shift { background:#ddebf7; font-weight:bold; border:1px solid #000; text-align:left; }
        </style>
    </head>

    <body>
    <table>
        <tr>
            <td class="meta">SystemKey:</td>
            <td class="meta" colspan="<?= 1 + $daysInMonth ?>">
                <?= htmlspecialchars($currentYearMonth) ?>
            </td>
        </tr>

        <thead>
        <tr>
            <th class="head">Employee ID</th>
            <th class="head">Name - Surname</th>
            <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                <th class="head" style="width:55px;"><?= $d ?></th>
            <?php endfor; ?>
        </tr>
        </thead>

        <tbody>
        <?php foreach ($exportUsers as $shift => $users): ?>
            <tr>
                <td colspan="<?= 2 + $daysInMonth ?>" class="shift">
                    Shift: <?= htmlspecialchars($shift) ?>
                </td>
            </tr>

            <?php foreach ($users as $u): ?>
                <?php $emid = $u['employee_id'] ?? ''; ?>
                <tr>
                    <td class="cell"><?= htmlspecialchars($emid) ?></td>
                    <td class="name"><?= htmlspecialchars($name_lookup[$emid] ?? '') ?></td>

                    <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                        <?php
                        $cellValue = '';
                        if (!empty($existingSched[$emid][$d])) {
                            // รวมหลาย slot ด้วย |
                            $cellValue = implode(' | ', $existingSched[$emid][$d]);
                        }
                        ?>
                        <td class="cell"><?= htmlspecialchars($cellValue) ?></td>
                    <?php endfor; ?>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    </body>
    </html>

    <?php
    exit;
}
// --- CLEAR ---
if ($action === 'clear') {
    //$msg = "<div class='alert success'>✅ Schedule deleted.</div>"; 
    $clearSchedule = callApi("/schedule/monthschedule?action=delete&month=".urlencode($currentYearMonth)."&team=".$teamFilter,"POST");
    if($clearSchedule["status"] == "success"){
        $msg = "<div class='alert success'><i class='fa-solid fa-trash'></i> ".$clearSchedule["message"]."</div>"; 
    }
    else{
        $msg = "<div class='alert error'><i class='fa-solid fa-circle-exclamation' style='color: #b31e1e;'></i> ".$clearSchedule["message"]."</div>"; 
    }
}

// --- PREVIEW ---
if ($action === 'preview' && isset($_FILES['import_file'])) {
    if ($_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
        $handle = fopen($_FILES['import_file']['tmp_name'], "r");
        $rows = [];
        while (($data = @fgetcsv($handle, 10000, ",", "\"", "\\")) !== FALSE) {
            if (array_filter($data)) $rows[] = $data; 
        }
        fclose($handle);

        $fileYM = '';
        if (isset($rows[0])) {
            foreach($rows[0] as $cell) {
                if (preg_match('/^\d{4}-\d{2}$/', trim($cell))) { $fileYM = trim($cell); break; }
            }
        }

        if ($fileYM === $currentYearMonth) {
            $_SESSION['upload_data'] = $rows;
            $_SESSION['upload_ym']   = $currentYearMonth;
            $action = 'review_mode';
            $msg = "<div class='alert success'><i class='fa-solid fa-circle-check' style='color: #10a305;'></i> File Validated ($fileYM). Ready to save.</div>";
        } else {
            $msg = "<div class='alert error'>❌ Error: File month ($fileYM) does not match ($currentYearMonth).</div>";
        }
    } else {
        $msg = "<div class='alert error'>❌ Upload Failed.</div>";
    }
}

// --- SAVE DATA (รองรับหลาย slot ต่อวัน) ---
if ($action === 'save_data' && !empty($_SESSION['upload_data'])) {

    $rows       = $_SESSION['upload_data'];
    $targetYM   = $_SESSION['upload_ym'];
    $daysTarget = (int)date('t', strtotime("$targetYM-01"));

    // หา header วันที่ (column 1)
    $hIdx = -1;
    $dStart = -1;
    foreach ($rows as $i => $r) {
        $fOne = array_search('1', $r);
        if ($fOne !== false) {
            $hIdx   = $i;
            $dStart = $fOne;
            break;
        }
    }

    if ($hIdx === -1) {
        $msg = "<div class='alert error'>❌ Format Error: Cannot find date header.</div>";
    } else {

        $insertData = [];

        // loop ข้อมูล user
        for ($i = $hIdx + 1; $i < count($rows); $i++) {

            $r = $rows[$i];

            // ข้ามแถว Shift
            if (strpos(strtolower($r[0] ?? ''), 'shift:') !== false) {
                continue;
            }

            $emid = trim($r[0] ?? '');
            if ($emid === '' || !isset($user_lookup[$emid])) {
                continue;
            }

            $uID  = $user_lookup[$emid];
            $team = $team_lookup[$emid] ?? '';

            // loop วันที่
            for ($d = 1; $d <= $daysTarget; $d++) {

                $colIdx = $dStart + ($d - 1);
                $rawVal = trim($r[$colIdx] ?? '');

                if ($rawVal === '') {
                    continue;
                }

                // 🔥 แยกหลาย slot ด้วย |
                $slots = array_map('trim', explode('|', $rawVal));

                foreach ($slots as $slot) {

                    if ($slot === '') continue;

                    // แยก type , workgroup
                    $parts = array_map('trim', explode(',', $slot));

                    if (count($parts) === 1) {
                        $val = $parts[0];
                        // ดึงกลุ่มตัวอักษรภาษาอังกฤษด้านหน้าออกมาเป็น Type
                        if (preg_match('/^([A-Za-z]+)(.*)$/', $val, $matches)) {
                            $letters = $matches[1]; // ตัวอักษร เช่น D, N, OD
                            $rest = $matches[2];    // ส่วนที่เหลือ เช่น 1, 2, ว่างๆ
                            
                            $typeCode = $letters;
                            $workGroup = ($rest === '') ? null : $val;

                            // กรณีเป็น OT (เช่น OD1) ให้ตัด O ด้านหน้าออกจากชื่อ WorkGroup (ให้เหลือแค่ D1)
                            if (strtoupper($letters[0]) === 'O' && strlen($letters) > 1 && $rest !== '') {
                                $workGroup = substr($val, 1);
                            }
                        } else {
                            $typeCode = $val;
                            $workGroup = null;
                        }
                    } else {
                        // กรณีใส่ลูกน้ำมาแบบเดิม (เช่น D,D1)
                        $typeCode  = $parts[0] ?? '';
                        $workGroup = $parts[1] ?? null;
                    }

                    if ($typeCode === '') continue;

                    // ตรวจ OT
                    if (str_starts_with(strtoupper($typeCode), 'O')) {
                        $typeCode = substr($typeCode, 1);
                        $s_status = 'OT';
                        $s_remark = 'ทำ OT ปริมาณงานเพิ่มขึ้น';
                    } else {
                        $s_status = 'Normal';
                        $s_remark = '';
                    }

                    // หา schedule id
                    $sID = $workschedule_lookup[$typeCode] ?? null;
                    if (!$sID) {
                        continue; // กัน schedule ไม่ถูกต้อง
                    }

                    // หา workgroup id
                    $gID = null;
                    if ($workGroup && isset($workgroup_lookup[$team][$workGroup])) {
                        $gID = $workgroup_lookup[$team][$workGroup];
                    }

                    // เพิ่มข้อมูลเข้า batch
                    $insertData[] = [
                        'user_id'          => $uID,
                        'work_group_id'    => $gID,
                        'work_schedule_id' => $sID,
                        'schedule_date'    => "$targetYM-" . sprintf('%02d', $d),
                        'status'           => $s_status,
                        'remark'           => $s_remark
                    ];
                }
            }
        }

        // บันทึกข้อมูล
        if (!empty($insertData)) {

            $total   = count($insertData);
            $batches = array_chunk($insertData, 300);
            $processed = 0;

            if (ob_get_level() > 0) ob_flush();
            flush();

            foreach ($batches as $chunk) {
                callApi("/api/update/schedule", "POST", $chunk);
                $processed += count($chunk);

                echo "<script>
                        const p = document.querySelector('.overlay-process p');
                        if(p){ p.innerText = '{$processed} / {$total}'; }
                      </script>";

                if (ob_get_level() > 0) ob_flush();
                flush();
            }

            unset($_SESSION['upload_data'], $_SESSION['upload_ym']);

            $msg = "<div class='alert success'>
                        <i class='fa-solid fa-floppy-disk'></i>
                        Saved Successfully! ({$total} records)
                    </div>";

            $action = '';
            echo "<script>
                    const ov = document.querySelector('.overlay-process');
                    if(ov){ ov.style.display='none'; }
                  </script>";

        } else {
            $msg = "<div class='alert error'>⚠️ No valid data to save.</div>";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="stylesheet" href="/static/fontawesome/css/all.css">
<link rel="stylesheet" href="/static/bootstrap/5.3.1/css/bootstrap.min.css">
<script src="/static/bootstrap/5.3.1/js/bootstrap.bundle.min.js"></script>
<script src="/static/jquery/jquery-3.6.0.min.js"></script>
<link rel="stylesheet" href="/static/datatable/dataTables.bootstrap5.min.css">
<script src="/static/datatable/jquery.dataTables.min.js"></script>
<script src="/static/datatable/dataTables.bootstrap5.min.js"></script>
<link rel="stylesheet" href="/static/css/content.css">
<title>Schedule Import</title>
<style>
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; background: #f4f6f9; color: #333; margin: 0; }
    .container { width: 100%; max-width: 100%; margin: 0; background: #fff; padding: 20px; box-sizing: border-box; border-bottom: 2px solid #ddd; }
    h2 { margin-top: 0; border-bottom: 2px solid #eee; padding-bottom: 10px; color:#444; }
    .control-panel { background:#f9f9f9; padding:15px; border-radius:5px; margin-bottom:20px; border:1px solid #eee; }
    select, input[type="file"] { padding: 8px; border: 1px solid #ccc; border-radius: 4px; margin-right: 10px; }
    button { padding: 8px 15px; border: none; border-radius: 4px; cursor: pointer; font-weight: 500; font-size:14px; transition:0.2s; }
    .btn-blue { background: #007bff; color: white; } .btn-blue:hover { background: #0069d9; }
    .btn-red{ background:rgb(240, 81, 81); color: white; } .btn-red:hover { background:rgb(224, 47, 47); }
    .btn-green { background: #28a745; color: white; } .btn-green:hover { background: #218838; }
    .btn-gray { background: #6c757d; color: white; } .btn-gray:hover { background: #5a6268; }
    .alert { padding: 12px; border-radius: 4px; margin-bottom: 20px; }
    .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    .table-wrapper { overflow-x: auto; max-height: 60vh; border: 1px solid #ddd; margin-top: 20px; }
    table { width: 100%; border-collapse: collapse; font-size: 10px; }
    th, td { border: 1px solid #ddd; padding: 6px; text-align: center; white-space: nowrap; }
    th { background: #f1f1f1; position: sticky; top: 0; z-index: 10; }
    .name { border:1px solid #ccc; text-align:left; padding-left:5px; vertical-align:middle; }
    .highlight { background-color: #e6f7ff; color: #0056b3; font-weight: bold; }
    .overlay-process { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.9); z-index:9999; display:flex; flex-direction:column; justify-content:center; align-items:center; }
    .spinner { width: 50px; height: 50px; border: 5px solid #f3f3f3; border-top: 5px solid #3498db; border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 15px; }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

    /* Header Bar Style */
    .header-bar { 
        background: #fff; 
        border-bottom: 1px solid #ddd; 
        padding: 15px 25px; 
        margin-bottom: 0; 
    }
    .header-title { 
        font-family: "Sarabun", "Arial", "Helvetica", sans-serif; 
        font-weight: bold; 
        font-size: 20px; 
        color: rgb(30, 133, 64); 
    }
</style>
<script>
    function confirmSave() {
        if(confirm('Are you sure you want to update the schedule to Database?')) {
            document.querySelector('.overlay-process').style.display = 'flex';
            return true;
        }
        return false;
    }
</script>
</head>
<body class="p-0">
    <div class="header-bar d-flex justify-content-between align-items-center">
        <span class="header-title">
            <i class="fa-solid fa-file-import"></i> Template and Import Schedule
        </span>
        <div class="d-flex align-items-center gap-4 ms-auto">
            <div class="btn-group" role="group">
                <a href="schedule_setting.php" class="btn btn-outline-success btn-sm">
                    <i class="fa-solid fa-arrow-left"></i> Back
                </a>
            </div>
        </div>
    </div>

<div class="overlay-process" style="display:none;">
    <div class="spinner"></div>
    <h3>Processing...</h3>
    <p>Please wait.</p>
</div>

<div class="container" style="margin-top: 20px;">
    <?= $msg ?>

    <div class="control-panel">
        <form method="POST" id="filterForm">
            <label>Month:</label>
            <select name="month" onchange="submitFilter()">
                <?php
                for($m=1;$m<=12;$m++){
                    echo "<option value='$m' ".($m==$month?'selected':'').">".
                        date('F', mktime(0,0,0,$m,1)).
                        "</option>";
                }
                ?>
            </select>

            <select name="year" onchange="submitFilter()">
                <?php
                for($y=date('Y')-1;$y<=date('Y')+1;$y++){
                    echo "<option value='$y' ".($y==$year?'selected':'').">$y</option>";
                }
                ?>
            </select>

            <label style="margin-left:15px;">Team:</label>
            <select name="team" onchange="submitFilter()">
                <option value="">All Teams</option>
                <?php
                foreach($teamList as $t){
                    echo "<option value='$t' ".($teamFilter==$t?'selected':'').">$t</option>";
                }
                ?>
            </select>
            <button type="submit" name="action" value="view" class="btn-gray"><i class="fa-solid fa-spell-check"></i> Set Filter</button>
            <button type="submit" name="action" value="download" class="btn-blue"><i class="fa-solid fa-download"></i> Download Template</button>
            <?php
            $teamText = $teamFilter ? "Team: $teamFilter" : "Team: ALL TEAMS";
            $confirmMsg = "Clear schedule for $month-$year\\n$teamText";
            ?>

            <button type="submit"
                    name="action"
                    value="clear"
                    class="btn-red"
                    onclick="return confirm('<?= htmlspecialchars($confirmMsg, ENT_QUOTES) ?>')">
                    <i class="fa-solid fa-trash-can"></i> Clear schedule
            </button>
        </form>
    </div>

    <?php if($action !== 'review_mode'): ?>
    <div style="border: 2px dashed #ccc; padding:30px; text-align:center; border-radius:5px; background:#fff;">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="month" value="<?=$month?>">
            <input type="hidden" name="year" value="<?=$year?>">
            <h3 style="margin-top:0; color:#555;">Upload CSV File</h3>
            <p style="font-size:14px; color:#666;">Format: <code>Schedule,WorkGroup</code> (e.g., <code>D,D1</code>) or Shorthand (e.g., <code>D1</code>, <code>N</code>)</p>
            <div style="display:flex; justify-content:center; align:center; gap:10px;">
                <input class="form-control w-50" type="file" name="import_file" accept=".csv" required>
                <button type="submit" name="action" value="preview" class="btn-green"><i class="fa-solid fa-magnifying-glass"></i> Preview Data</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if($action === 'review_mode' && !empty($_SESSION['upload_data'])): ?>
        <?php $pRows = $_SESSION['upload_data']; $hIdx = -1; foreach($pRows as $i=>$r){if(array_search('1',$r)!==false){$hIdx=$i;break;}} ?>
        <div>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px;">
                <h3 style="margin:0;">Preview Data (<?= $_SESSION['upload_ym'] ?>)</h3>
                <form method="POST">
                    <input type="hidden" name="month" value="<?=$month?>">
                    <input type="hidden" name="year" value="<?=$year?>">
                    <button type="submit" name="action" value="cancel" class="btn-gray">Cancel</button>
                    <button type="submit" name="action" value="save_data" class="btn-green" onclick="return confirmSave();"><i class="fa-regular fa-floppy-disk"></i> Confirm Save</button>
                </form>
            </div>
            <div class="table-wrapper">
                <table>
                    <?php foreach($pRows as $idx => $r): ?>
                        <tr <?= ($idx==$hIdx)?'style="background:#e9ecef; font-weight:bold;"':'' ?>>
                            <?php foreach($r as $cIdx => $val): ?>
                                <?php 
                                    $displayVal = htmlspecialchars($val);
                                    $style = "text-align:left;";
                                    
                                    if ($idx > $hIdx) {
                                        if ($cIdx == 1) {
                                            $emid = trim($r[0] ?? '');
                                            if (isset($name_lookup[$emid])) {
                                                $displayVal = htmlspecialchars($name_lookup[$emid]);
                                            }
                                        } elseif ($cIdx > 1) {
                                            $rawVal = trim($val);
                                            if (!empty($rawVal)) {
                                                $style = "class='highlight'"; 
                                                
                                                // จำลองการแยกรูปแบบย่อแบบเดียวกับตอน Save
                                                $slots = array_map('trim', explode('|', $rawVal));
                                                $parsedSlots = [];
                                                foreach ($slots as $slot) {
                                                    if ($slot === '') continue;
                                                    
                                                    $parts = array_map('trim', explode(',', $slot));
                                                    if (count($parts) === 1) {
                                                        $sVal = $parts[0];
                                                        if (preg_match('/^([A-Za-z]+)(.*)$/', $sVal, $matches)) {
                                                            $letters = $matches[1];
                                                            $rest = $matches[2];
                                                            
                                                            $typeCode = $letters;
                                                            $workGroup = ($rest === '') ? null : $sVal;

                                                            if (strtoupper($letters[0]) === 'O' && strlen($letters) > 1 && $rest !== '') {
                                                                $workGroup = substr($sVal, 1);
                                                            }
                                                            $parsedSlots[] = $typeCode . ($workGroup ? ',' . $workGroup : '');
                                                        } else {
                                                            $parsedSlots[] = $sVal;
                                                        }
                                                    } else {
                                                        $parsedSlots[] = trim($parts[0]) . (isset($parts[1]) && trim($parts[1]) !== '' ? ',' . trim($parts[1]) : '');
                                                    }
                                                }
                                                if (!empty($parsedSlots)) {
                                                    $displayVal = htmlspecialchars(implode(' | ', $parsedSlots));
                                                }
                                            }
                                        }
                                    }
                                ?>
                                <td <?=$style?>><?= $displayVal ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>
<script>
function submitFilter(){
    const form = document.getElementById('filterForm');
    let action = form.querySelector('input[name="action"]');
    if(!action){
        action = document.createElement('input');
        action.type = 'hidden';
        action.name = 'action';
        form.appendChild(action);
    }
    action.value = 'view';

    form.submit();
}
</script>
</body>
</html>
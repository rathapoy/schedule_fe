<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
checkLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: user.php");
    exit();
}

// Check CSRF
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("CSRF token validation failed.");
}

$user_id = $_POST['user_id'] ?? null;
$employee_id = $_POST['employee_id'] ?? null;

// เตรียมข้อมูล Payload
$payload = [
    "employee_id"     => $employee_id,
    "thai_initialname" => $_POST['thai_initialname'] ?? null,
    "thai_firstname"   => $_POST['thai_firstname'] ?? null,
    "thai_lastname"    => $_POST['thai_lastname'] ?? null,
    "eng_initialname"  => $_POST['eng_initialname'] ?? null,
    "eng_firstname"    => $_POST['eng_firstname'] ?? null,
    "eng_lastname"     => $_POST['eng_lastname'] ?? null,
    "email"            => $_POST['email'] ?? null,
    "manager_id"       => $_POST['manager_id'] ?? null,
    "approver_id"      => $_POST['approver_id'] ?? null,
    "division"         => $_POST['division'] ?? null,
    "department"       => $_POST['department'] ?? null,
    "role_id"          => $_POST['role_id'] ?? null,
    "team"             => $_POST['team'] ?? null,
    "shift_id"         => $_POST['shift_id'] ?? null,
    "is_active"        => isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1,
    "scheduled"        => isset($_POST['scheduled']) ? (int)$_POST['scheduled'] : 0,
];

if (!empty($user_id)) {
    // แก้ไขข้อมูล (Update)
    $payload['user_id'] = $user_id;
    $result = callApi('/user/update', 'PUT', $payload);
    $status_key = "update_success";
} else {
    // เพิ่มข้อมูลใหม่ (Add)
    $result = callApi('/user/add', 'POST', $payload);
    $status_key = "add_success";
}

if (isset($result['status']) && $result['status'] === 'success') {
    if (!empty($user_id)) {
        $_SESSION['employee_id'] = $employee_id;
        header("Location: userdetail.php?status=update_success");
    } else {
        header("Location: user.php?status=add_success");
    }
} else {
    // แสดง Error จาก API (FastAPI มักส่งในคีย์ 'detail')
    $error_msg = $result['detail'] ?? ($result['message'] ?? 'Unknown API Error');
    die("<div style='font-family:Sarabun; padding:20px;'><h3>Create/Update Failed</h3><hr><p>API Error: " . htmlspecialchars($error_msg) . "</p><a href='javascript:history.back()'>Go Back</a></div>");
}
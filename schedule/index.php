<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
checkLogin();
$token = isset($_COOKIE["token"]) ? $_COOKIE["token"] : null;
$user = isset($_SESSION["user_data"]) ? $_SESSION["user_data"] : null;
//print_r($user);
if (!$user || !$token) {
    header("Location: /auth/logon.php");
    exit();
}
else if ($user['scheduled'] === 1){
    header("Location: schedule.php");
    exit();
}
else if (hasPermission('menu.schedule')){
    header("Location: schedule.php");
    exit();
}
else if ($user["role_pr"] <= 200){
    header("Location: schedule.php?team=&shift=&display_type=schedule");
    exit();
}
else {
    //print_R($user);
    header("Location: non_schedule.php");
    exit();
}
?>
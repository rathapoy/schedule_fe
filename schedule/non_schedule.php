<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
checkLogin();
$token = isset($_COOKIE["token"]) ? $_COOKIE["token"] : null;
$user = isset($_SESSION["user_data"]) ? $_SESSION["user_data"] : null;
http_response_code(403);

$page_title = "Access Denied - 403 Forbidden";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/static/css/schedule.css">
    <title><?php echo $page_title; ?></title>
</head>
<body>
    <div class="container">
        <div class="icon">🔒</div>
        <h1>403 Access Denied</h1>
        <p>
            **Forbidden.** You do not have permission to access this page.<br>
            Your **user privileges** are insufficient to perform this action.
        </p>
        <p>
            If you believe this is an error, please contact the system administrator.
        </p>
    </div>
</body>
</html>
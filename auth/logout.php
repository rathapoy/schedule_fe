<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
$token = isset($_COOKIE["token"]) ? $_COOKIE["token"] : null;
$user = isset($_SESSION["user_data"]) ? $_SESSION["user_data"] : null;
if (!$user || !$token) {
    echo '<script>setTimeout(() => {window.top.location.replace("/auth/logon.php")}, 100);</script>';
    exit();
}
$api_url = $_ENV['BE_API_PYBE']."/logout/".$user["user_id"];

$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    error_log("Logout API failed: HTTP $http_code, Response: $response");
}

setcookie("token", "", time() - 3600, "/");
$_SESSION = [];            
session_unset();           
session_destroy(); 
?>
<!DOCTYPE html>
<html>
<head>
    <title>Logging out...</title>
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            font-family: Arial, sans-serif;
            background: #f8f8f8;
        }
        .loading {
            text-align: center;
        }
        .spinner {
            border: 6px solid #f3f3f3;
            border-top: 6px solid rgb(33, 148, 58);
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg);}
            100% { transform: rotate(360deg);}
        }
    </style>

    <script>
        setTimeout(() => {
            window.top.location.replace("/auth/logon.php");
        }, 1000); // 2 วินาที
    </script>
</head>
<body>
    <div class="loading">
        <div class="spinner"></div>
        <p>Logging out...</p>
    </div>
</body>
</html>
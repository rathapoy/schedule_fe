<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';


$username = isset($_POST['username']) && is_string($_POST['username']) ? trim($_POST['username']) : '';
$password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';

if (empty($username) || empty($password)) {
   
    header("Location: logon.php?error=invalid_input");
    exit();
}

$client_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

$postData = [
    "username" => $username,
    "password" => $password,
    "client_ip" => $client_ip
];


$base_url = $_ENV['BE_API_PYBE'] ?? '';
if (empty($base_url)) {
    header("Location: logon.php?error=server_error");
    exit();
}
$api_url = rtrim($base_url, '/') . "/login";

$ch = curl_init($api_url);
if ($ch === false) {
    header("Location: logon.php?error=server_error");
    exit();
}

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));


curl_setopt($ch, CURLOPT_TIMEOUT, 30);


$response = curl_exec($ch);
$status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    curl_close($ch);
    header("Location: logon.php?error=connection_failed");
    exit();
}
curl_close($ch);

$result = json_decode($response, true);

if ($status_code === 200 && isset($result["data"]["access_token"])) {
    

    session_regenerate_id(true);
    
    if (isset($result["data"]["first_login"]) && $result["data"]["first_login"] === true){
        $_SESSION['force_change_password'] = true;
    }
    
  
    $token = $result["data"]["access_token"];
    setcookie("token", $token, [
        'expires' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,  
        'samesite' => 'Strict' 
    ]);

    $_SESSION['user_data'] = $result["data"]["user"];
    header("Location: /");
    exit();

} else {
    header("Location: logon.php?error=authentication_failed");
    exit();
}
?>
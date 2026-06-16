<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/vendor/autoload.php';
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable($_SERVER['DOCUMENT_ROOT']);
$dotenv->load();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff'); 


if (!isset($_COOKIE["token"]) || !is_string($_COOKIE["token"])) {
    echo json_encode(["status" => "invalid", "message" => "No token or invalid format"]);
    exit();
}


$token = preg_replace('/[\r\n]/', '', trim($_COOKIE["token"]));


$base_url = $_ENV['BE_API_PYBE'] ?? '';
if (!filter_var($base_url, FILTER_VALIDATE_URL)) {
    echo json_encode(["status" => "error", "message" => "Server configuration error"]);
    exit();
}

$url = rtrim($base_url, '/') . "/verify_token";

$ch = curl_init($url);
if ($ch === false) {
    echo json_encode(["status" => "error", "message" => "Failed to initialize request"]);
    exit();
}


curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15); 
curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $token,
    "Accept: application/json"
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_errno($ch);

curl_close($ch);


if ($err) {
    echo json_encode(["status" => "error", "message" => "Connection to verification server failed"]);
    exit();
}

if ($status !== 200) {
    echo json_encode(["status" => "invalid", "message" => "Token expired or invalid"]);
    exit();
}

$decoded_response = json_decode($response, true);
if (json_last_error() === JSON_ERROR_NONE) {
    echo json_encode($decoded_response);
} else {
    echo json_encode(["status" => "error", "message" => "Invalid response format from server"]);
}
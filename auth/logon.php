<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 0,
        'cookie_path' => '/',
        'cookie_secure' => true,      
        'cookie_httponly' => true,   
        'cookie_samesite' => 'Strict' 
    ]);
}


require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';


header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff'); 
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; script-src 'self'; frame-ancestors 'none';"); 
header('Referrer-Policy: no-referrer-when-downgrade');


$allowed_errors = [
    'invalid_input'         => 'Username and password are required.',
    'server_error'          => 'An internal server error occurred. Please try again later.',
    'connection_failed'     => 'Could not connect to the authentication server.',
    'authentication_failed' => 'Invalid username or password.',
    'unauthorized'          => 'Access denied. Please log in first.',
    'session_expired'       => 'Your session has expired. Please log in again.'
];

$error_display_message = '';
if (isset($_GET['error']) && is_string($_GET['error'])) {
    $error_key = trim($_GET['error']);
    if (array_key_exists($error_key, $allowed_errors)) {

        $error_display_message = $allowed_errors[$error_key];
    } else {
        $error_display_message = 'An unknown error occurred.';
    }
}


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>.: NOC 3BB - Local Login</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .login-container {
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            width: 300px;
            text-align: center;
        }
        .login-container h2 {
            margin-bottom: 20px;
            color: #333;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .form-group {
            margin-bottom: 15px;
            text-align: left;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-size: 12px;
            text-align: center;
        }
        .form-group input[type="text"],
        .form-group input[type="password"] {
            width: calc(100% - 22px);
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .form-group input[type="submit"] {
            background-color: rgb(140, 200, 60);
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
        }
        .form-group input[type="submit"]:hover {
            background-color: rgb(154, 212, 105);
        }
        .message {
            color: red;
            margin-top: 15px;
            font-size: 13px;
            background-color: #ffeef0;
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #fcc;
        }
        input::placeholder {
            color: #999;
            font-style: italic;
        }
        .env-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            background-color: #ffc107; 
            color: #000;
            text-align: center;
            padding: 10px 0;
            font-weight: bold;
            border-top: 1px solid #ddd;
            z-index: 9999;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>
            <img src="../static/img/login.png" alt="Login Icon">
            NOC Login
        </h2>
        
        <?php if (!empty($error_display_message)): ?>
            <p class="message"><?= htmlspecialchars($error_display_message, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <form action="process_login.php" method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-group">
                <label for="username">Enter your username and password to log on.</label>
                <input type="text" id="username" name="username" placeholder="Example : noc3bb" required autocomplete="username">
            </div>
            <div class="form-group">
                <input type="password" id="password" name="password" placeholder="Enter your password." required autocomplete="current-password">
            </div>
            <div class="form-group">
                <input type="submit" value="Login">
            </div>
        </form>
    </div>

<?php 
if (isset($serversite) && is_string($serversite) && htmlspecialchars($serversite, ENT_QUOTES, 'UTF-8') === "Staging") : 
?>
    <footer class="env-footer">
        NOC Tools Server (Staging)
    </footer>
<?php endif; ?>
</body>
</html>
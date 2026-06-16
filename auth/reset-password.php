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
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; font-src 'self' data:; img-src 'self' data:;");
header('Referrer-Policy: no-referrer-when-downgrade');


$is_post = ($_SERVER['REQUEST_METHOD'] === 'POST');
$username = '';


if (!$is_post) {
    $token = $_COOKIE["token"] ?? null;
    $user = $_SESSION["user_data"] ?? null;


    if (!$user || !is_array($user) || !$token || !is_string($token)) {
        header("Location: /auth/logon.php");
        exit();
    }

    $email = isset($user["email"]) && is_string($user["email"]) ? $user["email"] : '';
    $email_parts = explode('@', $email);
    

    $username = isset($email_parts[0]) ? preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $email_parts[0]) : '';


    session_unset();
    session_destroy();
    

    setcookie('token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
}


if ($is_post) {
    header('Content-Type: application/json; charset=utf-8');
    
    $input = json_decode(file_get_contents("php://input"), true);
    
    $password = isset($input['password']) && is_string($input['password']) ? $input['password'] : '';
    $confirm  = isset($input['confirm']) && is_string($input['confirm']) ? $input['confirm'] : '';
    $u_payload = isset($input['u']) && is_string($input['u']) ? trim($input['u']) : '';

    if (empty($u_payload) || empty($password) || empty($confirm)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Request Data']);
        exit();
    }

    if (strlen($password) < 8) {
        echo json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters.']);
        exit();
    }

    if ($password !== $confirm) {
        echo json_encode(['status' => 'error', 'message' => 'Passwords do not match.']);
        exit();
    }

    $u_payload_clean = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $u_payload);
    
    $raw_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $client_ip = filter_var($raw_ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';

    $result = callApi('/reset_password', 'POST', [
        'username' => $u_payload_clean,
        'password' => $password,
        'client_ip' => $client_ip
    ]);

    $safe_result = json_decode(json_encode($result), true);
    echo json_encode($safe_result);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NOC Portal &gt; Reset Password</title>
    <link rel="stylesheet" href="/static/fontawesome/css/all.css">
    <link rel="stylesheet" href="/static/bootstrap/5.3.1/css/bootstrap.min.css">
    <script src="/static/jquery/jquery-3.6.0.min.js"></script>
    <style>
        body { background-color: #f8f9fa; }
        .card { border: none; box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15); border-radius: 0.5rem; max-width: 450px; width: 100%; }
        .warning-box { font-size: 0.85rem; color: #856404; background-color: #fff3cd; border: 1px solid #ffeeba; padding: 12px; border-radius: 5px; margin-bottom: 20px; }
    </style>
</head>
<body class="d-flex align-items-center min-vh-100">

    <script>
        const targetUser = <?php echo json_encode($username, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>

    <div class="container d-flex justify-content-center">
        <div class="card p-4">
            <div class="text-center mb-4">
                <div class="mb-3">
                    <i class="fas fa-user-lock fa-3x text-primary"></i>
                </div>
                <h3 class="fw-bold">Required Reset</h3>
            </div>

            <div class="warning-box">
                <i class="fas fa-exclamation-circle me-1"></i>
                Please set your new password now.
            </div>

            <div id="msg-container" class="alert d-none text-center small" role="alert">
                <span id="msg"></span>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold small" for="password">New Password</label>
                <input type="password" class="form-control" id="password" placeholder="8+ chars, A-Z, a-z, 0-9" autocomplete="new-password">
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold small" for="confirm">Confirm Password</label>
                <input type="password" class="form-control" id="confirm" placeholder="Repeat password" autocomplete="new-password">
            </div>

            <button onclick="submitReset()" id="btn-submit" class="btn btn-success w-100 fw-bold">
                Update Password &amp; Login
            </button>
        </div>
    </div>

<script>
function submitReset() {
    const password = document.getElementById('password').value;
    const confirm = document.getElementById('confirm').value;
    const btn = document.getElementById('btn-submit');
    const originalText = btn.innerHTML;

    if (password.length < 8) {
        showMsg('Password must be at least 8 characters.', 'error');
        return;
    }

    if (password !== confirm) {
        showMsg('Passwords do not match.', 'error');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Processing...';
    fetch(window.location.href, {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ 
            password: password, 
            confirm: confirm,
            u: targetUser 
        })
    })
    .then(r => {
        if (!r.ok) {
            throw new Error('HTTP error ' + r.status);
        }
        return r.json();
    })
    .then(res => {
        if (res && res.status === 'success') {
            showMsg('Success! Redirecting to login...', 'success');
            setTimeout(() => {
                window.location.replace("/auth/logon.php");
            }, 2000);
        } else {
            showMsg(res.detail || res.message || 'Error occurred', 'error');
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = 'Update Password & Login';
        showMsg('Network error, please try again.', 'error');
    });
}

function showMsg(text, type) {
    const container = document.getElementById('msg-container');
    const msgElem = document.getElementById('msg');
    container.className = `alert text-center small ${type === 'error' ? 'alert-danger' : 'alert-success'}`;
    msgElem.innerText = text;
    container.classList.remove('d-none');
}
</script>
</body>
</html>
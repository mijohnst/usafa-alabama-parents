<?php
require_once __DIR__ . '/auth.php';
start_session();

if (!empty($_SESSION['logged_in'])) {
    header('Location: index.php'); exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/config.php';
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    if ($user === ADMIN_USERNAME && password_verify($pass, ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
        header('Location: index.php'); exit;
    }
    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Login — USAFA Parents Club of Alabama</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Segoe UI",Arial,sans-serif;background:#002554;display:flex;justify-content:center;align-items:center;min-height:100vh}
.box{background:#fff;border-radius:8px;box-shadow:0 4px 24px rgba(0,0,0,.3);padding:2.5rem;width:100%;max-width:380px}
.logo{text-align:center;margin-bottom:1.5rem}
.logo strong{display:block;font-size:1rem;color:#002554;letter-spacing:.02em}
.logo small{color:#5a6a7a;font-size:.82rem}
label{display:block;font-size:.78rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem}
input{width:100%;padding:.65rem .75rem;border:1px solid #d0d5dd;border-radius:4px;font-size:.95rem;margin-bottom:1.1rem}
input:focus{outline:none;border-color:#003594;box-shadow:0 0 0 2px rgba(0,53,148,.15)}
button{width:100%;padding:.75rem;background:#003594;color:#fff;border:none;border-radius:4px;font-size:.95rem;font-weight:700;cursor:pointer;letter-spacing:.03em}
button:hover{background:#002268}
.alert{padding:.65rem .9rem;border-radius:4px;margin-bottom:1rem;font-size:.875rem;background:#ffebee;color:#c62828;border-left:4px solid #f44336}
</style></head><body>
<div class="box">
  <div class="logo">
    <strong>USAFA Parents Club of Alabama</strong>
    <small>Member Administration</small>
  </div>
  <?php if ($error): ?>
    <div class="alert"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="POST">
    <label for="username">Username</label>
    <input type="text" id="username" name="username" autocomplete="username" required>
    <label for="password">Password</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>
    <button type="submit">Log In</button>
  </form>
</div>
</body></html>

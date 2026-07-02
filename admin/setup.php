<?php
// ── One-time password setup utility ──────────────────────────────────────────
// 1. Open this page in your browser: https://alabamafalcons.org/admin/setup.php
// 2. Enter your desired password and click Generate.
// 3. Copy the hash shown and paste it into config.php as ADMIN_PASSWORD_HASH.
// 4. DELETE this file from the server immediately after.
// ─────────────────────────────────────────────────────────────────────────────

$hash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw  = trim($_POST['password']  ?? '');
    $pw2 = trim($_POST['password2'] ?? '');
    if (strlen($pw) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pw !== $pw2) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($pw, PASSWORD_BCRYPT);
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Setup</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Segoe UI",Arial,sans-serif;background:#f0f2f5;display:flex;justify-content:center;align-items:center;min-height:100vh}
.box{background:#fff;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.12);padding:2rem;width:100%;max-width:440px}
h1{font-size:1.2rem;color:#002554;margin-bottom:1.5rem;text-align:center}
label{display:block;font-size:.78rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem}
input{width:100%;padding:.6rem .75rem;border:1px solid #d0d5dd;border-radius:4px;font-size:.95rem;margin-bottom:1rem}
input:focus{outline:none;border-color:#003594;box-shadow:0 0 0 2px rgba(0,53,148,.15)}
button{width:100%;padding:.7rem;background:#003594;color:#fff;border:none;border-radius:4px;font-size:.95rem;font-weight:700;cursor:pointer}
button:hover{background:#002268}
.alert{padding:.75rem 1rem;border-radius:4px;margin-bottom:1rem;font-size:.9rem}
.alert-error{background:#ffebee;color:#c62828;border-left:4px solid #f44336}
.hash-box{background:#f5f7fa;border:1px solid #e1e5eb;border-radius:4px;padding:1rem;margin-top:1rem;word-break:break-all;font-family:monospace;font-size:.82rem;color:#1a2332}
.step{background:#e8f5e9;border-left:4px solid #4caf50;padding:.75rem 1rem;border-radius:4px;font-size:.875rem;color:#1b5e20;margin-top:1rem;line-height:1.6}
.warn{background:#fff8e1;border-left:4px solid #ffc107;padding:.75rem 1rem;border-radius:4px;font-size:.875rem;color:#5f4c00;margin-top:1rem}
</style></head><body>
<div class="box">
  <h1>🔐 Admin Password Setup</h1>
  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <?php if ($hash): ?>
    <div class="step">
      <strong>Step 1:</strong> Copy the hash below.<br>
      <strong>Step 2:</strong> Open <code>admin/config.php</code> and paste it as the value of <code>ADMIN_PASSWORD_HASH</code>.<br>
      <strong>Step 3:</strong> Delete <code>setup.php</code> from the server.
    </div>
    <div class="hash-box"><?= htmlspecialchars($hash) ?></div>
    <div class="warn">⚠️ Delete this file from your server after copying the hash.</div>
  <?php else: ?>
    <form method="POST">
      <label for="password">New Password (min 8 characters)</label>
      <input type="password" id="password" name="password" required minlength="8">
      <label for="password2">Confirm Password</label>
      <input type="password" id="password2" name="password2" required>
      <button type="submit">Generate Hash</button>
    </form>
  <?php endif; ?>
</div>
</body></html>

<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo    = get_pdo();
$errors = [];
$done   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $current  = $_POST['current_password']  ?? '';
    $new_pw   = $_POST['new_password']      ?? '';
    $confirm  = $_POST['confirm_password']  ?? '';

    // Verify current password
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($current, $user['password_hash']))
        $errors[] = 'Current password is incorrect.';
    if (strlen($new_pw) < 8)
        $errors[] = 'New password must be at least 8 characters.';
    if ($new_pw !== $confirm)
        $errors[] = 'New passwords do not match.';

    if (empty($errors)) {
        $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?')
            ->execute([password_hash($new_pw, PASSWORD_BCRYPT), $_SESSION['user_id']]);
        $done = true;
    }
}

$back = can_manage_finances() ? 'purchases.php' : 'index.php';
admin_header('Change Password');
?>
<style>
.pw-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1.5rem;max-width:420px}
.pw-wrap{position:relative;margin-bottom:.9rem}
.pw-wrap input{margin-bottom:0;padding-right:2.75rem}
.pw-toggle{position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9aa5b4;font-size:1.1rem;padding:0;line-height:1}
</style>

<div class="page-head">
  <h1>Change Password</h1>
  <a href="<?= $back ?>" class="btn btn-secondary">← Back</a>
</div>

<?php if ($done): ?>
  <div class="alert alert-success" style="max-width:420px">
    ✓ Password updated successfully.
    <a href="<?= $back ?>" style="color:#1b5e20;font-weight:600;margin-left:.5rem">← Back</a>
  </div>
<?php else: ?>

<?php if (!empty($errors)): ?>
  <div class="alert alert-error" style="max-width:420px">
    <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
  </div>
<?php endif; ?>

<div class="pw-card">
  <p style="font-size:.85rem;color:#5a6a7a;margin-bottom:1.25rem">
    Changing password for <strong><?= h(current_user_name()) ?></strong>
  </p>
  <form method="POST">
    <?= csrf_field() ?>
    <div class="form-group">
      <label>Current Password</label>
      <div class="pw-wrap">
        <input type="password" id="pw-cur" name="current_password" required autocomplete="current-password">
        <button type="button" class="pw-toggle" onclick="togglePw('pw-cur',this)">👁</button>
      </div>
    </div>
    <div class="form-group">
      <label>New Password <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">(min 8 characters)</span></label>
      <div class="pw-wrap">
        <input type="password" id="pw-new" name="new_password" required minlength="8" autocomplete="new-password">
        <button type="button" class="pw-toggle" onclick="togglePw('pw-new',this)">👁</button>
      </div>
    </div>
    <div class="form-group">
      <label>Confirm New Password</label>
      <div class="pw-wrap">
        <input type="password" id="pw-conf" name="confirm_password" required autocomplete="new-password">
        <button type="button" class="pw-toggle" onclick="togglePw('pw-conf',this)">👁</button>
      </div>
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%">Update Password</button>
  </form>
</div>
<?php endif; ?>

<script>
function togglePw(id, btn) {
  var inp  = document.getElementById(id);
  var show = inp.type === 'password';
  inp.type     = show ? 'text' : 'password';
  btn.style.opacity = show ? '1' : '0.4';
}
</script>

<?php admin_footer(); ?>

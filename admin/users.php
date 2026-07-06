<?php
require_once __DIR__ . '/auth.php';
require_admin();
$pdo = get_pdo();

$edit_user = null;
$errors    = [];

// ── Handle POST actions ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim($_POST['name']     ?? '');
        $email = trim($_POST['email']    ?? '');
        $uname = trim($_POST['username'] ?? '');
        $role  = $_POST['role'] ?? 'viewer';
        $pw    = $_POST['password']  ?? '';
        $pw2   = $_POST['password2'] ?? '';

        if (!$name)  $errors[] = 'Name is required.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (!$uname) $errors[] = 'Username is required.';
        if (!in_array($role, ['admin','treasurer','viewer','member'])) $errors[] = 'Invalid role.';
        if ($action === 'add' && strlen($pw) < 8)  $errors[] = 'Password must be at least 8 characters.';
        if ($pw && $pw !== $pw2)                    $errors[] = 'Passwords do not match.';

        if (empty($errors)) {
            if ($action === 'add') {
                $pdo->prepare('INSERT INTO users (name,email,username,password_hash,role,active) VALUES (?,?,?,?,?,1)')
                    ->execute([$name, $email, $uname, password_hash($pw, PASSWORD_BCRYPT), $role]);
                flash('success', "User '$name' added.");
            } else {
                if ($pw) {
                    $pdo->prepare('UPDATE users SET name=?,email=?,username=?,password_hash=?,role=? WHERE id=?')
                        ->execute([$name, $email, $uname, password_hash($pw, PASSWORD_BCRYPT), $role, $id]);
                } else {
                    $pdo->prepare('UPDATE users SET name=?,email=?,username=?,role=? WHERE id=?')
                        ->execute([$name, $email, $uname, $role, $id]);
                }
                flash('success', "User '$name' updated.");
            }
            header('Location: users.php'); exit;
        }

    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)($_SESSION['user_id'] ?? 0)) {
            flash('error', 'You cannot deactivate your own account.');
        } else {
            $row = $pdo->prepare('SELECT active,name FROM users WHERE id=?');
            $row->execute([$id]);
            $u = $row->fetch();
            if ($u) {
                $pdo->prepare('UPDATE users SET active=? WHERE id=?')->execute([$u['active'] ? 0 : 1, $id]);
                flash('success', $u['name'] . ' ' . ($u['active'] ? 'deactivated.' : 'reactivated.'));
            }
        }
        header('Location: users.php'); exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)($_SESSION['user_id'] ?? 0)) {
            flash('error', 'You cannot delete your own account.');
        } else {
            $row = $pdo->prepare('SELECT name FROM users WHERE id=?');
            $row->execute([$id]);
            $u = $row->fetch();
            if ($u) {
                $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
                flash('success', $u['name'] . ' deleted.');
            }
        }
        header('Location: users.php'); exit;
    }
}

// ── Load user for editing ──────────────────────────────────────────────────
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit_user = $stmt->fetch();
}

$users = $pdo->query('SELECT * FROM users ORDER BY role, name')->fetchAll();

$role_labels = ['admin'=>'Admin','treasurer'=>'Treasurer','viewer'=>'Viewer','member'=>'Member'];
$role_colors = ['admin'=>'#002554','treasurer'=>'#1b5e20','viewer'=>'#5a6a7a','member'=>'#7b3f00'];

admin_header('Users');
echo show_flash();
?>
<style>
.user-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;margin-bottom:1.5rem}
.user-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1.1rem 1.25rem}
.user-card.inactive{opacity:.55}
.user-role{display:inline-block;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:.15rem .5rem;border-radius:3px;background:#f0f2f5;margin-bottom:.5rem}
.user-name{font-size:1rem;font-weight:700;color:#002554;margin-bottom:.15rem}
.user-meta{font-size:.78rem;color:#5a6a7a;margin-bottom:.75rem}
.user-actions{display:flex;gap:.4rem;flex-wrap:wrap}
.form-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1.5rem;max-width:520px;margin-bottom:1.5rem}
.form-card h2{font-size:1rem;color:#002554;margin-bottom:1.25rem}
@media(max-width:500px){.user-grid{grid-template-columns:1fr}}
</style>

<div class="page-head">
  <h1>Users</h1>
  <?php if (!$edit_user): ?>
  <a href="users.php?edit=new" class="btn btn-primary">+ Add User</a>
  <?php endif; ?>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php endif; ?>

<?php if ($edit_user || isset($_GET['edit'])): ?>
<!-- Add / Edit form -->
<div class="form-card">
  <h2><?= $edit_user ? 'Edit User — ' . h($edit_user['name']) : 'Add New User' ?></h2>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $edit_user ? 'edit' : 'add' ?>">
    <?php if ($edit_user): ?><input type="hidden" name="id" value="<?= (int)$edit_user['id'] ?>"><?php endif; ?>

    <div class="form-row col-2">
      <div class="form-group">
        <label>Full Name *</label>
        <input name="name" value="<?= h($edit_user['name'] ?? '') ?>" required autocomplete="off">
      </div>
      <div class="form-group">
        <label>Username *</label>
        <input name="username" value="<?= h($edit_user['username'] ?? '') ?>" required autocomplete="off">
      </div>
    </div>
    <div class="form-group">
      <label>Email *</label>
      <input type="email" name="email" value="<?= h($edit_user['email'] ?? '') ?>" required autocomplete="off">
    </div>
    <div class="form-group">
      <label>Role *</label>
      <select name="role">
        <?php foreach ($role_labels as $r => $label): ?>
          <option value="<?= $r ?>" <?= ($edit_user['role'] ?? 'viewer')===$r?'selected':''?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row col-2">
      <div class="form-group">
        <label><?= $edit_user ? 'New Password' : 'Password *' ?> <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem">(min 8 chars)</span></label>
        <input type="password" name="password" <?= $edit_user?'':'required' ?> minlength="8" autocomplete="new-password">
      </div>
      <div class="form-group">
        <label>Confirm Password</label>
        <input type="password" name="password2" autocomplete="new-password">
      </div>
    </div>
    <?php if ($edit_user): ?><p style="font-size:.78rem;color:#9aa5b4;margin-top:-.5rem;margin-bottom:1rem">Leave password blank to keep current password.</p><?php endif; ?>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap">
      <button type="submit" class="btn btn-primary"><?= $edit_user ? 'Save Changes' : 'Add User' ?></button>
      <a href="users.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- User cards -->
<div class="user-grid">
<?php foreach ($users as $u): ?>
  <div class="user-card <?= $u['active'] ? '' : 'inactive' ?>">
    <div class="user-role" style="color:<?= $role_colors[$u['role']] ?>"><?= $role_labels[$u['role']] ?></div>
    <div class="user-name"><?= h($u['name']) ?> <?= !$u['active'] ? '<span style="font-size:.7rem;color:#c62828">(Inactive)</span>' : '' ?></div>
    <div class="user-meta">
      @<?= h($u['username']) ?><br>
      <?= h($u['email']) ?>
    </div>
    <div class="user-actions">
      <a href="users.php?edit=<?= $u['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
      <?php if ($u['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
      <form method="POST" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="toggle_active">
        <input type="hidden" name="id" value="<?= $u['id'] ?>">
        <button type="submit" class="btn btn-secondary btn-sm"><?= $u['active'] ? 'Deactivate' : 'Reactivate' ?></button>
      </form>
      <form method="POST" style="margin:0" onsubmit="return confirm('Delete <?= h(addslashes($u['name'])) ?>? This cannot be undone.')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= $u['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
      </form>
      <?php else: ?>
      <span style="font-size:.75rem;color:#9aa5b4;padding:.28rem 0">(your account)</span>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php admin_footer(); ?>

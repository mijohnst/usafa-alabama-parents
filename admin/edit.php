<?php
require_once __DIR__ . '/auth.php';
require_admin();
$pdo = get_pdo();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$m = $pdo->prepare('SELECT * FROM members WHERE id = ?');
$m->execute([$id]);
$member = $m->fetch();
if (!$member) { flash('error', 'Member not found.'); header('Location: index.php'); exit; }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach (FIELDS as $f) $member[$f] = trim($_POST[$f] ?? '');
    $member['membership_paid'] = isset($_POST['membership_paid']) ? (int)$_POST['membership_paid'] : 0;
    if ($member['cadet_birthday'] === '') $member['cadet_birthday'] = null;

    if ($member['class_year'] === '') $errors[] = 'Class Year is required.';
    if ($member['cadet_last_name'] === '') $errors[] = 'Cadet Last Name is required.';

    if (empty($errors)) {
        $set = implode(', ', array_map(fn($f) => "`$f` = :$f", FIELDS));
        $stmt = $pdo->prepare("UPDATE members SET $set WHERE id = :id");
        $params = [];
        foreach (FIELDS as $f) $params[$f] = $member[$f];
        $params['id'] = $id;
        $stmt->execute($params);
        flash('success', 'Member updated successfully.');
        header('Location: index.php'); exit;
    }
}

admin_header('Edit Member');
?>
<div class="page-head">
  <h1>Edit — <?= h($member['cadet_last_name']) ?>, <?= h($member['cadet_first_middle']) ?></h1>
  <a href="index.php" class="btn btn-secondary">← Back</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php endif; ?>

<div class="card">
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <?php member_form($member, true) ?>
    <div style="display:flex;gap:.75rem;margin-top:.5rem">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <a href="index.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>

<?php admin_footer(); ?>

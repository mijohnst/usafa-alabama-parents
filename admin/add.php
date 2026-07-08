<?php
require_once __DIR__ . '/auth.php';
require_member_admin();

$errors = [];
$m = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach (FIELDS as $f) $m[$f] = trim($_POST[$f] ?? '');
    $m['membership_paid'] = isset($_POST['membership_paid']) ? (int)$_POST['membership_paid'] : 0;
    $m['parent1_is_board_member'] = isset($_POST['parent1_is_board_member']) ? 1 : 0;
    $m['parent2_is_board_member'] = isset($_POST['parent2_is_board_member']) ? 1 : 0;
    if (!in_array($m['membership_type'], ['annual','4year'])) $m['membership_type'] = 'annual';
    $m['membership_paid_through'] = calc_paid_through($m['membership_year'], $m['membership_type'], (bool)$m['membership_paid']);

    if ($m['class_year'] === '') $errors[] = 'Class Year is required.';
    if ($m['cadet_last_name'] === '') $errors[] = 'Cadet Last Name is required.';
    if ($m['cadet_birthday'] === '') $m['cadet_birthday'] = null;

    if (empty($errors)) {
        $pdo = get_pdo();
        $cols = implode(', ', array_map(fn($f) => "`$f`", FIELDS));
        $placeholders = implode(', ', array_map(fn($f) => ":$f", FIELDS));
        $stmt = $pdo->prepare("INSERT INTO members ($cols) VALUES ($placeholders)");
        $stmt->execute($m);
        flash('success', 'Member added successfully.');
        header('Location: index.php'); exit;
    }
}

admin_header('Add Member');
?>
<div class="page-head">
  <h1>Add Member</h1>
  <a href="index.php" class="btn btn-secondary">← Back</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php endif; ?>

<div class="card">
  <form method="POST">
    <?= csrf_field() ?>
    <?php member_form($m, false) ?>
    <div style="display:flex;gap:.75rem;margin-top:.5rem">
      <button type="submit" class="btn btn-primary">Save Member</button>
      <a href="index.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>

<?php admin_footer(); ?>

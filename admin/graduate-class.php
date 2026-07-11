<?php
require_once __DIR__ . '/auth.php';
require_member_admin(); // officers and admins can graduate a class
$pdo = get_pdo();

$out_year = outgoing_class_year();

$cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM members WHERE archived = 0 AND class_year = ?");
$cnt_stmt->execute([$out_year]);
$affected = (int)$cnt_stmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $upd = $pdo->prepare("UPDATE members SET class_year = 'Graduate' WHERE archived = 0 AND class_year = ?");
    $upd->execute([$out_year]);
    flash('success', "$affected member(s) moved from Class of $out_year to Graduate.");
    header('Location: index.php'); exit;
}

admin_header('Graduate a Class');
?>

<div class="page-head">
  <h1>Graduate a Class</h1>
  <a href="index.php" class="btn btn-secondary">← Back</a>
</div>

<div class="card" style="max-width:600px">
  <?php if ($affected === 0): ?>
  <div style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:4px;padding:1rem 1.25rem;margin-bottom:1.5rem">
    <strong style="color:#1b5e20">No members currently in the Class of <?= h($out_year) ?>.</strong>
    <p style="color:#1b5e20;margin-top:.4rem;font-size:.9rem">Either they've already been graduated, or there's nothing to do yet.</p>
  </div>
  <?php else: ?>
  <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:1rem 1.25rem;margin-bottom:1.5rem">
    <strong style="color:#5f4c00">⚠️ This action cannot be undone automatically.</strong>
    <p style="color:#5f4c00;margin-top:.4rem;font-size:.9rem">
      This will move all <strong><?= $affected ?> member(s)</strong> in the
      <strong>Class of <?= h($out_year) ?></strong> to <strong>Graduate</strong> status.
      Their records, dues history, and contact info are kept — only the class year changes.
      A record can always be edited back to a specific year manually if needed.
    </p>
  </div>
  <?php endif; ?>
  <p style="margin-bottom:1.5rem;color:#333">
    Run this once each spring/summer after commencement, before starting the new membership year,
    so graduated cadets stop appearing in class-year lists, filters, and Contact Lists alongside
    currently-enrolled classes.
  </p>
  <?php if ($affected > 0): ?>
  <form method="POST">
    <?= csrf_field() ?>
    <div style="display:flex;gap:.75rem;align-items:center">
      <button type="submit" class="btn btn-danger"
        onclick="return confirm('Move all <?= $affected ?> members from Class of <?= h($out_year) ?> to Graduate?')">
        Graduate the Class of <?= h($out_year) ?>
      </button>
      <a href="index.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
  <?php endif; ?>
</div>

<?php admin_footer(); ?>

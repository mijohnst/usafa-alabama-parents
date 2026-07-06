<?php
require_once __DIR__ . '/auth.php';
require_admin();
$pdo = get_pdo();

$new_year     = membership_year();
$active_years = ['2027','2028','2029','2030'];
$ph           = implode(',', array_fill(0, count($active_years), '?'));
$cnt_stmt     = $pdo->prepare("SELECT COUNT(*) FROM members WHERE archived = 0 AND class_year IN ($ph)");
$cnt_stmt->execute($active_years);
$affected  = (int)$cnt_stmt->fetchColumn();
$confirmed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $upd = $pdo->prepare("UPDATE members SET membership_paid = 0, membership_year = '' WHERE archived = 0 AND class_year IN ($ph)");
    $upd->execute($active_years);
    $confirmed = true;
    flash('success', "Dues reset. $affected member(s) marked unpaid for $new_year.");
    header('Location: index.php'); exit;
}

admin_header('Start New Membership Year');
?>

<div class="page-head">
  <h1>Start New Membership Year</h1>
  <a href="index.php" class="btn btn-secondary">← Back</a>
</div>

<div class="card" style="max-width:600px">
  <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:1rem 1.25rem;margin-bottom:1.5rem">
    <strong style="color:#5f4c00">⚠️ This action cannot be undone.</strong>
    <p style="color:#5f4c00;margin-top:.4rem;font-size:.9rem">
      This will reset dues for <strong><?= $affected ?> member(s)</strong>
      (Class of 2027–2030 only) — marking them unpaid so they can renew for the
      <strong><?= h($new_year) ?></strong> dues cycle.
      Class of 2026, Prep School, and Graduate records are not changed.
    </p>
  </div>
  <p style="margin-bottom:1.5rem;color:#333">
    Use this at the start of each July to reset dues status for the new membership year.
    Members will need to renew and pay to be marked as paid again.
  </p>
  <form method="POST">
    <?= csrf_field() ?>
    <div style="display:flex;gap:.75rem;align-items:center">
      <button type="submit" class="btn btn-danger"
        onclick="return confirm('Reset all <?= $affected ?> members to unpaid for <?= h($new_year) ?>?')">
        Start <?= h($new_year) ?> Membership Year
      </button>
      <a href="index.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>

<?php admin_footer(); ?>

<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
require_finance();
$pdo = get_pdo();

$purchases = $pdo->query(
    "SELECT p.*, u.name as submitted_by_name, u.email as submitted_by_email
     FROM purchases p
     LEFT JOIN users u ON p.submitted_by = u.id
     WHERE p.status = 'approved'
     ORDER BY p.purchase_date ASC, p.id ASC"
)->fetchAll();

$total = array_sum(array_column($purchases, 'amount_total'));

admin_header('Pending Reimbursements');
echo show_flash();
?>
<style>
.reimb-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1.1rem 1.25rem;margin-bottom:.75rem;display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-start;justify-content:space-between}
.reimb-main{flex:1;min-width:220px}
.reimb-meta{font-size:.78rem;color:#5a6a7a;margin-top:.25rem;line-height:1.7}
.reimb-amount{font-size:1.3rem;font-weight:700;color:#002554;white-space:nowrap}
.reimb-actions{display:flex;flex-direction:column;gap:.4rem;align-items:flex-end}
.total-bar{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1rem 1.5rem;margin-bottom:1.25rem;display:flex;justify-content:space-between;align-items:center}
</style>

<div class="page-head">
  <h1>Pending Reimbursements</h1>
  <a href="purchases.php" class="btn btn-secondary">← Finance</a>
</div>

<?php if (empty($purchases)): ?>
  <div class="card" style="padding:2rem;text-align:center;color:#5a6a7a">
    ✅ No purchases are awaiting reimbursement.
  </div>
<?php else: ?>

<div class="total-bar">
  <span style="font-size:.82rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.05em">
    <?= count($purchases) ?> purchase<?= count($purchases)!==1?'s':'' ?> awaiting reimbursement
  </span>
  <span style="font-size:1.3rem;font-weight:700;color:#A6192E">
    Total: $<?= number_format($total, 2) ?>
  </span>
</div>

<?php foreach ($purchases as $p):
  $days = (int)floor((time() - strtotime($p['purchase_date'])) / 86400);
  $age_color = $days > 30 ? '#A6192E' : ($days > 14 ? '#f57c00' : '#5a6a7a');
?>
<div class="reimb-card">
  <div class="reimb-main">
    <strong style="color:#002554;font-size:1rem"><?= h($p['vendor']) ?></strong>
    <div class="reimb-meta">
      <?= h(date('M j, Y', strtotime($p['purchase_date']))) ?>
      <span style="color:<?= $age_color ?>"> · <?= $days ?>d ago</span>
      <?php if ($p['event']): ?> · <?= h($p['event']) ?><?php endif; ?>
      <?php if ($p['category']): ?> · <?= h($p['category']) ?><?php endif; ?>
      <br>
      <?= h($p['description']) ?>
      <?php if ($p['payment_method']): ?>
        <br>Paid via: <strong><?= h($p['payment_method']) ?></strong>
      <?php endif; ?>
      <?php if ($p['submitted_by_name']): ?>
        <br>Submitted by: <?= h($p['submitted_by_name']) ?>
        <?php if ($p['submitted_by_email']): ?>
          · <a href="mailto:<?= h($p['submitted_by_email']) ?>" style="color:#003594"><?= h($p['submitted_by_email']) ?></a>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($p['approved_note']): ?>
        <br><em style="color:#1b5e20">Approval note: <?= h($p['approved_note']) ?></em>
      <?php endif; ?>
    </div>
  </div>
  <div style="text-align:right">
    <div class="reimb-amount">$<?= number_format($p['amount_total'], 2) ?></div>
    <div style="font-size:.72rem;color:#5a6a7a">
      $<?= number_format($p['amount_pretax'],2) ?> + $<?= number_format($p['amount_tax'],2) ?> tax
      <?php if ($p['amount_shipping'] > 0): ?> + $<?= number_format($p['amount_shipping'],2) ?> ship<?php endif; ?>
    </div>
    <div class="reimb-actions" style="margin-top:.6rem">
      <a href="purchase-form.php?id=<?= (int)$p['id'] ?>" class="btn btn-secondary btn-sm">View</a>
      <?php if (is_treasurer()): ?>
      <form method="POST" action="purchase-action.php" style="margin:0"
            onsubmit="return confirm('Mark this purchase as reimbursed?')">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <input type="hidden" name="action" value="reimburse">
        <input type="hidden" name="note" id="rn-pr-<?= (int)$p['id'] ?>">
        <button type="button" class="btn btn-sm" style="background:#003594;color:#fff"
          onclick="prReimburse(<?= (int)$p['id'] ?>, this.closest('form'))">
          💰 Reimburse
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>

<script>
function prReimburse(id, form) {
  var note = prompt('Reimbursement method (e.g. Venmo #12345):', '');
  if (note === null) return;
  document.getElementById('rn-pr-' + id).value = note;
  form.submit();
}
</script>
<?php endif; ?>

<?php admin_footer(); ?>

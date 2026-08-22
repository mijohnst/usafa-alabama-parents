<?php
/**
 * Read-only diagnostic/reconciliation view of paypal_dues_orders — lets a
 * treasurer see every online dues checkout attempt and its outcome without
 * digging through the PayPal dashboard. No edit actions here; corrections
 * happen through the normal dues-editing UI (edit-dues.php) plus the
 * Income Ledger, same as any other payment discrepancy.
 */
require_once __DIR__ . '/auth.php';
require_finance();
if (!is_treasurer() && !is_super_admin()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

$STATUS_COLORS = [
    'created'                 => '#5a6a7a',
    'captured'                => '#1565c0',
    'applied'                 => '#1b5e20',
    'already_captured'        => '#1565c0',
    'amount_mismatch'         => '#c62828',
    'needs_manual_review'     => '#c62828',
    'capture_ok_apply_failed' => '#c62828',
];

$stmt = $pdo->query(
    "SELECT o.*, m.cadet_first_name, m.cadet_middle_name, m.cadet_last_name, m.cadet_suffix
     FROM paypal_dues_orders o
     LEFT JOIN members m ON m.id = o.member_id
     ORDER BY o.created_at DESC"
);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

admin_header('PayPal Dues Orders');
echo show_flash();
?>
<style>
.pdo-table td,.pdo-table th{padding:.55rem .9rem}
.pdo-table th{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5a6a7a;background:#f7f9fc;white-space:nowrap}
.pdo-table td{border-top:1px solid #f0f2f5;font-size:.84rem;vertical-align:middle}
.pdo-table tr:hover td{background:#fafbfc}
.status-pill{display:inline-block;padding:.15rem .5rem;border-radius:99px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
</style>

<div class="page-head">
  <h1>PayPal Dues Orders</h1>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="income.php" class="btn btn-secondary">← Income Ledger</a>
  </div>
</div>

<?php if (empty($orders)): ?>
  <p style="color:#9aa5b4">No online dues checkouts yet.</p>
<?php else: ?>
<div class="card" style="padding:0;overflow-x:auto">
<table class="pdo-table" style="width:100%;border-collapse:collapse">
  <thead>
    <tr>
      <th>Created</th>
      <th>Cadet</th>
      <th>Years</th>
      <th style="text-align:right">Amount</th>
      <th>Status</th>
      <th>Order / Capture ID</th>
      <th>Note</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($orders as $o): $sc = $STATUS_COLORS[$o['status']] ?? '#5a6a7a';
    $cadet_name = trim(preg_replace('/\s+/', ' ', ($o['cadet_first_name'] ?? '') . ' ' . ($o['cadet_middle_name'] ?? '') . ' ' . ($o['cadet_last_name'] ?? '') . ' ' . ($o['cadet_suffix'] ?? ''))); ?>
  <tr>
    <td style="white-space:nowrap"><?= date('M j, Y g:ia', strtotime($o['created_at'])) ?></td>
    <td style="font-weight:600"><?= h($cadet_name ?: ('Member #' . $o['member_id'])) ?></td>
    <td style="color:#5a6a7a"><?= h($o['years']) ?></td>
    <td style="text-align:right;font-weight:700;color:#1b5e20;white-space:nowrap">$<?= number_format($o['amount'],2) ?></td>
    <td><span class="status-pill" style="background:<?= $sc ?>22;color:<?= $sc ?>"><?= h(str_replace('_',' ',$o['status'])) ?></span></td>
    <td style="font-size:.72rem;color:#9aa5b4;font-family:monospace">
      <?= h($o['paypal_order_id']) ?><?php if ($o['paypal_capture_id']): ?><br><?= h($o['paypal_capture_id']) ?><?php endif; ?>
    </td>
    <td style="font-size:.72rem;color:#9aa5b4"><?= h($o['error_note'] ?? '') ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php admin_footer(); ?>

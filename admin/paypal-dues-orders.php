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

// Collapses the raw internal status into what a treasurer actually wants
// to know: did this succeed, fail, or is it still in flight. A 'created'
// row is only genuinely "in progress" while its checkout session could
// still be alive — dues-pay-lookup.php's verify token (and so the whole
// checkout window) expires 30 minutes after lookup, so a 'created' row
// older than that never got captured and is dead, not just slow.
// Nullable params on purpose: a single row with an unexpected null status
// or timestamp must never fatal the whole list off the page — worst case
// it just falls through to the generic label below.
function pdo_display_status(?string $status, ?string $created_at): array {
    $status = $status ?? '';
    if (in_array($status, ['applied', 'already_captured'], true)) {
        return ['Paid', '#1b5e20'];
    }
    if (in_array($status, ['amount_mismatch', 'needs_manual_review', 'capture_ok_apply_failed'], true)) {
        return ['Needs Review', '#c62828'];
    }
    if ($status === 'captured') {
        return ['Processing', '#1565c0'];
    }
    if ($status === 'created') {
        $created_ts   = $created_at ? strtotime($created_at) : false;
        $age_minutes  = $created_ts ? (time() - $created_ts) / 60 : 9999;
        return $age_minutes > 30 ? ['Failed / Abandoned', '#9aa5b4'] : ['Pending', '#f57f17'];
    }
    return [$status !== '' ? ucfirst(str_replace('_', ' ', $status)) : 'Unknown', '#5a6a7a'];
}

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
.status-pill{display:inline-block;padding:.25rem .7rem;border-radius:99px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#fff;white-space:nowrap}
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
  <?php foreach ($orders as $o):
    list($status_label, $sc) = pdo_display_status($o['status'], $o['created_at']);
    $cadet_name = trim(preg_replace('/\s+/', ' ', ($o['cadet_first_name'] ?? '') . ' ' . ($o['cadet_middle_name'] ?? '') . ' ' . ($o['cadet_last_name'] ?? '') . ' ' . ($o['cadet_suffix'] ?? ''))); ?>
  <tr>
    <td style="white-space:nowrap"><?= date('M j, Y g:ia', strtotime($o['created_at'])) ?></td>
    <td style="font-weight:600"><?= h($cadet_name ?: ('Member #' . $o['member_id'])) ?></td>
    <td style="color:#5a6a7a"><?= h($o['years']) ?></td>
    <td style="text-align:right;font-weight:700;color:#1b5e20;white-space:nowrap">$<?= number_format($o['amount'],2) ?></td>
    <td><span class="status-pill" style="background:<?= $sc ?>"><?= h($status_label) ?></span></td>
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

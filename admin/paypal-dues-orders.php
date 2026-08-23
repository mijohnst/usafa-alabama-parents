<?php
/**
 * Read-only diagnostic/reconciliation view of everything that's come
 * through PayPal on the public site — dues checkouts (paypal_dues_orders)
 * and donations (paypal_donations) merged into one list — so a treasurer
 * can see every online PayPal attempt and its outcome without digging
 * through the PayPal dashboard. No edit actions here; corrections happen
 * through the normal dues-editing UI (edit-dues.php) or the Income Ledger,
 * same as any other payment discrepancy.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/paypal.php';
require_finance();
if (!is_treasurer() && !is_super_admin()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

// Clears both diagnostic logs only — never touches members.membership_paid_years
// or income_entries, so it can't undo a real payment or dues status, only
// the PayPal order/capture ID breadcrumb trail for looking one up later.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_all') {
    csrf_verify();
    $pdo->exec('DELETE FROM paypal_dues_orders');
    $pdo->exec('DELETE FROM paypal_donations');
    flash('success', 'PayPal activity log cleared.');
    header('Location: paypal-dues-orders.php');
    exit;
}

// Confirms the PAYPAL_CLIENT_ID/PAYPAL_SECRET in config.php actually work by
// fetching a real OAuth token, without ever surfacing the secret or the
// token itself anywhere in the response or on-screen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_connection') {
    csrf_verify();
    $auth = paypal_get_access_token();
    if ($auth['token']) {
        flash('success', 'PayPal connection succeeded (' . paypal_mode_label() . ' mode). Credentials are valid.');
    } else {
        flash('error', $auth['error']);
    }
    header('Location: paypal-dues-orders.php');
    exit;
}

// Collapses the raw internal status into what a treasurer actually wants
// to know: did this succeed, fail, or is it still in flight. A 'created'
// row is only genuinely "in progress" while its checkout session could
// still be alive — for dues, dues-pay-lookup.php's verify token (and so
// the whole checkout window) expires 30 minutes after lookup, so a
// 'created' row older than that never got captured and is dead, not just
// slow; donations have no such session but the same 30-minute cutoff is a
// reasonable stand-in for "the donor closed the tab." Donations skip the
// dues-only 'applied'/'processing' distinction — a donation is done the
// moment PayPal captures it, there's no separate apply-to-member step.
// Nullable params on purpose: a single row with an unexpected null status
// or timestamp must never fatal the whole list off the page — worst case
// it just falls through to the generic label below.
function pdo_display_status(?string $status, ?string $created_at, string $type): array {
    $status = $status ?? '';
    if ($type === 'donation') {
        if ($status === 'captured') return ['Paid', '#1b5e20'];
        if ($status === 'amount_mismatch') return ['Needs Review', '#c62828'];
    } else {
        if (in_array($status, ['applied', 'already_captured'], true)) return ['Paid', '#1b5e20'];
        if (in_array($status, ['amount_mismatch', 'needs_manual_review', 'capture_ok_apply_failed'], true)) return ['Needs Review', '#c62828'];
        if ($status === 'captured') return ['Processing', '#1565c0'];
    }
    if ($status === 'created') {
        $created_ts   = $created_at ? strtotime($created_at) : false;
        $age_minutes  = $created_ts ? (time() - $created_ts) / 60 : 9999;
        return $age_minutes > 30 ? ['Failed / Abandoned', '#9aa5b4'] : ['Pending', '#f57f17'];
    }
    return [$status !== '' ? ucfirst(str_replace('_', ' ', $status)) : 'Unknown', '#5a6a7a'];
}

$dues_stmt = $pdo->query(
    "SELECT o.*, m.cadet_first_name, m.cadet_middle_name, m.cadet_last_name, m.cadet_suffix
     FROM paypal_dues_orders o
     LEFT JOIN members m ON m.id = o.member_id"
);
$rows = [];
foreach ($dues_stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $cadet_name = trim(preg_replace('/\s+/', ' ', ($o['cadet_first_name'] ?? '') . ' ' . ($o['cadet_middle_name'] ?? '') . ' ' . ($o['cadet_last_name'] ?? '') . ' ' . ($o['cadet_suffix'] ?? '')));
    $rows[] = [
        'type'        => 'dues',
        'created_at'  => $o['created_at'],
        'who'         => $cadet_name ?: ('Member #' . $o['member_id']),
        'detail'      => $o['years'],
        'amount'      => $o['amount'],
        'status'      => $o['status'],
        'order_id'    => $o['paypal_order_id'],
        'capture_id'  => $o['paypal_capture_id'],
        'note'        => $o['error_note'] ?? '',
    ];
}

$donation_stmt = $pdo->query("SELECT * FROM paypal_donations");
foreach ($donation_stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
    $rows[] = [
        'type'        => 'donation',
        'created_at'  => $d['created_at'],
        'who'         => $d['donor_name'] ?: $d['donor_email'],
        'detail'      => $d['donor_email'],
        'amount'      => $d['amount'],
        'status'      => $d['status'],
        'order_id'    => $d['paypal_order_id'],
        'capture_id'  => $d['paypal_capture_id'],
        'note'        => $d['error_note'] ?? '',
    ];
}

usort($rows, function($a, $b) { return strtotime($b['created_at']) <=> strtotime($a['created_at']); });

admin_header('PayPal Activity');
echo show_flash();
?>
<style>
.pdo-table td,.pdo-table th{padding:.55rem .9rem}
.pdo-table th{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5a6a7a;background:#f7f9fc;white-space:nowrap}
.pdo-table td{border-top:1px solid #f0f2f5;font-size:.84rem;vertical-align:middle}
.pdo-table tr:hover td{background:#fafbfc}
.status-pill{display:inline-block;padding:.25rem .7rem;border-radius:99px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#fff;white-space:nowrap}
.type-pill{display:inline-block;padding:.2rem .6rem;border-radius:4px;font-size:.7rem;font-weight:700;white-space:nowrap}
</style>

<div class="page-head">
  <h1>PayPal Activity</h1>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="income.php" class="btn btn-secondary">← Income Ledger</a>
    <form method="POST" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="test_connection">
      <button type="submit" class="btn btn-secondary">Test PayPal Connection (<?= h(paypal_mode_label()) ?>)</button>
    </form>
    <?php if (!empty($rows)): ?>
    <form method="POST" onsubmit="return pdoConfirmClearAll(<?= count($rows) ?>)" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="clear_all">
      <button type="submit" class="btn btn-danger">Clear All Records</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<p style="font-size:.78rem;color:#9aa5b4;margin-top:-.75rem;margin-bottom:1.25rem">Dues checkouts and donations made online via PayPal. Clearing only removes this diagnostic log — it never changes a cadet's paid status or the Income Ledger.</p>

<script>
function pdoConfirmClearAll(count) {
  if (!confirm('Permanently delete all ' + count + ' PayPal record(s) shown below (dues checkouts and donations)? This cannot be undone.')) return false;
  var typed = prompt('Type DELETE (all caps) to confirm.');
  if (typed !== 'DELETE') { alert('Not confirmed — nothing was deleted.'); return false; }
  return true;
}
</script>

<?php if (empty($rows)): ?>
  <p style="color:#9aa5b4">No online PayPal activity yet.</p>
<?php else: ?>
<div class="card" style="padding:0;overflow-x:auto">
<table class="pdo-table" style="width:100%;border-collapse:collapse">
  <thead>
    <tr>
      <th>Created</th>
      <th>Type</th>
      <th>Who</th>
      <th>Detail</th>
      <th style="text-align:right">Amount</th>
      <th>Status</th>
      <th>Order / Capture ID</th>
      <th>Note</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $r):
    list($status_label, $sc) = pdo_display_status($r['status'], $r['created_at'], $r['type']);
    $is_dues = $r['type'] === 'dues';
    $type_color = $is_dues ? '#1565c0' : '#e65100'; ?>
  <tr>
    <td style="white-space:nowrap"><?= date('M j, Y g:ia', strtotime($r['created_at'])) ?></td>
    <td><span class="type-pill" style="background:<?= $type_color ?>22;color:<?= $type_color ?>"><?= $is_dues ? 'Dues' : 'Donation' ?></span></td>
    <td style="font-weight:600"><?= h($r['who']) ?></td>
    <td style="color:#5a6a7a"><?= h($r['detail']) ?></td>
    <td style="text-align:right;font-weight:700;color:#1b5e20;white-space:nowrap">$<?= number_format($r['amount'],2) ?></td>
    <td><span class="status-pill" style="background:<?= $sc ?>"><?= h($status_label) ?></span></td>
    <td style="font-size:.72rem;color:#9aa5b4;font-family:monospace">
      <?= h($r['order_id']) ?><?php if ($r['capture_id']): ?><br><?= h($r['capture_id']) ?><?php endif; ?>
    </td>
    <td style="font-size:.72rem;color:#9aa5b4"><?= h($r['note']) ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php admin_footer(); ?>

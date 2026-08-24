<?php
/**
 * Read-only diagnostic/reconciliation view of everything that's touched
 * PayPal — money coming in (dues checkouts, donations) and money going out
 * (reimbursement payouts) — merged into one list so a treasurer can see
 * every PayPal transaction and its outcome without digging through the
 * PayPal dashboard. No edit actions here; corrections happen through the
 * normal dues-editing UI (edit-dues.php), the Purchases page (for payouts),
 * or the Income Ledger, same as any other payment discrepancy.
 *
 * Payout rows are read-only reflections of the `purchases` table — that's
 * the real reimbursement record, not a diagnostic log, so "Clear All
 * Records" below only ever clears paypal_dues_orders/paypal_donations and
 * never touches purchases.
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

// Deletes a single dues or donation diagnostic-log row — same invariant as
// Clear All above (never touches membership_paid_years or income_entries).
// Payout rows aren't deletable here on purpose: they reflect a real
// purchases/reimbursement record, not a diagnostic log, so removing one
// belongs on the Purchases page where the purchase itself lives.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_one') {
    csrf_verify();
    $del_type = $_POST['type'] ?? '';
    $del_id   = (int)($_POST['id'] ?? 0);
    if ($del_id > 0 && $del_type === 'dues') {
        $pdo->prepare('DELETE FROM paypal_dues_orders WHERE id = ?')->execute([$del_id]);
        flash('success', 'Dues checkout record deleted.');
    } elseif ($del_id > 0 && $del_type === 'donation') {
        $pdo->prepare('DELETE FROM paypal_donations WHERE id = ?')->execute([$del_id]);
        flash('success', 'Donation record deleted.');
    } else {
        flash('error', 'Invalid record to delete.');
    }
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
// Payouts use PayPal's own Payouts API status vocabulary (SUCCESS/PENDING/
// etc., set by paypal_send_payout()/paypal_check_payout_status() in
// admin/lib/paypal.php) plus this app's own SENDING/NEEDS_MANUAL_CHECK —
// entirely different strings from the dues/donation statuses above, so
// they get their own branch rather than trying to unify the vocabularies.
// Nullable params on purpose: a single row with an unexpected null status
// or timestamp must never fatal the whole list off the page — worst case
// it just falls through to the generic label below.
function pdo_display_status(?string $status, ?string $created_at, string $type): array {
    $status = $status ?? '';
    if ($type === 'donation') {
        if ($status === 'captured') return ['Paid', '#1b5e20'];
        if ($status === 'amount_mismatch') return ['Needs Review', '#c62828'];
    } elseif ($type === 'payout') {
        if ($status === 'SUCCESS') return ['Paid', '#1b5e20'];
        if (in_array($status, ['PENDING', 'SENDING', 'UNCLAIMED'], true)) return ['Pending', '#f57f17'];
        if (in_array($status, ['FAILED', 'DENIED', 'BLOCKED', 'RETURNED', 'REFUNDED', 'ONHOLD', 'NEEDS_MANUAL_CHECK'], true)) return ['Needs Review', '#c62828'];
        return [$status !== '' ? ucfirst(strtolower(str_replace('_', ' ', $status))) : 'Unknown', '#5a6a7a'];
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
        'id'          => $o['id'],
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
        'id'          => $d['id'],
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

// "Clear All Records" below only deletes the two diagnostic-log tables
// above — everything counted so far is fair game for that. Payouts,
// added next, are read-only reflections of the real purchases/reimbursement
// record and must never be included in that count or that delete.
$clearable_count = count($rows);

// Money going OUT — reimbursements paid via PayPal (admin/purchase-action.php's
// "Send via PayPal"). Only purchases where a payout was actually attempted;
// everything else about a purchase (approval, receipts, etc.) belongs on
// the Purchases page, not here.
$payout_stmt = $pdo->query(
    "SELECT p.vendor, p.amount_total, p.paypal_payout_batch_id, p.paypal_payout_status, p.paypal_payout_sent_at, u.name AS submitted_by_name
     FROM purchases p
     LEFT JOIN users u ON u.id = p.submitted_by
     WHERE p.paypal_payout_batch_id IS NOT NULL AND p.paypal_payout_batch_id <> ''"
);
foreach ($payout_stmt->fetchAll(PDO::FETCH_ASSOC) as $po) {
    $rows[] = [
        'type'        => 'payout',
        'id'          => null,
        'created_at'  => $po['paypal_payout_sent_at'],
        'who'         => $po['submitted_by_name'] ?: 'Unknown',
        'detail'      => $po['vendor'],
        'amount'      => $po['amount_total'],
        'status'      => $po['paypal_payout_status'],
        'order_id'    => $po['paypal_payout_batch_id'],
        'capture_id'  => null,
        'note'        => '',
    ];
}

usort($rows, function($a, $b) { return strtotime($b['created_at'] ?? '') <=> strtotime($a['created_at'] ?? ''); });

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
    <?php if ($clearable_count > 0): ?>
    <form method="POST" onsubmit="return pdoConfirmClearAll(<?= $clearable_count ?>)" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="clear_all">
      <button type="submit" class="btn btn-danger">Clear All Records</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<p style="font-size:.78rem;color:#9aa5b4;margin-top:-.75rem;margin-bottom:1.25rem">Everything that's touched PayPal: dues checkouts, donations, and reimbursement payouts. Clear All only removes the dues/donation diagnostic log below — it never changes a cadet's paid status, the Income Ledger, or any purchase/payout record.</p>

<script>
function pdoConfirmClearAll(count) {
  if (!confirm('Permanently delete all ' + count + ' dues/donation PayPal record(s) (payouts are not affected)? This cannot be undone.')) return false;
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
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php
  $TYPE_LABELS = ['dues' => 'Dues', 'donation' => 'Donation', 'payout' => 'Payout'];
  $TYPE_COLORS = ['dues' => '#1565c0', 'donation' => '#e65100', 'payout' => '#00695c'];
  foreach ($rows as $r):
    list($status_label, $sc) = pdo_display_status($r['status'], $r['created_at'], $r['type']);
    $type_color = $TYPE_COLORS[$r['type']] ?? '#5a6a7a'; ?>
  <tr>
    <td style="white-space:nowrap"><?= $r['created_at'] ? date('M j, Y g:ia', strtotime($r['created_at'])) : '—' ?></td>
    <td><span class="type-pill" style="background:<?= $type_color ?>22;color:<?= $type_color ?>"><?= h($TYPE_LABELS[$r['type']] ?? $r['type']) ?></span></td>
    <td style="font-weight:600"><?= h($r['who']) ?></td>
    <td style="color:#5a6a7a"><?= h($r['detail']) ?></td>
    <td style="text-align:right;font-weight:700;color:#1b5e20;white-space:nowrap">$<?= number_format($r['amount'],2) ?></td>
    <td><span class="status-pill" style="background:<?= $sc ?>"><?= h($status_label) ?></span></td>
    <td style="font-size:.72rem;color:#9aa5b4;font-family:monospace">
      <?= h($r['order_id']) ?><?php if ($r['capture_id']): ?><br><?= h($r['capture_id']) ?><?php endif; ?>
    </td>
    <td style="font-size:.72rem;color:#9aa5b4"><?= h($r['note']) ?></td>
    <td style="white-space:nowrap">
      <?php if ($r['id']): ?>
      <form method="POST" onsubmit="return confirm('Delete this <?= $r['type'] === 'dues' ? 'dues checkout' : 'donation' ?> record? This cannot be undone.')" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_one">
        <input type="hidden" name="type" value="<?= h($r['type']) ?>">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
      </form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php admin_footer(); ?>

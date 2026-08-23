<?php
/**
 * Read-only diagnostic/reconciliation view of paypal_dues_orders — lets a
 * treasurer see every online dues checkout attempt and its outcome without
 * digging through the PayPal dashboard. No edit actions here; corrections
 * happen through the normal dues-editing UI (edit-dues.php) plus the
 * Income Ledger, same as any other payment discrepancy.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/paypal.php';
require_finance();
if (!is_treasurer() && !is_super_admin()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

// Clears this diagnostic log only — never touches members.membership_paid_years
// or income_entries, so it can't undo a real payment or dues status, only
// the PayPal order/capture ID breadcrumb trail for looking one up later.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_all') {
    csrf_verify();
    $pdo->exec('DELETE FROM paypal_dues_orders');
    flash('success', 'PayPal Dues Orders log cleared.');
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

// Same check, but against a Client ID/Secret typed in on the spot rather
// than what's saved in config.php — lets a treasurer sanity-check new live
// credentials before pasting them into config.php. Nothing typed here is
// ever written to the database, session, or log — used once for this one
// cURL call and then discarded when the request ends.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_manual') {
    csrf_verify();
    $manual_client_id = trim($_POST['manual_client_id'] ?? '');
    $manual_secret     = trim($_POST['manual_secret'] ?? '');
    if ($manual_client_id === '' || $manual_secret === '') {
        flash('error', 'Enter both a Client ID and Secret to test.');
    } else {
        $auth = paypal_get_access_token($manual_client_id, $manual_secret, 'live');
        if ($auth['token']) {
            flash('success', 'These credentials work (live mode). Safe to paste into config.php.');
        } else {
            flash('error', $auth['error']);
        }
    }
    header('Location: paypal-dues-orders.php');
    exit;
}

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
    <form method="POST" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="test_connection">
      <button type="submit" class="btn btn-secondary">Test PayPal Connection (<?= h(paypal_mode_label()) ?>)</button>
    </form>
    <?php if (!empty($orders)): ?>
    <form method="POST" onsubmit="return pdoConfirmClearAll(<?= count($orders) ?>)" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="clear_all">
      <button type="submit" class="btn btn-danger">Clear All Records</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<p style="font-size:.78rem;color:#9aa5b4;margin-top:-.75rem;margin-bottom:1.25rem">Clearing only removes this diagnostic log — it never changes a cadet's paid status or the Income Ledger.</p>

<div class="card">
  <h2>Test Different Live Credentials</h2>
  <p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1rem">
    Sanity-check a Client ID/Secret against PayPal's <strong>live</strong> API before pasting them into config.php.
    Nothing entered here is saved anywhere — it's used once for this test and discarded.
  </p>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="test_manual">
    <div class="form-row col-3" style="align-items:end">
      <div class="form-group">
        <label for="manual_client_id">Client ID</label>
        <input type="text" id="manual_client_id" name="manual_client_id" autocomplete="off" spellcheck="false">
      </div>
      <div class="form-group">
        <label for="manual_secret">Secret</label>
        <input type="password" id="manual_secret" name="manual_secret" autocomplete="off" spellcheck="false">
      </div>
      <div class="form-group" style="margin-bottom:.9rem">
        <button type="submit" class="btn btn-primary">Test These Credentials (live)</button>
      </div>
    </div>
  </form>
</div>

<script>
function pdoConfirmClearAll(count) {
  if (!confirm('Permanently delete all ' + count + ' PayPal Dues Order record(s) shown below? This cannot be undone.')) return false;
  var typed = prompt('Type DELETE (all caps) to confirm.');
  if (typed !== 'DELETE') { alert('Not confirmed — nothing was deleted.'); return false; }
  return true;
}
</script>

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

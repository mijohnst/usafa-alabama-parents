<?php
/**
 * Public Donation — Capture Order
 * The load-bearing endpoint: this is the only place a donation actually
 * gets applied. Amount is never accepted from the client here — only read
 * back from the paypal_donations tracking row created by
 * donate-create-order.php. Same discipline as dues-pay-capture-order.php:
 * only proceed once PayPal confirms the capture status and the amount
 * matches what was locked in at order creation.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://alabamafalcons.org');

require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/admin/form-guard.php';
require_once __DIR__ . '/admin/lib/paypal.php';
require_once __DIR__ . '/admin/mailer.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$input   = file_get_contents('php://input');
$payload = json_decode($input, true);

if (!$payload) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request data.']);
    exit();
}

$pdo = get_pdo();

if (rate_limited($pdo, 'donate_capture_order', 10, 15)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many attempts from your network. Please try again later or email treasurer@alabamafalcons.org.']);
    exit();
}

$order_id = trim($payload['orderId'] ?? '');
if ($order_id === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing order id.']);
    exit();
}

$track_stmt = $pdo->prepare('SELECT * FROM paypal_donations WHERE paypal_order_id = ?');
$track_stmt->execute([$order_id]);
$track = $track_stmt->fetch(PDO::FETCH_ASSOC);

if (!$track) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'We could not find that donation. Please try again.']);
    exit();
}

// Idempotent no-op: this order was already fully captured by an earlier
// call (e.g. a duplicate onApprove firing twice in the browser).
if ($track['status'] === 'captured') {
    echo json_encode(['success' => true, 'amount' => number_format((float)$track['amount'], 2), 'captureId' => $track['paypal_capture_id'] ?? null]);
    exit();
}

function notify_treasurer_donation_issue(string $subject, string $detail): void {
    send_notification('treasurer@alabamafalcons.org', $subject, nl2br(htmlspecialchars($detail)));
}

// Atomic claim: without this, two near-simultaneous requests for the same
// order (a duplicate onApprove firing twice, a double-click) could both
// pass the "not yet captured" check above, both call PayPal, and both
// proceed to do local bookkeeping below — PayPal's own idempotency key
// stops a second real charge, but nothing stops a second Income Ledger
// entry and a second pair of emails. Mirrors the same
// claim-before-external-call pattern admin/purchase-action.php's
// send_paypal action already uses for outgoing payouts.
$claim = $pdo->prepare("UPDATE paypal_donations SET status = 'processing' WHERE id = ? AND status = 'created'");
$claim->execute([$track['id']]);
if ($claim->rowCount() !== 1) {
    // Someone else already claimed this exact order (or it resolved to a
    // terminal state) between our SELECT above and now — re-fetch rather
    // than assume, so a request that lost the race still reports the real
    // outcome instead of a generic "still processing" for a donation that
    // actually already finished.
    $recheck = $pdo->prepare('SELECT * FROM paypal_donations WHERE id = ?');
    $recheck->execute([$track['id']]);
    $track = $recheck->fetch(PDO::FETCH_ASSOC);
    $status = $track['status'] ?? '';
    if ($status === 'captured') {
        echo json_encode(['success' => true, 'amount' => number_format((float)$track['amount'], 2), 'captureId' => $track['paypal_capture_id'] ?? null]);
    } elseif ($status === 'amount_mismatch' || $status === 'capture_ok_apply_failed') {
        echo json_encode(['success' => false, 'manualReview' => true, 'error' => "PayPal received this payment, but we couldn't automatically record it. The treasurer has been notified and will follow up — please don't submit payment again."]);
    } else {
        echo json_encode(['success' => false, 'error' => 'This donation is already being processed. Please wait a moment before trying again.']);
    }
    exit();
}

// Sandbox mode always reports a successful capture (it's fake test money),
// but this code has no other way to know that -- it logs income and emails
// the donor/treasurer exactly like a real live payment. Tagging every
// notes field/subject line here is the only thing that keeps a sandbox
// test from looking identical to a real donation later on.
$capture_note_prefix = paypal_mode_label() === 'live' ? '' : '[SANDBOX TEST] ';

$capture_id = null;
$captured_amount = null;
$funding_source = 'PayPal';

$result = paypal_capture_order($order_id, 'capture-' . $order_id);
if ($result['success']) {
    $capture_id = $result['capture_id'];
    $captured_amount = $result['captured_amount'];
    $funding_source = $result['funding_source'];
} elseif (!empty($result['already_captured'])) {
    $recover = paypal_get_order($order_id);
    if (!$recover['success']) {
        error_log('donate-capture-order: recovery failed for order ' . $order_id . ': ' . $recover['error']);
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => 'We could not confirm your donation status. Please contact treasurer@alabamafalcons.org with your PayPal receipt.']);
        exit();
    }
    $capture_id = $recover['capture_id'];
    $captured_amount = $recover['captured_amount'];
    $funding_source = $recover['funding_source'];
} else {
    error_log('donate-capture-order: capture failed for order ' . $order_id . ': ' . $result['error']);
    echo json_encode(['success' => false, 'error' => 'Your donation could not be completed — no charge was made. Please try again, or email treasurer@alabamafalcons.org to give by Zelle or check instead.']);
    exit();
}

if (abs((float)$captured_amount - (float)$track['amount']) > 0.001) {
    $pdo->prepare("UPDATE paypal_donations SET paypal_capture_id=?, status='amount_mismatch', captured_at=NOW() WHERE id=?")
        ->execute([$capture_id, $track['id']]);
    notify_treasurer_donation_issue(
        "{$capture_note_prefix}PayPal donation amount mismatch — needs review",
        "Order $order_id / capture $capture_id captured \$$captured_amount but was expected to be \${$track['amount']} from {$track['donor_email']}. Please reconcile manually in the Income Ledger."
    );
    // PayPal did capture real money here, just not the expected amount —
    // but it was never recorded, so this must not read as a completed
    // donation to the browser (a plain success would tell the donor
    // "thank you" for something that still needs a human to sort out; a
    // plain failure would invite them to pay again and risk double-paying).
    // manualReview is the distinct third state the front end checks for.
    echo json_encode([
        'success'      => false,
        'manualReview' => true,
        'amount'       => number_format((float)$captured_amount, 2),
        'error'        => 'PayPal received your payment of $' . number_format((float)$captured_amount, 2) . ", but we couldn't automatically record it. The treasurer has been notified and will follow up shortly — please don't submit payment again.",
    ]);
    exit();
}

$donor_name  = (string)($track['donor_name'] ?? '');
$donor_email = (string)$track['donor_email'];

// $track['campaign'] only exists once the saber-fund migration has run —
// read defensively so a pre-migration paypal_donations table (missing the
// column entirely) just falls back to the general "Online Donation" label
// instead of throwing.
$campaign       = $track['campaign'] ?? null;
$campaigns      = donation_campaigns($pdo);
$campaign_label = ($campaign && isset($campaigns[$campaign])) ? saber_fund_label($pdo, $campaign, $campaigns[$campaign]) : null;
$description    = $campaign_label ? "Online Donation — {$campaign_label}" : 'Online Donation';

// Both writes below happen in one transaction so a crash between them
// (a host-level kill, a fatal error, a timeout) can never leave this
// donation marked captured with no matching Income Ledger entry — either
// both succeed or neither does, and the row is left claimable by a retry
// rather than silently skipping the ledger insert forever (the old
// failure mode: status was set to 'captured' first, so the idempotent
// check at the top of this file would short-circuit any retry before it
// ever reached the ledger insert again).
$applied_ok = false;
$pdo->beginTransaction();
try {
    // funding_source only exists once migrate_paypal_funding_source.sql has
    // run — fall back to the pre-migration UPDATE so this endpoint (the
    // only place a donation is actually applied) never breaks on an
    // un-migrated DB.
    try {
        $pdo->prepare("UPDATE paypal_donations SET paypal_capture_id=?, status='captured', captured_at=NOW(), funding_source=? WHERE id=?")
            ->execute([$capture_id, $funding_source, $track['id']]);
    } catch (\PDOException $e) {
        $pdo->prepare("UPDATE paypal_donations SET paypal_capture_id=?, status='captured', captured_at=NOW() WHERE id=?")
            ->execute([$capture_id, $track['id']]);
    }

    $pdo->prepare(
        'INSERT INTO income_entries (entry_date, source, source_type, description, amount, payment_method, notes, received_by)
         VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $donor_name ?: $donor_email,
        'donation',
        $description,
        $track['amount'],
        $funding_source,
        "{$capture_note_prefix}PayPal order $order_id, capture $capture_id",
        null,
    ]);

    $pdo->commit();
    $applied_ok = true;
} catch (\Throwable $e) {
    $pdo->rollBack();
    error_log('donate-capture-order: bookkeeping transaction failed for order ' . $order_id . ': ' . $e->getMessage());
    // Same terminal-status name dues-pay-capture-order.php already uses for
    // the equivalent situation (capture confirmed, our own bookkeeping
    // failed) — kept consistent across both endpoints for anyone reading
    // status values later.
    $pdo->prepare("UPDATE paypal_donations SET status='capture_ok_apply_failed', paypal_capture_id=? WHERE id=?")
        ->execute([$capture_id, $track['id']]);
    notify_treasurer_donation_issue(
        "{$capture_note_prefix}ACTION NEEDED: PayPal donation captured but not recorded",
        "Order $order_id / capture $capture_id from $donor_email (\${$track['amount']}) was successfully captured by PayPal, but our system failed to log it to the Income Ledger: {$e->getMessage()}. Please add it manually."
    );
}

// The receipt/treasurer-notification emails fire regardless of whether our
// own ledger bookkeeping succeeded — PayPal genuinely captured this money
// either way, so the donor's receipt (their tax-deduction proof) and the
// treasurer's heads-up shouldn't depend on an internal accounting detail.
try {
    send_donation_receipt($donor_email, $donor_name, (float)$track['amount'], $capture_id, $capture_note_prefix, $campaign_label);
} catch (\Throwable $e) {
    error_log('donate-capture-order: donor receipt email failed for order ' . $order_id . ': ' . $e->getMessage());
}
try {
    notify_treasurer_of_donation($donor_name, $donor_email, (float)$track['amount'], $order_id, (string)$capture_id, $capture_note_prefix, $campaign_label);
} catch (\Throwable $e) {
    error_log('donate-capture-order: treasurer notification email failed for order ' . $order_id . ': ' . $e->getMessage());
}

if ($applied_ok) {
    echo json_encode(['success' => true, 'amount' => number_format((float)$track['amount'], 2), 'captureId' => $capture_id]);
} else {
    echo json_encode([
        'success'      => false,
        'manualReview' => true,
        'amount'       => number_format((float)$track['amount'], 2),
        'error'        => 'PayPal received your donation of $' . number_format((float)$track['amount'], 2) . ", but we couldn't automatically record it. The treasurer has been notified and will follow up shortly — please don't submit payment again.",
    ]);
}

<?php
/**
 * Pay Dues Online — Capture Order
 * The load-bearing endpoint: this is the only place a payment actually
 * gets applied to a member's record. years/amount are never accepted from
 * the client here — only read back from the session + paypal_dues_orders
 * tracking row. save_dues_years() is only ever called after PayPal has
 * confirmed the capture status is COMPLETED (or recoverable via
 * already_captured) and the amount matches what we locked in at order
 * creation. See the plan's "core invariant" — this mirrors the same
 * discipline already applied to Payouts in admin/purchase-action.php.
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

if (rate_limited($pdo, 'dues_pay_capture_order', 10, 15)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many attempts from your network. Please try again later or email treasurer@alabamafalcons.org.']);
    exit();
}

start_verification_session();
$token = trim($payload['verifyToken'] ?? '');
$entry = $_SESSION['dues_verified'][$token] ?? null;

if (!$entry || ($entry['expires'] ?? 0) < time()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Your session has expired. Please look up your cadet again.']);
    exit();
}

$pending = $entry['pending_order'] ?? null;
$order_id = trim($payload['orderId'] ?? '');

if (!$pending || $order_id === '' || $order_id !== $pending['order_id']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'That order does not match your current session. Please look up your cadet again.']);
    exit();
}

$member_id = (int)$entry['member_id'];

$track_stmt = $pdo->prepare('SELECT * FROM paypal_dues_orders WHERE paypal_order_id = ? AND member_id = ?');
$track_stmt->execute([$order_id, $member_id]);
$track = $track_stmt->fetch(PDO::FETCH_ASSOC);

if (!$track) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'We could not find that order. Please try again.']);
    exit();
}

// Idempotent no-op: this order was already fully applied by an earlier
// call (e.g. a duplicate onApprove firing twice in the browser).
if ($track['status'] === 'applied') {
    echo json_encode(['success' => true, 'years' => explode(',', $track['years']), 'captureId' => $track['paypal_capture_id'] ?? null]);
    exit();
}

function notify_treasurer_capture_issue(string $subject, string $detail): void {
    send_notification('treasurer@alabamafalcons.org', $subject, nl2br(htmlspecialchars($detail)));
}

// Atomic claim: without this, two near-simultaneous requests for the same
// order (a duplicate onApprove firing twice, a double-click) could both
// pass the "not yet applied" check above, both call PayPal, and both
// proceed to apply dues years and log income below — PayPal's own
// idempotency key stops a second real charge, but nothing stops duplicate
// bookkeeping. Mirrors the same claim-before-external-call pattern
// admin/purchase-action.php's send_paypal action already uses for outgoing
// payouts.
$claim = $pdo->prepare("UPDATE paypal_dues_orders SET status = 'processing' WHERE id = ? AND status = 'created'");
$claim->execute([$track['id']]);
if ($claim->rowCount() !== 1) {
    // Someone else already claimed this exact order (or it resolved to a
    // terminal state) between our SELECT above and now — re-fetch rather
    // than assume, so a request that lost the race still reports the real
    // outcome instead of a generic "still processing" for a payment that
    // actually already finished.
    $recheck = $pdo->prepare('SELECT * FROM paypal_dues_orders WHERE id = ?');
    $recheck->execute([$track['id']]);
    $track = $recheck->fetch(PDO::FETCH_ASSOC);
    $status = $track['status'] ?? '';
    if ($status === 'applied') {
        echo json_encode(['success' => true, 'years' => explode(',', $track['years']), 'captureId' => $track['paypal_capture_id'] ?? null]);
    } elseif (in_array($status, ['amount_mismatch', 'capture_ok_apply_failed', 'needs_manual_review'], true)) {
        echo json_encode(['success' => false, 'manualReview' => true, 'error' => "PayPal received this payment, but we couldn't automatically apply it. The treasurer has been notified and will follow up — please don't submit payment again."]);
    } else {
        echo json_encode(['success' => false, 'error' => 'This payment is already being processed. Please wait a moment before trying again.']);
    }
    exit();
}

// Sandbox mode always reports a successful capture (it's fake test money),
// but this code has no other way to know that -- it applies the dues years
// and logs income exactly like a real live payment. Tagging the note/subject
// is the only thing that keeps a sandbox test from looking identical to a
// real payment (or a real problem, for the alert emails below) later on.
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
        error_log('dues-pay-capture-order: recovery failed for order ' . $order_id . ': ' . $recover['error']);
        // PayPal told us this order was already captured, but we couldn't
        // confirm the details — unlike an ordinary failure below, we do
        // NOT know money didn't move, so this must not be left claimable
        // by a retry (that could risk a second real charge) nor silently
        // stuck at 'processing' forever. Flag for a human to check PayPal
        // directly instead.
        $pdo->prepare("UPDATE paypal_dues_orders SET status = 'needs_manual_review' WHERE id = ?")->execute([$track['id']]);
        notify_treasurer_capture_issue(
            "{$capture_note_prefix}ACTION NEEDED: PayPal dues status unconfirmed after retry",
            "Order $order_id for member #$member_id (\${$track['amount']}, years {$track['years']}) — PayPal reported this order was already captured, but we could not retrieve the capture details ({$recover['error']}). Please check the PayPal dashboard directly for order $order_id and reconcile manually if it succeeded."
        );
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => 'We could not confirm your payment status. Please contact treasurer@alabamafalcons.org with your PayPal receipt.']);
        exit();
    }
    $capture_id = $recover['capture_id'];
    $captured_amount = $recover['captured_amount'];
    $funding_source = $recover['funding_source'];
} else {
    error_log('dues-pay-capture-order: capture failed for order ' . $order_id . ': ' . $result['error']);
    // PayPal never captured anything here (unlike the already_captured
    // branch above), so it's safe to release the claim taken earlier —
    // without this, the row stays stuck at 'processing' forever and every
    // retry falls into the "already being processed" recheck above,
    // permanently blocking a parent who just had an ordinary declined card.
    $pdo->prepare("UPDATE paypal_dues_orders SET status = 'created' WHERE id = ? AND status = 'processing'")->execute([$track['id']]);
    echo json_encode(['success' => false, 'error' => 'Your payment could not be completed — no charge was made. Please try again, use the Zelle option below, or email treasurer@alabamafalcons.org.']);
    exit();
}

if (abs((float)$captured_amount - (float)$track['amount']) > 0.001) {
    $pdo->prepare("UPDATE paypal_dues_orders SET paypal_capture_id=?, status='amount_mismatch', captured_at=NOW() WHERE id=?")
        ->execute([$capture_id, $track['id']]);
    notify_treasurer_capture_issue(
        "{$capture_note_prefix}PayPal dues amount mismatch — needs review",
        "Order $order_id / capture $capture_id captured \$$captured_amount but was expected to be \${$track['amount']} for member #$member_id (years: {$track['years']}). Please reconcile manually in the Income Ledger."
    );
    // PayPal captured real money here, just not the expected amount, and
    // the years below were never actually applied to the member's record —
    // the old response claiming 'years' were paid was flatly untrue. This
    // must not read as a completed payment (a plain success lies about
    // membership state) nor as a failure (inviting a second, duplicate
    // payment) — manualReview is the distinct third state the front end
    // checks for.
    echo json_encode([
        'success'      => false,
        'manualReview' => true,
        'error'        => "PayPal received your payment, but the amount didn't match what we expected, so we couldn't automatically apply it. The treasurer has been notified and will follow up shortly — please don't submit payment again.",
    ]);
    exit();
}

$pdo->prepare("UPDATE paypal_dues_orders SET paypal_capture_id=?, status='captured', captured_at=NOW() WHERE id=?")
    ->execute([$capture_id, $track['id']]);

// Race re-check: someone (a treasurer, another payment) may have marked
// one of these years paid while this checkout was in flight. Never
// silently drop money if that happened — flag it for manual review
// instead of double-applying or discarding the payment.
$member_stmt = $pdo->prepare('SELECT * FROM members WHERE id = ?');
$member_stmt->execute([$member_id]);
$row = $member_stmt->fetch(PDO::FETCH_ASSOC);
$current_paid = parse_dues_years($row['membership_paid_years'] ?? '');
$order_years  = explode(',', $track['years']);
$still_needed = array_values(array_diff($order_years, $current_paid));

if (!$still_needed) {
    $pdo->prepare("UPDATE paypal_dues_orders SET status='needs_manual_review' WHERE id=?")->execute([$track['id']]);
    notify_treasurer_capture_issue(
        "{$capture_note_prefix}PayPal dues payment needs manual review",
        "Order $order_id / capture $capture_id for member #$member_id (\${$track['amount']}) captured successfully, but years {$track['years']} were already marked paid by the time we went to apply it. Please confirm this isn't a double payment and reconcile in the Income Ledger."
    );
    echo json_encode(['success' => true, 'years' => $order_years, 'captureId' => $capture_id]);
    exit();
}

// save_dues_years() does its own separate UPDATE (members) and, when the
// price actually changed, INSERT (income_entries) — wrapped in a
// transaction together with the status='applied' update below so a crash
// partway through (host-level kill, fatal error, timeout) can never leave
// dues marked applied with a half-written members/income_entries state, or
// vice versa. Previously this whole block fell through to an unconditional
// "success" response at the bottom regardless of whether either attempt
// below actually succeeded — $applied_ok now tracks the real outcome.
$applied_ok = false;
$pdo->beginTransaction();
try {
    save_dues_years(
        $pdo,
        $member_id,
        array_merge($current_paid, $still_needed),
        true,
        $row,
        $funding_source,
        "{$capture_note_prefix}PayPal order $order_id, capture $capture_id"
    );
    $pdo->prepare("UPDATE paypal_dues_orders SET status='applied', applied_at=NOW() WHERE id=?")->execute([$track['id']]);
    $pdo->commit();
    $applied_ok = true;
} catch (\Throwable $e) {
    $pdo->rollBack();
    error_log('dues-pay-capture-order: save_dues_years failed for order ' . $order_id . ': ' . $e->getMessage());
    // One retry, in case of a transient DB hiccup, before giving up and
    // flagging for the treasurer — the capture already succeeded at
    // PayPal, so this money must never be silently lost track of.
    $pdo->beginTransaction();
    try {
        save_dues_years(
            $pdo,
            $member_id,
            array_merge($current_paid, $still_needed),
            true,
            $row,
            $funding_source,
            "{$capture_note_prefix}PayPal order $order_id, capture $capture_id"
        );
        $pdo->prepare("UPDATE paypal_dues_orders SET status='applied', applied_at=NOW() WHERE id=?")->execute([$track['id']]);
        $pdo->commit();
        $applied_ok = true;
    } catch (\Throwable $e2) {
        $pdo->rollBack();
        error_log('dues-pay-capture-order: save_dues_years retry failed for order ' . $order_id . ': ' . $e2->getMessage());
        $pdo->prepare("UPDATE paypal_dues_orders SET status='capture_ok_apply_failed', error_note=? WHERE id=?")
            ->execute([$e2->getMessage(), $track['id']]);
        notify_treasurer_capture_issue(
            "{$capture_note_prefix}ACTION NEEDED: PayPal dues payment captured but not recorded",
            "Order $order_id / capture $capture_id for member #$member_id (\${$track['amount']}, years {$track['years']}) was successfully captured by PayPal, but our system failed to record it: {$e2->getMessage()}. Please mark the year(s) paid manually in the member's profile and note the capture id for reference."
        );
    }
}

// Clear the completed pending order but keep the token valid so a parent
// paying for multiple non-contiguous years doesn't need to re-verify.
$_SESSION['dues_verified'][$token]['pending_order'] = null;

if ($applied_ok) {
    echo json_encode(['success' => true, 'years' => $order_years, 'captureId' => $capture_id]);
} else {
    // The old response claimed success (and listed years as paid) even
    // when both attempts above failed — membership was never actually
    // updated. manualReview is the same distinct third state used by the
    // amount-mismatch branch above: not a completed payment, but not an
    // invitation to pay again either.
    echo json_encode([
        'success'      => false,
        'manualReview' => true,
        'error'        => "PayPal received your payment, but we couldn't automatically apply it to your cadet's dues record. The treasurer has been notified and will follow up shortly — please don't submit payment again.",
    ]);
}

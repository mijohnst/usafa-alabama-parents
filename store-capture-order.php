<?php
/**
 * Public Club Store — Capture Order
 * The load-bearing endpoint: this is the only place a store order actually
 * gets applied. Mirrors donate-capture-order.php's hardened reliability
 * pattern close to verbatim (that file was reworked this same session
 * after real bugs were found and fixed) — the state machine, atomic claim,
 * already_captured recovery, and three-way response shape are
 * amount/state-shaped, not item-count-shaped, so there was no reason to
 * redesign them for a multi-item order. Two deliberate differences from
 * the donation version: (1) exactly one income_entries row per order, not
 * per line item — store_order_items carries the per-line detail instead;
 * (2) inventory is intentionally left untouched here for MVP.
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

if (rate_limited($pdo, 'store_capture_order', 10, 15)) {
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

$track_stmt = $pdo->prepare('SELECT * FROM store_orders WHERE paypal_order_id = ?');
$track_stmt->execute([$order_id]);
$track = $track_stmt->fetch(PDO::FETCH_ASSOC);

if (!$track) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'We could not find that order. Please try again.']);
    exit();
}

// Idempotent no-op: this order was already fully captured by an earlier
// call (e.g. a duplicate onApprove firing twice in the browser).
if ($track['status'] === 'captured') {
    echo json_encode(['success' => true, 'total' => number_format((float)$track['total'], 2), 'captureId' => $track['paypal_capture_id'] ?? null]);
    exit();
}

function notify_treasurer_store_issue(string $subject, string $detail): void {
    send_notification('treasurer@alabamafalcons.org', $subject, nl2br(htmlspecialchars($detail)));
}

// Atomic claim — see donate-capture-order.php for the full reasoning.
// Without it, two near-simultaneous requests for the same order could both
// pass the "not yet captured" check above and both perform local
// bookkeeping (PayPal's own idempotency key stops a second real charge,
// but not a second ledger entry or a second pair of emails).
$claim = $pdo->prepare("UPDATE store_orders SET status = 'processing' WHERE id = ? AND status = 'created'");
$claim->execute([$track['id']]);
if ($claim->rowCount() !== 1) {
    $recheck = $pdo->prepare('SELECT * FROM store_orders WHERE id = ?');
    $recheck->execute([$track['id']]);
    $track = $recheck->fetch(PDO::FETCH_ASSOC);
    $status = $track['status'] ?? '';
    if ($status === 'captured') {
        echo json_encode(['success' => true, 'total' => number_format((float)$track['total'], 2), 'captureId' => $track['paypal_capture_id'] ?? null]);
    } elseif (in_array($status, ['amount_mismatch', 'capture_ok_apply_failed', 'needs_manual_review'], true)) {
        echo json_encode(['success' => false, 'manualReview' => true, 'error' => "PayPal received this payment, but we couldn't automatically record it. The treasurer has been notified and will follow up — please don't submit payment again."]);
    } else {
        echo json_encode(['success' => false, 'error' => 'This order is already being processed. Please wait a moment before trying again.']);
    }
    exit();
}

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
        error_log('store-capture-order: recovery failed for order ' . $order_id . ': ' . $recover['error']);
        // PayPal told us this order was already captured, but we couldn't
        // confirm the details — unlike an ordinary failure below, we do
        // NOT know money didn't move, so this must not be left claimable
        // by a retry (that could risk a second real charge) nor silently
        // stuck at 'processing' forever.
        $pdo->prepare("UPDATE store_orders SET status = 'needs_manual_review' WHERE id = ?")->execute([$track['id']]);
        notify_treasurer_store_issue(
            "{$capture_note_prefix}ACTION NEEDED: Club Store order status unconfirmed after retry",
            "Order $order_id from {$track['customer_email']} (\${$track['total']}) — PayPal reported this order was already captured, but we could not retrieve the capture details ({$recover['error']}). Please check the PayPal dashboard directly for order $order_id and reconcile manually if it succeeded."
        );
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => 'We could not confirm your order status. Please contact treasurer@alabamafalcons.org with your PayPal receipt.']);
        exit();
    }
    $capture_id = $recover['capture_id'];
    $captured_amount = $recover['captured_amount'];
    $funding_source = $recover['funding_source'];
} else {
    error_log('store-capture-order: capture failed for order ' . $order_id . ': ' . $result['error']);
    // PayPal never captured anything here, so it's safe to release the
    // claim taken above — without this, the row stays stuck at
    // 'processing' forever and every retry falls into the "already being
    // processed" recheck above, permanently blocking an ordinary decline.
    $pdo->prepare("UPDATE store_orders SET status = 'created' WHERE id = ? AND status = 'processing'")->execute([$track['id']]);
    echo json_encode(['success' => false, 'error' => 'Your order could not be completed — no charge was made. Please try again, or email treasurer@alabamafalcons.org.']);
    exit();
}

if (abs((float)$captured_amount - (float)$track['total']) > 0.001) {
    $pdo->prepare("UPDATE store_orders SET paypal_capture_id=?, status='amount_mismatch', captured_at=NOW() WHERE id=?")
        ->execute([$capture_id, $track['id']]);
    notify_treasurer_store_issue(
        "{$capture_note_prefix}Club Store amount mismatch — needs review",
        "Order $order_id / capture $capture_id captured \$$captured_amount but was expected to be \${$track['total']} from {$track['customer_email']}. Please reconcile manually in the Income Ledger."
    );
    echo json_encode([
        'success'      => false,
        'manualReview' => true,
        'error'        => 'PayPal received your payment, but the amount didn\'t match your order, so we couldn\'t automatically record it. The treasurer has been notified and will follow up shortly — please don\'t submit payment again.',
    ]);
    exit();
}

// Order items were already snapshotted at store-create-order.php time — the
// only work here is (1) marking the order captured, (2) logging exactly
// one Income Ledger row for the whole order (per-line detail lives in
// store_order_items, surfaced via admin/store-orders.php), all in one
// transaction so a crash between the two can never leave the order marked
// paid with no matching ledger entry.
$items_stmt = $pdo->prepare('SELECT * FROM store_order_items WHERE order_id = ? ORDER BY id ASC');
$items_stmt->execute([$track['id']]);
$order_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

$item_count = count($order_items);
$description = 'Club Store order (' . $item_count . ' item' . ($item_count === 1 ? '' : 's') . ')';

$applied_ok = false;
$pdo->beginTransaction();
try {
    $pdo->prepare("UPDATE store_orders SET paypal_capture_id=?, status='captured', funding_source=?, captured_at=NOW() WHERE id=?")
        ->execute([$capture_id, $funding_source, $track['id']]);

    $pdo->prepare(
        'INSERT INTO income_entries (entry_date, source, source_type, description, amount, payment_method, notes, received_by)
         VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $track['customer_name'] ?: $track['customer_email'],
        'store',
        $description,
        $track['total'],
        $funding_source,
        "{$capture_note_prefix}PayPal order $order_id, capture $capture_id",
        null,
    ]);

    $pdo->commit();
    $applied_ok = true;
} catch (\Throwable $e) {
    $pdo->rollBack();
    error_log('store-capture-order: bookkeeping transaction failed for order ' . $order_id . ': ' . $e->getMessage());
    $pdo->prepare("UPDATE store_orders SET status='capture_ok_apply_failed', paypal_capture_id=? WHERE id=?")
        ->execute([$capture_id, $track['id']]);
    notify_treasurer_store_issue(
        "{$capture_note_prefix}ACTION NEEDED: Club Store order captured but not recorded",
        "Order $order_id / capture $capture_id from {$track['customer_email']} (\${$track['total']}) was successfully captured by PayPal, but our system failed to log it to the Income Ledger: {$e->getMessage()}. Please add it manually."
    );
}

// Receipt/treasurer emails fire regardless of whether our own ledger
// bookkeeping succeeded — PayPal genuinely captured this money either way.
try {
    send_store_receipt($track['customer_email'], $track['customer_name'], $order_items, (float)$track['total'], $capture_id, $capture_note_prefix);
} catch (\Throwable $e) {
    error_log('store-capture-order: receipt email failed for order ' . $order_id . ': ' . $e->getMessage());
}
try {
    notify_treasurer_of_store_order($track['customer_name'], $track['customer_email'], $order_items, (float)$track['total'], $order_id, (string)$capture_id, $capture_note_prefix);
} catch (\Throwable $e) {
    error_log('store-capture-order: treasurer notification email failed for order ' . $order_id . ': ' . $e->getMessage());
}

if ($applied_ok) {
    echo json_encode(['success' => true, 'total' => number_format((float)$track['total'], 2), 'captureId' => $capture_id]);
} else {
    echo json_encode([
        'success'      => false,
        'manualReview' => true,
        'total'        => number_format((float)$track['total'], 2),
        'error'        => 'PayPal received your payment of $' . number_format((float)$track['total'], 2) . ", but we couldn't automatically record it. The treasurer has been notified and will follow up shortly — please don't submit payment again.",
    ]);
}

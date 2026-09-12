<?php
/**
 * Public Donation — Create Order
 * Unlike dues-pay-create-order.php, there's no cadet identity to verify
 * here — anyone can donate any amount, so this endpoint trusts the amount
 * the donor typed (after validating it's a sane positive dollar figure)
 * rather than re-deriving it from a member record. donor_name/donor_email
 * are stored purely for the receipt/notification emails sent once the
 * order is captured — they never affect what PayPal actually charges.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://alabamafalcons.org');

require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/admin/form-guard.php';
require_once __DIR__ . '/admin/lib/paypal.php';

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

if (honeypot_tripped($payload, 'website')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit();
}

$pdo = get_pdo();

if (rate_limited($pdo, 'donate_create_order')) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many attempts from your network. Please try again later or email treasurer@alabamafalcons.org.']);
    exit();
}

$amount      = (float)($payload['amount'] ?? 0);
$donor_name  = trim((string)($payload['donorName'] ?? ''));
$donor_email = trim((string)($payload['donorEmail'] ?? ''));

// $25,000 sanity ceiling — a real donation this large would come through
// the treasurer directly, not this public form; this just catches a typo
// or malformed client payload, not a legitimate high-dollar gift.
if ($amount < 1 || $amount > 25000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please enter a donation amount between $1 and $25,000.']);
    exit();
}
if (!filter_var($donor_email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
    exit();
}
$amount = round($amount, 2);
$donor_name = mb_substr($donor_name, 0, 200);

// Optional campaign tag (e.g. a time-limited fundraiser page like
// fundraiser.html) — general donations from payment.html never send this,
// so it defaults to null and the row looks identical to today.
$campaign = trim((string)($payload['campaign'] ?? ''));
if ($campaign !== '' && !isset(DONATION_CAMPAIGNS[$campaign])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown campaign.']);
    exit();
}
$campaign = $campaign !== '' ? $campaign : null;

// Optional short public comment + name-display choice — only meaningful
// for a campaign page that shows a "recent donations" wall (fundraiser.html);
// general donations from payment.html never send these, so they default to
// blank/shown and are simply never displayed anywhere.
$comment = trim((string)($payload['comment'] ?? ''));
$comment = preg_replace('/[\x00-\x1F\x7F]/', ' ', $comment); // strip control chars/newlines
$comment = mb_substr(strip_tags($comment), 0, 240);
$comment = $comment !== '' ? $comment : null;
$show_name = (array_key_exists('showName', $payload) && !$payload['showName']) ? 0 : 1;

$reference_id = 'donation-' . bin2hex(random_bytes(6));
$request_id   = 'create-' . bin2hex(random_bytes(16));

// Tags the order itself with the campaign so it's identifiable directly in
// PayPal's own dashboard/receipts, not just in this site's admin panel —
// custom_id (searchable in PayPal's Activity/CSV export, not shown to the
// donor) gets the stable slug, description (shown to the donor and in
// PayPal's transaction details) gets the current human-readable label.
$paypal_description = $campaign ? saber_fund_label($pdo, $campaign, DONATION_CAMPAIGNS[$campaign]) : null;
$order = paypal_create_order($amount, $reference_id, $request_id, $paypal_description, $campaign);
if (!$order['success']) {
    error_log('donate-create-order: ' . $order['error']);
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'We could not start the PayPal checkout. Please try again in a moment.']);
    exit();
}

// Tries the comment/show_name columns first; falls back to the original
// insert if they don't exist yet (migration not run) so a deploy of this
// code ahead of the migration can never break live donation processing —
// including the general payment.html flow, which shares this endpoint and
// never sends these fields at all. Only the comment/name-display choice is
// lost for a donation caught mid-migration, never the donation itself.
try {
    $pdo->prepare(
        'INSERT INTO paypal_donations (donor_name, donor_email, paypal_order_id, amount, status, campaign, donor_comment, show_name)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$donor_name ?: null, $donor_email, $order['order_id'], $amount, 'created', $campaign, $comment, $show_name]);
} catch (\PDOException $e) {
    error_log('donate-create-order: donor_comment/show_name insert failed, falling back — ' . $e->getMessage());
    $pdo->prepare(
        'INSERT INTO paypal_donations (donor_name, donor_email, paypal_order_id, amount, status, campaign)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$donor_name ?: null, $donor_email, $order['order_id'], $amount, 'created', $campaign]);
}

echo json_encode(['success' => true, 'orderId' => $order['order_id']]);

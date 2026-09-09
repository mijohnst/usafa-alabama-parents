<?php
/**
 * Pay Dues Online — Zelle Notification
 * Called when a parent clicks "Notify Treasurer" after finding their cadet
 * via dues-pay-lookup.php and choosing to pay by Zelle instead of PayPal.
 * Zelle has no API hook to confirm receipt, so this only emails the
 * Treasurer as a heads-up — it does NOT mark anything paid. member_id and
 * the cadet record are re-derived from the session token issued by
 * dues-pay-lookup.php (never trusted from the client), and years/amount are
 * re-validated the same way dues-pay-create-order.php does, so a tampered
 * client payload can't misrepresent what's owed.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://alabamafalcons.org');

require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/admin/form-guard.php';
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

if (rate_limited($pdo, 'dues_pay_zelle_notify')) {
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

$member_id = (int)$entry['member_id'];
$stmt = $pdo->prepare('SELECT * FROM members WHERE id = ? AND archived = 0');
$stmt->execute([$member_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'We could not find that record. Please look up your cadet again.']);
    exit();
}

$cadet_years  = cadet_dues_years($row['class_year'] ?? '');
$paid_years   = parse_dues_years($row['membership_paid_years']);
$requested_in = array_map('strval', (array)($payload['years'] ?? []));
$requested    = array_values(array_diff(array_intersect($requested_in, $cadet_years), $paid_years));

if (!$requested) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please select at least one unpaid year.']);
    exit();
}

$amount = dues_years_price(array_merge($paid_years, $requested), $cadet_years)
        - dues_years_price($paid_years, $cadet_years);

$cadet_name   = trim(cadet_full_name($row)) ?: 'Unknown Cadet';
$parent_email = trim($row['parent1_email'] ?: ($row['parent2_email'] ?: ''));

$subject = "Zelle Payment Expected — $cadet_name (\$$amount)";
$body    = CLUB_NAME . "\n"
         . "Zelle Payment Notification\n"
         . str_repeat('─', 48) . "\n\n"
         . "A parent indicated on the Pay Dues Online page that they are sending "
         . "a Zelle payment for membership dues.\n\n"
         . "Cadet:        $cadet_name\n"
         . "Year(s):      " . implode(', ', $requested) . "\n"
         . "Amount:       \$$amount\n"
         . ($parent_email !== '' ? "Parent Email: $parent_email\n" : '')
         . "\nThis is a heads-up only — please confirm the funds actually arrived in "
         . "the usafapcofal@gmail.com Zelle account before marking dues paid.\n\n"
         . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . ADMIN_URL;

$sent = send_notification('treasurer@alabamafalcons.org', $subject, $body);

if ($sent) {
    echo json_encode(['success' => true]);
} else {
    error_log('dues-pay-zelle-notify: send_notification failed for member_id=' . $member_id);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not send the notification email. Please email treasurer@alabamafalcons.org directly.']);
}

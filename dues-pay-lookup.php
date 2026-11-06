<?php
/**
 * Pay Dues Online — Lookup
 * Same 3-factor identity check as job-drop-lookup.php (cadet last name +
 * birthday + an email already on file — either parent's or, unlike Job
 * Drop, the cadet's own), and also unlike Job Drop this
 * isn't restricted to one class_year — any cadet with an unpaid year can
 * pay. Two steps: the lookup emails a one-time code to the address typed
 * (admin/email-code.php), and only a correct code (action = "verify")
 * returns the payable years and a token in its own session pool
 * (dues_verified), so it doesn't interact with any other verification flow.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://alabamafalcons.org');

require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/admin/form-guard.php';
require_once __DIR__ . '/admin/email-code.php';

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

if (honeypot_tripped($payload)) {
    echo json_encode(['success' => false, 'error' => "We couldn't find a matching record. Please double-check your information, or contact treasurer@alabamafalcons.org."]);
    exit();
}

$pdo = get_pdo();

function s(array $p, string $key): string {
    return trim($p[$key] ?? '');
}

if (s($payload, 'action') === 'verify') {
    // ── Step 2: a correct emailed code returns the payable years ─────────
    [$m] = email_code_verified_member($pdo, 'dues', $payload, 'treasurer@alabamafalcons.org');
} else {
    // ── Step 1: look up the record and email a code ──────────────────────
    if (rate_limited($pdo, 'dues_pay_lookup')) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many attempts from your network. Please try again later or email treasurer@alabamafalcons.org.']);
        exit();
    }

    $last     = s($payload, 'cadetLastName');
    $email    = s($payload, 'email');
    $birthday = s($payload, 'cadetBirthday');

    if ($last === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Please enter the cadet's last name, birthday, and the email address on file."]);
        exit();
    }

    // Shared identity check (admin/lib.php) — same rules as update-lookup.php.
    $m = find_member_by_identity($pdo, $last, $birthday, $email);

    // Code to the address typed; a non-match gets an identical-looking decoy.
    email_code_respond_for_member($pdo, 'dues', $m, $email, 'pay your membership dues', 'treasurer@alabamafalcons.org');
}

echo json_encode(['success' => true] + dues_verified_payload($m));

<?php
/**
 * Parent Letters — Lookup
 * Same identity check as update-lookup.php (cadet last name + graduation
 * year + a parent email already on file), but issues its own token in a
 * separate session pool so it doesn't interact with the Update Your
 * Information flow. Unlike that token, this one isn't consumed after a
 * single use — writing a letter and saving/downloading it can take several
 * separate requests, so it stays valid (and gets refreshed) for the whole
 * session window instead of a one-shot submit.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://alabamafalcons.org');

require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/admin/form-guard.php';

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
    echo json_encode(['success' => false, 'error' => "We couldn't find a matching record. Please double-check your information, or contact secretary@alabamafalcons.org."]);
    exit();
}

$pdo = get_pdo();

if (rate_limited($pdo, 'parent_letters_lookup')) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many attempts from your network. Please try again later or email secretary@alabamafalcons.org.']);
    exit();
}

function s(array $p, string $key): string {
    return trim($p[$key] ?? '');
}

$last  = s($payload, 'cadetLastName');
$year  = s($payload, 'graduationYear');
$email = s($payload, 'email');

if ($last === '' || $year === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please enter the cadet last name, graduation year, and the email address on file.']);
    exit();
}

$stmt = $pdo->prepare(
    'SELECT * FROM members
     WHERE archived = 0 AND class_year = :class_year
       AND (parent1_email = :email OR parent2_email = :email)'
);
$stmt->execute(['class_year' => $year, 'email' => $email]);
$target_norm = strip_name_suffix(normalize_name($last));
$m = null;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (strip_name_suffix(normalize_name($row['cadet_last_name'])) === $target_norm) { $m = $row; break; }
}

if (!$m) {
    echo json_encode([
        'success' => false,
        'error'   => "We couldn't find a matching record. Please double-check the cadet's last name, graduation year, and the email on file, or contact secretary@alabamafalcons.org."
    ]);
    exit();
}

start_verification_session();
$verify_token = bin2hex(random_bytes(24));
if (!isset($_SESSION['letters_verified']) || !is_array($_SESSION['letters_verified'])) {
    $_SESSION['letters_verified'] = [];
}
foreach ($_SESSION['letters_verified'] as $t => $entry) {
    if (($entry['expires'] ?? 0) < time()) unset($_SESSION['letters_verified'][$t]);
}
$_SESSION['letters_verified'][$verify_token] = [
    'member_id' => (int)$m['id'],
    'expires'   => time() + 1800, // 30 minutes, refreshed on each save/list/delete
];

$letters_stmt = $pdo->prepare('SELECT id, letter_body, created_at, updated_at FROM parent_letters WHERE member_id = ? ORDER BY updated_at DESC');
$letters_stmt->execute([(int)$m['id']]);

echo json_encode([
    'success'        => true,
    'verifyToken'    => $verify_token,
    'cadetFirstName' => (string)($m['cadet_first_name'] ?? ''),
    'letters'        => $letters_stmt->fetchAll(PDO::FETCH_ASSOC),
]);

<?php
/**
 * Update-Your-Information Lookup
 * Verifies a cadet last name + graduation year + email already on file
 * (either parent) before returning that family's current record, so the
 * Update form can be pre-filled. Never creates or modifies anything.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/admin/form-guard.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: https://alabamafalcons.org');
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

// Honeypot — bots fill this hidden field, real visitors never see it.
// Pretend "not found" either way so bots don't learn anything from the response.
if (honeypot_tripped($payload)) {
    echo json_encode(['success' => false, 'error' => "We couldn't find a matching record. Please double-check your information, or contact secretary@alabamafalcons.org."]);
    exit();
}

$pdo = get_pdo();

if (rate_limited($pdo, 'update_lookup')) {
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
     WHERE archived = 0 AND cadet_last_name = :last_name AND class_year = :class_year
       AND (parent1_email = :email OR parent2_email = :email)
     LIMIT 1'
);
$stmt->execute(['last_name' => $last, 'class_year' => $year, 'email' => $email]);
$m = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$m) {
    echo json_encode([
        'success' => false,
        'error'   => "We couldn't find a matching record. Please double-check the cadet's last name, graduation year, and the email on file, or contact secretary@alabamafalcons.org."
    ]);
    exit();
}

$g = fn(string $k) => (string)($m[$k] ?? '');

echo json_encode([
    'success' => true,
    'member'  => [
        'cadetFirstName'   => $g('cadet_first_name'),
        'cadetMiddleName'  => $g('cadet_middle_name'),
        'cadetLastName'    => $g('cadet_last_name'),
        'cadetSuffix'      => $g('cadet_suffix'),
        'graduationYear'   => $g('class_year'),
        'nickname'         => $g('nickname'),
        'poBox'            => $g('cadet_po_box'),
        'cadetEmail'       => $g('cadet_email'),
        'cadetPhone'       => $g('cadet_cell'),
        'squadron'         => $g('bct_squadron'),
        'photoConsent'     => $g('photo_consent'),
        'directoryConsent' => $g('directory_consent'),
        'parent1FirstName' => $g('parent1_first_name'),
        'parent1LastName'  => $g('parent1_last_name'),
        'parent1Phone'     => $g('parent1_cell'),
        'parent1Email'     => $g('parent1_email'),
        'parent2FirstName' => $g('parent2_first_name'),
        'parent2LastName'  => $g('parent2_last_name'),
        'parent2Phone'     => $g('parent2_cell'),
        'parent2Email'     => $g('parent2_email'),
        'streetAddress'    => $g('parent1_street'),
        'city'             => $g('parent1_city'),
        'state'            => $g('parent1_state'),
        'zipCode'          => $g('parent1_zip'),
        'parent2Street'    => $g('parent2_street'),
        'parent2City'      => $g('parent2_city'),
        'parent2State'     => $g('parent2_state'),
        'parent2Zip'       => $g('parent2_zip'),
    ],
]);

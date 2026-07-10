<?php
/**
 * Update-Your-Information Handler
 * Matches the submitted form to an existing member record and updates it.
 * Never creates a new record — if no match is found, the visitor is told
 * to contact the secretary. Does not touch membership_paid, membership_year,
 * membership_paid_through, or membership_type (dues status is untouched).
 */

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: https://alabamafalcons.org');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$input   = file_get_contents('php://input');
$payload = json_decode($input, true);

if (!$payload) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit();
}

require_once __DIR__ . '/admin/form-guard.php';

// Honeypot — bots fill this hidden field, real visitors never see it.
if (honeypot_tripped($payload)) {
    echo json_encode(['success' => true, 'message' => 'Your information has been updated.']);
    exit();
}

function sanitize_header($val) {
    return str_replace(["\r", "\n"], '', (string)$val);
}

function s(array $p, string $key): string {
    return trim($p[$key] ?? '');
}

require_once __DIR__ . '/admin/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES => true]
    );

    if (rate_limited($pdo, 'update_form')) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many submissions from your network. Please try again later or email secretary@alabamafalcons.org.']);
        exit();
    }

    $first  = s($payload, 'cadetFirstName');
    $middle = s($payload, 'cadetMiddleName');

    // Date of Birth isn't collected on this form — bind null so the
    // COALESCE-protected UPDATE below leaves the existing value untouched.
    $dob = null;

    // ── Find the existing record — never create a new one. Identity is
    // whatever was verified by update-lookup.php (last name + class year +
    // an email already on file for either parent), carried here in hidden
    // fields — NOT re-derived from the (possibly just-edited) visible form
    // fields, and NOT a guessable first-name fallback.
    $verified_last  = s($payload, 'verifiedLastName');
    $verified_year  = s($payload, 'verifiedYear');
    $verified_email = s($payload, 'verifiedEmail');

    if ($verified_last === '' || $verified_year === '' || $verified_email === '') {
        echo json_encode([
            'success' => false,
            'error'   => 'Please use "Find My Record" before submitting changes.'
        ]);
        exit();
    }

    $dup = $pdo->prepare(
        'SELECT id FROM members
         WHERE archived = 0 AND cadet_last_name = :last_name AND class_year = :class_year
           AND (parent1_email = :email OR parent2_email = :email)
         LIMIT 1'
    );
    $dup->execute([
        'last_name'  => $verified_last,
        'class_year' => $verified_year,
        'email'      => $verified_email,
    ]);
    $existing_id = $dup->fetchColumn();

    if (!$existing_id) {
        echo json_encode([
            'success' => false,
            'error'   => "We couldn't find a matching record. Please email secretary@alabamafalcons.org so we can update your information manually."
        ]);
        exit();
    }

    // A blank submitted value means "didn't change this field" — COALESCE(NULLIF(...))
    // keeps the existing column value instead of overwriting it with an empty string.
    // cadet_birthday is the exception: it's already normalized to PHP null when blank,
    // so a plain COALESCE against the bound (possibly-null) value works directly.
    $upd = $pdo->prepare("
        UPDATE members SET
            cadet_first_name  = COALESCE(NULLIF(:cadet_first_name, ''), cadet_first_name),
            cadet_middle_name = COALESCE(NULLIF(:cadet_middle_name, ''), cadet_middle_name),
            nickname          = COALESCE(NULLIF(:nickname, ''), nickname),
            cadet_birthday    = COALESCE(:cadet_birthday, cadet_birthday),
            cadet_po_box      = COALESCE(NULLIF(:cadet_po_box, ''), cadet_po_box),
            cadet_email       = COALESCE(NULLIF(:cadet_email, ''), cadet_email),
            cadet_cell        = COALESCE(NULLIF(:cadet_cell, ''), cadet_cell),
            bct_squadron      = COALESCE(NULLIF(:bct_squadron, ''), bct_squadron),
            parent1_last_name  = COALESCE(NULLIF(:parent1_last_name, ''), parent1_last_name),
            parent1_first_name = COALESCE(NULLIF(:parent1_first_name, ''), parent1_first_name),
            parent1_email      = COALESCE(NULLIF(:parent1_email, ''), parent1_email),
            parent1_cell       = COALESCE(NULLIF(:parent1_cell, ''), parent1_cell),
            parent1_street     = COALESCE(NULLIF(:parent1_street, ''), parent1_street),
            parent1_city       = COALESCE(NULLIF(:parent1_city, ''), parent1_city),
            parent1_state      = COALESCE(NULLIF(:parent1_state, ''), parent1_state),
            parent1_zip        = COALESCE(NULLIF(:parent1_zip, ''), parent1_zip),
            parent2_last_name  = COALESCE(NULLIF(:parent2_last_name, ''), parent2_last_name),
            parent2_first_name = COALESCE(NULLIF(:parent2_first_name, ''), parent2_first_name),
            parent2_email      = COALESCE(NULLIF(:parent2_email, ''), parent2_email),
            parent2_cell       = COALESCE(NULLIF(:parent2_cell, ''), parent2_cell),
            parent2_street     = COALESCE(NULLIF(:parent2_street, ''), parent2_street),
            parent2_city       = COALESCE(NULLIF(:parent2_city, ''), parent2_city),
            parent2_state      = COALESCE(NULLIF(:parent2_state, ''), parent2_state),
            parent2_zip        = COALESCE(NULLIF(:parent2_zip, ''), parent2_zip),
            photo_consent      = COALESCE(NULLIF(:photo_consent, ''), photo_consent),
            directory_consent  = COALESCE(NULLIF(:directory_consent, ''), directory_consent)
        WHERE id = :id
    ");
    $upd->execute([
        'cadet_first_name'   => $first,
        'cadet_middle_name'  => $middle,
        'nickname'           => s($payload, 'nickname'),
        'cadet_birthday'     => $dob,
        'cadet_po_box'       => s($payload, 'poBox'),
        'cadet_email'        => s($payload, 'cadetEmail'),
        'cadet_cell'         => s($payload, 'cadetPhone'),
        'bct_squadron'       => s($payload, 'squadron'),
        'parent1_last_name'  => s($payload, 'parent1LastName'),
        'parent1_first_name' => s($payload, 'parent1FirstName'),
        'parent1_email'      => s($payload, 'parent1Email'),
        'parent1_cell'       => s($payload, 'parent1Phone'),
        'parent1_street'     => s($payload, 'streetAddress'),
        'parent1_city'       => s($payload, 'city'),
        'parent1_state'      => s($payload, 'state'),
        'parent1_zip'        => s($payload, 'zipCode'),
        'parent2_last_name'  => s($payload, 'parent2LastName'),
        'parent2_first_name' => s($payload, 'parent2FirstName'),
        'parent2_email'      => s($payload, 'parent2Email'),
        'parent2_cell'       => s($payload, 'parent2Phone'),
        'parent2_street'     => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'streetAddress') : s($payload,'parent2Street'),
        'parent2_city'       => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'city')          : s($payload,'parent2City'),
        'parent2_state'      => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'state')         : s($payload,'parent2State'),
        'parent2_zip'        => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'zipCode')       : s($payload,'parent2Zip'),
        'photo_consent'      => s($payload, 'photoConsent'),
        'directory_consent'  => s($payload, 'directoryConsent'),
        'id'                 => $existing_id,
    ]);

    // Re-fetch the record as it now actually stands — since blank submitted
    // fields were preserved rather than overwritten, the emails below need
    // the real saved values, not just whatever was (or wasn't) typed this time.
    $fresh = $pdo->prepare('SELECT * FROM members WHERE id = ?');
    $fresh->execute([$existing_id]);
    $member = $fresh->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Update handler: MySQL error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error. Please email secretary@alabamafalcons.org directly.']);
    exit();
}

$g = fn(string $k) => (string)($member[$k] ?? '');

// ── Notify secretary of the update ────────────────────────────────────────
$secretary_email = 'secretary@alabamafalcons.org';
$subject = 'Member Info Updated: ' . sanitize_header($g('cadet_first_name')) . ' ' . sanitize_header($g('cadet_last_name'));

$email_body  = "A member updated their information via the Update Your Information form.\n";
$email_body .= "Fields left blank on the form kept their existing value — this reflects the record as it now stands:\n\n";
$email_body .= "CADET INFORMATION\n";
$email_body .= "Name: " . trim($g('cadet_first_name') . ' ' . $g('cadet_middle_name') . ' ' . $g('cadet_last_name')) . "\n";
$email_body .= "Nickname: " . $g('nickname') . "\n";
$email_body .= "Email: " . $g('cadet_email') . "\n";
$email_body .= "Phone: " . $g('cadet_cell') . "\n";
$email_body .= "Graduation Year: " . $g('class_year') . "\n";
$email_body .= "Squadron: " . $g('bct_squadron') . "\n";
$email_body .= "USAFA Mailbox: " . $g('cadet_po_box') . "\n\n";
$email_body .= "PARENT/FAMILY INFORMATION\n";
$email_body .= "Primary: " . trim($g('parent1_first_name') . ' ' . $g('parent1_last_name')) . "\n";
$email_body .= "Email: " . $g('parent1_email') . "\n";
$email_body .= "Phone: " . $g('parent1_cell') . "\n";
if ($g('parent2_first_name') !== '') {
    $email_body .= "\nSecondary: " . trim($g('parent2_first_name') . ' ' . $g('parent2_last_name')) . "\n";
    $email_body .= "Email: " . $g('parent2_email') . "\n";
    $email_body .= "Phone: " . $g('parent2_cell') . "\n";
}
$email_body .= "\nADDRESS\n";
$email_body .= $g('parent1_street') . "\n";
$email_body .= $g('parent1_city') . ", " . $g('parent1_state') . " " . $g('parent1_zip') . "\n\n";
$email_body .= "CONSENTS\n";
$email_body .= "Photo: " . $g('photo_consent') . "\n";
$email_body .= "Directory: " . $g('directory_consent') . "\n";

$headers  = "From: noreply@alabamafalcons.org\r\n";
$headers .= "Reply-To: " . sanitize_header($g('parent1_email')) . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
mail($secretary_email, $subject, $email_body, $headers);

// ── Confirmation email to parent ──────────────────────────────────────────
$parent_email = $g('parent1_email');
if (filter_var($parent_email, FILTER_VALIDATE_EMAIL)) {
    $parent_name  = $g('parent1_first_name') ?: 'there';
    $cadet_name   = trim($g('cadet_first_name') . ' ' . $g('cadet_middle_name') . ' ' . $g('cadet_last_name')) ?: 'your cadet';
    $conf_subject = 'Your Information Has Been Updated — USAFA Parents Club of Alabama';
    $conf_body    = "Dear $parent_name,\n\n"
                  . "Your family's information for $cadet_name has been updated in our records.\n\n"
                  . "If any of this wasn't intentional, or you have questions, please contact us at secretary@alabamafalcons.org.\n\n"
                  . "Aim High · Fly · Fight · Win\n"
                  . "USAFA Parents Club of Alabama\n"
                  . "alabamafalcons.org";
    $conf_headers = "From: USAFA Parents Club of Alabama <secretary@alabamafalcons.org>\r\n"
                  . "Reply-To: secretary@alabamafalcons.org\r\n"
                  . "Content-Type: text/plain; charset=UTF-8\r\n";
    mail($parent_email, $conf_subject, $conf_body, $conf_headers);
}

http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Thank you! Your information has been updated.'
]);

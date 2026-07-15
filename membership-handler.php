<?php
/**
 * Membership Form Handler
 * Primary: writes to MySQL database
 * Backup:  forwards to Google Apps Script (Google Sheets)
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
// Pretend success so bots don't learn to avoid the field.
if (honeypot_tripped($payload)) {
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Application received! Thank you for joining the Alabama Falcons family.']);
    exit();
}

function sanitize_header($val) {
    return str_replace(["\r", "\n"], '', (string)$val);
}

function s(array $p, string $key): string {
    return trim($p[$key] ?? '');
}

// Required fields mirror the `required` attributes on membership.html —
// keep both in sync if the form changes.
$required_fields = [
    'cadetFirstName', 'cadetLastName', 'cadetEmail', 'graduationYear',
    'parent1FirstName', 'parent1LastName', 'parent1Phone', 'parent1Email',
    'streetAddress', 'city', 'state', 'zipCode',
];
foreach ($required_fields as $field) {
    if (s($payload, $field) === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
        exit();
    }
}
if (!filter_var(s($payload, 'cadetEmail'), FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid cadet email address.']);
    exit();
}
// Graduation year is a <select> of known values on membership.html — reject
// anything else rather than writing a tampered/arbitrary class_year
// (e.g. 'Graduate', which would wrongly exclude a new applicant from
// dues-renewal emails and current-class filters).
if (!in_array(s($payload, 'graduationYear'), ['2026', '2027', '2028', '2029', '2030', 'Prep School'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid graduation year.']);
    exit();
}
if (!filter_var(s($payload, 'parent1Email'), FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid primary contact email address.']);
    exit();
}

// ── 1. Write to MySQL (primary) ────────────────────────────────────────────
require_once __DIR__ . '/admin/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES => true]
    );

    if (rate_limited($pdo, 'membership_form')) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many submissions from your network. Please try again later or email us directly at secretary@alabamafalcons.org.']);
        exit();
    }

    // Map form field names → DB columns
    $first  = s($payload, 'cadetFirstName');
    $middle = s($payload, 'cadetMiddleName');

    $dob = s($payload, 'cadetDOB');
    if ($dob === '') $dob = null;

    // ── Duplicate detection: same last name + class year, AND a matching
    // parent email (either submitted parent against either stored parent
    // column, so a resubmission that lists parents in the opposite order
    // still matches). Matching on name+class-year alone — without an email
    // check — would let two unrelated families who happen to share a last
    // name, class year, and cadet first name silently overwrite each other.
    //
    // Last-name comparison is done in PHP against a normalized form (strip
    // punctuation, collapse whitespace, lowercase) rather than a strict SQL
    // `=` — a name typed as "Jimmerson, Jr" vs "Jimmerson, Jr." on separate
    // submissions is the same family, but a literal `=` treats them as two
    // different rows and silently inserts a duplicate instead of updating.
    function normalize_name(string $s): string {
        $s = preg_replace('/[.,]/', '', $s);
        return strtolower(trim(preg_replace('/\s+/', ' ', $s)));
    }
    $parent1_email = s($payload, 'parent1Email');
    $parent2_email = s($payload, 'parent2Email');
    $cand = $pdo->prepare(
        'SELECT id, cadet_last_name FROM members
         WHERE class_year = :class_year
           AND (
                (:parent1_email <> "" AND (parent1_email = :parent1_email OR parent2_email = :parent1_email))
             OR (:parent2_email <> "" AND (parent1_email = :parent2_email OR parent2_email = :parent2_email))
           )'
    );
    $cand->execute([
        'class_year'    => s($payload, 'graduationYear'),
        'parent1_email' => $parent1_email,
        'parent2_email' => $parent2_email,
    ]);
    $target_norm = normalize_name(s($payload, 'cadetLastName'));
    $existing_id = null;
    foreach ($cand->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (normalize_name($row['cadet_last_name']) === $target_norm) { $existing_id = $row['id']; break; }
    }

    if ($existing_id) {
        // Returning member — update their record instead of inserting
        $upd = $pdo->prepare("
            UPDATE members SET
                cadet_first_name=:cadet_first_name, cadet_middle_name=:cadet_middle_name, nickname=:nickname,
                cadet_birthday=:cadet_birthday, cadet_po_box=:cadet_po_box,
                cadet_email=:cadet_email, cadet_cell=:cadet_cell,
                bct_squadron=:bct_squadron,
                parent1_last_name=:parent1_last_name, parent1_first_name=:parent1_first_name,
                parent1_email=:parent1_email, parent1_cell=:parent1_cell,
                parent1_street=:parent1_street, parent1_city=:parent1_city,
                parent1_state=:parent1_state, parent1_zip=:parent1_zip,
                parent2_last_name=:parent2_last_name, parent2_first_name=:parent2_first_name,
                parent2_email=:parent2_email, parent2_cell=:parent2_cell,
                parent2_street=:parent2_street, parent2_city=:parent2_city,
                parent2_state=:parent2_state, parent2_zip=:parent2_zip,
                photo_consent=:photo_consent, directory_consent=:directory_consent
            WHERE id = :id
        ");
        $upd->execute([
            'cadet_first_name'   => $first,
            'cadet_middle_name'  => $middle,
            'nickname'           => s($payload,'nickname'),
            'cadet_birthday'     => $dob,
            'cadet_po_box'       => s($payload,'poBox'),
            'cadet_email'        => s($payload,'cadetEmail'),
            'cadet_cell'         => s($payload,'cadetPhone'),
            'bct_squadron'       => s($payload,'squadron'),
            'parent1_last_name'  => s($payload,'parent1LastName'),
            'parent1_first_name' => s($payload,'parent1FirstName'),
            'parent1_email'      => s($payload,'parent1Email'),
            'parent1_cell'       => s($payload,'parent1Phone'),
            'parent1_street'     => s($payload,'streetAddress'),
            'parent1_city'       => s($payload,'city'),
            'parent1_state'      => s($payload,'state'),
            'parent1_zip'        => s($payload,'zipCode'),
            'parent2_last_name'  => s($payload,'parent2LastName'),
            'parent2_first_name' => s($payload,'parent2FirstName'),
            'parent2_email'      => s($payload,'parent2Email'),
            'parent2_cell'       => s($payload,'parent2Phone'),
            'parent2_street'     => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'streetAddress') : s($payload,'parent2Street'),
            'parent2_city'       => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'city')          : s($payload,'parent2City'),
            'parent2_state'      => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'state')         : s($payload,'parent2State'),
            'parent2_zip'        => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'zipCode')       : s($payload,'parent2Zip'),
            'photo_consent'      => s($payload,'photoConsent'),
            'directory_consent'  => s($payload,'directoryConsent'),
            'id'                 => $existing_id,
        ]);
        $db_success = true;
    } else {
    $stmt = $pdo->prepare("
        INSERT INTO members (
            class_year, cadet_last_name, cadet_first_name, cadet_middle_name, nickname,
            cadet_birthday, cadet_po_box, cadet_email, cadet_cell,
            bct_squadron,
            parent1_last_name, parent1_first_name, parent1_email, parent1_cell,
            parent1_street, parent1_city, parent1_state, parent1_zip,
            parent2_last_name, parent2_first_name, parent2_email, parent2_cell,
            parent2_street, parent2_city, parent2_state, parent2_zip,
            photo_consent, directory_consent,
            membership_paid, membership_year
        ) VALUES (
            :class_year, :cadet_last_name, :cadet_first_name, :cadet_middle_name, :nickname,
            :cadet_birthday, :cadet_po_box, :cadet_email, :cadet_cell,
            :bct_squadron,
            :parent1_last_name, :parent1_first_name, :parent1_email, :parent1_cell,
            :parent1_street, :parent1_city, :parent1_state, :parent1_zip,
            :parent2_last_name, :parent2_first_name, :parent2_email, :parent2_cell,
            :parent2_street, :parent2_city, :parent2_state, :parent2_zip,
            :photo_consent, :directory_consent,
            0, ''
        )
    ");

    $stmt->execute([
        'class_year'          => s($payload, 'graduationYear'),
        'cadet_last_name'     => s($payload, 'cadetLastName'),
        'cadet_first_name'    => $first,
        'cadet_middle_name'   => $middle,
        'nickname'            => s($payload, 'nickname'),
        'cadet_birthday'      => $dob,
        'cadet_po_box'        => s($payload, 'poBox'),
        'cadet_email'         => s($payload, 'cadetEmail'),
        'cadet_cell'          => s($payload, 'cadetPhone'),
        'bct_squadron'        => s($payload, 'squadron'),
        'parent1_last_name'   => s($payload, 'parent1LastName'),
        'parent1_first_name'  => s($payload, 'parent1FirstName'),
        'parent1_email'       => s($payload, 'parent1Email'),
        'parent1_cell'        => s($payload, 'parent1Phone'),
        'parent1_street'      => s($payload, 'streetAddress'),
        'parent1_city'        => s($payload, 'city'),
        'parent1_state'       => s($payload, 'state'),
        'parent1_zip'         => s($payload, 'zipCode'),
        'parent2_last_name'   => s($payload, 'parent2LastName'),
        'parent2_first_name'  => s($payload, 'parent2FirstName'),
        'parent2_email'       => s($payload, 'parent2Email'),
        'parent2_cell'        => s($payload, 'parent2Phone'),
        'parent2_street'      => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'streetAddress') : s($payload,'parent2Street'),
        'parent2_city'        => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'city')          : s($payload,'parent2City'),
        'parent2_state'       => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'state')         : s($payload,'parent2State'),
        'parent2_zip'         => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'zipCode')       : s($payload,'parent2Zip'),
        'photo_consent'       => s($payload, 'photoConsent'),
        'directory_consent'   => s($payload, 'directoryConsent'),
    ]);

    $db_success = true;
    } // end else (new member insert)

} catch (PDOException $e) {
    $db_success = false;
    error_log('Membership handler: MySQL insert failed: ' . $e->getMessage());
}

if (!$db_success) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Database error. Please email secretary@alabamafalcons.org directly.'
    ]);
    exit();
}

// ── 2. Send secretary notification email ──────────────────────────────────
$secretary_email = 'secretary@alabamafalcons.org';
$subject = 'New Membership Application: '
         . sanitize_header(s($payload, 'cadetFirstName')) . ' '
         . sanitize_header(s($payload, 'cadetLastName'));

$email_body  = "New membership application received:\n\n";
$email_body .= "CADET INFORMATION\n";
$email_body .= "Name: " . s($payload,'cadetFirstName') . " " . s($payload,'cadetMiddleName') . " " . s($payload,'cadetLastName') . "\n";
$email_body .= "Nickname: " . s($payload,'nickname') . "\n";
$email_body .= "Email: " . s($payload,'cadetEmail') . "\n";
$email_body .= "Phone: " . s($payload,'cadetPhone') . "\n";
$email_body .= "Graduation Year: " . s($payload,'graduationYear') . "\n";
$email_body .= "Squadron: " . s($payload,'squadron') . "\n\n";
$email_body .= "PARENT/FAMILY INFORMATION\n";
$email_body .= "Primary: " . s($payload,'parent1FirstName') . " " . s($payload,'parent1LastName') . "\n";
$email_body .= "Email: " . s($payload,'parent1Email') . "\n";
$email_body .= "Phone: " . s($payload,'parent1Phone') . "\n";
if (s($payload,'parent2FirstName') !== '') {
    $email_body .= "\nSecondary: " . s($payload,'parent2FirstName') . " " . s($payload,'parent2LastName') . "\n";
    $email_body .= "Email: " . s($payload,'parent2Email') . "\n";
    $email_body .= "Phone: " . s($payload,'parent2Phone') . "\n";
}
$email_body .= "\nADDRESS\n";
$email_body .= s($payload,'streetAddress') . "\n";
$email_body .= s($payload,'city') . ", " . s($payload,'state') . " " . s($payload,'zipCode') . "\n\n";
$email_body .= "CONSENTS\n";
$email_body .= "Photo: " . s($payload,'photoConsent') . "\n";
$email_body .= "Directory: " . s($payload,'directoryConsent') . "\n";

$headers  = "From: noreply@alabamafalcons.org\r\n";
$headers .= "Reply-To: " . sanitize_header(s($payload,'parent1Email')) . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
mail($secretary_email, $subject, $email_body, $headers);

// ── 3. Confirmation email to parent ──────────────────────────────────────
$parent_email = s($payload, 'parent1Email');
if (filter_var($parent_email, FILTER_VALIDATE_EMAIL)) {
    $parent_name  = s($payload, 'parent1FirstName');
    $cadet_name   = trim(preg_replace('/\s+/', ' ', "$first $middle " . s($payload, 'cadetLastName')));
    $conf_subject = 'Membership Application Received — USAFA Parents Club of Alabama';
    $conf_body    = "Dear $parent_name,\n\n"
                  . "We have received your membership application for $cadet_name (Class of " . s($payload,'graduationYear') . ").\n\n"
                  . "Your information has been recorded. You will be redirected to our payment page to complete your membership.\n\n"
                  . "If you have any questions, please contact us at info@alabamafalcons.org.\n\n"
                  . "Aim High · Fly · Fight · Win\n"
                  . "USAFA Parents Club of Alabama\n"
                  . "alabamafalcons.org";
    $conf_headers = "From: USAFA Parents Club of Alabama <info@alabamafalcons.org>\r\n"
                  . "Reply-To: info@alabamafalcons.org\r\n"
                  . "Content-Type: text/plain; charset=UTF-8\r\n";
    mail($parent_email, $conf_subject, $conf_body, $conf_headers);
}

// ── 5. Forward to Google Sheets as backup (fire-and-forget) ───────────────
$apps_script_url = 'https://script.google.com/macros/s/AKfycbzFG0SKrECB1toC6rMckgNQxsUuc_QsCvPr1TMfWztUNM_3plG3J9XnxhTvGdq2faAc/exec';
$json_data = json_encode($payload);
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apps_script_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Content-Length: ' . strlen($json_data)]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
$sheets_response = curl_exec($ch);
$sheets_error    = curl_error($ch);
curl_close($ch);
if ($sheets_error) {
    error_log("Membership handler: Google Sheets backup failed: $sheets_error");
}

// ── 6. Return success (DB write already succeeded) ────────────────────────
http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Application received! Thank you for joining the Alabama Falcons family. Redirecting to payment page...'
]);

<?php
/**
 * Membership Form Handler
 * Primary: writes to MySQL database
 * Backup:  forwards to Google Apps Script (Google Sheets)
 */

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
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

function sanitize_header($val) {
    return str_replace(["\r", "\n"], '', (string)$val);
}

function s(array $p, string $key): string {
    return trim($p[$key] ?? '');
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

    // Map form field names → DB columns
    $first  = s($payload, 'cadetFirstName');
    $middle = s($payload, 'cadetMiddleName');
    $first_middle = trim("$first $middle");

    $dob = s($payload, 'cadetDOB');
    if ($dob === '') $dob = null;

    $stmt = $pdo->prepare("
        INSERT INTO members (
            class_year, cadet_last_name, cadet_first_middle, nickname,
            cadet_birthday, cadet_po_box, cadet_email, cadet_cell,
            bct_squadron,
            parent1_last_name, parent1_first_name, parent1_email, parent1_cell,
            parent1_street, parent1_city, parent1_state, parent1_zip,
            parent2_last_name, parent2_first_name, parent2_email, parent2_cell,
            photo_consent, directory_consent,
            membership_paid, membership_year
        ) VALUES (
            :class_year, :cadet_last_name, :cadet_first_middle, :nickname,
            :cadet_birthday, :cadet_po_box, :cadet_email, :cadet_cell,
            :bct_squadron,
            :parent1_last_name, :parent1_first_name, :parent1_email, :parent1_cell,
            :parent1_street, :parent1_city, :parent1_state, :parent1_zip,
            :parent2_last_name, :parent2_first_name, :parent2_email, :parent2_cell,
            :photo_consent, :directory_consent,
            0, ''
        )
    ");

    $stmt->execute([
        'class_year'          => s($payload, 'graduationYear'),
        'cadet_last_name'     => s($payload, 'cadetLastName'),
        'cadet_first_middle'  => $first_middle,
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
        'photo_consent'       => s($payload, 'photoConsent'),
        'directory_consent'   => s($payload, 'directoryConsent'),
    ]);

    $db_success = true;

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

// ── 3. Forward to Google Sheets as backup (fire-and-forget) ───────────────
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
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$sheets_response = curl_exec($ch);
$sheets_error    = curl_error($ch);
curl_close($ch);
if ($sheets_error) {
    error_log("Membership handler: Google Sheets backup failed: $sheets_error");
}

// ── 4. Return success (DB write already succeeded) ────────────────────────
http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Application received! Thank you for joining the Alabama Falcons family. Redirecting to payment page...'
]);

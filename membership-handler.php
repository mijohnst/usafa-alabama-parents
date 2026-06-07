<?php
/**
 * Membership Form Handler
 * Receives form data and forwards it to Google Apps Script
 */

// Set JSON response header
header('Content-Type: application/json');

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// Get the JSON payload from the request body
$input = file_get_contents('php://input');
$payload = json_decode($input, true);

// Validate that we received data
if (!$payload) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit();
}

// Strip CR/LF from a value before using it in a mail header
function sanitize_header($val) {
    return str_replace(["\r", "\n"], '', (string)$val);
}

// Google Apps Script deployment URL
$apps_script_url = 'https://script.google.com/macros/s/AKfycbzFG0SKrECB1toC6rMckgNQxsUuc_QsCvPr1TMfWztUNM_3plG3J9XnxhTvGdq2faAc/exec';

// Convert payload to JSON
$json_data = json_encode($payload);

// Initialize cURL request
$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, $apps_script_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($json_data)
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

// Execute the request
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);

curl_close($ch);

// Send email notification to secretary
$secretary_email = 'secretary@alabamafalcons.org';
$subject = 'New Membership Application: ' . sanitize_header($payload['cadetFirstName'] ?? 'N/A') . ' ' . sanitize_header($payload['cadetLastName'] ?? 'N/A');

// Format email body
$email_body = "New membership application received:\n\n";
$email_body .= "CADET INFORMATION\n";
$email_body .= "Name: " . ($payload['cadetFirstName'] ?? '') . " " . ($payload['cadetMiddleName'] ?? '') . " " . ($payload['cadetLastName'] ?? '') . "\n";
$email_body .= "Email: " . ($payload['cadetEmail'] ?? '') . "\n";
$email_body .= "Graduation Year: " . ($payload['graduationYear'] ?? '') . "\n";
$email_body .= "Squadron: " . ($payload['squadron'] ?? '') . "\n\n";

$email_body .= "PARENT/FAMILY INFORMATION\n";
$email_body .= "Primary Contact: " . ($payload['parent1FirstName'] ?? '') . " " . ($payload['parent1LastName'] ?? '') . "\n";
$email_body .= "Email: " . ($payload['parent1Email'] ?? '') . "\n";
$email_body .= "Phone: " . ($payload['parent1Phone'] ?? '') . "\n";

if (!empty($payload['parent2FirstName'])) {
    $email_body .= "\nSecondary Contact: " . ($payload['parent2FirstName'] ?? '') . " " . ($payload['parent2LastName'] ?? '') . "\n";
    $email_body .= "Email: " . ($payload['parent2Email'] ?? '') . "\n";
    $email_body .= "Phone: " . ($payload['parent2Phone'] ?? '') . "\n";
}

$email_body .= "\nADDRESS\n";
$email_body .= ($payload['streetAddress'] ?? '') . "\n";
$email_body .= ($payload['city'] ?? '') . ", " . ($payload['state'] ?? '') . " " . ($payload['zipCode'] ?? '') . "\n\n";

$email_body .= "CONSENTS\n";
$email_body .= "Photo Consent: " . ($payload['photoConsent'] ?? 'Not specified') . "\n";
$email_body .= "Directory Consent: " . ($payload['directoryConsent'] ?? 'Not specified') . "\n";

// Set headers for email
$headers = "From: noreply@alabamafalcons.org\r\n";
$headers .= "Reply-To: " . sanitize_header($payload['parent1Email'] ?? '') . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

// Send the email
mail($secretary_email, $subject, $email_body, $headers);

// Handle the response from Apps Script
if ($curl_error) {
    error_log("Membership handler: Google Sheets submission failed (cURL error): $curl_error");
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'There was a problem recording your application. Please email secretary@alabamafalcons.org directly.'
    ]);
    exit();
}

// Parse the response from Apps Script
if ($response) {
    $result = json_decode($response, true);
    if ($result && $result['success']) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => $result['message']
        ]);
    } else {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Application received! Thank you for joining the Alabama Falcons family. Redirecting to payment page...'
        ]);
    }
} else {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Application received! Thank you for joining the Alabama Falcons family. Redirecting to payment page...'
    ]);
}
?>

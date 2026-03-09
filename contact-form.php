<?php
/**
 * Contact Form Handler for USAFA Alabama Parents Club
 * Sends emails via PHP mail() function
 */

// Set headers
header('Content-Type: application/json');

// Configuration
$to_email = 'info@alabamafalcons.org'; // Where form submissions go
$subject_prefix = '[USAFA AL Website]'; // Email subject prefix

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get POST data
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// Fallback: also accept regular POST fields (form submission)
if (empty($data)) {
    $data = $_POST;
}

// Validate required fields
$required_fields = ['name', 'email', 'message'];
foreach ($required_fields as $field) {
    if (empty($data[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit;
    }
}

// Sanitize inputs
$name = htmlspecialchars(strip_tags(trim($data['name'])));
$email = filter_var(trim($data['email']), FILTER_SANITIZE_EMAIL);
$iam = isset($data['iam']) ? htmlspecialchars(strip_tags(trim($data['iam']))) : 'Not specified';
$class_year = isset($data['classYear']) ? htmlspecialchars(strip_tags(trim($data['classYear']))) : 'N/A';
$message = htmlspecialchars(strip_tags(trim($data['message'])));

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit;
}

// Build email subject
$subject = "$subject_prefix Contact Form Submission from $name";

// Build email body
$email_body = "New contact form submission from alabamafalcons.org\n\n";
$email_body .= "Name: $name\n";
$email_body .= "Email: $email\n";
$email_body .= "I am a: $iam\n";
$email_body .= "Cadet's Class Year: $class_year\n\n";
$email_body .= "Message:\n";
$email_body .= wordwrap($message, 70) . "\n\n";
$email_body .= "---\n";
$email_body .= "Sent from: alabamafalcons.org contact form\n";
$email_body .= "Submitted: " . date('Y-m-d H:i:s T') . "\n";
$email_body .= "IP Address: " . $_SERVER['REMOTE_ADDR'] . "\n";

// Email headers
$headers = "From: noreply@alabamafalcons.org\r\n";
$headers .= "Reply-To: $email\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

// Send email
$mail_sent = mail($to_email, $subject, $email_body, $headers);

if ($mail_sent) {
    echo json_encode([
        'success' => true,
        'message' => 'Thank you! Your message has been sent. We\'ll get back to you within 24-48 hours.'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Sorry, there was an error sending your message. Please email us directly at info@alabamafalcons.org'
    ]);
}
?>

<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200); exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit();
}

$input = file_get_contents('php://input');
$data  = json_decode($input, true);
if (!$data) { http_response_code(400); echo json_encode(['error'=>'Invalid input']); exit(); }

function s(array $d, string $k): string { return trim($d[$k] ?? ''); }
function sanitize_hdr(string $v): string { return str_replace(["\r","\n"], '', $v); }

$name         = s($data, 'name');
$email        = s($data, 'email');
$phone        = s($data, 'phone');
$areas_raw    = $data['areas'] ?? null;
$areas        = is_array($areas_raw)
    ? implode(', ', array_filter(array_map(fn($v) => is_string($v) ? trim($v) : '', $areas_raw)))
    : s($data, 'areas');
$availability = s($data, 'availability');
$cadet_info   = s($data, 'cadetInfo');
$comments     = s($data, 'comments');

if (!$name || !$email) { http_response_code(400); echo json_encode(['error'=>'Name and email are required.']); exit(); }
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(['error'=>'Invalid email address.']); exit(); }

// Save to DB
require_once __DIR__ . '/admin/config.php';
try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>true]);
    $pdo->prepare('INSERT INTO volunteers (name,email,phone,areas,availability,cadet_info,comments) VALUES (?,?,?,?,?,?,?)')
        ->execute([$name,$email,$phone,$areas,$availability,$cadet_info,$comments]);
} catch (Exception $e) { error_log('volunteer-handler DB error: '.$e->getMessage()); }

// Email notification
$subject  = sanitize_hdr('New Volunteer Interest: ' . $name);
$body     = "USAFA Parents Club of Alabama\nNew Volunteer Interest Submission\n" . str_repeat('─',48) . "\n\n"
           . "Name:         $name\n"
           . "Email:        $email\n"
           . ($phone        ? "Phone:        $phone\n"        : '')
           . ($areas        ? "Areas:        $areas\n"        : '')
           . ($availability ? "Availability: $availability\n" : '')
           . ($cadet_info   ? "Cadet Info:   $cadet_info\n"  : '')
           . ($comments     ? "\nComments:\n$comments\n"      : '')
           . "\n" . str_repeat('─',48) . "\nalabamafalcons.org/admin/";

$headers  = "From: USAFA Parents Club <info@alabamafalcons.org>\r\n";
$headers .= "Reply-To: " . sanitize_hdr($email) . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

mail('secretary@alabamafalcons.org', $subject, $body, $headers);
mail('president@alabamafalcons.org', $subject, $body, $headers);

// Confirmation to volunteer
$conf = "Thank you for your interest in volunteering with the USAFA Parents Club of Alabama!\n\n"
      . "We've received your information and a club officer will be in touch soon.\n\n"
      . "Your submission:\n"
      . "  Areas of interest: " . ($areas ?: 'Not specified') . "\n"
      . "  Availability: " . ($availability ?: 'Not specified') . "\n\n"
      . "Aim High · Fly · Fight · Win\n"
      . "USAFA Parents Club of Alabama\nalabamafalcons.org";
mail($email, 'Volunteer Interest Received — USAFA Parents Club of Alabama', $conf, "From: USAFA Parents Club <info@alabamafalcons.org>\r\nContent-Type: text/plain; charset=UTF-8\r\n");

http_response_code(200);
echo json_encode(['success'=>true,'message'=>'Thank you! We\'ll be in touch soon.']);

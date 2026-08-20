<?php
/**
 * Job Drop Night — Submit
 * Accepts a job title + photo for the member_id bound to a job-drop-lookup.php
 * verifyToken. Never trusts a member_id from the request itself. Stored as
 * 'pending' in job_drop_submissions — admin/job-drop-submissions.php (open
 * only to President/VP/Secretary) approves or rejects it from there.
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

if (honeypot_tripped($_POST)) {
    echo json_encode(['success' => true, 'message' => 'Thank you! Your submission is awaiting review.']);
    exit();
}

$pdo = get_pdo();

if (rate_limited($pdo, 'job_drop_submit')) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many submissions from your network. Please try again later or email secretary@alabamafalcons.org.']);
    exit();
}

start_verification_session();
$token    = trim((string)($_POST['verifyToken'] ?? ''));
$verified = $_SESSION['jobdrop_verified'][$token] ?? null;

if (!$verified || ($verified['expires'] ?? 0) < time()) {
    echo json_encode(['success' => false, 'error' => 'Your session expired. Please use "Find My Record" again.']);
    exit();
}
$member_id = (int)$verified['member_id'];

$job_title = trim((string)($_POST['jobTitle'] ?? ''));
if ($job_title === '') {
    echo json_encode(['success' => false, 'error' => 'Please enter the job/career field.']);
    exit();
}
if (mb_strlen($job_title) > 150) {
    echo json_encode(['success' => false, 'error' => 'That job title is too long.']);
    exit();
}

if (empty($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
    echo json_encode(['success' => false, 'error' => 'Please choose a photo to upload.']);
    exit();
}
if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Photo upload failed. Please try again.']);
    exit();
}

$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
$finfo   = finfo_open(FILEINFO_MIME_TYPE);
$mime    = finfo_file($finfo, $_FILES['photo']['tmp_name']);
finfo_close($finfo);

if (!isset($allowed[$mime])) {
    echo json_encode(['success' => false, 'error' => 'Please use a JPG, PNG, GIF, or WebP photo.']);
    exit();
}
if ($_FILES['photo']['size'] > 10 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'Photo must be under 10MB.']);
    exit();
}

$dir = __DIR__ . '/job-drop-submissions/';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dir . $filename)) {
    echo json_encode(['success' => false, 'error' => 'Could not save the uploaded photo. Please try again.']);
    exit();
}

$pdo->prepare('INSERT INTO job_drop_submissions (member_id, job_title, filename, status) VALUES (?, ?, ?, \'pending\')')
    ->execute([$member_id, $job_title, $filename]);

echo json_encode(['success' => true, 'message' => "Thank you! Your submission is awaiting review — once approved, it'll appear on the homepage."]);

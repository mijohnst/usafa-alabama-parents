<?php
// Public minutes viewer for board members without an admin login.
// Requires a valid per-meeting token — not guessable, not browsable.
require_once __DIR__ . '/admin/auth.php';
$pdo = get_pdo();

$id    = (int)($_GET['id'] ?? 0);
$token = $_GET['token'] ?? '';
if ($id < 1 || !preg_match('/^[a-f0-9]{48}$/', $token)) { http_response_code(404); echo 'Not found.'; exit; }

$stmt = $pdo->prepare('SELECT minutes_file, minutes_token, title FROM club_meetings WHERE id=?');
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row || !$row['minutes_file'] || !$row['minutes_token'] || !hash_equals($row['minutes_token'], $token)) {
    http_response_code(404); echo 'Not found.'; exit;
}

$fname = basename($row['minutes_file']);
$path  = __DIR__ . '/admin/minutes-files/' . $fname;
if (!is_file($path)) { http_response_code(404); echo 'File not found on server.'; exit; }

$ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
$mime_map = ['pdf' => 'application/pdf', 'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
$mime = $mime_map[$ext] ?? 'application/octet-stream';

$safe_title = preg_replace('/[^a-zA-Z0-9\-_ ]/', '', $row['title']);
$dl_name = (trim($safe_title) ?: 'minutes');
$dl_name = str_replace(' ', '-', $dl_name) . '.' . $ext;

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $dl_name . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
readfile($path);

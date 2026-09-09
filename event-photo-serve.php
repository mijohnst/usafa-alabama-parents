<?php
// Public photo server — only serves a photo if its album is visible,
// unless the request comes from a logged-in admin session (for previewing
// hidden albums in the admin panel).
require_once __DIR__ . '/admin/auth.php';
start_session();
// Was previously "any logged-in session" — that let a plain member account
// bypass the visible=1 check and view hidden/unpublished albums just by
// knowing a filename. Hidden-album preview should be limited to whoever can
// actually manage albums (event-albums.php's own guard), same as admin.
$is_admin_session = !empty($_SESSION['logged_in']) && can_manage_members();
$pdo = get_pdo();

$filename = basename($_GET['f'] ?? '');
if ($filename === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
    http_response_code(404); exit;
}

if ($is_admin_session) {
    $stmt = $pdo->prepare('SELECT filename FROM event_photos WHERE filename = ? LIMIT 1');
} else {
    $stmt = $pdo->prepare(
        'SELECT p.filename FROM event_photos p JOIN event_albums a ON a.id = p.album_id WHERE p.filename = ? AND a.visible = 1 LIMIT 1'
    );
}
$stmt->execute([$filename]);
if (!$stmt->fetch()) { http_response_code(404); exit; }

$dir  = realpath(__DIR__ . '/event-photos');
$file = $dir ? realpath($dir . '/' . $filename) : false;
if (!$file || !$dir || strpos($file, $dir . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
    http_response_code(404); exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file) ?: 'application/octet-stream';
finfo_close($finfo);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
    http_response_code(404); exit;
}

// Gallery grids request the small ?thumb=1 variant so a page with dozens of
// full-resolution originals doesn't have to download all of them just to
// render a grid of small squares — only the lightbox needs the real file.
// Thumbnails are generated once (on upload, see admin/event-photos.php) and
// cached to disk; a photo uploaded before this existed has no cached
// thumbnail yet, so it's generated here on first request instead of
// requiring a one-time migration pass over every existing album.
$want_thumb = isset($_GET['thumb']);
if ($want_thumb) {
    $thumb_dir  = $dir . DIRECTORY_SEPARATOR . 'thumbs';
    $thumb_file = $thumb_dir . DIRECTORY_SEPARATOR . pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
    if (!is_file($thumb_file)) {
        if (!is_dir($thumb_dir)) @mkdir($thumb_dir, 0755, true);
        generate_photo_thumbnail($file, $thumb_file);
    }
    if (is_file($thumb_file)) {
        $file = $thumb_file;
        $mime = 'image/jpeg';
    }
    // else: generation failed (e.g. GD unavailable) — falls through and
    // serves the full-size original instead of a broken image.
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($file));
header('Cache-Control: ' . ($is_admin_session ? 'private, no-store' : 'public, max-age=86400'));
readfile($file);

<?php
// Public photo server for Club Store products — only serves a photo if its
// product is active (is_active=1), unless the request comes from a
// logged-in board-member session that can manage the store (so the admin
// product-edit page can preview photos on a still-hidden/draft product).
// Mirrors event-photo-serve.php's pattern exactly.
require_once __DIR__ . '/admin/auth.php';
start_session();
$is_store_admin_session = !empty($_SESSION['logged_in']) && can_manage_store();
$pdo = get_pdo();

$filename = basename($_GET['f'] ?? '');
if ($filename === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
    http_response_code(404); exit;
}

if ($is_store_admin_session) {
    $stmt = $pdo->prepare('SELECT filename FROM store_product_photos WHERE filename = ? LIMIT 1');
} else {
    $stmt = $pdo->prepare(
        'SELECT p.filename FROM store_product_photos p JOIN store_products sp ON sp.id = p.product_id WHERE p.filename = ? AND sp.is_active = 1 LIMIT 1'
    );
}
$stmt->execute([$filename]);
if (!$stmt->fetch()) { http_response_code(404); exit; }

$dir  = realpath(__DIR__ . '/product-photos');
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

// Catalog grids request the small ?thumb=1 variant, same reasoning as
// event-photo-serve.php: don't ship dozens of full-resolution originals to
// render a grid of small squares. Thumbnails are generated on upload (see
// admin/store-products.php) and cached to disk; generated here on first
// request as a fallback if one is somehow missing.
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
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($file));
header('Cache-Control: ' . ($is_store_admin_session ? 'private, no-store' : 'public, max-age=86400'));
readfile($file);

<?php
// Public JSON API for the Club Events gallery page.
// No authentication required — only returns visible albums and their photos.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

// Allow cross-origin requests from same-site only (not needed for same-domain, but safe)
// header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/admin/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

$action = $_GET['action'] ?? 'albums';

if ($action === 'albums') {
    // Return all visible albums with photo count and cover photo filename
    $rows = $pdo->query("
        SELECT a.id, a.name, a.event_date, a.description, a.sort_order,
               COUNT(p.id) AS photo_count,
               cp.filename AS cover_filename
        FROM event_albums a
        LEFT JOIN event_photos  p  ON p.album_id = a.id
        LEFT JOIN event_photos  cp ON cp.id = a.cover_photo_id
        WHERE a.visible = 1
        GROUP BY a.id
        HAVING photo_count > 0
        ORDER BY a.sort_order ASC, a.id DESC
    ")->fetchAll();

    $albums = [];
    foreach ($rows as $r) {
        $albums[] = [
            'id'             => (int)$r['id'],
            'name'           => $r['name'],
            'event_date'     => $r['event_date'],
            'description'    => $r['description'],
            'photo_count'    => (int)$r['photo_count'],
            'cover_url'      => $r['cover_filename'] ? '/event-photos/' . rawurlencode($r['cover_filename']) : null,
        ];
    }
    echo json_encode(['albums' => $albums]);

} elseif ($action === 'photos') {
    $album_id = (int)($_GET['album_id'] ?? 0);
    if (!$album_id) { echo json_encode(['error' => 'Missing album_id']); exit; }

    // Confirm album is visible
    $chk = $pdo->prepare('SELECT id FROM event_albums WHERE id=? AND visible=1');
    $chk->execute([$album_id]);
    if (!$chk->fetch()) { echo json_encode(['photos' => []]); exit; }

    $rows = $pdo->prepare('SELECT id, filename, caption, sort_order FROM event_photos WHERE album_id=? ORDER BY sort_order ASC, id ASC');
    $rows->execute([$album_id]);

    $photos = [];
    foreach ($rows->fetchAll() as $p) {
        $photos[] = [
            'id'      => (int)$p['id'],
            'url'     => '/event-photos/' . rawurlencode($p['filename']),
            'caption' => $p['caption'],
        ];
    }
    echo json_encode(['photos' => $photos]);

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
}

<?php
header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');
require_once __DIR__ . '/admin/config.php';
try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>true, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    // Explicit column list, not SELECT * — `description` is admin-only
    // internal notes (see the "internal notes only" label on its field in
    // admin/sponsors.php) and must never reach this public feed, even
    // though nothing on the public pages currently renders it.
    $rows = $pdo->query(
        "SELECT id, name, level, location, contribution_type, contribution_amount, contribution_purpose,
                about_text, gratitude_quote, visit_link_text, website_url, logo_filename, sort_order
         FROM sponsors WHERE active=1 ORDER BY FIELD(level,'presenting','gold','silver','individual','other'), sort_order ASC, name ASC"
    )->fetchAll();
    echo json_encode(['success'=>true,'sponsors'=>$rows]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'sponsors'=>[]]);
    error_log('sponsors-feed: '.$e->getMessage());
}

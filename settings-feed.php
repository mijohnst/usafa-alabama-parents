<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
require_once __DIR__ . '/admin/config.php';
try {
    $pdo  = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>true, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $rows = $pdo->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll();
    $settings = [];
    foreach ($rows as $r) $settings[$r['setting_key']] = $r['setting_value'];
    echo json_encode(['success'=>true,'settings'=>$settings]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'settings'=>[]]);
    error_log('settings-feed: '.$e->getMessage());
}

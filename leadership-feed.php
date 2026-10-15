<?php
header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');
require_once __DIR__ . '/admin/config.php';
require_once __DIR__ . '/admin/lib.php'; // db_timezone_init_option()
try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC] + db_timezone_init_option());
    $rows = $pdo->query("SELECT id, name, role_title, bio, photo_filename, email, sort_order FROM leadership WHERE active=1 ORDER BY sort_order ASC")->fetchAll();
    echo json_encode(['success'=>true,'officers'=>$rows]);
} catch (Exception $e) { http_response_code(500); echo json_encode(['success'=>false,'officers'=>[]]); }

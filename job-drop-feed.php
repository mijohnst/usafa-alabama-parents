<?php
header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');
require_once __DIR__ . '/admin/config.php';
try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>true, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    // Only ever shows the currently-graduating class's entries — mirrors
    // admin/lib.php's outgoing_class_year()+1 so a prior class's approved
    // photos automatically stop appearing here the moment the next class
    // becomes eligible, with no manual cleanup needed between years.
    $month = (int)date('n'); $year = (int)date('Y');
    $outgoing_class_year = $month >= 7 ? $year : $year - 1;
    $eligible_year = (string)($outgoing_class_year + 1);
    $stmt = $pdo->prepare("SELECT filename, cadet_name, job_title FROM job_drop_photos WHERE active=1 AND class_year=? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$eligible_year]);
    $rows = $stmt->fetchAll();
    echo json_encode(['success'=>true,'drops'=>$rows]);
} catch (Exception $e) { http_response_code(500); echo json_encode(['success'=>false,'drops'=>[]]); error_log('job-drop-feed: '.$e->getMessage()); }

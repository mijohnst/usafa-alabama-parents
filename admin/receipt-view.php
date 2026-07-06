<?php
require_once __DIR__ . '/auth.php';
require_finance();
$pdo = get_pdo();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: purchases.php'); exit; }
$stmt = $pdo->prepare('SELECT receipt_filename FROM purchases WHERE id=?');
$stmt->execute([$id]);
$p = $stmt->fetch();
if (!$p || empty($p['receipt_filename'])) { header('Location: purchases.php'); exit; }
$file = __DIR__ . '/receipts/' . basename($p['receipt_filename']);
if (!file_exists($file)) { header('Location: purchases.php'); exit; }
$mime = mime_content_type($file);
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($p['receipt_filename']) . '"');
header('Content-Length: ' . filesize($file));
readfile($file);
exit;

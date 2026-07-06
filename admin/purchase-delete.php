<?php
require_once __DIR__ . '/auth.php';
require_finance();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: purchases.php'); exit; }
csrf_verify();
$id  = (int)($_POST['id'] ?? 0);
$pdo = get_pdo();
$row = $pdo->prepare('SELECT receipt_filename FROM purchases WHERE id=?');
$row->execute([$id]);
$p = $row->fetch();
if ($p) {
    if (!empty($p['receipt_filename'])) @unlink(__DIR__ . '/receipts/' . $p['receipt_filename']);
    $pdo->prepare('DELETE FROM purchases WHERE id=?')->execute([$id]);
    flash('success','Purchase deleted.');
}
header('Location: purchases.php');
exit;

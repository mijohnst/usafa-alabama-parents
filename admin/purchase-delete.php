<?php
require_once __DIR__ . '/auth.php';
require_finance();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: purchases.php'); exit; }
csrf_verify();
$id  = (int)($_POST['id'] ?? 0);
$pdo = get_pdo();
$row = $pdo->prepare('SELECT * FROM purchases WHERE id=?');
$row->execute([$id]);
$p = $row->fetch();
if ($p) {
    $own = (int)($p['submitted_by'] ?? -1) === (int)($_SESSION['user_id'] ?? 0);
    $can_delete = is_treasurer() || (is_member() && $own);
    if (!$can_delete) {
        flash('error', 'Only the treasurer can delete purchases.');
    } else {
        if (!empty($p['receipt_filename'])) @unlink(__DIR__ . '/receipts/' . $p['receipt_filename']);
        $pdo->prepare('DELETE FROM purchases WHERE id=?')->execute([$id]);
        flash('success', 'Purchase deleted.');
    }
}
header('Location: purchases.php');
exit;

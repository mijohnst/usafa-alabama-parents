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
    // Paid means money has actually moved — never deletable, by anyone,
    // regardless of role, so this can't be bypassed by posting here
    // directly even though purchases.php already hides the button.
    if ($p['status'] === 'paid') {
        flash('error', 'Paid purchases cannot be deleted — the money has already moved. Edit the record instead if something needs correcting.');
        header('Location: purchases.php'); exit;
    }
    $can_delete = is_treasurer() || is_super_admin() || ((is_member() || is_secretary()) && $own && $p['status'] === 'pending');
    if (!$can_delete) {
        flash('error', 'Approved or submitted purchases can only be deleted by the treasurer.');
    } else {
        if (!empty($p['receipt_filename'])) @unlink(__DIR__ . '/receipts/' . $p['receipt_filename']);
        $pdo->prepare('DELETE FROM purchases WHERE id=?')->execute([$id]);
        flash('success', 'Purchase deleted.');
    }
}
header('Location: purchases.php');
exit;

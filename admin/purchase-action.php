<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
require_finance();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: purchases.php'); exit; }
csrf_verify();

$id     = (int)($_POST['id']     ?? 0);
$action = trim($_POST['action']  ?? '');
$pdo    = get_pdo();

if (!$id || !in_array($action, ['approve','reimburse'])) {
    flash('error', 'Invalid request.');
    header('Location: purchases.php'); exit;
}

$stmt = $pdo->prepare(
    'SELECT p.*, u.name as submitted_by_name, u.email as submitted_by_email
     FROM purchases p LEFT JOIN users u ON p.submitted_by = u.id WHERE p.id = ?'
);
$stmt->execute([$id]);
$p = $stmt->fetch();

if (!$p) { flash('error', 'Purchase not found.'); header('Location: purchases.php'); exit; }

if ($action === 'approve') {
    // Only admins can approve
    if (!is_admin()) {
        flash('error', 'Only admins can approve purchases.');
        header('Location: purchases.php'); exit;
    }
    if ($p['status'] !== 'pending') {
        flash('error', 'Only pending purchases can be approved.');
        header('Location: purchases.php'); exit;
    }
    $pdo->prepare('UPDATE purchases SET status = ?, updated_at = NOW() WHERE id = ?')
        ->execute(['approved', $id]);
    flash('success', 'Purchase approved. Treasurers have been notified.');
    notify_approved($pdo, $p, current_user_name());

} elseif ($action === 'reimburse') {
    // Treasurer only can mark reimbursed
    if (!is_treasurer()) {
        flash('error', 'Only the treasurer can mark purchases as reimbursed.');
        header('Location: purchases.php'); exit;
    }
    if ($p['status'] !== 'approved') {
        flash('error', 'Only approved purchases can be marked as reimbursed.');
        header('Location: purchases.php'); exit;
    }
    $pdo->prepare('UPDATE purchases SET status = ?, updated_at = NOW() WHERE id = ?')
        ->execute(['reimbursed', $id]);
    flash('success', 'Purchase marked as reimbursed. Submitter has been notified.');
    notify_reimbursed($pdo, $p, current_user_name());
}

header('Location: purchases.php');
exit;

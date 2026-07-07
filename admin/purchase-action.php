<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
require_finance();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: purchases.php'); exit; }
csrf_verify();

$id             = (int)($_POST['id']             ?? 0);
$action         = trim($_POST['action']          ?? '');
$note           = trim($_POST['note']            ?? '');
$payment_method = trim($_POST['payment_method']  ?? '');
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
    if (!is_club_officer()) {
        flash('error', 'Only admins and officers can approve purchases.');
        header('Location: purchases.php'); exit;
    }
    if ($p['status'] !== 'pending') {
        flash('error', 'Only pending purchases can be approved.');
        header('Location: purchases.php'); exit;
    }
    // Warn if no receipt and receipt was marked required
    if (!empty($p['receipt_required']) && empty($p['receipt_filename'])) {
        // Allow but flag in note
        $note = trim('⚠️ Approved without receipt. ' . $note);
    }
    $pdo->prepare('UPDATE purchases SET status = ?, approved_note = ?, updated_at = NOW() WHERE id = ?')
        ->execute(['approved', $note, $id]);
    flash('success', 'Purchase approved. Treasurers have been notified.');
    $p['approved_note'] = $note;
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
    $pdo->prepare('UPDATE purchases SET status = ?, reimbursed_note = ?, payment_method = ?, updated_at = NOW() WHERE id = ?')
        ->execute(['reimbursed', $note, $payment_method, $id]);
    flash('success', 'Purchase marked as reimbursed. Submitter has been notified.');
    $p['reimbursed_note'] = $note;
    notify_reimbursed($pdo, $p, current_user_name());
}

header('Location: purchases.php');
exit;

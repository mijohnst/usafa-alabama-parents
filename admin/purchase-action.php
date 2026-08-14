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
    'SELECT p.*, u.name as submitted_by_name, u.email as submitted_by_email,
            u.role as submitted_by_role, u.officer_title as submitted_by_officer_title
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
    // President <-> VP cross-approval: President and VP share the generic
    // 'officer' role, so nothing else distinguishes them. If the submitter
    // is tagged as one of the two, only their counterpart (or an Admin/Tech
    // override) may approve — closes the self-approval loophole for those
    // two board seats. Submitters without a title set (legacy accounts, or
    // non-officer roles) fall back to the plain is_club_officer() check above.
    $submitter_title = $p['submitted_by_officer_title'] ?? '';
    if ($p['submitted_by_role'] === 'officer' && in_array($submitter_title, ['President', 'VP'], true) && !is_super_admin()) {
        $needed = $submitter_title === 'President' ? 'VP' : 'President';
        if (current_officer_title() !== $needed) {
            flash('error', "This purchase was submitted by the $submitter_title and must be approved by the $needed.");
            header('Location: purchases.php'); exit;
        }
    }
    // Block approval if a receipt was marked required and none is on file
    if (!empty($p['receipt_required']) && empty($p['receipt_filename'])) {
        flash('error', 'This purchase requires a receipt before it can be approved. Upload one on the edit page first.');
        header('Location: purchase-form.php?id=' . $id); exit;
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

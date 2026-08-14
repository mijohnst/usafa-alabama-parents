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

if (!$id || !in_array($action, ['approve','submit','paid'])) {
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
        // Read fresh from the DB rather than trusting $_SESSION['officer_title']
        // — that's only populated at login, so a title set/changed on this
        // account after the current session started would otherwise be
        // silently ignored, in either direction (wrongly blocking OR wrongly
        // allowing a self-approval).
        $approver_stmt = $pdo->prepare('SELECT officer_title FROM users WHERE id = ?');
        $approver_stmt->execute([$_SESSION['user_id'] ?? 0]);
        $approver_title = (string)($approver_stmt->fetchColumn() ?: '');
        if ($approver_title !== $needed) {
            flash('error', "This purchase was submitted by the $submitter_title and must be approved by the $needed.");
            header('Location: purchases.php'); exit;
        }
    }
    // Every purchase requires a receipt before it can be approved (the form
    // requires one to submit at all now, but older purchases predating that
    // rule may still be missing one).
    if (empty($p['receipt_filename'])) {
        flash('error', 'This purchase requires a receipt before it can be approved. Upload one on the edit page first.');
        header('Location: purchase-form.php?id=' . $id); exit;
    }
    $pdo->prepare('UPDATE purchases SET status = ?, approved_note = ?, updated_at = NOW() WHERE id = ?')
        ->execute(['approved', $note, $id]);
    // TEMP DIAGNOSTIC — remove once the self-approval bypass is root-caused.
    $debug = sprintf(
        '[debug: submitted_by=%s submitted_by_role=%s submitted_by_officer_title=%s | approver_user_id=%s approver_session_role=%s]',
        $p['submitted_by'] ?? 'null',
        var_export($p['submitted_by_role'] ?? null, true),
        var_export($p['submitted_by_officer_title'] ?? null, true),
        $_SESSION['user_id'] ?? 'null',
        var_export($_SESSION['role'] ?? null, true)
    );
    flash('success', 'Purchase approved. Treasurers have been notified. ' . $debug);
    $p['approved_note'] = $note;
    notify_approved($pdo, $p, current_user_name());

} elseif ($action === 'submit') {
    // Treasurer only can submit payment
    if (!is_treasurer()) {
        flash('error', 'Only the treasurer can submit payment for purchases.');
        header('Location: purchases.php'); exit;
    }
    if ($p['status'] !== 'approved') {
        flash('error', 'Only approved purchases can have payment submitted.');
        header('Location: purchases.php'); exit;
    }
    // reimbursed_note is the pre-4-step-workflow column name — still holds
    // this step's note (how/when payment was sent), see migrate_add_purchase_paid_step.sql.
    $pdo->prepare('UPDATE purchases SET status = ?, reimbursed_note = ?, payment_method = ?, updated_at = NOW() WHERE id = ?')
        ->execute(['submitted', $note, $payment_method, $id]);
    flash('success', 'Payment submitted. Submitter has been notified.');
    $p['reimbursed_note'] = $note;
    $p['payment_method']  = $payment_method;
    notify_submitted($pdo, $p, current_user_name());

} elseif ($action === 'paid') {
    // Treasurer only can confirm payment received
    if (!is_treasurer()) {
        flash('error', 'Only the treasurer can mark purchases as paid.');
        header('Location: purchases.php'); exit;
    }
    if ($p['status'] !== 'submitted') {
        flash('error', 'Only purchases with payment submitted can be marked as paid.');
        header('Location: purchases.php'); exit;
    }
    $pdo->prepare('UPDATE purchases SET status = ?, paid_note = ?, paid_at = NOW(), updated_at = NOW() WHERE id = ?')
        ->execute(['paid', $note, $id]);
    flash('success', 'Purchase marked as paid. Submitter has been notified.');
    $p['paid_note'] = $note;
    notify_paid($pdo, $p, current_user_name());
}

header('Location: purchases.php');
exit;

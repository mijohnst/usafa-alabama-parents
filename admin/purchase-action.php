<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/lib/paypal.php';
require_finance();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: purchases.php'); exit; }
csrf_verify();

$id             = (int)($_POST['id']             ?? 0);
$action         = trim($_POST['action']          ?? '');
$note           = trim($_POST['note']            ?? '');
$payment_method = trim($_POST['payment_method']  ?? '');
$pdo    = get_pdo();

if (!$id || !in_array($action, ['approve','submit','paid','send_paypal','check_paypal_status'])) {
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
    flash('success', 'Purchase approved. Treasurers have been notified.');
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

} elseif ($action === 'send_paypal') {
    // Treasurer only — same gate as submitting/marking payment
    if (!is_treasurer()) {
        flash('error', 'Only the treasurer can send PayPal payouts.');
        header('Location: purchases.php'); exit;
    }
    if ($p['status'] !== 'submitted') {
        flash('error', 'Only purchases with payment submitted can be paid via PayPal.');
        header('Location: purchases.php'); exit;
    }
    if (!empty($p['paypal_payout_batch_id'])) {
        flash('error', 'A PayPal payout has already been sent for this purchase — use Check Status instead.');
        header('Location: purchases.php'); exit;
    }
    $stored_pm = $p['payment_method'] ?? '';
    if (!str_starts_with($stored_pm, 'PayPal ')) {
        flash('error', 'This purchase\'s payment method is not PayPal.');
        header('Location: purchases.php'); exit;
    }
    $paypal_email = trim(substr($stored_pm, 7));
    $result = paypal_send_payout($paypal_email, (float)$p['amount_total'], 'Reimbursement: ' . $p['vendor'], 'purchase-' . $id);
    if ($result['success']) {
        $pdo->prepare('UPDATE purchases SET paypal_payout_batch_id = ?, paypal_payout_status = ?, paypal_payout_sent_at = NOW(), updated_at = NOW() WHERE id = ?')
            ->execute([$result['batch_id'], $result['status'], $id]);
        flash('success', "PayPal payout sent to $paypal_email — status: {$result['status']}. Check status before marking paid.");
    } else {
        flash('error', 'PayPal payout failed: ' . $result['error']);
    }

} elseif ($action === 'check_paypal_status') {
    if (!is_treasurer()) {
        flash('error', 'Only the treasurer can check PayPal payout status.');
        header('Location: purchases.php'); exit;
    }
    if (empty($p['paypal_payout_batch_id'])) {
        flash('error', 'No PayPal payout has been sent for this purchase yet.');
        header('Location: purchases.php'); exit;
    }
    $result = paypal_check_payout_status($p['paypal_payout_batch_id']);
    if ($result['success']) {
        $pdo->prepare('UPDATE purchases SET paypal_payout_status = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$result['status'], $id]);
        flash('success', 'PayPal payout status: ' . $result['status']);
    } else {
        flash('error', 'Could not check PayPal status: ' . $result['error']);
    }
}

header('Location: purchases.php');
exit;

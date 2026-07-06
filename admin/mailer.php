<?php
/**
 * Centralized email sender
 * ─────────────────────────────────────────────────────────────────────────────
 * Currently uses PHP mail(). When Google Workspace SMTP is ready, replace the
 * body of send_notification() with PHPMailer — nothing else needs to change.
 * ─────────────────────────────────────────────────────────────────────────────
 */

define('CLUB_NAME',  'USAFA Parents Club of Alabama');
define('CLUB_FROM',  'USAFA Parents Club of Alabama <info@alabamafalcons.org>');
define('ADMIN_URL',  'https://alabamafalcons.org/admin/');

function send_notification(string $to, string $subject, string $body): bool {
    $headers  = "From: " . CLUB_FROM . "\r\n";
    $headers .= "Reply-To: info@alabamafalcons.org\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    // Strip all control characters from subject to prevent header injection
    $clean_subject = preg_replace('/[\x00-\x1F\x7F]/', '', $subject);
    $clean_subject = mb_substr($clean_subject, 0, 200); // cap length
    return mail($to, $clean_subject, $body, $headers);
}

// ── Notify all treasurers + admins of a new purchase ─────────────────────
function notify_new_purchase(PDO $pdo, array $purchase, string $submitter_name): void {
    try {
        $recipients = $pdo->query(
            "SELECT name, email FROM users WHERE role IN ('treasurer','admin') AND active = 1"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('mailer: failed to fetch recipients — ' . $e->getMessage());
        return;
    }
    if (empty($recipients)) return;

    $url  = ADMIN_URL . 'purchase-form.php?id=' . (int)$purchase['id'];
    $amt  = '$' . number_format($purchase['amount_total'], 2);
    $date = date('F j, Y', strtotime($purchase['purchase_date']));

    $subject = 'New Purchase Submitted: ' . $purchase['vendor'] . ' — ' . $amt;
    $body    = CLUB_NAME . "\n"
             . "New Purchase Submitted\n"
             . str_repeat('─', 48) . "\n\n"
             . "Submitted by: $submitter_name\n"
             . "Date:         $date\n"
             . "Vendor:       {$purchase['vendor']}\n";
    if (!empty($purchase['order_number']))
        $body .= "Order #:      {$purchase['order_number']}\n";
    $body   .= "Description:  {$purchase['description']}\n";
    if (!empty($purchase['event']))
        $body .= "Event:        {$purchase['event']}\n";
    if (!empty($purchase['category']))
        $body .= "Category:     {$purchase['category']}\n";
    $body   .= "\nAmounts:\n"
             . "  Pre-Tax:  \${$purchase['amount_pretax']}\n"
             . "  Tax:      \${$purchase['amount_tax']}\n";
    if (!empty($purchase['amount_shipping']) && $purchase['amount_shipping'] > 0)
        $body .= "  Shipping: \${$purchase['amount_shipping']}\n";
    $body   .= "  Total:    $amt\n\n"
             . "View / Approve:  $url\n\n"
             . str_repeat('─', 48) . "\n"
             . CLUB_NAME . "\n" . ADMIN_URL;

    foreach ($recipients as $r) {
        send_notification($r['email'], $subject, $body);
    }
}

// ── Notify submitter of a status change ──────────────────────────────────
function notify_status_change(PDO $pdo, array $purchase, string $old_status, string $new_status, string $changed_by_name): void {
    if (!$purchase['submitted_by']) return;

    $submitter = $pdo->prepare('SELECT name, email FROM users WHERE id = ?');
    $submitter->execute([$purchase['submitted_by']]);
    $user = $submitter->fetch();
    if (!$user || !$user['email']) return;

    $status_labels = ['pending'=>'Pending','approved'=>'Approved','reimbursed'=>'Reimbursed'];
    $old_label = $status_labels[$old_status] ?? $old_status;
    $new_label = $status_labels[$new_status] ?? $new_status;
    $amt  = '$' . number_format($purchase['amount_total'], 2);
    $url  = ADMIN_URL . 'purchase-form.php?id=' . (int)$purchase['id'];
    $date = date('F j, Y', strtotime($purchase['purchase_date']));

    $subject = "Purchase {$new_label}: {$purchase['vendor']} — $amt";
    $body    = CLUB_NAME . "\n"
             . "Purchase Status Updated\n"
             . str_repeat('─', 48) . "\n\n"
             . "Hi {$user['name']},\n\n"
             . "Your purchase submission has been updated:\n\n"
             . "  Status:      $old_label  →  $new_label\n"
             . "  Updated by:  $changed_by_name\n\n"
             . "Purchase Details:\n"
             . "  Date:        $date\n"
             . "  Vendor:      {$purchase['vendor']}\n"
             . "  Description: {$purchase['description']}\n"
             . "  Total:       $amt\n\n";

    if ($new_status === 'approved')
        $body .= "Your purchase has been approved. Please retain your receipt for records.\n\n";
    elseif ($new_status === 'reimbursed')
        $body .= "Your reimbursement has been processed. Please allow time for payment delivery.\n\n";

    $body .= "View purchase:  $url\n\n"
           . str_repeat('─', 48) . "\n"
           . CLUB_NAME . "\n" . ADMIN_URL;

    send_notification($user['email'], $subject, $body);
}

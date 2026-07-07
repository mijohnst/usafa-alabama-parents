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

// ── Notify all treasurers that a purchase is approved & needs reimbursement ──
function notify_approved(PDO $pdo, array $purchase, string $approved_by): void {
    try {
        $recipients = $pdo->query(
            "SELECT name, email FROM users WHERE role IN ('treasurer','admin') AND active = 1"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('mailer: failed to fetch treasurer recipients — ' . $e->getMessage());
        return;
    }
    if (empty($recipients)) return;

    $amt  = '$' . number_format($purchase['amount_total'], 2);
    $date = date('F j, Y', strtotime($purchase['purchase_date']));
    $url  = ADMIN_URL . 'purchase-form.php?id=' . (int)$purchase['id'];

    $subject = "Action Required — Reimburse Approved Purchase: {$purchase['vendor']} $amt";
    $body    = CLUB_NAME . "\n"
             . "Purchase Approved — Reimbursement Needed\n"
             . str_repeat('─', 48) . "\n\n"
             . "A purchase has been approved and is ready for reimbursement.\n\n"
             . "Approved by:  $approved_by\n"
             . "Submitted by: " . ($purchase['submitted_by_name'] ?? 'Unknown') . "\n"
             . "Date:         $date\n"
             . "Vendor:       {$purchase['vendor']}\n";
    if (!empty($purchase['order_number']))
        $body .= "Order #:      {$purchase['order_number']}\n";
    $body   .= "Description:  {$purchase['description']}\n";
    if (!empty($purchase['event']))    $body .= "Event:        {$purchase['event']}\n";
    if (!empty($purchase['category'])) $body .= "Category:     {$purchase['category']}\n";
    $body   .= "\nAmounts:\n"
             . "  Pre-Tax:  \${$purchase['amount_pretax']}\n"
             . "  Tax:      \${$purchase['amount_tax']}\n";
    if (!empty($purchase['amount_shipping']) && $purchase['amount_shipping'] > 0)
        $body .= "  Shipping: \${$purchase['amount_shipping']}\n";
    $body   .= "  Total:    $amt\n\n"
             . "Please process the reimbursement and mark as Reimbursed:\n$url\n\n"
             . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . ADMIN_URL;

    foreach ($recipients as $r) {
        send_notification($r['email'], $subject, $body);
    }
}

// ── Notify submitter their reimbursement has been processed ──────────────
function notify_reimbursed(PDO $pdo, array $purchase, string $processed_by): void {
    // Use email already fetched via JOIN in purchase-action.php if available
    $email = $purchase['submitted_by_email'] ?? '';
    $name  = $purchase['submitted_by_name']  ?? '';

    // Fallback: query users table directly
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        if (empty($purchase['submitted_by'])) {
            error_log('mailer: notify_reimbursed — no submitted_by on purchase ' . ($purchase['id'] ?? '?'));
            return;
        }
        $submitter = $pdo->prepare('SELECT name, email FROM users WHERE id = ?');
        $submitter->execute([$purchase['submitted_by']]);
        $user = $submitter->fetch();
        if (!$user || !$user['email']) {
            error_log('mailer: notify_reimbursed — could not find user ' . $purchase['submitted_by']);
            return;
        }
        $email = $user['email'];
        $name  = $user['name'];
    }

    $amt  = '$' . number_format($purchase['amount_total'], 2);
    $date = date('F j, Y', strtotime($purchase['purchase_date']));
    $url  = ADMIN_URL . 'purchase-form.php?id=' . (int)$purchase['id'];

    $subject = "Reimbursement Processed: {$purchase['vendor']} $amt";
    $body    = CLUB_NAME . "\n"
             . "Your Reimbursement Has Been Processed\n"
             . str_repeat('─', 48) . "\n\n"
             . "Hi $name,\n\n"
             . "Your reimbursement has been processed by $processed_by.\n\n"
             . "Purchase Details:\n"
             . "  Date:        $date\n"
             . "  Vendor:      {$purchase['vendor']}\n"
             . "  Description: {$purchase['description']}\n"
             . "  Amount:      $amt\n\n"
             . "Please allow time for payment delivery. If you have questions,\n"
             . "contact your club treasurer.\n\n"
             . "View record:  $url\n\n"
             . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . ADMIN_URL;

    $sent = send_notification($email, $subject, $body);
    if (!$sent) {
        error_log("mailer: notify_reimbursed — mail() returned false for email='$email' purchase_id=" . ($purchase['id'] ?? '?'));
    } else {
        error_log("mailer: notify_reimbursed — sent OK to '$email' for purchase_id=" . ($purchase['id'] ?? '?'));
    }
}

// ── Check event budget thresholds after a purchase is saved ─────────────
function check_budget_thresholds(PDO $pdo, string $event): void {
    if (!$event) return;
    try {
        $b_stmt = $pdo->prepare('SELECT * FROM event_budgets WHERE event = ? LIMIT 1');
        $b_stmt->execute([$event]);
        $budget = $b_stmt->fetch();
        if (!$budget || $budget['budget'] <= 0) return;

        // Count all purchases (all statuses) towards budget
        $s_stmt = $pdo->prepare('SELECT COALESCE(SUM(amount_total),0) FROM purchases WHERE event = ?');
        $s_stmt->execute([$event]);
        $spent = (float)$s_stmt->fetchColumn();

        $pct  = (int)round($spent / $budget['budget'] * 100);
        $last = (int)$budget['last_notified_pct'];

        // Determine if a new threshold has been crossed: 75, 90, 100+
        $crossed = null;
        foreach ([75, 90] as $t) {
            if ($pct >= $t && $last < $t) $crossed = $t;
        }
        if ($pct >= 100 && $last < 100) $crossed = $pct; // show exact % when over

        if ($crossed !== null) {
            notify_budget_alert($pdo, $budget, $spent, $pct);
            $pdo->prepare('UPDATE event_budgets SET last_notified_pct = ? WHERE id = ?')
                ->execute([$pct, $budget['id']]);
        }
    } catch (PDOException $e) {
        error_log('mailer: check_budget_thresholds failed — ' . $e->getMessage());
    }
}

// ── Send budget threshold alert to all admins and treasurers ─────────────
function notify_budget_alert(PDO $pdo, array $budget, float $spent, int $pct): void {
    try {
        $recipients = $pdo->query(
            "SELECT name, email FROM users WHERE role IN ('admin','treasurer') AND active = 1"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('mailer: notify_budget_alert query failed — ' . $e->getMessage());
        return;
    }
    if (empty($recipients)) return;

    $budget_amt = '$' . number_format($budget['budget'], 2);
    $spent_amt  = '$' . number_format($spent, 2);
    $remaining  = $budget['budget'] - $spent;

    if ($pct >= 100) {
        $over    = '$' . number_format(abs($remaining), 2);
        $subject = "⚠️ Budget Exceeded: {$budget['event']} at {$pct}% — $over over budget";
        $level   = "OVER BUDGET ({$pct}%)";
    } elseif ($pct >= 90) {
        $subject = "Budget Alert — 90%: {$budget['event']} ($spent_amt of $budget_amt)";
        $level   = "90% THRESHOLD REACHED";
    } else {
        $subject = "Budget Alert — 75%: {$budget['event']} ($spent_amt of $budget_amt)";
        $level   = "75% THRESHOLD REACHED";
    }

    $body  = CLUB_NAME . "\n"
           . "Event Budget Alert — $level\n"
           . str_repeat('─', 48) . "\n\n"
           . "Event:     {$budget['event']}\n"
           . "Budget:    $budget_amt\n"
           . "Spent:     $spent_amt ($pct%)\n"
           . "Remaining: " . ($remaining >= 0 ? '$' . number_format($remaining,2) : '⚠️ -$' . number_format(abs($remaining),2)) . "\n\n";
    if ($pct >= 100)
        $body .= "This event has exceeded its budget by " . '$' . number_format(abs($remaining),2) . ".\n\n";
    $body .= "Review purchases:  " . ADMIN_URL . "purchases.php?event=" . urlencode($budget['event']) . "\n"
           . "Manage budgets:    " . ADMIN_URL . "budgets.php\n\n"
           . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . ADMIN_URL;

    foreach ($recipients as $r) {
        send_notification($r['email'], $subject, $body);
    }
}

// ── Notify submitter of a generic status change ───────────────────────────
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

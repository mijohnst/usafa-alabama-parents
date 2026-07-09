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
define('SITE_URL',   'https://alabamafalcons.org/');

function send_notification(string $to, string $subject, string $body): bool {
    $headers  = "From: " . CLUB_FROM . "\r\n";
    $headers .= "Reply-To: info@alabamafalcons.org\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    // Strip all control characters from subject to prevent header injection
    $clean_subject = preg_replace('/[\x00-\x1F\x7F]/', '', $subject);
    $clean_subject = mb_substr($clean_subject, 0, 200); // cap length
    return mail($to, $clean_subject, $body, $headers);
}

// Load the four birthday email templates (subject/body × cadet/parent) from
// site_settings — editable in admin/settings.php — falling back to these
// defaults if that migration hasn't been run yet.
function load_birthday_templates(PDO $pdo): array {
    $tpl = [
        'birthday_cadet_subject'  => 'Happy Birthday, {name}! 🎉',
        'birthday_cadet_body'     => "Happy Birthday, {name}!\n\nThe " . CLUB_NAME . " is thinking of you today and wishing you a fantastic birthday.\nThank you for everything you do — we're proud of you!\n\nAim High · Fly · Fight · Win\n" . CLUB_NAME . "\n" . SITE_URL,
        'birthday_parent_subject' => "Celebrating {cadet_name} Today! \u{1F382}",
        'birthday_parent_body'    => "On behalf of the " . CLUB_NAME . ", we want to take a moment to recognize {cadet_name} on their birthday today.\n\nCadets like {name} inspire us with their dedication, discipline, and hard work, and we are incredibly proud of everything they've accomplished on their journey at the Academy. We hope today is filled with celebration, and that {name} feels the pride and support of the entire Alabama Falcons family.\n\nHappy Birthday, {name}!\n\n" . CLUB_NAME . "\n" . SITE_URL,
    ];
    try {
        $tpl_stmt = $pdo->prepare('SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN (?,?,?,?)');
        $tpl_stmt->execute(array_keys($tpl));
        foreach ($tpl_stmt->fetchAll(PDO::FETCH_ASSOC) as $tr) {
            if (trim((string)$tr['setting_value']) !== '') $tpl[$tr['setting_key']] = $tr['setting_value'];
        }
    } catch (PDOException $e) {
        // site_settings not migrated yet — silently use the defaults above
    }
    return $tpl;
}

// ── Send happy-birthday emails to today's cadets + their parents ─────────
// Idempotent per cadet per calendar year via birthday_email_log.
// Returns the number of cadets processed (not the number of individual emails).
function send_birthday_emails(PDO $pdo): int {
    try {
        $rows = $pdo->query(
            "SELECT id, cadet_first_middle, cadet_last_name, nickname, cadet_email, parent1_email, parent2_email
             FROM members
             WHERE archived = 0 AND cadet_birthday IS NOT NULL
               AND MONTH(cadet_birthday) = MONTH(CURDATE()) AND DAY(cadet_birthday) = DAY(CURDATE())"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('mailer: send_birthday_emails query failed — ' . $e->getMessage());
        return 0;
    }
    if (empty($rows)) return 0;

    $tpl   = load_birthday_templates($pdo);
    $year  = (int)date('Y');
    $mark  = $pdo->prepare('INSERT IGNORE INTO birthday_email_log (member_id, year_sent) VALUES (?, ?)');
    $count = 0;

    foreach ($rows as $r) {
        $mark->execute([$r['id'], $year]);
        if ($mark->rowCount() < 1) continue; // already sent this cadet a wish this year
        $count++;

        $full_name = trim(($r['cadet_first_middle'] ?? '') . ' ' . ($r['cadet_last_name'] ?? ''));
        $nickname  = trim((string)($r['nickname'] ?? ''));
        $nick_or_first = $nickname !== '' ? $nickname : (explode(' ', trim((string)($r['cadet_first_middle'] ?? '')))[0] ?? '');
        if ($nick_or_first === '') $nick_or_first = $full_name ?: 'Cadet';

        $replace = ['{name}' => $nick_or_first, '{cadet_name}' => $full_name ?: $nick_or_first];

        if (!empty($r['cadet_email']) && filter_var($r['cadet_email'], FILTER_VALIDATE_EMAIL)) {
            send_notification($r['cadet_email'], strtr($tpl['birthday_cadet_subject'], $replace), strtr($tpl['birthday_cadet_body'], $replace));
        }

        $parent_subject = strtr($tpl['birthday_parent_subject'], $replace);
        $parent_body    = strtr($tpl['birthday_parent_body'], $replace);
        foreach ([$r['parent1_email'] ?? '', $r['parent2_email'] ?? ''] as $pe) {
            if ($pe !== '' && filter_var($pe, FILTER_VALIDATE_EMAIL)) {
                send_notification($pe, $parent_subject, $parent_body);
            }
        }
    }
    return $count;
}

// ── Send a preview of both birthday email templates to a test address ────
// Uses sample placeholder data — does not touch birthday_email_log or query members.
function send_birthday_test_email(PDO $pdo, string $to): bool {
    $tpl     = load_birthday_templates($pdo);
    $replace = ['{name}' => 'Jamie', '{cadet_name}' => 'Jamie Example'];

    $ok1 = send_notification($to, '[TEST — Cadet Email] ' . strtr($tpl['birthday_cadet_subject'], $replace), strtr($tpl['birthday_cadet_body'], $replace));
    $ok2 = send_notification($to, '[TEST — Parent Email] ' . strtr($tpl['birthday_parent_subject'], $replace), strtr($tpl['birthday_parent_body'], $replace));
    return $ok1 && $ok2;
}

// ── Notify board-flagged parents that meeting minutes have been posted ───
// Returns the number of emails successfully sent.
function notify_board_minutes_posted(PDO $pdo, array $meeting, string $posted_by_name): int {
    try {
        $rows = $pdo->query(
            "SELECT parent1_email AS email FROM members WHERE archived=0 AND parent1_is_board_member=1 AND parent1_email <> ''
             UNION
             SELECT parent2_email AS email FROM members WHERE archived=0 AND parent2_is_board_member=1 AND parent2_email <> ''"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('mailer: notify_board_minutes_posted query failed — ' . $e->getMessage());
        return 0;
    }
    if (empty($rows)) return 0;

    $seen = [];
    $emails = [];
    foreach ($rows as $r) {
        $email = strtolower(trim($r['email']));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[$email])) continue;
        $seen[$email] = true;
        $emails[] = $email;
    }
    if (empty($emails)) return 0;

    $date = date('F j, Y', strtotime($meeting['meeting_date']));
    $url  = SITE_URL . 'minutes-public.php?id=' . (int)$meeting['id'] . '&token=' . $meeting['minutes_token'];

    $subject = "Meeting Minutes Posted — {$meeting['title']} ($date)";
    $body    = CLUB_NAME . "\n"
             . "Meeting Minutes Posted\n"
             . str_repeat('─', 48) . "\n\n"
             . "Minutes have been posted for the following meeting:\n\n"
             . "  Meeting:  {$meeting['title']}\n"
             . "  Date:     $date\n";
    if (!empty($meeting['location']))     $body .= "  Location: {$meeting['location']}\n";
    if (!empty($meeting['meeting_link'])) $body .= "  Link:     {$meeting['meeting_link']}\n";
    $body .= "\nPosted by: $posted_by_name\n\n"
           . "View / download the minutes:\n$url\n\n"
           . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . SITE_URL;

    $sent = 0;
    foreach ($emails as $email) {
        if (send_notification($email, $subject, $body)) $sent++;
    }
    return $sent;
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

// ── Notify tech/admin when a submitter adds details to their ticket ──────
function notify_tech_of_comment(PDO $pdo, array $ticket, string $comment, string $from_name): void {
    try {
        $recipients = $pdo->query(
            "SELECT name, email FROM users WHERE role IN ('admin','tech') AND active = 1"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('mailer: notify_tech_of_comment failed — ' . $e->getMessage());
        return;
    }
    if (empty($recipients)) return;

    $url     = ADMIN_URL . 'ticket-view.php?id=' . (int)$ticket['id'];
    $subject = preg_replace('/[\x00-\x1F\x7F]/', '',
               "Ticket Update — {$ticket['ticket_number']}: {$ticket['subject']}");
    $body    = CLUB_NAME . "\n"
             . "Ticket Update from Submitter\n"
             . str_repeat('─', 48) . "\n\n"
             . "Ticket:   {$ticket['ticket_number']}\n"
             . "Subject:  {$ticket['subject']}\n"
             . "Status:   " . (TICKET_STATUSES[$ticket['status']] ?? $ticket['status']) . "\n"
             . "From:     $from_name\n\n"
             . "Added details:\n$comment\n\n"
             . "Respond: $url\n\n"
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

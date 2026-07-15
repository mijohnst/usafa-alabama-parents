<?php
/**
 * Centralized email sender
 * ─────────────────────────────────────────────────────────────────────────────
 * Currently uses PHP mail(). When Google Workspace SMTP is ready, replace the
 * body of send_notification() with PHPMailer — nothing else needs to change.
 * ─────────────────────────────────────────────────────────────────────────────
 */

// Same reasoning as auth.php — this file is also the entry point for the
// CLI cron job, which never includes auth.php, so the timezone needs to be
// anchored here too, independently.
date_default_timezone_set('America/Chicago');

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

// ─────────────────────────────────────────────────────────────────────────
// Automated Emails framework — templates + enable/disable live in the
// automated_emails table, managed from admin/automated-emails.php.
// Idempotency ("don't resend the same occasion") is tracked generically in
// automated_email_log, keyed by (email_key, subject_id, period_key).
// ─────────────────────────────────────────────────────────────────────────

function load_automated_email(PDO $pdo, string $email_key): ?array {
    $stmt = $pdo->prepare('SELECT * FROM automated_emails WHERE email_key = ? LIMIT 1');
    $stmt->execute([$email_key]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Returns true (and records the send) only the first time this exact
// (email_key, subject_id, period_key) combination is seen.
function mark_automated_sent(PDO $pdo, string $email_key, int $subject_id, string $period_key): bool {
    $stmt = $pdo->prepare('INSERT IGNORE INTO automated_email_log (email_key, subject_id, period_key) VALUES (?, ?, ?)');
    $stmt->execute([$email_key, $subject_id, $period_key]);
    return $stmt->rowCount() > 0;
}

// Parses a "YYYY-YYYY" membership_paid_through value into the date it
// actually expires (June 30 of the second year, matching the July-June
// club year used by membership_year()). Returns null if unparseable.
function parse_membership_expiration(string $paid_through): ?DateTimeImmutable {
    if (!preg_match('/^(\d{4})-(\d{4})$/', trim($paid_through), $m)) return null;
    try {
        return new DateTimeImmutable($m[2] . '-06-30');
    } catch (Exception $e) {
        return null;
    }
}

// ── Send happy-birthday emails to today's cadets + their parents ─────────
// Cadet and parent versions can be enabled/disabled independently.
// Returns the number of cadets processed (not the number of individual emails).
function send_birthday_emails(PDO $pdo): int {
    $cadet_cfg  = load_automated_email($pdo, 'birthday_cadet');
    $parent_cfg = load_automated_email($pdo, 'birthday_parent');
    $cadet_on   = $cadet_cfg  && $cadet_cfg['enabled'];
    $parent_on  = $parent_cfg && $parent_cfg['enabled'];
    if (!$cadet_on && !$parent_on) return 0;

    try {
        // Bind PHP's own month/day rather than trusting MySQL's CURDATE() —
        // the two can disagree if the DB server's timezone isn't the same
        // as the one set above, silently shifting "today" by hours.
        $stmt = $pdo->prepare(
            "SELECT id, cadet_first_name, cadet_middle_name, cadet_last_name, nickname, cadet_email, parent1_email, parent2_email
             FROM members
             WHERE archived = 0 AND cadet_birthday IS NOT NULL
               AND MONTH(cadet_birthday) = :month AND DAY(cadet_birthday) = :day"
        );
        $stmt->execute(['month' => (int)date('n'), 'day' => (int)date('j')]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('mailer: send_birthday_emails query failed — ' . $e->getMessage());
        return 0;
    }
    if (empty($rows)) return 0;

    $year  = (int)date('Y');
    $count = 0;

    foreach ($rows as $r) {
        if (!mark_automated_sent($pdo, 'birthday', (int)$r['id'], (string)$year)) continue; // already wished this year
        $count++;

        $full_name = trim(($r['cadet_first_name'] ?? '') . ' ' . ($r['cadet_middle_name'] ?? '') . ' ' . ($r['cadet_last_name'] ?? ''));
        $full_name = preg_replace('/\s+/', ' ', $full_name);
        $nickname  = trim((string)($r['nickname'] ?? ''));
        $nick_or_first = $nickname !== '' ? $nickname : trim((string)($r['cadet_first_name'] ?? ''));
        if ($nick_or_first === '') $nick_or_first = $full_name ?: 'Cadet';
        $replace = ['{name}' => $nick_or_first, '{cadet_name}' => $full_name ?: $nick_or_first];

        if ($cadet_on && !empty($r['cadet_email']) && filter_var($r['cadet_email'], FILTER_VALIDATE_EMAIL)) {
            send_notification($r['cadet_email'], strtr($cadet_cfg['subject'], $replace), strtr($cadet_cfg['body'], $replace));
        }
        if ($parent_on) {
            $parent_subject = strtr($parent_cfg['subject'], $replace);
            $parent_body    = strtr($parent_cfg['body'], $replace);
            foreach ([$r['parent1_email'] ?? '', $r['parent2_email'] ?? ''] as $pe) {
                if ($pe !== '' && filter_var($pe, FILTER_VALIDATE_EMAIL)) {
                    send_notification($pe, $parent_subject, $parent_body);
                }
            }
        }
    }
    return $count;
}

// ── Dues renewal reminder — parents, once, N days before paid-through ends ──
function send_dues_renewal_reminders(PDO $pdo): int {
    $cfg = load_automated_email($pdo, 'dues_renewal');
    if (!$cfg || !$cfg['enabled']) return 0;

    try {
        $rows = $pdo->query(
            "SELECT id, cadet_first_name, cadet_middle_name, cadet_last_name, parent1_first_name, parent1_email, parent2_email, membership_paid_through, membership_type
             FROM members
             WHERE archived = 0 AND membership_paid = 1 AND membership_paid_through <> '' AND class_year <> 'Graduate'"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('mailer: send_dues_renewal_reminders query failed — ' . $e->getMessage());
        return 0;
    }

    $today = new DateTimeImmutable('today');
    $count = 0;
    foreach ($rows as $r) {
        $exp = parse_membership_expiration($r['membership_paid_through']);
        if (!$exp) continue;
        $days_left = (int)$today->diff($exp)->format('%r%a');
        if ($days_left < 0 || $days_left > (int)$cfg['days_offset']) continue;
        if (!mark_automated_sent($pdo, 'dues_renewal', (int)$r['id'], $r['membership_paid_through'])) continue;
        $count++;

        $full_name = trim(preg_replace('/\s+/', ' ', ($r['cadet_first_name'] ?? '') . ' ' . ($r['cadet_middle_name'] ?? '') . ' ' . ($r['cadet_last_name'] ?? '')));
        $replace = [
            '{parent_name}' => $r['parent1_first_name'] ?: 'there',
            '{cadet_name}'  => $full_name ?: 'your cadet',
            '{expire_date}' => $exp->format('F j, Y'),
            '{dues_amount}' => $r['membership_type'] === '4year' ? '$275' : '$75',
        ];
        $subject = strtr($cfg['subject'], $replace);
        $body    = strtr($cfg['body'], $replace);
        foreach ([$r['parent1_email'] ?? '', $r['parent2_email'] ?? ''] as $pe) {
            if ($pe !== '' && filter_var($pe, FILTER_VALIDATE_EMAIL)) send_notification($pe, $subject, $body);
        }
    }
    return $count;
}

// ── Lapsed member re-engagement — parents, once, N days after expiration ──
function send_lapsed_reengagement(PDO $pdo): int {
    $cfg = load_automated_email($pdo, 'lapsed_reengagement');
    if (!$cfg || !$cfg['enabled']) return 0;

    try {
        $rows = $pdo->query(
            "SELECT id, cadet_first_name, cadet_middle_name, cadet_last_name, parent1_first_name, parent1_email, parent2_email, membership_paid_through
             FROM members
             WHERE archived = 0 AND membership_paid_through <> '' AND class_year <> 'Graduate'"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('mailer: send_lapsed_reengagement query failed — ' . $e->getMessage());
        return 0;
    }

    $today = new DateTimeImmutable('today');
    $count = 0;
    foreach ($rows as $r) {
        $exp = parse_membership_expiration($r['membership_paid_through']);
        if (!$exp || $today < $exp) continue; // not expired yet
        $days_past = (int)$exp->diff($today)->format('%r%a');
        if ($days_past < (int)$cfg['days_offset']) continue;
        if (!mark_automated_sent($pdo, 'lapsed_reengagement', (int)$r['id'], $r['membership_paid_through'])) continue;
        $count++;

        $full_name = trim(preg_replace('/\s+/', ' ', ($r['cadet_first_name'] ?? '') . ' ' . ($r['cadet_middle_name'] ?? '') . ' ' . ($r['cadet_last_name'] ?? '')));
        $replace = [
            '{parent_name}' => $r['parent1_first_name'] ?: 'there',
            '{cadet_name}'  => $full_name ?: 'your cadet',
            '{expire_date}' => $exp->format('F j, Y'),
        ];
        $subject = strtr($cfg['subject'], $replace);
        $body    = strtr($cfg['body'], $replace);
        foreach ([$r['parent1_email'] ?? '', $r['parent2_email'] ?? ''] as $pe) {
            if ($pe !== '' && filter_var($pe, FILTER_VALIDATE_EMAIL)) send_notification($pe, $subject, $body);
        }
    }
    return $count;
}

// ── New member welcome follow-up — parents, once, N days after joining ───
function send_new_member_welcome(PDO $pdo): int {
    $cfg = load_automated_email($pdo, 'new_member_welcome');
    if (!$cfg || !$cfg['enabled']) return 0;

    $offset = (int)$cfg['days_offset'];
    try {
        $stmt = $pdo->prepare(
            "SELECT id, cadet_first_name, cadet_middle_name, cadet_last_name, parent1_first_name, parent1_email, parent2_email
             FROM members
             WHERE archived = 0 AND created_at IS NOT NULL
               AND DATEDIFF(?, created_at) BETWEEN ? AND ?"
        );
        $stmt->execute([date('Y-m-d'), $offset, $offset + 6]); // small window so a missed cron day doesn't skip anyone
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('mailer: send_new_member_welcome query failed — ' . $e->getMessage());
        return 0;
    }

    $count = 0;
    foreach ($rows as $r) {
        if (!mark_automated_sent($pdo, 'new_member_welcome', (int)$r['id'], 'once')) continue;
        $count++;

        $full_name = trim(preg_replace('/\s+/', ' ', ($r['cadet_first_name'] ?? '') . ' ' . ($r['cadet_middle_name'] ?? '') . ' ' . ($r['cadet_last_name'] ?? '')));
        $replace = [
            '{parent_name}' => $r['parent1_first_name'] ?: 'there',
            '{cadet_name}'  => $full_name ?: 'your cadet',
        ];
        $subject = strtr($cfg['subject'], $replace);
        $body    = strtr($cfg['body'], $replace);
        foreach ([$r['parent1_email'] ?? '', $r['parent2_email'] ?? ''] as $pe) {
            if ($pe !== '' && filter_var($pe, FILTER_VALIDATE_EMAIL)) send_notification($pe, $subject, $body);
        }
    }
    return $count;
}

// ── Meeting reminder — morning-of. Board meetings → board-flagged parents ──
// ── only. General meetings → all active members. Special/Other → no email ──
// ── at all. ─────────────────────────────────────────────────────────────
function send_meeting_reminders(PDO $pdo): int {
    $cfg = load_automated_email($pdo, 'meeting_reminder');
    if (!$cfg || !$cfg['enabled']) return 0;

    try {
        $stmt = $pdo->prepare("SELECT * FROM club_meetings WHERE meeting_date = ?");
        $stmt->execute([date('Y-m-d')]);
        $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('mailer: send_meeting_reminders query failed — ' . $e->getMessage());
        return 0;
    }
    if (empty($meetings)) return 0;

    $count = 0;
    foreach ($meetings as $meeting) {
        // Special/Other meetings never get a reminder
        if (!in_array($meeting['meeting_type'], ['board', 'general'], true)) continue;

        if (!mark_automated_sent($pdo, 'meeting_reminder', (int)$meeting['id'], 'sent')) continue;

        try {
            if ($meeting['meeting_type'] === 'board') {
                $email_rows = $pdo->query(
                    "SELECT parent1_email AS email FROM members WHERE archived=0 AND parent1_is_board_member=1 AND parent1_email <> ''
                     UNION
                     SELECT parent2_email AS email FROM members WHERE archived=0 AND parent2_is_board_member=1 AND parent2_email <> ''"
                )->fetchAll(PDO::FETCH_ASSOC);
            } else { // general
                $pair_rows = $pdo->query("SELECT parent1_email, parent2_email FROM members WHERE archived=0")->fetchAll(PDO::FETCH_ASSOC);
                $email_rows = [];
                foreach ($pair_rows as $pr) {
                    if (!empty($pr['parent1_email'])) $email_rows[] = ['email' => $pr['parent1_email']];
                    if (!empty($pr['parent2_email'])) $email_rows[] = ['email' => $pr['parent2_email']];
                }
            }
        } catch (PDOException $e) {
            $email_rows = [];
        }

        $seen = []; $emails = [];
        foreach ($email_rows as $er) {
            $email = strtolower(trim($er['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[$email])) continue;
            $seen[$email] = true;
            $emails[] = $email;
        }
        if (empty($emails)) continue;
        $count++;

        $replace = [
            '{meeting_title}'    => $meeting['title'],
            '{meeting_date}'     => date('l, F j, Y', strtotime($meeting['meeting_date'])),
            '{meeting_location}' => $meeting['location'] ?: 'No location listed',
            '{meeting_link}'     => $meeting['meeting_link'] ?: 'No virtual link provided',
        ];
        $subject = strtr($cfg['subject'], $replace);
        $body    = strtr($cfg['body'], $replace);
        foreach ($emails as $email) send_notification($email, $subject, $body);
    }
    return $count;
}

// ── Send a preview of any automated email to a test address ──────────────
// Uses sample placeholder data — does not touch automated_email_log or query members.
function send_automated_test_email(PDO $pdo, string $email_key, string $to): bool {
    $samples = [
        'birthday_cadet'      => ['{name}' => 'Jamie', '{cadet_name}' => 'Jamie Example'],
        'birthday_parent'     => ['{name}' => 'Jamie', '{cadet_name}' => 'Jamie Example'],
        'dues_renewal'        => ['{parent_name}' => 'Alex', '{cadet_name}' => 'Jamie Example', '{expire_date}' => date('F j, Y', strtotime('+30 days')), '{dues_amount}' => '$75'],
        'meeting_reminder'    => ['{meeting_title}' => 'Monthly General Meeting', '{meeting_date}' => date('l, F j, Y'), '{meeting_location}' => 'Zoom', '{meeting_link}' => 'https://zoom.us/j/example'],
        'new_member_welcome'  => ['{parent_name}' => 'Alex', '{cadet_name}' => 'Jamie Example'],
        'lapsed_reengagement' => ['{parent_name}' => 'Alex', '{cadet_name}' => 'Jamie Example', '{expire_date}' => date('F j, Y', strtotime('-60 days'))],
    ];
    $cfg = load_automated_email($pdo, $email_key);
    if (!$cfg) return false;
    $replace = $samples[$email_key] ?? [];
    return send_notification($to, '[TEST] ' . strtr($cfg['subject'], $replace), strtr($cfg['body'], $replace));
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

// Builds the subject/body for the nominations-open email — shared by the
// real send (notify_nominations_open) and the Secretary's test-send
// (send_nominations_open_test) so a preview always matches what members
// would actually receive. Returns null if every position already has an
// approved candidate (nothing to announce).
function build_nominations_open_email(PDO $pdo, array $election): ?array {
    $open_positions = ELECTION_POSITIONS;
    try {
        $filled = $pdo->prepare("SELECT DISTINCT position FROM election_candidates WHERE election_id=? AND status='approved'");
        $filled->execute([$election['id']]);
        $open_positions = array_values(array_diff(ELECTION_POSITIONS, $filled->fetchAll(PDO::FETCH_COLUMN)));
    } catch (PDOException $e) {
        error_log('mailer: build_nominations_open_email position query failed — ' . $e->getMessage());
    }
    if (empty($open_positions)) return null;

    $url     = ADMIN_URL . 'vote.php';
    $subject = 'Board Elections — Nominations Now Open';
    $body    = CLUB_NAME . "\n"
             . "Officer Election — Nominations Open\n"
             . str_repeat('─', 48) . "\n\n"
             . "{$election['title']} is coming up, and nominations are now open. The following "
             . "board position" . (count($open_positions) === 1 ? ' is' : 's are') . " still open:\n\n";
    foreach ($open_positions as $p) $body .= "  • $p\n";
    $body .= "\nAs a paid member, you're eligible to nominate yourself for any open position. "
           . "The Secretary reviews and approves nominations before voting opens.\n\n"
           . "Nominate yourself:  $url\n\n"
           . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . ADMIN_URL;

    return ['subject' => $subject, 'body' => $body];
}

// ── Notify all paid members that nominations are open for an election ────
// Emails parent1_email/parent2_email straight from the members table
// (rather than the users/portal accounts) so it reaches every paid family,
// including ones who haven't set up a portal login yet — matching the same
// membership_paid=1 pool that's eligible to actually run (see elections.php's
// member picker and vote.php's self-nomination check).
function notify_nominations_open(PDO $pdo, array $election): int {
    $email = build_nominations_open_email($pdo, $election);
    if (!$email) return 0; // every seat already has an approved candidate

    try {
        $rows = $pdo->query(
            "SELECT parent1_email AS email FROM members WHERE archived=0 AND membership_paid=1 AND parent1_email <> ''
             UNION
             SELECT parent2_email AS email FROM members WHERE archived=0 AND membership_paid=1 AND parent2_email <> ''"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('mailer: notify_nominations_open recipient query failed — ' . $e->getMessage());
        return 0;
    }
    if (empty($rows)) return 0;

    $seen = []; $emails = [];
    foreach ($rows as $r) {
        $addr = strtolower(trim($r['email'] ?? ''));
        if ($addr === '' || !filter_var($addr, FILTER_VALIDATE_EMAIL) || isset($seen[$addr])) continue;
        $seen[$addr] = true;
        $emails[] = $addr;
    }
    if (empty($emails)) return 0;

    $sent = 0;
    foreach ($emails as $addr) {
        if (send_notification($addr, $email['subject'], $email['body'])) $sent++;
    }
    return $sent;
}

// ── Send the Secretary a preview of the nominations-open email ───────────
// Same content real recipients would get, minus the recipient list — sent
// only to the given test address, with the subject flagged [TEST].
function send_nominations_open_test(PDO $pdo, array $election, string $to): bool {
    $email = build_nominations_open_email($pdo, $election);
    if (!$email) return false; // nothing to preview — every seat already filled
    return send_notification($to, '[TEST] ' . $email['subject'], $email['body']);
}

// ── Notify all active portal accounts that voting has opened ─────────────
function notify_election_open(PDO $pdo, array $election): int {
    try {
        $recipients = $pdo->query("SELECT name, email FROM users WHERE active = 1")->fetchAll();
    } catch (PDOException $e) {
        error_log('mailer: notify_election_open query failed — ' . $e->getMessage());
        return 0;
    }
    if (empty($recipients)) return 0;

    $closes  = date('F j, Y \a\t g:ia', strtotime($election['voting_closes_at']));
    $url     = ADMIN_URL . 'vote.php';
    $subject = 'Voting Is Open: ' . $election['title'];
    $body    = CLUB_NAME . "\n"
             . "Officer Election — Voting Is Open\n"
             . str_repeat('─', 48) . "\n\n"
             . "{$election['title']} is now open for voting. Cast your ballot for "
             . "President, Vice President, Secretary, and Treasurer.\n\n"
             . "Voting closes: $closes\n\n"
             . "Vote now:  $url\n\n"
             . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . SITE_URL;

    $sent = 0;
    foreach ($recipients as $r) {
        $email = trim($r['email'] ?? '');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && send_notification($email, $subject, $body)) $sent++;
    }
    return $sent;
}

// ── Invite a paid member to set up their own portal login ────────────────
function send_portal_invite(string $to, string $name, string $token): bool {
    $url     = SITE_URL . 'portal-signup.php?token=' . $token;
    $subject = 'Set Up Your ' . CLUB_NAME . ' Portal Account';
    $body    = CLUB_NAME . "\n"
             . "You're Invited to the Member Portal\n"
             . str_repeat('─', 48) . "\n\n"
             . "Hi $name,\n\n"
             . "As a paid member, you now have access to the Parents Club portal — "
             . "sign up for volunteer opportunities, RSVP to events, share event photos, "
             . "and flag which committees you'd like to help with.\n\n"
             . "Set up your account (link expires in 14 days):\n$url\n\n"
             . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . SITE_URL;
    return send_notification($to, $subject, $body);
}

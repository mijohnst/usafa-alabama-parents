<?php
require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/admin/form-guard.php';
require_once __DIR__ . '/admin/mailer.php';

header('Content-Type: application/json');
// Must be set on every response, not just the OPTIONS preflight — see
// membership-handler.php for why.
header('Access-Control-Allow-Origin: https://alabamafalcons.org');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200); exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['success' => false, 'error' => 'Method not allowed']); exit();
}

$input = file_get_contents('php://input');
$data  = json_decode($input, true);
if (!$data) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'Invalid input']); exit(); }

// Honeypot — bots fill this hidden field, real visitors never see it.
// Pretend success so bots don't learn to avoid the field.
if (honeypot_tripped($data)) {
    echo json_encode(['success' => true, 'message' => "You're signed up — thank you!"]);
    exit;
}

$pdo = get_pdo();

if (rate_limited($pdo, 'volunteer_claim')) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many submissions from your network. Please try again later or email us directly at info@alabamafalcons.org.']);
    exit;
}

$opportunity_id = (int)($data['opportunity_id'] ?? 0);
$last     = trim((string)($data['cadetLastName'] ?? ''));
$birthday = trim((string)($data['cadetBirthday'] ?? ''));
$email    = trim((string)($data['email'] ?? ''));

if (!$opportunity_id || $last === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) {
    http_response_code(400); echo json_encode(['success' => false, 'error' => "Please enter your cadet's last name, birthday, and the email address on file."]); exit();
}

// Verified against the roster the same way as update.html and
// event-signup.html (find_member_by_identity() in admin/lib.php), so a
// claim is tied to a real member family instead of a typed-in "guest"
// name. The name recorded is whoever on that record owns the email used.
$member = find_member_by_identity($pdo, $last, $birthday, $email);
if (!$member) {
    echo json_encode(['success' => false, 'error' => "We couldn't find a matching record. Please double-check your cadet's last name, birthday, and the email on file, or contact secretary@alabamafalcons.org."]);
    exit();
}
$member_id = (int)$member['id'];
$lc = strtolower($email);
if ($lc === strtolower((string)$member['parent1_email'])) {
    $name = trim($member['parent1_first_name'] . ' ' . $member['parent1_last_name']);
} elseif ($lc === strtolower((string)($member['parent2_email'] ?? ''))) {
    $name = trim(($member['parent2_first_name'] ?? '') . ' ' . ($member['parent2_last_name'] ?? ''));
} else {
    $name = cadet_full_name($member);
}
if ($name === '') $name = cadet_full_name($member) ?: $email;

try {
    // Fail fast instead of hanging the visitor's button for MySQL's default
    // 50s if the opportunity row / signups table is locked (e.g. by a
    // long-running query or schema change in phpMyAdmin).
    try { $pdo->exec('SET SESSION innodb_lock_wait_timeout = 10'); } catch (PDOException $e) {}
    $pdo->beginTransaction();

    // FOR UPDATE locks the opportunity row for the rest of this
    // transaction, so a second concurrent claim has to wait until this one
    // commits (and sees the up-to-date fill count) rather than both reading
    // "not yet full" at the same time and overbooking past spots_needed.
    $stmt = $pdo->prepare('SELECT id, title, spots_needed, active FROM volunteer_opportunities WHERE id = ? FOR UPDATE');
    $stmt->execute([$opportunity_id]);
    $opp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$opp || !$opp['active']) {
        $pdo->rollBack();
        http_response_code(410); echo json_encode(['success' => false, 'error' => 'This opportunity is no longer open.']); exit();
    }

    $filled_stmt = $pdo->prepare('SELECT COUNT(*) FROM volunteer_signups WHERE opportunity_id = ?');
    $filled_stmt->execute([$opportunity_id]);
    $filled = (int)$filled_stmt->fetchColumn();

    if ($filled >= (int)$opp['spots_needed']) {
        $pdo->rollBack();
        http_response_code(409); echo json_encode(['success' => false, 'error' => 'That opportunity is already full.']); exit();
    }

    // One claim per family per opportunity, whichever of their emails they
    // use (the table's own unique key is only per email). Guarded: the
    // member_id column only exists once migrate_volunteer_member_link.sql
    // has run — before that, claims save exactly as they used to.
    $has_member_col = (bool)$pdo->query("SHOW COLUMNS FROM volunteer_signups LIKE 'member_id'")->fetch();
    if ($has_member_col) {
        $dup = $pdo->prepare('SELECT 1 FROM volunteer_signups WHERE opportunity_id = ? AND member_id = ?');
        $dup->execute([$opportunity_id, $member_id]);
        if ($dup->fetchColumn()) {
            $pdo->rollBack();
            echo json_encode(['success' => true, 'message' => "Your family is already signed up for that one — thank you!"]);
            exit;
        }
        $pdo->prepare('INSERT INTO volunteer_signups (opportunity_id, guest_name, guest_email, member_id) VALUES (?, ?, ?, ?)')
            ->execute([$opportunity_id, $name, $lc, $member_id]);
    } else {
        $pdo->prepare('INSERT INTO volunteer_signups (opportunity_id, guest_name, guest_email) VALUES (?, ?, ?)')
            ->execute([$opportunity_id, $name, $lc]);
    }

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e->getCode() === '23000') {
        // Unique key on (opportunity_id, guest_email) — this email already
        // claimed this one. Any other error code is a real failure (lost
        // connection, schema mismatch, etc.) and must not be reported to
        // the visitor as if they'd succeeded.
        echo json_encode(['success' => true, 'message' => "You're already signed up for that one — thank you!"]);
        exit;
    }
    error_log('volunteer-opportunity-claim: signup failed — ' . $e->getMessage());
    http_response_code(500); echo json_encode(['success' => false, 'error' => 'A server error occurred. Please try again or email us directly at info@alabamafalcons.org.']); exit();
}

$title = $opp['title'];

// Answer the visitor now — the claim is already committed — then send the
// confirmation + officer emails (each a separate SMTP round-trip) after.
send_json_and_continue(['success' => true, 'message' => "You're signed up — thank you!"]);

send_notification(
    $email,
    'You\'re Signed Up — ' . $title,
    "Thanks for volunteering with the USAFA Parents Club of Alabama!\n\n"
    . "You're signed up for: $title\n\n"
    . "A club officer may follow up with details beforehand. If your plans change, just reply to this email and let us know.\n\n"
    . "Aim High \xC2\xB7 Fly \xC2\xB7 Fight \xC2\xB7 Win\nUSAFA Parents Club of Alabama\nalabamafalcons.org"
);

foreach (['secretary@alabamafalcons.org', 'president@alabamafalcons.org'] as $notify_to) {
    send_notification(
        $notify_to,
        'New Volunteer Sign-Up: ' . $title,
        "$name <$email> just claimed a spot for \"$title\" from the website (verified member family: " . member_family_label($member) . ").\n\n"
        . ADMIN_URL . 'volunteer-opportunities.php'
    );
}

<?php
/**
 * Public Event Sign-Up (event-signup.html)
 * Lets a member family RSVP to one club event from an emailed link, without
 * a portal login. Same identity check as update-lookup.php (cadet last name
 * + birthday + a parent's or the cadet's email on file, via
 * find_member_by_identity() in admin/lib.php).
 *
 *   GET  ?event=ID                 → the event's public details (or closed)
 *   POST {action:'lookup', ...}    → verify the family, issue a token
 *   POST {action:'save',   ...}    → add/update their sign-up (token)
 *   POST {action:'cancel', ...}    → remove their sign-up (token)
 *
 * Save/cancel trust only the per-lookup token in the verification session
 * (never a resubmitted member id), and unlike update-handler.php the token
 * is NOT consumed on use, so a family can save and then change or cancel
 * on the same visit. No CSRF token here — the session token plays that
 * role, the same way update.html's flow does.
 *
 * Sign-ups live in event_signups (keyed by member_id), separate from the
 * portal's event_rsvps (keyed by user_id); admin/event-rsvps.php merges
 * both into one list per event.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: https://alabamafalcons.org');

require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/admin/form-guard.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit();
}

$pdo = get_pdo();

function signup_event_payload(array $e): array {
    return [
        'id'          => (int)$e['id'],
        'title'       => (string)$e['title'],
        'date'        => $e['event_date'] ? date('l, F j, Y', strtotime($e['event_date'])) : '',
        'dateEnd'     => ($e['event_date_end'] && $e['event_date_end'] !== $e['event_date']) ? date('l, F j, Y', strtotime($e['event_date_end'])) : '',
        'time'        => (string)($e['event_time'] ?? ''),
        'location'    => (string)($e['location'] ?? ''),
        'description' => (string)($e['description'] ?? ''),
    ];
}

const SIGNUP_CLOSED_MSG = 'Sign-ups for this event are closed, or this link is no longer valid. Check the Club Events section on alabamafalcons.org or contact secretary@alabamafalcons.org.';

// ── GET: event details ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $event = signup_open_event($pdo, (int)($_GET['event'] ?? 0));
    if (!$event) {
        echo json_encode(['success' => false, 'error' => SIGNUP_CLOSED_MSG]);
        exit();
    }
    echo json_encode(['success' => true, 'event' => signup_event_payload($event)]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request data.']);
    exit();
}

$action = (string)($payload['action'] ?? '');
start_verification_session();
if (!isset($_SESSION['event_signup_verified']) || !is_array($_SESSION['event_signup_verified'])) {
    $_SESSION['event_signup_verified'] = [];
}

// ── lookup ───────────────────────────────────────────────────────────────
if ($action === 'lookup') {
    $not_found = "We couldn't find a matching record. Please double-check the cadet's last name, birthday, and the email on file, or contact secretary@alabamafalcons.org.";

    if (honeypot_tripped($payload)) {
        echo json_encode(['success' => false, 'error' => $not_found]);
        exit();
    }
    if (rate_limited($pdo, 'event_signup_lookup', 10, 15)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many attempts from your network. Please try again later or email secretary@alabamafalcons.org.']);
        exit();
    }

    $event = signup_open_event($pdo, (int)($payload['eventId'] ?? 0));
    if (!$event) {
        echo json_encode(['success' => false, 'error' => SIGNUP_CLOSED_MSG]);
        exit();
    }

    $last     = trim((string)($payload['cadetLastName'] ?? ''));
    $birthday = trim((string)($payload['cadetBirthday'] ?? ''));
    $email    = trim((string)($payload['email'] ?? ''));
    if ($last === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Please enter the cadet's last name, birthday, and the email address on file."]);
        exit();
    }

    $m = find_member_by_identity($pdo, $last, $birthday, $email);
    if (!$m) {
        echo json_encode(['success' => false, 'error' => $not_found]);
        exit();
    }

    // Prune expired tokens so the pool doesn't grow across a long session.
    foreach ($_SESSION['event_signup_verified'] as $t => $entry) {
        if (($entry['expires'] ?? 0) < time()) unset($_SESSION['event_signup_verified'][$t]);
    }
    $token = bin2hex(random_bytes(24));
    $_SESSION['event_signup_verified'][$token] = [
        'member_id' => (int)$m['id'],
        'event_id'  => (int)$event['id'],
        'email'     => $email,
        'expires'   => time() + 1800, // 30 minutes
    ];

    $existing = null;
    try {
        $s = $pdo->prepare('SELECT attendee_count, note FROM event_signups WHERE event_id = ? AND member_id = ?');
        $s->execute([(int)$event['id'], (int)$m['id']]);
        if ($row = $s->fetch(PDO::FETCH_ASSOC)) {
            $existing = ['count' => (int)$row['attendee_count'], 'note' => (string)($row['note'] ?? '')];
        }
    } catch (PDOException $e) {
        // migrate_event_signups.sql not run yet — the save below reports it.
    }

    echo json_encode([
        'success'  => true,
        'token'    => $token,
        'family'   => member_family_label($m),
        'existing' => $existing,
    ]);
    exit();
}

// ── save / cancel (token-gated) ──────────────────────────────────────────
if ($action === 'save' || $action === 'cancel') {
    $token = (string)($payload['token'] ?? '');
    $entry = $_SESSION['event_signup_verified'][$token] ?? null;
    if ($token === '' || !$entry || ($entry['expires'] ?? 0) < time()) {
        echo json_encode(['success' => false, 'error' => 'Your session expired. Please look up your family again.']);
        exit();
    }
    // Re-checked on every save so a link can't keep collecting sign-ups
    // after the event closes mid-visit.
    $event = signup_open_event($pdo, (int)$entry['event_id']);
    if (!$event) {
        echo json_encode(['success' => false, 'error' => SIGNUP_CLOSED_MSG]);
        exit();
    }
    $member_id = (int)$entry['member_id'];

    try {
        if ($action === 'cancel') {
            $pdo->prepare('DELETE FROM event_signups WHERE event_id = ? AND member_id = ?')
                ->execute([(int)$event['id'], $member_id]);
            echo json_encode(['success' => true, 'cancelled' => true]);
            exit();
        }

        $count = (int)($payload['count'] ?? 0);
        if ($count < 1 || $count > 20) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please enter how many people are coming (1–20).']);
            exit();
        }
        $note = mb_substr(trim((string)($payload['note'] ?? '')), 0, 255);

        $pdo->prepare(
            'INSERT INTO event_signups (event_id, member_id, attendee_count, note, signup_email)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE attendee_count = VALUES(attendee_count), note = VALUES(note),
                                     signup_email = VALUES(signup_email), updated_at = NOW()'
        )->execute([(int)$event['id'], $member_id, $count, $note !== '' ? $note : null, $entry['email']]);
    } catch (PDOException $e) {
        error_log('event-signup: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => "Sign-ups aren't available right now. Please email secretary@alabamafalcons.org to RSVP."]);
        exit();
    }

    // Confirmation to the address they verified with. Best-effort only —
    // the sign-up is already saved either way.
    try {
        require_once __DIR__ . '/admin/mailer.php';
        $ev   = signup_event_payload($event);
        $when = trim($ev['date'] . ($ev['dateEnd'] ? ' – ' . $ev['dateEnd'] : '') . ($ev['time'] ? ', ' . $ev['time'] : ''));
        $link = SITE_URL . 'event-signup.html?event=' . (int)$event['id'];
        $body = CLUB_NAME . "\n"
              . "Event Sign-Up Confirmation\n"
              . str_repeat('─', 48) . "\n\n"
              . "You're on the list for {$ev['title']}!\n\n"
              . ($when !== '' ? "  When:      $when\n" : '')
              . ($ev['location'] !== '' ? "  Where:     {$ev['location']}\n" : '')
              . "  Attending: $count\n"
              . ($note !== '' ? "  Note:      $note\n" : '')
              . "\nNeed to change your headcount or cancel? Use the same link again:\n$link\n\n"
              . "Questions? Contact secretary@alabamafalcons.org.\n\n"
              . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . SITE_URL;
        send_notification($entry['email'], "You're signed up: {$ev['title']}", $body);
    } catch (\Throwable $e) {
        error_log('event-signup: confirmation email failed — ' . $e->getMessage());
    }

    echo json_encode(['success' => true, 'count' => $count]);
    exit();
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action.']);

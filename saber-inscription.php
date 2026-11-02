<?php
/**
 * Public Saber Inscription (saber-inscription.html)
 * Lets the family of a graduating cadet (the active Saber Fund campaign's
 * class year) enter what should be engraved on the cadet's saber, without
 * a portal login. Same identity check as update-lookup.php and
 * event-signup.php (cadet last name + birthday + a parent's or the cadet's
 * email on file, via find_member_by_identity() in admin/lib.php).
 *
 *   GET                         → campaign year, open/closed, max length
 *   POST {action:'lookup', ...} → verify the family, issue a token
 *   POST {action:'save',   ...} → save their inscription (token)
 *
 * Save trusts only the per-lookup token in the verification session (never
 * a resubmitted member id). Like event-signup.php the token is NOT consumed
 * on use, so a family can save, spot a typo, and fix it on the same visit.
 * No CSRF token — the session token plays that role.
 *
 * Officers see, edit, open/close, and print these from the Saber
 * Inscriptions section of admin/fundraiser.php.
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

const INSCRIPTION_UNAVAILABLE_MSG = "Saber inscriptions aren't being collected right now. Questions? Contact treasurer@alabamafalcons.org.";
const INSCRIPTION_CLOSED_MSG = 'Saber inscriptions are closed — the order is being placed. To request a change, contact treasurer@alabamafalcons.org.';

// ── GET: campaign status ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $c = saber_inscription_campaign($pdo);
    if (!$c) {
        echo json_encode(['success' => false, 'error' => INSCRIPTION_UNAVAILABLE_MSG]);
        exit();
    }
    echo json_encode(['success' => true, 'year' => $c['year'], 'open' => $c['open'], 'max' => SABER_INSCRIPTION_MAX]);
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
if (!isset($_SESSION['saber_inscription_verified']) || !is_array($_SESSION['saber_inscription_verified'])) {
    $_SESSION['saber_inscription_verified'] = [];
}

// ── lookup ───────────────────────────────────────────────────────────────
if ($action === 'lookup') {
    $not_found = "We couldn't find a matching record. Please double-check the cadet's last name, birthday, and the email on file, or contact secretary@alabamafalcons.org.";

    if (honeypot_tripped($payload)) {
        echo json_encode(['success' => false, 'error' => $not_found]);
        exit();
    }
    if (rate_limited($pdo, 'saber_inscription_lookup', 10, 15)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many attempts from your network. Please try again later or email secretary@alabamafalcons.org.']);
        exit();
    }

    $c = saber_inscription_campaign($pdo);
    if (!$c) {
        echo json_encode(['success' => false, 'error' => INSCRIPTION_UNAVAILABLE_MSG]);
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
    // Checked after the full 3-part match (not as a lookup filter) so a
    // family whose record has the wrong class year gets told why, instead
    // of a misleading "no matching record".
    $on_file = trim((string)($m['class_year'] ?? ''));
    if ($on_file !== $c['year']) {
        echo json_encode(['success' => false, 'error' => "This form is only for families of Class of {$c['year']} cadets, and our records show your cadet in the Class of " . ($on_file !== '' ? $on_file : '(not set)') . ". If that's wrong, please update it at alabamafalcons.org/update.html or email secretary@alabamafalcons.org."]);
        exit();
    }

    // Prune expired tokens so the pool doesn't grow across a long session.
    foreach ($_SESSION['saber_inscription_verified'] as $t => $entry) {
        if (($entry['expires'] ?? 0) < time()) unset($_SESSION['saber_inscription_verified'][$t]);
    }
    $token = bin2hex(random_bytes(24));
    $_SESSION['saber_inscription_verified'][$token] = [
        'member_id' => (int)$m['id'],
        'slug'      => $c['slug'],
        'email'     => $email,
        'expires'   => time() + 1800, // 30 minutes
    ];

    $existing = null;
    try {
        $s = $pdo->prepare('SELECT inscription FROM saber_inscriptions WHERE campaign_slug = ? AND member_id = ?');
        $s->execute([$c['slug'], (int)$m['id']]);
        $val = $s->fetchColumn();
        if ($val !== false) $existing = (string)$val;
    } catch (PDOException $e) {
        // migrate_saber_inscriptions.sql not run yet — the save below reports it.
    }

    echo json_encode([
        'success'  => true,
        'token'    => $token,
        'family'   => member_family_label($m),
        'cadet'    => cadet_full_name($m),
        'existing' => $existing,
        'open'     => $c['open'],
    ]);
    exit();
}

// ── save (token-gated) ───────────────────────────────────────────────────
if ($action === 'save') {
    $token = (string)($payload['token'] ?? '');
    $entry = $_SESSION['saber_inscription_verified'][$token] ?? null;
    if ($token === '' || !$entry || ($entry['expires'] ?? 0) < time()) {
        echo json_encode(['success' => false, 'error' => 'Your session expired. Please look up your family again.']);
        exit();
    }
    // Re-checked on every save so a family can't keep editing after an
    // officer closes inscriptions (or switches campaigns) mid-visit.
    $c = saber_inscription_campaign($pdo);
    if (!$c || $c['slug'] !== $entry['slug']) {
        echo json_encode(['success' => false, 'error' => INSCRIPTION_UNAVAILABLE_MSG]);
        exit();
    }
    if (!$c['open']) {
        echo json_encode(['success' => false, 'error' => INSCRIPTION_CLOSED_MSG]);
        exit();
    }

    $inscription = clean_saber_inscription((string)($payload['inscription'] ?? ''));
    if ($err = saber_inscription_error($inscription)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $err]);
        exit();
    }

    $member_id = (int)$entry['member_id'];
    try {
        $m = $pdo->prepare('SELECT * FROM members WHERE id = ? AND archived = 0');
        $m->execute([$member_id]);
        $member = $m->fetch(PDO::FETCH_ASSOC);
        if (!$member || trim((string)$member['class_year']) !== $c['year']) {
            echo json_encode(['success' => false, 'error' => INSCRIPTION_UNAVAILABLE_MSG]);
            exit();
        }
        save_saber_inscription($pdo, $c['slug'], $member_id, $inscription, $entry['email'], 'Parent');
    } catch (PDOException $e) {
        error_log('saber-inscription: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => "Inscriptions can't be saved right now. Please email treasurer@alabamafalcons.org with your cadet's name and the inscription."]);
        exit();
    }

    send_json_and_continue(['success' => true, 'inscription' => $inscription]);

    // Confirmation to the address they verified with, so the family has a
    // written copy of exactly what was submitted. Best-effort only — the
    // inscription is already saved either way.
    try {
        require_once __DIR__ . '/admin/mailer.php';
        $cadet = cadet_full_name($member);
        $link  = SITE_URL . 'saber-inscription.html';
        $body = CLUB_NAME . "\n"
              . "Saber Inscription Received\n"
              . str_repeat('─', 48) . "\n\n"
              . "We received the saber inscription for Cadet {$cadet} (Class of {$c['year']}):\n\n"
              . "    {$inscription}\n\n"
              . "Please check the spelling, capitalization, and punctuation carefully — it\n"
              . "will be engraved exactly as shown above.\n\n"
              . "Need to fix something? Use the same page again before inscriptions close:\n$link\n\n"
              . "Questions? Contact treasurer@alabamafalcons.org.\n\n"
              . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . SITE_URL;
        send_notification($entry['email'], "Saber inscription received: Cadet {$cadet}", $body);
    } catch (\Throwable $e) {
        error_log('saber-inscription: confirmation email failed — ' . $e->getMessage());
    }
    exit();
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action.']);

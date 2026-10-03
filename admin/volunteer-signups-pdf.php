<?php
/**
 * Volunteer Opportunity — printable sign-up roster (PDF)
 * One opportunity's volunteers in sign-up order: name, linked cadet, email,
 * phone, and a blank check-in box for the day of. Linked from each
 * opportunity on admin/volunteer-opportunities.php. Layout lives in
 * lib/volunteer-roster-pdf.php.
 */
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();
require_once __DIR__ . '/lib/volunteer-roster-pdf.php';

$id = (int)($_GET['id'] ?? 0);
$s = $pdo->prepare('SELECT * FROM volunteer_opportunities WHERE id = ?'); // * so event_time comes along once it exists
$s->execute([$id]);
$opp = $s->fetch(PDO::FETCH_ASSOC);
if (!$opp) {
    http_response_code(404);
    exit('Volunteer opportunity not found.');
}

// Family record for each sign-up: a homepage claim links it directly
// (volunteer_signups.member_id, once that migration has run); a portal
// sign-up links through the user's own users.member_id. Either may be
// missing — those rows just print without a cadet/phone.
$has_signup_member = (bool)$pdo->query("SHOW COLUMNS FROM volunteer_signups LIKE 'member_id'")->fetch();
$has_user_member   = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'member_id'")->fetch();
$member_expr = $has_signup_member && $has_user_member ? 'COALESCE(s.member_id, u.member_id)'
             : ($has_signup_member ? 's.member_id' : ($has_user_member ? 'u.member_id' : 'NULL'));

$q = $pdo->prepare(
    "SELECT COALESCE(u.name, s.guest_name) AS name, COALESCE(u.email, s.guest_email) AS email,
            m.cadet_first_name, m.cadet_middle_name, m.cadet_last_name, m.cadet_suffix, m.class_year,
            m.parent1_email, m.parent1_cell, m.parent2_email, m.parent2_cell, m.cadet_email, m.cadet_cell
     FROM volunteer_signups s
     LEFT JOIN users u ON u.id = s.user_id
     LEFT JOIN members m ON m.id = $member_expr
     WHERE s.opportunity_id = ?
     ORDER BY s.signed_up_at ASC"
);
$q->execute([$id]);

$rows = [];
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $email = strtolower(trim((string)$r['email']));
    // Phone for whoever on the family record owns this email; else parent 1's.
    $phone = '';
    if (!empty($r['cadet_last_name'])) {
        if ($email !== '' && $email === strtolower((string)$r['parent2_email'])) $phone = (string)$r['parent2_cell'];
        elseif ($email !== '' && $email === strtolower((string)$r['cadet_email'])) $phone = (string)$r['cadet_cell'];
        else $phone = (string)$r['parent1_cell'];
    }
    $cadet = !empty($r['cadet_last_name'])
        ? cadet_full_name($r) . (!empty($r['class_year']) ? ' (' . $r['class_year'] . ')' : '')
        : 'Guest';
    $rows[] = ['name' => (string)$r['name'], 'cadet' => $cadet, 'email' => $email, 'phone' => trim($phone)];
}

$slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($opp['title'])), '-') ?: 'volunteers';
build_volunteer_roster_pdf($opp, $rows)->Output('I', 'volunteers-' . $slug . '.pdf', true);

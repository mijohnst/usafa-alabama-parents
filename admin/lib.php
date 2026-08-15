<?php
// Tiny dependency-free helpers shared by both the authenticated admin panel
// (auth.php) and the public-facing form handlers (e.g. membership-handler.php)
// that intentionally don't pull in the full admin bootstrap.

// Strips punctuation/whitespace differences so "Jimmerson, Jr" and
// "Jimmerson, Jr." compare equal for duplicate-member detection.
function normalize_name(string $s): string {
    $s = preg_replace('/[.,]/', '', $s);
    return strtolower(trim(preg_replace('/\s+/', ' ', $s)));
}

// Drops a trailing suffix token from an already-normalize_name()'d last
// name, so a legacy record where the suffix is still crammed into
// cadet_last_name (e.g. "jimmerson jr", from before cadet_suffix existed)
// compares equal to the same family's clean "jimmerson" + a separate
// suffix field. Used wherever a submitted/looked-up last name is matched
// against what's on file, so old dirty data doesn't silently mismatch.
function strip_name_suffix(string $normalized): string {
    return trim(preg_replace('/\s+(jr|sr|ii|iii|iv|v)$/i', '', $normalized));
}

// Starts a session under its own cookie name, distinct from the admin
// panel's 'usafa_admin' session (auth.php's start_session()) — used to bind
// a successful public-form identity lookup (see update-lookup.php) to the
// specific browser that performed it, so update-handler.php doesn't have to
// trust resubmitted last-name/year/email values that could otherwise be
// fabricated or replayed directly against that endpoint without ever
// passing a real lookup. Deliberately a separate cookie so a board member
// browsing the public site in one tab while logged into /admin/ in another
// never shares session state between the two.
function start_verification_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('usafa_verify');
        $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => $is_https]);
        session_start();
    }
}

// Cadet's full name — "First Middle Last Suffix", whitespace-collapsed.
// Built in one place so a display site (or an automated email — this file
// is required directly by the cron entry point, which never loads
// auth.php) can't forget cadet_suffix the way over a dozen separately
// hand-rolled concatenations did before this existed. Accepts any array
// with the usual cadet_* keys (a `members` row, or a narrower SELECT that
// still includes them).
function cadet_full_name(array $m): string {
    return trim(preg_replace('/\s+/', ' ',
        ($m['cadet_first_name'] ?? '') . ' ' . ($m['cadet_middle_name'] ?? '') . ' ' .
        ($m['cadet_last_name'] ?? '') . ' ' . ($m['cadet_suffix'] ?? '')
    ));
}

// "Last Suffix" — the leading half of the "Last, First Middle" display
// convention used in tables, headings, and dropdowns throughout admin/.
function cadet_last_name_suffixed(array $m): string {
    return trim(($m['cadet_last_name'] ?? '') . ' ' . ($m['cadet_suffix'] ?? ''));
}

// [he/she, him/her, his/her] for the {he_she}/{him_her}/{his_her} placeholders
// in automated emails. Falls back to gender-neutral "they/them/their" when
// cadet_gender is blank, so older profiles that predate the field (or simply
// leave it unset) still produce a grammatical email instead of a blank spot.
function cadet_pronouns(string $gender): array {
    switch (strtolower(trim($gender))) {
        case 'male':   return ['he', 'him', 'his'];
        case 'female': return ['she', 'her', 'her'];
        default:       return ['they', 'them', 'their'];
    }
}

// Class year that graduates this club year (spring commencement) — the
// "outgoing" class. The club year runs July-June, so this stays the same
// value from July through the following June, then rolls over.
function outgoing_class_year(): string {
    $m = (int)date('n'); $y = (int)date('Y');
    return (string)($m >= 7 ? $y : $y - 1);
}

// The 4 currently-enrolled undergrad class years (e.g. ['2027','2028','2029','2030']
// from July 2026 through June 2027), computed from today's date so class-year
// lists never need manual upkeep as classes graduate each summer. Lives here
// (not auth.php) so the cron entry point can filter on it too.
function current_class_years(): array {
    $base = (int)outgoing_class_year();
    return [(string)($base+1), (string)($base+2), (string)($base+3), (string)($base+4)];
}

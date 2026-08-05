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

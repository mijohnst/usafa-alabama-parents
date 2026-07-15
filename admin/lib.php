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

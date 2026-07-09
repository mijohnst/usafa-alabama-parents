<?php
/**
 * Daily Automated Emails
 * Runs every enabled automated email check in one pass: cadet birthdays,
 * dues renewal reminders, meeting reminders, new-member welcome follow-ups,
 * and lapsed-member re-engagement. Each is individually toggled on/off from
 * admin/automated-emails.php — this script always runs all of them and lets
 * the enabled flag in the database decide what actually sends.
 *
 * Run via cron every day:
 *   0 8 * * * php /home/alabkmgg/public_html/admin/automated-emails-cron.php
 *
 * If you already have a cron entry for admin/birthday-wishes.php, you can
 * repoint it to this script instead (recommended, one cron for everything)
 * or leave it running alongside this one — birthday sends are idempotent
 * either way, so nothing will double-send.
 */

// Block direct browser access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script runs via cron only.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES => true]
    );
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}

$results = [
    'Birthdays'             => send_birthday_emails($pdo),
    'Dues renewals'         => send_dues_renewal_reminders($pdo),
    'Meeting reminders'     => send_meeting_reminders($pdo),
    'New member welcomes'   => send_new_member_welcome($pdo),
    'Lapsed re-engagements' => send_lapsed_reengagement($pdo),
];

echo date('Y-m-d H:i:s') . " — Automated emails:\n";
foreach ($results as $label => $count) {
    echo "  $label: $count\n";
}

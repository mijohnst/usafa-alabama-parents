<?php
/**
 * Monthly Birthday Report
 * Run via cron on the 1st of each month:
 *   0 8 1 * * php /home/alabkmgg/public_html/admin/birthday-report.php
 */

// Block direct browser access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script runs via cron only.');
}

require_once __DIR__ . '/config.php';

$month      = (int)date('n');
$month_name = date('F');
$year       = (int)date('Y');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES => true]
    );

    $stmt = $pdo->prepare(
        "SELECT cadet_last_name, cadet_suffix, cadet_first_name, cadet_middle_name, cadet_birthday, cadet_po_box, class_year
         FROM members
         WHERE archived = 0
           AND cadet_birthday IS NOT NULL
           AND cadet_birthday != ''
           AND MONTH(cadet_birthday) = ?
         ORDER BY DAY(cadet_birthday), cadet_last_name"
    );
    $stmt->execute([$month]);
    $cadets = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}

// Build email body
$subject = "Cadet Birthdays — $month_name $year";

$body  = "USAFA Parents Club of Alabama\n";
$body .= "Cadet Birthdays for $month_name $year\n";
$body .= str_repeat('=', 48) . "\n\n";

if (empty($cadets)) {
    $body .= "No cadets have birthdays in $month_name.\n";
} else {
    foreach ($cadets as $c) {
        $name = trim(preg_replace('/\s+/', ' ', $c['cadet_first_name'] . ' ' . $c['cadet_middle_name'] . ' ' . $c['cadet_last_name'] . ' ' . ($c['cadet_suffix'] ?? '')));
        $day  = $c['cadet_birthday'] ? date('F j', strtotime($c['cadet_birthday'])) : '—';
        $box  = $c['cadet_po_box'] ?: 'No PO Box on file';
        $body .= "$day\n";
        $body .= "  $name (Class of {$c['class_year']})\n";
        $body .= "  Mailing address:\n";
        $body .= "    Cadet $name\n";
        if ($c['cadet_po_box']) {
            $body .= "    P.O. Box {$c['cadet_po_box']}\n";
            $body .= "    USAF Academy, CO 80841-{$c['cadet_po_box']}\n";
        } else {
            $body .= "    (No PO Box on file — check member record)\n";
        }
        $body .= "\n";
    }
    $body .= str_repeat('-', 48) . "\n";
    $body .= count($cadets) . " cadet(s) have birthdays in $month_name $year.\n";
}

$body .= "\n— USAFA Parents Club of Alabama Admin System\n";
$body .= "   alabamafalcons.org\n";

$headers  = "From: USAFA Parents Club Admin <info@alabamafalcons.org>\r\n";
$headers .= "Reply-To: info@alabamafalcons.org\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

$sent = mail('info@alabamafalcons.org', $subject, $body, $headers);

echo date('Y-m-d H:i:s') . " — Birthday report for $month_name $year: "
   . count($cadets) . " cadet(s). Mail " . ($sent ? "sent." : "FAILED.") . "\n";

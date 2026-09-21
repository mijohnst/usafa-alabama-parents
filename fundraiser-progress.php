<?php
/**
 * Public Fundraiser Progress
 * Read-only, no side effects — computes the live "$X raised of $Y goal"
 * total server-side so it can never be spoofed from the client. Total =
 * captured PayPal donations tagged with this campaign (paypal_donations,
 * the same table donate-create-order.php/donate-capture-order.php already
 * maintain) plus a treasurer-entered "offline" figure (checks/Zelle/cash
 * for this same drive) stored in site_settings and edited via
 * admin/fundraiser.php.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: https://alabamafalcons.org');

require_once __DIR__ . '/admin/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit();
}

$pdo = get_pdo();

// No campaign specified resolves to whichever one is currently active —
// this is how fundraiser.html tracks "this year's" drive without a
// hardcoded slug baked into the page: it just asks for progress with no
// campaign param and gets back whichever campaign admin/fundraiser.php has
// marked active, including which slug that actually is (see 'campaign' in
// the response below) so the page can tag the donation it creates with it.
$campaign = trim((string)($_GET['campaign'] ?? ''));
$campaigns = donation_campaigns($pdo);
$active_slug = active_campaign_slug($pdo);
if ($campaign === '') {
    $campaign = $active_slug ?? '';
}
if ($campaign === '' || !isset($campaigns[$campaign])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown campaign.']);
    exit();
}

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0), COUNT(*) FROM paypal_donations WHERE campaign = ? AND status = 'captured'");
$stmt->execute([$campaign]);
list($raised_online, $donor_count) = $stmt->fetch(PDO::FETCH_NUM);
$raised_online = (float)$raised_online;
$donor_count   = (int)$donor_count;

$offline_key  = "fundraiser_{$campaign}_offline_raised";
$year_key     = "fundraiser_{$campaign}_year";
$deadline_key = "fundraiser_{$campaign}_deadline";
$all_keys     = [$offline_key, $year_key, $deadline_key];
$stmt = $pdo->prepare('SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN (' . implode(',', array_fill(0, count($all_keys), '?')) . ')');
$stmt->execute($all_keys);
$vals = [];
foreach ($stmt->fetchAll() as $r) $vals[$r['setting_key']] = $r['setting_value'];
// Defensive casts: a stray non-numeric setting_value degrades to 0 rather
// than crashing this public endpoint. year/deadline are just strings the
// page displays directly, so they default to blank if not set yet (an
// older campaign, or migrate_saber_fund_year.sql not run yet) rather than
// erroring — fundraiser.html falls back to its own hardcoded copy in that case.
$offline  = (float)($vals[$offline_key] ?? 0);
$year     = $vals[$year_key] ?? '';
$deadline = $vals[$deadline_key] ?? '';
// A plain Treasurer-set target (see campaign_cadet_count_and_goal() in
// admin/lib.php) — the whole graduating class this fund covers, not a count
// of paid members. fundraiser.html derives its displayed cadet count back
// out of this same goal (goal / SABER_PRICE), so nothing else needs to
// change there.
[, $goal] = campaign_cadet_count_and_goal($pdo, $campaign);

$raised_total = $raised_online + $offline;

// Recent donations wall (name/comment/amount, newest first). Wrapped
// separately from the totals query above so a pre-migration database
// (donor_comment/show_name columns not added yet) just yields an empty
// list here rather than breaking the progress totals the whole page
// depends on.
$recent = [];
try {
    $stmt = $pdo->prepare(
        "SELECT donor_name, donor_comment, show_name, amount, captured_at
         FROM paypal_donations
         WHERE campaign = ? AND status = 'captured'
         ORDER BY captured_at DESC
         LIMIT 8"
    );
    $stmt->execute([$campaign]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = trim((string)($row['donor_name'] ?? ''));
        if ((int)($row['show_name'] ?? 1) === 0) {
            $display_name = 'Anonymous';
        } else {
            $display_name = $name !== '' ? $name : 'A Generous Donor';
        }
        $recent[] = [
            'name'    => $display_name,
            'comment' => $row['donor_comment'] ?? '',
            'amount'  => round((float)$row['amount'], 2),
        ];
    }
} catch (\PDOException $e) {
    error_log('fundraiser-progress: recent donations query failed (migration not run?) — ' . $e->getMessage());
}

echo json_encode([
    'success'       => true,
    'campaign'      => $campaign,
    'raisedOnline'  => round($raised_online, 2),
    'raisedOffline' => round($offline, 2),
    'raisedTotal'   => round($raised_total, 2),
    'goal'          => round($goal, 2),
    'pct'           => $goal > 0 ? min(100, round($raised_total / $goal * 100)) : 0,
    'donorCount'    => $donor_count,
    'goalReached'   => $goal > 0 && $raised_total >= $goal,
    'recent'        => $recent,
    'year'          => $year,
    'deadline'      => $deadline,
]);

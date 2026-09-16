<?php
/**
 * Public Fundraiser — In-Honor-Of Cadet List
 * Read-only, no side effects. Returns the last names of paid members'
 * cadets in the campaign's target class year, for the optional "in honor
 * of" dropdown on the donation form. Last name only, by design — this
 * powers a per-cadet donor list handed out with each saber, not a public
 * display, so it never needs (and shouldn't expose) anything more than that.
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

// Same campaign-resolution pattern as fundraiser-progress.php — no
// campaign param resolves to whichever one is currently active.
$campaign  = trim((string)($_GET['campaign'] ?? ''));
$campaigns = donation_campaigns($pdo);
if ($campaign === '') {
    $campaign = active_campaign_slug($pdo) ?? '';
}
if ($campaign === '' || !isset($campaigns[$campaign])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown campaign.']);
    exit();
}

$year_stmt = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ?');
$year_stmt->execute(["fundraiser_{$campaign}_year"]);
$year = trim((string)$year_stmt->fetchColumn());

// campaign_eligible_cadets() (admin/lib.php) is the one place "who counts"
// lives — also used by campaign_paid_cadet_count() and re-checked again in
// donate-create-order.php when a donor actually submits an honoree id, so
// this list and that validation can never silently disagree.
$options = [];
foreach (campaign_eligible_cadets($pdo, $year) as $id => $lastName) {
    if (trim((string)$lastName) === '') continue;
    $options[] = ['id' => (int)$id, 'lastName' => $lastName];
}

echo json_encode(['success' => true, 'options' => $options]);

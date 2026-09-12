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

$campaign = trim((string)($_GET['campaign'] ?? ''));
if (!isset(DONATION_CAMPAIGNS[$campaign])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown campaign.']);
    exit();
}

$pdo = get_pdo();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM paypal_donations WHERE campaign = ? AND status = 'captured'");
$stmt->execute([$campaign]);
$raised_online = (float)$stmt->fetchColumn();

$goal_key    = "fundraiser_{$campaign}_goal";
$offline_key = "fundraiser_{$campaign}_offline_raised";
$stmt = $pdo->prepare('SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN (?, ?)');
$stmt->execute([$goal_key, $offline_key]);
$vals = [];
foreach ($stmt->fetchAll() as $r) $vals[$r['setting_key']] = $r['setting_value'];
// Defensive casts: a stray non-numeric setting_value degrades to 0 rather
// than crashing this public endpoint.
$goal    = (float)($vals[$goal_key] ?? 0);
$offline = (float)($vals[$offline_key] ?? 0);

$raised_total = $raised_online + $offline;

echo json_encode([
    'success'       => true,
    'campaign'      => $campaign,
    'raisedOnline'  => round($raised_online, 2),
    'raisedOffline' => round($offline, 2),
    'raisedTotal'   => round($raised_total, 2),
    'goal'          => round($goal, 2),
    'pct'           => $goal > 0 ? min(100, round($raised_total / $goal * 100)) : 0,
]);

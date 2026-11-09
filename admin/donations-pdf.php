<?php
/**
 * Online Donations — PDF Download
 * Every captured online donation for a campaign (not just the 25 shown in
 * "Recent Online Donations" on admin/fundraiser.php), with donor email,
 * public/anonymous choice, honoree and comment, plus a total. Same access as
 * fundraiser.php. Rendering lives in lib/donations-pdf.php.
 */
require_once __DIR__ . '/auth.php';
require_finance();
$pdo = get_pdo();
require_once __DIR__ . '/lib/donations-pdf.php';

$campaigns = donation_campaigns($pdo);
$campaign  = trim((string)($_GET['campaign'] ?? ''));
if (!isset($campaigns[$campaign])) {
    $campaign = active_campaign_slug($pdo) ?? '';
}
if ($campaign === '') {
    flash('error', 'No fundraiser campaign to print donations for.');
    header('Location: fundraiser.php'); exit;
}

$rows  = campaign_donations($pdo, $campaign);
$title = saber_fund_label($pdo, $campaign, $campaigns[$campaign]) . ' — Online Donations';
$pdf   = build_donations_pdf($title, $rows);
$pdf->Output('D', 'Online-Donations-' . preg_replace('/[^A-Za-z0-9-]+/', '-', $campaign) . '-' . date('Y-m-d') . '.pdf');

<?php
/**
 * Saber Inscriptions — PDF Download
 * Prints a campaign's saber inscriptions (collected on
 * saber-inscription.html, managed on admin/fundraiser.php) to send in with
 * the saber order. ?layout=sheet (default) is one table of every cadet;
 * ?layout=slips is one page per cadet. Rendering lives in
 * lib/saber-inscriptions-pdf.php.
 */
require_once __DIR__ . '/auth.php';
require_login();
if (!can_manage_saber_inscriptions()) {
    header('Location: index.php?denied=1');
    exit;
}
$pdo = get_pdo();
require_once __DIR__ . '/lib/saber-inscriptions-pdf.php';

$campaigns = donation_campaigns($pdo);
$campaign  = trim((string)($_GET['campaign'] ?? ''));
if (!isset($campaigns[$campaign])) {
    $campaign = active_campaign_slug($pdo) ?? '';
}
if ($campaign === '') {
    flash('error', 'No fundraiser campaign to print inscriptions for.');
    header('Location: fundraiser.php'); exit;
}
$layout = ($_GET['layout'] ?? '') === 'slips' ? 'slips' : 'sheet';

$stmt = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ?');
$stmt->execute(["fundraiser_{$campaign}_year"]);
$year = trim((string)$stmt->fetchColumn());

$roster = saber_inscription_roster($pdo, $campaign, $year);
if ($roster === null) {
    flash('error', 'Saber inscriptions are not set up yet — run migrate_saber_inscriptions.sql in phpMyAdmin first.');
    header('Location: fundraiser.php?campaign=' . urlencode($campaign)); exit;
}

$rows = [];
$missing = [];
foreach ($roster as $r) {
    $name = cadet_full_name($r);
    if ((string)($r['inscription'] ?? '') !== '') {
        $rows[] = ['cadet' => $name, 'inscription' => saber_engraving((string)$r['inscription'], $year)];
    } else {
        $missing[] = $name;
    }
}

$title = ($year !== '' ? "Class of {$year} " : '') . 'Saber Inscriptions';
$pdf = build_saber_inscriptions_pdf($title, $rows, $missing, $layout);
$pdf->Output('D', 'Saber-Inscriptions-' . ($year ?: $campaign) . ($layout === 'slips' ? '-Slips' : '') . '-' . date('Y-m-d') . '.pdf');

<?php
/**
 * Saber Inscriptions — PDF Download
 * Prints a campaign's saber inscriptions (collected on
 * saber-inscription.html, managed on admin/fundraiser.php) to send in with
 * the saber order. ?layout=sheet (default) is one table of every cadet;
 * ?layout=slips is one page per cadet. Rendering lives in
 * lib/saber-inscriptions-pdf.php. Once any cadet is marked Awarded, only
 * awarded cadets are included.
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

// Once any cadet is marked Awarded, the order covers only them — a family
// that submitted but isn't getting a saber must not end up on the order.
$any_awarded = (bool)array_filter($roster, fn($r) => (int)$r['awarded'] === 1);
if ($any_awarded) {
    $roster = array_values(array_filter($roster, fn($r) => (int)$r['awarded'] === 1));
}

$rows = [];
$missing = [];
$opted_out = [];
foreach ($roster as $r) {
    $name = cadet_full_name($r);
    if ((int)$r['opted_out'] === 1) {
        // Never on the order, even if someone also marked them Awarded.
        $opted_out[] = $name;
    } elseif ((string)($r['inscription'] ?? '') !== '') {
        $rows[] = ['cadet' => $name, 'inscription' => saber_engraving((string)$r['inscription'], $year, $r['year_position'] ?? 'after')];
    } else {
        $missing[] = $name;
    }
}

$title = ($year !== '' ? "Class of {$year} " : '') . 'Saber Inscriptions';
$pdf = build_saber_inscriptions_pdf($title, $rows, $missing, $layout, $opted_out);

// So the officer list can flag entries edited after this printout.
saber_save_setting($pdo, "fundraiser_{$campaign}_inscriptions_printed_at", 'Saber inscriptions last PDF', date('Y-m-d H:i:s'));
$pdf->Output('D', 'Saber-Inscriptions-' . ($year ?: $campaign) . ($layout === 'slips' ? '-Slips' : '') . '-' . date('Y-m-d') . '.pdf');

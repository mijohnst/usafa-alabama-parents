<?php
/**
 * Parent Letters — Print All
 * Every saved letter, one per printed page, sorted alphabetically by
 * cadet last name so a batch printout lines up with envelopes sorted
 * the same way. Same letterhead styling as the parent-facing single-letter
 * print page (parent-letters-print.php) — kept as a separate file since
 * that one is scoped to a single public verifyToken, not an admin session.
 */
require_once __DIR__ . '/auth.php';
require_login();
if (!can_manage_members() && !is_secretary() && !is_treasurer()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

$letters = $pdo->query(
    "SELECT pl.letter_body, pl.created_at,
            m.cadet_first_name, m.cadet_middle_name, m.cadet_last_name, m.cadet_suffix
     FROM parent_letters pl
     JOIN members m ON m.id = pl.member_id
     ORDER BY m.cadet_last_name ASC, m.cadet_first_name ASC, pl.created_at ASC"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>All Parent Letters — A to Z</title>
<link rel="icon" type="image/png" href="../logo01.png" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Caveat:wght@500;600&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Times New Roman',Times,serif;color:#000;background:#fff}
.letter-page{padding:0.75in;page-break-after:always}
.letter-page:last-child{page-break-after:auto}
@media print{
  .no-print{display:none!important}
  .letter-page{padding:0}
  @page{margin:0.85in}
}
.cadet-tag{font-family:sans-serif;font-size:9pt;color:#9aa5b4;margin-bottom:.5rem}
.letterhead{text-align:center;margin-bottom:1rem}
.letterhead img{width:3in;height:auto}
.club-blurb{text-align:center;font-family:'Source Sans 3',Arial,sans-serif;font-size:10pt;color:#5a6a7a;line-height:1.5;max-width:5.5in;margin:0 auto 1.6rem;padding-bottom:1.2rem;border-bottom:2px solid #003594}
.club-blurb strong{color:#002554}
.letter-date{text-align:right;margin-bottom:1.4rem;font-family:'Caveat',cursive;font-size:24pt}
.body-text{font-family:'Caveat',cursive;font-weight:500;line-height:1.5;font-size:22pt;white-space:pre-wrap}
.footer-mark{text-align:center;margin-top:3rem}
.footer-mark img{width:5.5in;height:auto}
.print-btn{position:fixed;top:1rem;right:1rem;background:#003594;color:#fff;border:none;padding:.6rem 1.2rem;border-radius:5px;font-size:14px;cursor:pointer;font-family:sans-serif;z-index:10}
.print-btn:hover{background:#002554}
.back-link{position:fixed;top:1rem;left:1rem;background:#f0f2f5;color:#002554;border:none;padding:.5rem 1rem;border-radius:5px;font-size:13px;text-decoration:none;font-family:sans-serif;z-index:10}
</style>
</head>
<body>

<a href="parent-letters.php" class="back-link no-print">← Back</a>
<button class="print-btn no-print" onclick="window.print()">🖨️ Print All / Save as PDF</button>

<?php if (empty($letters)): ?>
  <p style="font-family:sans-serif;padding:2rem">No letters saved yet.</p>
<?php endif; ?>

<?php foreach ($letters as $l):
  $cadet_name = trim($l['cadet_first_name'] . ' ' . $l['cadet_middle_name'] . ' ' . $l['cadet_last_name'] . ' ' . $l['cadet_suffix']);
  $cadet_name = preg_replace('/\s+/', ' ', $cadet_name);
  $letter_date = date('F j, Y', strtotime($l['created_at']));
?>
<div class="letter-page">
  <div class="cadet-tag no-print">For sorting only, not printed: <?= h($cadet_name) ?></div>
  <div class="letterhead">
    <img src="../logo01.png" alt="USAFA Parents Club of Alabama">
  </div>
  <div class="club-blurb">
    <strong>USAFA Parents Club of Alabama</strong> is a volunteer-run nonprofit supporting Alabama
    families with cadets at the United States Air Force Academy — care packages, events, mentorship,
    and community throughout the cadet journey. Learn more at alabamafalcons.org.
  </div>
  <div class="letter-date"><?= h($letter_date) ?></div>
  <div class="body-text"><?= h($l['letter_body']) ?></div>
  <div class="footer-mark">
    <img src="../falcon-strong-logo.png" alt="Falcon Strong — USAFA Parents Club of Alabama">
  </div>
</div>
<?php endforeach; ?>

</body>
</html>

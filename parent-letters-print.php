<?php
/**
 * Parent Letters — Print / Save as PDF
 * Renders one saved letter as a print-styled page (logo header, letter
 * body) — the parent uses the browser's own "Print → Save as PDF" to get
 * a PDF file, same approach as admin/member-letter.php uses for membership
 * letters. Scoped to the member_id bound to the verifyToken so a guessed
 * letter id from another family can't be viewed.
 */

require_once __DIR__ . '/admin/auth.php';

start_verification_session();
$token     = trim((string)($_GET['token'] ?? ''));
$letter_id = (int)($_GET['id'] ?? 0);
$verified  = $_SESSION['letters_verified'][$token] ?? null;

if (!$verified || ($verified['expires'] ?? 0) < time() || !$letter_id) {
    http_response_code(403);
    echo '<p style="font-family:sans-serif;padding:2rem">Your session expired. Please go back to the Parent Letters page and use "Find My Record" again.</p>';
    exit();
}
$member_id = (int)$verified['member_id'];

$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT pl.letter_body, pl.created_at, m.cadet_first_name
                        FROM parent_letters pl
                        JOIN members m ON m.id = pl.member_id
                        WHERE pl.id = ? AND pl.member_id = ?');
$stmt->execute([$letter_id, $member_id]);
$letter = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$letter) {
    http_response_code(404);
    echo '<p style="font-family:sans-serif;padding:2rem">That letter could not be found.</p>';
    exit();
}

$cadet_first = $letter['cadet_first_name'] ?: 'your cadet';
$letter_date = date('F j, Y', strtotime($letter['created_at']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Letter to <?= h($cadet_first) ?> — <?= h($letter_date) ?></title>
<link rel="icon" type="image/png" href="logo01.png" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Caveat:wght@500;600&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Times New Roman',Times,serif;font-size:12pt;color:#000;background:#fff;padding:0.75in}
@media print{
  body{padding:0}
  .no-print{display:none!important}
  @page{margin:0.85in}
}
.letterhead{text-align:center;margin-bottom:1.8rem}
.letterhead img{height:170px}
.letter-date{text-align:right;margin-bottom:1.4rem;font-family:'Caveat',cursive;font-size:24pt}
.body-text{font-family:'Caveat',cursive;font-weight:500;line-height:1.5;font-size:22pt;white-space:pre-wrap}
.footer-mark{text-align:center;margin-top:3rem}
.footer-mark img{height:130px}
.print-btn{position:fixed;top:1rem;right:1rem;background:#003594;color:#fff;border:none;padding:.6rem 1.2rem;border-radius:5px;font-size:14px;cursor:pointer;font-family:sans-serif}
.print-btn:hover{background:#002554}
.back-link{position:fixed;top:1rem;left:1rem;background:#f0f2f5;color:#002554;border:none;padding:.5rem 1rem;border-radius:5px;font-size:13px;text-decoration:none;font-family:sans-serif}
</style>
</head>
<body>

<a href="parent-letters.html" class="back-link no-print">← Back</a>
<button class="print-btn no-print" onclick="window.print()">🖨️ Print / Save as PDF</button>

<div class="letterhead">
  <img src="falcon-strong-logo.png" alt="Falcon Strong — USAFA Parents Club of Alabama">
</div>

<div class="letter-date"><?= h($letter_date) ?></div>

<div class="body-text"><?= h($letter['letter_body']) ?></div>

<div class="footer-mark">
  <img src="logo01.png" alt="USAFA Parents Club of Alabama">
</div>

</body>
</html>

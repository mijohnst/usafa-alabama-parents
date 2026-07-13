<?php
require_once __DIR__ . '/auth.php';
require_login();
// This page shows the submitter's own live approval status — never let a
// browser or host-level cache serve a stale "Pending" after it's changed.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$pdo     = get_pdo();
$user_id = $_SESSION['user_id'] ?? 0;

$dir = __DIR__ . '/../photo-submissions/';
if (!is_dir($dir)) mkdir($dir, 0755, true);

const MAX_PHOTOS_PER_SUBMISSION = 5;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $caption = trim($_POST['caption'] ?? '');
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    // Normalize single- and multi-file input into parallel arrays.
    $files = $_FILES['photo'] ?? [];
    if (!empty($files['name']) && !is_array($files['name'])) {
        foreach ($files as $k => $v) $files[$k] = [$v];
    }
    $names = $files['name'] ?? [];

    if (empty($names) || empty($names[0])) {
        flash('error', 'Please choose at least one photo to upload.');
        header('Location: submit-photo.php'); exit;
    }

    $count = min(count($names), MAX_PHOTOS_PER_SUBMISSION);
    $insert  = $pdo->prepare('INSERT INTO photo_submissions (user_id, filename, caption, status) VALUES (?,?,?,\'pending\')');
    $uploaded = 0; $skipped = 0;

    for ($i = 0; $i < $count; $i++) {
        if (empty($names[$i]) || $files['error'][$i] !== UPLOAD_ERR_OK) { $skipped++; continue; }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $files['tmp_name'][$i]);
        finfo_close($finfo);

        if (!isset($allowed[$mime]) || $files['size'][$i] > 10 * 1024 * 1024) { $skipped++; continue; }

        $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
        move_uploaded_file($files['tmp_name'][$i], $dir . $filename);
        $insert->execute([$user_id, $filename, $caption]);
        $uploaded++;
    }

    if (count($names) > MAX_PHOTOS_PER_SUBMISSION) $skipped += count($names) - MAX_PHOTOS_PER_SUBMISSION;

    if ($uploaded > 0) {
        $msg = "Thank you! $uploaded photo" . ($uploaded !== 1 ? 's have' : ' has') . " been submitted for review.";
        if ($skipped > 0) $msg .= " $skipped skipped (invalid file, or over the " . MAX_PHOTOS_PER_SUBMISSION . "-photo limit).";
        flash('success', $msg);
    } else {
        flash('error', 'Please use JPG, PNG, GIF, or WebP photos under 10MB.');
    }
    header('Location: submit-photo.php'); exit;
}

// Explicit column list (not SELECT *) — this table's status column is
// stored with different case than the rest (STATUS, not status); SELECT *
// returns the array key using that stored case, while naming the column
// explicitly here normalizes it to lowercase so $s['status'] is reliable.
$mine = $pdo->prepare('SELECT id, user_id, album_id, filename, caption, status, submitted_at, reviewed_by, reviewed_at FROM photo_submissions WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 10');
$mine->execute([$user_id]);
$my_submissions = $mine->fetchAll(PDO::FETCH_ASSOC);

admin_header('Submit Event Photos');
echo show_flash();
?>
<style>
.ps-list{display:flex;flex-direction:column;gap:.5rem;margin-top:1.25rem}
.ps-item{display:flex;justify-content:space-between;align-items:center;background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:.6rem .9rem;font-size:.85rem}
.ps-status{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;padding:.15rem .5rem;border-radius:99px}
</style>

<div class="page-head">
  <h1>Submit Event Photos</h1>
  <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">Got great shots from a club event? Submit them here — an officer reviews each one before it appears in the Member Photos slideshow on the homepage.</p>

<div class="card" style="max-width:520px">
  <form method="POST" enctype="multipart/form-data" id="submit-photo-form">
    <?= csrf_field() ?>
    <div class="form-group">
      <label>Photos * <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">up to <?= MAX_PHOTOS_PER_SUBMISSION ?> at once — JPG, PNG, GIF, or WebP, under 10MB each</span></label>
      <input type="file" name="photo[]" id="photo-input" accept="image/*" multiple required>
      <div id="photo-count-msg" style="font-size:.75rem;color:#c62828;margin-top:.35rem;display:none"></div>
    </div>
    <div class="form-group">
      <label>Caption <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">optional — applies to all selected photos</span></label>
      <input name="caption" placeholder="A short description">
    </div>
    <button type="submit" class="btn btn-primary" id="submit-photo-btn">Submit Photo<span id="submit-photo-plural">s</span></button>
  </form>
</div>
<script>
(function() {
  var MAX = <?= MAX_PHOTOS_PER_SUBMISSION ?>;
  var input  = document.getElementById('photo-input');
  var msg    = document.getElementById('photo-count-msg');
  var plural = document.getElementById('submit-photo-plural');
  input.addEventListener('change', function() {
    var n = input.files.length;
    plural.style.display = n === 1 ? 'none' : '';
    if (n > MAX) {
      msg.textContent = 'You selected ' + n + ' photos — only the first ' + MAX + ' will be submitted.';
      msg.style.display = 'block';
    } else {
      msg.style.display = 'none';
    }
  });
})();
</script>

<?php if (!empty($my_submissions)): ?>
<p style="font-size:.72rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.08em;margin-top:1.75rem">Your Recent Submissions</p>
<div class="ps-list">
  <?php foreach ($my_submissions as $s):
    $status = $s['status'] ?? 'pending';
    $colors = ['pending' => ['#fff3cd','#5f4c00'], 'approved' => ['#e8f5e9','#1b5e20'], 'rejected' => ['#ffebee','#c62828']];
    [$bg, $fg] = $colors[$status] ?? ['#f0f2f5','#5a6a7a'];
  ?>
  <div class="ps-item">
    <span><?= h($s['caption'] ?: '(no caption)') ?> <span style="color:#9aa5b4">— <?= date('M j, Y', strtotime($s['submitted_at'])) ?></span></span>
    <span class="ps-status" style="background:<?= $bg ?>;color:<?= $fg ?>"><?= ucfirst($status) ?></span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php admin_footer(); ?>

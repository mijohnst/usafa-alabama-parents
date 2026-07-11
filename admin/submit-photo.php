<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo     = get_pdo();
$user_id = $_SESSION['user_id'] ?? 0;

$dir = __DIR__ . '/../photo-submissions/';
if (!is_dir($dir)) mkdir($dir, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $caption  = trim($_POST['caption'] ?? '');
    $album_id = (int)($_POST['album_id'] ?? 0) ?: null;

    $file = $_FILES['photo'] ?? null;
    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Please choose a photo to upload.');
        header('Location: submit-photo.php'); exit;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    if (!isset($allowed[$mime]) || $file['size'] > 10 * 1024 * 1024) {
        flash('error', 'Please use a JPG, PNG, GIF, or WebP photo under 10MB.');
        header('Location: submit-photo.php'); exit;
    }

    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    move_uploaded_file($file['tmp_name'], $dir . $filename);

    $pdo->prepare('INSERT INTO photo_submissions (user_id, album_id, filename, caption, status) VALUES (?,?,?,?,\'pending\')')
        ->execute([$user_id, $album_id, $filename, $caption]);

    flash('success', 'Thank you! Your photo has been submitted for review.');
    header('Location: submit-photo.php'); exit;
}

$albums = $pdo->query("SELECT id, name FROM event_albums WHERE visible = 1 ORDER BY sort_order ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

$mine = $pdo->prepare('SELECT * FROM photo_submissions WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 10');
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
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">Got a great shot from a club event? Submit it here — an officer reviews before it appears in the public gallery.</p>

<div class="card" style="max-width:520px">
  <form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="form-group">
      <label>Photo * <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">JPG, PNG, GIF, or WebP, under 10MB</span></label>
      <input type="file" name="photo" accept="image/*" capture="environment" required>
    </div>
    <div class="form-group">
      <label>Which event? <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">optional</span></label>
      <select name="album_id">
        <option value="">— not sure / general —</option>
        <?php foreach ($albums as $a): ?>
          <option value="<?= $a['id'] ?>"><?= h($a['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Caption <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">optional</span></label>
      <input name="caption" placeholder="A short description">
    </div>
    <button type="submit" class="btn btn-primary">Submit Photo</button>
  </form>
</div>

<?php if (!empty($my_submissions)): ?>
<p style="font-size:.72rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.08em;margin-top:1.75rem">Your Recent Submissions</p>
<div class="ps-list">
  <?php foreach ($my_submissions as $s):
    $colors = ['pending' => ['#fff3cd','#5f4c00'], 'approved' => ['#e8f5e9','#1b5e20'], 'rejected' => ['#ffebee','#c62828']];
    [$bg, $fg] = $colors[$s['status']] ?? ['#f0f2f5','#5a6a7a'];
  ?>
  <div class="ps-item">
    <span><?= h($s['caption'] ?: '(no caption)') ?> <span style="color:#9aa5b4">— <?= date('M j, Y', strtotime($s['submitted_at'])) ?></span></span>
    <span class="ps-status" style="background:<?= $bg ?>;color:<?= $fg ?>"><?= ucfirst($s['status']) ?></span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php admin_footer(); ?>

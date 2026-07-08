<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

function get_gallery_limit(PDO $pdo): int {
    $row = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='gallery_max_photos'")->fetch();
    $val = $row ? (int)$row['setting_value'] : 20;
    return max(1, min(100, $val));
}

// ── Auto-cleanup: remove photos > 30 days old or keep max N ──────────────
function gallery_cleanup(PDO $pdo, string $dir, int $limit): void {
    // Delete photos older than 30 days
    $old = $pdo->query("SELECT id, filename FROM site_photos WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchAll();
    foreach ($old as $p) {
        if (preg_match('/^[a-zA-Z0-9._-]+$/', $p['filename'])) @unlink($dir . $p['filename']);
        $pdo->prepare('DELETE FROM site_photos WHERE id=?')->execute([$p['id']]);
    }
    // Enforce photo limit — delete oldest beyond limit
    $count = (int)$pdo->query('SELECT COUNT(*) FROM site_photos')->fetchColumn();
    if ($count > $limit) {
        $excess = $pdo->query("SELECT id, filename FROM site_photos ORDER BY id ASC LIMIT " . (int)($count - $limit))->fetchAll();
        foreach ($excess as $p) {
            if (preg_match('/^[a-zA-Z0-9._-]+$/', $p['filename'])) @unlink($dir . $p['filename']);
            $pdo->prepare('DELETE FROM site_photos WHERE id=?')->execute([$p['id']]);
        }
    }
}

$dir = __DIR__ . '/../site-photos/';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$max_photos = get_gallery_limit($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify(); $action = $_POST['action'] ?? '';

    if ($action === 'set_limit') {
        $new_limit = max(1, min(100, (int)($_POST['gallery_max_photos'] ?? 20)));
        $pdo->prepare("INSERT INTO site_settings (setting_key,setting_label,setting_value,setting_type) VALUES ('gallery_max_photos','Max photos in gallery',?,'number') ON DUPLICATE KEY UPDATE setting_value=?")->execute([$new_limit, $new_limit]);
        $max_photos = $new_limit;
        gallery_cleanup($pdo, $dir, $max_photos);
        flash('success', "Photo limit updated to $max_photos.");
        header('Location: gallery.php'); exit;

    } elseif ($action === 'upload') {
        $caption    = trim($_POST['caption']    ?? '');
        $sort       = (int)($_POST['sort_order']?? 0);
        $uploaded   = 0;
        $skipped    = 0;

        // Handle multiple files (photo[] array)
        $files = $_FILES['photo'] ?? [];
        if (!empty($files['name'])) {
            // Normalise single-file to array format
            if (!is_array($files['name'])) {
                foreach ($files as $k => $v) $files[$k] = [$v];
            }
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                if (empty($files['name'][$i]) || $files['error'][$i] !== UPLOAD_ERR_OK) continue;
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $files['tmp_name'][$i]); finfo_close($finfo);
                if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp']) || $files['size'][$i] > 10*1024*1024) {
                    $skipped++; continue;
                }
                $ext  = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$mime];
                $name = date('Ymd') . '_' . substr(date('His'), 0, 6) . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                move_uploaded_file($files['tmp_name'][$i], $dir . $name);
                $pdo->prepare('INSERT INTO site_photos (filename,caption,sort_order,active) VALUES (?,?,?,1)')
                    ->execute([$name, $caption, $sort + $i]);
                $uploaded++;
            }
        }

        // Run cleanup after upload
        gallery_cleanup($pdo, $dir, $max_photos);

        if ($uploaded > 0)  flash('success', "$uploaded photo" . ($uploaded>1?'s':'') . " uploaded." . ($skipped>0?" $skipped skipped (invalid).":''));
        elseif ($skipped > 0) flash('error', "No valid photos. Use JPG, PNG, GIF, or WebP under 10MB.");
        header('Location: gallery.php'); exit;

    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE site_photos SET caption=?,sort_order=?,active=? WHERE id=?')
            ->execute([trim($_POST['caption']??''), (int)$_POST['sort_order'], isset($_POST['active'])?1:0, $id]);
        flash('success','Photo updated.'); header('Location: gallery.php'); exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $row = $pdo->prepare('SELECT filename FROM site_photos WHERE id=?'); $row->execute([$id]); $p = $row->fetch();
        if ($p && preg_match('/^[a-zA-Z0-9._-]+$/', $p['filename'])) {
            @unlink($dir . $p['filename']);
            $pdo->prepare('DELETE FROM site_photos WHERE id=?')->execute([$id]);
        }
        flash('success','Photo deleted.'); header('Location: gallery.php'); exit;
    }
}

// Run cleanup on page load too (catches old photos even without uploads)
gallery_cleanup($pdo, $dir, $max_photos);

$photos = $pdo->query('SELECT *, DATEDIFF(NOW(),created_at) as days_old FROM site_photos ORDER BY sort_order ASC, id ASC')->fetchAll();
$total  = count($photos);

admin_header('Gallery');
echo show_flash();
?>
<style>
.photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-top:1.25rem}
.photo-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);overflow:hidden}
.photo-card img{width:100%;height:150px;object-fit:cover;display:block}
.photo-card-body{padding:.75rem}
</style>

<div class="page-head"><h1>Photo Gallery</h1><a href="dashboard.php" class="btn btn-secondary">← Dashboard</a></div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">
  Photos appear in the main site slideshow. <strong>Auto-cleanup:</strong> photos older than 30 days or beyond the photo limit are removed automatically.
  Currently <strong><?= $total ?>/<?= $max_photos ?></strong> photos.
</p>

<!-- Photo limit setting -->
<div class="card" style="max-width:520px;margin-bottom:1.5rem">
  <h2 style="margin-bottom:1rem">Display Limit</h2>
  <form method="POST" style="display:flex;align-items:flex-end;gap:.75rem">
    <?= csrf_field() ?><input type="hidden" name="action" value="set_limit">
    <div class="form-group" style="margin:0;flex:1">
      <label>Max photos to display <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">(1–100)</span></label>
      <input type="number" name="gallery_max_photos" value="<?= $max_photos ?>" min="1" max="100" required>
    </div>
    <button type="submit" class="btn btn-secondary" style="margin-bottom:0">Save</button>
  </form>
</div>

<!-- Upload form -->
<div class="card" style="max-width:520px;margin-bottom:1.5rem">
  <h2 style="margin-bottom:1rem">Upload Photos</h2>
  <form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="upload">
    <div class="form-group">
      <label>Photos — select multiple at once (JPG, PNG, WebP · max 10MB each)</label>
      <input type="file" name="photo[]" accept="image/*" capture="environment" multiple required style="padding:.5rem;font-size:.9rem">
    </div>
    <div class="form-row col-2">
      <div class="form-group"><label>Caption <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">applies to all selected</span></label><input name="caption" placeholder="Event or description"></div>
      <div class="form-group"><label>Start Sort Order</label><input type="number" name="sort_order" value="<?= ($total)*10+10 ?>"></div>
    </div>
    <?php if ($total >= $max_photos - 2): ?>
    <div style="background:#fff8e1;border:1px solid #ffc107;border-radius:4px;padding:.6rem .8rem;font-size:.82rem;color:#5f4c00;margin-bottom:.75rem">
      ⚠️ <?= $max_photos-$total ?> slot<?= ($max_photos-$total)!==1?'s':'' ?> remaining before the oldest are auto-removed.
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary">Upload Photos</button>
  </form>
</div>

<!-- Photo grid -->
<?php if (empty($photos)): ?>
  <p style="color:#9aa5b4">No photos uploaded yet. The Google Drive slideshow is being used.</p>
<?php else: ?>
<div class="photo-grid">
  <?php foreach ($photos as $p): $age_color = $p['days_old'] >= 25 ? '#A6192E' : ($p['days_old'] >= 20 ? '#f57c00' : '#9aa5b4'); ?>
  <div class="photo-card" style="<?= $p['active']?'':'opacity:.5' ?>">
    <img src="/site-photos/<?= h($p['filename']) ?>" alt="<?= h($p['caption']) ?>">
    <div class="photo-card-body">
      <div style="font-size:.7rem;color:<?= $age_color ?>;margin-bottom:.4rem;font-weight:700">
        <?= $p['days_old'] === '0' ? 'Added today' : $p['days_old'] . 'd old' ?> · expires in <?= max(0,30-(int)$p['days_old']) ?>d
      </div>
      <form method="POST">
        <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $p['id'] ?>">
        <input name="caption" value="<?= h($p['caption']) ?>" placeholder="Caption" style="font-size:.8rem;padding:.35rem .5rem;margin-bottom:.4rem">
        <div style="display:flex;gap:.4rem;align-items:center">
          <input type="number" name="sort_order" value="<?= $p['sort_order'] ?>" style="width:60px;font-size:.8rem;padding:.35rem .4rem">
          <label style="display:flex;align-items:center;gap:.3rem;font-size:.78rem;font-weight:400;text-transform:none;letter-spacing:0;cursor:pointer;margin:0">
            <input type="checkbox" name="active" value="1" style="width:auto" <?= $p['active']?'checked':'' ?>> Show
          </label>
          <button type="submit" class="btn btn-secondary btn-sm" style="margin-left:auto">Save</button>
        </div>
      </form>
      <form method="POST" onsubmit="return confirm('Delete photo?')" style="margin-top:.4rem">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm" style="width:100%">Delete</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php admin_footer(); ?>

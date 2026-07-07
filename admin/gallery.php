<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify(); $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        $caption = trim($_POST['caption'] ?? '');
        $sort    = (int)($_POST['sort_order'] ?? 0);
        if (!empty($_FILES['photo']['name'])) {
            $file  = $_FILES['photo'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']); finfo_close($finfo);
            if (in_array($mime, ['image/jpeg','image/png','image/gif','image/webp']) && $file['size'] <= 10*1024*1024) {
                $ext  = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$mime];
                $name = date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                $dir  = __DIR__ . '/../site-photos/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                move_uploaded_file($file['tmp_name'], $dir . $name);
                $pdo->prepare('INSERT INTO site_photos (filename,caption,sort_order,active) VALUES (?,?,?,1)')->execute([$name,$caption,$sort]);
                flash('success','Photo uploaded.');
            } else { flash('error','Invalid file. Use JPG, PNG, GIF, or WebP under 10MB.'); }
        }
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
            @unlink(__DIR__.'/../site-photos/'.$p['filename']);
            $pdo->prepare('DELETE FROM site_photos WHERE id=?')->execute([$id]);
        }
        flash('success','Photo deleted.'); header('Location: gallery.php'); exit;
    }
}

$photos = $pdo->query('SELECT * FROM site_photos ORDER BY sort_order ASC, id ASC')->fetchAll();

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
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">Photos uploaded here appear in the slideshow on the main site. If no photos are uploaded, the slideshow uses Google Drive.</p>

<!-- Upload form -->
<div class="card" style="max-width:500px;margin-bottom:1.5rem">
  <h2 style="margin-bottom:1rem">Upload Photo</h2>
  <form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="upload">
    <div class="form-group">
      <label>Photo (JPG, PNG, WebP — max 10MB)</label>
      <input type="file" name="photo" accept="image/*" capture="environment" required style="padding:.5rem;font-size:.9rem">
    </div>
    <div class="form-row col-2">
      <div class="form-group"><label>Caption <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">optional</span></label><input name="caption" placeholder="Event or description"></div>
      <div class="form-group"><label>Sort Order</label><input type="number" name="sort_order" value="<?= count($photos)*10 + 10 ?>"></div>
    </div>
    <button type="submit" class="btn btn-primary">Upload Photo</button>
  </form>
</div>

<!-- Photo grid -->
<?php if (empty($photos)): ?>
  <p style="color:#9aa5b4">No photos uploaded yet. The Google Drive slideshow is being used.</p>
<?php else: ?>
<div class="photo-grid">
  <?php foreach ($photos as $p): ?>
  <div class="photo-card <?= $p['active']?'':'opacity:'.'.5' ?>">
    <img src="/site-photos/<?= h($p['filename']) ?>" alt="<?= h($p['caption']) ?>">
    <div class="photo-card-body">
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

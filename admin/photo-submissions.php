<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

$submissions_dir = __DIR__ . '/../photo-submissions/';
$photos_dir       = __DIR__ . '/../event-photos/';
if (!is_dir($photos_dir)) mkdir($photos_dir, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    $s = $pdo->prepare('SELECT * FROM photo_submissions WHERE id = ? AND status = \'pending\'');
    $s->execute([$id]);
    $sub = $s->fetch(PDO::FETCH_ASSOC);

    if ($sub) {
        if ($action === 'approve') {
            $album_id = (int)($_POST['album_id'] ?? 0);
            if (!$album_id) {
                flash('error', 'Pick an album before approving.');
            } else {
                $src = $submissions_dir . basename($sub['filename']);
                if (is_file($src)) {
                    rename($src, $photos_dir . basename($sub['filename']));
                    $pdo->prepare('INSERT INTO event_photos (album_id, filename, caption, sort_order) VALUES (?,?,?,0)')
                        ->execute([$album_id, $sub['filename'], $sub['caption']]);
                }
                $pdo->prepare('UPDATE photo_submissions SET status=\'approved\', album_id=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?')
                    ->execute([$album_id, $_SESSION['user_id'] ?? null, $id]);
                flash('success', 'Photo approved and added to the album.');
            }
        } elseif ($action === 'reject') {
            $pdo->prepare('UPDATE photo_submissions SET status=\'rejected\', reviewed_by=?, reviewed_at=NOW() WHERE id=?')
                ->execute([$_SESSION['user_id'] ?? null, $id]);
            flash('success', 'Photo rejected.');
        }
    }
    header('Location: photo-submissions.php'); exit;
}

$pending = $pdo->query(
    "SELECT p.*, u.name AS submitter_name FROM photo_submissions p
     JOIN users u ON p.user_id = u.id WHERE p.status = 'pending' ORDER BY p.submitted_at ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$albums = $pdo->query("SELECT id, name FROM event_albums ORDER BY sort_order ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

admin_header('Photo Submissions');
echo show_flash();
?>
<style>
.sub-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1rem;margin-top:1.25rem}
.sub-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);overflow:hidden}
.sub-card img{width:100%;height:180px;object-fit:cover;display:block}
.sub-body{padding:.85rem}
.sub-meta{font-size:.75rem;color:#5a6a7a;margin-bottom:.5rem}
</style>

<div class="page-head">
  <h1>Photo Submissions</h1>
  <div style="display:flex;gap:.5rem">
    <a href="event-albums.php" class="btn btn-secondary">Event Albums</a>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">Member-submitted photos awaiting review. Approving moves the photo into the album you choose.</p>

<?php if (empty($pending)): ?>
  <p style="color:#9aa5b4">No photos waiting for review.</p>
<?php elseif (empty($albums)): ?>
  <div class="alert alert-error">Create at least one <a href="event-albums.php">event album</a> before approving submissions.</div>
<?php else: ?>
<div class="sub-grid">
  <?php foreach ($pending as $s): ?>
  <div class="sub-card">
    <img src="/photo-submission-serve.php?id=<?= $s['id'] ?>" alt="">
    <div class="sub-body">
      <div class="sub-meta">
        By <?= h($s['submitter_name']) ?> &bull; <?= date('M j, Y', strtotime($s['submitted_at'])) ?>
        <?php if ($s['caption']): ?><br><?= h($s['caption']) ?><?php endif; ?>
      </div>
      <form method="POST">
        <?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= $s['id'] ?>">
        <select name="album_id" style="margin-bottom:.5rem;font-size:.82rem">
          <?php foreach ($albums as $a): ?>
            <option value="<?= $a['id'] ?>" <?= $a['id'] == $s['album_id'] ? 'selected' : '' ?>><?= h($a['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div style="display:flex;gap:.4rem">
          <button type="submit" class="btn btn-primary btn-sm" style="flex:1">Approve</button>
        </div>
      </form>
      <form method="POST" style="margin-top:.4rem" onsubmit="return confirm('Reject this photo?')">
        <?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= $s['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm" style="width:100%">Reject</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php admin_footer(); ?>

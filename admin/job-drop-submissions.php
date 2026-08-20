<?php
require_once __DIR__ . '/auth.php';
require_login();

// Narrower than the general photo-submissions approval queue (any board/
// admin role there) — Job Drop Night is deliberately restricted to
// President, VP, or Secretary. officer_title is looked up fresh from the
// DB rather than trusted from $_SESSION, same reasoning as the President/VP
// cross-approval check in purchase-action.php: a title set/changed after
// this session started must still be honored immediately.
$pdo = get_pdo();
$title_check = $pdo->prepare('SELECT officer_title FROM users WHERE id = ?');
$title_check->execute([$_SESSION['user_id'] ?? 0]);
$my_title = (string)($title_check->fetchColumn() ?: '');
$can_approve = is_super_admin() || is_secretary() || in_array($my_title, ['President', 'VP'], true);
if (!$can_approve) { header('Location: dashboard.php?denied=1'); exit; }

$submissions_dir = __DIR__ . '/../job-drop-submissions/';
$photos_dir       = __DIR__ . '/../job-drop-photos/';
if (!is_dir($photos_dir)) mkdir($photos_dir, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    $s = $pdo->prepare(
        "SELECT js.*, m.cadet_first_name, m.cadet_middle_name, m.cadet_last_name, m.cadet_suffix
         FROM job_drop_submissions js
         JOIN members m ON m.id = js.member_id
         WHERE js.id = ? AND js.status = 'pending'"
    );
    $s->execute([$id]);
    $sub = $s->fetch(PDO::FETCH_ASSOC);

    if ($sub) {
        if ($action === 'approve') {
            $src = $submissions_dir . basename($sub['filename']);
            if (is_file($src) && rename($src, $photos_dir . basename($sub['filename']))) {
                $cadet_name = trim(preg_replace('/\s+/', ' ',
                    $sub['cadet_first_name'] . ' ' . $sub['cadet_middle_name'] . ' ' . $sub['cadet_last_name'] . ' ' . $sub['cadet_suffix']
                ));
                // Stamped with the class this submission was eligible under
                // (same computation as job-drop-lookup.php) so the homepage
                // feed can automatically stop showing it once that class
                // has graduated and the next one becomes eligible — no
                // manual cleanup needed between years.
                $class_year = (string)((int)outgoing_class_year() + 1);
                $next_sort = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+10 FROM job_drop_photos')->fetchColumn();
                $pdo->prepare('INSERT INTO job_drop_photos (filename, cadet_name, job_title, sort_order, active, class_year) VALUES (?,?,?,?,1,?)')
                    ->execute([$sub['filename'], $cadet_name, $sub['job_title'], $next_sort, $class_year]);
                $pdo->prepare("UPDATE job_drop_submissions SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                    ->execute([$_SESSION['user_id'] ?? null, $id]);
                flash('success', 'Job Drop approved and added to the homepage.');
            } else {
                flash('error', 'The submitted photo file is missing on the server — could not approve. Contact tech support.');
            }
        } elseif ($action === 'reject') {
            $pdo->prepare("UPDATE job_drop_submissions SET status='rejected', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                ->execute([$_SESSION['user_id'] ?? null, $id]);
            flash('success', 'Submission rejected.');
        }
    }

    if ($action === 'toggle_live') {
        $cur = $pdo->prepare('SELECT active FROM job_drop_photos WHERE id=?');
        $cur->execute([$id]);
        $v = $cur->fetchColumn();
        if ($v !== false) {
            $pdo->prepare('UPDATE job_drop_photos SET active=? WHERE id=?')->execute([$v ? 0 : 1, $id]);
            flash('success', $v ? 'Hidden from the homepage.' : 'Back on the homepage.');
        }
    } elseif ($action === 'delete_live') {
        $row = $pdo->prepare('SELECT filename FROM job_drop_photos WHERE id=?');
        $row->execute([$id]);
        $filename = $row->fetchColumn();
        if ($filename) {
            $path = $photos_dir . basename($filename);
            if (is_file($path)) unlink($path);
            $pdo->prepare('DELETE FROM job_drop_photos WHERE id=?')->execute([$id]);
            flash('success', 'Entry deleted.');
        }
    }
    header('Location: job-drop-submissions.php'); exit;
}

$pending = $pdo->query(
    "SELECT js.id, js.filename, js.job_title, js.submitted_at,
            m.cadet_first_name, m.cadet_middle_name, m.cadet_last_name, m.cadet_suffix,
            m.parent1_first_name, m.parent1_last_name
     FROM job_drop_submissions js
     JOIN members m ON m.id = js.member_id
     WHERE js.status = 'pending' ORDER BY js.submitted_at ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$live = $pdo->query(
    "SELECT id, filename, cadet_name, job_title, active, class_year, created_at
     FROM job_drop_photos ORDER BY class_year DESC, sort_order ASC, id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

admin_header('Job Drop Night Submissions');
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
  <h1>Job Drop Night Submissions</h1>
  <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">Parent-submitted cadet job assignments awaiting review. Approving adds the photo to the homepage Job Drop Night rotation.</p>

<?php if (empty($pending)): ?>
  <p style="color:#9aa5b4">No submissions waiting for review.</p>
<?php else: ?>
<div class="sub-grid">
  <?php foreach ($pending as $s):
    $cadet_name = trim(preg_replace('/\s+/', ' ',
        $s['cadet_first_name'] . ' ' . $s['cadet_middle_name'] . ' ' . $s['cadet_last_name'] . ' ' . $s['cadet_suffix']
    ));
    $parent_name = trim($s['parent1_first_name'] . ' ' . $s['parent1_last_name']);
  ?>
  <div class="sub-card">
    <img src="/job-drop-submissions/<?= h(basename($s['filename'])) ?>" alt="">
    <div class="sub-body">
      <div class="sub-meta">
        <strong style="color:#002554"><?= h($cadet_name) ?></strong> — <?= h($s['job_title']) ?><br>
        Submitted by <?= h($parent_name) ?> &bull; <?= date('M j, Y', strtotime($s['submitted_at'])) ?>
      </div>
      <form method="POST">
        <?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= $s['id'] ?>">
        <button type="submit" class="btn btn-primary btn-sm" style="width:100%">Approve</button>
      </form>
      <form method="POST" style="margin-top:.4rem" onsubmit="return confirm('Reject this submission?')">
        <?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= $s['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm" style="width:100%">Reject</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<h2 style="margin-top:2.5rem;margin-bottom:.25rem;font-size:1.15rem;color:#002554">Live on Homepage</h2>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">
  Approved entries. Grouped by class year — once a class graduates and the next one becomes eligible, its entries stop
  appearing on the homepage automatically, but stay listed here until you delete them.
</p>

<?php if (empty($live)): ?>
  <p style="color:#9aa5b4">Nothing approved yet.</p>
<?php else: ?>
<div class="sub-grid">
  <?php foreach ($live as $l): ?>
  <div class="sub-card" style="<?= $l['active'] ? '' : 'opacity:.5' ?>">
    <img src="/job-drop-photos/<?= h(basename($l['filename'])) ?>" alt="">
    <div class="sub-body">
      <div class="sub-meta">
        <strong style="color:#002554"><?= h($l['cadet_name']) ?></strong> — <?= h($l['job_title']) ?><br>
        Class of <?= h($l['class_year']) ?><?= $l['active'] ? '' : ' &middot; Hidden' ?>
      </div>
      <form method="POST">
        <?= csrf_field() ?><input type="hidden" name="action" value="toggle_live"><input type="hidden" name="id" value="<?= $l['id'] ?>">
        <button type="submit" class="btn btn-secondary btn-sm" style="width:100%"><?= $l['active'] ? 'Hide from Homepage' : 'Show on Homepage' ?></button>
      </form>
      <form method="POST" style="margin-top:.4rem" onsubmit="return confirm('Permanently delete this entry and its photo?')">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete_live"><input type="hidden" name="id" value="<?= $l['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm" style="width:100%">Delete</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php admin_footer(); ?>

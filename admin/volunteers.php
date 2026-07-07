<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

$search = trim($_GET['q'] ?? '');
$where  = ['1=1']; $params = [];
if ($search) { $where[] = '(name LIKE :q OR email LIKE :q OR areas LIKE :q)'; $params[':q']="%$search%"; }

$stmt = $pdo->prepare('SELECT * FROM volunteers WHERE '.implode(' AND ',$where).' ORDER BY created_at DESC');
$stmt->execute($params);
$vols = $stmt->fetchAll();
$total = (int)$pdo->query('SELECT COUNT(*) FROM volunteers')->fetchColumn();

admin_header('Volunteer Submissions');
echo show_flash();
?>
<div class="page-head">
  <h1>Volunteer Interest <span style="font-size:.85rem;font-weight:400;color:#5a6a7a">(<?= $total ?> total)</span></h1>
  <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
</div>

<div class="card" style="padding:.85rem 1.25rem;margin-bottom:1rem">
  <form method="GET" style="display:flex;gap:.75rem;align-items:flex-end">
    <div class="form-group" style="flex:1;margin:0"><label>Search name / email / area</label><input name="q" value="<?= h($search) ?>" placeholder="Type to search…"></div>
    <button type="submit" class="btn btn-primary">Search</button>
    <a href="volunteers.php" class="btn btn-secondary">Clear</a>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto">
<table>
  <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Areas</th><th>Availability</th><th>Cadet</th><th>Date</th><th class="actions-head">Action</th></tr></thead>
  <tbody>
  <?php if (empty($vols)): ?><tr><td colspan="8" style="text-align:center;padding:2rem;color:#5a6a7a">No volunteer submissions yet.</td></tr><?php endif; ?>
  <?php foreach ($vols as $v): ?>
  <tr>
    <td><strong><?= h($v['name']) ?></strong></td>
    <td><a href="mailto:<?= h($v['email']) ?>" style="color:#003594"><?= h($v['email']) ?></a></td>
    <td style="font-size:.82rem"><?= h($v['phone']) ?: '—' ?></td>
    <td style="font-size:.78rem;color:#5a6a7a"><?= h($v['areas']) ?: '—' ?></td>
    <td style="font-size:.78rem;color:#5a6a7a"><?= h($v['availability']) ?: '—' ?></td>
    <td style="font-size:.78rem;color:#5a6a7a"><?= h($v['cadet_info']) ?: '—' ?></td>
    <td style="font-size:.78rem;white-space:nowrap;color:#5a6a7a"><?= date('M j, Y', strtotime($v['created_at'])) ?></td>
    <td class="actions">
      <form method="POST" onsubmit="return confirm('Delete this submission?')" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= $v['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete') {
    csrf_verify();
    $pdo->prepare('DELETE FROM volunteers WHERE id=?')->execute([(int)$_POST['id']]);
    flash('success','Submission deleted.'); header('Location: volunteers.php'); exit;
}
admin_footer(); ?>

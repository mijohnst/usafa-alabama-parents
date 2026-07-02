<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo = get_pdo();

// ── Filters ────────────────────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$year   = $_GET['year']   ?? '';
$region = $_GET['region'] ?? '';
$paid   = $_GET['paid']   ?? '';

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(cadet_last_name LIKE :q OR cadet_first_middle LIKE :q
                 OR parent1_last_name LIKE :q OR parent1_first_name LIKE :q
                 OR parent2_last_name LIKE :q OR parent2_first_name LIKE :q
                 OR cadet_email LIKE :q OR parent1_email LIKE :q OR parent2_email LIKE :q
                 OR cadet_cell LIKE :q OR parent1_cell LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
if ($year   !== '') { $where[] = 'class_year = :year';          $params[':year']   = $year; }
if ($region !== '') { $where[] = 'al_region  = :region';         $params[':region'] = $region; }
if ($paid   === '1') { $where[] = 'membership_paid = 1'; }
if ($paid   === '0') { $where[] = 'membership_paid = 0'; }

$sql = 'SELECT * FROM members WHERE ' . implode(' AND ', $where)
     . ' ORDER BY class_year, cadet_last_name, cadet_first_middle';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();

$total_stmt = $pdo->query('SELECT COUNT(*) FROM members');
$total = (int)$total_stmt->fetchColumn();

admin_header('Members');
echo show_flash();
?>

<div class="page-head">
  <h1>Members <span style="font-size:.85rem;font-weight:400;color:#5a6a7a">(<?= count($members) ?> of <?= $total ?> total)</span></h1>
  <?php if (!is_viewer()): ?><a href="add.php" class="btn btn-primary">+ Add Member</a><?php endif; ?>
</div>

<div class="card" style="padding:1rem 1.5rem">
  <form method="GET" class="filter-bar">
    <div class="form-group" style="flex:2;min-width:200px">
      <label>Search name / email / phone</label>
      <input name="q" value="<?= h($search) ?>" placeholder="Type to search…">
    </div>
    <div class="form-group">
      <label>Class Year</label>
      <select name="year">
        <option value="">All years</option>
        <?php foreach (['2026','2027','2028','2029','2030','Prep School','Graduate'] as $y): ?>
          <option value="<?= h($y) ?>" <?= $year === $y ? 'selected' : '' ?>><?= h($y) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>AL Region</label>
      <select name="region">
        <option value="">All regions</option>
        <?php foreach (['North','Central','South'] as $r): ?>
          <option value="<?= h($r) ?>" <?= $region === $r ? 'selected' : '' ?>><?= h($r) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
      <div class="form-group">
        <label>Dues Status</label>
        <select name="paid">
          <option value="">All</option>
          <option value="1" <?= $paid==='1'?'selected':''?>>Paid</option>
          <option value="0" <?= $paid==='0'?'selected':''?>>Not Paid</option>
        </select>
      </div>
    <div class="form-group" style="flex:0">
      <label>&nbsp;</label>
      <div style="display:flex;gap:.5rem">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="index.php" class="btn btn-secondary">Clear</a>
      </div>
    </div>
  </form>
</div>

<div class="card" style="padding:0;overflow:auto">
<table>
  <thead>
    <tr>
      <th>Year</th>
      <th>Cadet</th>
      <th>Squadron</th>
      <th>Region</th>
      <th>Parent 1</th>
      <th>Parent 2</th>
      <th>Dues</th>
      <th>Remarks</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($members)): ?>
    <tr><td colspan="8" style="text-align:center;padding:2rem;color:#5a6a7a">No members found.</td></tr>
  <?php endif; ?>
  <?php foreach ($members as $m): ?>
    <?php
      $sqd = $m['squadron_yr2_4'] ?: ($m['fall_squadron'] ?: $m['bct_squadron']);
      $region_cls = $m['al_region'] ? 'badge-' . $m['al_region'] : '';
    ?>
    <tr>
      <td><?= h($m['class_year']) ?></td>
      <td>
        <strong><?= h($m['cadet_last_name']) ?></strong><?= $m['cadet_first_middle'] ? ', ' . h($m['cadet_first_middle']) : '' ?><br>
        <?php if ($m['cadet_email']): ?><span style="font-size:.78rem;color:#5a6a7a"><?= h($m['cadet_email']) ?></span><?php endif; ?>
      </td>
      <td><?= h($sqd) ?></td>
      <td><?php if ($m['al_region']): ?><span class="badge <?= h($region_cls) ?>"><?= h($m['al_region']) ?></span><?php endif; ?></td>
      <td>
        <?= h(trim($m['parent1_first_name'] . ' ' . $m['parent1_last_name'])) ?><br>
        <span style="font-size:.78rem;color:#5a6a7a"><?= h($m['parent1_cell']) ?></span>
      </td>
      <td>
        <?= h(trim($m['parent2_first_name'] . ' ' . $m['parent2_last_name'])) ?><br>
        <span style="font-size:.78rem;color:#5a6a7a"><?= h($m['parent2_cell']) ?></span>
      </td>
      <td>
        <?php if ($m['membership_paid']): ?>
          <span class="badge badge-paid">✓ Paid</span><br>
          <span style="font-size:.72rem;color:#5a6a7a"><?= h($m['membership_year']) ?></span>
        <?php else: ?>
          <span class="badge badge-unpaid">✗ Unpaid</span>
        <?php endif; ?>
      </td>
      <td style="max-width:180px;font-size:.78rem;color:#5a6a7a"><?= h(mb_strimwidth($m['remarks'] ?? '', 0, 60, '…')) ?></td>
      <td class="actions">
        <?php if (!is_viewer()): ?>
        <div class="btn-group">
          <a href="edit.php?id=<?= (int)$m['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
          <form method="POST" action="delete.php" onsubmit="return confirm('Delete <?= h(addslashes($m['cadet_last_name'])) ?>? This cannot be undone.')">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
          </form>
        </div>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php admin_footer(); ?>

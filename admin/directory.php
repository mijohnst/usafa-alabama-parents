<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo = get_pdo();

$year   = $_GET['year']   ?? '';
$region = $_GET['region'] ?? '';

$where  = ['archived = 0'];
$params = [];
if ($year   !== '') { $where[] = 'class_year = :year';   $params[':year']   = $year; }
if ($region !== '') { $where[] = 'al_region  = :region'; $params[':region'] = $region; }

$stmt = $pdo->prepare('SELECT * FROM members WHERE ' . implode(' AND ', $where)
    . ' ORDER BY class_year, cadet_last_name');
$stmt->execute($params);
$members = $stmt->fetchAll();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Member Directory — USAFA Parents Club of Alabama</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Segoe UI",Arial,sans-serif;font-size:13px;color:#111;background:#fff;padding:1.5rem}
h1{font-size:1.2rem;color:#002554;margin-bottom:.25rem}
.subtitle{font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem}
.filters{display:flex;gap:.75rem;margin-bottom:1.25rem;flex-wrap:wrap;align-items:flex-end}
.filters select,.filters a{padding:.4rem .65rem;border:1px solid #d0d5dd;border-radius:4px;font-size:.82rem;font-family:inherit;background:#fff;color:#1a2332;cursor:pointer;text-decoration:none}
.filters button{padding:.4rem .9rem;background:#003594;color:#fff;border:none;border-radius:4px;font-size:.82rem;cursor:pointer}
.yr-group{margin-bottom:1.5rem}
.yr-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#003594;border-bottom:2px solid #003594;padding-bottom:.25rem;margin-bottom:.75rem}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:.65rem}
.card{border:1px solid #e1e5eb;border-radius:4px;padding:.7rem .85rem;page-break-inside:avoid}
.cadet-name{font-weight:700;font-size:.92rem;color:#002554}
.cadet-meta{font-size:.72rem;color:#5a6a7a;margin-bottom:.4rem}
.p-name{font-weight:600;font-size:.8rem;color:#1a2332;margin-top:.35rem}
.p-detail{font-size:.75rem;color:#333;line-height:1.6}
.p-detail a{color:#333;text-decoration:none}
.divider{border-top:1px solid #f0f2f5;margin:.35rem 0}
@media print{
  body{padding:.5rem}
  .no-print{display:none!important}
  .card{border-color:#ccc}
  a{color:#111!important}
  .grid{grid-template-columns:repeat(3,1fr)}
}
</style>
</head><body>

<div class="no-print" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1rem">
  <div>
    <h1>Member Directory</h1>
    <div class="subtitle">USAFA Parents Club of Alabama &mdash; <?= date('F Y') ?></div>
  </div>
  <div style="display:flex;gap:.5rem">
    <button onclick="window.print()" style="padding:.5rem 1.1rem;background:#003594;color:#fff;border:none;border-radius:4px;font-size:.85rem;cursor:pointer">Print / Save PDF</button>
    <a href="index.php" style="padding:.5rem 1.1rem;background:#f0f2f5;color:#333;border:1px solid #d0d5dd;border-radius:4px;font-size:.85rem;text-decoration:none">← Back</a>
  </div>
</div>

<form class="filters no-print" method="GET">
  <div>
    <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#5a6a7a;display:block;margin-bottom:.2rem">Year</label>
    <select name="year">
      <option value="">All years</option>
      <?php foreach (['2026','2027','2028','2029','2030','2031','Prep School'] as $y): ?>
        <option value="<?= h($y) ?>" <?= $year===$y?'selected':''?>><?= h($y) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#5a6a7a;display:block;margin-bottom:.2rem">Region</label>
    <select name="region">
      <option value="">All Regions</option>
      <?php foreach (['North','Central','South'] as $r): ?>
        <option value="<?= h($r) ?>" <?= $region===$r?'selected':''?>><?= h($r) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit">Filter</button>
</form>

<?php
$by_year = [];
foreach ($members as $m) { $by_year[$m['class_year']][] = $m; }
foreach ($by_year as $yr => $group):
?>
<div class="yr-group">
  <div class="yr-label">Class of <?= h($yr) ?> &mdash; <?= count($group) ?> member<?= count($group)!==1?'s':''?></div>
  <div class="grid">
  <?php foreach ($group as $m):
    $sqd = $m['squadron_yr2_4'] ?: ($m['fall_squadron'] ?: $m['bct_squadron']);
  ?>
  <div class="card">
    <div class="cadet-name"><?= h($m['cadet_last_name']) ?>, <?= h(trim($m['cadet_first_name'] . ' ' . $m['cadet_middle_name'])) ?></div>
    <div class="cadet-meta">
      <?= h($sqd) ?><?= $sqd && $m['al_region'] ? ' &bull; ' : '' ?><?= h($m['al_region']) ?>
      <?php if ($m['cadet_po_box']): ?>&bull; PO <?= h($m['cadet_po_box']) ?><?php endif; ?>
    </div>

    <?php if ($m['parent1_first_name'] || $m['parent1_last_name']): ?>
    <div class="p-name"><?= h(trim($m['parent1_first_name'].' '.$m['parent1_last_name'])) ?></div>
    <div class="p-detail">
      <?php if ($m['parent1_cell']): ?><a href="tel:<?= h(preg_replace('/\D/','',$m['parent1_cell'])) ?>"><?= h($m['parent1_cell']) ?></a><br><?php endif; ?>
      <?php if ($m['parent1_email']): ?><a href="mailto:<?= h($m['parent1_email']) ?>"><?= h($m['parent1_email']) ?></a><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($m['parent2_first_name'] || $m['parent2_last_name']): ?>
    <div class="divider"></div>
    <div class="p-name"><?= h(trim($m['parent2_first_name'].' '.$m['parent2_last_name'])) ?></div>
    <div class="p-detail">
      <?php if ($m['parent2_cell']): ?><a href="tel:<?= h(preg_replace('/\D/','',$m['parent2_cell'])) ?>"><?= h($m['parent2_cell']) ?></a><br><?php endif; ?>
      <?php if ($m['parent2_email']): ?><a href="mailto:<?= h($m['parent2_email']) ?>"><?= h($m['parent2_email']) ?></a><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($m['parent1_street']): ?>
    <div class="divider"></div>
    <div class="p-detail"><?= h($m['parent1_street']) ?><br><?= h($m['parent1_city']) ?>, <?= h($m['parent1_state']) ?> <?= h($m['parent1_zip']) ?></div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<?php if (empty($members)): ?>
<p style="color:#5a6a7a;font-size:.9rem">No members found.</p>
<?php endif; ?>

</body></html>

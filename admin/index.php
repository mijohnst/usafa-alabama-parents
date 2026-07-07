<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo = get_pdo();

// ── Filters ────────────────────────────────────────────────────────────────
$search  = trim($_GET['q']       ?? '');
$year    = $_GET['year']         ?? '';
$region  = $_GET['region']       ?? '';
$paid    = $_GET['paid']         ?? '';
$squadron  = trim($_GET['squadron']  ?? '');
$archived  = $_GET['archived']   ?? '0';
$sort      = $_GET['sort']       ?? 'class_year';
$dir       = $_GET['dir']        ?? 'asc';

$allowed_sorts = ['class_year','cadet_last_name','al_region','membership_paid'];
if (!in_array($sort, $allowed_sorts)) $sort = 'class_year';
if (!in_array($dir, ['asc','desc']))  $dir  = 'asc';
$next_dir = $dir === 'asc' ? 'desc' : 'asc';

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(cadet_last_name LIKE :q OR cadet_first_middle LIKE :q
                 OR parent1_last_name LIKE :q OR parent1_first_name LIKE :q
                 OR parent2_last_name LIKE :q OR parent2_first_name LIKE :q
                 OR cadet_email LIKE :q OR parent1_email LIKE :q OR parent2_email LIKE :q
                 OR cadet_cell LIKE :q OR parent1_cell LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
if ($year   !== '') { $where[] = 'class_year = :year';  $params[':year']   = $year; }
else                { $where[] = 'class_year != :excl'; $params[':excl']   = '2026'; }
if ($region !== '') { $where[] = 'al_region  = :region'; $params[':region'] = $region; }
if ($paid     === '1') { $where[] = 'membership_paid = 1'; }
if ($paid     === '0') { $where[] = 'membership_paid = 0'; }
if ($squadron !== '') {
    $where[] = '(bct_squadron = :sqd OR fall_squadron = :sqd OR squadron_yr2_4 = :sqd)';
    $params[':sqd'] = $squadron;
}
$where[] = $archived === '1' ? 'archived = 1' : 'archived = 0';

$order = $sort === 'cadet_last_name'
    ? "cadet_last_name $dir, cadet_first_middle $dir"
    : "$sort $dir, cadet_last_name asc";

$sql = 'SELECT * FROM members WHERE ' . implode(' AND ', $where) . " ORDER BY $order";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();

// ── CSV export ─────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="members-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Year','Last Name','First/Middle','Squadron','Region',
                   'P1 Name','P1 Email','P1 Cell','P2 Name','P2 Email','P2 Cell',
                   'Dues','Dues Year','Remarks']);
    foreach ($members as $m) {
        $sqd = $m['squadron_yr2_4'] ?: ($m['fall_squadron'] ?: $m['bct_squadron']);
        fputcsv($out, [
            $m['class_year'], $m['cadet_last_name'], $m['cadet_first_middle'],
            $sqd, $m['al_region'],
            trim($m['parent1_first_name'].' '.$m['parent1_last_name']),
            $m['parent1_email'], $m['parent1_cell'],
            trim($m['parent2_first_name'].' '.$m['parent2_last_name']),
            $m['parent2_email'], $m['parent2_cell'],
            $m['membership_paid'] ? 'Paid' : 'Unpaid',
            $m['membership_year'], $m['remarks']
        ]);
    }
    fclose($out);
    exit;
}

// ── Distinct squadrons for filter dropdown ─────────────────────────────────
$squadrons = $pdo->query(
    "SELECT DISTINCT s FROM (
        SELECT squadron_yr2_4 s FROM members WHERE squadron_yr2_4 != ''
        UNION SELECT fall_squadron FROM members WHERE fall_squadron != ''
        UNION SELECT bct_squadron  FROM members WHERE bct_squadron  != ''
    ) sq ORDER BY s"
)->fetchAll(PDO::FETCH_COLUMN);

// ── Dashboard stats ────────────────────────────────────────────────────────
$stats_rows = $pdo->query(
    "SELECT class_year, membership_paid, COUNT(*) as cnt
     FROM members WHERE archived = 0 GROUP BY class_year, membership_paid ORDER BY class_year"
)->fetchAll();

$stat_total = 0; $stat_paid = 0; $stat_by_year = []; $stat_paid_by_year = [];
foreach ($stats_rows as $s) {
    $stat_total += $s['cnt'];
    if ($s['membership_paid']) $stat_paid += $s['cnt'];
    $stat_by_year[$s['class_year']] = ($stat_by_year[$s['class_year']] ?? 0) + $s['cnt'];
    if ($s['membership_paid']) $stat_paid_by_year[$s['class_year']] = ($stat_paid_by_year[$s['class_year']] ?? 0) + $s['cnt'];
}
// Unpaid only counts active years (2027-2030), not 2026 graduates
$stat_unpaid = (int)$pdo->query(
    "SELECT COUNT(*) FROM members WHERE archived = 0 AND membership_paid = 0 AND class_year != '2026'"
)->fetchColumn();
unset($stat_by_year['2026']);

// New members this month
$new_this_month = (int)$pdo->query(
    "SELECT COUNT(*) FROM members WHERE archived = 0 AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
)->fetchColumn();

// Dues progress bar (active years 2027-2030 only)
$active_total = $stat_paid + $stat_unpaid;
$dues_pct     = $active_total > 0 ? round($stat_paid / $active_total * 100) : 0;

// Finance widget data (for admins and treasurers)
$fin_pending = $fin_approved = $fin_ytd = 0;
if (can_manage_finances() && !is_member()) {
    try {
        $fin_pending  = (int)$pdo->query("SELECT COUNT(*) FROM purchases WHERE status='pending'")->fetchColumn();
        $fin_approved = (int)$pdo->query("SELECT COUNT(*) FROM purchases WHERE status='approved'")->fetchColumn();
        $fin_ytd      = (float)$pdo->query("SELECT COALESCE(SUM(amount_total),0) FROM purchases WHERE YEAR(purchase_date)=YEAR(NOW()) AND status='reimbursed'")->fetchColumn();
    } catch (Exception $e) {}
}

// Upcoming birthdays (next 30 days)
$bday_rows = $pdo->query(
    "SELECT cadet_last_name, cadet_first_middle, cadet_birthday, cadet_po_box
     FROM members WHERE archived = 0 AND class_year != '2026' AND cadet_birthday IS NOT NULL AND cadet_birthday != ''"
)->fetchAll();
$upcoming_bdays = [];
$today = new DateTime('today');
foreach ($bday_rows as $b) {
    try {
        $bday = new DateTime($b['cadet_birthday']);
        $next = new DateTime($today->format('Y') . '-' . $bday->format('m-d'));
        if ($next < $today) $next->modify('+1 year');
        $days = (int)$today->diff($next)->days;
        if ($days <= 30) {
            $upcoming_bdays[] = ['name'  => $b['cadet_last_name'] . ', ' . $b['cadet_first_middle'],
                                  'box'   => $b['cadet_po_box'],
                                  'fmt'   => $next->format('M j'),
                                  'days'  => $days];
        }
    } catch (Exception $e) {}
}
usort($upcoming_bdays, fn($a, $b) => $a['days'] - $b['days']);

// Helper: build sort link preserving current filters
function sort_link(string $col, string $label, string $current_sort, string $current_dir, string $next_dir, array $get): string {
    $params = array_merge($get, ['sort' => $col, 'dir' => $col === $current_sort ? $next_dir : 'asc']);
    $arrow  = $col === $current_sort ? ($current_dir === 'asc' ? ' ↑' : ' ↓') : '';
    return '<a href="index.php?' . http_build_query($params) . '" style="color:inherit;text-decoration:none;white-space:nowrap">'
         . htmlspecialchars($label) . '<span style="opacity:.5">' . $arrow . '</span></a>';
}

$get_params = array_filter(['q'=>$search,'year'=>$year,'region'=>$region,'paid'=>$paid]);

admin_header('Members');
echo show_flash();
?>

<!-- ── Page header ──────────────────────────────────────────────────────── -->
<div class="page-head" style="margin-bottom:1rem">
  <h1>Members <span style="font-size:.85rem;font-weight:400;color:#5a6a7a">(<?= count($members) ?> shown of <?= $stat_total ?> total)</span></h1>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <?php
    $csv_params = array_filter(['q'=>$search,'year'=>$year,'region'=>$region,'paid'=>$paid,'squadron'=>$squadron]);
    $csv_params['export'] = 'csv';
    ?>
    <a href="index.php?<?= http_build_query($csv_params) ?>" class="btn btn-secondary">Export CSV</a>
    <a href="directory.php" class="btn btn-secondary">Directory</a>
    <?php if (can_manage_members()): ?>
      <a href="reset-dues.php" class="btn btn-secondary">Reset Dues</a>
      <a href="add.php" class="btn btn-primary">+ Add Member</a>
    <?php endif; ?>
  </div>
</div>

<!-- ── Alerts strip — things that need attention today ─────────────────── -->
<?php
$alerts = [];
if ($fin_pending)  $alerts[] = ['color'=>'#fff3cd','border'=>'#ffc107','text'=>'#5f4c00','icon'=>'⏳','msg'=>"$fin_pending purchase".($fin_pending>1?'s':'')." need approval",'href'=>'purchases.php?status=pending'];
if ($fin_approved) $alerts[] = ['color'=>'#e3f2fd','border'=>'#90caf9','text'=>'#0d47a1','icon'=>'💰','msg'=>"$fin_approved awaiting reimbursement",'href'=>'pending-reimbursements.php'];
if ($new_this_month) $alerts[] = ['color'=>'#e8f5e9','border'=>'#a5d6a7','text'=>'#1b5e20','icon'=>'👤','msg'=>"$new_this_month new member".($new_this_month>1?'s':'')." this month",'href'=>'index.php?q='];
if (!empty($upcoming_bdays)) $alerts[] = ['color'=>'#f3e5f5','border'=>'#ce93d8','text'=>'#4a148c','icon'=>'🎂','msg'=>count($upcoming_bdays)." birthday".( count($upcoming_bdays)>1?'s':'')." in the next 30 days",'href'=>'#bday-panel','onclick'=>'openBirthdays()'];
?>
<?php if (!empty($alerts)): ?>
<div style="display:grid;grid-template-columns:repeat(<?= count($alerts) ?>,1fr);gap:.6rem;margin-bottom:1.25rem">
  <?php foreach ($alerts as $a): ?>
  <a href="<?= h($a['href']) ?>" <?= !empty($a['onclick'])?'onclick="'.$a['onclick'].'; return false;"':'' ?>
     style="display:flex;align-items:center;justify-content:center;gap:.45rem;background:<?= $a['color'] ?>;border:1px solid <?= $a['border'] ?>;border-radius:6px;padding:.6rem .9rem;text-decoration:none;font-size:.82rem;font-weight:600;color:<?= $a['text'] ?>;text-align:center">
    <span><?= $a['icon'] ?></span> <?= h($a['msg']) ?>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Summary stats row ────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:.6rem;margin-bottom:.6rem">
  <?php
  $summary_cards = [
    ['label'=>'Total Members',  'value'=>$stat_total,     'sub'=>'active roster',   'pct'=>100,      'color'=>'#002554'],
    ['label'=>'Dues Paid',      'value'=>$stat_paid,       'sub'=>membership_year(), 'pct'=>$dues_pct,'color'=>'#1b5e20'],
    ['label'=>'Dues Unpaid',    'value'=>$stat_unpaid,     'sub'=>'need to renew',   'pct'=>$active_total>0?round($stat_unpaid/$active_total*100):0,'color'=>'#c62828'],
    ['label'=>'New This Month', 'value'=>$new_this_month,  'sub'=>date('F Y'),       'pct'=>$stat_total>0?min(round($new_this_month/$stat_total*100),100):0,'color'=>'#003594'],
  ];
  foreach ($summary_cards as $c): ?>
  <div class="card" style="padding:.75rem 1rem;margin:0;display:flex;flex-direction:column;justify-content:space-between;gap:.4rem;min-width:0">
    <div style="font-size:.65rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.05em"><?= h($c['label']) ?></div>
    <div style="font-size:1.4rem;font-weight:700;color:<?= $c['color'] ?>;line-height:1"><?= h((string)$c['value']) ?></div>
    <div style="font-size:.65rem;color:#9aa5b4"><?= h($c['sub']) ?></div>
    <div style="background:#e1e5eb;border-radius:99px;height:4px;overflow:hidden">
      <div style="height:100%;width:<?= (int)$c['pct'] ?>%;background:<?= $c['color'] ?>;border-radius:99px"></div>
    </div>
  </div>
  <?php endforeach; ?>
  <!-- Dues progress card -->
  <div class="card" style="padding:.75rem 1rem;margin:0;display:flex;flex-direction:column;justify-content:space-between;gap:.4rem;min-width:0">
    <div style="font-size:.65rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.05em">Dues <?= h(membership_year()) ?></div>
    <div style="font-size:1.3rem;font-weight:700;color:<?= $dues_pct>=75?'#2e7d32':($dues_pct>=40?'#f57c00':'#c62828') ?>;line-height:1"><?= $dues_pct ?>%</div>
    <div style="font-size:.65rem;color:#9aa5b4"><?= $stat_paid ?> of <?= $active_total ?></div>
    <div style="background:#e1e5eb;border-radius:99px;height:4px;overflow:hidden">
      <div style="height:100%;width:<?= $dues_pct ?>%;background:<?= $dues_pct>=75?'#2e7d32':($dues_pct>=40?'#f57c00':'#c62828') ?>;border-radius:99px"></div>
    </div>
  </div>
</div>

<!-- ── Class year breakdown row ─────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(<?= count($stat_by_year) ?>,1fr);gap:.6rem;margin-bottom:1.25rem">
  <?php foreach ($stat_by_year as $yr => $cnt):
    $yp   = $stat_paid_by_year[$yr] ?? 0;
    $ypct = $cnt > 0 ? round($yp/$cnt*100) : 0;
    $col  = $ypct>=75?'#2e7d32':($ypct>=40?'#f57c00':'#c62828');
  ?>
  <div class="card" style="padding:.75rem 1rem;margin:0;display:flex;flex-direction:column;justify-content:space-between;gap:.4rem;min-width:0;cursor:pointer"
       onclick="document.querySelector('[name=year]').value='<?= h($yr) ?>'; document.querySelector('.filter-bar button[type=submit]').click()">
    <div style="font-size:.65rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.05em">Class of <?= h($yr) ?></div>
    <div style="font-size:1.3rem;font-weight:700;color:<?= $col ?>;line-height:1"><?= $yp ?><span style="font-size:.8rem;color:#9aa5b4"> / <?= $cnt ?></span></div>
    <div style="font-size:.65rem;color:#9aa5b4"><?= $ypct ?>% paid</div>
    <div style="background:#e1e5eb;border-radius:99px;height:4px;overflow:hidden">
      <div style="height:100%;width:<?= $ypct ?>%;background:<?= $col ?>;border-radius:99px"></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if (!empty($upcoming_bdays)): ?>
<div class="card" style="padding:0;margin-bottom:1.25rem;overflow:hidden">
  <button onclick="toggleBdays(this)" style="width:100%;background:none;border:none;padding:.85rem 1.25rem;display:flex;justify-content:space-between;align-items:center;cursor:pointer;text-align:left;font-family:inherit">
    <span style="font-size:.78rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.05em">
      🎂 Upcoming Birthdays
      <span style="background:<?= !empty(array_filter($upcoming_bdays,fn($b)=>$b['days']<=7))?'#f57c00':'#5a6a7a' ?>;color:#fff;font-size:.65rem;padding:.15rem .45rem;border-radius:99px;margin-left:.4rem;vertical-align:middle"><?= count($upcoming_bdays) ?></span>
    </span>
    <span id="bday-chevron" style="color:#5a6a7a;font-size:.85rem">▸ Show</span>
  </button>
  <div id="bday-panel" style="display:none;padding:.25rem 1.25rem 1.25rem">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:.5rem">
      <?php foreach ($upcoming_bdays as $b): ?>
      <div style="background:#f0f4ff;border:1px solid #c7d4f5;border-radius:4px;padding:.5rem .85rem;font-size:.82rem;display:flex;justify-content:space-between;align-items:center">
        <div><strong style="color:#002554"><?= h($b['name']) ?></strong><br>
          <span style="color:#5a6a7a"><?= h($b['fmt']) ?></span>
          <?php if ($b['box']): ?><span style="color:#9aa5b4"> · PO <?= h($b['box']) ?></span><?php endif; ?></div>
        <div style="white-space:nowrap;padding-left:.5rem">
          <?php if ($b['days']===0): ?><span style="color:#A6192E;font-weight:700">🎉 Today!</span>
          <?php elseif($b['days']<=7): ?><span style="color:#f57c00;font-weight:700"><?= $b['days'] ?>d</span>
          <?php else: ?><span style="color:#9aa5b4"><?= $b['days'] ?>d</span><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<script>
function toggleBdays(btn) {
  var panel=document.getElementById('bday-panel'), chev=document.getElementById('bday-chevron');
  var open=panel.style.display==='none';
  panel.style.display=open?'block':'none';
  chev.textContent=open?'▾ Hide':'▸ Show';
}
function openBirthdays() {
  var panel=document.getElementById('bday-panel'), chev=document.getElementById('bday-chevron');
  panel.style.display='block';
  chev.textContent='▾ Hide';
}
</script>
<?php endif; ?>

<!-- Filters -->
<div class="card" style="padding:1rem 1.5rem">
  <form method="GET" class="filter-bar">
    <div class="form-group" style="flex:2;min-width:200px">
      <label>Search name / email / phone</label>
      <input name="q" value="<?= h($search) ?>" placeholder="Type to search…">
    </div>
    <div class="form-group">
      <label>Class Year</label>
      <select name="year">
        <option value="">All years (excl. 2026)</option>
        <?php foreach (['2026','2027','2028','2029','2030','Prep School','Graduate'] as $y): ?>
          <option value="<?= h($y) ?>" <?= $year===$y?'selected':''?>><?= h($y) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>AL Region</label>
      <select name="region">
        <option value="">All regions</option>
        <?php foreach (['North','Central','South'] as $r): ?>
          <option value="<?= h($r) ?>" <?= $region===$r?'selected':''?>><?= h($r) ?></option>
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
    <div class="form-group">
      <label>Status</label>
      <select name="archived">
        <option value="0" <?= $archived==='0'?'selected':''?>>Active</option>
        <option value="1" <?= $archived==='1'?'selected':''?>>Archived</option>
      </select>
    </div>
    <div class="form-group">
      <label>Squadron</label>
      <select name="squadron">
        <option value="">All</option>
        <?php foreach ($squadrons as $s): ?>
          <option value="<?= h($s) ?>" <?= $squadron===$s?'selected':''?>><?= h($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex:0">
      <label>&nbsp;</label>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="index.php" class="btn btn-secondary">Clear</a>
      </div>
    </div>
  </form>
</div>

<?php if (can_mark_dues()): ?>
<!-- Bulk action form (inputs inside the table use form="bulk-form") -->
<form id="bulk-form" method="POST" action="bulk-action.php">
  <?= csrf_field() ?>
  <input type="hidden" name="membership_year" value="<?= h(membership_year()) ?>">
</form>
<?php endif; ?>

<div class="card" style="padding:0;overflow:auto">
<table>
  <thead>
    <tr>
      <?php if (can_mark_dues()): ?>
      <th style="width:36px"><input type="checkbox" id="select-all" style="width:auto;accent-color:#003594" title="Select all"></th>
      <?php endif; ?>
      <th><?= sort_link('class_year',    'Year',    $sort, $dir, $next_dir, $get_params) ?></th>
      <th><?= sort_link('cadet_last_name','Cadet',   $sort, $dir, $next_dir, $get_params) ?></th>
      <th><?= sort_link('al_region',     'Region',  $sort, $dir, $next_dir, $get_params) ?></th>
      <th>Parent 1</th>
      <th>Parent 2</th>
      <th><?= sort_link('membership_paid','Dues',    $sort, $dir, $next_dir, $get_params) ?></th>
      <th class="actions-head">Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($members)): ?>
    <tr><td colspan="<?= can_mark_dues()?8:7 ?>" style="text-align:center;padding:2rem;color:#5a6a7a">No members found.</td></tr>
  <?php endif; ?>
  <?php foreach ($members as $m): ?>
    <?php
      $sqd        = $m['squadron_yr2_4'] ?: ($m['fall_squadron'] ?: $m['bct_squadron']);
      $region_cls = $m['al_region'] ? 'badge-' . $m['al_region'] : '';
      $p1email    = $m['parent1_email'];
      $p1cell     = $m['parent1_cell'];
      $p2email    = $m['parent2_email'];
      $p2cell     = $m['parent2_cell'];
    ?>
    <tr>
      <?php if (can_mark_dues()): ?>
      <td><input type="checkbox" name="member_ids[]" value="<?= (int)$m['id'] ?>" form="bulk-form" style="width:auto;accent-color:#003594" class="row-cb"></td>
      <?php endif; ?>
      <td><?= h($m['class_year']) ?></td>
      <td>
        <a href="view.php?id=<?= (int)$m['id'] ?>" style="font-weight:700;color:#002554"><?= h($m['cadet_last_name']) ?></a><?= $m['cadet_first_middle'] ? ', ' . h($m['cadet_first_middle']) : '' ?><br>
        <?php if ($m['cadet_email']): ?><a href="mailto:<?= h($m['cadet_email']) ?>" style="font-size:.78rem;color:#5a6a7a"><?= h($m['cadet_email']) ?></a><?php endif; ?>
      </td>
      <td><?php if ($m['al_region']): ?><span class="badge <?= h($region_cls) ?>"><?= h($m['al_region']) ?></span><?php endif; ?></td>
      <td>
        <?= h(trim($m['parent1_first_name'] . ' ' . $m['parent1_last_name'])) ?><br>
        <?php if ($p1cell): ?><a href="tel:<?= h(preg_replace('/\D/','',$p1cell)) ?>" style="font-size:.78rem;color:#5a6a7a"><?= h($p1cell) ?></a><?php endif; ?>
        <?php if ($p1email): ?><br><a href="mailto:<?= h($p1email) ?>" style="font-size:.78rem;color:#5a6a7a"><?= h($p1email) ?></a><?php endif; ?>
      </td>
      <td>
        <?= h(trim($m['parent2_first_name'] . ' ' . $m['parent2_last_name'])) ?><br>
        <?php if ($p2cell): ?><a href="tel:<?= h(preg_replace('/\D/','',$p2cell)) ?>" style="font-size:.78rem;color:#5a6a7a"><?= h($p2cell) ?></a><?php endif; ?>
        <?php if ($p2email): ?><br><a href="mailto:<?= h($p2email) ?>" style="font-size:.78rem;color:#5a6a7a"><?= h($p2email) ?></a><?php endif; ?>
      </td>
      <td>
        <?php if ($m['membership_paid']): ?>
          <span class="badge badge-paid">✓ Paid</span><br>
          <span style="font-size:.72rem;color:#5a6a7a"><?= h($m['membership_year']) ?></span>
        <?php else: ?>
          <span class="badge badge-unpaid">✗ Unpaid</span>
        <?php endif; ?>
      </td>
      <td class="actions">
        <div class="btn-group">
          <?php if (can_manage_members()): ?>
          <a href="edit.php?id=<?= (int)$m['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
          <form method="POST" action="archive-member.php">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <input type="hidden" name="archived" value="<?= $m['archived'] ? 0 : 1 ?>">
            <button type="submit" class="btn btn-secondary btn-sm"
              onclick="return confirm('<?= $m['archived'] ? 'Restore' : 'Archive' ?> <?= h(addslashes($m['cadet_last_name'])) ?>?')"
            ><?= $m['archived'] ? 'Restore' : 'Archive' ?></button>
          </form>
          <form method="POST" action="delete.php" onsubmit="return confirm('Delete <?= h(addslashes($m['cadet_last_name'])) ?>? This cannot be undone.')">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
          </form>
          <?php elseif (can_mark_dues()): ?>
          <form method="POST" action="bulk-action.php">
            <?= csrf_field() ?>
            <input type="hidden" name="member_ids[]" value="<?= (int)$m['id'] ?>">
            <input type="hidden" name="membership_year" value="<?= h(membership_year()) ?>">
            <?php if ($m['membership_paid']): ?>
              <button type="submit" name="action" value="mark_unpaid" class="btn btn-secondary btn-sm">✗ Unpaid</button>
            <?php else: ?>
              <button type="submit" name="action" value="mark_paid" class="btn btn-primary btn-sm">✓ Mark Paid</button>
            <?php endif; ?>
          </form>
          <?php else: ?>
          <a href="view.php?id=<?= (int)$m['id'] ?>" class="btn btn-secondary btn-sm">View</a>
          <?php endif; ?>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if (can_mark_dues() && !empty($members)): ?>
<!-- Bulk action bar -->
<div id="bulk-bar" style="display:none;position:sticky;bottom:1rem;background:#002554;color:#fff;padding:.85rem 1.25rem;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.3);align-items:center;gap:1rem;flex-wrap:wrap;margin-top:.75rem">
  <span id="bulk-count" style="font-size:.9rem;font-weight:600"></span>
  <span style="font-size:.85rem;opacity:.75">Mark selected as paid for <strong><?= h(membership_year()) ?></strong></span>
  <button type="submit" form="bulk-form" name="action" value="mark_paid" class="btn btn-primary btn-sm">✓ Mark as Paid</button>
  <button type="submit" form="bulk-form" name="action" value="mark_unpaid" class="btn btn-secondary btn-sm">✗ Mark as Unpaid</button>
</div>

<script>
var selectAll = document.getElementById('select-all');
var checkboxes = document.querySelectorAll('.row-cb');
var bulkBar = document.getElementById('bulk-bar');
var bulkCount = document.getElementById('bulk-count');

function updateBulkBar() {
  var checked = document.querySelectorAll('.row-cb:checked').length;
  bulkBar.style.display = checked > 0 ? 'flex' : 'none';
  bulkBar.style.alignItems = 'center';
  bulkCount.textContent = checked + ' member' + (checked !== 1 ? 's' : '') + ' selected';
  selectAll.indeterminate = checked > 0 && checked < checkboxes.length;
  selectAll.checked = checked === checkboxes.length;
}

selectAll.addEventListener('change', function() {
  checkboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
  updateBulkBar();
});
checkboxes.forEach(function(cb) { cb.addEventListener('change', updateBulkBar); });
</script>
<?php endif; ?>

<?php admin_footer(); ?>

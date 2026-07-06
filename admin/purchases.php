<?php
require_once __DIR__ . '/auth.php';
require_finance();
$pdo = get_pdo();

$filter_status   = $_GET['status']   ?? '';
$filter_category = $_GET['category'] ?? '';
$filter_event    = $_GET['event']    ?? '';
$filter_from     = $_GET['from']     ?? '';
$filter_to       = $_GET['to']       ?? '';

$where  = ['1=1'];
$params = [];
if ($filter_status   !== '') { $where[] = 'p.status = :status';      $params[':status']   = $filter_status; }
if ($filter_category !== '') { $where[] = 'p.category = :cat';       $params[':cat']      = $filter_category; }
if ($filter_event    !== '') { $where[] = 'p.event = :event';        $params[':event']    = $filter_event; }
if ($filter_from     !== '') { $where[] = 'p.purchase_date >= :dfrom'; $params[':dfrom']  = $filter_from; }
if ($filter_to       !== '') { $where[] = 'p.purchase_date <= :dto';   $params[':dto']    = $filter_to; }

$sql = 'SELECT p.*, u.name as submitted_by_name
        FROM purchases p
        LEFT JOIN users u ON p.submitted_by = u.id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY p.purchase_date DESC, p.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$purchases = $stmt->fetchAll();

// Totals
$total_pretax  = array_sum(array_column($purchases, 'amount_pretax'));
$total_tax     = array_sum(array_column($purchases, 'amount_tax'));
$total_shipping= array_sum(array_column($purchases, 'amount_shipping'));
$total_all     = array_sum(array_column($purchases, 'amount_total'));

// CSV export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="purchases-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date','Vendor','Description','Event','Category','Pre-Tax','Tax','Total','Status','Submitted By','Notes']);
    foreach ($purchases as $p) {
        fputcsv($out, [$p['purchase_date'],$p['vendor'],$p['description'],$p['event'],
                       $p['category'],$p['amount_pretax'],$p['amount_tax'],$p['amount_total'],
                       $p['status'],$p['submitted_by_name'],$p['notes']]);
    }
    fclose($out); exit;
}

$status_colors = ['pending'=>'#f57c00','approved'=>'#1b5e20','reimbursed'=>'#003594'];

admin_header('Finance');
?>
<style>
.fin-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.75rem;margin-bottom:1.25rem}
.fin-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1rem;text-align:center}
.fin-amount{font-size:1.5rem;font-weight:700;color:#002554}
.fin-label{font-size:.72rem;color:#5a6a7a;text-transform:uppercase;letter-spacing:.05em;margin-top:.2rem}
.status-badge{display:inline-block;padding:.15rem .5rem;border-radius:3px;font-size:.7rem;font-weight:700;white-space:nowrap}
@media(max-width:600px){.fin-cards{grid-template-columns:1fr 1fr}}
</style>

<div class="page-head">
  <h1>Finance</h1>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <?php $ep = array_merge(array_filter(['status'=>$filter_status,'category'=>$filter_category,'event'=>$filter_event,'from'=>$filter_from,'to'=>$filter_to]),['export'=>1]); ?>
    <a href="purchases.php?<?= http_build_query($ep) ?>" class="btn btn-secondary">Export CSV</a>
    <?php if (can_manage_finances()): ?>
    <a href="purchase-form.php" class="btn btn-primary">+ Add Purchase</a>
    <?php endif; ?>
  </div>
</div>

<!-- Summary cards -->
<div class="fin-cards">
  <div class="fin-card">
    <div class="fin-amount"><?= count($purchases) ?></div>
    <div class="fin-label">Purchases</div>
  </div>
  <div class="fin-card">
    <div class="fin-amount">$<?= number_format($total_pretax, 2) ?></div>
    <div class="fin-label">Pre-Tax Total</div>
  </div>
  <div class="fin-card">
    <div class="fin-amount">$<?= number_format($total_tax, 2) ?></div>
    <div class="fin-label">Tax Paid</div>
  </div>
  <div class="fin-card">
    <div class="fin-amount">$<?= number_format($total_shipping, 2) ?></div>
    <div class="fin-label">Shipping</div>
  </div>
  <div class="fin-card" style="border:2px solid #003594">
    <div class="fin-amount" style="color:#A6192E">$<?= number_format($total_all, 2) ?></div>
    <div class="fin-label">Grand Total</div>
  </div>
</div>

<!-- Filters -->
<div class="card" style="padding:1rem 1.5rem;margin-bottom:1rem">
  <form method="GET" class="filter-bar">
    <div class="form-group">
      <label>Status</label>
      <select name="status">
        <option value="">All</option>
        <?php foreach (PURCHASE_STATUSES as $k => $v): ?>
          <option value="<?= h($k) ?>" <?= $filter_status===$k?'selected':''?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Category</label>
      <select name="category">
        <?php foreach (PURCHASE_CATEGORIES as $c): ?>
          <option value="<?= h($c) ?>" <?= $filter_category===$c?'selected':''?>><?= $c===''?'All Categories':h($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Event</label>
      <select name="event">
        <?php foreach (PURCHASE_EVENTS as $e): ?>
          <option value="<?= h($e) ?>" <?= $filter_event===$e?'selected':''?>><?= $e===''?'All Events':h($e) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>From Date</label>
      <input type="date" name="from" value="<?= h($filter_from) ?>">
    </div>
    <div class="form-group">
      <label>To Date</label>
      <input type="date" name="to" value="<?= h($filter_to) ?>">
    </div>
    <div class="form-group" style="flex:0">
      <label>&nbsp;</label>
      <div style="display:flex;gap:.5rem">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="purchases.php" class="btn btn-secondary">Clear</a>
      </div>
    </div>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto">
<table>
  <thead>
    <tr>
      <th>Date</th>
      <th>Vendor</th>
      <th>Description</th>
      <th>Event</th>
      <th>Category</th>
      <th style="text-align:right">Pre-Tax</th>
      <th style="text-align:right">Tax</th>
      <th style="text-align:right">Ship</th>
      <th style="text-align:right">Total</th>
      <th>Status</th>
      <th>By</th>
      <th class="actions-head">Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($purchases)): ?>
    <tr><td colspan="11" style="text-align:center;padding:2rem;color:#5a6a7a">No purchases found.</td></tr>
  <?php endif; ?>
  <?php foreach ($purchases as $p): ?>
    <tr>
      <td style="white-space:nowrap"><?= h(date('M j, Y', strtotime($p['purchase_date']))) ?></td>
      <td><strong><?= h($p['vendor']) ?></strong></td>
      <td style="max-width:200px"><?= h($p['description']) ?></td>
      <td style="font-size:.8rem;color:#5a6a7a"><?= h($p['event']) ?></td>
      <td style="font-size:.8rem;color:#5a6a7a"><?= h($p['category']) ?></td>
      <td style="text-align:right;white-space:nowrap">$<?= number_format($p['amount_pretax'],2) ?></td>
      <td style="text-align:right;white-space:nowrap;color:#5a6a7a">$<?= number_format($p['amount_tax'],2) ?></td>
      <td style="text-align:right;white-space:nowrap;color:#5a6a7a">$<?= number_format($p['amount_shipping'],2) ?></td>
      <td style="text-align:right;white-space:nowrap;font-weight:700">$<?= number_format($p['amount_total'],2) ?></td>
      <td>
        <span class="status-badge" style="background:<?= $status_colors[$p['status']] ?>22;color:<?= $status_colors[$p['status']] ?>">
          <?= h(PURCHASE_STATUSES[$p['status']]) ?>
        </span>
      </td>
      <td style="font-size:.78rem;color:#5a6a7a;white-space:nowrap"><?= h($p['submitted_by_name'] ?? '—') ?></td>
      <td class="actions">
        <div class="btn-group">
          <a href="purchase-form.php?id=<?= (int)$p['id'] ?>" class="btn btn-secondary btn-sm"><?= can_edit_purchase($p) ? 'Edit' : 'View' ?></a>
          <?php if ($p['status']==='pending' && is_admin()): ?>
          <form method="POST" action="purchase-action.php" style="margin:0" onsubmit="return confirm('Approve this purchase?')">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <input type="hidden" name="action" value="approve">
            <button type="submit" class="btn btn-sm" style="background:#1b5e20;color:#fff">✓ Approve</button>
          </form>
          <?php elseif ($p['status']==='approved' && is_treasurer()): ?>
          <form method="POST" action="purchase-action.php" style="margin:0" onsubmit="return confirm('Mark this purchase as reimbursed?')">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <input type="hidden" name="action" value="reimburse">
            <button type="submit" class="btn btn-sm" style="background:#003594;color:#fff">💰 Reimburse</button>
          </form>
          <?php endif; ?>
          <?php if (is_treasurer() || (is_member() && (int)($p['submitted_by']??-1)===(int)($_SESSION['user_id']??0))): ?>
          <form method="POST" action="purchase-delete.php" onsubmit="return confirm('Delete this purchase? This cannot be undone.')">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
          </form>
          <?php endif; ?>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <?php if (!empty($purchases)): ?>
  <tfoot>
    <tr style="background:#f5f7fa;font-weight:700">
      <td colspan="5" style="text-align:right;font-size:.8rem;color:#5a6a7a;padding:.75rem">TOTALS</td>
      <td style="text-align:right">$<?= number_format($total_pretax,2) ?></td>
      <td style="text-align:right;color:#5a6a7a">$<?= number_format($total_tax,2) ?></td>
      <td style="text-align:right;color:#5a6a7a">$<?= number_format($total_shipping,2) ?></td>
      <td style="text-align:right;color:#A6192E">$<?= number_format($total_all,2) ?></td>
      <td colspan="3"></td>
    </tr>
  </tfoot>
  <?php endif; ?>
</table>
</div>

<?php admin_footer(); ?>

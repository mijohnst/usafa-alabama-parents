<?php
require_once __DIR__ . '/auth.php';
require_finance();
$pdo = get_pdo();

$pending_count = (int)$pdo->query("SELECT COUNT(*) FROM purchases WHERE status='approved'")->fetchColumn();
$filter_status   = $_GET['status']   ?? '';
$filter_category = $_GET['category'] ?? '';
$filter_event    = $_GET['event']    ?? '';
$filter_from     = $_GET['from']     ?? '';
$filter_to       = $_GET['to']       ?? '';
$filter_search   = trim($_GET['q']   ?? '');

$where  = ['1=1'];
$params = [];
if ($filter_status   !== '') { $where[] = 'p.status = :status';        $params[':status']   = $filter_status; }
if ($filter_category !== '') { $where[] = 'p.category = :cat';         $params[':cat']      = $filter_category; }
if ($filter_event    !== '') { $where[] = 'p.event = :event';          $params[':event']    = $filter_event; }
if ($filter_from     !== '') { $where[] = 'p.purchase_date >= :dfrom'; $params[':dfrom']    = $filter_from; }
if ($filter_to       !== '') { $where[] = 'p.purchase_date <= :dto';   $params[':dto']      = $filter_to; }
if ($filter_search   !== '') {
    $where[] = '(p.vendor LIKE :q OR p.description LIKE :q OR p.order_number LIKE :q OR p.notes LIKE :q)';
    $params[':q'] = '%' . $filter_search . '%';
}

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
    <a href="report.php" class="btn btn-secondary">📊 Report</a>
    <a href="year-end.php" class="btn btn-secondary">📋 Year-End</a>
    <?php if (is_treasurer()): ?>
    <a href="pending-reimbursements.php" class="btn btn-secondary" style="<?= $pending_count>0?'background:#e3f2fd;border-color:#90caf9':'' ?>">
      💰 Reimburse<?= $pending_count>0?" ($pending_count)":" (0)" ?>
    </a>
    <?php endif; ?>
    <?php if (is_admin() || is_treasurer()): ?>
    <a href="budgets.php" class="btn btn-secondary">🎯 Budgets</a>
    <?php endif; ?>
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
    <div class="form-group" style="flex:2;min-width:180px">
      <label>Search vendor / description</label>
      <input name="q" value="<?= h($filter_search) ?>" placeholder="Type to search…">
    </div>
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
          <?php if ($p['status']==='pending' && is_super_admin()): ?>
          <form id="af-<?= (int)$p['id'] ?>" method="POST" action="purchase-action.php" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="note" id="an-<?= (int)$p['id'] ?>">
            <button type="button" class="btn btn-sm" style="background:#1b5e20;color:#fff;white-space:nowrap"
              onclick="doAction('af-<?= (int)$p['id'] ?>','an-<?= (int)$p['id'] ?>','Approve note (optional):','Approve this purchase?'<?= !$p['receipt_filename']?", '⚠️ No receipt attached. '":'' ?>)">✓ Approve</button>
          </form>
          <?php elseif ($p['status']==='approved' && is_treasurer()): ?>
          <form id="rf-<?= (int)$p['id'] ?>" method="POST" action="purchase-action.php" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <input type="hidden" name="action" value="reimburse">
            <input type="hidden" name="note" id="rn-<?= (int)$p['id'] ?>">
            <input type="hidden" name="payment_method" id="rpm-<?= (int)$p['id'] ?>">
            <button type="button" class="btn btn-sm" style="background:#003594;color:#fff;white-space:nowrap"
              onclick="openReimburseModal(<?= (int)$p['id'] ?>, '<?= h(addslashes($p['vendor'])) ?>', '$<?= number_format($p['amount_total'],2) ?>')">
              💰 Reimburse</button>
          </form>
          <?php endif; ?>
          <?php
            $own_purchase = (int)($p['submitted_by']??-1)===(int)($_SESSION['user_id']??0);
            if (is_treasurer() || ((is_member()||is_secretary()) && $own_purchase)): ?>
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

<!-- Reimburse modal -->
<div id="reimburse-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,.25);padding:1.75rem;max-width:420px;width:90%;margin:1rem">
    <h2 style="font-size:1rem;color:#002554;margin-bottom:.25rem">Mark as Reimbursed</h2>
    <p id="reimburse-modal-desc" style="font-size:.85rem;color:#5a6a7a;margin-bottom:1.25rem"></p>
    <div class="form-group">
      <label>Payment Method *</label>
      <select id="modal-payment-method" onchange="updateModalFields()" style="width:100%;padding:.6rem .75rem;border:1px solid #d0d5dd;border-radius:4px;font-family:inherit;font-size:.9rem">
        <?php foreach (PAYMENT_METHODS as $pm): ?>
          <option value="<?= h($pm) ?>"><?= $pm === '' ? '— select method —' : h($pm) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div id="modal-check-row" style="display:none;margin-bottom:.9rem">
      <label style="display:block;font-size:.78rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem">Check Number *</label>
      <input type="text" id="modal-check-number" placeholder="e.g. 1042" style="width:100%;padding:.6rem .75rem;border:1px solid #d0d5dd;border-radius:4px;font-family:inherit;font-size:.9rem">
    </div>
    <div id="modal-other-row" style="display:none;margin-bottom:.9rem">
      <label style="display:block;font-size:.78rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem">Explanation *</label>
      <input type="text" id="modal-other-text" placeholder="Describe the payment method…" style="width:100%;padding:.6rem .75rem;border:1px solid #d0d5dd;border-radius:4px;font-family:inherit;font-size:.9rem">
    </div>
    <div class="form-group">
      <label id="modal-note-label">Note <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">optional</span></label>
      <input type="text" id="modal-note" placeholder="Optional note…" style="width:100%;padding:.6rem .75rem;border:1px solid #d0d5dd;border-radius:4px;font-family:inherit;font-size:.9rem">
    </div>
    <div style="display:flex;gap:.75rem;margin-top:1.25rem">
      <button onclick="confirmReimburse()" class="btn btn-primary" style="flex:1">Confirm Reimbursement</button>
      <button onclick="closeReimburseModal()" class="btn btn-secondary">Cancel</button>
    </div>
  </div>
</div>

<script>
var _reimburseId = null;

function updateModalFields() {
  var m = document.getElementById('modal-payment-method').value;
  document.getElementById('modal-check-row').style.display = m === 'Check' ? 'block' : 'none';
  document.getElementById('modal-other-row').style.display = m === 'Other' ? 'block' : 'none';
  var noteLabel = document.getElementById('modal-note-label');
  var noteInput = document.getElementById('modal-note');
  noteLabel.textContent = m === 'Internet Transfer' ? 'Transfer Reference / Details *' : 'Note (optional)';
  noteLabel.style.color = m === 'Internet Transfer' ? '#002554' : '#5a6a7a';
  noteInput.placeholder = m === 'Internet Transfer' ? 'e.g. Confirmation #12345, bank reference…' : 'Optional note…';
}

function openReimburseModal(id, vendor, amount) {
  _reimburseId = id;
  document.getElementById('reimburse-modal-desc').textContent = vendor + ' — ' + amount;
  document.getElementById('modal-payment-method').value = '';
  document.getElementById('modal-check-number').value = '';
  document.getElementById('modal-other-text').value = '';
  document.getElementById('modal-note').value = '';
  updateModalFields();
  document.getElementById('reimburse-modal').style.display = 'flex';
}

function closeReimburseModal() {
  document.getElementById('reimburse-modal').style.display = 'none';
  _reimburseId = null;
}

function confirmReimburse() {
  var method = document.getElementById('modal-payment-method').value;
  if (!method) { alert('Please select a payment method.'); return; }
  var fullMethod = method;
  if (method === 'Check') {
    var num = document.getElementById('modal-check-number').value.trim();
    if (!num) { alert('Please enter the check number.'); return; }
    fullMethod = 'Check #' + num;
  } else if (method === 'Other') {
    var expl = document.getElementById('modal-other-text').value.trim();
    if (!expl) { alert('Please explain the payment method.'); return; }
    fullMethod = 'Other: ' + expl;
  }
  var note = document.getElementById('modal-note').value.trim();
  if (method === 'Internet Transfer' && !note) { alert('Please enter the transfer reference or details.'); return; }
  document.getElementById('rpm-' + _reimburseId).value = fullMethod;
  document.getElementById('rn-'  + _reimburseId).value = note;
  closeReimburseModal();
  document.getElementById('rf-' + _reimburseId).submit();
}

// Close on backdrop click
document.getElementById('reimburse-modal').addEventListener('click', function(e) {
  if (e.target === this) closeReimburseModal();
});

function doAction(formId, noteId, notePrompt, confirmMsg, notePrefix) {
  var note = prompt(notePrompt, '');
  if (note === null) return;
  if (notePrefix) note = notePrefix + note;
  document.getElementById(noteId).value = note;
  if (!confirm(confirmMsg)) return;
  document.getElementById(formId).submit();
}
</script>
<?php admin_footer(); ?>

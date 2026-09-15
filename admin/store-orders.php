<?php
/**
 * Club Store — Order Ledger
 * Two distinct views, not one: (1) the per-order list/detail below, for
 * customer info/payment status/fulfillment tracking; (2) a separate
 * pivoted "Vendor Export" view aggregating quantity by product+variant
 * across a date range — a flat per-order-item list is the wrong shape for
 * "how many Large Navy shirts do I order from the vendor," so that gets
 * its own report rather than being an afterthought on this one.
 */
require_once __DIR__ . '/auth.php';
require_store_admin();
$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'update_fulfillment') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['fulfillment_status'] ?? '';
        if (isset(STORE_FULFILLMENT_STATUSES[$status])) {
            $pdo->prepare('UPDATE store_orders SET fulfillment_status = ? WHERE id = ?')->execute([$status, $id]);
            flash('success', 'Fulfillment status updated.');
        } else {
            flash('error', 'Invalid status.');
        }
        header('Location: store-orders.php' . ($id ? "?view=$id" : '')); exit;
    }
}

$view_mode = $_GET['mode'] ?? 'orders';

if ($view_mode === 'vendor') {
    // Pivoted: total quantity by product/variant across every order in a
    // paid state, restricted to a date range — the shape a treasurer
    // actually needs to place a bulk order with a merch vendor.
    $from = $_GET['from'] ?? date('Y-m-01');
    $to   = $_GET['to']   ?? date('Y-m-d');
    $stmt = $pdo->prepare(
        "SELECT oi.product_name_snapshot, oi.variant_label_snapshot, SUM(oi.quantity) AS total_qty, COUNT(DISTINCT oi.order_id) AS order_count
         FROM store_order_items oi
         JOIN store_orders o ON o.id = oi.order_id
         WHERE o.status = 'captured' AND DATE(o.created_at) BETWEEN ? AND ?
         GROUP BY oi.product_name_snapshot, oi.variant_label_snapshot
         ORDER BY oi.product_name_snapshot ASC, oi.variant_label_snapshot ASC"
    );
    $stmt->execute([$from, $to]);
    $pivot = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Must happen before admin_header() outputs any HTML — a CSV download
    // needs to set its Content-Type/Content-Disposition headers first,
    // same as income.php's export handling.
    if (isset($_GET['export'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="store-vendor-export-' . $from . '-to-' . $to . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Product', 'Size/Color', 'Total Quantity', 'Orders']);
        foreach ($pivot as $row) fputcsv($out, [$row['product_name_snapshot'], $row['variant_label_snapshot'], $row['total_qty'], $row['order_count']]);
        fclose($out); exit;
    }

    admin_header('Club Store — Vendor Export');
    ?>
    <div class="page-head">
      <h1>🏭 Club Store — Vendor Export</h1>
      <a href="store-orders.php" class="btn btn-secondary">← Order Ledger</a>
    </div>
    <form method="GET" class="card" style="max-width:500px;display:flex;gap:.75rem;align-items:flex-end;margin-bottom:1.25rem">
      <input type="hidden" name="mode" value="vendor">
      <div class="form-group" style="margin:0">
        <label style="font-size:.72rem">From</label>
        <input type="date" name="from" value="<?= h($from) ?>">
      </div>
      <div class="form-group" style="margin:0">
        <label style="font-size:.72rem">To</label>
        <input type="date" name="to" value="<?= h($to) ?>">
      </div>
      <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
      <a href="store-orders.php?mode=vendor&from=<?= h($from) ?>&to=<?= h($to) ?>&export=1" class="btn btn-secondary btn-sm">Export CSV</a>
    </form>
    <div class="card" style="padding:0;overflow-x:auto">
    <table class="sp-table" style="width:100%;border-collapse:collapse">
      <thead><tr><th>Product</th><th>Size / Color</th><th style="text-align:right">Total Qty</th><th style="text-align:right">Orders</th></tr></thead>
      <tbody>
        <?php foreach ($pivot as $row): ?>
        <tr>
          <td style="padding:.55rem .9rem;border-top:1px solid #f0f2f5;font-weight:600"><?= h($row['product_name_snapshot']) ?></td>
          <td style="padding:.55rem .9rem;border-top:1px solid #f0f2f5;color:#5a6a7a"><?= h($row['variant_label_snapshot'] ?: '—') ?></td>
          <td style="padding:.55rem .9rem;border-top:1px solid #f0f2f5;text-align:right;font-weight:700"><?= (int)$row['total_qty'] ?></td>
          <td style="padding:.55rem .9rem;border-top:1px solid #f0f2f5;text-align:right;color:#5a6a7a"><?= (int)$row['order_count'] ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($pivot)): ?>
        <tr><td colspan="4" style="text-align:center;color:#9aa5b4;padding:1.5rem">No paid orders in this date range.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    </div>
    <?php admin_footer(); exit;
}

// ── Per-order list/detail view ───────────────────────────────────────────────
$viewing = null;
$view_items = [];
if (isset($_GET['view'])) {
    $stmt = $pdo->prepare('SELECT * FROM store_orders WHERE id = ?');
    $stmt->execute([(int)$_GET['view']]);
    $viewing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($viewing) {
        $istmt = $pdo->prepare('SELECT * FROM store_order_items WHERE order_id = ? ORDER BY id ASC');
        $istmt->execute([$viewing['id']]);
        $view_items = $istmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$filter_status = $_GET['status'] ?? '';
$where = ['1=1']; $params = [];
if ($filter_status !== '') { $where[] = 'status = ?'; $params[] = $filter_status; }
$orders = $pdo->prepare('SELECT * FROM store_orders WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC');
$orders->execute($params);
$orders = $orders->fetchAll(PDO::FETCH_ASSOC);

// Item count per order, for the list view, without an N+1 query per row.
$counts = [];
if ($orders) {
    $ids = array_column($orders, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $cstmt = $pdo->prepare("SELECT order_id, SUM(quantity) qty FROM store_order_items WHERE order_id IN ($ph) GROUP BY order_id");
    $cstmt->execute($ids);
    foreach ($cstmt->fetchAll(PDO::FETCH_ASSOC) as $r) $counts[$r['order_id']] = (int)$r['qty'];
}

admin_header('Club Store — Order Ledger');
echo show_flash();
$status_colors = ['created' => '#8A8D8F', 'processing' => '#f57c00', 'captured' => '#1b5e20', 'amount_mismatch' => '#c62828', 'capture_ok_apply_failed' => '#c62828', 'needs_manual_review' => '#c62828'];
$fulfillment_colors = ['pending' => '#f57c00', 'ready_for_pickup' => '#1565c0', 'picked_up' => '#1b5e20'];
?>
<style>
.sp-table td,.sp-table th{padding:.55rem .9rem}
.sp-table th{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5a6a7a;background:#f7f9fc;white-space:nowrap}
.sp-table td{border-top:1px solid #f0f2f5;font-size:.84rem;vertical-align:middle}
.status-pill{display:inline-block;padding:.2rem .6rem;border-radius:99px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:#fff;white-space:nowrap}
</style>

<div class="page-head">
  <h1>📦 Club Store — Order Ledger</h1>
  <div style="display:flex;gap:.5rem">
    <a href="store-orders.php?mode=vendor" class="btn btn-secondary">🏭 Vendor Export</a>
    <a href="store-products.php" class="btn btn-secondary">🛍️ Products</a>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>

<?php if ($viewing): ?>
<div class="card" style="max-width:700px">
  <h2 style="margin-bottom:1rem">Order #<?= (int)$viewing['id'] ?></h2>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.25rem;font-size:.85rem">
    <div><strong>Customer:</strong> <?= h($viewing['customer_name']) ?></div>
    <div><strong>Email:</strong> <?= h($viewing['customer_email']) ?></div>
    <div><strong>Phone:</strong> <?= h($viewing['customer_phone'] ?: '—') ?></div>
    <div><strong>Placed:</strong> <?= h(date('M j, Y g:ia', strtotime($viewing['created_at']))) ?></div>
    <div><strong>Payment:</strong> <span class="status-pill" style="background:<?= $status_colors[$viewing['status']] ?? '#5a6a7a' ?>"><?= h(STORE_ORDER_STATUSES[$viewing['status']] ?? $viewing['status']) ?></span></div>
    <div><strong>Funding:</strong> <?= h($viewing['funding_source'] ?: '—') ?></div>
  </div>

  <h3 style="font-size:.85rem;margin-bottom:.5rem">Items</h3>
  <table style="width:100%;border-collapse:collapse;margin-bottom:1.25rem">
    <thead><tr><th style="text-align:left;font-size:.72rem;color:#5a6a7a">Item</th><th style="text-align:right;font-size:.72rem;color:#5a6a7a">Qty</th><th style="text-align:right;font-size:.72rem;color:#5a6a7a">Unit</th><th style="text-align:right;font-size:.72rem;color:#5a6a7a">Total</th></tr></thead>
    <tbody>
      <?php foreach ($view_items as $it): ?>
      <tr>
        <td style="padding:.4rem 0;border-top:1px solid #f0f2f5"><?= h($it['product_name_snapshot']) ?><?php if ($it['variant_label_snapshot']): ?><div style="font-size:.75rem;color:#5a6a7a"><?= h($it['variant_label_snapshot']) ?></div><?php endif; ?></td>
        <td style="padding:.4rem 0;border-top:1px solid #f0f2f5;text-align:right"><?= (int)$it['quantity'] ?></td>
        <td style="padding:.4rem 0;border-top:1px solid #f0f2f5;text-align:right">$<?= number_format($it['unit_price'], 2) ?></td>
        <td style="padding:.4rem 0;border-top:1px solid #f0f2f5;text-align:right;font-weight:700">$<?= number_format($it['line_total'], 2) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="3" style="text-align:right;padding-top:.5rem;font-weight:700">Total</td><td style="text-align:right;padding-top:.5rem;font-weight:700">$<?= number_format($viewing['total'], 2) ?></td></tr>
    </tfoot>
  </table>

  <form method="POST" style="display:flex;gap:.6rem;align-items:center">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update_fulfillment">
    <input type="hidden" name="id" value="<?= (int)$viewing['id'] ?>">
    <label style="font-size:.82rem;font-weight:600">Fulfillment:</label>
    <select name="fulfillment_status">
      <?php foreach (STORE_FULFILLMENT_STATUSES as $k => $v): ?>
      <option value="<?= h($k) ?>" <?= $viewing['fulfillment_status'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">Update</button>
  </form>
  <a href="store-orders.php" class="btn btn-secondary btn-sm" style="margin-top:1rem;display:inline-block">← Back to Ledger</a>
</div>
<?php else: ?>

<form method="GET" style="margin-bottom:1.25rem">
  <label style="font-size:.72rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;margin-right:.5rem">Payment Status</label>
  <select name="status" onchange="this.form.submit()">
    <option value="">All</option>
    <?php foreach (STORE_ORDER_STATUSES as $k => $v): ?>
    <option value="<?= h($k) ?>" <?= $filter_status === $k ? 'selected' : '' ?>><?= h($v) ?></option>
    <?php endforeach; ?>
  </select>
</form>

<div class="card" style="padding:0;overflow-x:auto">
<table class="sp-table" style="width:100%;border-collapse:collapse">
  <thead><tr><th>Date</th><th>Customer</th><th>Items</th><th style="text-align:right">Total</th><th>Payment</th><th>Fulfillment</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($orders as $o): ?>
    <tr>
      <td style="white-space:nowrap"><?= h(date('M j, Y', strtotime($o['created_at']))) ?></td>
      <td style="font-weight:600"><?= h($o['customer_name']) ?><div style="font-size:.72rem;color:#5a6a7a"><?= h($o['customer_email']) ?></div></td>
      <td><?= (int)($counts[$o['id']] ?? 0) ?></td>
      <td style="text-align:right;font-weight:700">$<?= number_format($o['total'], 2) ?></td>
      <td><span class="status-pill" style="background:<?= $status_colors[$o['status']] ?? '#5a6a7a' ?>"><?= h(STORE_ORDER_STATUSES[$o['status']] ?? $o['status']) ?></span></td>
      <td><span class="status-pill" style="background:<?= $fulfillment_colors[$o['fulfillment_status']] ?? '#5a6a7a' ?>"><?= h(STORE_FULFILLMENT_STATUSES[$o['fulfillment_status']] ?? $o['fulfillment_status']) ?></span></td>
      <td><a href="store-orders.php?view=<?= (int)$o['id'] ?>" class="btn btn-secondary btn-sm">View</a></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($orders)): ?>
    <tr><td colspan="7" style="text-align:center;color:#9aa5b4;padding:1.5rem">No orders yet.</td></tr>
    <?php endif; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php admin_footer(); ?>

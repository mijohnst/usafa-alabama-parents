<?php
require_once __DIR__ . '/auth.php';
require_finance();
$pdo = get_pdo();

$id      = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$p       = [];
$errors  = [];
$is_edit = false;

if ($id) {
    $stmt = $pdo->prepare('SELECT p.*, u.name as submitted_by_name FROM purchases p LEFT JOIN users u ON p.submitted_by=u.id WHERE p.id=?');
    $stmt->execute([$id]);
    $p = $stmt->fetch();
    if (!$p) { flash('error','Purchase not found.'); header('Location: purchases.php'); exit; }
    $is_edit = true;
}

// Upload helper
function handle_receipt_upload(): ?string {
    if (empty($_FILES['receipt']['name'])) return null;
    $file = $_FILES['receipt'];
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    $allowed = ['image/jpeg','image/png','image/gif','application/pdf'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed)) return null;
    if ($file['size'] > 10 * 1024 * 1024) return null; // 10MB max
    $ext      = $mime === 'application/pdf' ? 'pdf' : 'jpg';
    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest     = __DIR__ . '/receipts/' . $filename;
    if (!is_dir(__DIR__ . '/receipts')) mkdir(__DIR__ . '/receipts', 0755, true);
    move_uploaded_file($file['tmp_name'], $dest);
    return $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $vendor       = trim($_POST['vendor']      ?? '');
    $description  = trim($_POST['description'] ?? '');
    $event        = trim($_POST['event']       ?? '');
    $category     = trim($_POST['category']    ?? '');
    $date         = trim($_POST['purchase_date'] ?? '');
    $pretax       = (float)str_replace(',','', $_POST['amount_pretax'] ?? '0');
    $tax          = (float)str_replace(',','', $_POST['amount_tax']    ?? '0');
    $total        = round($pretax + $tax, 2);
    $status       = $_POST['status'] ?? 'pending';
    $notes        = trim($_POST['notes'] ?? '');
    $submitted_by = (int)($_POST['submitted_by'] ?? $_SESSION['user_id'] ?? 0);

    if (!$vendor)      $errors[] = 'Vendor is required.';
    if (!$description) $errors[] = 'Description is required.';
    if (!$date)        $errors[] = 'Date is required.';
    if ($pretax < 0)   $errors[] = 'Pre-tax amount cannot be negative.';
    if (!in_array($status, array_keys(PURCHASE_STATUSES))) $status = 'pending';

    $new_receipt = null;
    if (!empty($_FILES['receipt']['name'])) {
        $new_receipt = handle_receipt_upload();
        if (!$new_receipt) $errors[] = 'Receipt upload failed. Use JPG, PNG or PDF under 10MB.';
    }

    if (empty($errors)) {
        $receipt_filename = $new_receipt ?? ($p['receipt_filename'] ?? null);

        // Delete old receipt if replaced
        if ($new_receipt && $is_edit && !empty($p['receipt_filename'])) {
            @unlink(__DIR__ . '/receipts/' . $p['receipt_filename']);
        }

        if ($is_edit) {
            $pdo->prepare('UPDATE purchases SET vendor=?,description=?,event=?,category=?,purchase_date=?,amount_pretax=?,amount_tax=?,amount_total=?,receipt_filename=?,submitted_by=?,status=?,notes=?,updated_at=NOW() WHERE id=?')
                ->execute([$vendor,$description,$event,$category,$date,$pretax,$tax,$total,$receipt_filename,$submitted_by,$status,$notes,$id]);
            flash('success','Purchase updated.');
        } else {
            $pdo->prepare('INSERT INTO purchases (vendor,description,event,category,purchase_date,amount_pretax,amount_tax,amount_total,receipt_filename,submitted_by,status,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$vendor,$description,$event,$category,$date,$pretax,$tax,$total,$receipt_filename,$submitted_by ?: null,$status,$notes]);
            flash('success','Purchase added.');
        }
        header('Location: purchases.php'); exit;
    }

    // Re-populate from POST on error
    $p = array_merge($p, compact('vendor','description','event','category','date','pretax','tax','total','status','notes','submitted_by'));
    $p['purchase_date']  = $date;
    $p['amount_pretax']  = $pretax;
    $p['amount_tax']     = $tax;
    $p['amount_total']   = $total;
}

$v = fn(string $k) => h((string)($p[$k] ?? ''));

// Load users for submitted_by dropdown
$users_list = $pdo->query('SELECT id,name FROM users WHERE active=1 ORDER BY name')->fetchAll();

$title = $is_edit ? 'Edit Purchase' : 'Add Purchase';
admin_header($title);
?>
<style>
.receipt-preview{margin-top:.5rem;max-width:100%;border-radius:4px;border:1px solid #e1e5eb}
.amount-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.9rem}
@media(max-width:500px){.amount-row{grid-template-columns:1fr}}
.total-display{background:#f0f4ff;border:2px solid #003594;border-radius:4px;padding:.6rem .9rem;font-size:1.2rem;font-weight:700;color:#002554;text-align:center}
</style>

<div class="page-head">
  <h1><?= $title ?></h1>
  <a href="purchases.php" class="btn btn-secondary">← Back</a>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars',$errors)) ?></div>
<?php endif; ?>

<div class="card" style="max-width:700px">
  <form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <?php if ($is_edit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

    <fieldset><legend>Purchase Details</legend>
      <div class="form-row col-2">
        <div class="form-group">
          <label>Vendor *</label>
          <input name="vendor" value="<?= $v('vendor') ?>" required placeholder="e.g. Walmart, Amazon">
        </div>
        <div class="form-group">
          <label>Date *</label>
          <input type="date" name="purchase_date" value="<?= $v('purchase_date') ?>" required>
        </div>
      </div>
      <div class="form-group">
        <label>Description *</label>
        <input name="description" value="<?= $v('description') ?>" required placeholder="What was purchased">
      </div>
      <div class="form-row col-2">
        <div class="form-group">
          <label>Event</label>
          <select name="event">
            <?php foreach (PURCHASE_EVENTS as $e): ?>
              <option value="<?= h($e) ?>" <?= ($p['event']??'')===$e?'selected':''?>><?= $e===''?'— select event —':h($e) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Category</label>
          <select name="category">
            <?php foreach (PURCHASE_CATEGORIES as $c): ?>
              <option value="<?= h($c) ?>" <?= ($p['category']??'')===$c?'selected':''?>><?= $c===''?'— select category —':h($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </fieldset>

    <fieldset><legend>Amounts</legend>
      <div class="amount-row">
        <div class="form-group">
          <label>Pre-Tax Amount *</label>
          <input type="number" name="amount_pretax" id="pretax" value="<?= $v('amount_pretax') ?>"
                 step="0.01" min="0" required placeholder="0.00" oninput="calcTotal()">
        </div>
        <div class="form-group">
          <label>Tax</label>
          <input type="number" name="amount_tax" id="tax_amt" value="<?= $v('amount_tax') ?>"
                 step="0.01" min="0" placeholder="0.00" oninput="calcTotal()">
        </div>
        <div class="form-group">
          <label>Total</label>
          <div class="total-display" id="total-display">
            $<?= number_format((float)($p['amount_total'] ?? 0), 2) ?>
          </div>
        </div>
      </div>
    </fieldset>

    <fieldset><legend>Receipt &amp; Status</legend>
      <div class="form-row col-2">
        <div class="form-group">
          <label>Status</label>
          <select name="status">
            <?php foreach (PURCHASE_STATUSES as $k => $v2): ?>
              <option value="<?= h($k) ?>" <?= ($p['status']??'pending')===$k?'selected':''?>><?= h($v2) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Submitted By</label>
          <select name="submitted_by">
            <?php foreach ($users_list as $u): ?>
              <option value="<?= $u['id'] ?>" <?= (int)($p['submitted_by']??$_SESSION['user_id']??0)===(int)$u['id']?'selected':''?>><?= h($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Receipt Photo or PDF <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.75rem;color:#5a6a7a">— tap to photograph with phone camera</span></label>
        <input type="file" name="receipt" accept="image/*,application/pdf" capture="environment"
               style="padding:.5rem;font-size:.9rem">
        <?php if ($is_edit && !empty($p['receipt_filename'])): ?>
          <div style="margin-top:.5rem;font-size:.82rem">
            Current: <a href="receipt-view.php?id=<?= $id ?>" target="_blank" style="color:#003594">View Receipt</a>
            <span style="color:#9aa5b4"> — upload a new file to replace it</span>
          </div>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label>Notes</label>
        <textarea name="notes" rows="3" placeholder="Any additional details…"><?= $v('notes') ?></textarea>
      </div>
    </fieldset>

    <div style="display:flex;gap:.75rem;flex-wrap:wrap">
      <button type="submit" class="btn btn-primary"><?= $is_edit ? 'Save Changes' : 'Add Purchase' ?></button>
      <a href="purchases.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>

<script>
function calcTotal() {
  var pre = parseFloat(document.getElementById('pretax').value)   || 0;
  var tax = parseFloat(document.getElementById('tax_amt').value) || 0;
  document.getElementById('total-display').textContent = '$' + (pre + tax).toFixed(2);
}
</script>

<?php admin_footer(); ?>

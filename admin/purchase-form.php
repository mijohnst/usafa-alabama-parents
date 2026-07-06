<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
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
    $is_edit     = true;
    $read_only   = !can_edit_purchase($p);
}

// Upload helper
function handle_receipt_upload(string $key = 'receipt'): ?string {
    if (empty($_FILES[$key]['name'])) return null;
    $file = $_FILES[$key];
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    $allowed = ['image/jpeg','image/png','image/gif','application/pdf'];
    $finfo   = finfo_open(FILEINFO_MIME_TYPE);
    $mime    = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
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
    if ($is_edit && ($read_only ?? false)) {
        flash('error','You can only edit your own purchases.');
        header('Location: purchases.php'); exit;
    }
    csrf_verify();

    $vendor        = trim($_POST['vendor']        ?? '');
    $order_number  = trim($_POST['order_number']  ?? '');
    $description   = trim($_POST['description']   ?? '');
    $event         = trim($_POST['event']         ?? '');
    $category      = trim($_POST['category']      ?? '');
    $date          = trim($_POST['purchase_date'] ?? '');
    $pretax        = (float)str_replace(',','', $_POST['amount_pretax']    ?? '0');
    $tax           = (float)str_replace(',','', $_POST['amount_tax']       ?? '0');
    $shipping      = (float)str_replace(',','', $_POST['amount_shipping']  ?? '0');
    $total         = round($pretax + $tax + $shipping, 2);
    $status           = $_POST['status']                    ?? 'pending';
    $notes            = trim($_POST['notes']               ?? '');
    $receipt_required = isset($_POST['receipt_required'])   ? 1 : 0;
    $submitted_by     = (int)($_POST['submitted_by']        ?? $_SESSION['user_id'] ?? 0);

    if (!$vendor)      $errors[] = 'Vendor is required.';
    if (!$description) $errors[] = 'Description is required.';
    if (!$date)        $errors[] = 'Date is required.';
    if ($pretax < 0)   $errors[] = 'Pre-tax amount cannot be negative.';
    if (!in_array($status, array_keys(PURCHASE_STATUSES))) $status = 'pending';

    // Accept from either camera or file picker input
    $new_receipt = null;
    $upload_key  = !empty($_FILES['receipt']['name']) ? 'receipt' : (!empty($_FILES['receipt_file']['name']) ? 'receipt_file' : null);
    if ($upload_key) {
        $new_receipt = handle_receipt_upload($upload_key);
        if (!$new_receipt) $errors[] = 'Receipt upload failed. Use JPG, PNG or PDF under 10MB.';
    }

    if (empty($errors)) {
        $receipt_filename = $new_receipt ?? ($p['receipt_filename'] ?? null);

        // Delete old receipt if replaced
        if ($new_receipt && $is_edit && !empty($p['receipt_filename'])) {
            @unlink(__DIR__ . '/receipts/' . $p['receipt_filename']);
        }

        if ($is_edit) {
            // Capture old status before update for change detection
            $old = $pdo->prepare('SELECT status FROM purchases WHERE id=?');
            $old->execute([$id]);
            $old_status = $old->fetchColumn();

            $pdo->prepare('UPDATE purchases SET vendor=?,order_number=?,description=?,event=?,category=?,purchase_date=?,amount_pretax=?,amount_tax=?,amount_shipping=?,amount_total=?,receipt_filename=?,submitted_by=?,status=?,notes=?,receipt_required=?,updated_at=NOW() WHERE id=?')
                ->execute([$vendor,$order_number,$description,$event,$category,$date,$pretax,$tax,$shipping,$total,$receipt_filename,$submitted_by,$status,$notes,$receipt_required,$id]);
            flash('success','Purchase updated.');

            // Notify submitter if status changed
            if ($old_status !== $status) {
                $updated = $pdo->prepare('SELECT * FROM purchases WHERE id=?');
                $updated->execute([$id]);
                notify_status_change($pdo, $updated->fetch(), $old_status, $status, current_user_name());
            }
        } else {
            $pdo->prepare('INSERT INTO purchases (vendor,order_number,description,event,category,purchase_date,amount_pretax,amount_tax,amount_shipping,amount_total,receipt_filename,submitted_by,status,notes,receipt_required) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$vendor,$order_number,$description,$event,$category,$date,$pretax,$tax,$shipping,$total,$receipt_filename,$submitted_by ?: null,$status,$notes,$receipt_required]);
            $new_id = (int)$pdo->lastInsertId();
            flash('success','Purchase added.');

            // Notify treasurers/admins of new submission
            $new_p = $pdo->prepare('SELECT * FROM purchases WHERE id=?');
            $new_p->execute([$new_id]);
            notify_new_purchase($pdo, $new_p->fetch(), current_user_name());
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

$read_only = $read_only ?? false;
$title = $read_only ? 'View Purchase' : ($is_edit ? 'Edit Purchase' : 'Add Purchase');
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

<?php if ($read_only): ?>
<div class="alert alert-error" style="max-width:700px;background:#fff8e1;border-left-color:#ffc107;color:#5f4c00">
  👁 You are viewing this purchase in read-only mode.
</div>
<?php endif; ?>
<div class="card" style="max-width:700px">
  <form method="POST" enctype="multipart/form-data"><?php if ($read_only) echo '<fieldset disabled style="border:none;padding:0;margin:0">'; ?>
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
      <div class="form-row col-2">
        <div class="form-group">
          <label>Order Number <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">optional</span></label>
          <input name="order_number" value="<?= $v('order_number') ?>" placeholder="e.g. 123-4567890-1234567">
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
          <label>Tax <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">optional</span></label>
          <input type="number" name="amount_tax" id="tax_amt" value="<?= $v('amount_tax') ?>"
                 step="0.01" min="0" placeholder="0.00" oninput="calcTotal()">
        </div>
        <div class="form-group">
          <label>Shipping <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">optional</span></label>
          <input type="number" name="amount_shipping" id="shipping_amt" value="<?= $v('amount_shipping') ?>"
                 step="0.01" min="0" placeholder="0.00" oninput="calcTotal()">
        </div>
      </div>
      <div class="form-group" style="margin-top:.25rem">
        <label>Total</label>
        <div class="total-display" id="total-display">
          $<?= number_format((float)($p['amount_total'] ?? 0), 2) ?>
        </div>
      </div>
    </fieldset>

    <fieldset><legend>Receipt &amp; Status</legend>
      <?php $cur_status = $p['status'] ?? 'pending';
            $status_steps = ['pending'=>0,'approved'=>1,'reimbursed'=>2]; ?>
      <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap">
        <?php foreach (PURCHASE_STATUSES as $k => $label):
          $step = $status_steps[$k];
          $cur  = $status_steps[$cur_status];
          $done = $step < $cur; $active = $step === $cur;
          $col  = $done||$active ? '#003594' : '#d0d5dd';
          $bg   = $active ? '#003594' : ($done ? '#e8f0fe' : '#f5f7fa');
          $tc   = $active ? '#fff' : ($done ? '#003594' : '#9aa5b4');
        ?>
        <div style="display:flex;align-items:center;gap:.4rem">
          <?php if ($step > 0): ?><span style="color:<?= $col ?>;font-size:1rem">→</span><?php endif; ?>
          <span style="background:<?= $bg ?>;color:<?= $tc ?>;border:2px solid <?= $col ?>;border-radius:99px;padding:.25rem .85rem;font-size:.78rem;font-weight:700;white-space:nowrap">
            <?php if ($done): ?>✓ <?php endif; ?><?= h($label) ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="form-row col-2">
        <div class="form-group">
          <label>Status</label>
          <?php
          // Restrict status options by role — use workflow buttons for transitions
          $allowed_statuses = PURCHASE_STATUSES;
          if (!is_admin()) unset($allowed_statuses['approved']); // only admins can approve
          if (is_member())  $allowed_statuses = [$cur_status => PURCHASE_STATUSES[$cur_status]]; // members can't change status
          ?>
          <select name="status" onchange="updateFlow(this.value)" <?= is_member()?'disabled':'' ?>>
            <?php foreach ($allowed_statuses as $k => $v2): ?>
              <option value="<?= h($k) ?>" <?= $cur_status===$k?'selected':''?>><?= h($v2) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (is_member()): ?>
            <input type="hidden" name="status" value="<?= h($cur_status) ?>">
            <p style="font-size:.75rem;color:#9aa5b4;margin-top:.25rem">Status changes are handled by the admin workflow.</p>
          <?php endif; ?>
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
        <label>Receipt</label>
        <!-- Hidden inputs: camera (photo only) and file picker (photo or PDF) -->
        <input type="file" id="receipt-camera" name="receipt" accept="image/*" capture="environment" style="display:none" onchange="previewReceipt(this)">
        <input type="file" id="receipt-file"   name="receipt_file" accept="image/*,application/pdf" style="display:none" onchange="previewReceipt(this)">
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.25rem">
          <button type="button" onclick="document.getElementById('receipt-camera').click()"
            style="flex:1;min-width:140px;padding:.75rem;background:#003594;color:#fff;border:none;border-radius:6px;font-size:1rem;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.5rem">
            📷 Take Photo
          </button>
          <button type="button" onclick="document.getElementById('receipt-file').click()"
            style="flex:1;min-width:140px;padding:.75rem;background:#f0f2f5;color:#333;border:1px solid #d0d5dd;border-radius:6px;font-size:1rem;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.5rem">
            📁 Upload File
          </button>
        </div>
        <div id="receipt-preview-wrap" style="margin-top:.75rem;display:none">
          <img id="receipt-img-preview" src="" alt="Receipt preview" style="max-width:100%;max-height:240px;border-radius:4px;border:1px solid #e1e5eb;display:none">
          <div id="receipt-file-name" style="font-size:.82rem;color:#1b5e20;padding:.5rem;background:#e8f5e9;border-radius:4px;display:none">✓ File selected: <span></span></div>
        </div>
        <?php if ($is_edit && !empty($p['receipt_filename'])): ?>
          <div style="margin-top:.5rem;font-size:.82rem">
            Current receipt: <a href="receipt-view.php?id=<?= $id ?>" target="_blank" style="color:#003594">View</a>
            <span style="color:#9aa5b4"> — use buttons above to replace it</span>
          </div>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label>Notes</label>
        <textarea name="notes" rows="3" placeholder="Any additional details…"><?= $v('notes') ?></textarea>
      </div>
      <div class="form-group" style="display:flex;align-items:center;gap:.5rem">
        <input type="checkbox" name="receipt_required" id="req_receipt" value="1" style="width:auto"
               <?= !empty($p['receipt_required']) ? 'checked' : '' ?>>
        <label for="req_receipt" style="font-size:.85rem;text-transform:none;letter-spacing:0;font-weight:400;color:#333;cursor:pointer;margin:0">
          Receipt required before approval
        </label>
      </div>
    </fieldset>

    <?php if ($is_edit && (!empty($p['approved_note']) || !empty($p['reimbursed_note']))): ?>
    <fieldset><legend>Workflow Notes</legend>
      <?php if (!empty($p['approved_note'])): ?>
      <div style="background:#e8f5e9;border-left:3px solid #4caf50;padding:.6rem .9rem;border-radius:4px;margin-bottom:.5rem;font-size:.85rem">
        <strong style="color:#1b5e20">Approval note:</strong> <?= h($p['approved_note']) ?>
      </div>
      <?php endif; ?>
      <?php if (!empty($p['reimbursed_note'])): ?>
      <div style="background:#e3f2fd;border-left:3px solid #2196f3;padding:.6rem .9rem;border-radius:4px;font-size:.85rem">
        <strong style="color:#003594">Reimbursement note:</strong> <?= h($p['reimbursed_note']) ?>
      </div>
      <?php endif; ?>
    </fieldset>
    <?php endif; ?>

    <?php if ($read_only): ?>
    <?php else: ?>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap">
      <button type="submit" class="btn btn-primary"><?= $is_edit ? 'Save Changes' : 'Add Purchase' ?></button>
      <a href="purchases.php" class="btn btn-secondary">Cancel</a>
    </div>
    <?php endif; ?>
    <?php if ($read_only) echo '</fieldset>'; ?>
  </form>
  <?php if ($read_only): ?>
  <div style="margin-top:1rem">
    <a href="purchases.php" class="btn btn-secondary">← Back to Finance</a>
  </div>
  <?php endif; ?>
</div>

<script>
function calcTotal() {
  var pre  = parseFloat(document.getElementById('pretax').value)       || 0;
  var tax  = parseFloat(document.getElementById('tax_amt').value)      || 0;
  var ship = parseFloat(document.getElementById('shipping_amt').value) || 0;
  document.getElementById('total-display').textContent = '$' + (pre + tax + ship).toFixed(2);
}
function updateFlow(val) {
  // Reload page with new status to refresh flow indicator
  var form = document.querySelector('form');
  var hidden = document.createElement('input');
  hidden.type='hidden'; hidden.name='_preview_status'; hidden.value=val;
  form.appendChild(hidden);
  // Just let the select change reflect visually on submit
}

function previewReceipt(input) {
  var wrap = document.getElementById('receipt-preview-wrap');
  var img  = document.getElementById('receipt-img-preview');
  var fn   = document.getElementById('receipt-file-name');
  wrap.style.display = 'block';
  if (!input.files || !input.files[0]) return;
  var file = input.files[0];
  fn.querySelector('span').textContent = file.name;
  if (file.type.startsWith('image/')) {
    var reader = new FileReader();
    reader.onload = function(e) { img.src = e.target.result; img.style.display='block'; fn.style.display='none'; };
    reader.readAsDataURL(file);
  } else {
    img.style.display = 'none';
    fn.style.display  = 'block';
  }
}
</script>

<?php admin_footer(); ?>

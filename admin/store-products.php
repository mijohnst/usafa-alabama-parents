<?php
/**
 * Club Store — Product Management
 * Board members (officer/secretary/treasurer/admin — see can_manage_store())
 * create and edit products: name/description/category/price, one or more
 * photos, and optional size/color variants. A product with no variant rows
 * sells as a single plain item; a product with variant rows requires the
 * shopper to pick one on the public store page.
 *
 * Inventory tracking exists as a column on each variant but is NOT enforced
 * anywhere yet (no oversold blocking, no decrement on purchase) — that's a
 * deliberate MVP deferral, not an oversight. It's just informal bookkeeping
 * for the treasurer until a later phase adds real enforcement.
 */
require_once __DIR__ . '/auth.php';
require_store_admin();
$pdo = get_pdo();

$photo_dir = __DIR__ . '/../product-photos/';
$thumb_dir = $photo_dir . 'thumbs/';
if (!is_dir($photo_dir)) mkdir($photo_dir, 0755, true);
if (!is_dir($thumb_dir)) mkdir($thumb_dir, 0755, true);

function store_thumb_filename(string $filename): string {
    return pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
}

// ── Actions ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category    = $_POST['category'] ?? '';
        $base_price  = round((float)str_replace(',', '', $_POST['base_price'] ?? '0'), 2);
        $is_active   = !empty($_POST['is_active']) ? 1 : 0;

        $errors = [];
        if ($name === '') $errors[] = 'Product name is required.';
        if ($base_price <= 0 || $base_price > 99999.99) $errors[] = 'Price must be a positive amount.';
        if (!in_array($category, STORE_CATEGORIES, true)) $category = '';

        if ($errors) {
            $_SESSION['store_product_old_input'] = $_POST;
            flash('error', implode(' ', $errors));
            header('Location: store-products.php' . ($id ? "?edit=$id" : '&new=1')); exit;
        }

        if ($id) {
            $pdo->prepare('UPDATE store_products SET name=?, description=?, category=?, base_price=?, is_active=? WHERE id=?')
                ->execute([$name, $description, $category, $base_price, $is_active, $id]);
        } else {
            $pdo->prepare('INSERT INTO store_products (name, description, category, base_price, is_active, created_by) VALUES (?,?,?,?,?,?)')
                ->execute([$name, $description, $category, $base_price, $is_active, $_SESSION['user_id'] ?? null]);
            $id = (int)$pdo->lastInsertId();
        }

        // Variants: parallel arrays keyed by row index. A row with a
        // positive variant_id is an existing row being updated; a blank/0
        // id is a new row. Any previously-existing variant whose id isn't
        // present in this submission was removed via the "Remove" button
        // in the form and gets deleted here — the form always re-submits
        // every row currently shown, so "missing" reliably means "deleted."
        $v_ids     = $_POST['variant_id']      ?? [];
        $v_sizes   = $_POST['variant_size']    ?? [];
        $v_colors  = $_POST['variant_color']   ?? [];
        $v_skus    = $_POST['variant_sku']     ?? [];
        $v_prices  = $_POST['variant_price']   ?? [];
        $v_invs    = $_POST['variant_inv']     ?? [];
        $v_actives = $_POST['variant_active']  ?? [];
        $kept_ids  = [];

        foreach ($v_sizes as $i => $_) {
            $size  = trim($v_sizes[$i] ?? '');
            $color = trim($v_colors[$i] ?? '');
            if ($size === '' && $color === '') continue; // blank row, ignore
            $sku       = trim($v_skus[$i] ?? '') ?: null;
            $price_raw = trim($v_prices[$i] ?? '');
            $price     = $price_raw !== '' ? round((float)$price_raw, 2) : null;
            $inv_raw   = trim($v_invs[$i] ?? '');
            $inv       = $inv_raw !== '' ? (int)$inv_raw : null;
            $active    = !empty($v_actives[$i]) ? 1 : 0;
            $vid       = (int)($v_ids[$i] ?? 0);

            if ($vid) {
                $pdo->prepare('UPDATE store_product_variants SET size=?, color=?, sku=?, price_override=?, inventory_qty=?, is_active=? WHERE id=? AND product_id=?')
                    ->execute([$size ?: null, $color ?: null, $sku, $price, $inv, $active, $vid, $id]);
                $kept_ids[] = $vid;
            } else {
                $pdo->prepare('INSERT INTO store_product_variants (product_id, size, color, sku, price_override, inventory_qty, is_active) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$id, $size ?: null, $color ?: null, $sku, $price, $inv, $active]);
                $kept_ids[] = (int)$pdo->lastInsertId();
            }
        }
        if ($kept_ids) {
            $ph = implode(',', array_fill(0, count($kept_ids), '?'));
            $pdo->prepare("DELETE FROM store_product_variants WHERE product_id = ? AND id NOT IN ($ph)")
                ->execute(array_merge([$id], $kept_ids));
        } else {
            $pdo->prepare('DELETE FROM store_product_variants WHERE product_id = ?')->execute([$id]);
        }

        flash('success', 'Product saved.');
        header('Location: store-products.php?edit=' . $id); exit;

    } elseif ($action === 'upload_photo') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        if (!$product_id) { flash('error', 'Save the product before adding photos.'); header('Location: store-products.php'); exit; }
        $files = $_FILES['photo'] ?? [];
        $uploaded = 0; $skipped = 0;
        if (!empty($files['name'])) {
            if (!is_array($files['name'])) foreach ($files as $k => $v) $files[$k] = [$v];
            $max_sort = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM store_product_photos WHERE product_id=?');
            $max_sort->execute([$product_id]);
            $next_sort = (int)$max_sort->fetchColumn() + 10;
            $primary_stmt = $pdo->prepare('SELECT COUNT(*) FROM store_product_photos WHERE product_id=? AND is_primary=1');
            $primary_stmt->execute([$product_id]);
            $has_primary = (int)$primary_stmt->fetchColumn() > 0;

            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                if (empty($files['name'][$i]) || $files['error'][$i] !== UPLOAD_ERR_OK) continue;
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $files['tmp_name'][$i]); finfo_close($finfo);
                if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true) || $files['size'][$i] > 10 * 1024 * 1024) {
                    $skipped++; continue;
                }
                $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime];
                $name = 'prod_' . $product_id . '_' . date('Ymd') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (!move_uploaded_file($files['tmp_name'][$i], $photo_dir . $name)) { $skipped++; continue; }
                generate_photo_thumbnail($photo_dir . $name, $thumb_dir . store_thumb_filename($name));
                $is_primary = (!$has_primary && $uploaded === 0) ? 1 : 0;
                $pdo->prepare('INSERT INTO store_product_photos (product_id, filename, sort_order, is_primary) VALUES (?,?,?,?)')
                    ->execute([$product_id, $name, $next_sort + $i, $is_primary]);
                $uploaded++;
            }
        }
        if ($uploaded) flash('success', "$uploaded photo" . ($uploaded > 1 ? 's' : '') . ' uploaded.' . ($skipped ? " $skipped skipped (invalid)." : ''));
        else flash('error', 'No valid photos uploaded. Use JPG, PNG, GIF, or WebP under 10MB.');
        header('Location: store-products.php?edit=' . $product_id); exit;

    } elseif ($action === 'delete_photo') {
        $photo_id   = (int)($_POST['photo_id'] ?? 0);
        $product_id = (int)($_POST['product_id'] ?? 0);
        $row = $pdo->prepare('SELECT filename, is_primary FROM store_product_photos WHERE id=? AND product_id=?');
        $row->execute([$photo_id, $product_id]);
        $ph = $row->fetch(PDO::FETCH_ASSOC);
        if ($ph && preg_match('/^[a-zA-Z0-9._-]+$/', $ph['filename'])) {
            @unlink($photo_dir . $ph['filename']);
            @unlink($thumb_dir . store_thumb_filename($ph['filename']));
            $pdo->prepare('DELETE FROM store_product_photos WHERE id=? AND product_id=?')->execute([$photo_id, $product_id]);
            if ($ph['is_primary']) {
                // Promote the next-lowest sort_order photo to primary so the
                // product never ends up with zero primary photos while
                // others remain — the catalog grid picks primary first.
                $next = $pdo->prepare('SELECT id FROM store_product_photos WHERE product_id=? ORDER BY sort_order ASC LIMIT 1');
                $next->execute([$product_id]);
                $next_id = $next->fetchColumn();
                if ($next_id) $pdo->prepare('UPDATE store_product_photos SET is_primary=1 WHERE id=?')->execute([$next_id]);
            }
        }
        flash('success', 'Photo deleted.');
        header('Location: store-products.php?edit=' . $product_id); exit;

    } elseif ($action === 'set_primary_photo') {
        $photo_id   = (int)($_POST['photo_id'] ?? 0);
        $product_id = (int)($_POST['product_id'] ?? 0);
        $pdo->prepare('UPDATE store_product_photos SET is_primary=0 WHERE product_id=?')->execute([$product_id]);
        $pdo->prepare('UPDATE store_product_photos SET is_primary=1 WHERE id=? AND product_id=?')->execute([$photo_id, $product_id]);
        header('Location: store-products.php?edit=' . $product_id); exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $photos = $pdo->prepare('SELECT filename FROM store_product_photos WHERE product_id=?');
        $photos->execute([$id]);
        foreach ($photos->fetchAll(PDO::FETCH_COLUMN) as $filename) {
            if (preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
                @unlink($photo_dir . $filename);
                @unlink($thumb_dir . store_thumb_filename($filename));
            }
        }
        // store_product_photos and store_product_variants both cascade via
        // FK ON DELETE CASCADE — only the product row itself needs deleting.
        // store_order_items intentionally has no FK to store_products (see
        // migrate_store.sql) so past orders keep their snapshotted
        // product_name_snapshot/variant_label_snapshot even after the
        // product itself is removed from the catalog.
        $pdo->prepare('DELETE FROM store_products WHERE id=?')->execute([$id]);
        flash('success', 'Product deleted.');
        header('Location: store-products.php'); exit;
    }
}

// ── Data for rendering ───────────────────────────────────────────────────────
$products = $pdo->query('SELECT * FROM store_products ORDER BY is_active DESC, name ASC')->fetchAll(PDO::FETCH_ASSOC);

$editing = null;
$variants = [];
$photos = [];
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM store_products WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($editing) {
        $vstmt = $pdo->prepare('SELECT * FROM store_product_variants WHERE product_id=? ORDER BY id ASC');
        $vstmt->execute([$editing['id']]);
        $variants = $vstmt->fetchAll(PDO::FETCH_ASSOC);
        $pstmt = $pdo->prepare('SELECT * FROM store_product_photos WHERE product_id=? ORDER BY sort_order ASC');
        $pstmt->execute([$editing['id']]);
        $photos = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
$adding_new = isset($_GET['new']) && !$editing;

$old_input = null;
if (!empty($_SESSION['store_product_old_input'])) {
    $old_input = $_SESSION['store_product_old_input'];
    unset($_SESSION['store_product_old_input']);
}
function store_field(?array $old, ?array $editing, string $key, string $default = ''): string {
    if ($old !== null) return (string)($old[$key] ?? $default);
    return (string)($editing[$key] ?? $default);
}

admin_header('Club Store — Products');
echo show_flash();
?>
<style>
.sp-table td,.sp-table th{padding:.55rem .9rem}
.sp-table th{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5a6a7a;background:#f7f9fc;white-space:nowrap}
.sp-table td{border-top:1px solid #f0f2f5;font-size:.84rem;vertical-align:middle}
.sp-thumb{width:44px;height:44px;object-fit:cover;border-radius:4px;background:#f0f2f5}
.variant-row{display:grid;grid-template-columns:1fr 1fr 1fr .8fr .7fr auto auto;gap:.5rem;align-items:center;margin-bottom:.5rem}
.variant-row input[type=text],.variant-row input[type=number]{padding:.45rem .6rem;border:1px solid #d0d5dd;border-radius:4px;font-size:.85rem;width:100%}
.photo-grid{display:flex;flex-wrap:wrap;gap:.75rem;margin-bottom:1rem}
.photo-card{position:relative;width:110px}
.photo-card img{width:110px;height:110px;object-fit:cover;border-radius:6px;border:2px solid transparent}
.photo-card.is-primary img{border-color:#003594}
.photo-card .badge{position:absolute;top:2px;left:2px;background:#003594;color:#fff;font-size:.6rem;padding:.1rem .4rem;border-radius:3px}
.photo-card form{margin-top:.3rem;display:flex;gap:.3rem;justify-content:center}
</style>

<div class="page-head">
  <h1>🛍️ Club Store — Products</h1>
  <div style="display:flex;gap:.5rem">
    <a href="store-orders.php" class="btn btn-secondary">📦 Order Ledger</a>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>

<?php if ($editing || $adding_new): ?>
<div class="card" style="max-width:820px">
  <h2 style="margin-bottom:1rem"><?= $editing ? 'Edit Product' : 'Add Product' ?></h2>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
    <div class="form-row col-2">
      <div class="form-group">
        <label>Product Name <span style="color:#A6192E">*</span></label>
        <input type="text" name="name" required value="<?= h(store_field($old_input, $editing, 'name')) ?>">
      </div>
      <div class="form-group">
        <label>Category</label>
        <select name="category">
          <?php $cur_cat = store_field($old_input, $editing, 'category'); ?>
          <?php foreach (STORE_CATEGORIES as $c): ?>
          <option value="<?= h($c) ?>" <?= $cur_cat === $c ? 'selected' : '' ?>><?= $c === '' ? '—' : h($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label>Description</label>
      <textarea name="description" rows="3"><?= h(store_field($old_input, $editing, 'description')) ?></textarea>
    </div>
    <div class="form-row col-2">
      <div class="form-group">
        <label>Base Price ($) <span style="color:#A6192E">*</span></label>
        <input type="number" name="base_price" step="0.01" min="0.01" required value="<?= h(store_field($old_input, $editing, 'base_price')) ?>">
        <p style="font-size:.72rem;color:#9aa5b4;margin-top:.35rem">Used for any variant that doesn't have its own price override below.</p>
      </div>
      <div class="form-group" style="display:flex;align-items:flex-end;gap:.5rem">
        <label style="display:flex;align-items:center;gap:.5rem;font-weight:400;text-transform:none;letter-spacing:0">
          <input type="checkbox" name="is_active" style="width:auto" <?= (($old_input['is_active'] ?? ($editing['is_active'] ?? 1))) ? 'checked' : '' ?>>
          Active (visible on the public store)
        </label>
      </div>
    </div>

    <hr style="margin:1.25rem 0;border:none;border-top:1px solid #f0f2f5">
    <h3 style="font-size:.9rem;margin-bottom:.5rem">Sizes / Colors (optional)</h3>
    <p style="font-size:.78rem;color:#9aa5b4;margin-bottom:.75rem">Leave this section empty for a product with no size/color choice (e.g. an ornament) — it'll sell as a single item. Add a row per size/color combination for something like a shirt. Price and inventory are optional per row; blank price falls back to the base price above, blank inventory means unlimited.</p>
    <div id="variantRows">
      <?php foreach ($variants as $v): ?>
      <div class="variant-row">
        <input type="hidden" name="variant_id[]" value="<?= (int)$v['id'] ?>">
        <input type="text" name="variant_size[]" placeholder="Size (e.g. Large)" value="<?= h($v['size'] ?? '') ?>">
        <input type="text" name="variant_color[]" placeholder="Color (e.g. Navy)" value="<?= h($v['color'] ?? '') ?>">
        <input type="text" name="variant_sku[]" placeholder="SKU (optional)" value="<?= h($v['sku'] ?? '') ?>">
        <input type="number" step="0.01" name="variant_price[]" placeholder="Price override" value="<?= h($v['price_override'] ?? '') ?>">
        <input type="number" name="variant_inv[]" placeholder="Inventory" value="<?= h($v['inventory_qty'] ?? '') ?>">
        <label style="font-size:.72rem;white-space:nowrap"><input type="checkbox" name="variant_active[]" style="width:auto" <?= $v['is_active'] ? 'checked' : '' ?>> Active</label>
        <button type="button" class="btn btn-danger btn-sm" onclick="this.closest('.variant-row').remove()">✕</button>
      </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-secondary btn-sm" onclick="addVariantRow()" style="margin-bottom:1rem">+ Add Size/Color Row</button>
    <template id="variantRowTemplate">
      <div class="variant-row">
        <input type="hidden" name="variant_id[]" value="0">
        <input type="text" name="variant_size[]" placeholder="Size (e.g. Large)">
        <input type="text" name="variant_color[]" placeholder="Color (e.g. Navy)">
        <input type="text" name="variant_sku[]" placeholder="SKU (optional)">
        <input type="number" step="0.01" name="variant_price[]" placeholder="Price override">
        <input type="number" name="variant_inv[]" placeholder="Inventory">
        <label style="font-size:.72rem;white-space:nowrap"><input type="checkbox" name="variant_active[]" style="width:auto" checked> Active</label>
        <button type="button" class="btn btn-danger btn-sm" onclick="this.closest('.variant-row').remove()">✕</button>
      </div>
    </template>
    <script>
      function addVariantRow() {
        var tpl = document.getElementById('variantRowTemplate');
        document.getElementById('variantRows').appendChild(tpl.content.cloneNode(true));
      }
    </script>

    <div style="display:flex;gap:.6rem;margin-top:1rem">
      <button type="submit" class="btn btn-primary">Save Product</button>
      <a href="store-products.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>

  <?php if ($editing): ?>
  <hr style="margin:1.5rem 0;border:none;border-top:1px solid #f0f2f5">
  <h3 style="font-size:.9rem;margin-bottom:.75rem">Photos</h3>
  <div class="photo-grid">
    <?php foreach ($photos as $ph): ?>
    <div class="photo-card <?= $ph['is_primary'] ? 'is-primary' : '' ?>">
      <?php if ($ph['is_primary']): ?><span class="badge">Primary</span><?php endif; ?>
      <img src="../product-photo-serve.php?f=<?= h($ph['filename']) ?>&thumb=1" alt="">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_photo">
        <input type="hidden" name="photo_id" value="<?= (int)$ph['id'] ?>">
        <input type="hidden" name="product_id" value="<?= (int)$editing['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this photo?')">Delete</button>
      </form>
      <?php if (!$ph['is_primary']): ?>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="set_primary_photo">
        <input type="hidden" name="photo_id" value="<?= (int)$ph['id'] ?>">
        <input type="hidden" name="product_id" value="<?= (int)$editing['id'] ?>">
        <button type="submit" class="btn btn-secondary btn-sm">Set Primary</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload_photo">
    <input type="hidden" name="product_id" value="<?= (int)$editing['id'] ?>">
    <input type="file" name="photo[]" accept="image/*" multiple>
    <button type="submit" class="btn btn-secondary btn-sm">Upload</button>
  </form>
  <?php else: ?>
  <p style="font-size:.8rem;color:#9aa5b4;margin-top:1rem">Save the product first, then come back to add photos.</p>
  <?php endif; ?>
</div>
<?php else: ?>
<div style="margin-bottom:1.25rem"><a href="store-products.php?new=1" class="btn btn-primary">+ Add Product</a></div>
<?php endif; ?>

<div class="card" style="padding:0;overflow-x:auto">
<table class="sp-table" style="width:100%;border-collapse:collapse">
  <thead>
    <tr><th></th><th>Name</th><th>Category</th><th>Price</th><th>Variants</th><th>Status</th><th>Actions</th></tr>
  </thead>
  <tbody>
    <?php foreach ($products as $p): ?>
    <?php
      $thumb = $pdo->prepare('SELECT filename FROM store_product_photos WHERE product_id=? ORDER BY is_primary DESC, sort_order ASC LIMIT 1');
      $thumb->execute([$p['id']]);
      $thumb_file = $thumb->fetchColumn();
      $vcount = $pdo->prepare('SELECT COUNT(*) FROM store_product_variants WHERE product_id=?');
      $vcount->execute([$p['id']]);
      $vcount = (int)$vcount->fetchColumn();
    ?>
    <tr>
      <td><?php if ($thumb_file): ?><img class="sp-thumb" src="../product-photo-serve.php?f=<?= h($thumb_file) ?>&thumb=1" alt=""><?php endif; ?></td>
      <td style="font-weight:600"><?= h($p['name']) ?></td>
      <td style="color:#5a6a7a"><?= h($p['category'] ?: '—') ?></td>
      <td>$<?= number_format((float)$p['base_price'], 2) ?></td>
      <td style="color:#5a6a7a"><?= $vcount ?: '—' ?></td>
      <td><span class="type-pill" style="background:<?= $p['is_active'] ? '#1b5e2022' : '#5a6a7a22' ?>;color:<?= $p['is_active'] ? '#1b5e20' : '#5a6a7a' ?>"><?= $p['is_active'] ? 'Active' : 'Hidden' ?></span></td>
      <td>
        <div class="btn-group">
          <a href="store-products.php?edit=<?= (int)$p['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
          <form method="POST" onsubmit="return confirm('Delete this product and its photos/variants? This cannot be undone.')" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($products)): ?>
    <tr><td colspan="7" style="text-align:center;color:#9aa5b4;padding:1.5rem">No products yet — add one above.</td></tr>
    <?php endif; ?>
  </tbody>
</table>
</div>

<?php admin_footer(); ?>

<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo(); $errors = []; $edit = null;

function save_logo(string $key): ?string {
    if (empty($_FILES[$key]['name'])) return null;
    $file = $_FILES[$key];
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']); finfo_close($finfo);
    if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'])) return null;
    $ext  = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','image/svg+xml'=>'svg'][$mime];
    $name = 'sponsor-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dir  = __DIR__ . '/../sponsor-logos/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    move_uploaded_file($file['tmp_name'], $dir . $name);
    return $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify(); $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $name        = trim($_POST['name']        ?? '');
        $level       = $_POST['level']            ?? 'individual';
        $description = trim($_POST['description'] ?? '');
        $website_url = trim($_POST['website_url'] ?? '');
        $sort_order  = (int)($_POST['sort_order'] ?? 0);
        $active      = isset($_POST['active']) ? 1 : 0;
        $new_logo    = save_logo('logo');
        if (!$name) $errors[] = 'Sponsor name is required.';
        if (empty($errors)) {
            if ($id) {
                $cur = $pdo->prepare('SELECT logo_filename FROM sponsors WHERE id=?'); $cur->execute([$id]); $existing=$cur->fetchColumn();
                $logo = $new_logo ?? $existing;
                $pdo->prepare('UPDATE sponsors SET name=?,level=?,description=?,website_url=?,logo_filename=?,sort_order=?,active=?,updated_at=NOW() WHERE id=?')
                    ->execute([$name,$level,$description,$website_url,$logo,$sort_order,$active,$id]);
                flash('success','Sponsor updated.');
            } else {
                $pdo->prepare('INSERT INTO sponsors (name,level,description,website_url,logo_filename,sort_order,active) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$name,$level,$description,$website_url,$new_logo,$sort_order,$active]);
                flash('success','Sponsor added.');
            }
            header('Location: sponsors.php'); exit;
        }
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM sponsors WHERE id=?')->execute([(int)$_POST['id']]);
        flash('success','Sponsor removed.'); header('Location: sponsors.php'); exit;
    }
}

if (isset($_GET['edit'])) { $s=$pdo->prepare('SELECT * FROM sponsors WHERE id=?'); $s->execute([(int)$_GET['edit']]); $edit=$s->fetch(); }
$sponsors = $pdo->query("SELECT * FROM sponsors ORDER BY FIELD(level,'presenting','gold','silver','individual','other'), sort_order ASC, name ASC")->fetchAll();
$levels   = ['presenting'=>'Presenting','gold'=>'Gold','silver'=>'Silver','individual'=>'Individual','other'=>'Other'];

admin_header('Sponsors');
echo show_flash();
?>
<div class="page-head"><h1>Sponsors</h1>
  <div style="display:flex;gap:.5rem">
    <?php if (!$edit): ?><a href="sponsors.php?edit=new" class="btn btn-primary">+ Add Sponsor</a><?php endif; ?>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">Changes here update the Sponsors page automatically.</p>

<?php if (!empty($errors)): ?><div class="alert alert-error"><?= implode('<br>',array_map('htmlspecialchars',$errors)) ?></div><?php endif; ?>

<?php if ($edit !== null || isset($_GET['edit'])): ?>
<div class="card" style="max-width:600px;margin-bottom:1.5rem">
  <h2 style="margin-bottom:1.25rem"><?= $edit?'Edit Sponsor':'Add Sponsor' ?></h2>
  <form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
    <div class="form-row col-2">
      <div class="form-group"><label>Name *</label><input name="name" value="<?= h($edit['name']??'') ?>" required></div>
      <div class="form-group"><label>Level</label>
        <select name="level"><?php foreach ($levels as $k=>$v): ?>
          <option value="<?= $k ?>" <?= ($edit['level']??'individual')===$k?'selected':''?>><?= $v ?></option>
        <?php endforeach; ?></select>
      </div>
    </div>
    <div class="form-group"><label>Description</label><textarea name="description" rows="3"><?= h($edit['description']??'') ?></textarea></div>
    <div class="form-row col-2">
      <div class="form-group"><label>Website URL</label><input name="website_url" value="<?= h($edit['website_url']??'') ?>" placeholder="https://…"></div>
      <div class="form-group"><label>Sort Order</label><input type="number" name="sort_order" value="<?= h($edit['sort_order']??'0') ?>"></div>
    </div>
    <div class="form-group">
      <label>Logo <?= $edit?'(upload to replace)':'' ?></label>
      <input type="file" name="logo" accept="image/*,.svg" style="padding:.5rem;font-size:.9rem">
      <?php if (!empty($edit['logo_filename'])): ?><div style="font-size:.82rem;color:#5a6a7a;margin-top:.4rem">Current: <?= h($edit['logo_filename']) ?></div><?php endif; ?>
    </div>
    <div class="form-group" style="display:flex;align-items:center;gap:.5rem">
      <input type="checkbox" name="active" id="sp_active" value="1" style="width:auto" <?= ($edit['active']??1)?'checked':'' ?>>
      <label for="sp_active" style="font-weight:400;text-transform:none;cursor:pointer;margin:0;font-size:.9rem">Show on website</label>
    </div>
    <div style="display:flex;gap:.75rem">
      <button type="submit" class="btn btn-primary"><?= $edit?'Save Changes':'Add Sponsor' ?></button>
      <a href="sponsors.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card" style="padding:0;overflow-x:auto">
<table><thead><tr><th>Logo</th><th>Name</th><th>Level</th><th>Website</th><th>Visible</th><th class="actions-head">Actions</th></tr></thead><tbody>
<?php if (empty($sponsors)): ?><tr><td colspan="6" style="text-align:center;padding:2rem;color:#9aa5b4">No sponsors yet.</td></tr><?php endif; ?>
<?php foreach ($sponsors as $s): ?>
<tr>
  <td><?php if ($s['logo_filename']): $src=file_exists(__DIR__.'/../sponsor-logos/'.$s['logo_filename'])?'/sponsor-logos/'.h($s['logo_filename']):''; if ($src): ?><img src="<?= $src ?>" style="height:36px;max-width:80px;object-fit:contain"><?php endif; endif; ?></td>
  <td><strong><?= h($s['name']) ?></strong></td>
  <td><span style="font-size:.75rem;font-weight:700;color:#5a6a7a;text-transform:uppercase"><?= $levels[$s['level']]??$s['level'] ?></span></td>
  <td style="font-size:.78rem"><?php if ($s['website_url']): ?><a href="<?= h($s['website_url']) ?>" target="_blank" rel="noopener" style="color:#003594"><?= h(parse_url($s['website_url'],PHP_URL_HOST)?:'Link') ?></a><?php endif; ?></td>
  <td style="text-align:center"><?= $s['active']?'✅':'—' ?></td>
  <td class="actions"><div class="btn-group">
    <a href="sponsors.php?edit=<?= $s['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
    <form method="POST" onsubmit="return confirm('Remove sponsor?')" style="margin:0">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>">
      <button type="submit" class="btn btn-danger btn-sm">Remove</button>
    </form>
  </div></td>
</tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php admin_footer(); ?>

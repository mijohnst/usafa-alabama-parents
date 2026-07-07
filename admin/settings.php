<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $rows = $pdo->query('SELECT setting_key, setting_type FROM site_settings')->fetchAll();
    foreach ($rows as $row) {
        $key = $row['setting_key'];
        $val = trim($_POST[$key] ?? '');
        $pdo->prepare('UPDATE site_settings SET setting_value=? WHERE setting_key=?')->execute([$val, $key]);
    }
    flash('success', 'Settings saved. Changes will appear on the site within a few minutes.');
    header('Location: settings.php'); exit;
}

$settings = [];
$labels   = [];
$types    = [];
$rows = $pdo->query('SELECT * FROM site_settings ORDER BY id')->fetchAll();
foreach ($rows as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
    $labels[$r['setting_key']]   = $r['setting_label'];
    $types[$r['setting_key']]    = $r['setting_type'];
}

$sections = [
    'Homepage Hero' => ['hero_subtitle','hero_cta_text','hero_cta_url'],
    'Membership'    => ['membership_dues','membership_description'],
    'President\'s Letter' => ['president_letter','president_name','president_title'],
    'Social & Links'=> ['facebook_url'],
    'Footer Resources' => ['footer_resources'],
];

admin_header('Site Settings');
echo show_flash();
?>
<div class="page-head">
  <h1>Site Settings</h1>
  <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.5rem">
  Changes here update the main website automatically within a few minutes.
  <strong>Footer Resources</strong> format: one per line as <code>Title|URL</code>
</p>

<form method="POST">
  <?= csrf_field() ?>
  <?php foreach ($sections as $section => $keys): ?>
  <div class="card" style="margin-bottom:1.25rem">
    <h2 style="margin-bottom:1.25rem;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#5a6a7a"><?= h($section) ?></h2>
    <?php foreach ($keys as $key): if (!isset($settings[$key])) continue;
      $val   = $settings[$key] ?? '';
      $label = $labels[$key]   ?? $key;
      $type  = $types[$key]    ?? 'text';
    ?>
    <div class="form-group">
      <label><?= h($label) ?></label>
      <?php if ($type === 'textarea'): ?>
        <textarea name="<?= h($key) ?>" rows="<?= $key==='president_letter'?12:4 ?>"><?= h($val) ?></textarea>
      <?php else: ?>
        <input type="<?= $type==='url'?'url':'text' ?>" name="<?= h($key) ?>" value="<?= h($val) ?>">
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
  <button type="submit" class="btn btn-primary" style="min-width:180px">Save All Settings</button>
</form>

<?php admin_footer(); ?>

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

$settings = []; $labels = []; $types = [];
$rows = $pdo->query('SELECT * FROM site_settings ORDER BY id')->fetchAll();
foreach ($rows as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
    $labels[$r['setting_key']]   = $r['setting_label'];
    $types[$r['setting_key']]    = $r['setting_type'];
}

$sections = [
    'Homepage Hero'       => ['hero_subtitle','hero_cta_text','hero_cta_url'],
    'Membership'          => ['membership_dues','membership_description'],
    'President\'s Letter' => ['president_letter','president_name','president_title'],
    'Social & Links'      => ['facebook_url'],
    'Footer Resources'    => ['footer_resources'],
];

admin_header('Site Settings');
echo show_flash();
?>
<!-- Quill rich text editor (CDN) -->
<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<style>
.ql-editor{min-height:300px;font-family:"Segoe UI",Arial,sans-serif;font-size:1rem}
.ql-toolbar{border-radius:4px 4px 0 0}
.ql-container{border-radius:0 0 4px 4px;background:#fff}
</style>

<div class="page-head">
  <h1>Site Settings</h1>
  <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.5rem">
  Changes here update the main website automatically.
  <strong>Footer Resources:</strong> one per line as <code>Title|URL</code>.
  Looking for the cadet birthday / dues renewal / meeting reminder emails? Those moved to
  <a href="automated-emails.php">Automated Emails</a>.
</p>

<form method="POST" id="settings-form">
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
      <?php if ($key === 'president_letter'): ?>
        <!-- Rich text editor for president's letter -->
        <div id="quill-editor" style="margin-bottom:.5rem"><?= $val ?></div>
        <input type="hidden" name="president_letter" id="president_letter_input">
        <p style="font-size:.72rem;color:#9aa5b4;margin-top:.35rem">Use the toolbar above to format text, add links, or insert images. Looks the same as it will on the website.</p>
      <?php elseif ($type === 'textarea'): ?>
        <textarea name="<?= h($key) ?>" rows="<?= $key==='membership_description' ? 6 : 4 ?>"><?= h($val) ?></textarea>
      <?php else: ?>
        <input type="text" name="<?= h($key) ?>" value="<?= h($val) ?>" placeholder="<?= $type==='url'?'e.g. https://... or #section':'' ?>">
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
  <button type="submit" class="btn btn-primary" style="min-width:180px">Save All Settings</button>
</form>

<script>
// Initialise Quill rich text editor
var quill = new Quill('#quill-editor', {
  theme: 'snow',
  modules: {
    toolbar: [
      [{ 'header': [1,2,3,false] }],
      ['bold','italic','underline','strike'],
      [{ 'color': [] },{ 'background': [] }],
      [{ 'size': ['small',false,'large','huge'] }],
      [{ 'align': [] }],
      [{ 'list': 'ordered' },{ 'list': 'bullet' }],
      ['link','image'],
      ['clean']
    ]
  }
});

// Populate hidden input initially so a save without editing still works
document.getElementById('president_letter_input').value = quill.root.innerHTML;

// Update hidden input whenever content changes
quill.on('text-change', function() {
  document.getElementById('president_letter_input').value = quill.root.innerHTML;
});

// Also update on submit as a safety net
document.getElementById('settings-form').addEventListener('submit', function() {
  document.getElementById('president_letter_input').value = quill.root.innerHTML;
});
</script>

<?php admin_footer(); ?>

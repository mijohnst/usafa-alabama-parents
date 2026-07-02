<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo = get_pdo();

$year   = $_POST['year']   ?? '';
$region = $_POST['region'] ?? '';
$type   = $_POST['type']   ?? 'emails';

$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $where  = ['1=1'];
    $params = [];
    if ($year   !== '') { $where[] = 'class_year = :year';   $params[':year']   = $year; }
    if ($region !== '') { $where[] = 'al_region  = :region'; $params[':region'] = $region; }

    $sql = 'SELECT cadet_last_name, cadet_first_middle, class_year, al_region,
                   parent1_first_name, parent1_last_name, parent1_email, parent1_cell,
                   parent2_first_name, parent2_last_name, parent2_email, parent2_cell,
                   cadet_email, cadet_cell
            FROM members WHERE ' . implode(' AND ', $where)
         . ' ORDER BY class_year, cadet_last_name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $lines = [];
    foreach ($rows as $r) {
        $cadet = trim($r['cadet_last_name'] . ', ' . $r['cadet_first_middle']);
        if ($type === 'emails') {
            if ($r['parent1_email']) {
                $name = trim($r['parent1_first_name'] . ' ' . $r['parent1_last_name']);
                $lines[] = "$name <{$r['parent1_email']}>";
            }
            if ($r['parent2_email']) {
                $name = trim($r['parent2_first_name'] . ' ' . $r['parent2_last_name']);
                $lines[] = "$name <{$r['parent2_email']}>";
            }
        } elseif ($type === 'emails_plain') {
            if ($r['parent1_email']) $lines[] = $r['parent1_email'];
            if ($r['parent2_email']) $lines[] = $r['parent2_email'];
        } elseif ($type === 'cells') {
            if ($r['parent1_cell']) {
                $name = trim($r['parent1_first_name'] . ' ' . $r['parent1_last_name']);
                $lines[] = "$name ({$cadet}): {$r['parent1_cell']}";
            }
            if ($r['parent2_cell']) {
                $name = trim($r['parent2_first_name'] . ' ' . $r['parent2_last_name']);
                $lines[] = "$name ({$cadet}): {$r['parent2_cell']}";
            }
        } elseif ($type === 'cadet_emails') {
            if ($r['cadet_email']) $lines[] = "$cadet: {$r['cadet_email']}";
        }
    }

    $label = ($year ?: 'All Years') . ' / ' . ($region ?: 'All Regions');
    $results = ['lines' => $lines, 'count' => count($rows), 'label' => $label];
}

admin_header('Lists');
?>

<div class="page-head">
  <h1>Contact Lists</h1>
</div>

<div class="card">
  <form method="POST">
    <div class="form-row col-4" style="align-items:flex-end">
      <div class="form-group">
        <label>Class Year</label>
        <select name="year">
          <option value="">All Years</option>
          <?php foreach (['2026','2027','2028','2029','2030','Prep School','Graduate'] as $y): ?>
            <option value="<?= h($y) ?>" <?= $year === $y ? 'selected' : '' ?>><?= h($y) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>AL Region</label>
        <select name="region">
          <option value="">All Regions</option>
          <?php foreach (['North','Central','South'] as $r): ?>
            <option value="<?= h($r) ?>" <?= $region === $r ? 'selected' : '' ?>><?= h($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Output</label>
        <select name="type">
          <option value="emails"       <?= $type==='emails'       ? 'selected':'' ?>>Parent Emails (Name &lt;email&gt;)</option>
          <option value="emails_plain" <?= $type==='emails_plain' ? 'selected':'' ?>>Parent Emails (plain list)</option>
          <option value="cells"        <?= $type==='cells'        ? 'selected':'' ?>>Parent Cell Numbers</option>
          <option value="cadet_emails" <?= $type==='cadet_emails' ? 'selected':'' ?>>Cadet Emails</option>
        </select>
      </div>
      <div class="form-group">
        <label>&nbsp;</label>
        <button type="submit" class="btn btn-primary" style="width:100%">Generate List</button>
      </div>
    </div>
  </form>
</div>

<?php if ($results !== null): ?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem">
    <h2 style="margin:0"><?= h($results['label']) ?> — <?= count($results['lines']) ?> entries from <?= $results['count'] ?> members</h2>
    <button class="btn btn-secondary" onclick="copyList()">Copy to Clipboard</button>
  </div>
  <?php if (empty($results['lines'])): ?>
    <p style="color:#5a6a7a;font-size:.9rem">No results for this filter combination.</p>
  <?php else: ?>
    <textarea id="list-output" rows="<?= min(max(count($results['lines']), 5), 30) ?>"
      style="width:100%;font-family:monospace;font-size:.85rem;padding:.75rem;border:1px solid #d0d5dd;border-radius:4px;resize:vertical;background:#f9fafb"
      readonly><?= h(implode("\n", $results['lines'])) ?></textarea>
  <?php endif; ?>
</div>

<script>
function copyList() {
  var ta = document.getElementById('list-output');
  ta.select();
  ta.setSelectionRange(0, 99999);
  if (navigator.clipboard) {
    navigator.clipboard.writeText(ta.value).then(function() {
      var btn = event.target;
      btn.textContent = '✓ Copied!';
      setTimeout(function(){ btn.textContent = 'Copy to Clipboard'; }, 2000);
    });
  } else {
    document.execCommand('copy');
  }
}
</script>
<?php endif; ?>

<?php admin_footer(); ?>

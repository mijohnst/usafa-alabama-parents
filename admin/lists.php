<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo = get_pdo();

$all_years = ['2026','2027','2028','2029','2030','Prep School','Graduate'];

$selected_years = $_POST['years']  ?? $all_years; // default: all checked
$region         = $_POST['region'] ?? '';
$type           = $_POST['type']   ?? 'emails';

$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $where  = ['1=1'];
    $params = [];

    // Year filter — build IN (...) clause with individual placeholders
    $selected_years = array_intersect($selected_years, $all_years); // whitelist
    if (!empty($selected_years) && count($selected_years) < count($all_years)) {
        $ph = [];
        foreach (array_values($selected_years) as $i => $y) {
            $key = ':yr' . $i;
            $ph[]      = $key;
            $params[$key] = $y;
        }
        $where[] = 'class_year IN (' . implode(', ', $ph) . ')';
    }

    if ($region !== '') { $where[] = 'al_region = :region'; $params[':region'] = $region; }

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

    $year_label = empty($selected_years) ? 'No Years' :
                  (count($selected_years) === count($all_years) ? 'All Years' :
                   implode(', ', $selected_years));
    $label = $year_label . ' / ' . ($region ?: 'All Regions');
    $results = ['lines' => $lines, 'count' => count($rows), 'label' => $label];
}

admin_header('Lists');
?>

<div class="page-head">
  <h1>Contact Lists</h1>
</div>

<div class="card">
  <form method="POST">
    <div class="form-row col-4" style="align-items:flex-start">

      <div class="form-group">
        <label>Class Years</label>
        <div style="display:flex;flex-direction:column;gap:.4rem;margin-top:.25rem">
          <?php foreach ($all_years as $y): ?>
            <label style="display:flex;align-items:center;gap:.5rem;font-weight:400;font-size:.88rem;text-transform:none;letter-spacing:0;color:#1a2332;cursor:pointer">
              <input type="checkbox" name="years[]" value="<?= h($y) ?>"
                     <?= in_array($y, $selected_years) ? 'checked' : '' ?>
                     style="width:auto;accent-color:#003594">
              <?= h($y) ?>
            </label>
          <?php endforeach; ?>
          <div style="display:flex;gap:.5rem;margin-top:.25rem">
            <button type="button" class="btn btn-secondary btn-sm" onclick="setAll(true)">All</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="setAll(false)">None</button>
          </div>
        </div>
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
    <?php if (!empty($results['lines'])): ?>
      <button class="btn btn-secondary" onclick="copyList(this)">Copy to Clipboard</button>
    <?php endif; ?>
  </div>
  <?php if (empty($results['lines'])): ?>
    <p style="color:#5a6a7a;font-size:.9rem">No results for this filter combination.</p>
  <?php else: ?>
    <textarea id="list-output" rows="<?= min(max(count($results['lines']), 5), 30) ?>"
      style="width:100%;font-family:monospace;font-size:.85rem;padding:.75rem;border:1px solid #d0d5dd;border-radius:4px;resize:vertical;background:#f9fafb"
      readonly><?= h(implode("\n", $results['lines'])) ?></textarea>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
function setAll(checked) {
  document.querySelectorAll('input[name="years[]"]').forEach(function(cb){ cb.checked = checked; });
}
function copyList(btn) {
  var ta = document.getElementById('list-output');
  ta.select();
  ta.setSelectionRange(0, 99999);
  if (navigator.clipboard) {
    navigator.clipboard.writeText(ta.value).then(function() {
      btn.textContent = '✓ Copied!';
      setTimeout(function(){ btn.textContent = 'Copy to Clipboard'; }, 2000);
    });
  } else {
    document.execCommand('copy');
  }
}
</script>

<?php admin_footer(); ?>

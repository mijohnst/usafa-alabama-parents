<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo = get_pdo();

$all_years = ['2026','2027','2028','2029','2030','Prep School','Graduate'];

$selected_years = $_POST['years']  ?? $all_years;
$region         = $_POST['region'] ?? '';
$type           = $_POST['type']   ?? 'emails';

$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $where  = ['1=1'];
    $params = [];

    $selected_years = array_intersect((array)$selected_years, $all_years);
    if (!empty($selected_years) && count($selected_years) < count($all_years)) {
        $ph = [];
        foreach (array_values($selected_years) as $i => $y) {
            $key = ':yr' . $i;
            $ph[]       = $key;
            $params[$key] = $y;
        }
        $where[] = 'class_year IN (' . implode(', ', $ph) . ')';
    }

    if ($region !== '') { $where[] = 'al_region = :region'; $params[':region'] = $region; }

    $sql = 'SELECT cadet_last_name, cadet_first_middle, class_year, al_region,
                   parent1_first_name, parent1_last_name, parent1_email, parent1_cell,
                   parent1_street, parent1_city, parent1_state, parent1_zip,
                   parent2_first_name, parent2_last_name, parent2_email, parent2_cell,
                   parent2_street, parent2_city, parent2_state, parent2_zip,
                   cadet_email, cadet_cell
            FROM members WHERE ' . implode(' AND ', $where)
         . ' ORDER BY class_year, cadet_last_name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $lines = [];
    foreach ($rows as $r) {
        $cadet = trim($r['cadet_last_name'] . ', ' . $r['cadet_first_middle']);

        // Helper to format a mailing address block
        $addr = function(string $prefix) use ($r): string {
            $street = trim($r[$prefix . '_street'] ?? '');
            $city   = trim($r[$prefix . '_city']   ?? '');
            $state  = trim($r[$prefix . '_state']  ?? '');
            $zip    = trim($r[$prefix . '_zip']     ?? '');
            if (!$street && !$city) return '';
            $line2 = trim("$city, $state $zip");
            return "$street\n$line2";
        };

        switch ($type) {
            case 'emails':
                if ($r['parent1_email']) {
                    $name = trim($r['parent1_first_name'] . ' ' . $r['parent1_last_name']);
                    $lines[] = "$name <{$r['parent1_email']}>";
                }
                if ($r['parent2_email']) {
                    $name = trim($r['parent2_first_name'] . ' ' . $r['parent2_last_name']);
                    $lines[] = "$name <{$r['parent2_email']}>";
                }
                break;
            case 'emails_plain':
                if ($r['parent1_email']) $lines[] = $r['parent1_email'];
                if ($r['parent2_email']) $lines[] = $r['parent2_email'];
                break;
            case 'cells':
                if ($r['parent1_cell']) {
                    $name = trim($r['parent1_first_name'] . ' ' . $r['parent1_last_name']);
                    $lines[] = "$name ({$cadet}): {$r['parent1_cell']}";
                }
                if ($r['parent2_cell']) {
                    $name = trim($r['parent2_first_name'] . ' ' . $r['parent2_last_name']);
                    $lines[] = "$name ({$cadet}): {$r['parent2_cell']}";
                }
                break;
            case 'cadet_emails':
                if ($r['cadet_email']) $lines[] = "$cadet: {$r['cadet_email']}";
                break;
            case 'addr1':
                $a = $addr('parent1');
                if ($a) {
                    $name = trim($r['parent1_first_name'] . ' ' . $r['parent1_last_name']);
                    $lines[] = "$name\n$a\n";
                }
                break;
            case 'addr2':
                $a = $addr('parent2');
                if ($a) {
                    $name = trim($r['parent2_first_name'] . ' ' . $r['parent2_last_name']);
                    $lines[] = "$name\n$a\n";
                }
                break;
            case 'addr_both':
                $a1 = $addr('parent1');
                if ($a1) {
                    $name = trim($r['parent1_first_name'] . ' ' . $r['parent1_last_name']);
                    $lines[] = "$name\n$a1\n";
                }
                $a2 = $addr('parent2');
                if ($a2) {
                    $name = trim($r['parent2_first_name'] . ' ' . $r['parent2_last_name']);
                    $lines[] = "$name\n$a2\n";
                }
                break;
        }
    }

    $year_label = empty($selected_years) ? 'No Years' :
                  (count($selected_years) === count($all_years) ? 'All Years' :
                   implode(', ', $selected_years));
    $label   = $year_label . ' / ' . ($region ?: 'All Regions');
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
        <label>Class Years <span style="font-weight:400;font-size:.72rem;text-transform:none">(Ctrl+click to multi-select)</span></label>
        <select name="years[]" multiple size="<?= count($all_years) ?>"
                style="height:auto;padding:.35rem 0">
          <?php foreach ($all_years as $y): ?>
            <option value="<?= h($y) ?>" <?= in_array($y, $selected_years) ? 'selected' : '' ?>
                    style="padding:.3rem .75rem"><?= h($y) ?></option>
          <?php endforeach; ?>
        </select>
        <div style="display:flex;gap:.5rem;margin-top:.4rem">
          <button type="button" class="btn btn-secondary btn-sm" onclick="setAll(true)">All</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="setAll(false)">None</button>
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
          <optgroup label="Emails">
            <option value="emails"       <?= $type==='emails'       ?'selected':''?>>Parent Emails (Name &lt;email&gt;)</option>
            <option value="emails_plain" <?= $type==='emails_plain' ?'selected':''?>>Parent Emails (plain list)</option>
            <option value="cadet_emails" <?= $type==='cadet_emails' ?'selected':''?>>Cadet Emails</option>
          </optgroup>
          <optgroup label="Phone">
            <option value="cells"        <?= $type==='cells'        ?'selected':''?>>Parent Cell Numbers</option>
          </optgroup>
          <optgroup label="Addresses">
            <option value="addr1"        <?= $type==='addr1'        ?'selected':''?>>Parent 1 Addresses</option>
            <option value="addr2"        <?= $type==='addr2'        ?'selected':''?>>Parent 2 Addresses</option>
            <option value="addr_both"    <?= $type==='addr_both'    ?'selected':''?>>Both Parent Addresses</option>
          </optgroup>
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
  document.querySelectorAll('select[name="years[]"] option').forEach(function(o){ o.selected = checked; });
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

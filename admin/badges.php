<?php
// Parents Club Badges tracker — one row per parent-on-file (not per cadet;
// cadets don't get a badge, both parents on a member record can). Deliberately
// its own table (member_badges) rather than columns on `members`, keyed by
// (member_id, parent_slot) so it just reads parent names/cities live off the
// member record instead of duplicating them.
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

$errors = [];

// Authoritative slot list — always rebuilt from `members`, never trusted from
// the posted form, so someone can't inject a member_id/slot pair that isn't
// real. Also used to render the page. Filtering by class year happens in PHP
// at the call sites (small roster — not worth complicating the SQL).
function badge_slots(PDO $pdo): array {
    $stmt = $pdo->query("SELECT id, class_year, cadet_first_name, cadet_middle_name, cadet_last_name, cadet_suffix,
                   parent1_first_name, parent1_last_name, parent1_city,
                   parent2_first_name, parent2_last_name, parent2_city
            FROM members WHERE archived = 0
            ORDER BY class_year ASC, cadet_last_name ASC, cadet_first_name ASC");

    $slots = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $mem) {
        $cadet = cadet_full_name($mem);
        $p1 = trim($mem['parent1_first_name'] . ' ' . $mem['parent1_last_name']);
        $p2 = trim(($mem['parent2_first_name'] ?? '') . ' ' . ($mem['parent2_last_name'] ?? ''));
        if ($p1 !== '') $slots[] = ['member_id' => (int)$mem['id'], 'slot' => 1, 'name' => $p1, 'cadet' => $cadet, 'class_year' => $mem['class_year'], 'city' => $mem['parent1_city']];
        if ($p2 !== '') $slots[] = ['member_id' => (int)$mem['id'], 'slot' => 2, 'name' => $p2, 'cadet' => $cadet, 'class_year' => $mem['class_year'], 'city' => $mem['parent2_city']];
    }
    return $slots;
}

$year = $_GET['year'] ?? '';
if (!in_array($year, CLASS_YEAR_LIST, true) && $year !== 'all') {
    $year = ''; // '' = default view below
}
$default_view = ($year === '');
$current_years = array_merge(current_class_years(), ['Prep School']);
$search = trim($_GET['q'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Validate against the full roster regardless of the filter the form was
    // viewed under, so a filtered view can never accidentally wipe rows it
    // didn't render (the loop below only touches slots present in $_POST anyway).
    $all_slots = badge_slots($pdo);
    $posted = [];

    foreach ($all_slots as $s) {
        $key = $s['member_id'] . ':' . $s['slot'];
        if (!isset($_POST['seen'][$key])) continue; // slot wasn't on the submitted page

        $done        = isset($_POST['done'][$key]) ? 1 : 0;
        $done_date   = trim($_POST['done_date'][$key] ?? '');
        $mailed      = isset($_POST['mailed'][$key]) ? 1 : 0;
        $mailed_date = trim($_POST['mailed_date'][$key] ?? '');
        $comment     = mb_substr(trim($_POST['comment'][$key] ?? ''), 0, 255);

        if ($done && $done_date === '') $errors[] = $s['name'] . ' (' . $s['cadet'] . '): "Done" is checked but no Done Date was entered.';
        if ($mailed && $mailed_date === '') $errors[] = $s['name'] . ' (' . $s['cadet'] . '): "Mailed" is checked but no Mailed Date was entered.';

        if (!$done) $done_date = '';
        if (!$mailed) $mailed_date = '';

        $posted[$key] = [
            'member_id' => $s['member_id'], 'slot' => $s['slot'],
            'done' => $done, 'done_date' => $done_date ?: null,
            'mailed' => $mailed, 'mailed_date' => $mailed_date ?: null,
            'comment' => $comment !== '' ? $comment : null,
        ];
    }

    if (empty($errors)) {
        $pdo->beginTransaction();
        $up = $pdo->prepare('INSERT INTO member_badges (member_id, parent_slot, done, done_date, mailed, mailed_date, comment)
            VALUES (:member_id, :slot, :done, :done_date, :mailed, :mailed_date, :comment)
            ON DUPLICATE KEY UPDATE done=VALUES(done), done_date=VALUES(done_date), mailed=VALUES(mailed), mailed_date=VALUES(mailed_date), comment=VALUES(comment)');
        foreach ($posted as $r) $up->execute($r);
        $pdo->commit();
        flash('success', 'Badge tracker saved — ' . count($posted) . ' row' . (count($posted) != 1 ? 's' : '') . ' updated.');
        $qs = array_filter(['year' => $year !== '' ? $year : null, 'q' => $search !== '' ? $search : null]);
        header('Location: badges.php' . ($qs ? '?' . http_build_query($qs) : ''));
        exit;
    }
}

// ── Render ──────────────────────────────────────────────────────────────────
// badge_slots() only filters on a single class_year value; the default view
// spans several, so fetch everything and filter here in PHP instead.
$slots = badge_slots($pdo);
if ($default_view) {
    $slots = array_values(array_filter($slots, fn($s) => in_array($s['class_year'], $current_years, true)));
} elseif ($year !== 'all') {
    $slots = array_values(array_filter($slots, fn($s) => $s['class_year'] === $year));
}
if ($search !== '') {
    $slots = array_values(array_filter($slots, fn($s) =>
        stripos($s['cadet'], $search) !== false || stripos($s['name'], $search) !== false));
}

// Existing badge state, keyed the same way as $posted above.
$existing = [];
if ($slots) {
    $existing_rows = $pdo->query('SELECT * FROM member_badges')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($existing_rows as $r) $existing[$r['member_id'] . ':' . $r['parent_slot']] = $r;
}

// If the form was just re-rendered after a validation error, show what the
// user typed (not what's saved) so nothing they entered gets lost.
$repost = [];
if ($errors) {
    foreach ($_POST['seen'] ?? [] as $key => $_) {
        $repost[$key] = [
            'done' => isset($_POST['done'][$key]),
            'done_date' => $_POST['done_date'][$key] ?? '',
            'mailed' => isset($_POST['mailed'][$key]),
            'mailed_date' => $_POST['mailed_date'][$key] ?? '',
            'comment' => $_POST['comment'][$key] ?? '',
        ];
    }
}

$groups = [];
foreach ($slots as $s) $groups[$s['class_year']][] = $s;

admin_header('Parents Club Badges');
?>
<style>
.badge-table{width:100%;border-collapse:collapse;font-size:.85rem}
.badge-table th{padding:.5rem .75rem;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5a6a7a;background:#f7f9fc;text-align:left;white-space:nowrap}
.badge-table td{padding:.5rem .75rem;border-top:1px solid #f0f2f5;vertical-align:middle}
.badge-table tr:hover td{background:#fafbfc}
.badge-cb{width:17px;height:17px;accent-color:#1b5e20;cursor:pointer}
.badge-date{padding:.3rem .4rem;font-size:.8rem;border:1px solid #d0d5dd;border-radius:4px;width:9.5rem}
.badge-date:disabled{background:#f7f9fc;color:#c3cad4}
.badge-comment{padding:.3rem .4rem;font-size:.8rem;border:1px solid #d0d5dd;border-radius:4px;width:100%;min-width:11rem}
.badge-city{font-size:.75rem;color:#9aa5b4}
</style>

<div class="page-head">
  <h1>Parents Club Badges</h1>
  <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
</div>

<?= show_flash() ?>

<?php if ($errors): ?>
  <div class="alert alert-danger" style="margin-bottom:1rem">
    Nothing was saved — fix the following and resubmit:
    <ul style="margin:.5rem 0 0 1.25rem">
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="GET" style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem">
  <label style="font-size:.75rem;font-weight:700;color:#5a6a7a">Class:</label>
  <select name="year" onchange="this.form.submit()" style="padding:.35rem .6rem;font-size:.85rem;border:1px solid #d0d5dd;border-radius:4px">
    <option value="" <?= $default_view ? 'selected' : '' ?>>Current classes (<?= h(implode(', ', $current_years)) ?>)</option>
    <option value="all" <?= $year === 'all' ? 'selected' : '' ?>>All classes</option>
    <?php foreach (CLASS_YEAR_LIST as $y): if ($y === '') continue; ?>
      <option value="<?= h($y) ?>" <?= $year === $y ? 'selected' : '' ?>><?= h($y) ?></option>
    <?php endforeach; ?>
  </select>
  <label style="font-size:.75rem;font-weight:700;color:#5a6a7a;margin-left:.5rem">Name:</label>
  <input type="text" name="q" value="<?= h($search) ?>" placeholder="Cadet or parent name" style="padding:.35rem .6rem;font-size:.85rem;border:1px solid #d0d5dd;border-radius:4px">
  <button type="submit" class="btn btn-secondary btn-sm">Search</button>
  <?php if ($search !== ''): ?><a href="badges.php<?= $year !== '' ? '?year=' . urlencode($year) : '' ?>" style="font-size:.8rem;color:#9aa5b4">Clear</a><?php endif; ?>
</form>

<?php if (empty($slots)): ?>
  <p style="color:#9aa5b4">No members found for this filter.</p>
<?php else: ?>

<form method="POST" id="badges-form">
  <?= csrf_field() ?>

  <?php foreach ($groups as $gyear => $gslots):
      $done_cnt = 0; $mailed_cnt = 0;
      foreach ($gslots as $s) {
          $key = $s['member_id'] . ':' . $s['slot'];
          $st = $existing[$key] ?? null;
          if ($st && $st['done']) $done_cnt++;
          if ($st && $st['mailed']) $mailed_cnt++;
      }
  ?>
  <div style="display:flex;align-items:baseline;gap:.6rem;margin:1.25rem 0 .4rem">
    <h3 style="margin:0;color:#002554"><?= h($gyear) ?></h3>
    <span style="font-size:.78rem;color:#9aa5b4"><?= $done_cnt ?>/<?= count($gslots) ?> done · <?= $mailed_cnt ?>/<?= count($gslots) ?> mailed</span>
  </div>
  <div class="card" style="padding:0;overflow-x:auto">
  <table class="badge-table">
    <thead>
      <tr>
        <th>Cadet</th>
        <th>Parent</th>
        <th>City</th>
        <th>Done</th>
        <th>Done Date</th>
        <th>Mailed</th>
        <th>Mailed Date</th>
        <th>Comment</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($gslots as $s):
          $key = $s['member_id'] . ':' . $s['slot'];
          $st = $existing[$key] ?? null;
          $rp = $repost[$key] ?? null;
          $done        = $rp ? $rp['done']        : (bool)($st['done'] ?? false);
          $done_date   = $rp ? $rp['done_date']   : ($st['done_date'] ?? '');
          $mailed      = $rp ? $rp['mailed']      : (bool)($st['mailed'] ?? false);
          $mailed_date = $rp ? $rp['mailed_date'] : ($st['mailed_date'] ?? '');
          $comment     = $rp ? $rp['comment']     : ($st['comment'] ?? '');
      ?>
      <tr>
        <td><?= h($s['cadet']) ?></td>
        <td><?= h($s['name']) ?></td>
        <td class="badge-city"><?= h($s['city'] ?? '') ?></td>
        <td>
          <input type="hidden" name="seen[<?= h($key) ?>]" value="1">
          <input type="checkbox" class="badge-cb badge-done-cb" name="done[<?= h($key) ?>]" data-key="<?= h($key) ?>" <?= $done ? 'checked' : '' ?>>
        </td>
        <td><input type="date" class="badge-date badge-done-date" name="done_date[<?= h($key) ?>]" data-key="<?= h($key) ?>" value="<?= h($done_date) ?>" <?= $done ? '' : 'disabled' ?>></td>
        <td>
          <input type="checkbox" class="badge-cb badge-mailed-cb" name="mailed[<?= h($key) ?>]" data-key="<?= h($key) ?>" <?= $mailed ? 'checked' : '' ?>>
        </td>
        <td><input type="date" class="badge-date badge-mailed-date" name="mailed_date[<?= h($key) ?>]" data-key="<?= h($key) ?>" value="<?= h($mailed_date) ?>" <?= $mailed ? '' : 'disabled' ?>></td>
        <td><input type="text" class="badge-comment" name="comment[<?= h($key) ?>]" value="<?= h($comment) ?>" maxlength="255"></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endforeach; ?>

  <div style="margin-top:1.25rem">
    <button type="submit" class="btn btn-primary">Save Badge Tracker</button>
  </div>
</form>

<script>
// Checking "Done"/"Mailed" enables and requires its date field (and fills
// today's date as a starting point, editable for backfilling); unchecking
// disables it again — disabled fields don't get submitted, and the server
// independently blanks the date for any row whose checkbox came back unchecked.
function wireBadgeCheckbox(cbClass, dateClass) {
    document.querySelectorAll('.' + cbClass).forEach(function(cb) {
        cb.addEventListener('change', function() {
            var date = document.querySelector('.' + dateClass + '[data-key="' + this.dataset.key + '"]');
            date.disabled = !this.checked;
            date.required = this.checked;
            if (this.checked && !date.value) {
                var today = new Date().toISOString().slice(0, 10);
                date.value = today;
            }
        });
    });
}
wireBadgeCheckbox('badge-done-cb', 'badge-done-date');
wireBadgeCheckbox('badge-mailed-cb', 'badge-mailed-date');
// Existing checked rows on page load also need `required` set, so the
// browser's own validation catches someone blanking a date without unchecking.
document.querySelectorAll('.badge-done-cb:checked').forEach(function(cb) {
    document.querySelector('.badge-done-date[data-key="' + cb.dataset.key + '"]').required = true;
});
document.querySelectorAll('.badge-mailed-cb:checked').forEach(function(cb) {
    document.querySelector('.badge-mailed-date[data-key="' + cb.dataset.key + '"]').required = true;
});
</script>

<?php endif; ?>
<?php admin_footer(); ?>

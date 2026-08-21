<?php
/**
 * ONE-TIME TOOL — run once after admin/migrate_add_dues_years.sql, then
 * this page (and this file) can be deleted.
 *
 * Converts every already-paid member's old Annual/2-Year/3-Year/4-Year
 * plan (membership_year + membership_type) into the new per-year format
 * (membership_paid_years), and logs one retroactive Income entry per
 * member so admin/income.php's historical reports don't lose the dues
 * revenue that used to be computed directly from the members table.
 * Safe to run more than once — members that already have
 * membership_paid_years set are skipped automatically.
 */
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

$rows = $pdo->query(
    "SELECT id, cadet_first_name, cadet_middle_name, cadet_last_name, cadet_suffix,
            class_year, membership_year, membership_type, membership_paid_through
     FROM members
     WHERE membership_paid = 1 AND (membership_paid_years IS NULL OR membership_paid_years = '')"
)->fetchAll(PDO::FETCH_ASSOC);

$preview = [];
foreach ($rows as $r) {
    $n = ['annual' => 1, '2year' => 2, '3year' => 3, '4year' => 4][$r['membership_type'] ?? ''] ?? 1;
    $parts = explode('-', $r['membership_year'] ?? '');
    $years = [];
    if (count($parts) === 2 && is_numeric($parts[0])) {
        $start = (int)$parts[0];
        for ($i = 0; $i < $n; $i++) { $y = $start + $i; $years[] = $y . '-' . ($y + 1); }
    }
    $cadet_years = cadet_dues_years($r['class_year'] ?? '');
    $amount = dues_years_price($years, $cadet_years ?: $years);
    $name = trim(preg_replace('/\s+/', ' ',
        ($r['cadet_first_name'] ?? '') . ' ' . ($r['cadet_middle_name'] ?? '') . ' ' . ($r['cadet_last_name'] ?? '') . ' ' . ($r['cadet_suffix'] ?? '')
    ));
    $preview[] = [
        'id' => (int)$r['id'], 'name' => $name ?: ('Member #' . $r['id']),
        'years' => $years, 'amount' => $amount, 'mem_year' => $r['membership_year'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $n = 0; $total = 0.0;
    foreach ($preview as $p) {
        if (!$p['years']) continue;
        // touch_year=false: this is a backfill of old data, not a new
        // payment happening today — the retroactive income entry below is
        // dated to match when the old system would have booked it.
        save_dues_years($pdo, $p['id'], $p['years'], false);
        $date = preg_match('/^(\d{4})/', (string)$p['mem_year'], $mm) ? ($mm[1] . '-07-01') : date('Y-m-d');
        $pdo->prepare('INSERT INTO income_entries (entry_date, source, source_type, description, amount, payment_method, notes, received_by) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$date, $p['name'], 'dues', 'Dues (backfilled from legacy plan) — ' . implode(', ', $p['years']),
                       $p['amount'], '', 'One-time backfill from membership_type/membership_year', $_SESSION['user_id'] ?? null]);
        $n++; $total += $p['amount'];
    }
    flash('success', "Backfilled $n member(s), logged $" . number_format($total, 2) . ' in historical dues income.');
    header('Location: index.php'); exit;
}

admin_header('One-Time: Backfill Dues Years');
echo show_flash();
?>

<div class="page-head">
  <h1>One-Time: Backfill Dues Years</h1>
  <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
</div>

<p style="margin-bottom:1.25rem;color:#5a6a7a;max-width:640px">
  Run this once after the membership_paid_years migration. It converts every already-paid member's old
  Annual/2/3/4-Year plan into the new per-year format, and logs one retroactive Income entry per member so past
  income reports keep their dues totals. Delete this file once you've run it.
</p>

<?php if (empty($preview)): ?>
  <p style="color:#9aa5b4">Nothing to backfill — no paid members are missing membership_paid_years.</p>
<?php else: ?>
<div class="card" style="padding:0;overflow:auto;margin-bottom:1.25rem;max-width:700px">
<table>
  <thead><tr><th style="padding:.5rem .75rem">Cadet</th><th style="padding:.5rem .75rem">Years</th><th style="padding:.5rem .75rem">Amount</th></tr></thead>
  <tbody>
  <?php foreach ($preview as $p): ?>
    <tr>
      <td style="padding:.5rem .75rem"><?= h($p['name']) ?></td>
      <td style="padding:.5rem .75rem"><?= $p['years'] ? h(implode(', ', $p['years'])) : '<span style="color:#c62828">unparsable — skipped</span>' ?></td>
      <td style="padding:.5rem .75rem">$<?= number_format($p['amount'], 2) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<form method="POST">
  <?= csrf_field() ?>
  <button type="submit" class="btn btn-primary"
    onclick="return confirm('Backfill dues years for <?= count($preview) ?> member(s) and log historical income entries?')">
    Run Backfill
  </button>
</form>
<?php endif; ?>

<?php admin_footer(); ?>

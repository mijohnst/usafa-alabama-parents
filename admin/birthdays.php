<?php
/**
 * Cadet Birthday Cards
 * Every cadet gets a card; paid-member families (highlighted) also get a
 * gift card inside it. Read-only browsable list, by month or the full
 * year — same underlying data birthday-report.php's monthly cron email
 * uses (cadet_birthday, class_year, cadet_po_box, membership_paid).
 * See birthdays-pdf.php for the downloadable version of this same view.
 */
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

// "month" is either 1-12 or the literal string "all" (full-year list).
$view      = trim((string)($_GET['month'] ?? date('n')));
$show_all  = ($view === 'all');
$month     = $show_all ? 0 : (int)$view;
if (!$show_all && ($month < 1 || $month > 12)) $month = (int)date('n');
$month_name = $show_all ? '' : date('F', mktime(0, 0, 0, $month, 1));

if ($show_all) {
    $stmt = $pdo->query(
        "SELECT cadet_first_name, cadet_middle_name, cadet_last_name, cadet_suffix, cadet_birthday,
                cadet_po_box, class_year, membership_paid
         FROM members
         WHERE archived = 0 AND cadet_birthday IS NOT NULL
         ORDER BY MONTH(cadet_birthday), DAY(cadet_birthday), cadet_last_name"
    );
} else {
    $stmt = $pdo->prepare(
        "SELECT cadet_first_name, cadet_middle_name, cadet_last_name, cadet_suffix, cadet_birthday,
                cadet_po_box, class_year, membership_paid
         FROM members
         WHERE archived = 0 AND cadet_birthday IS NOT NULL AND MONTH(cadet_birthday) = ?
         ORDER BY DAY(cadet_birthday), cadet_last_name"
    );
    $stmt->execute([$month]);
}
$cadets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Grouped by month so the full-year view can render one table per month;
// the single-month view is just one group.
$groups = [];
if ($show_all) {
    foreach ($cadets as $c) $groups[(int)date('n', strtotime($c['cadet_birthday']))][] = $c;
} else {
    $groups[$month] = $cadets;
}
$paid_count = count(array_filter($cadets, fn($c) => $c['membership_paid']));

admin_header('Cadet Birthday Cards');
?>
<style>
.bday-table{width:100%;border-collapse:collapse;font-size:.85rem}
.bday-table th{padding:.5rem .75rem;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5a6a7a;background:#f7f9fc;text-align:left;white-space:nowrap}
.bday-table td{padding:.5rem .75rem;border-top:1px solid #f0f2f5;vertical-align:middle}
.bday-table tr:hover td{background:#fafbfc}
.bday-paid{display:inline-block;padding:.15rem .5rem;border-radius:99px;font-size:.7rem;font-weight:700;background:#e8f5e9;color:#1b5e20}
.bday-unpaid{display:inline-block;padding:.15rem .5rem;border-radius:99px;font-size:.7rem;font-weight:700;background:#f7f9fc;color:#9aa5b4}
.bday-noaddr{color:#A6192E;font-size:.78rem}
.bday-paid-row td{background:#f1f8f2}
.bday-table tr.bday-paid-row:hover td{background:#e6f3e7}
@media print {
  .no-print{display:none!important}
  .card{page-break-inside:avoid}
}
</style>

<div class="page-head">
  <h1>🎂 Cadet Birthday Cards</h1>
  <div class="no-print" style="display:flex;gap:.5rem">
    <a href="birthdays-pdf.php?month=<?= h($show_all ? 'all' : (string)$month) ?>" class="btn btn-secondary">⬇ Download PDF</a>
    <button type="button" class="btn btn-secondary" onclick="window.print()">🖨️ Print</button>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>

<?= show_flash() ?>

<form method="GET" class="no-print" style="margin-bottom:1rem">
  <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
    <label style="font-size:.75rem;font-weight:700;color:#5a6a7a;text-transform:none;letter-spacing:normal;margin:0">Month:</label>
    <select name="month" onchange="this.form.submit()" style="padding:.35rem .6rem;font-size:.85rem;border:1px solid #d0d5dd;border-radius:4px">
      <option value="all" <?= $show_all ? 'selected' : '' ?>>All Months — Full-Year List</option>
      <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>" <?= (!$show_all && $m === $month) ? 'selected' : '' ?>><?= h(date('F', mktime(0, 0, 0, $m, 1))) ?></option>
      <?php endfor; ?>
    </select>
  </div>
</form>

<p style="font-size:.85rem;color:#5a6a7a;margin-bottom:1.25rem">
  <?php if ($show_all): ?>
    <strong style="color:#003594"><?= count($cadets) ?></strong> cadet<?= count($cadets) === 1 ? '' : 's' ?> total across the year,
  <?php else: ?>
    <strong style="color:#003594"><?= count($cadets) ?></strong> cadet<?= count($cadets) === 1 ? '' : 's' ?> with a birthday in <?= h($month_name) ?>,
  <?php endif; ?>
  <strong style="color:#1b5e20"><?= $paid_count ?></strong> from a currently paid member family (highlighted below) — those are the ones who should also get a gift card in their card.
</p>

<?php if (empty($cadets)): ?>
  <p style="color:#9aa5b4">No cadets have birthdays <?= $show_all ? 'on file' : 'in ' . h($month_name) ?>.</p>
<?php else: ?>

<?php foreach ($groups as $gmonth => $gcadets): ?>
<?php if ($show_all): ?>
  <h3 style="margin:1.5rem 0 .5rem;color:#002554"><?= h(date('F', mktime(0, 0, 0, $gmonth, 1))) ?></h3>
<?php endif; ?>
<div class="card" style="padding:0;overflow-x:auto<?= $show_all ? ';margin-bottom:1rem' : '' ?>">
<table class="bday-table">
  <thead>
    <tr>
      <th>Date</th>
      <th>Cadet</th>
      <th>Class</th>
      <th>Mailing Address</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($gcadets as $c): ?>
    <tr class="<?= $c['membership_paid'] ? 'bday-paid-row' : '' ?>">
      <td><?= h(date('M j', strtotime($c['cadet_birthday']))) ?></td>
      <td>
        <?= h(cadet_full_name($c)) ?>
        <?php if ($c['membership_paid']): ?><span class="bday-paid">Paid</span><?php else: ?><span class="bday-unpaid">Unpaid</span><?php endif; ?>
      </td>
      <td><?= h($c['class_year']) ?></td>
      <td>
        <?php [$addr1, $addr2] = cadet_mailing_address($c['cadet_po_box']);
              if ($addr2 === ''): ?>
          <span class="bday-noaddr"><?= h($addr1) ?></span>
        <?php else: ?>
          <?= h($addr1) ?><br><?= h($addr2) ?>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endforeach; ?>

<?php endif; ?>
<?php admin_footer(); ?>

<?php
/**
 * Cadet Birthday Cards
 * Every cadet gets a card; only paid members also get a gift card inside
 * it. This is the web/checklist counterpart to birthday-report.php's
 * monthly cron email — same underlying data (cadet_birthday, class_year,
 * cadet_po_box, membership_paid), but browsable by month with a per-cadet
 * "card sent" / "gift card included" checklist so multiple volunteers
 * working the same month don't duplicate or miss someone. Tracked per
 * (member_id, card_year) in birthday_card_log so next year starts fresh
 * without losing this year's history.
 */
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

// "month" is either 1-12 or the literal string "all" (full-year list).
$view      = trim((string)($_GET['month'] ?? date('n')));
$show_all  = ($view === 'all');
$month     = $show_all ? 0 : (int)$view;
if (!$show_all && ($month < 1 || $month > 12)) $month = (int)date('n');
$card_year = (int)date('Y');
$month_name = $show_all ? '' : date('F', mktime(0, 0, 0, $month, 1));

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $posted_view  = trim((string)($_POST['month'] ?? $view));
    $posted_all   = ($posted_view === 'all');
    $posted_month = $posted_all ? 0 : (int)$posted_view;

    // Validate against exactly the cadets shown for the posted view, not
    // whatever's in $_POST — a submission can only affect rows the form
    // actually rendered.
    if ($posted_all) {
        $stmt = $pdo->query('SELECT id FROM members WHERE archived = 0 AND cadet_birthday IS NOT NULL');
    } else {
        $stmt = $pdo->prepare('SELECT id FROM members WHERE archived = 0 AND cadet_birthday IS NOT NULL AND MONTH(cadet_birthday) = ?');
        $stmt->execute([$posted_month]);
    }
    $shown_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $posted = [];
    foreach ($shown_ids as $mid) {
        if (!isset($_POST['seen'][$mid])) continue;

        $sent       = isset($_POST['sent'][$mid]) ? 1 : 0;
        $sent_date  = trim($_POST['sent_date'][$mid] ?? '');
        $gift       = isset($_POST['gift'][$mid]) ? 1 : 0;
        $gift_date  = trim($_POST['gift_date'][$mid] ?? '');
        $comment    = mb_substr(trim($_POST['comment'][$mid] ?? ''), 0, 255);

        if ($sent && $sent_date === '') $errors[] = "Cadet #$mid: \"Card Sent\" is checked but no date was entered.";
        if ($gift && $gift_date === '') $errors[] = "Cadet #$mid: \"Gift Card\" is checked but no date was entered.";

        if (!$sent) $sent_date = '';
        if (!$gift) $gift_date = '';

        $posted[$mid] = [
            'member_id' => (int)$mid, 'card_year' => $card_year,
            'card_sent' => $sent, 'card_sent_date' => $sent_date ?: null,
            'gift_card_included' => $gift, 'gift_card_date' => $gift_date ?: null,
            'comment' => $comment !== '' ? $comment : null,
        ];
    }

    if (empty($errors)) {
        $pdo->beginTransaction();
        $up = $pdo->prepare(
            'INSERT INTO birthday_card_log (member_id, card_year, card_sent, card_sent_date, gift_card_included, gift_card_date, comment)
             VALUES (:member_id, :card_year, :card_sent, :card_sent_date, :gift_card_included, :gift_card_date, :comment)
             ON DUPLICATE KEY UPDATE card_sent=VALUES(card_sent), card_sent_date=VALUES(card_sent_date),
                 gift_card_included=VALUES(gift_card_included), gift_card_date=VALUES(gift_card_date), comment=VALUES(comment)'
        );
        foreach ($posted as $r) $up->execute($r);
        $pdo->commit();
        flash('success', 'Birthday card tracker saved — ' . count($posted) . ' cadet' . (count($posted) != 1 ? 's' : '') . ' updated.');
        header('Location: birthdays.php?month=' . ($posted_all ? 'all' : $posted_month));
        exit;
    }
}

if ($show_all) {
    $stmt = $pdo->query(
        "SELECT id, cadet_first_name, cadet_middle_name, cadet_last_name, cadet_suffix, cadet_birthday,
                cadet_po_box, class_year, membership_paid
         FROM members
         WHERE archived = 0 AND cadet_birthday IS NOT NULL
         ORDER BY MONTH(cadet_birthday), DAY(cadet_birthday), cadet_last_name"
    );
} else {
    $stmt = $pdo->prepare(
        "SELECT id, cadet_first_name, cadet_middle_name, cadet_last_name, cadet_suffix, cadet_birthday,
                cadet_po_box, class_year, membership_paid
         FROM members
         WHERE archived = 0 AND cadet_birthday IS NOT NULL AND MONTH(cadet_birthday) = ?
         ORDER BY DAY(cadet_birthday), cadet_last_name"
    );
    $stmt->execute([$month]);
}
$cadets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Grouped by month so the full-year view can render one table per month
// (with page-break-friendly headings for printing); the single-month view
// is just one group.
$groups = [];
if ($show_all) {
    foreach ($cadets as $c) $groups[(int)date('n', strtotime($c['cadet_birthday']))][] = $c;
} else {
    $groups[$month] = $cadets;
}

$existing = [];
if ($cadets) {
    $stmt = $pdo->prepare('SELECT * FROM birthday_card_log WHERE card_year = ?');
    $stmt->execute([$card_year]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $existing[(int)$r['member_id']] = $r;
}
$paid_count = count(array_filter($cadets, fn($c) => $c['membership_paid']));

// If the form was just re-rendered after a validation error, show what was
// typed (not what's saved) so nothing entered gets lost.
$repost = [];
if ($errors) {
    foreach ($_POST['seen'] ?? [] as $mid => $_) {
        $repost[$mid] = [
            'sent' => isset($_POST['sent'][$mid]),
            'sent_date' => $_POST['sent_date'][$mid] ?? '',
            'gift' => isset($_POST['gift'][$mid]),
            'gift_date' => $_POST['gift_date'][$mid] ?? '',
            'comment' => $_POST['comment'][$mid] ?? '',
        ];
    }
}

admin_header('Cadet Birthday Cards');
?>
<style>
.bday-table{width:100%;border-collapse:collapse;font-size:.85rem}
.bday-table th{padding:.5rem .75rem;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5a6a7a;background:#f7f9fc;text-align:left;white-space:nowrap}
.bday-table td{padding:.5rem .75rem;border-top:1px solid #f0f2f5;vertical-align:middle}
.bday-table tr:hover td{background:#fafbfc}
.bday-table th:nth-child(5), .bday-table td:nth-child(5),
.bday-table th:nth-child(6), .bday-table td:nth-child(6) { text-align:center }
.bday-cb{width:17px;height:17px;accent-color:#1b5e20;cursor:pointer}
.bday-date{padding:.3rem .4rem;font-size:.8rem;border:1px solid #d0d5dd;border-radius:4px;width:9rem}
.bday-date:disabled{background:#f7f9fc;color:#c3cad4}
.bday-comment{padding:.3rem .4rem;font-size:.8rem;border:1px solid #d0d5dd;border-radius:4px;width:100%;min-width:10rem}
.bday-paid{display:inline-block;padding:.15rem .5rem;border-radius:99px;font-size:.7rem;font-weight:700;background:#e8f5e9;color:#1b5e20}
.bday-unpaid{display:inline-block;padding:.15rem .5rem;border-radius:99px;font-size:.7rem;font-weight:700;background:#f7f9fc;color:#9aa5b4}
.bday-noaddr{color:#A6192E;font-size:.78rem}
/* Declaration order matters here: paid-row is the base highlight, and
   sent/gift are declared after so they win over it (same selector
   specificity) on a row that's both paid and sent/gift-included. */
.bday-paid-row td{background:#f1f8f2}
.bday-table tr.bday-paid-row:hover td{background:#e6f3e7}
.bday-row-sent td{background:#fff8e1}
.bday-table tr.bday-row-sent:hover td{background:#fbeecb}
.bday-row-gift td{background:#c8e6c9}
.bday-table tr.bday-row-gift:hover td{background:#b6dbb7}
@media print {
  .no-print{display:none!important}
  .card{page-break-inside:avoid}
}
</style>

<div class="page-head">
  <h1>🎂 Cadet Birthday Cards</h1>
  <div class="no-print" style="display:flex;gap:.5rem">
    <button type="button" class="btn btn-secondary" onclick="window.print()">🖨️ Print</button>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>

<?= show_flash() ?>

<?php if ($errors): ?>
  <div class="alert alert-danger no-print" style="margin-bottom:1rem">
    Nothing was saved — fix the following and resubmit:
    <ul style="margin:.5rem 0 0 1.25rem">
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

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

<form method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="month" value="<?= h($show_all ? 'all' : (string)$month) ?>">
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
        <th>Card Sent</th>
        <th>Gift Card</th>
        <th>Comment</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($gcadets as $c):
          $mid = $c['id'];
          $st  = $existing[$mid] ?? null;
          $rp  = $repost[$mid] ?? null;
          $sent      = $rp ? $rp['sent']      : (bool)($st['card_sent'] ?? false);
          $sent_date = $rp ? $rp['sent_date'] : ($st['card_sent_date'] ?? '');
          $gift      = $rp ? $rp['gift']      : (bool)($st['gift_card_included'] ?? false);
          $gift_date = $rp ? $rp['gift_date'] : ($st['gift_card_date'] ?? '');
          $comment   = $rp ? $rp['comment']   : ($st['comment'] ?? '');
          $row_class = trim(($c['membership_paid'] ? 'bday-paid-row ' : '') . ($gift ? 'bday-row-gift' : ($sent ? 'bday-row-sent' : '')));
      ?>
      <tr class="<?= $row_class ?>">
        <td><?= h(date('M j', strtotime($c['cadet_birthday']))) ?></td>
        <td>
          <?= h(cadet_full_name($c)) ?>
          <?php if ($c['membership_paid']): ?><span class="bday-paid">Paid</span><?php else: ?><span class="bday-unpaid">Unpaid</span><?php endif; ?>
        </td>
        <td><?= h($c['class_year']) ?></td>
        <td>
          <?php if ($c['cadet_po_box']): ?>
            P.O. Box <?= h($c['cadet_po_box']) ?><br>USAF Academy, CO 80841-<?= h($c['cadet_po_box']) ?>
          <?php else: ?>
            <span class="bday-noaddr">No PO Box on file</span>
          <?php endif; ?>
        </td>
        <td>
          <input type="hidden" name="seen[<?= $mid ?>]" value="1">
          <input type="checkbox" class="bday-cb bday-sent-cb" name="sent[<?= $mid ?>]" data-key="<?= $mid ?>" <?= $sent ? 'checked' : '' ?>><br>
          <input type="date" class="bday-date bday-sent-date" name="sent_date[<?= $mid ?>]" data-key="<?= $mid ?>" value="<?= h($sent_date) ?>" <?= $sent ? '' : 'disabled' ?>>
        </td>
        <td>
          <input type="checkbox" class="bday-cb bday-gift-cb" name="gift[<?= $mid ?>]" data-key="<?= $mid ?>" <?= $gift ? 'checked' : '' ?>><br>
          <input type="date" class="bday-date bday-gift-date" name="gift_date[<?= $mid ?>]" data-key="<?= $mid ?>" value="<?= h($gift_date) ?>" <?= $gift ? '' : 'disabled' ?>>
        </td>
        <td><input type="text" class="bday-comment" name="comment[<?= $mid ?>]" value="<?= h($comment) ?>" maxlength="255"></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endforeach; ?>

  <div class="no-print" style="margin-top:1.25rem">
    <button type="submit" class="btn btn-primary">Save Birthday Card Tracker</button>
  </div>
</form>

<script>
function updateRowColor(cb) {
    var row = cb.closest('tr');
    var giftCb = row.querySelector('.bday-gift-cb');
    var sentCb = row.querySelector('.bday-sent-cb');
    row.classList.remove('bday-row-sent', 'bday-row-gift');
    if (giftCb.checked) row.classList.add('bday-row-gift');
    else if (sentCb.checked) row.classList.add('bday-row-sent');
}
function wireBdayCheckbox(cbClass, dateClass) {
    document.querySelectorAll('.' + cbClass).forEach(function(cb) {
        cb.addEventListener('change', function() {
            var date = document.querySelector('.' + dateClass + '[data-key="' + this.dataset.key + '"]');
            date.disabled = !this.checked;
            date.required = this.checked;
            if (this.checked && !date.value) {
                date.value = new Date().toISOString().slice(0, 10);
            }
            updateRowColor(this);
        });
    });
}
wireBdayCheckbox('bday-sent-cb', 'bday-sent-date');
wireBdayCheckbox('bday-gift-cb', 'bday-gift-date');
document.querySelectorAll('.bday-sent-cb:checked').forEach(function(cb) {
    document.querySelector('.bday-sent-date[data-key="' + cb.dataset.key + '"]').required = true;
});
document.querySelectorAll('.bday-gift-cb:checked').forEach(function(cb) {
    document.querySelector('.bday-gift-date[data-key="' + cb.dataset.key + '"]').required = true;
});
</script>

<?php endif; ?>
<?php admin_footer(); ?>

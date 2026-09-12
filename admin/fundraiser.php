<?php
/**
 * Fundraiser Campaign Tracker — lets the Treasurer record offline
 * (check/Zelle/cash) gifts toward a named donation campaign so the public
 * progress bar on the matching fundraiser page (e.g. fundraiser.html) can
 * show online + offline combined. Online totals here are read-only —
 * they come straight from paypal_donations, the same table
 * donate-create-order.php/donate-capture-order.php already maintain.
 *
 * Gated by require_finance() (not require_member_admin()) specifically so
 * the treasurer role — who actually records these offline gifts — can
 * reach this page; admin/settings.php's guard excludes that role.
 */
require_once __DIR__ . '/auth.php';
require_finance();
$pdo = get_pdo();

// Same edit-restriction as income.php: everyone who can view finances can
// see the numbers, but only the Treasurer/super-admin can change the
// hand-entered offline figure.
$can_edit = is_treasurer() || is_super_admin();

$campaign = trim($_GET['campaign'] ?? '');
if (!isset(DONATION_CAMPAIGNS[$campaign])) {
    $campaign = array_key_first(DONATION_CAMPAIGNS);
}

$goal_key     = "fundraiser_{$campaign}_goal";
$offline_key  = "fundraiser_{$campaign}_offline_raised";
$year_key     = "fundraiser_{$campaign}_year";
$deadline_key = "fundraiser_{$campaign}_deadline";
$all_keys     = [$goal_key, $offline_key, $year_key, $deadline_key];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    csrf_verify();
    $goal_raw     = trim($_POST['goal'] ?? '');
    $offline_raw  = trim($_POST['offline_raised'] ?? '');
    $year_raw     = trim($_POST['year'] ?? '');
    $deadline_raw = trim($_POST['deadline'] ?? '');

    $errors = [];
    if (!is_numeric($goal_raw) || (float)$goal_raw <= 0)          $errors[] = 'Goal must be a number greater than $0.';
    if (!is_numeric($offline_raw) || (float)$offline_raw < 0)     $errors[] = 'Offline total must be a number of $0 or more.';
    if ($year_raw === '')                                          $errors[] = 'Target class year cannot be blank.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline_raw))       $errors[] = 'Deadline must be a valid date.';

    if ($errors) {
        flash('error', implode(' ', $errors));
        header('Location: fundraiser.php?campaign=' . urlencode($campaign)); exit;
    }

    $updates = [
        $goal_key     => number_format((float)$goal_raw, 2, '.', ''),
        $offline_key  => number_format((float)$offline_raw, 2, '.', ''),
        $year_key     => mb_substr($year_raw, 0, 20),
        $deadline_key => $deadline_raw,
    ];

    // Check which rows actually exist via SELECT first, rather than
    // inferring existence from UPDATE's affected-row count — PDO's MySQL
    // driver reports 0 affected rows whenever the new value is identical to
    // what's already stored (not just when the row is missing), which was
    // flagging an unchanged field as "missing" on every re-save.
    $keys = array_keys($updates);
    $stmt = $pdo->prepare('SELECT setting_key FROM site_settings WHERE setting_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')');
    $stmt->execute($keys);
    $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_diff($keys, $found);

    if ($missing) {
        flash('error', 'Some settings could not be saved — has the saber-fund migration been fully run? Missing: ' . h(implode(', ', $missing)));
    } else {
        $stmt = $pdo->prepare('UPDATE site_settings SET setting_value = ? WHERE setting_key = ?');
        foreach ($updates as $key => $val) $stmt->execute([$val, $key]);
        flash('success', 'Campaign settings updated.');
    }
    header('Location: fundraiser.php?campaign=' . urlencode($campaign)); exit;
}

$stmt = $pdo->prepare('SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN (' . implode(',', array_fill(0, count($all_keys), '?')) . ')');
$stmt->execute($all_keys);
$vals = [];
foreach ($stmt->fetchAll() as $r) $vals[$r['setting_key']] = $r['setting_value'];
$goal     = (float)($vals[$goal_key] ?? 0);
$offline  = (float)($vals[$offline_key] ?? 0);
$year     = $vals[$year_key] ?? '';
$deadline = $vals[$deadline_key] ?? '';
$settings_missing = count($vals) < count($all_keys);
$campaign_label = saber_fund_label($pdo, $campaign, DONATION_CAMPAIGNS[$campaign]);

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM paypal_donations WHERE campaign = ? AND status = 'captured'");
$stmt->execute([$campaign]);
$raised_online = (float)$stmt->fetchColumn();

// donor_comment/show_name columns only exist after the comments migration
// runs — fall back to the pre-comment column list so this page still works
// against an un-migrated database rather than erroring outright.
try {
    $stmt = $pdo->prepare("SELECT donor_name, donor_email, amount, captured_at, donor_comment, show_name FROM paypal_donations WHERE campaign = ? AND status = 'captured' ORDER BY captured_at DESC LIMIT 25");
    $stmt->execute([$campaign]);
    $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    $stmt = $pdo->prepare("SELECT donor_name, donor_email, amount, captured_at FROM paypal_donations WHERE campaign = ? AND status = 'captured' ORDER BY captured_at DESC LIMIT 25");
    $stmt->execute([$campaign]);
    $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$raised_total = $raised_online + $offline;
$pct = $goal > 0 ? min(100, round($raised_total / $goal * 100)) : 0;

admin_header('Fundraiser: ' . $campaign_label);
echo show_flash();
?>

<div class="page-head">
  <h1>🗡️ <?= h($campaign_label) ?></h1>
  <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
</div>

<?php if ($settings_missing): ?>
<div class="alert alert-error">
  Missing one or more <code>site_settings</code> rows for this campaign. Run <code>migrate_saber_fund.sql</code> and
  <code>migrate_saber_fund_year.sql</code> in phpMyAdmin, then reload this page.
</div>
<?php endif; ?>

<div class="card" style="max-width:640px">
  <h2 style="margin-bottom:1rem">Progress</h2>
  <div style="font-size:2rem;font-weight:700;color:#002554;margin-bottom:.25rem">
    $<?= number_format($raised_total, 2) ?> <span style="font-size:1rem;font-weight:400;color:#5a6a7a">of $<?= number_format($goal, 2) ?> goal</span>
  </div>
  <div style="background:#e1e5eb;border-radius:99px;height:14px;overflow:hidden;margin-bottom:1rem">
    <div style="height:100%;width:<?= $pct ?>%;background:#A6192E;border-radius:99px"></div>
  </div>
  <div style="display:flex;gap:2rem;font-size:.85rem;color:#5a6a7a;margin-bottom:1.5rem">
    <div><strong style="color:#003594;font-size:1.1rem;display:block">$<?= number_format($raised_online, 2) ?></strong>Online (PayPal, live)</div>
    <div><strong style="color:#003594;font-size:1.1rem;display:block">$<?= number_format($offline, 2) ?></strong>Offline (checks/Zelle/cash)</div>
    <div><strong style="color:#003594;font-size:1.1rem;display:block"><?= $pct ?>%</strong>Funded</div>
  </div>

  <?php if ($can_edit): ?>
  <form method="POST">
    <?= csrf_field() ?>
    <div class="form-row col-2">
      <div class="form-group">
        <label>Goal ($)</label>
        <input type="number" step="0.01" min="0.01" name="goal" value="<?= h(number_format($goal, 2, '.', '')) ?>">
      </div>
      <div class="form-group">
        <label>Offline Total Raised ($)</label>
        <input type="number" step="0.01" min="0" name="offline_raised" value="<?= h(number_format($offline, 2, '.', '')) ?>">
      </div>
    </div>
    <p style="font-size:.72rem;color:#9aa5b4;margin:-.5rem 0 1rem">Update "Offline Total Raised" whenever a check, Zelle, or cash gift comes in — enter the new running total, not just the latest gift.</p>
    <div class="form-row col-2">
      <div class="form-group">
        <label>Target Class Year</label>
        <input type="text" name="year" value="<?= h($year) ?>" placeholder="2027">
      </div>
      <div class="form-group">
        <label>Deadline</label>
        <input type="date" name="deadline" value="<?= h($deadline) ?>">
      </div>
    </div>
    <p style="font-size:.72rem;color:#9aa5b4;margin:-.5rem 0 1rem">These feed the public fundraiser page's headline, story text, and countdown automatically — reuse this same page next year by updating them here instead of asking for a code change. The browser-tab title and social-share preview text are separate and still static — those would need a one-off edit to fully match.</p>
    <button type="submit" class="btn btn-primary">Save Campaign Settings</button>
  </form>
  <?php else: ?>
  <p style="font-size:.82rem;color:#9aa5b4">Only the Treasurer or an admin can update campaign settings.</p>
  <?php endif; ?>
</div>

<div class="card" style="max-width:640px">
  <h2 style="margin-bottom:1rem">Recent Online Donations</h2>
  <?php if (empty($recent)): ?>
    <p style="color:#9aa5b4;font-size:.85rem">No online donations captured for this campaign yet.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Date</th><th>Donor</th><th>Amount</th><th>Public?</th><th>Comment</th></tr></thead>
    <tbody>
      <?php foreach ($recent as $r): ?>
      <tr>
        <td><?= h(date('M j, Y', strtotime($r['captured_at']))) ?></td>
        <td><?= h($r['donor_name'] ?: $r['donor_email']) ?></td>
        <td>$<?= number_format($r['amount'], 2) ?></td>
        <td><?= !array_key_exists('show_name', $r) ? '—' : ((int)$r['show_name'] === 0 ? 'Anonymous' : 'Shown') ?></td>
        <td><?= h($r['donor_comment'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php admin_footer(); ?>

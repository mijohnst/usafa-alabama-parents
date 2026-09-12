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
$campaign_label = DONATION_CAMPAIGNS[$campaign];

$goal_key    = "fundraiser_{$campaign}_goal";
$offline_key = "fundraiser_{$campaign}_offline_raised";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    csrf_verify();
    $offline_raw = trim($_POST['offline_raised'] ?? '');
    if (!is_numeric($offline_raw) || (float)$offline_raw < 0) {
        flash('error', 'Offline total must be a number of $0 or more.');
        header('Location: fundraiser.php?campaign=' . urlencode($campaign)); exit;
    }
    $offline_val = number_format((float)$offline_raw, 2, '.', '');
    $stmt = $pdo->prepare('UPDATE site_settings SET setting_value = ? WHERE setting_key = ?');
    $stmt->execute([$offline_val, $offline_key]);
    if ($stmt->rowCount() === 0) {
        flash('error', 'Could not save — has the saber-fund migration been run? (missing site_settings row for ' . h($offline_key) . ')');
    } else {
        flash('success', 'Offline total updated.');
    }
    header('Location: fundraiser.php?campaign=' . urlencode($campaign)); exit;
}

$stmt = $pdo->prepare('SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN (?, ?)');
$stmt->execute([$goal_key, $offline_key]);
$vals = [];
foreach ($stmt->fetchAll() as $r) $vals[$r['setting_key']] = $r['setting_value'];
$goal    = (float)($vals[$goal_key] ?? 0);
$offline = (float)($vals[$offline_key] ?? 0);
$settings_missing = !isset($vals[$goal_key]) || !isset($vals[$offline_key]);

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM paypal_donations WHERE campaign = ? AND status = 'captured'");
$stmt->execute([$campaign]);
$raised_online = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT donor_name, donor_email, amount, captured_at FROM paypal_donations WHERE campaign = ? AND status = 'captured' ORDER BY captured_at DESC LIMIT 25");
$stmt->execute([$campaign]);
$recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
  Missing <code>site_settings</code> rows for this campaign (<code><?= h($goal_key) ?></code> / <code><?= h($offline_key) ?></code>).
  Run the saber-fund migration in phpMyAdmin, then reload this page.
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
    <div class="form-group">
      <label>Offline Total Raised ($)</label>
      <input type="number" step="0.01" min="0" name="offline_raised" value="<?= h(number_format($offline, 2, '.', '')) ?>">
      <p style="font-size:.72rem;color:#9aa5b4;margin-top:.35rem">Update this whenever a check, Zelle, or cash gift comes in for this campaign — enter the new running total, not just the latest gift.</p>
    </div>
    <button type="submit" class="btn btn-primary">Save Offline Total</button>
  </form>
  <?php else: ?>
  <p style="font-size:.82rem;color:#9aa5b4">Only the Treasurer or an admin can update the offline total.</p>
  <?php endif; ?>
</div>

<div class="card" style="max-width:640px">
  <h2 style="margin-bottom:1rem">Recent Online Donations</h2>
  <?php if (empty($recent)): ?>
    <p style="color:#9aa5b4;font-size:.85rem">No online donations captured for this campaign yet.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Date</th><th>Donor</th><th>Amount</th></tr></thead>
    <tbody>
      <?php foreach ($recent as $r): ?>
      <tr>
        <td><?= h(date('M j, Y', strtotime($r['captured_at']))) ?></td>
        <td><?= h($r['donor_name'] ?: $r['donor_email']) ?></td>
        <td>$<?= number_format($r['amount'], 2) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php admin_footer(); ?>

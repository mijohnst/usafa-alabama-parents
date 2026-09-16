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

// DB-backed instead of the old hardcoded DONATION_CAMPAIGNS constant — lets
// the Treasurer start next year's drive (see the "Start a New Campaign"
// form below) without a code deploy. $active_slug is whichever one
// fundraiser.html actually shows to the public right now; the campaign
// being *viewed/edited* on this page ($campaign) can be a different one if
// the Treasurer picks an older campaign from the switcher.
$campaigns   = donation_campaigns($pdo);
$active_slug = active_campaign_slug($pdo);
$campaign    = trim($_GET['campaign'] ?? '');
if (!isset($campaigns[$campaign])) {
    $campaign = $active_slug ?? array_key_first($campaigns) ?? '';
}

$goal_key     = "fundraiser_{$campaign}_goal";
$cadet_key    = "fundraiser_{$campaign}_cadet_count";
$offline_key  = "fundraiser_{$campaign}_offline_raised";
$year_key     = "fundraiser_{$campaign}_year";
$deadline_key = "fundraiser_{$campaign}_deadline";
$all_keys     = $campaign !== '' ? [$goal_key, $cadet_key, $offline_key, $year_key, $deadline_key] : [];

$post_action = trim($_POST['action'] ?? 'save');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit && $post_action === 'activate') {
    csrf_verify();
    $target = trim($_POST['campaign'] ?? '');
    if (!isset($campaigns[$target])) {
        flash('error', 'Unknown campaign.');
    } else {
        activate_donation_campaign($pdo, $target);
        flash('success', h($campaigns[$target]) . ' is now the active campaign — fundraiser.html will show it.');
    }
    header('Location: fundraiser.php?campaign=' . urlencode($target)); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit && $post_action === 'create') {
    csrf_verify();
    $new_slug_raw  = trim($_POST['new_slug'] ?? '');
    $new_label_raw = trim($_POST['new_label'] ?? '');
    $new_cadet_raw = trim($_POST['new_cadet_count'] ?? '');
    $new_year_raw  = trim($_POST['new_year'] ?? '');
    $new_deadline_raw = trim($_POST['new_deadline'] ?? '');

    $errors = [];
    if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $new_slug_raw)) $errors[] = 'Campaign ID must be lowercase letters, numbers, and hyphens only (e.g. saber-fund-2028).';
    elseif (isset($campaigns[$new_slug_raw]))                     $errors[] = 'That campaign ID already exists — pick a different one.';
    if ($new_label_raw === '')                                     $errors[] = 'Campaign name cannot be blank.';
    if (!ctype_digit($new_cadet_raw) || (int)$new_cadet_raw <= 0)  $errors[] = 'Number of cadets must be a whole number greater than 0.';
    if ($new_year_raw === '')                                      $errors[] = 'Target class year cannot be blank.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_deadline_raw))   $errors[] = 'Deadline must be a valid date.';

    if ($errors) {
        flash('error', implode(' ', $errors));
        header('Location: fundraiser.php?campaign=' . urlencode($campaign)); exit;
    }

    create_donation_campaign(
        $pdo,
        $new_slug_raw,
        $new_label_raw,
        (int)$new_cadet_raw,
        (int)$new_cadet_raw * SABER_PRICE,
        $new_year_raw,
        $new_deadline_raw
    );
    flash('success', 'Started "' . h($new_label_raw) . '" and made it active — fundraiser.html now shows this campaign.');
    header('Location: fundraiser.php?campaign=' . urlencode($new_slug_raw)); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit && $post_action === 'save') {
    csrf_verify();
    $cadet_raw    = trim($_POST['cadet_count'] ?? '');
    $offline_raw  = trim($_POST['offline_raised'] ?? '');
    $year_raw     = trim($_POST['year'] ?? '');
    $deadline_raw = trim($_POST['deadline'] ?? '');

    $errors = [];
    if (!ctype_digit($cadet_raw) || (int)$cadet_raw <= 0)          $errors[] = 'Number of cadets must be a whole number greater than 0.';
    if (!is_numeric($offline_raw) || (float)$offline_raw < 0)     $errors[] = 'Offline total must be a number of $0 or more.';
    if ($year_raw === '')                                          $errors[] = 'Target class year cannot be blank.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline_raw))       $errors[] = 'Deadline must be a valid date.';

    if ($errors) {
        flash('error', implode(' ', $errors));
        header('Location: fundraiser.php?campaign=' . urlencode($campaign)); exit;
    }

    // Goal is derived from cadet count × the per-saber price, not entered
    // directly — the dollar goal is a consequence of how many cadets need
    // one, and letting the two drift independently is how you'd end up
    // with a goal that no longer matches "$500 x N cadets" on the public page.
    $cadet_count_new = (int)$cadet_raw;
    $goal_computed    = $cadet_count_new * SABER_PRICE;

    $updates = [
        $goal_key     => number_format($goal_computed, 2, '.', ''),
        $cadet_key    => (string)$cadet_count_new,
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

$vals = [];
if ($all_keys) {
    $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN (' . implode(',', array_fill(0, count($all_keys), '?')) . ')');
    $stmt->execute($all_keys);
    foreach ($stmt->fetchAll() as $r) $vals[$r['setting_key']] = $r['setting_value'];
}
$goal     = (float)($vals[$goal_key] ?? 0);
$offline  = (float)($vals[$offline_key] ?? 0);
$year     = $vals[$year_key] ?? '';
$deadline = $vals[$deadline_key] ?? '';
// Falls back to deriving cadet count from the existing goal (the old
// relationship) so this page still shows a sane number before
// migrate_saber_fund_cadet_count.sql has been run, rather than "0 cadets".
$cadet_count = isset($vals[$cadet_key]) ? (int)$vals[$cadet_key] : (int)round($goal / SABER_PRICE);
$settings_missing = $campaign !== '' && count($vals) < count($all_keys);
$campaign_label = $campaign !== '' ? saber_fund_label($pdo, $campaign, $campaigns[$campaign]) : '';

$raised_online = 0.0;
$recent = [];
if ($campaign !== '') {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM paypal_donations WHERE campaign = ? AND status = 'captured'");
    $stmt->execute([$campaign]);
    $raised_online = (float)$stmt->fetchColumn();

    // donor_comment/show_name/honoree_last_name columns only exist after
    // their respective migrations run — fall back one column set at a time
    // so this page still works against an un-migrated database rather than
    // erroring outright.
    try {
        $stmt = $pdo->prepare("SELECT donor_name, donor_email, amount, captured_at, donor_comment, show_name, honoree_last_name FROM paypal_donations WHERE campaign = ? AND status = 'captured' ORDER BY captured_at DESC LIMIT 25");
        $stmt->execute([$campaign]);
        $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        try {
            $stmt = $pdo->prepare("SELECT donor_name, donor_email, amount, captured_at, donor_comment, show_name FROM paypal_donations WHERE campaign = ? AND status = 'captured' ORDER BY captured_at DESC LIMIT 25");
            $stmt->execute([$campaign]);
            $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e2) {
            $stmt = $pdo->prepare("SELECT donor_name, donor_email, amount, captured_at FROM paypal_donations WHERE campaign = ? AND status = 'captured' ORDER BY captured_at DESC LIMIT 25");
            $stmt->execute([$campaign]);
            $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

// Grouped by honoree for handing each cadet a list of who gave in their
// honor — separate from $recent above (which is capped at 25 and sorted by
// date, the wrong shape for "everything for cadet X"). Always shows the
// donor's real name here regardless of their public show-name preference:
// that preference only controls the public donor wall on fundraiser.html,
// not this internal, treasurer-only list.
$by_honoree = [];
if ($campaign !== '') {
    try {
        $stmt = $pdo->prepare(
            "SELECT honoree_last_name, donor_name, donor_email, amount, captured_at
             FROM paypal_donations
             WHERE campaign = ? AND status = 'captured' AND honoree_last_name IS NOT NULL AND honoree_last_name <> ''
             ORDER BY honoree_last_name ASC, captured_at ASC"
        );
        $stmt->execute([$campaign]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $by_honoree[$row['honoree_last_name']][] = $row;
        }
    } catch (\PDOException $e) {
        // honoree_last_name column doesn't exist yet — no honoree section, not an error.
    }
}

$raised_total = $raised_online + $offline;
$pct = $goal > 0 ? min(100, round($raised_total / $goal * 100)) : 0;

// Suggests the next slug/label/year for "Start a New Campaign" based on the
// currently *active* campaign's year (not whichever one is being viewed
// above) — next year's cadets follow from whichever drive is actually
// running now, regardless of what the Treasurer happens to be looking at.
$suggest_year = (int)date('Y') + 1;
if ($active_slug) {
    $active_year_val = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ?');
    $active_year_val->execute(["fundraiser_{$active_slug}_year"]);
    $active_year = (int)$active_year_val->fetchColumn();
    if ($active_year > 0) $suggest_year = $active_year + 1;
}
$suggest_slug = 'saber-fund-' . $suggest_year;
if (isset($campaigns[$suggest_slug])) $suggest_slug = ''; // already exists — Treasurer picks their own instead of a colliding default

admin_header($campaign_label !== '' ? 'Fundraiser: ' . $campaign_label : 'Fundraiser Campaigns');
echo show_flash();
?>

<div class="page-head">
  <h1>🗡️ <?= $campaign_label !== '' ? h($campaign_label) : 'Fundraiser Campaigns' ?></h1>
  <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
</div>

<?php if ($campaigns): ?>
<form method="GET" class="card" style="max-width:640px;display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap">
  <div class="form-group" style="margin:0;flex:1;min-width:220px">
    <label style="font-size:.72rem">Viewing Campaign</label>
    <select name="campaign" onchange="this.form.submit()">
      <?php foreach ($campaigns as $slug => $label): ?>
      <option value="<?= h($slug) ?>" <?= $slug===$campaign?'selected':'' ?>><?= h($label) ?><?= $slug===$active_slug?' (active)':'' ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($can_edit && $campaign !== $active_slug): ?>
  <noscript><button type="submit" class="btn btn-secondary btn-sm">Switch</button></noscript>
  <?php endif; ?>
</form>
<?php if ($can_edit && $campaign !== '' && $campaign !== $active_slug): ?>
<form method="POST" style="margin:0 0 1.5rem">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="activate">
  <input type="hidden" name="campaign" value="<?= h($campaign) ?>">
  <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('Make this the campaign fundraiser.html shows to the public?')">Make This Campaign Active</button>
</form>
<?php endif; ?>
<?php endif; ?>

<?php if ($settings_missing): ?>
<div class="alert alert-error">
  Missing one or more <code>site_settings</code> rows for this campaign. Run <code>migrate_saber_fund.sql</code>,
  <code>migrate_saber_fund_year.sql</code>, and <code>migrate_saber_fund_cadet_count.sql</code> in phpMyAdmin, then reload this page.
</div>
<?php endif; ?>

<?php if ($campaign !== ''): ?>
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
    <input type="hidden" name="action" value="save">
    <div class="form-row col-2">
      <div class="form-group">
        <label>Number of Cadets</label>
        <input type="number" step="1" min="1" name="cadet_count" id="cadetCountInput" value="<?= h($cadet_count) ?>">
        <p style="font-size:.72rem;color:#9aa5b4;margin:.35rem 0 0">Goal = cadets &times; $<?= number_format(SABER_PRICE,0) ?>/saber = <strong id="cadetCountGoalPreview">$<?= number_format($cadet_count * SABER_PRICE, 2) ?></strong></p>
      </div>
      <div class="form-group">
        <label>Offline Total Raised ($)</label>
        <input type="number" step="0.01" min="0" name="offline_raised" value="<?= h(number_format($offline, 2, '.', '')) ?>">
      </div>
    </div>
    <p style="font-size:.72rem;color:#9aa5b4;margin:-.5rem 0 1rem">Update "Offline Total Raised" whenever a check, Zelle, or cash gift comes in — enter the new running total, not just the latest gift. If a cadet drops out of the program, lower "Number of Cadets" here and the goal recalculates automatically — no need to compute the new dollar total yourself.</p>
    <script>
      document.getElementById('cadetCountInput').addEventListener('input', function() {
        var n = parseInt(this.value, 10) || 0;
        document.getElementById('cadetCountGoalPreview').textContent = '$' + (n * <?= (int)SABER_PRICE ?>).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
      });
    </script>
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
    <thead><tr><th>Date</th><th>Donor</th><th>Amount</th><th>Public?</th><th>In Honor Of</th><th>Comment</th></tr></thead>
    <tbody>
      <?php foreach ($recent as $r): ?>
      <tr>
        <td><?= h(date('M j, Y', strtotime($r['captured_at']))) ?></td>
        <td><?= h($r['donor_name'] ?: $r['donor_email']) ?></td>
        <td>$<?= number_format($r['amount'], 2) ?></td>
        <td><?= !array_key_exists('show_name', $r) ? '—' : ((int)$r['show_name'] === 0 ? 'Anonymous' : 'Shown') ?></td>
        <td><?= h($r['honoree_last_name'] ?? '') ?: '—' ?></td>
        <td><?= h($r['donor_comment'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card" style="max-width:640px">
  <h2 style="margin-bottom:.25rem">Donations by Cadet</h2>
  <p style="font-size:.82rem;color:#9aa5b4;margin-bottom:1rem">Every gift given "in honor of" a specific cadet, grouped for handing that cadet their own list of who gave toward their saber. Real donor names are always shown here regardless of a donor's public "anonymous" choice — that setting only affects the public donor wall on the fundraiser page.</p>
  <?php if (empty($by_honoree)): ?>
    <p style="color:#9aa5b4;font-size:.85rem">No donations have been designated for a specific cadet yet.</p>
  <?php else: ?>
    <?php foreach ($by_honoree as $last_name => $gifts): ?>
    <?php $subtotal = array_sum(array_column($gifts, 'amount')); ?>
    <div style="margin-bottom:1.5rem">
      <h3 style="font-size:.95rem;color:#003594;margin-bottom:.5rem">Cadet <?= h($last_name) ?> — <span style="color:#1b5e20">$<?= number_format($subtotal, 2) ?> total</span></h3>
      <table>
        <thead><tr><th>Date</th><th>Donor</th><th>Amount</th></tr></thead>
        <tbody>
          <?php foreach ($gifts as $g): ?>
          <tr>
            <td><?= h(date('M j, Y', strtotime($g['captured_at']))) ?></td>
            <td><?= h($g['donor_name'] ?: $g['donor_email']) ?></td>
            <td>$<?= number_format($g['amount'], 2) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($can_edit): ?>
<div class="card" style="max-width:640px">
  <h2 style="margin-bottom:.25rem">Start a New Campaign</h2>
  <p style="font-size:.82rem;color:#9aa5b4;margin-bottom:1rem">Use this once a year, when a new class's cadets need sabers — it creates a fresh campaign and makes it the one <code>fundraiser.html</code> shows publicly. The campaign you're currently viewing above stays untouched, for the treasurer's own records.</p>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="form-row col-2">
      <div class="form-group">
        <label>Campaign ID</label>
        <input type="text" name="new_slug" value="<?= h($suggest_slug) ?>" placeholder="saber-fund-2028" pattern="[a-z0-9]+(-[a-z0-9]+)*">
        <p style="font-size:.72rem;color:#9aa5b4;margin:.35rem 0 0">Lowercase letters, numbers, and hyphens only — used internally to tag donations, never shown to donors.</p>
      </div>
      <div class="form-group">
        <label>Campaign Name</label>
        <input type="text" name="new_label" value="Class of <?= h($suggest_year) ?> Saber Fund" placeholder="Class of 2028 Saber Fund">
      </div>
    </div>
    <div class="form-row col-2">
      <div class="form-group">
        <label>Number of Cadets</label>
        <input type="number" step="1" min="1" name="new_cadet_count" id="newCadetCountInput" placeholder="20">
        <p style="font-size:.72rem;color:#9aa5b4;margin:.35rem 0 0">Goal = cadets &times; $<?= number_format(SABER_PRICE,0) ?>/saber = <strong id="newCadetCountGoalPreview">$0.00</strong></p>
      </div>
      <div class="form-group">
        <label>Target Class Year</label>
        <input type="text" name="new_year" value="<?= h($suggest_year) ?>" placeholder="2028">
      </div>
    </div>
    <div class="form-group">
      <label>Deadline</label>
      <input type="date" name="new_deadline">
    </div>
    <script>
      document.getElementById('newCadetCountInput').addEventListener('input', function() {
        var n = parseInt(this.value, 10) || 0;
        document.getElementById('newCadetCountGoalPreview').textContent = '$' + (n * <?= (int)SABER_PRICE ?>).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
      });
    </script>
    <button type="submit" class="btn btn-primary" onclick="return confirm('Start this new campaign and make it the one fundraiser.html shows publicly?')">Start Campaign &amp; Make It Active</button>
  </form>
</div>
<?php endif; ?>

<?php admin_footer(); ?>

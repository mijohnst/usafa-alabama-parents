<?php
require_once __DIR__ . '/auth.php';
require_admin();

$all_years = ['2026','2027','2028','2029','2030','Prep School','Graduate'];

// ── Extract valid emails from various text formats ─────────────────────────
function extract_emails(string $raw): array {
    $valid = [];
    foreach (array_filter(array_map('trim', explode("\n", $raw))) as $line) {
        if (preg_match('/<([^>@\s]+@[^>\s]+)>/', $line, $m))        $email = trim($m[1]);
        elseif (preg_match('/:\s*([^\s,;]+@[^\s,;]+)/', $line, $m)) $email = trim($m[1]);
        else                                                          $email = trim(explode(' ', $line)[0]);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) $valid[] = $email;
    }
    return array_values(array_unique($valid));
}

// ── Query DB to build recipient list ──────────────────────────────────────
function load_recipients(PDO $pdo, array $years, string $region, string $paid, string $list_type): string {
    $where  = ['1=1'];
    $params = [];

    $safe_years = array_intersect($years, ['2026','2027','2028','2029','2030','Prep School','Graduate']);
    if (!empty($safe_years)) {
        $ph = [];
        foreach (array_values($safe_years) as $i => $y) { $ph[] = ":yr$i"; $params[":yr$i"] = $y; }
        $where[] = 'class_year IN (' . implode(',', $ph) . ')';
    }
    if ($region !== '') { $where[] = 'al_region = :region'; $params[':region'] = $region; }
    if ($paid   === '1') $where[] = 'membership_paid = 1';
    if ($paid   === '0') $where[] = 'membership_paid = 0';

    $sql  = 'SELECT parent1_email, parent2_email, cadet_email FROM members WHERE ' . implode(' AND ', $where);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $lines = [];
    foreach ($rows as $r) {
        switch ($list_type) {
            case 'parent_both':
                if ($r['parent1_email']) $lines[] = $r['parent1_email'];
                if ($r['parent2_email']) $lines[] = $r['parent2_email'];
                break;
            case 'parent1':
                if ($r['parent1_email']) $lines[] = $r['parent1_email'];
                break;
            case 'cadet':
                if ($r['cadet_email']) $lines[] = $r['cadet_email'];
                break;
        }
    }
    return implode("\n", array_unique($lines));
}

// ── State ─────────────────────────────────────────────────────────────────
$recipients  = trim($_POST['recipients']  ?? '');
$subject     = trim($_POST['subject']     ?? '');
$body        = trim($_POST['body']        ?? '');
$sent        = false;
$errors      = [];
$valid_count = 0;

// Filter state
$f_years  = $_POST['f_years']  ?? $all_years;
$f_region = $_POST['f_region'] ?? '';
$f_paid   = $_POST['f_paid']   ?? '';
$f_type   = $_POST['f_type']   ?? 'parent_both';

// ── Handle load recipients ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['load'])) {
    $pdo        = get_pdo();
    $recipients = load_recipients($pdo, (array)$f_years, $f_region, $f_paid, $f_type);
}

// ── Handle send ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['send'])) {
    csrf_verify();
    if (empty($recipients)) $errors[] = 'At least one recipient is required.';
    if (empty($subject))    $errors[] = 'Subject is required.';
    if (empty($body))       $errors[] = 'Message body is required.';

    if (empty($errors)) {
        $valid = extract_emails($recipients);
        if (empty($valid)) {
            $errors[] = 'No valid email addresses found.';
        } else {
            $clean_subject = str_replace(["\r","\n"], '', $subject);
            $headers  = "From: USAFA Parents Club of Alabama <info@alabamafalcons.org>\r\n";
            $headers .= "Reply-To: info@alabamafalcons.org\r\n";
            $headers .= "BCC: " . implode(', ', $valid) . "\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

            if (mail('info@alabamafalcons.org', $clean_subject, $body, $headers)) {
                $sent        = true;
                $valid_count = count($valid);
                $recipients  = '';
                $subject     = '';
                $body        = '';
            } else {
                $errors[] = 'Server failed to send. Please try again or contact your hosting provider.';
            }
        }
    }
}

$preview_count = count(extract_emails($recipients));

admin_header('Compose Email');
?>
<style>
.compose-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1.5rem;max-width:900px;margin-bottom:1.25rem}
.loader-card{background:#f0f4ff;border:1px solid #c7d4f5;border-radius:6px;padding:1.25rem 1.5rem;max-width:900px;margin-bottom:1.25rem}
.loader-card h2{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#003594;margin-bottom:1rem}
.from-badge{display:inline-flex;align-items:center;gap:.5rem;background:#f0f4ff;border:1px solid #c7d4f5;border-radius:4px;padding:.45rem .85rem;font-size:.9rem;color:#002554;font-weight:600}
.recipient-count{font-size:.78rem;margin-top:.3rem}
.char-count{font-size:.78rem;color:#5a6a7a;text-align:right;margin-top:.25rem}
</style>

<div class="page-head">
  <h1>Compose Email</h1>
  <a href="lists.php" class="btn btn-secondary">← Lists</a>
</div>

<?php if ($sent): ?>
  <div class="alert alert-success" style="max-width:900px">
    ✓ Email sent to <strong><?= $valid_count ?></strong> recipient(s) via BCC.
  </div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
  <div class="alert alert-error" style="max-width:900px">
    <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
  </div>
<?php endif; ?>

<!-- BCC Quick Loader -->
<div class="loader-card">
  <h2>Load Recipients from Database</h2>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="subject"    value="<?= h($subject) ?>">
    <input type="hidden" name="body"       value="<?= h($body) ?>">
    <input type="hidden" name="recipients" value="<?= h($recipients) ?>">

    <div class="form-row" style="grid-template-columns:1fr 1fr 1fr 1fr auto;align-items:flex-end;gap:.75rem">

      <div class="form-group" style="margin:0">
        <label>Class Year</label>
        <select name="f_years[]" multiple size="<?= count($all_years) ?>" style="height:auto;padding:.3rem 0">
          <?php foreach ($all_years as $y): ?>
            <option value="<?= h($y) ?>" <?= in_array($y,(array)$f_years)?'selected':''?> style="padding:.25rem .6rem"><?= h($y) ?></option>
          <?php endforeach; ?>
        </select>
        <div style="display:flex;gap:.4rem;margin-top:.3rem">
          <button type="button" class="btn btn-secondary btn-sm" onclick="setYrs(true)">All</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="setYrs(false)">None</button>
        </div>
      </div>

      <div class="form-group" style="margin:0">
        <label>AL Region</label>
        <select name="f_region">
          <option value="">All Regions</option>
          <?php foreach (['North','Central','South'] as $r): ?>
            <option value="<?= h($r) ?>" <?= $f_region===$r?'selected':''?>><?= h($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group" style="margin:0">
        <label>Dues Status</label>
        <select name="f_paid">
          <option value="">All Members</option>
          <option value="1" <?= $f_paid==='1'?'selected':''?>>Paid Only</option>
          <option value="0" <?= $f_paid==='0'?'selected':''?>>Unpaid Only</option>
        </select>
      </div>

      <div class="form-group" style="margin:0">
        <label>Email List</label>
        <select name="f_type">
          <option value="parent_both" <?= $f_type==='parent_both'?'selected':''?>>Both Parent Emails</option>
          <option value="parent1"     <?= $f_type==='parent1'    ?'selected':''?>>Parent 1 Only</option>
          <option value="cadet"       <?= $f_type==='cadet'      ?'selected':''?>>Cadet Emails</option>
        </select>
      </div>

      <div class="form-group" style="margin:0">
        <label>&nbsp;</label>
        <button type="submit" name="load" value="1" class="btn btn-primary">Load →</button>
      </div>

    </div>
  </form>
</div>

<!-- Compose Form -->
<div class="compose-card">
  <form method="POST" id="compose-form">
    <?= csrf_field() ?>
    <input type="hidden" name="f_years[]" value="<?= h(implode(',', (array)$f_years)) ?>">
    <input type="hidden" name="f_region"  value="<?= h($f_region) ?>">
    <input type="hidden" name="f_paid"    value="<?= h($f_paid) ?>">
    <input type="hidden" name="f_type"    value="<?= h($f_type) ?>">

    <div class="form-group">
      <label>From</label>
      <div class="from-badge">✉ info@alabamafalcons.org</div>
    </div>

    <div class="form-group">
      <label>Recipients (BCC)</label>
      <textarea name="recipients" id="recipients" rows="5"
        placeholder="Load from the filters above, or paste addresses manually…"
        oninput="updateCount()"><?= h($recipients) ?></textarea>
      <div class="recipient-count" id="recipient-count" style="color:<?= $preview_count>0?'#2e7d32':'#5a6a7a' ?>">
        <?= $preview_count > 0 ? '✓ ' . $preview_count . ' valid address' . ($preview_count!==1?'es':'') . ' loaded' : 'No addresses loaded yet' ?>
      </div>
    </div>

    <div class="form-group">
      <label>Subject</label>
      <input name="subject" value="<?= h($subject) ?>" placeholder="e.g. Parents Weekend Reminder" maxlength="200">
    </div>

    <div class="form-group">
      <label>Message</label>
      <textarea name="body" id="body" rows="12"
        placeholder="Type your message here…"
        oninput="updateChar()"><?= h($body) ?></textarea>
      <div class="char-count" id="char-count">0 characters</div>
    </div>

    <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
      <button type="submit" name="send" value="1" class="btn btn-primary">Send Email</button>
      <a href="email.php" class="btn btn-secondary">Clear</a>
      <span style="font-size:.82rem;color:#5a6a7a">Sends as BCC — recipients will not see each other's addresses.</span>
    </div>
  </form>
</div>

<script>
function setYrs(state) {
  document.querySelectorAll('select[name="f_years[]"] option').forEach(function(o){ o.selected = state; });
}
function extract_count(text) {
  var re = /[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/;
  return text.split('\n').filter(function(l){ return re.test(l.trim()); }).length;
}
function updateCount() {
  var n = extract_count(document.getElementById('recipients').value);
  var el = document.getElementById('recipient-count');
  el.style.color = n > 0 ? '#2e7d32' : '#5a6a7a';
  el.textContent = n > 0 ? '✓ ' + n + ' valid address' + (n!==1?'es':'') + ' loaded' : 'No addresses loaded yet';
}
function updateChar() {
  var n = document.getElementById('body').value.length;
  document.getElementById('char-count').textContent = n.toLocaleString() + ' character' + (n!==1?'s':'');
}
updateChar();
</script>

<?php admin_footer(); ?>
